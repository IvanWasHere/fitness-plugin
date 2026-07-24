<?php
/**
 * Uninstall.
 *
 * Removes roles, capabilities and options. User DATA is deliberately preserved
 * by default: silently dropping months of someone's workout, nutrition and
 * health history because a plugin was deleted is not a recoverable mistake.
 *
 * Set the FITNESSCLUB_REMOVE_ALL_DATA constant in wp-config.php to opt in to a
 * full teardown.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';

use FitnessClub\Database\Upgrade\Manager;
use FitnessClub\Providers\RoleProvider;

RoleProvider::uninstall();
Manager::forget();

delete_option('fitnessclub');

if (defined('FITNESSCLUB_REMOVE_ALL_DATA') && FITNESSCLUB_REMOVE_ALL_DATA) {
    global $wpdb;

    // Children first: foreign keys are ON DELETE CASCADE, but the order matters
    // on installs where the constraints could not be created.
    $tables = [
        'ticket_replies', 'tickets', 'activity_log', 'notifications',
        'messages', 'message_threads', 'body_measurements', 'health_stats',
        'user_food_plans', 'food_plan_meals', 'food_plans', 'nutrition_days',
        'nutrition_log_items', 'nutrition_logs', 'foods', 'personal_records',
        'set_logs', 'exercise_logs', 'workout_sessions', 'user_workouts',
        'exercises', 'workouts', 'payments', 'subscriptions', 'plans',
        'client_notes', 'user_trainers', 'trainers', 'users',
    ];

    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $name = $wpdb->prefix . 'fc_' . $table;
        $wpdb->query("DROP TABLE IF EXISTS `{$name}`");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
}
