<?php

namespace FitnessClub\Providers;

use FitnessClub\Cli\SeedCommand;
use FitnessClub\WPBones\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Registers the plugin's WP-CLI commands.
 *
 * WP-runtime CLI work (seeding, and later PR rebuild / cleanup) lives in WP-CLI
 * commands rather than `php bones` commands, because bones does not bootstrap
 * WordPress for custom commands. WP-CLI does. This provider is a no-op outside
 * WP-CLI.
 */
class CliServiceProvider extends ServiceProvider
{
    public function register()
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('fitnessclub seed', SeedCommand::class);
    }
}
