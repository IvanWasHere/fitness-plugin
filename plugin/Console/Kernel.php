<?php

namespace FitnessClub\Console;

use FitnessClub\WPBones\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
  // Console commands (`php bones <command>`) are registered per work package —
  // seeding, PR rebuild, cleanup. See plans/03-backend.md.
    protected $commands = [];
}
