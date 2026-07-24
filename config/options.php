<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Plugin options
|--------------------------------------------------------------------------
|
| WP Bones stores these as a single option and applies delta() on activation
| and update, so new keys appear on existing installs without a migration.
|
| Secrets do NOT belong here. The JWT signing key is read from the
| FC_JWT_SECRET constant in wp-config.php — an options row lives in a database
| that gets dumped, shared and restored into staging.
| See plans/03-backend.md#jwt-phase-4-external-clients-only.
|
*/

return [
    'branding' => [
        'name'    => 'FitForge',
        'logo_id' => 0,
    ],

    'theme' => [
        'active'    => 'default',
        'overrides' => '',
    ],

    'features' => [
        'messaging_enabled'     => true,
        'nutrition_enabled'     => true,
        'health_enabled'        => true,
        'support_enabled'       => true,
        'trainer_directory'     => true,
        'jwt_api_enabled'       => false,
        'registration_open'     => true,
    ],

    'billing' => [
        'gateway'          => 'manual',
        'currency'         => 'USD',
        'stripe_test_mode' => true,
        'stripe_publishable_key' => '',
    ],

    'email' => [
        'from_name'    => '',
        'from_address' => '',
    ],
];
