#!/usr/bin/env php
<?php
/**
 * Seed runner.
 *
 * Demo data is deliberately kept out of database/seeders/ (WP Bones includes
 * that directory on every activation, and demo accounts must never appear on a
 * production site), so this is how you run it.
 *
 * Usage, from the plugin directory:
 *
 *   php bin/seed.php --plans              essential platform tiers (idempotent)
 *   php bin/seed.php --demo               the full prototype dataset
 *   php bin/seed.php --volume[=1000:180]  N users x D days of synthetic history
 *   php bin/seed.php --purge-volume       remove synthetic rows only
 *   php bin/seed.php --all                plans + demo
 *
 * Every seeder upserts on a natural key, so re-running updates rather than
 * duplicates.
 */

declare(strict_types=1);

use FitnessClub\Database\Seeders\DemoSeeder;
use FitnessClub\Database\Seeders\PlatformPlansSeeder;
use FitnessClub\Database\Seeders\VolumeSeeder;

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$pluginDir = dirname(__DIR__);

// Walk up to the WordPress root.
$wpLoad = null;
$dir    = $pluginDir;
for ($i = 0; $i < 6; $i++) {
    $dir = dirname($dir);
    if (is_file($dir . '/wp-load.php')) {
        $wpLoad = $dir . '/wp-load.php';
        break;
    }
}

if (!$wpLoad) {
    exit("Could not locate wp-load.php above {$pluginDir}.\n");
}

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require $wpLoad;

$args    = array_slice($argv, 1);
$plans   = in_array('--plans', $args, true);
$demo    = in_array('--demo', $args, true);
$purge   = in_array('--purge-volume', $args, true);
$all     = in_array('--all', $args, true);
$volume  = null;

foreach ($args as $arg) {
    if ($arg === '--volume') {
        $volume = [1000, 180];
    } elseif (str_starts_with($arg, '--volume=')) {
        $spec   = explode(':', substr($arg, 9));
        $volume = [max(1, (int) ($spec[0] ?? 1000)), max(1, (int) ($spec[1] ?? 180))];
    }
}

if (!$plans && !$demo && !$volume && !$purge && !$all) {
    echo "Nothing to do. Try --plans, --demo, --volume, --purge-volume or --all.\n";
    exit(1);
}

global $wpdb;

if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fc_users'")) {
    exit("FitnessClub tables not found. Activate the plugin first.\n");
}

$run = function (string $label, object $seeder): void {
    $t0 = microtime(true);
    $seeder->run();
    printf("%s (%.2fs)\n", $label, microtime(true) - $t0);
    foreach ($seeder->messages() as $line) {
        echo '  ', $line, "\n";
    }
};

echo "FitnessClub seeder — database: ", DB_NAME, "\n";
echo str_repeat('-', 66), "\n";

if ($plans || $all) {
    $run('platform plans', new PlatformPlansSeeder());
}

if ($demo || $all) {
    $run('demo dataset', new DemoSeeder());
}

if ($volume) {
    [$users, $days] = $volume;
    echo "generating {$users} users x {$days} days — this takes a while\n";
    $run('volume', new VolumeSeeder($users, $days));
}

if ($purge) {
    $seeder = new VolumeSeeder();
    $seeder->purge();
    echo "volume purge\n";
    foreach ($seeder->messages() as $line) {
        echo '  ', $line, "\n";
    }
}

echo str_repeat('-', 66), "\n";

$counts = [];
foreach ([
    'users', 'trainers', 'user_trainers', 'plans', 'subscriptions', 'payments',
    'workouts', 'exercises', 'user_workouts', 'workout_sessions', 'exercise_logs',
    'set_logs', 'personal_records', 'foods', 'nutrition_logs', 'nutrition_log_items',
    'nutrition_days', 'health_stats', 'body_measurements', 'message_threads',
    'messages', 'notifications', 'activity_log', 'tickets', 'ticket_replies',
] as $table) {
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}fc_{$table}`");
    if ($n > 0) {
        $counts[$table] = $n;
    }
}

echo "row counts\n";
foreach ($counts as $table => $n) {
    printf("  %-22s %d\n", $table, $n);
}

echo "\ndone.\n";
