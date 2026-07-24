<?php

namespace FitnessClub\Database\Seeders;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bulk data for query profiling.
 *
 * The performance targets in the specification (page < 2 s, API < 200 ms) cannot
 * be tuned against six seeded users — every query looks instant on an empty
 * table, and the indexes that matter are the ones you only discover under
 * volume. This generates a realistic corpus:
 *
 *   N users x DAYS days of workout sessions, set logs, nutrition and health rows.
 *
 * Defaults to 1 000 users x 180 days ~ 2M rows. Inserts in batches with WP's
 * object cache suspended, because otherwise the run itself becomes the bottleneck.
 *
 * These users are NOT WordPress users — they are fc_users rows with synthetic
 * wp_user_id values above a high offset, so they can never collide with, or be
 * mistaken for, real accounts.
 */
class VolumeSeeder extends Seeder
{
    /** wp_user_id values start here so synthetic rows are unmistakable. */
    private const WP_USER_OFFSET = 900000;

    private const BATCH = 500;

    public function __construct(
        private int $userCount = 1000,
        private int $days = 180
    ) {
        parent::__construct();
    }

    public function run(): void
    {
        $suspended = wp_suspend_cache_addition(true);
        $t0        = microtime(true);

        $workoutIds = $this->wpdb->get_col('SELECT id FROM `' . $this->table('workouts') . '` LIMIT 10');

        if (!$workoutIds) {
            $this->note('  ! no workouts found — run the demo seeder first');
            wp_suspend_cache_addition($suspended);

            return;
        }

        $userIds = $this->seedUsers();
        $this->note('volume users: ' . count($userIds));

        $sessions  = $this->seedSessions($userIds, $workoutIds);
        $nutrition = $this->seedNutrition($userIds);
        $health    = $this->seedHealth($userIds);

        wp_suspend_cache_addition($suspended);

        $this->note(sprintf(
            'volume: %d sessions, %d nutrition days, %d health rows in %.1fs',
            $sessions,
            $nutrition,
            $health,
            microtime(true) - $t0
        ));
    }

    /** @return int[] */
    private function seedUsers(): array
    {
        $table = $this->table('users');
        $ids   = [];
        $rows  = [];

        for ($i = 0; $i < $this->userCount; $i++) {
            $wpId = self::WP_USER_OFFSET + $i;

            $existing = (int) $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT id FROM `{$table}` WHERE wp_user_id = %d", $wpId)
            );

            if ($existing) {
                $ids[] = $existing;
                continue;
            }

            $rows[] = $this->wpdb->prepare(
                '(%d,%s,%s,%f,%f,%s,%s,%s)',
                $wpId,
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

        return $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE wp_user_id >= %d ORDER BY id",
            self::WP_USER_OFFSET
        ));
    }

    /** @param string[] $rows */
    private function flushUsers(string $table, array $rows): void
    {
        $this->wpdb->query(
            "INSERT INTO `{$table}`
             (wp_user_id, display_name, gender, weight_kg, height_cm, fitness_level, activity_level, updated_at)
             VALUES " . implode(',', $rows)
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
                // ~4 sessions a week, deterministic so re-runs are comparable.
                if (($userId + $d) % 7 >= 4) {
                    continue;
                }

                $date     = $this->daysAgo($d);
                $duration = 1800 + (($userId + $d) % 1800);

                $rows[] = $this->wpdb->prepare(
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
            "INSERT IGNORE INTO `{$table}`
             (user_id, workout_id, log_date, started_at, ended_at, duration_seconds,
              status, completion_percentage, calories_burned, updated_at)
             VALUES " . implode(',', $rows)
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
                    $this->wpdb->query(
                        "INSERT IGNORE INTO `{$table}`
                         (user_id, log_date, water_ml, goal_calories, total_calories,
                          total_protein_g, total_carbs_g, total_fat_g, updated_at)
                         VALUES " . implode(',', $rows)
                    );
                    $rows = [];
                }
            }
        }

        if ($rows) {
            $this->wpdb->query(
                "INSERT IGNORE INTO `{$table}`
                 (user_id, log_date, water_ml, goal_calories, total_calories,
                  total_protein_g, total_carbs_g, total_fat_g, updated_at)
                 VALUES " . implode(',', $rows)
            );
        }

        return $count;
    }

    /** @param int[] $userIds */
    private function seedHealth(array $userIds): int
    {
        $table = $this->table('health_stats');
        $rows  = [];
        $count = 0;

        foreach ($userIds as $userId) {
            for ($d = 0; $d < $this->days; $d += 2) {
                $weight = 70 + (($userId % 30)) - ($d * 0.02);

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
            "INSERT IGNORE INTO `{$table}`
             (user_id, record_date, weight_kg, body_fat_percentage, heart_rate_resting,
              sleep_hours, mood_score, energy_score, updated_at)
             VALUES " . implode(',', $rows)
        );
    }

    /**
     * Remove every synthetic row. Safe because volume users live above the
     * wp_user_id offset and cannot overlap real accounts.
     */
    public function purge(): void
    {
        $users = $this->table('users');
        $ids   = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM `{$users}` WHERE wp_user_id >= %d",
            self::WP_USER_OFFSET
        ));

        if (!$ids) {
            $this->note('volume purge: nothing to remove');

            return;
        }

        $in = implode(',', array_map('intval', $ids));

        foreach (['workout_sessions', 'nutrition_days', 'health_stats'] as $table) {
            $this->wpdb->query('DELETE FROM `' . $this->table($table) . "` WHERE user_id IN ({$in})");
        }

        $this->wpdb->query("DELETE FROM `{$users}` WHERE id IN ({$in})");

        $this->note('volume purge: removed ' . count($ids) . ' synthetic users and their rows');
    }
}
