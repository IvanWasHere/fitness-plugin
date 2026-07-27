<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\AccountBootstrap;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\PasswordHasher;

/**
 * The first accounts, and the one-time credential display.
 *
 * This is the only path by which a fresh install becomes usable — the plugin
 * owns its identity, so without it nobody, including the WordPress
 * administrator who just activated the plugin, can sign in at all. It is also
 * the only place the plugin ever holds a plaintext password, so both halves are
 * worth pinning down: that it creates what it should, and that the credentials
 * really do disappear after one read.
 */
final class BootstrapAccountsTest extends IntegrationTestCase
{
    /** @var int[] Accounts created by the bootstrap, cleaned up here. */
    private array $bootstrapped = [];

    /** The real install's list of generated accounts, restored in teardown. */
    private mixed $realGeneratedOption = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The dev database has already been bootstrapped, so each test starts
        // from the "nothing here yet" state and puts it back afterwards.
        //
        // The generated-accounts option is *saved*, not just cleared: running
        // the bootstrap overwrites it, and leaving it pointing at the accounts
        // this test then deletes would empty the real settings screen.
        $this->realGeneratedOption = get_option(AccountBootstrap::GENERATED_OPTION);

        delete_option(AccountBootstrap::DONE_OPTION);
        delete_transient(AccountBootstrap::CREDENTIALS_TRANSIENT);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->bootstrapped as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_accounts', ['id' => $id]);
        }
        $this->bootstrapped = [];

        delete_transient(AccountBootstrap::CREDENTIALS_TRANSIENT);
        update_option(AccountBootstrap::DONE_OPTION, 1, false);

        if (false === $this->realGeneratedOption) {
            delete_option(AccountBootstrap::GENERATED_OPTION);
        } else {
            update_option(AccountBootstrap::GENERATED_OPTION, $this->realGeneratedOption, false);
        }

        parent::tearDown();
    }

    public function testBootstrapCreatesTheFirstAdminAndOneExampleOfEachRole(): void
    {
        $this->assertTrue($this->runBootstrapOnAnEmptyInstall());

        $credentials = AccountBootstrap::takeCredentials();

        $this->assertCount(4, $credentials, 'A first administrator plus one example per role.');

        $roles = array_column($credentials, 'role');
        $this->assertSame(
            [Capabilities::ROLE_ADMIN, Capabilities::ROLE_ADMIN, Capabilities::ROLE_TRAINER, Capabilities::ROLE_USER],
            $roles
        );

        $accounts = new AccountRepository();

        foreach ($credentials as $entry) {
            $account = $accounts->findByIdentifier($entry['login']);

            $this->assertNotNull($account, "Account {$entry['login']} exists.");
            $this->bootstrapped[] = $account->id;

            $this->assertTrue($account->isActive());
            $this->assertSame($entry['role'], $account->role);

            // The displayed password is the one that actually works — the whole
            // point of showing it.
            $stored = $accounts->credentialsFor($account->id);
            $this->assertTrue(
                PasswordHasher::verify($entry['password'], $stored['password_hash']),
                "The shown password signs {$entry['login']} in."
            );
        }
    }

    public function testGeneratedCredentialsFollowTheReadableShapeWithASuffix(): void
    {
        $this->assertTrue($this->runBootstrapOnAnEmptyInstall());

        $credentials = AccountBootstrap::takeCredentials();
        $this->rememberAccounts($credentials);

        foreach ($credentials as $entry) {
            $prefix = Capabilities::ROLE_ADMIN === $entry['role'] ? 'admin'
                : (Capabilities::ROLE_TRAINER === $entry['role'] ? 'trainer' : 'user');

            $this->assertMatchesRegularExpression(
                '/^' . $prefix . '[A-Z][a-z]+$/',
                $entry['login'],
                'Logins read as adminFalcon, trainerHarbor, userCedar.'
            );

            // pass{Word}{4}. The suffix is what takes this from ~12 bits against
            // a format anyone can read in the source to ~36.
            $this->assertMatchesRegularExpression(
                '/^pass[A-Z][a-z]+[A-Z0-9]{4}$/',
                $entry['password']
            );
            $this->assertGreaterThanOrEqual(
                (int) FitnessClub()->config('fitnessclub.limits.password_min_length', 10),
                strlen($entry['password']),
                'Generated passwords clear the minimum the API enforces.'
            );
        }
    }

    /**
     * The settings screen lists the generated accounts, and stops listing one
     * the moment its row is gone — which is the whole contract of that list.
     */
    public function testGeneratedAccountsAreListedUntilTheRowIsDeleted(): void
    {
        global $wpdb;

        $this->assertTrue($this->runBootstrapOnAnEmptyInstall());
        $credentials = AccountBootstrap::takeCredentials();
        $this->rememberAccounts($credentials);

        $listed = AccountBootstrap::generatedAccounts();
        $this->assertCount(4, $listed);
        $this->assertSame(
            array_column($credentials, 'login'),
            array_column($listed, 'login'),
            'Listed in creation order, so the first administrator is first.'
        );

        // Delete one the way the settings screen does.
        $victim = (int) $listed[2]['id'];
        $wpdb->delete($wpdb->prefix . 'fc_users', ['account_id' => $victim]);
        $wpdb->delete($wpdb->prefix . 'fc_trainers', ['account_id' => $victim]);
        $wpdb->delete($wpdb->prefix . 'fc_accounts', ['id' => $victim]);

        $after = AccountBootstrap::generatedAccounts();

        $this->assertCount(3, $after);
        $this->assertNotContains($victim, array_map('intval', array_column($after, 'id')));

        // Still flagged as generated: the option is a record of what was
        // created, and forgetting the id would be a second thing to keep in
        // sync. Existence is decided by the table.
        $this->assertTrue(AccountBootstrap::isGenerated($victim));
    }

    public function testAccountsCreatedByHandAreNotMarkedAsGenerated(): void
    {
        $this->assertFalse(
            AccountBootstrap::isGenerated($this->makeAccount()),
            'Only the bootstrap marks accounts, so the settings screen cannot offer to delete a real one.'
        );
    }

    public function testCredentialsAreShownOnceAndThenGone(): void
    {
        $this->assertTrue($this->runBootstrapOnAnEmptyInstall());

        $first = AccountBootstrap::takeCredentials();
        $this->rememberAccounts($first);
        $this->assertCount(4, $first);

        // The requested behaviour: refresh the page and they are gone. Reading
        // consumes them, so there is no path that leaves them lying in the
        // options table.
        $this->assertSame([], AccountBootstrap::takeCredentials());
    }

    public function testBootstrapDoesNotRunTwice(): void
    {
        $this->assertTrue($this->runBootstrapOnAnEmptyInstall());
        $this->rememberAccounts(AccountBootstrap::takeCredentials());

        $before = $this->accountCount();

        AccountBootstrap::ensure();

        $this->assertSame($before, $this->accountCount(), 'The option guard holds.');
    }

    public function testBootstrapStandsDownWhenAnAdministratorAlreadyExists(): void
    {
        // The restored-database case: the tables have accounts but the option is
        // missing. Minting a second administrator here would be a silent
        // privilege grant on somebody else's install.
        $existing = $this->makeAccount(Capabilities::ROLE_ADMIN);

        $before = $this->accountCount();
        AccountBootstrap::ensure();

        $this->assertSame($before, $this->accountCount());
        $this->assertSame([], AccountBootstrap::takeCredentials());
        $this->assertNotEmpty(get_option(AccountBootstrap::DONE_OPTION), 'And it marks itself done.');
        $this->assertGreaterThan(0, $existing);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run the bootstrap as if on a fresh install.
     *
     * The dev database already has administrators, and the bootstrap refuses to
     * run when one exists — correctly. So they are hidden for the duration by
     * flipping their role, and restored immediately afterwards.
     */
    private function runBootstrapOnAnEmptyInstall(): bool
    {
        global $wpdb;

        $admins = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fc_accounts WHERE role = 'admin' AND status = 'active'"
        );

        foreach ($admins as $id) {
            $wpdb->update($wpdb->prefix . 'fc_accounts', ['role' => 'user'], ['id' => (int) $id]);
        }

        AccountBootstrap::ensure();

        foreach ($admins as $id) {
            $wpdb->update($wpdb->prefix . 'fc_accounts', ['role' => 'admin'], ['id' => (int) $id]);
        }

        return true;
    }

    /**
     * @param array<int,array{login:string,password:string,role:string,label:string}> $credentials
     */
    private function rememberAccounts(array $credentials): void
    {
        $accounts = new AccountRepository();

        foreach ($credentials as $entry) {
            $account = $accounts->findByIdentifier($entry['login']);
            if (null !== $account) {
                $this->bootstrapped[] = $account->id;
            }
        }
    }

    private function accountCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fc_accounts");
    }
}
