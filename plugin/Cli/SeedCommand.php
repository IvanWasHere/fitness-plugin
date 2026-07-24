<?php

namespace FitnessClub\Cli;

use FitnessClub\Database\Seeders\DemoSeeder;
use FitnessClub\Database\Seeders\VolumeSeeder;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Seed the FitnessClub database — `wp fitnessclub seed`.
 *
 * A WP-CLI command, not a `php bones` command: bones does not bootstrap WordPress
 * for custom commands, and seeding needs $wpdb and the WP user functions. WP-CLI
 * loads the full runtime.
 *
 * The essential platform tiers seed automatically on activation; demo and volume
 * data are opt-in here so they never land on a production site. Every seeder upserts
 * on a natural key, so re-running converges rather than duplicating.
 *
 * ## EXAMPLES
 *
 *     wp fitnessclub seed --demo
 *     wp fitnessclub seed --volume=1000:180
 *     wp fitnessclub seed --purge-volume
 */
class SeedCommand
{
    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fc_users'")) {
            WP_CLI::error('FitnessClub tables not found. Activate the plugin first.');
        }

        $did = false;

        if (isset($assoc['demo'])) {
            $this->runSeeder('demo dataset', new DemoSeeder());
            $did = true;
        }

        if (isset($assoc['volume'])) {
            [$users, $days] = $this->parseVolume((string) $assoc['volume']);
            WP_CLI::log("Generating {$users} users x {$days} days — this can take a while.");
            $this->runSeeder('volume', new VolumeSeeder($users, $days));
            $did = true;
        }

        if (isset($assoc['purge-volume'])) {
            $seeder = new VolumeSeeder();
            $seeder->purge();
            foreach ($seeder->messages() as $line) {
                WP_CLI::log('  ' . $line);
            }
            $did = true;
        }

        if (!$did) {
            WP_CLI::warning('Nothing to do. Try --demo, --volume=1000:180 or --purge-volume.');

            return;
        }

        $this->rowCounts();
        WP_CLI::success('Seed complete.');
    }

    private function runSeeder(string $label, object $seeder): void
    {
        $seeder->run();
        WP_CLI::log(WP_CLI::colorize("%g{$label}%n"));
        foreach ($seeder->messages() as $line) {
            WP_CLI::log('  ' . $line);
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseVolume(string $spec): array
    {
        if ('' === $spec || '1' === $spec) {
            return [1000, 180];
        }
        $parts = explode(':', $spec);

        return [max(1, (int) ($parts[0] ?? 1000)), max(1, (int) ($parts[1] ?? 180))];
    }

    private function rowCounts(): void
    {
        global $wpdb;

        $rows = [];
        foreach (
            [
            'users', 'trainers', 'user_trainers', 'plans', 'subscriptions', 'payments',
            'workouts', 'exercises', 'user_workouts', 'workout_sessions', 'exercise_logs',
            'set_logs', 'personal_records', 'foods', 'nutrition_logs', 'nutrition_log_items',
            'nutrition_days', 'health_stats', 'body_measurements', 'message_threads',
            'messages', 'notifications', 'activity_log', 'tickets', 'ticket_replies',
            ] as $table
        ) {
            $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}fc_{$table}`");
            if ($n > 0) {
                $rows[] = ['table' => $table, 'rows' => $n];
            }
        }

        if ($rows) {
            WP_CLI\Utils\format_items('table', $rows, ['table', 'rows']);
        }
    }
}
