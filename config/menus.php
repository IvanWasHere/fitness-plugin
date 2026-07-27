<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin Menus (wp-admin)
|--------------------------------------------------------------------------
|
| wpBones registers wp-admin menu pages from this array.
|
| A **top-level** menu, not a child of Settings: the plugin is the site's whole
| fitness platform rather than a setting of it, and the one screen that lists the
| generated account credentials should not be three clicks deep under
| Settings → FitnessClub.
|
| `manage_options`, deliberately — and this is the one place left in the plugin
| where a WordPress capability decides anything. wp-admin pages cannot be gated on
| plugin accounts: `admin.php` calls `auth_redirect()` before any plugin callback
| runs, so somebody without a WordPress session never reaches us at all. See D4a
| in plans/00-architecture.md for where that line falls.
|
| FitnessClub's real UI is the front-end SPA at the configured URL (D9/D10); this
| screen exists to point at it and to hold the two things that cannot live there —
| the URL itself, and the accounts generated before anyone could sign in.
|
*/

return [
    'fitnessclub' => [
        'menu_title' => 'FitnessClub',
        'page_title' => 'FitnessClub',
        'capability' => 'manage_options',
        'icon'       => 'dashicons-heart',
        // Below Comments, above the Appearance block — where a site's own
        // application belongs rather than among the tools.
        'position'   => 26,

        'items' => [
            // An empty key takes the top-level slug, so "FitnessClub" and its
            // first submenu entry are one page rather than a duplicate.
            '' => [
                'menu_title' => 'Settings',
                'page_title' => 'FitnessClub Settings',
                'capability' => 'manage_options',
                'route'      => [
                    'get' => 'Admin\SettingsController@index',

                    // Form handling goes on `load`, which wpBones wires to
                    // `load-{$hook}` — i.e. **before** wp-admin prints its
                    // header. The `post` verb would run inside the page render,
                    // by which point the headers are already sent and
                    // `wp_safe_redirect()` can only emit a warning. A settings
                    // screen has to redirect after a write, or a refresh
                    // re-submits it — and one of these actions deletes an
                    // account.
                    'load' => 'Admin\SettingsController@handle',
                ],
            ],
        ],
    ],
];
