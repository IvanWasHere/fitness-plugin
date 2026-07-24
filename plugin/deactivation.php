<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin deactivation
|--------------------------------------------------------------------------
|
| Deliberately minimal. Roles, capabilities and tables are NOT removed here:
| deactivating a plugin (to debug a conflict, say) must not strip roles from
| live accounts or destroy user data. That belongs in uninstall.php.
|
*/

wp_clear_scheduled_hook('fitnessclub_subscription_expiry');
wp_clear_scheduled_hook('fitnessclub_stale_session_cleanup');
wp_clear_scheduled_hook('fitnessclub_stale_request_expiry');
wp_clear_scheduled_hook('fitnessclub_workout_reminders');

flush_rewrite_rules();
