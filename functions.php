<?php
/**
 * Global helpers for the FitnessClub plugin.
 *
 * Autoloaded by composer (see composer.json "autoload.files").
 */

if (!defined('ABSPATH')) {
    exit();
}

if (!function_exists('fitnessclub_table')) {
    /**
     * Fully-qualified name for one of the plugin's tables.
     *
     * All domain tables are prefixed `{$wpdb->prefix}fc_` — see plans/01-database.md.
     *
     * @param string $name Table name without the `fc_` prefix, e.g. "users".
     */
    function fitnessclub_table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'fc_' . ltrim($name, '_');
    }
}
