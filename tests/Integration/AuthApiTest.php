<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Providers\RoleProvider;
use FitnessClub\Support\RateLimiter;
use WP_REST_Request;

/**
 * `/auth/*` — the session lifecycle (W1.3, plans/02-api-contract.md#auth).
 *
 * Against the real REST server and the real database: the whole point of these
 * endpoints is what WordPress does with cookies, nonces and password hashing, so
 * a mocked version would assert nothing.
 */
final class AuthApiTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /** @var int[] fc_users rows to clean up (created by the register endpoint). */
    private array $createdLogins = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every test shares one IP hash, so a previous test's attempts would
        // spend this one's budget.
        foreach (['login', 'register', 'pwforgot_ip', 'pwreset', 'api'] as $bucket) {
            RateLimiter::clear($bucket, RateLimiter::ipHash());
        }

        // No test may send real mail.
        add_filter('pre_wp_mail', '__return_true', 999);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', '__return_true', 999);

        global $wpdb;
        foreach ($this->createdLogins as $wpUserId) {
            $wpdb->delete($wpdb->prefix . 'fc_users', ['wp_user_id' => $wpUserId]);
            wp_delete_user($wpUserId);
        }
        $this->createdLogins = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------- login

    public function testLoginReturnsTheBootPayloadAndAWorkingNonce(): void
    {
        $userId = $this->makeMember();
        $email  = get_userdata($userId)->user_email;

        $response = $this->post('/auth/login', ['user_login' => $email, 'password' => self::PASSWORD]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame($userId, $data['user']['wp_user_id']);
        $this->assertSame('fc_user', $data['user']['role']);
        $this->assertSame('user', $data['app']['spa'], 'A member resolves to the user SPA.');
        $this->assertNotEmpty($data['nonce']);
        $this->assertNotEmpty($data['restUrl']);
        $this->assertNotEmpty($data['ajaxUrl'], 'The client needs this to refresh an expired nonce.');

        // The session really was established, not just described.
        $this->assertSame($userId, get_current_user_id());

        // The nonce was minted for the session that login created — this is the
        // cookie-bridging fix in AuthServiceProvider. Without it the value comes
        // back signed against the anonymous session and every later call 403s.
        $this->assertSame(1, wp_verify_nonce($data['nonce'], 'wp_rest'));
    }

    public function testLoginWithAWrongPasswordIsFlatlyRejected(): void
    {
        $userId = $this->makeMember();
        $email  = get_userdata($userId)->user_email;

        $response = $this->post('/auth/login', ['user_login' => $email, 'password' => 'not-the-password']);

        $this->assertSame(401, $response->get_status());
        $this->assertSame('fc_invalid_credentials', $response->get_data()['code']);
        $this->assertSame(0, get_current_user_id());
    }

    public function testLoginTellsUnknownAccountsApartFromWrongPasswordsInNoWay(): void
    {
        $userId = $this->makeMember();
        $email  = get_userdata($userId)->user_email;

        $wrongPassword = $this->post('/auth/login', ['user_login' => $email, 'password' => 'nope']);
        RateLimiter::clear('login', RateLimiter::ipHash());
        $unknownUser = $this->post('/auth/login', [
            'user_login' => 'nobody-here@example.test',
            'password'   => 'nope',
        ]);

        // Identical code, status and message: the endpoint is not an oracle for
        // "does this address have an account".
        $this->assertSame($wrongPassword->get_status(), $unknownUser->get_status());
        $this->assertSame($wrongPassword->get_data()['code'], $unknownUser->get_data()['code']);
        $this->assertSame($wrongPassword->get_data()['message'], $unknownUser->get_data()['message']);
    }

    public function testLoginIsRateLimitedPerIp(): void
    {
        $limit = (int) FitnessClub()->config('fitnessclub.limits.login_per_minute_ip');
        $this->assertGreaterThan(0, $limit);

        for ($i = 0; $i < $limit; $i++) {
            $response = $this->post('/auth/login', ['user_login' => 'ghost@example.test', 'password' => 'x']);
            $this->assertSame(401, $response->get_status(), "Attempt {$i} should still be allowed.");
        }

        $blocked = $this->post('/auth/login', ['user_login' => 'ghost@example.test', 'password' => 'x']);

        $this->assertSame(429, $blocked->get_status());
        $data = $blocked->get_data();
        $this->assertSame('fc_rate_limited', $data['code']);
        $this->assertSame($limit, $data['data']['limit']);
        $this->assertGreaterThan(0, $data['data']['retry_after'], 'Retry-After must never say "now".');
    }

    public function testLoginIsRefusedWhileAlreadySignedIn(): void
    {
        $userId = $this->makeMember();
        wp_set_current_user($userId);

        $response = $this->post('/auth/login', [
            'user_login' => get_userdata($userId)->user_email,
            'password'   => self::PASSWORD,
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_already_authenticated', $response->get_data()['code']);
    }

    // ------------------------------------------------------------- register

    public function testRegisterCreatesAMemberWithAProfileRowAndSignsThemIn(): void
    {
        global $wpdb;

        $email    = uniqid('fc_reg_', true) . '@example.test';
        $response = $this->post('/auth/register', [
            'email'        => $email,
            'password'     => self::PASSWORD,
            'display_name' => 'Casey Rivers',
        ]);

        $this->assertSame(201, $response->get_status());

        $data   = $response->get_data();
        $wpUser = get_user_by('email', $email);
        $this->assertNotFalse($wpUser, 'The WordPress user should exist.');
        $this->createdLogins[] = (int) $wpUser->ID;

        $this->assertContains(RoleProvider::ROLE_USER, (array) $wpUser->roles);
        $this->assertSame('Casey Rivers', $data['user']['display_name']);
        $this->assertSame((int) $wpUser->ID, get_current_user_id(), 'Registration signs the member in.');

        // The fc_users row is what every ownership check hangs off — without it
        // the account exists in WordPress and nowhere in the product.
        $profileId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE wp_user_id = %d",
            $wpUser->ID
        ));
        $this->assertNotNull($profileId);
        $this->assertSame((int) $profileId, $data['user']['id']);
    }

    public function testRegisterRejectsAShortPassword(): void
    {
        $response = $this->post('/auth/register', [
            'email'    => uniqid('fc_reg_', true) . '@example.test',
            'password' => 'short',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_weak_password', $response->get_data()['code']);
    }

    public function testRegisterRejectsAnAddressThatAlreadyHasAnAccount(): void
    {
        $userId = $this->makeMember();

        $response = $this->post('/auth/register', [
            'email'    => get_userdata($userId)->user_email,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_email_taken', $response->get_data()['code']);
    }

    // --------------------------------------------------------------- me

    public function testMeRequiresASession(): void
    {
        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'));

        $this->assertSame(401, $response->get_status());
        $this->assertSame('fc_not_authenticated', $response->get_data()['code']);
    }

    public function testMeResolvesTheSpaFromTheRole(): void
    {
        $cases = [
            'user'    => $this->makeMember(),
            'trainer' => $this->makeUser(RoleProvider::ROLE_TRAINER),
            'admin'   => $this->makeUser('administrator'),
        ];

        foreach ($cases as $expectedSpa => $userId) {
            wp_set_current_user($userId);

            $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'));
            $data     = $response->get_data();

            $this->assertSame(200, $response->get_status());
            $this->assertSame($expectedSpa, $data['app']['spa'], "A {$expectedSpa} should get the {$expectedSpa} SPA.");
            $this->assertSame((int) $userId, $data['user']['wp_user_id']);
            $this->assertArrayHasKey('colors', $data['theme'], 'The boot payload carries theme tokens.');
        }
    }

    public function testMeFallsBackToFreeTierEntitlementsWithNoSubscription(): void
    {
        wp_set_current_user($this->makeMember());

        $data = rest_get_server()
            ->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/auth/me'))
            ->get_data();

        // Fail open to the floor, never closed — a user with no plan can still
        // log their own workouts (plans/03-backend.md#entitlements).
        $this->assertTrue($data['entitlements']['can_log_workouts']);
        $this->assertFalse($data['entitlements']['has_active_subscription']);
        $this->assertSame(0, $data['entitlements']['max_trainers']);
        $this->assertSame(0, $data['entitlements']['trainers_used']);
        $this->assertSame([], $data['subscriptions']);
        $this->assertSame([], $data['trainers']);
    }

    // ------------------------------------------------------------- logout

    public function testLogoutEndsTheSessionAndReturnsAGuestNonce(): void
    {
        wp_set_current_user($this->makeMember());

        $response = $this->post('/auth/logout', []);

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['ok']);
        $this->assertNotEmpty($response->get_data()['nonce']);
        $this->assertSame(0, get_current_user_id());
    }

    // ------------------------------------------------------- password reset

    public function testForgotPasswordAnswersTheSameForKnownAndUnknownAddresses(): void
    {
        $userId = $this->makeMember();

        $email = get_userdata($userId)->user_email;
        $this->clearForgotBucketsFor($email, 'nobody-here@example.test');

        $known = $this->post('/auth/password/forgot', ['user_login' => $email]);
        $unknown = $this->post('/auth/password/forgot', ['user_login' => 'nobody-here@example.test']);

        $this->assertSame(200, $known->get_status());
        $this->assertSame(200, $unknown->get_status());
        $this->assertSame($known->get_data(), $unknown->get_data());
    }

    public function testForgotPasswordIsRateLimitedPerAddress(): void
    {
        $limit = (int) FitnessClub()->config('fitnessclub.limits.password_reset_per_hour');
        $email = 'flooded@example.test';

        // This bucket's window is an hour, so a previous run of the suite would
        // otherwise still be holding it.
        $this->clearForgotBucketsFor($email);

        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(200, $this->post('/auth/password/forgot', ['user_login' => $email])->get_status());
        }

        $blocked = $this->post('/auth/password/forgot', ['user_login' => $email]);

        $this->assertSame(429, $blocked->get_status());
        $this->assertSame('fc_rate_limited', $blocked->get_data()['code']);
    }

    public function testResetPasswordChangesThePasswordWithAValidKey(): void
    {
        $userId = $this->makeMember();
        $user   = get_userdata($userId);
        $key    = get_password_reset_key($user);
        $this->assertNotInstanceOf(\WP_Error::class, $key);

        $response = $this->post('/auth/password/reset', [
            'key'      => $key,
            'login'    => $user->user_login,
            'password' => 'a-brand-new-passphrase',
        ]);

        $this->assertSame(200, $response->get_status());

        $authenticated = wp_authenticate($user->user_login, 'a-brand-new-passphrase');
        $this->assertNotInstanceOf(\WP_Error::class, $authenticated);
        $this->assertSame($userId, $authenticated->ID);

        // The key is single-use: replaying it must not work.
        $replay = $this->post('/auth/password/reset', [
            'key'      => $key,
            'login'    => $user->user_login,
            'password' => 'yet-another-passphrase',
        ]);
        $this->assertSame(400, $replay->get_status());
        $this->assertSame('fc_invalid_reset_key', $replay->get_data()['code']);
    }

    public function testResetPasswordWorksWhileStillSignedInOnThisBrowser(): void
    {
        $userId = $this->makeMember();
        $user   = get_userdata($userId);
        $key    = get_password_reset_key($user);
        wp_set_current_user($userId);

        // The key is the authorisation, not the absence of a session — a user
        // who asked for a reset on their phone must be able to follow the link
        // on the laptop where they are still signed in.
        $response = $this->post('/auth/password/reset', [
            'key'      => $key,
            'login'    => $user->user_login,
            'password' => 'a-brand-new-passphrase',
        ]);

        $this->assertSame(200, $response->get_status());
    }

    public function testResetPasswordRejectsAForgedKey(): void
    {
        $userId = $this->makeMember();

        $response = $this->post('/auth/password/reset', [
            'key'      => 'made-up-key',
            'login'    => get_userdata($userId)->user_login,
            'password' => 'a-brand-new-passphrase',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_invalid_reset_key', $response->get_data()['code']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * A member with a known password and an fc_users row.
     */
    private function makeMember(): int
    {
        $id = $this->makeUser(RoleProvider::ROLE_USER);
        wp_set_password(self::PASSWORD, $id);

        return $id;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }

    private function clearForgotBucketsFor(string ...$emails): void
    {
        RateLimiter::clear('pwforgot_ip', RateLimiter::ipHash());

        foreach ($emails as $email) {
            RateLimiter::clear('pwforgot', RateLimiter::keyFor($email));
        }
    }
}
