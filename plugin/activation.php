<?php

use FitnessClub\Providers\RewriteServiceProvider;
use FitnessClub\Providers\RoleProvider;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin activation
|--------------------------------------------------------------------------
|
| Runs on register_activation_hook(). Nothing schema-dependent belongs here —
| WP Bones includes this file BEFORE the migrations run. Roles and rewrite rules
| touch no plugin tables, so they are safe.
|
*/

// Roles + capabilities (idempotent). Removed on uninstall, never deactivation.
RoleProvider::install();

// Request a one-shot rewrite-rule flush: RewriteServiceProvider consumes the flag
// on the next init(), once it has registered the app route. This is the robust
// order (register the rule, then flush) without duplicating rule registration.
update_option(RewriteServiceProvider::FLUSH_OPTION, true);
