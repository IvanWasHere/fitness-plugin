<?php

namespace FitnessClub\Database\Seeders;

use FitnessClub\Auth\Account;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Auth\PasswordHasher;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bulk data for query profiling. The performance targets (page < 2 s, API < 200 ms)
 * cannot be tuned against six seeded users — every query looks instant on an empty
 * table, and the indexes that matter only show up under volume.
 *
 * Generates N users × DAYS days of sessions, nutrition and health rows.
 *
 * These now need **real fc_accounts rows**: `fc_users.account_id` carries an
 * ON DELETE RESTRICT foreign key, so the old trick of writing synthetic ids
 * above a high offset would simply be rejected. They are marked by their login
 * prefix instead, which is a better handle anyway — it is visible in the table
 * rather than encoded in a number's magnitude, and purge() matches on it.
 *
 * The accounts get unusable password hashes. Nobody signs in as volume user 734,
 * and hashing a thousand real passwords at bcrypt cost 12 would take minutes.
 */
class VolumeSeeder extends Seeder
{
    /** Login prefix that marks an account as generated bulk data. */
    private const LOGIN_PREFIX = 'volume-';

    private const BATCH = 500;

    public function __construct(
        private int $userCount = 1000,
        private int $days = 180
    ) {
        parent::__construct();
    }

    public function run(): void
    {
        $suspended  = wp_suspend_cache_addition(true);
        $workoutIds = $this->wpdb->get_col('SELECT id FROM ' . $this->table('workouts') . ' LIMIT 10');
        if (!$workoutIds) {
            $this->note('  ! no workouts found — run the demo seed first');
            wp_suspend_cache_addition($suspended);

            return;
        }

        $userIds = $this->seedUsers();
        $this->note('volume users: ' . count($userIds));
        $sessions  = $this->seedSessions($userIds, $workoutIds);
        $nutrition = $this->seedNutrition($userIds);
        $health    = $this->seedHealth($userIds);
        wp_suspend_cache_addition($suspended);

        $this->note(sprintf('volume: %d sessions, %d nutrition days, %d health rows', $sessions, $nutrition, $health));
    }

    /** @return int[] */
    private function seedUsers(): array
    {
        $table = $this->table('users');
        $rows  = [];

        for ($i = 0; $i < $this->userCount; $i++) {
            $accountId = $this->volumeAccount($i);
            if (0 === $accountId) {
                continue;
            }

            $existing = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$table} WHERE account_id = %d",
                $accountId
            ));
            if ($existing) {
                continue;
            }

            $rows[] = $this->wpdb->prepare(
                '(%d,%s,%s,%f,%f,%s,%s,%s)',
                $accountId,
                'Volume User ' . $i,
                $i % 2 ? 'male' : 'female',
                60 + ($i % 40),
                160 + ($i % 30),
                'intermediate',
                'moderate',
                $this->now()
            );
            if (count($rows) >= self::BATCH) {
                $this->flushUsers($table, $rows);
                $rows = [];
            }
        }
        if ($rows) {
            $this->flushUsers($table, $rows);
        }

        $accounts = $this->table('accounts');

        return $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT u.id FROM {$table} u
               JOIN {$accounts} a ON a.id = u.account_id
              WHERE a.login LIKE %s
              ORDER BY u.id",
            $this->wpdb->esc_like(self::LOGIN_PREFIX) . '%'
        ));
    }

    /**
     * The account behind volume user $i, created if absent.
     *
     * Written directly rather than through AccountRepository::create() so the
     * hash is generated once per row instead of running bcrypt a thousand times.
     */
    private function volumeAccount(int $index): int
    {
        $table = $this->table('accounts');
        $login = self::LOGIN_PREFIX . $index;

        $existing = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE login = %s",
            $login
        ));

        if ($existing) {
            return $existing;
        }

        $this->wpdb->insert($table, [
            'login'         => $login,
            'password_hash' => PasswordHasher::unusable(),
            'display_name'  => 'Volume User ' . $index,
            'role'          => Capabilities::ROLE_USER,
            'status'        => Account::STATUS_ACTIVE,
            'timezone'      => 'UTC',
            'created_at'    => $this->now(),
            'updated_at'    => $this->now(),
        ]);

        return (int) $this->wpdb->insert_id;
    }

    /** @param string[] $rows */
    private function flushUsers(string $table, array $rows): void
    {
        $this->wpdb->query(
            "INSERT INTO `{$table}` (account_id, display_name, gender, weight_kg, height_cm, fitness_level, activity_level, updated_at) VALUES " . implode(',', $rows)
        );
    }

    /**
     * @param int[] $userIds
     * @param int[] $workoutIds
     */
    private function seedSessions(array $userIds, array $workoutIds): int
    {
        $table = $this->table('workout_sessions');
        $rows  = [];
        $count = 0;
        foreach ($userIds as $userId) {
            for ($d = 0; $d < $this->days; $d++) {
                if (($userId + $d) % 7 >= 4) { // ~4 sessions a week, deterministic
                    continue;
                }
                $date     = $this->daysAgo($d);
                $duration = 1800 + (($userId + $d) % 1800);
                $rows[]   = $this->wpdb->prepare(
                    '(%d,%d,%s,%s,%s,%d,%s,%f,%d,%s)',
                    $userId,
                    $workoutIds[($userId + $d) % count($workoutIds)],
                    $date,
                    $date . ' 08:00:00',
                    $date . ' 08:45:00',
                    $duration,
                    'completed',
                    100.0,
                    (int) round($duration * 0.11),
                    $this->now()
                );
                $count++;
                if (count($rows) >= self::BATCH) {
                    $this->flushSessions($table, $rows);
                    $rows = [];
                }
            }
        }
        if ($rows) {
            $this->flushSessions($table, $rows);
        }

        return $count;
    }

    /** @param string[] $rows */
    private function flushSessions(string $table, array $rows): void
    {
        $this->wpdb->query(
            "INSERT IGNORE INTO `{$table}` (user_id, workout_id, log_date, started_at, ended_at, duration_seconds, status, completion_percentage, calories_burned, updated_at) VALUES " . implode(',', $rows)
        );
    }

    /** @param int[] $userIds */
    private function seedNutrition(array $userIds): int
    {
        $table = $this->table('nutrition_days');
        $rows  = [];
        $count = 0;
        foreach ($userIds as $userId) {
            for ($d = 0; $d < $this->days; $d++) {
                $rows[] = $this->wpdb->prepare(
                    '(%d,%s,%d,%d,%d,%f,%f,%f,%s)',
                    $userId,
                    $this->daysAgo($d),
                    1000 + (($userId + $d) % 1500),
                    2400,
                    1600 + (($userId + $d) % 900),
                    120 + (($userId + $d) % 80),
                    180 + (($userId + $d) % 90),
                    50 + (($userId + $d) % 40),
                    $this->now()
                );
                $count++;
                if (count($rows) >= self::BATCH) {
                    $this->flushNutrition($table, $rows);
                    $rows = [];
                }
            }
        }
        if ($rows) {
            $this->flushNutrition($table, $rows);
        }

        return $count;
    }

    /** @param string[] $rows */
    private function flushNutrition(string $table, array $rows): void
    {
        $this->wpdb->query(
            "INSERT IGNORE INTO `{$table}` (user_id, log_date, water_ml, goal_calories, total_calories, total_protein_g, total_carbs_g, total_fat_g, updated_at) VALUES " . implode(',', $rows)
        );
    }

    /** @param int[] $userIds */
    private function seedHealth(array $userIds): int
    {
        $table = $this->table('health_stats');
        $rows  = [];
        $count = 0;
        foreach ($userIds as $userId) {
            for ($d = 0; $d < $this->days; $d += 2) {
                $weight = 70 + ($userId % 30) - ($d * 0.02);
                $rows[] = $this->wpdb->prepare(
                    '(%d,%s,%f,%f,%d,%f,%d,%d,%s)',
                    $userId,
                    $this->daysAgo($d),
                    round($weight, 2),
                    18 + (($userId + $d) % 8),
                    58 + (($userId + $d) % 20),
                    6.5 + (($userId + $d) % 3),
                    3 + (($userId + $d) % 3),
                    5 + (($userId + $d) % 5),
                    $this->now()
                );
                $count++;
                if (count($rows) >= self::BATCH) {
                    $this->flushHealth($table, $rows);
                    $rows = [];
                }
            }
        }
        if ($rows) {
            $this->flushHealth($table, $rows);
        }

        return $count;
    }

    /** @param string[] $rows */
    private function flushHealth(string $table, array $rows): void
    {
        $this->wpdb->query(
            "INSERT IGNORE INTO `{$table}` (user_id, record_date, weight_kg, body_fat_percentage, heart_rate_resting, sleep_hours, mood_score, energy_score, updated_at) VALUES " . implode(',', $rows)
        );
    }

    /**
     * Remove every synthetic row. Safe because volume users live above the
     * wp_user_id offset and cannot overlap real accounts.
     */
    public function purge(): void
    {
        $users    = $this->table('users');
        $accounts = $this->table('accounts');
        $like     = $this->wpdb->esc_like(self::LOGIN_PREFIX) . '%';

        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT u.id FROM {$users} u
               JOIN {$accounts} a ON a.id = u.account_id
              WHERE a.login LIKE %s",
            $like
        ));

        if (!$ids) {
            $this->note('volume purge: nothing to remove');

            return;
        }

        $in = implode(',', array_map('intval', $ids));
        foreach (['workout_sessions', 'nutrition_days', 'health_stats'] as $table) {
            $this->wpdb->query('DELETE FROM ' . $this->table($table) . " WHERE user_id IN ({$in})");
        }

        // fc_users before fc_accounts: the FK is ON DELETE RESTRICT, so the
        // other order is rejected rather than cascading.
        $this->wpdb->query("DELETE FROM {$users} WHERE id IN ({$in})");
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$accounts} WHERE login LIKE %s", $like));

        $this->note('volume purge: removed ' . count($ids) . ' synthetic users and their rows');
    }
}
