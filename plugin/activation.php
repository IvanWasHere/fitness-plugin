<?php

use FitnessClub\Providers\RewriteServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin activation
|--------------------------------------------------------------------------
|
| Runs on register_activation_hook(). **Nothing schema-dependent belongs here** —
| WP Bones includes this file BEFORE the migrations run, so the fc_* tables do
| not exist yet. Options and rewrite rules touch no plugin table, so they are
| safe; creating the first account is not, and lives in
| Auth\AccountBootstrap, invoked from UpgradeProvider once migrations have run.
|
| There are no WordPress roles to install any more: the plugin owns its own
| accounts, and a WordPress administrator has no access to the app without one.
| See plugin/Auth/Capabilities.php.
|
*/

// Request a one-shot rewrite-rule flush: RewriteServiceProvider consumes the flag
// on the next init(), once it has registered the app route. This is the robust
// order (register the rule, then flush) without duplicating rule registration.
update_option(RewriteServiceProvider::FLUSH_OPTION, true);

// Remember who activated the plugin, so AccountBootstrap can address the
// generated credentials to them. Written here because this is the only moment
// the activating administrator is knowable; consumed and deleted on the next
// admin page load.
if (function_exists('get_current_user_id')) {
    update_option(\FitnessClub\Auth\AccountBootstrap::ACTIVATOR_OPTION, get_current_user_id(), false);
}
