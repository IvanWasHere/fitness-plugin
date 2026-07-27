<?php

namespace FitnessClub\Database\Seeders;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\AccountRepository;
use FitnessClub\WPBones\Database\DB;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Base for the on-demand, multi-table seeders (demo + volume).
 *
 * Distinct from WP Bones' `Seeder`, which is single-table and runs on activation.
 * These span a dozen related tables and are invoked deliberately via
 * `php bones fitnessclub:seed`, never on activation (so demo accounts never land on
 * a production site).
 *
 * Everything writes through `upsert()` on a natural key, so seeding twice updates
 * rather than duplicates.
 */
abstract class Seeder
{
    /** @var \wpdb */
    protected $wpdb;

    /** @var string[] Log lines for the console runner. */
    protected array $log = [];

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
    }

    abstract public function run(): void;

    /**
     * @return string[]
     */
    public function messages(): array
    {
        return $this->log;
    }

    protected function note(string $line): void
    {
        $this->log[] = $line;
    }

    protected function table(string $name): string
    {
        return DB::getTableName('fc_' . ltrim($name, '_'));
    }

    /**
     * Insert or update a row identified by $match, returning its id.
     *
     * @param string              $table Unprefixed table name without fc_, e.g. "users".
     * @param array<string,mixed> $match Natural key.
     * @param array<string,mixed> $data  Columns to write.
     */
    protected function upsert(string $table, array $match, array $data): int
    {
        $full = $this->table($table);

        $where  = [];
        $params = [];
        foreach ($match as $column => $value) {
            $where[]  = "`{$column}` = %s";
            $params[] = $value;
        }

        $sql = "SELECT id FROM `{$full}` WHERE " . implode(' AND ', $where) . ' LIMIT 1';
        $id  = (int) $this->wpdb->get_var($this->wpdb->prepare($sql, $params));

        if ($id > 0) {
            $this->wpdb->update($full, $data, ['id' => $id]);

            return $id;
        }

        $this->wpdb->insert($full, $data + $match);

        return (int) $this->wpdb->insert_id;
    }

    /**
     * The password every demo account gets.
     *
     * A known constant, because a demo account nobody can sign in to is not a
     * demo account — and because these are only ever created by an explicit
     * `wp fitnessclub seed --demo`, never on activation. It is long enough to
     * clear the `password_min_length` floor.
     */
    public const DEMO_PASSWORD = 'demo-password-2026';

    /**
     * Find or create a plugin account, returning its fc_accounts.id.
     *
     * Never touches an existing account's password or role: re-running the
     * seeder against a database somebody has been using must not silently reset
     * their credentials.
     */
    protected function account(string $login, string $email, string $displayName, string $role): int
    {
        $accounts = new AccountRepository();

        $existing = $accounts->findByIdentifier($login) ?? $accounts->findByEmail($email);
        if (null !== $existing) {
            return $existing->id;
        }

        return $accounts->create([
            'login'        => $login,
            'email'        => $email,
            'password'     => self::DEMO_PASSWORD,
            'display_name' => $displayName,
            'role'         => $role,
            'status'       => Account::STATUS_ACTIVE,
            'timezone'     => 'UTC',
            'locale'       => 'en_US',
        ]);
    }

    /**
     * The account id behind a demo login, or 0.
     */
    protected function accountId(string $login): int
    {
        return (new AccountRepository())->findByIdentifier($login)?->id ?? 0;
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    protected function daysAgo(int $days): string
    {
        return gmdate('Y-m-d', strtotime("-{$days} days"));
    }
}
