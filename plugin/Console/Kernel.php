<?php

namespace FitnessClub\Console;

use FitnessClub\WPBones\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    // `php bones` commands run WITHOUT WordPress bootstrapped (bones only loads WP
    // for tinker/deploy), so WP-runtime work — seeding, PR rebuild, cleanup — lives
    // in WP-CLI commands under plugin/Cli/ instead, registered by CliServiceProvider.
    protected $commands = [];
}
