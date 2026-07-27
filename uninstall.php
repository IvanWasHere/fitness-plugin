<?php

/**
 * Uninstall.
 *
 * Removes plugin options. User DATA is deliberately preserved by default —
 * silently dropping months of someone's workout, nutrition and health history
 * because a plugin was deleted is not a recoverable mistake.
 *
 * Set the FITNESSCLUB_REMOVE_ALL_DATA constant in wp-config.php to opt in to a full
 * teardown (drops the fc_* tables). Note that this includes fc_accounts: the
 * plugin owns its own credentials, so uninstalling with that constant set
 * deletes every account, and there is no WordPress user left behind to fall back
 * on.
 *
 * There are no roles or capabilities to remove any more — the plugin stopped
 * granting them to WordPress users when identity moved into fc_accounts.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';

\FitnessClub\Database\Upgrade\Manager::forget();

// The wpBones options model row (keyed by the plugin slug) + any flush flag.
delete_option('fitnessclub_slug');
delete_option('fitnessclub_flush_rewrites');
delete_option(\FitnessClub\Auth\AccountBootstrap::DONE_OPTION);
delete_option(\FitnessClub\Auth\AccountBootstrap::ACTIVATOR_OPTION);
delete_transient(\FitnessClub\Auth\AccountBootstrap::CREDENTIALS_TRANSIENT);

if (defined('FITNESSCLUB_REMOVE_ALL_DATA') && FITNESSCLUB_REMOVE_ALL_DATA) {
    global $wpdb;

    // Children first (FKs are ON DELETE CASCADE, but order matters on installs
    // where the constraints could not be created).
    $tables = [
        'ticket_replies', 'tickets', 'activity_log', 'notifications',
        'messages', 'message_threads', 'body_measurements', 'health_stats',
        'user_food_plans', 'food_plan_meals', 'food_plans', 'nutrition_days',
        'nutrition_log_items', 'nutrition_logs', 'foods', 'personal_records',
        'set_logs', 'exercise_logs', 'workout_sessions', 'user_workouts',
        'exercises', 'workouts', 'payments', 'subscriptions', 'plans',
        'client_notes', 'user_trainers', 'trainers', 'users',
        // Identity last: fc_users and fc_trainers reference it with RESTRICT,
        // so it can only be dropped once they are gone.
        'account_tokens', 'sessions', 'accounts',
    ];

    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $name = $wpdb->prefix . 'fc_' . $table;
        $wpdb->query("DROP TABLE IF EXISTS `{$name}`");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
}
