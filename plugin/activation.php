<?php

use FitnessClub\Providers\RoleProvider;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin activation
|--------------------------------------------------------------------------
|
| IMPORTANT ORDERING NOTE
|
| WP Bones' Plugin::_activation() runs, in this order:
|
|   1. $this->options->delta()
|   2. include plugin/activation.php   <- you are here
|   3. include database/migrations/*.php
|   4. include database/seeders/*.php
|
| So this file executes BEFORE any table exists. Nothing schema-dependent may
| live here. The schema version dispatcher therefore runs from UpgradeProvider
| on `init` (the next request), by which point migrations have applied.
|
*/

// Roles and capabilities touch no plugin tables, so they are safe here.
RoleProvider::install();

flush_rewrite_rules();
