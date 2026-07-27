<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\Csrf;
use FitnessClub\Auth\PasswordHasher;
use FitnessClub\Auth\TokenService;
use FitnessClub\Services\AccountMailer;
use FitnessClub\Services\BootPresenter;
use FitnessClub\Support\AppRouter;
use FitnessClub\Support\RateLimitException;
use FitnessClub\Support\RateLimiter;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/auth/*` — session lifecycle and the boot payload (plans/02-api-contract.md#auth).
 *
 * **The plugin owns its own accounts.** A WordPress session grants nothing here:
 * an administrator signed in to wp-admin who has no fc_accounts row sees the
 * login panel like anyone else. Identity is the plugin's session cookie, CSRF is
 * the plugin's own token (Auth\Csrf), and neither `wp_signon()` nor the
 * `wp_rest` nonce is involved.
 *
 * Four things every method here holds to:
 *
 *   1. **No account enumeration.** A wrong password, an unknown email and an
 *      unknown login all produce the same `fc_invalid_credentials`; a forgot
 *      request for an address that does not exist returns the same 200 as one
 *      that does.
 *   2. **No timing oracle either.** Owning the hash makes the *duration* of a
 *      request as revealing as its body, so an unknown login still spends a
 *      bcrypt verify against a dummy hash. Careful wording alone would not have
 *      been enough here.
 *   3. **Rate limits before work.** Every public method spends its bucket before
 *      touching the database, so a flood costs one transient read.
 *   4. **A password change evicts every session.** A reset whose whole
 *      motivation may be "somebody else is in my account" has to remove them.
 */
final class AuthController extends RestController
{
    /**
     * POST /auth/login
     *
     * On success the response *is* the boot payload — the same shape as
     * `/auth/me` plus the CSRF token for the session just created, so the SPA
     * swaps straight from the login panel to the app with no second round trip.
     */
    public function login(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limits = $this->limits();
        $ipKey  = RateLimiter::ipHash();

        try {
            RateLimiter::hit('login', $ipKey, (int) $limits['login_per_minute_ip'], MINUTE_IN_SECONDS);
        } catch (RateLimitException $e) {
            return $e->toWpError();
        }

        $identifier = sanitize_text_field((string) $request->get_param('user_login'));
        $password   = (string) $request->get_param('password');
        $account    = Auth::accounts()->findByIdentifier($identifier);

        if (null === $account) {
            // Spend the time anyway. Without this, an unknown login returns in
            // microseconds while a known one takes a bcrypt verify, and the
            // endpoint becomes an account enumerator no matter how carefully the
            // message is worded.
            PasswordHasher::verifyDummy($password);

            return $this->invalidCredentials();
        }

        $credentials = Auth::accounts()->credentialsFor($account->id);
        if (null === $credentials) {
            return $this->invalidCredentials();
        }

        if ($this->isLocked($credentials['locked_until'])) {
            return $this->responseError(
                'fc_account_locked',
                __('Too many failed attempts. Try again shortly.', 'fitnessclub'),
                429
            );
        }

        if (!PasswordHasher::verify($password, $credentials['password_hash'])) {
            Auth::accounts()->recordFailedLogin($account->id);

            return $this->invalidCredentials();
        }

        // A correct password for an account that is not active is still a
        // refusal, but a different one: telling somebody "your account is
        // suspended" is not enumeration, because they have already proved they
        // own it.
        if (!$account->isActive()) {
            return $this->responseError(
                'fc_account_inactive',
                __('This account is not active. Contact an administrator.', 'fitnessclub'),
                403
            );
        }

        // The hash is upgraded here, while the plaintext is still in scope, so
        // raising the cost later migrates accounts as they sign in.
        if (PasswordHasher::needsRehash($credentials['password_hash'])) {
            Auth::accounts()->rehash($account->id, $password);
        }

        // A user who mistyped twice then succeeded should not stay throttled.
        RateLimiter::clear('login', $ipKey);

        return $this->bootResponse($account, (bool) $request->get_param('remember'));
    }

    /**
     * POST /auth/register
     *
     * Creates the account, its fc_users profile row, and signs them in — one
     * call, because a registration flow that then asks the user to log in is a
     * flow with a drop-off point for no reason.
     */
    public function register(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!FitnessClub()->options->get('features.registration_open', true)) {
            return $this->responseError(
                'fc_registration_closed',
                __('Registration is currently closed.', 'fitnessclub'),
                403
            );
        }

        $limits = $this->limits();

        try {
            RateLimiter::hit(
                'register',
                RateLimiter::ipHash(),
                (int) $limits['register_per_hour_ip'],
                HOUR_IN_SECONDS
            );
        } catch (RateLimitException $e) {
            return $e->toWpError();
        }

        $email    = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $name     = sanitize_text_field((string) $request->get_param('display_name'));

        if (!is_email($email)) {
            return $this->responseError(
                'fc_invalid_email',
                __('That email address is not valid.', 'fitnessclub'),
                400
            );
        }

        $tooShort = $this->passwordTooShort($password);
        if (null !== $tooShort) {
            return $tooShort;
        }

        if (Auth::accounts()->emailExists($email)) {
            // Registration cannot hide that an address is taken — the account
            // simply cannot be created twice. Say so plainly and point at login.
            return $this->responseError(
                'fc_email_taken',
                __('An account already exists for that email address.', 'fitnessclub'),
                409
            );
        }

        $accountId = Auth::accounts()->create([
            'login'        => $this->uniqueLoginFrom($email),
            'email'        => $email,
            'password'     => $password,
            'display_name' => '' !== $name ? $name : $this->nameFromEmail($email),
            'role'         => Capabilities::ROLE_USER,
            'status'       => Account::STATUS_ACTIVE,
            'locale'       => determine_locale(),
            'timezone'     => wp_timezone_string(),
        ]);

        $account = Auth::accounts()->find($accountId);

        if (null === $account) {
            return $this->responseError(
                'fc_registration_failed',
                __('The account could not be created.', 'fitnessclub'),
                400
            );
        }

        $response = $this->bootResponse($account, false);
        $response->set_status(201);

        return $response;
    }

    /**
     * POST /auth/logout
     *
     * Returns a CSRF token for the *signed-out* caller, so the SPA can keep
     * talking to public endpoints — the login panel it is about to render —
     * without a reload.
     */
    public function logout(): WP_REST_Response
    {
        Auth::logout();

        return $this->response([
            'ok'   => true,
            'csrf' => Csrf::ensureCookie(),
        ]);
    }

    /**
     * GET /auth/me — the single boot call.
     */
    public function me(): WP_REST_Response
    {
        return $this->response((new BootPresenter())->me());
    }

    /**
     * POST /auth/password/forgot
     *
     * Always 200 with the same body. Rate-limited twice: per address (so one
     * account cannot be mail-bombed) and per IP (so the endpoint cannot be swept
     * to find which addresses are registered by mail volume).
     */
    public function forgotPassword(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limits = $this->limits();
        $login  = sanitize_text_field((string) $request->get_param('user_login'));

        try {
            RateLimiter::hit(
                'pwforgot_ip',
                RateLimiter::ipHash(),
                (int) $limits['password_reset_per_hour'] * 3,
                HOUR_IN_SECONDS
            );
            RateLimiter::hit(
                'pwforgot',
                RateLimiter::keyFor($login),
                (int) $limits['password_reset_per_hour'],
                HOUR_IN_SECONDS
            );
        } catch (RateLimitException $e) {
            return $e->toWpError();
        }

        $account = '' === $login ? null : Auth::accounts()->findByIdentifier($login);

        // Unknown address, no email, same response. An account with no email on
        // file (a bootstrapped administrator) is the same case: there is nowhere
        // to send it, and saying so would confirm the account exists.
        if (null !== $account && null !== $account->email && $account->isActive()) {
            $token = (new TokenService())->issue(
                $account->id,
                TokenService::PURPOSE_RESET,
                (int) FitnessClub()->config('fitnessclub.auth.reset_ttl_minutes', 60) * MINUTE_IN_SECONDS
            );

            (new AccountMailer())->sendPasswordReset($account, $token);
        }

        return $this->response([
            'ok'      => true,
            'message' => __(
                'If an account exists for that address, a reset link is on its way.',
                'fitnessclub'
            ),
        ]);
    }

    /**
     * POST /auth/password/reset — the target of the emailed link.
     *
     * Serves invitations too: the mechanics are identical, and redeeming an
     * invite is what moves a bootstrapped account from `pending` to `active`.
     */
    public function resetPassword(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $limits = $this->limits();

        try {
            RateLimiter::hit(
                'pwreset',
                RateLimiter::ipHash(),
                (int) $limits['password_reset_confirm_per_hour_ip'],
                HOUR_IN_SECONDS
            );
        } catch (RateLimitException $e) {
            return $e->toWpError();
        }

        $token    = sanitize_text_field((string) $request->get_param('token'));
        $password = (string) $request->get_param('password');

        $tooShort = $this->passwordTooShort($password);
        if (null !== $tooShort) {
            return $tooShort;
        }

        $tokens    = new TokenService();
        $accountId = $tokens->redeem($token, TokenService::PURPOSE_RESET)
            ?? $tokens->redeem($token, TokenService::PURPOSE_INVITE);

        // One flat error for missing, wrong, expired and already-used. Any
        // distinction here is an oracle for whether a link was ever valid.
        if (null === $accountId) {
            return $this->responseError(
                'fc_invalid_reset_key',
                __('That reset link has expired or has already been used.', 'fitnessclub'),
                400
            );
        }

        Auth::accounts()->setPassword($accountId, $password);
        Auth::accounts()->activate($accountId);
        Auth::sessions()->revokeAllFor($accountId);

        $account = Auth::accounts()->find($accountId);
        if (null !== $account) {
            (new AccountMailer())->sendPasswordChanged($account);
        }

        // Not signed in automatically: whoever holds the link may not be the
        // account owner, and a fresh login proves the new password was received.
        return $this->response([
            'ok'      => true,
            'message' => __('Your password has been changed. You can sign in now.', 'fitnessclub'),
        ]);
    }

    /**
     * The boot payload for a freshly authenticated account.
     *
     * `Auth::login()` before building it matters: the CSRF token, the role → SPA
     * resolution and every query in BootPresenter must run against the *new*
     * identity, not the anonymous one this request started with.
     */
    private function bootResponse(Account $account, bool $remember): WP_REST_Response
    {
        Auth::login($account, $remember);
        $this->ensureProfileRow($account);

        return $this->response((new BootPresenter())->shell());
    }

    /**
     * Every member needs an fc_users row — it is where the profile, the health
     * data and every ownership check hang off. Created lazily so an account made
     * by an administrator heals on first sign-in.
     *
     * Trainers and administrators are skipped: a trainer's identity lives in
     * fc_trainers, and an admin who never uses the member app needs no row.
     */
    private function ensureProfileRow(Account $account): void
    {
        global $wpdb;

        if (Capabilities::ROLE_USER !== $account->role) {
            return;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE account_id = %d LIMIT 1",
            $account->id
        ));

        if (null !== $exists) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_users', [
            'account_id'   => $account->id,
            'display_name' => $account->displayName,
            'locale'       => $account->locale ?? determine_locale(),
            'timezone'     => $account->timezone ?? wp_timezone_string(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    private function invalidCredentials(): WP_Error
    {
        // Deliberately flat: distinguishing "unknown account" from "wrong
        // password" turns the endpoint into an account oracle.
        return new WP_Error(
            'fc_invalid_credentials',
            __('That email or password is not correct.', 'fitnessclub'),
            ['status' => 401]
        );
    }

    private function isLocked(?string $lockedUntil): bool
    {
        return null !== $lockedUntil && $lockedUntil > gmdate('Y-m-d H:i:s');
    }

    /**
     * A login handle derived from the email's local part, suffixed until free.
     * Members sign in with their email; the login is an internal handle they
     * never see, so readability beats cleverness.
     */
    private function uniqueLoginFrom(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true) ?: 'member', true);
        $base = '' !== $base ? strtolower($base) : 'member';

        $login = $base;
        for ($i = 2; Auth::accounts()->loginExists($login) && $i < 1000; $i++) {
            $login = $base . $i;
        }

        return $login;
    }

    private function nameFromEmail(string $email): string
    {
        $local = strstr($email, '@', true) ?: $email;

        return ucwords(trim(str_replace(['.', '_', '-'], ' ', $local)));
    }

    /**
     * @return WP_Error|null Null when the password is long enough.
     */
    private function passwordTooShort(string $password): ?WP_Error
    {
        $min = (int) $this->limits()['password_min_length'];

        if (strlen($password) >= $min) {
            return null;
        }

        return new WP_Error(
            'fc_weak_password',
            sprintf(
                /* translators: %d: minimum number of characters. */
                __('Please choose a password of at least %d characters.', 'fitnessclub'),
                $min
            ),
            ['status' => 400, 'min_length' => $min]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function limits(): array
    {
        return (array) FitnessClub()->config('fitnessclub.limits', []);
    }

    /**
     * Permission callback for `/auth/me` and `/auth/logout`.
     *
     * Having a session, not holding a capability: an administrator must be able
     * to boot the admin SPA (AppRouter decides which one), and a 401 here is
     * about *being signed in*, not about what the account may do.
     */
    public static function requireSession(): bool|WP_Error
    {
        if (Auth::check()) {
            return true;
        }

        return new WP_Error(
            'fc_not_authenticated',
            __('You need to be signed in.', 'fitnessclub'),
            ['status' => 401]
        );
    }

    /**
     * Public routes still refuse to serve a request from somebody already signed
     * in: the login endpoint being reachable with a live session is a footgun,
     * because it would silently swap one account for another.
     */
    public static function requireGuest(): bool|WP_Error
    {
        if (!Auth::check()) {
            return true;
        }

        return new WP_Error(
            'fc_already_authenticated',
            __('You are already signed in.', 'fitnessclub'),
            ['status' => 409]
        );
    }

    /**
     * The app URL a client should send the user to after a successful reset.
     * Exposed so the reset screen can link "back to sign in" without hardcoding
     * the configurable base (D9).
     */
    public static function loginUrl(): string
    {
        return AppRouter::url();
    }
}
