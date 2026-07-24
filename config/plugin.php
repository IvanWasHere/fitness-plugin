<?php

if (!defined('ABSPATH')) {
    exit();
}

return [
    'priorities' => [
        'init'              => 10,
        'widgets_init'      => 10,
        'admin_init'        => 10,
        'set_screen_option' => 10,
    ],

    'logging' => [
        'type'             => 'errorlog',
        'daily_format'     => 'Y-m-d',
        'timestamp_format' => 'd-M-Y H:i:s T',
    ],

    'screen_options' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom Post Types / Taxonomies
    |--------------------------------------------------------------------------
    |
    | Deliberately empty and staying that way. All domain data lives in custom
    | fc_* tables — see plans/00-architecture.md#non-negotiables.
    |
    */
    'custom_post_types'     => [],
    'custom_taxonomy_types' => [],

    /*
    |--------------------------------------------------------------------------
    | Shortcodes
    |--------------------------------------------------------------------------
    |
    | [fitnessclub_app]     — user SPA
    | [fitnessclub_trainer] — trainer SPA
    |
    | Registered in Phase 1 W1.3 alongside the boot payload.
    |
    */
    'shortcodes' => [],

    'widgets' => [],
    'ajax'    => [],

    'providers' => [
        \FitnessClub\Providers\UpgradeProvider::class,
    ],
];
