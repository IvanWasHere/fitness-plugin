<?php

namespace FitnessClub\Database\Seeders;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Base seeder.
 *
 * Deliberately NOT extending WP Bones' Seeder: that one is single-table
 * (`$tablename` is mandatory) and runs from its constructor, whereas every
 * seeder here spans a dozen related tables and must be callable on demand.
 *
 * Everything is written through `upsert()` on a natural key, so seeding twice
 * updates rather than duplicates. That matters because WP Bones includes
 * database/seeders/*.php on EVERY activation.
 */
abstract class Seeder
{
    /** @var \wpdb */
    protected $wpdb;

    /** @var string[] Human-readable log lines, for the CLI runner. */
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
        return $this->wpdb->prefix . 'fc_' . ltrim($name, '_');
    }

    /**
     * Insert or update a row identified by $match, returning its id.
     *
     * @param string               $table Unprefixed table name, e.g. "users".
     * @param array<string,mixed>  $match Natural key.
     * @param array<string,mixed>  $data  Columns to write.
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

        $sql = 'SELECT id FROM `' . $full . '` WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $id  = (int) $this->wpdb->get_var($this->wpdb->prepare($sql, $params));

        if ($id > 0) {
            $this->wpdb->update($full, $data, ['id' => $id]);

            return $id;
        }

        $this->wpdb->insert($full, $data + $match);

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Find or create a WordPress user, returning its ID.
     *
     * Never overwrites an existing account's password or role — a seeder must
     * not be able to take over a real user that happens to share a login.
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

    /**
     * A UTC date $days ago.
     */
    protected function daysAgo(int $days): string
    {
        return gmdate('Y-m-d', strtotime("-{$days} days"));
    }
}
