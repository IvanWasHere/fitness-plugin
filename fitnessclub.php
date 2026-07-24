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
define('FITNESSCLUB_FILE', __FILE__);
define('FITNESSCLUB_PATH', plugin_dir_path(__FILE__));
define('FITNESSCLUB_URL', plugin_dir_url(__FILE__));

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader. The
| bootstrap file below wires it up and returns the Plugin instance.
|
*/

require_once __DIR__ . '/bootstrap/autoload.php';
