<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\Csrf;
use FitnessClub\Auth\PasswordHasher;
use FitnessClub\Auth\SessionCookie;
use FitnessClub\Auth\TokenService;
use FitnessClub\Support\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/auth/*` against the real REST server, on the plugin's own identity.
 *
 * The behaviours this pins down are the ones that are easy to regress and
 * expensive to get wrong: flat credential errors, the CSRF gate, per-IP and
 * per-address throttling, and the fact that a WordPress session buys nothing.
 *
 * @covers \FitnessClub\Http\Controllers\Api\AuthController
 */
final class AuthApiTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts from an anonymous browser holding a CSRF cookie —
        // which is what the shell hands a visitor before they sign in.
        Auth::forget();
        SessionCookie::clear();
        Csrf::ensureCookie();

        // Buckets are per-IP and the test IP never changes, so a login test
        // would otherwise inherit the previous one's failures.
        RateLimiter::clear('login', RateLimiter::ipHash());
        RateLimiter::clear('register', RateLimiter::ipHash());
        RateLimiter::clear('pwforgot_ip', RateLimiter::ipHash());
        RateLimiter::clear('pwreset', RateLimiter::ipHash());
    }

    // ----------------------------------------------------------------- login

    public function testLoginReturnsTheBootPayloadAndAWorkingCsrfToken(): void
    {
        $accountId = $this->makeAccount();
        $account   = (new AccountRepository())->find($accountId);

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame($accountId, $data['user']['account_id']);
        $this->assertSame('user', $data['user']['role']);
        $this->assertSame('user', $data['app']['spa']);

        // The token is bound to the session that was just created, so the very
        // next write works without a round trip.
        $this->assertNotEmpty($data['csrf']);
        $this->assertSame(
            $data['csrf'],
            SessionCookie::readCsrf(),
            'The token in the payload is the one in the cookie.'
        );

        // The WordPress nonce apparatus is gone, and its absence is part of the
        // contract now — a client that still looks for these is out of date.
        $this->assertArrayNotHasKey('nonce', $data);
        $this->assertArrayNotHasKey('ajaxUrl', $data);
    }

    public function testLoginSignsInByEmailAsWellAsLogin(): void
    {
        $accountId = $this->makeAccount();
        $account   = (new AccountRepository())->find($accountId);

        $response = $this->post('/auth/login', [
            'user_login' => $account->email,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertSame($accountId, $response->get_data()['user']['account_id']);
    }

    public function testLoginWithAWrongPasswordIsFlatlyRejected(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => 'not-the-password',
        ]);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('fc_invalid_credentials', $response->get_data()['code']);
    }

    public function testLoginTellsUnknownAccountsApartFromWrongPasswordsInNoWay(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $wrongPassword = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => 'not-the-password',
        ]);

        $unknownAccount = $this->post('/auth/login', [
            'user_login' => 'nobody-here@example.test',
            'password'   => 'not-the-password',
        ]);

        // Same status, same code, same message: the endpoint is not an oracle
        // for which addresses have accounts.
        $this->assertSame($wrongPassword->get_status(), $unknownAccount->get_status());
        $this->assertSame(
            $wrongPassword->get_data()['code'],
            $unknownAccount->get_data()['code']
        );
        $this->assertSame(
            $wrongPassword->get_data()['message'],
            $unknownAccount->get_data()['message']
        );
    }

    public function testLoginIsRateLimitedPerIp(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());
        $limit   = (int) FitnessClub()->config('fitnessclub.limits.login_per_minute_ip', 5);

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->post('/auth/login', [
                'user_login' => $account->login,
                'password'   => 'wrong',
            ]);
        }

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(429, $response->get_status());
    }

    public function testAnAccountLocksItselfAfterRepeatedFailures(): void
    {
        $accountId = $this->makeAccount();
        $account   = (new AccountRepository())->find($accountId);
        $accounts  = new AccountRepository();

        // Straight at the repository: the per-IP limiter would refuse long
        // before the per-account threshold, and it is the account-level lock
        // being tested — the half that a distributed attacker cannot dodge by
        // changing address.
        $threshold = (int) FitnessClub()->config('fitnessclub.auth.lockout_threshold', 10);
        for ($i = 0; $i < $threshold; $i++) {
            $accounts->recordFailedLogin($accountId);
        }

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(429, $response->get_status());
        $this->assertSame('fc_account_locked', $response->get_data()['code']);
    }

    public function testLoginIsRefusedWhileAlreadySignedIn(): void
    {
        $accountId = $this->makeAccount();
        $account   = (new AccountRepository())->find($accountId);
        $this->signIn($accountId);

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_already_authenticated', $response->get_data()['code']);
    }

    public function testASuspendedAccountCannotSignInEvenWithTheRightPassword(): void
    {
        $accountId = $this->makeAccount(Capabilities::ROLE_USER, [], Account::STATUS_SUSPENDED);
        $account   = (new AccountRepository())->find($accountId);

        $response = $this->post('/auth/login', [
            'user_login' => $account->login,
            'password'   => self::PASSWORD,
        ]);

        // Not `fc_invalid_credentials`: they proved they own the account, so
        // telling them it is switched off is not enumeration.
        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_account_inactive', $response->get_data()['code']);
    }

    // -------------------------------------------------------------- the gate

    public function testAWriteWithoutACsrfTokenIsRefused(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $request = new WP_REST_Request('POST', '/fitnessclub/v1/auth/login');
        $request->set_param('user_login', $account->login);
        $request->set_param('password', self::PASSWORD);

        $response = rest_get_server()->dispatch($request);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_csrf_missing', $response->get_data()['code']);
    }

    public function testAWriteWithTheWrongCsrfTokenIsRefused(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $request = new WP_REST_Request('POST', '/fitnessclub/v1/auth/login');
        $request->set_header('X-FC-CSRF', str_repeat('f', 64));
        $request->set_param('user_login', $account->login);
        $request->set_param('password', self::PASSWORD);

        $response = rest_get_server()->dispatch($request);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_csrf_mismatch', $response->get_data()['code']);
    }

    public function testReadsNeedNoCsrfToken(): void
    {
        $this->signIn($this->makeAccount());

        // No header at all — GET is safe by contract, and requiring a token on
        // reads would break every link into the app.
        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'));

        $this->assertSame(200, $response->get_status());
    }

    // -------------------------------------------------------------- register

    public function testRegisterCreatesAMemberWithAProfileRowAndSignsThemIn(): void
    {
        global $wpdb;

        $email = 'new_' . wp_generate_password(8, false) . '@example.test';

        $response = $this->post('/auth/register', [
            'email'        => $email,
            'password'     => 'a-long-enough-password',
            'display_name' => 'New Member',
        ]);

        $this->assertSame(201, $response->get_status());

        $account = (new AccountRepository())->findByEmail($email);
        $this->assertNotNull($account);
        $this->trackAccount($account->id);

        $this->assertSame(Capabilities::ROLE_USER, $account->role);
        $this->assertSame('New Member', $account->displayName);

        // The member profile is created with the account — every ownership
        // check hangs off it.
        $this->assertNotNull($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE account_id = %d",
            $account->id
        )));

        // And they are signed in, not left at a second form.
        $this->assertSame($account->id, $response->get_data()['user']['account_id']);
        $this->assertTrue(Auth::check());

        $wpdb->delete($wpdb->prefix . 'fc_users', ['account_id' => $account->id]);
    }

    public function testRegisterRejectsAShortPassword(): void
    {
        $response = $this->post('/auth/register', [
            'email'    => 'short_' . wp_generate_password(8, false) . '@example.test',
            'password' => 'short',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_weak_password', $response->get_data()['code']);
    }

    public function testRegisterRejectsAnAddressThatAlreadyHasAnAccount(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $response = $this->post('/auth/register', [
            'email'    => $account->email,
            'password' => 'a-long-enough-password',
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_email_taken', $response->get_data()['code']);
    }

    // -------------------------------------------------------------- me/logout

    public function testMeRequiresASession(): void
    {
        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'));

        $this->assertSame(401, $response->get_status());
        $this->assertSame('fc_not_authenticated', $response->get_data()['code']);
    }

    public function testMeResolvesTheSpaFromTheAccountRole(): void
    {
        foreach (
            [
            Capabilities::ROLE_USER    => 'user',
            Capabilities::ROLE_TRAINER => 'trainer',
            Capabilities::ROLE_ADMIN   => 'admin',
            ] as $role => $expectedSpa
        ) {
            $this->signIn($this->makeAccount($role));

            $data = rest_get_server()
                ->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'))
                ->get_data();

            $this->assertSame($expectedSpa, $data['app']['spa'], "Role {$role}");
            $this->assertSame($role, $data['user']['role']);
        }
    }

    /**
     * The requirement this whole work package exists for.
     */
    public function testAWordPressAdministratorWithNoAccountIsAnonymous(): void
    {
        $wpAdmin = wp_insert_user([
            'user_login' => 'fc_wpadmin_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(),
            'user_email' => uniqid('fc_wpadmin_', true) . '@example.test',
            'role'       => 'administrator',
        ]);

        wp_set_current_user((int) $wpAdmin);
        $this->assertTrue(current_user_can('manage_options'), 'Really is a WordPress administrator.');

        $me = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'));
        $this->assertSame(401, $me->get_status(), 'A wp-admin session grants nothing here.');

        $dashboard = rest_get_server()->dispatch(
            new WP_REST_Request('GET', '/fitnessclub/v1/user/dashboard')
        );
        $this->assertSame(401, $dashboard->get_status());

        // And they are routed to the user SPA, which is the login screen.
        $this->assertSame('user', \FitnessClub\Support\AppRouter::currentSpa());

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user((int) $wpAdmin);
    }

    public function testLogoutEndsTheSessionAndReturnsAGuestToken(): void
    {
        $this->signIn($this->makeAccount());

        $response = $this->post('/auth/logout');

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['ok']);
        $this->assertNotEmpty(
            $response->get_data()['csrf'],
            'A signed-out client still needs a token to reach the login endpoint.'
        );
        $this->assertFalse(Auth::check());
    }

    public function testLogoutRevokesTheSessionRowSoTheCookieIsDead(): void
    {
        global $wpdb;

        $accountId = $this->makeAccount();
        $this->signIn($accountId);
        $cookie = SessionCookie::read();

        $this->post('/auth/logout');

        $this->assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_sessions
                  WHERE account_id = %d AND revoked_at IS NOT NULL",
                $accountId
            )),
            'Revoked server-side, so replaying the cookie cannot resurrect it.'
        );

        Auth::forget();
        $this->assertNull(Auth::sessions()->resolve($cookie));
    }

    // ------------------------------------------------------ password recovery

    public function testForgotPasswordAnswersTheSameForKnownAndUnknownAddresses(): void
    {
        $account = (new AccountRepository())->find($this->makeAccount());

        $known = $this->post('/auth/password/forgot', ['user_login' => $account->email]);

        RateLimiter::clear('pwforgot_ip', RateLimiter::ipHash());

        // A *fresh* unknown address each run. The per-address bucket is a
        // one-hour window held in a transient, so a hard-coded address here
        // 429s on the fourth run within an hour — which reads as a broken
        // endpoint rather than as the throttle doing its job.
        $unknown = $this->post('/auth/password/forgot', [
            'user_login' => 'nobody_' . wp_generate_password(10, false) . '@example.test',
        ]);

        $this->assertSame(200, $known->get_status());
        $this->assertSame(200, $unknown->get_status());
        $this->assertSame($known->get_data(), $unknown->get_data());
    }

    public function testResetPasswordChangesThePasswordAndRevokesEverySession(): void
    {
        global $wpdb;

        $accountId = $this->makeAccount();
        $account   = (new AccountRepository())->find($accountId);

        // Two devices signed in, which is the state a reset is meant to end.
        $this->signIn($accountId);
        Auth::sessions()->issue($accountId);

        $token = (new TokenService())->issue($accountId, TokenService::PURPOSE_RESET, 3600);

        Auth::forget();
        SessionCookie::clear();
        Csrf::ensureCookie();

        $response = $this->post('/auth/password/reset', [
            'token'    => $token,
            'password' => 'a-brand-new-password',
        ]);

        $this->assertSame(200, $response->get_status());

        $credentials = (new AccountRepository())->credentialsFor($accountId);
        $this->assertTrue(PasswordHasher::verify('a-brand-new-password', $credentials['password_hash']));
        $this->assertFalse(PasswordHasher::verify(self::PASSWORD, $credentials['password_hash']));

        $live = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_sessions
              WHERE account_id = %d AND revoked_at IS NULL",
            $accountId
        ));
        $this->assertSame(0, $live, 'Every device is signed out — the point of a reset.');

        // Not signed in automatically: whoever held the link may not be the owner.
        $this->assertFalse(Auth::check());
        $this->assertNotNull($account);
    }

    public function testAResetTokenWorksOnlyOnce(): void
    {
        $accountId = $this->makeAccount();
        $token     = (new TokenService())->issue($accountId, TokenService::PURPOSE_RESET, 3600);

        $first = $this->post('/auth/password/reset', [
            'token'    => $token,
            'password' => 'first-new-password',
        ]);
        $this->assertSame(200, $first->get_status());

        $second = $this->post('/auth/password/reset', [
            'token'    => $token,
            'password' => 'second-new-password',
        ]);

        $this->assertSame(400, $second->get_status());
        $this->assertSame('fc_invalid_reset_key', $second->get_data()['code']);
    }

    public function testAnExpiredResetTokenIsRefused(): void
    {
        global $wpdb;

        $accountId = $this->makeAccount();
        $token     = (new TokenService())->issue($accountId, TokenService::PURPOSE_RESET, 3600);

        // Move the expiry into the past rather than waiting an hour.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_account_tokens
                SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
              WHERE account_id = %d",
            $accountId
        ));

        $response = $this->post('/auth/password/reset', [
            'token'    => $token,
            'password' => 'a-brand-new-password',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_invalid_reset_key', $response->get_data()['code']);
    }

    public function testResetPasswordRejectsAForgedToken(): void
    {
        $response = $this->post('/auth/password/reset', [
            'token'    => str_repeat('a', 32) . '.' . str_repeat('b', 64),
            'password' => 'a-brand-new-password',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_invalid_reset_key', $response->get_data()['code']);
    }

    /**
     * An invitation redeems through the same endpoint, and activates the
     * account — which is how a bootstrapped or admin-created account becomes
     * usable.
     */
    public function testAnInviteTokenActivatesAPendingAccount(): void
    {
        $accountId = $this->makeAccount(Capabilities::ROLE_USER, [], Account::STATUS_PENDING);
        $token     = (new TokenService())->issue($accountId, TokenService::PURPOSE_INVITE, 3600);

        $response = $this->post('/auth/password/reset', [
            'token'    => $token,
            'password' => 'a-chosen-password',
        ]);

        $this->assertSame(200, $response->get_status());

        $account = (new AccountRepository())->find($accountId);
        $this->assertTrue($account->isActive(), 'Redeeming an invite activates the account.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        $request->set_header('X-FC-CSRF', $this->csrf());

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }
}
