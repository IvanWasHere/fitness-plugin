<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\SessionCookie;
use PHPUnit\Framework\TestCase;

/**
 * Base for integration tests against the real WordPress runtime.
 *
 * Identity here is the **plugin's**, not WordPress': tests create fc_accounts
 * rows and sign in through Auth, because that is what the code under test
 * consults. `wp_set_current_user()` no longer has any effect on anything in this
 * plugin — which is itself worth a test, and BootPayloadTest has one.
 *
 * Everything created is cleaned up, so the development database is left as it
 * was found.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var int[] fc_accounts.id rows created here. */
    private array $createdAccounts = [];

    protected function tearDown(): void
    {
        global $wpdb;

        // Sign out before deleting, or a memoized account outlives its row and
        // the next test starts as a ghost.
        Auth::forget();
        SessionCookie::clear();
        wp_set_current_user(0);

        foreach ($this->createdAccounts as $id) {
            // Profiles first: fc_users and fc_trainers hold the account with
            // ON DELETE RESTRICT, so the account cannot go while they exist.
            //
            // This is not only the fixtures' business — signing in as a member
            // *creates* an fc_users row (AuthController::ensureProfileRow heals
            // accounts made outside the app), so any test that logs in leaves
            // one behind whether it meant to or not.
            $wpdb->delete($wpdb->prefix . 'fc_users', ['account_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_trainers', ['account_id' => $id]);

            // Sessions and tokens cascade.
            $wpdb->delete($wpdb->prefix . 'fc_accounts', ['id' => $id]);
        }
        $this->createdAccounts = [];

        parent::tearDown();
    }

    /**
     * Create a throwaway plugin account with a known password.
     *
     * @param string[] $extraCaps Per-account capability grants.
     */
    protected function makeAccount(
        string $role = Capabilities::ROLE_USER,
        array $extraCaps = [],
        string $status = Account::STATUS_ACTIVE
    ): int {
        global $wpdb;

        $suffix = wp_generate_password(10, false);

        $id = (new AccountRepository())->create([
            'login'        => 'fc_test_' . $suffix,
            'email'        => 'fc_test_' . $suffix . '@example.test',
            'password'     => self::PASSWORD,
            'display_name' => 'Test Account',
            'role'         => $role,
            'status'       => $status,
            // UTC on purpose: log_date and the streak are computed in the
            // account's own zone, and a floating zone makes those assertions
            // flaky.
            'timezone'     => 'UTC',
        ]);

        $this->assertGreaterThan(0, $id, 'Test account should be created.');

        if ([] !== $extraCaps) {
            $wpdb->update(
                $wpdb->prefix . 'fc_accounts',
                ['extra_caps' => wp_json_encode($extraCaps)],
                ['id' => $id]
            );
        }

        $this->createdAccounts[] = $id;

        return $id;
    }

    /**
     * Register an account the *code under test* created, so teardown removes it
     * too. Registration tests need this: the account they assert on was never
     * handed out by makeAccount().
     */
    protected function trackAccount(int $accountId): void
    {
        $this->createdAccounts[] = $accountId;
    }

    /**
     * Act as this account for the rest of the test — the replacement for
     * `wp_set_current_user()`.
     *
     * Goes through `Auth::login()` rather than poking at state, so the session
     * row, the cookie and the CSRF token all exist and the CSRF gate can be
     * exercised for real.
     */
    protected function signIn(int $accountId): void
    {
        $account = (new AccountRepository())->find($accountId);

        $this->assertNotNull($account, 'Account to sign in as should exist.');

        Auth::forget();
        Auth::login($account);
    }

    protected function signOut(): void
    {
        Auth::logout();
        Auth::forget();
    }

    /**
     * The CSRF token for the current session, for tests that dispatch writes.
     */
    protected function csrf(): string
    {
        return SessionCookie::readCsrf() ?? '';
    }

    /** The password every account made here is created with. */
    protected const PASSWORD = 'test-password-2026';
}
