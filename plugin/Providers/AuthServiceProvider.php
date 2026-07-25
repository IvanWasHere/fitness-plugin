<?php

namespace FitnessClub\Providers;

use FitnessClub\Support\AppRouter;
use FitnessClub\WPBones\Support\ServiceProvider;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Cookie and nonce plumbing for the front-end SPAs (plans/03-backend.md#authentication).
 *
 * Two problems this provider exists to solve.
 *
 * ## 1. The nonce minted during login is worthless without this
 *
 * `wp_create_nonce('wp_rest')` is bound to the current user id *and* the session
 * token, and the token is read out of the logged-in cookie in `$_COOKIE`.
 * `wp_set_auth_cookie()` sends the cookie in the response headers but never
 * populates `$_COOKIE` — so a nonce created later in the same request is signed
 * against the *previous* (empty) session and every call the SPA makes with it
 * fails `rest_cookie_invalid_nonce`.
 *
 * Bridging the cookie into `$_COOKIE` as it is issued makes `POST /auth/login`
 * able to hand back a nonce that actually works, which is what lets the app go
 * straight from the login panel into the dashboard with no reload.
 *
 * ## 2. Password reset has to land in the app, not in wp-login.php
 *
 * Members never see wp-admin (D9). Core's reset mail points at
 * `wp-login.php?action=rp`, which drops them into WordPress chrome mid-flow, so
 * the mail is rewritten to `/{base}/reset?key=…&login=…`. Administrators are
 * left on the core flow deliberately — they *do* work in wp-admin, and an admin
 * locked out of wp-login by a broken app route is a support incident.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register()
    {
        add_action('set_logged_in_cookie', [$this, 'bridgeLoggedInCookie'], 10, 6);
        add_action('clear_auth_cookie', [$this, 'clearBridgedCookie']);

        add_filter('retrieve_password_message', [$this, 'resetMessage'], 10, 4);
        add_filter('retrieve_password_title', [$this, 'resetSubject'], 10, 3);
    }

    /**
     * Make the just-issued logged-in cookie visible to the rest of this request,
     * so wp_get_session_token() — and therefore wp_create_nonce() — sees the new
     * session. See the class docblock.
     *
     * @param string $cookie The logged-in cookie value.
     */
    public function bridgeLoggedInCookie($cookie): void
    {
        if (defined('LOGGED_IN_COOKIE')) {
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
        }
    }

    /**
     * The mirror image on logout: leaving the stale value in `$_COOKIE` would
     * have the logout response mint a nonce for a session that no longer exists.
     */
    public function clearBridgedCookie(): void
    {
        if (defined('LOGGED_IN_COOKIE')) {
            unset($_COOKIE[LOGGED_IN_COOKIE]);
        }
    }

    /**
     * Point the reset mail at the SPA's reset screen.
     *
     * @param string  $message   Default message body.
     * @param string  $key       Reset key.
     * @param string  $userLogin Login of the user resetting.
     * @param WP_User $userData  The user.
     */
    public function resetMessage($message, $key, $userLogin, $userData): string
    {
        if (!$this->usesApp($userData)) {
            return $message;
        }

        $url = add_query_arg(
            [
                'key'   => rawurlencode($key),
                'login' => rawurlencode($userLogin),
            ],
            AppRouter::url('reset')
        );

        $lines = [
            sprintf(
                /* translators: %s: site name. */
                __('Someone asked to reset the password for your %s account.', 'fitnessclub'),
                $this->siteName()
            ),
            '',
            __('If that was not you, you can ignore this email — nothing has changed.', 'fitnessclub'),
            '',
            __('To choose a new password, open this link:', 'fitnessclub'),
            $url,
            '',
            __('The link stops working once it is used, or after 24 hours.', 'fitnessclub'),
        ];

        return implode("\r\n", $lines);
    }

    /**
     * @param string  $title     Default subject.
     * @param string  $userLogin Login of the user resetting.
     * @param WP_User $userData  The user.
     */
    public function resetSubject($title, $userLogin, $userData): string
    {
        if (!$this->usesApp($userData)) {
            return $title;
        }

        /* translators: %s: site name. */
        return sprintf(__('[%s] Choose a new password', 'fitnessclub'), $this->siteName());
    }

    /**
     * Does this account live in the front-end app rather than wp-admin?
     *
     * @param mixed $user Expected WP_User; core has passed odd things here before.
     */
    private function usesApp($user): bool
    {
        if (!$user instanceof WP_User) {
            return false;
        }

        return !user_can($user, 'manage_options');
    }

    private function siteName(): string
    {
        $brand = (string) $this->plugin->options->get('branding.name', '');

        return '' !== $brand ? $brand : wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    }
}
