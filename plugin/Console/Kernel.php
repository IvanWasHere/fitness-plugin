<?php

namespace FitnessClub\Console;

use FitnessClub\WPBones\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
  protected $commands = [
    'FitnessClub\Console\Commands\SimpleCommand',
    'FitnessClub\Console\Commands\WordPressCommand',
  ];
}
