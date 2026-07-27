<?php

namespace FitnessClub\Providers;

use FitnessClub\Auth\AccountBootstrap;
use FitnessClub\Database\Upgrade\Manager;
use FitnessClub\WPBones\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs pending schema upgrades, and creates the first accounts.
 *
 * Providers register on `init`, the earliest hook guaranteed to run AFTER activation
 * has applied the migrations — so this is where the version dispatcher belongs, not
 * in plugin/activation.php (which executes before any table exists). The cost on a
 * normal request is two option reads.
 *
 * The account bootstrap needs the same "after migrations" guarantee but **not**
 * the version dispatcher: `Manager::run()` short-circuits on a fresh install, so
 * an upgrade step would never fire on a new site. It carries its own guard.
 */
class UpgradeProvider extends ServiceProvider
{
    public function register()
    {
        if ((int) get_option(Manager::OPTION_VERSION, 0) !== Manager::VERSION) {
            Manager::run();
        }

        AccountBootstrap::ensure();

        add_action('admin_notices', [AccountBootstrap::class, 'renderNotice']);
    }
}
