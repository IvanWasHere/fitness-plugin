<?php

/**
 * WordPress requires an index.php or it lists the directory under Appearance →
 * Broken Themes and `wp_get_themes()` omits it entirely.
 *
 * A real front-end theme should redirect to the app URL here, so that activating
 * it by accident is a detour rather than an outage. This fixture is never
 * rendered, so it only has to exist.
 */

if (!defined('ABSPATH')) {
    exit();
}
