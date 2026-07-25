<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Providers\RoleProvider;
use FitnessClub\Services\BootPresenter;
use FitnessClub\Support\AppRouter;
use FitnessClub\Support\RateLimitException;
use FitnessClub\Support\RateLimiter;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/auth/*` — session lifecycle and the boot payload (plans/02-api-contract.md#auth).
 *
 * Cookie + nonce is the primary scheme: the SPA is served from the site's own
 * origin (D9), so a WordPress auth cookie is the session and `X-WP-Nonce` is the
 * CSRF proof. JWT is Phase 4 and for external clients only.
 *
 * Two things every method here holds to:
 *
 *   1. **No account enumeration.** A wrong password, an unknown email and an
 *      unknown username all produce the same `fc_invalid_credentials`; a forgot
 *      request for an address that does not exist returns the same 200 as one
 *      that does. WordPress core leaks this by default; we do not re-leak it.
 *   2. **Rate limits before work.** Every public method spends its bucket before
 *      touching the database, so a flood costs one transient read.
 */
final class AuthController extends RestController
{
    /**
     * POST /auth/login
     *
     * On success the response *is* the boot payload — same shape as `/auth/me`
     * plus a nonce minted for the session that was just created, so the SPA can
     * swap straight from the login panel to the app with no second round trip.
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

        $signon = wp_signon([
            'user_login'    => (string) $request->get_param('user_login'),
            'user_password' => (string) $request->get_param('password'),
            'remember'      => (bool) $request->get_param('remember'),
        ], is_ssl());

        if (is_wp_error($signon)) {
            // Deliberately flat: core distinguishes "unknown user" from "wrong
            // password", which turns the endpoint into an account oracle.
            return $this->responseError(
                'fc_invalid_credentials',
                __('That email or password is not correct.', 'fitnessclub'),
                401
            );
        }

        // A user who mistyped twice then succeeded should not stay throttled.
        RateLimiter::clear('login', $ipKey);

        return $this->bootResponse($signon);
    }

    /**
     * POST /auth/register
     *
     * Creates the WordPress user, its fc_users profile row, and signs them in —
     * one call, because a registration flow that then asks the user to log in is
     * a flow with a drop-off point for no reason.
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

        if (email_exists($email)) {
            // Registration cannot hide that an address is taken — the account
            // simply cannot be created twice. Say so plainly and point at login.
            return $this->responseError(
                'fc_email_taken',
                __('An account already exists for that email address.', 'fitnessclub'),
                409
            );
        }

        $userId = wp_insert_user([
            'user_login'   => $this->uniqueLoginFrom($email),
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => '' !== $name ? $name : $this->nameFromEmail($email),
            'role'         => RoleProvider::ROLE_USER,
        ]);

        if (is_wp_error($userId)) {
            return $this->responseError(
                'fc_registration_failed',
                $userId->get_error_message(),
                400
            );
        }

        $user = get_user_by('id', (int) $userId);
        $this->ensureProfileRow($user);

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user);

        $response = $this->bootResponse($user);
        $response->set_status(201);

        return $response;
    }

    /**
     * POST /auth/logout
     *
     * Returns a nonce for the *logged-out* session so the SPA can keep talking to
     * public endpoints (the login panel it is about to render) without a reload.
     */
    public function logout(): WP_REST_Response
    {
        wp_logout();
        wp_set_current_user(0);

        return $this->response([
            'ok'    => true,
            'nonce' => wp_create_nonce('wp_rest'),
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
     * Always 200 with the same body. Rate-limited twice: per email (so one
     * address cannot be mail-bombed) and per IP (so the endpoint cannot be swept
     * to find which addresses are registered by timing or by mail volume).
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

        // retrieve_password() sends the mail and returns a WP_Error for unknown
        // accounts. The error is swallowed on purpose — see the docblock.
        if ('' !== $login) {
            retrieve_password($login);
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

        $key      = sanitize_text_field((string) $request->get_param('key'));
        $login    = sanitize_text_field((string) $request->get_param('login'));
        $password = (string) $request->get_param('password');

        $tooShort = $this->passwordTooShort($password);
        if (null !== $tooShort) {
            return $tooShort;
        }

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user)) {
            return $this->responseError(
                'fc_invalid_reset_key',
                __('That reset link has expired or has already been used.', 'fitnessclub'),
                400
            );
        }

        reset_password($user, $password);

        // Not signed in automatically: whoever holds the link may not be the
        // account owner, and a fresh login proves the new password was received.
        return $this->response([
            'ok'      => true,
            'message' => __('Your password has been changed. You can sign in now.', 'fitnessclub'),
        ]);
    }

    /**
     * The boot payload for a freshly authenticated user.
     *
     * `wp_set_current_user()` after sign-in matters: the nonce, the role → SPA
     * resolution and every query in BootPresenter run against the *new* identity,
     * not the anonymous one this request started with.
     */
    private function bootResponse(WP_User $user): WP_REST_Response
    {
        wp_set_current_user($user->ID);
        $this->ensureProfileRow($user);

        return $this->response((new BootPresenter())->shell());
    }

    /**
     * Every member needs an fc_users row — it is where the profile, the health
     * data and every ownership check hang off. Created lazily so accounts that
     * predate the plugin (or were made in wp-admin) heal on first sign-in.
     *
     * Trainers and administrators are skipped: a trainer's identity lives in
     * fc_trainers, and an admin who never uses the member app needs no row.
     */
    private function ensureProfileRow(WP_User $user): void
    {
        global $wpdb;

        if (!in_array(RoleProvider::ROLE_USER, (array) $user->roles, true)) {
            return;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE wp_user_id = %d LIMIT 1",
            $user->ID
        ));

        if (null !== $exists) {
            return;
        }

        $wpdb->insert($wpdb->prefix . 'fc_users', [
            'wp_user_id'   => $user->ID,
            'display_name' => $user->display_name,
            'locale'       => determine_locale(),
            'timezone'     => wp_timezone_string(),
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A login name derived from the email's local part, suffixed until free.
     * Members sign in with their email; the login is an internal handle they
     * never see, so readability beats cleverness.
     */
    private function uniqueLoginFrom(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true) ?: 'member', true);
        $base = '' !== $base ? strtolower($base) : 'member';

        $login = $base;
        for ($i = 2; username_exists($login) && $i < 1000; $i++) {
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
     * `is_user_logged_in()` rather than a capability: an administrator with no
     * fc_* role must still be able to boot the admin SPA (AppRouter resolves
     * which one), and a 401 here is about *having a session*, not about what the
     * session may do.
     */
    public static function requireSession(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error(
            'fc_not_authenticated',
            __('You need to be signed in.', 'fitnessclub'),
            ['status' => 401]
        );
    }

    /**
     * Public routes still refuse to serve a *half*-authenticated request: if the
     * caller sent a cookie, WordPress has already resolved it by now, and the
     * login endpoint being reachable while signed in is a footgun (it would
     * silently swap sessions).
     */
    public static function requireGuest(): bool|WP_Error
    {
        if (!is_user_logged_in()) {
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
