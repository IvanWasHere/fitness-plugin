<?php

use FitnessClub\Database\Seeders\PlatformPlansSeeder;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Essential reference data
|--------------------------------------------------------------------------
|
| WP Bones includes every file in this directory on EVERY activation, so only
| data the product cannot run without belongs here, and it must be idempotent.
|
| Demo data lives in FitnessClub\Database\Seeders\DemoSeeder and is deliberately
| NOT wired in here — run it on purpose with `php bin/seed.php --demo`.
|
*/

(new PlatformPlansSeeder())->run();
