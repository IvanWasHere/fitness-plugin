<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Register The Composer Auto Loader
|--------------------------------------------------------------------------
*/

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'FitnessClub: dependencies are missing. Run "composer install" in the plugin directory.',
                'fitnessclub'
            )
        );
    });

    return;
}

require_once __DIR__ . '/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Plugin Static class
|--------------------------------------------------------------------------
|
| Holds the running Plugin instance globally. The framework's helper functions
| (wpbones_logger(), wpbones_provider(), wpbones_modules(), …) resolve it by
| calling FitnessClub() — so this accessor is required, not decorative.
|
*/

final class FitnessClub
{
    public const TEXTDOMAIN = 'fitnessclub';

    /** @var \FitnessClub\WPBones\Foundation\Plugin|null */
    public static $plugin;

    /** @var float Boot timestamp, for profiling. */
    public static $start;
}

FitnessClub::$plugin = require_once __DIR__ . '/plugin.php';
FitnessClub::$start  = microtime(true);

if (!function_exists('FitnessClub')) {
    /**
     * The running plugin instance.
     *
     * @return \FitnessClub\WPBones\Foundation\Plugin|null
     */
    function FitnessClub()
    {
        return FitnessClub::$plugin;
    }
}

return FitnessClub::$plugin;
