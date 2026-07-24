<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin options
|--------------------------------------------------------------------------
|
| The options MODEL. wpBones stores this as a single JSON row in wp_options
| (keyed by the plugin slug) on first activation, and applies delta() on
| activation/update so new keys appear on existing installs without a migration.
|
| Read anywhere via the wpBones options model, e.g.
|   FitnessClub()->options->get('routing.app_base', 'fitness')
|
| Secrets do NOT belong here (an options row lives in a database that gets
| dumped and restored). The JWT signing key is read from a wp-config.php
| constant instead — see plans/03-backend.md.
|
*/

return [

    /*
    | The front-end app lives at example.com/{app_base} (D9). "app_base" is the
    | slug the admin edits in Settings; changing it re-flushes rewrite rules.
    */
    'routing' => [
        'app_base' => 'fitness',
    ],

    'branding' => [
        'name'    => 'FitForge',
        'logo_id' => 0,
    ],

    'theme' => [
        'active'    => 'default',
        'overrides' => '',
    ],

    'features' => [
        'messaging_enabled' => true,
        'nutrition_enabled' => true,
        'health_enabled'    => true,
        'support_enabled'   => true,
        'trainer_directory' => true,
        'jwt_api_enabled'   => false,
        'registration_open' => true,
    ],

    'billing' => [
        'gateway'                => 'manual',
        'currency'               => 'USD',
        'stripe_test_mode'       => true,
        'stripe_publishable_key' => '',
    ],

    'email' => [
        'from_name'    => '',
        'from_address' => '',
    ],
];
