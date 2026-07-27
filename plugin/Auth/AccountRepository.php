<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes fc_accounts.
 *
 * **Every SELECT names its columns.** Not `SELECT *` — the one column that must
 * never leave this class is `password_hash`, and a wildcard is how it
 * eventually ends up in a JSON response after somebody adds an account to a
 * payload. The single query that reads it (`credentialsFor`) returns a bare
 * array and is called from exactly one place.
 */
final class AccountRepository
{
    /** The projection every Account is built from. Never includes password_hash. */
    private const COLUMNS = 'id, login, email, display_name, role, status, locale, timezone,
                             avatar_url, extra_caps, last_login_at';

    public function find(int $id): ?Account
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- COLUMNS is a constant.
        $sql = "SELECT " . self::COLUMNS . " FROM {$wpdb->prefix}fc_accounts WHERE id = %d LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $row = $wpdb->get_row($wpdb->prepare($sql, $id), ARRAY_A);

        return $row ? Account::fromRow($row) : null;
    }

    /**
     * Look up by login **or** email — the sign-in form has one field, and
     * members never see their login handle.
     */
    public function findByIdentifier(string $identifier): ?Account
    {
        global $wpdb;

        $identifier = trim($identifier);
        if ('' === $identifier) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- COLUMNS is a constant.
        $sql = "SELECT " . self::COLUMNS . "
                  FROM {$wpdb->prefix}fc_accounts
                 WHERE login = %s OR email_canonical = %s
                 LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $row = $wpdb->get_row($wpdb->prepare($sql, $identifier, self::canonical($identifier)), ARRAY_A);

        return $row ? Account::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?Account
    {
        global $wpdb;

        $canonical = self::canonical($email);
        if ('' === $canonical) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- COLUMNS is a constant.
        $sql = "SELECT " . self::COLUMNS . "
                  FROM {$wpdb->prefix}fc_accounts WHERE email_canonical = %s LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $row = $wpdb->get_row($wpdb->prepare($sql, $canonical), ARRAY_A);

        return $row ? Account::fromRow($row) : null;
    }

    /**
     * The hash and the lockout state, for the sign-in path only.
     *
     * @return array{password_hash:string,failed_login_count:int,locked_until:?string}|null
     */
    public function credentialsFor(int $accountId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT password_hash, failed_login_count, locked_until
               FROM {$wpdb->prefix}fc_accounts WHERE id = %d LIMIT 1",
            $accountId
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return [
            'password_hash'      => (string) $row['password_hash'],
            'failed_login_count' => (int) $row['failed_login_count'],
            'locked_until'       => $row['locked_until'],
        ];
    }

    /**
     * Create an account. Returns its id.
     *
     * @param array<string,mixed> $attributes login, email, password, display_name, role, status, locale, timezone
     */
    public function create(array $attributes): int
    {
        global $wpdb;

        $now      = gmdate('Y-m-d H:i:s');
        $email    = isset($attributes['email']) ? trim((string) $attributes['email']) : '';
        $password = (string) ($attributes['password'] ?? '');

        $wpdb->insert($wpdb->prefix . 'fc_accounts', [
            'login'           => (string) $attributes['login'],
            'email'           => '' === $email ? null : $email,
            'email_canonical' => '' === $email ? null : self::canonical($email),
            'password_hash'   => '' === $password
                ? PasswordHasher::unusable()
                : PasswordHasher::hash($password),
            'display_name'    => (string) ($attributes['display_name'] ?? ''),
            'role'            => (string) ($attributes['role'] ?? Capabilities::ROLE_USER),
            'status'          => (string) ($attributes['status'] ?? Account::STATUS_ACTIVE),
            'locale'          => $attributes['locale'] ?? null,
            'timezone'        => $attributes['timezone'] ?? null,
            'password_changed_at' => '' === $password ? null : $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Replace the password, clear the lockout, and stamp the change.
     *
     * The caller is responsible for revoking sessions — see
     * SessionStore::revokeAllFor(). It is not done here because "set a
     * password" and "evict everyone" are separable: the bootstrap path sets a
     * password on an account that has no sessions at all.
     */
    public function setPassword(int $accountId, string $password): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->update(
            $wpdb->prefix . 'fc_accounts',
            [
                'password_hash'       => PasswordHasher::hash($password),
                'password_changed_at' => $now,
                'failed_login_count'  => 0,
                'locked_until'        => null,
                'updated_at'          => $now,
            ],
            ['id' => $accountId]
        );
    }

    /**
     * Re-write a hash at the current cost, leaving everything else alone.
     */
    public function rehash(int $accountId, string $password): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'fc_accounts',
            ['password_hash' => PasswordHasher::hash($password)],
            ['id' => $accountId]
        );
    }

    public function activate(int $accountId): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'fc_accounts',
            ['status' => Account::STATUS_ACTIVE, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $accountId]
        );
    }

    public function recordSuccessfulLogin(int $accountId): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->update(
            $wpdb->prefix . 'fc_accounts',
            [
                'last_login_at'      => $now,
                'failed_login_count' => 0,
                'locked_until'       => null,
                'updated_at'         => $now,
            ],
            ['id' => $accountId]
        );
    }

    /**
     * Count a failed attempt and lock the account once the threshold is passed.
     *
     * This is the half of throttling that RateLimiter cannot do: it limits per
     * IP, which is no obstacle at all to an attacker spreading attempts for one
     * account across many addresses.
     */
    public function recordFailedLogin(int $accountId): void
    {
        global $wpdb;

        $auth      = (array) FitnessClub()->config('fitnessclub.auth', []);
        $threshold = max(1, (int) ($auth['lockout_threshold'] ?? 10));
        $minutes   = max(1, (int) ($auth['lockout_minutes'] ?? 15));

        // One statement, so two simultaneous failures cannot both read the same
        // count and write the same increment.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_accounts
                SET failed_login_count = failed_login_count + 1,
                    locked_until = IF(failed_login_count + 1 >= %d,
                                      DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d MINUTE),
                                      locked_until),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d",
            $threshold,
            $minutes,
            $accountId
        ));
    }

    public function loginExists(string $login): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_accounts WHERE login = %s LIMIT 1",
            $login
        ));
    }

    public function emailExists(string $email): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_accounts WHERE email_canonical = %s LIMIT 1",
            self::canonical($email)
        ));
    }

    public function countByRole(string $role, string $status = Account::STATUS_ACTIVE): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_accounts WHERE role = %s AND status = %s",
            $role,
            $status
        ));
    }

    /**
     * Lowercased and trimmed. Uniqueness rides on this rather than on `email`,
     * because the collation that would otherwise enforce case-insensitivity is
     * a per-install variable.
     */
    public static function canonical(string $email): string
    {
        return strtolower(trim($email));
    }
}
