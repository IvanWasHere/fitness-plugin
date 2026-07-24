<?php

namespace FitnessClub\Providers;

use FitnessClub\Database\Upgrade\Manager;
use FitnessClub\WPBones\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs pending schema upgrades.
 *
 * Providers register on `init`, the earliest hook guaranteed to run AFTER activation
 * has applied the migrations — so this is where the version dispatcher belongs, not
 * in plugin/activation.php (which executes before any table exists). The cost on a
 * normal request is one autoloaded option read.
 */
class UpgradeProvider extends ServiceProvider
{
    public function register()
    {
        if ((int) get_option(Manager::OPTION_VERSION, 0) === Manager::VERSION) {
            return;
        }

        Manager::run();
    }
}
