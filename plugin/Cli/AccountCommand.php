<?php

namespace FitnessClub\Cli;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\SessionStore;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Manage plugin accounts — `wp fitnessclub account <subcommand>`.
 *
 * This is the operable path when the browser is not one: mail is broken on a
 * surprising share of fresh WordPress installs, and the plugin owns its own
 * credentials, so without this a site whose only administrator lost their
 * password has no way back in short of editing the database by hand.
 *
 * It runs as "system": there is no session in WP-CLI, and shell access on the
 * server is the authorisation.
 *
 * ## EXAMPLES
 *
 *     wp fitnessclub account list
 *     wp fitnessclub account create --login=coach --email=c@example.com --role=trainer
 *     wp fitnessclub account reset-password adminFalcon
 *     wp fitnessclub account promote adminFalcon --role=admin
 *     wp fitnessclub account revoke-sessions adminFalcon
 */
class AccountCommand
{
    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fc_accounts';
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            WP_CLI::error('FitnessClub tables not found. Activate the plugin first.');
        }

        $subcommand = $args[0] ?? '';

        match ($subcommand) {
            'list'            => $this->list(),
            'create'          => $this->create($assoc),
            'reset-password'  => $this->resetPassword($args[1] ?? ''),
            'promote'         => $this->promote($args[1] ?? '', $assoc),
            'revoke-sessions' => $this->revokeSessions($args[1] ?? ''),
            default           => WP_CLI::error(
                'Usage: wp fitnessclub account <list|create|reset-password|promote|revoke-sessions>'
            ),
        };
    }

    private function list(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, login, email, display_name, role, status, last_login_at
               FROM {$wpdb->prefix}fc_accounts
              ORDER BY id ASC",
            ARRAY_A
        );

        if (!$rows) {
            WP_CLI::log('No accounts.');

            return;
        }

        WP_CLI\Utils\format_items(
            'table',
            $rows,
            ['id', 'login', 'email', 'display_name', 'role', 'status', 'last_login_at']
        );
    }

    /**
     * @param array<string,string> $assoc
     */
    private function create(array $assoc): void
    {
        $login = trim((string) ($assoc['login'] ?? ''));
        $email = trim((string) ($assoc['email'] ?? ''));
        $role  = (string) ($assoc['role'] ?? Capabilities::ROLE_USER);
        $name  = (string) ($assoc['display-name'] ?? $login);

        if ('' === $login) {
            WP_CLI::error('--login is required.');
        }

        if (!Capabilities::isRole($role)) {
            WP_CLI::error('--role must be one of: ' . implode(', ', Capabilities::ROLES));
        }

        $accounts = new AccountRepository();

        if ($accounts->loginExists($login)) {
            WP_CLI::error("An account with the login '{$login}' already exists.");
        }

        if ('' !== $email && $accounts->emailExists($email)) {
            WP_CLI::error("An account with the email '{$email}' already exists.");
        }

        $password = $this->generatePassword();

        $accounts->create([
            'login'        => $login,
            'email'        => $email,
            'password'     => $password,
            'display_name' => $name,
            'role'         => $role,
            'status'       => Account::STATUS_ACTIVE,
        ]);

        WP_CLI::success("Created {$role} account '{$login}'.");
        WP_CLI::log("Password: {$password}");
        WP_CLI::warning('This password is shown once. Store it now.');
    }

    private function resetPassword(string $login): void
    {
        $account = $this->require($login);

        $password = $this->generatePassword();

        $accounts = new AccountRepository();
        $accounts->setPassword($account->id, $password);
        $accounts->activate($account->id);

        // A password change evicts every session, here as everywhere else — the
        // reason for running this command is often that somebody else is in the
        // account.
        (new SessionStore())->revokeAllFor($account->id);

        WP_CLI::success("Reset the password for '{$account->login}' and signed out every device.");
        WP_CLI::log("Password: {$password}");
        WP_CLI::warning('This password is shown once. Store it now.');
    }

    /**
     * @param array<string,string> $assoc
     */
    private function promote(string $login, array $assoc): void
    {
        global $wpdb;

        $account = $this->require($login);
        $role    = (string) ($assoc['role'] ?? Capabilities::ROLE_ADMIN);

        if (!Capabilities::isRole($role)) {
            WP_CLI::error('--role must be one of: ' . implode(', ', Capabilities::ROLES));
        }

        $wpdb->update(
            $wpdb->prefix . 'fc_accounts',
            ['role' => $role, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $account->id]
        );

        // The role decides which SPA is served and which capabilities apply, so
        // an open session would keep the old answer until it expired.
        (new SessionStore())->revokeAllFor($account->id);

        WP_CLI::success("'{$account->login}' is now a {$role}. Existing sessions were revoked.");
    }

    private function revokeSessions(string $login): void
    {
        $account = $this->require($login);

        (new SessionStore())->revokeAllFor($account->id);

        WP_CLI::success("Signed out every device for '{$account->login}'.");
    }

    private function require(string $login): Account
    {
        if ('' === trim($login)) {
            WP_CLI::error('Which account? Pass a login or an email address.');
        }

        $account = (new AccountRepository())->findByIdentifier($login);

        if (null === $account) {
            WP_CLI::error("No account matches '{$login}'.");
        }

        return $account;
    }

    /**
     * Sixteen characters from an unambiguous alphabet — long enough that it does
     * not matter that it was printed to a terminal that keeps scrollback.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
