<?php

/**
 * Plugin Name: FitnessClub
 * Plugin URI: https://example.com/fitnessclub
 * Description: Workout, nutrition and health tracking with trainer coaching. Built on WP Bones.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: FitnessClub
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fitnessclub
 * Domain Path: languages
 *
 * @package FitnessClub
 */

if (!defined('ABSPATH')) {
    exit();
}

define('FITNESSCLUB_VERSION', '0.1.0');

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| our application. We just need to utilize it! We'll simply require it
| into the script here so that we don't have to worry about manual
| loading any of our classes later on. It feels nice to relax.
|
*/

require_once __DIR__ . '/bootstrap/autoload.php';
