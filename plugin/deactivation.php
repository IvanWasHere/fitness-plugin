<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin deactivation
|--------------------------------------------------------------------------
|
| Roles and data survive deactivation on purpose — deactivating to debug a
| conflict must not strip live accounts. Only the things that would keep firing
| without the plugin loaded are torn down: rewrite rules and cron events.
|
*/

flush_rewrite_rules();

\FitnessClub\Providers\ScheduleProvider::unschedule();
