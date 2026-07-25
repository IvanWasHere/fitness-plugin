<?php

/**
 * PHPUnit bootstrap — integration tests against a real WordPress + MySQL.
 *
 * The Guard, the rewrite render and the REST endpoints are all about behaviour
 * against the real WP runtime and schema, so mocking would test nothing. We boot
 * the actual site (the plugin is active on it) and assert against it.
 *
 * Loading Composer's autoloader here is safe: functions.php's direct-access guard
 * is scoped to non-CLI SAPIs, so the autoload no longer exits under CLI.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Locate wp-load.php above the plugin.
$wpLoad = null;
$dir    = __DIR__;
for ($i = 0; $i < 7; $i++) {
    $dir = dirname($dir);
    if (is_file($dir . '/wp-load.php')) {
        $wpLoad = $dir . '/wp-load.php';
        break;
    }
}

if (null === $wpLoad) {
    fwrite(STDERR, "Could not locate wp-load.php above the plugin.\n");
    exit(1);
}

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'fitnessplugin.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Core reads REMOTE_ADDR directly (retrieve_password() logs the requesting IP),
// and PHPUnit's CLI request has none.
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require $wpLoad;

/*
 * Auth cookies: fire the actions, skip the setcookie().
 *
 * PHPUnit has already written to stdout by the time a test signs a user in, so
 * `setcookie()` raises "headers already sent" — an artefact of the CLI harness,
 * not of the code under test. `send_auth_cookies` is checked *after*
 * `set_auth_cookie`/`set_logged_in_cookie` fire (pluggable.php), so suppressing
 * the wire write leaves the plugin's cookie-bridging plumbing — and therefore
 * the nonce-after-login assertion — fully exercised.
 */
add_filter('send_auth_cookies', '__return_false');

if (!function_exists('FitnessClub') || !FitnessClub()) {
    fwrite(STDERR, "FitnessClub plugin is not active — activate it before running tests.\n");
    exit(1);
}
