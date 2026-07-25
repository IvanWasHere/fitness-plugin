<?php

namespace FitnessClub\Ajax;

use FitnessClub\WPBones\Foundation\WordPressAjaxServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Nonce refresh for a long-lived SPA (plans/03-backend.md#cookie--nonce-primary).
 *
 * A `wp_rest` nonce lasts ~12–24 h. An app left open overnight starts getting
 * `403 rest_cookie_invalid_nonce` on every call — the classic "it silently
 * stopped saving" bug. The API client intercepts that code, refreshes here,
 * retries once, and only then asks the user to sign in again.
 *
 * ## Why admin-ajax and not a REST route
 *
 * The obvious `GET /auth/nonce` cannot work. `rest_cookie_check_errors()` rejects
 * the *whole request* when a cookie-authenticated caller presents a stale nonce,
 * so a refresh route with a stale nonce 403s before it runs; and dropping the
 * header instead makes WordPress treat the caller as anonymous, so the route
 * would mint a nonce for user 0 — valid-looking and useless.
 *
 * admin-ajax authenticates from the cookie with no nonce requirement of its own,
 * which is exactly what a refresh endpoint needs. This is the same mechanism
 * core's own Heartbeat uses to hand refreshed nonces to wp-admin.
 *
 * Handing the nonce to any caller with the cookie is safe for the reason every
 * nonce scheme relies on: a cross-origin page can *send* the request but cannot
 * read the reply — admin-ajax emits no CORS headers.
 */
class NonceProvider extends WordPressAjaxServiceProvider
{
    /**
     * Registered for logged-in and logged-out callers alike: the login panel is
     * itself an app screen, and it needs a valid anonymous nonce to post to
     * `/auth/login`.
     *
     * @var array
     */
    protected $trusted = ['fc_nonce'];

    /**
     * Echoes a nonce for whoever the cookie says is calling.
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- must match the wp_ajax_{action} name.
    public function fc_nonce(): void
    {
        wp_send_json_success([
            'nonce'     => wp_create_nonce('wp_rest'),
            'logged_in' => is_user_logged_in(),
        ]);
    }
}
