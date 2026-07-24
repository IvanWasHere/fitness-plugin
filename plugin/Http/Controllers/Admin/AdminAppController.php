<?php

namespace FitnessClub\Http\Controllers\Admin;

use FitnessClub\Http\Controllers\Controller;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Renders the mount point for the admin SPA.
 *
 * The view is deliberately almost empty: it emits a single container plus the
 * boot payload, and everything below that is React. See plans/05-admin-app.md.
 */
class AdminAppController extends Controller
{
    public function index()
    {
        return FitnessClub()->view('admin.app')->with('boot', $this->boot());
    }

    /**
     * Boot payload. The SPA never guesses its own configuration.
     */
    private function boot(): array
    {
        return [
            'restUrl' => esc_url_raw(rest_url('fitnessclub/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => FitnessClub()->Version,
            'brand'   => FitnessClub()->options->get('branding.name', 'FitForge'),
            'locale'  => get_user_locale(),
            'user'    => [
                'id'   => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
            ],
        ];
    }
}
