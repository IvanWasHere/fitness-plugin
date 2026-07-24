<?php

use FitnessClub\WPBones\Foundation\Plugin;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Create The Plugin
|--------------------------------------------------------------------------
|
| The Plugin instance is the "glue" for all components: it reads config/,
| registers providers, menus, shortcodes and schedules, and runs the
| activation/deactivation hooks (which is where migrations execute).
|
*/

if (class_exists(Plugin::class)) {
    $plugin = new Plugin(realpath(__DIR__ . '/../'));

    /**
     * Fires once the plugin instance exists and config has been read.
     */
    do_action('fitnessclub_loaded', $plugin);

    return $plugin;
}

return null;
