<?php

namespace FitnessClub\Database\Seeders;

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
     * Find or create a WordPress user, returning its ID. Never overwrites an
     * existing account's password or role.
     */
    protected function wpUser(string $login, string $email, string $displayName, string $role): int
    {
        $user = get_user_by('login', $login) ?: get_user_by('email', $email);

        if ($user) {
            if (!in_array($role, (array) $user->roles, true)) {
                $user->add_role($role);
            }

            return (int) $user->ID;
        }

        $id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(24),
            'display_name' => $displayName,
            'first_name'   => explode(' ', $displayName)[0],
            'last_name'    => explode(' ', $displayName)[1] ?? '',
            'role'         => $role,
        ]);

        if (is_wp_error($id)) {
            $this->note("  ! could not create WP user {$login}: " . $id->get_error_message());

            return 0;
        }

        return (int) $id;
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
