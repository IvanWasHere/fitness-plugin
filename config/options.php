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

    /*
    | `extension` is the front-end theme (D11): the stylesheet directory of a
    | WordPress theme in wp-content/themes that declares
    | "Fitness Plugin Extension Enabled: true" and ships its own React apps.
    | Empty — the default — means the plugin serves its own.
    |
    | `active`/`overrides` belong to the colour-token system, which is deferred
    | indefinitely (D11). They stay because ThemeService still reads them to emit
    | the shell's `--fc-*` block, which is what the plugin's own apps are styled
    | from today.
    */
    'theme' => [
        'extension' => '',
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

    /*
    | Support FAQ, as a JSON array of {q, a}. The prototype hardcoded five
    | answers into the JS bundle, so correcting a wrong one meant a rebuild.
    | Empty means "use the bundled defaults" — see TicketService::defaultFaq() —
    | so an install that never touches this still has an FAQ (W3.3).
    |
    | Deliberately a **flat** key rather than `support.faq`: the options model
    | resolves a dotted path by walking the stored blob, and on an install whose
    | row predates the key that walk hits a value it cannot index. One level has
    | nothing to walk.
    */
    'support_faq' => '',

    'email' => [
        'from_name'    => '',
        'from_address' => '',
    ],
];
