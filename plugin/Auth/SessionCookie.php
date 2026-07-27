<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The plugin's session cookie: naming, attributes, reading, clearing.
 *
 * ## Two paths, not one
 *
 * The SPA is served under `home_url()` (`/fitness/`) and the REST API under
 * `site_url()` (`/wp-json/`). On a standard install both are `/` and this is
 * moot — but on a WordPress-in-a-subdirectory install (`home = /`,
 * `siteurl = /wp`) the REST API lives at `/wp/wp-json/`, and a cookie scoped to
 * one path is not sent to the other. Core emits its own auth cookie twice for
 * exactly this reason, and so does this.
 *
 * Hardcoding `'/'` instead would be wrong in the other direction: on a site
 * installed at `/blog/`, it leaks the session to every unrelated application on
 * the same host.
 *
 * `COOKIEHASH`, `COOKIEPATH`, `SITECOOKIEPATH` and `COOKIE_DOMAIN` are defined
 * by `wp_cookie_constants()`, which runs in `wp-settings.php` before plugins
 * load — so they are always available here.
 *
 * ## SameSite=Lax, not Strict
 *
 * `Lax` is the CSRF defence (see Csrf): it is never sent on a cross-site
 * POST/PUT/PATCH/DELETE, and every state-changing route in this API is a
 * non-GET. `Strict` would additionally withhold the cookie when a member clicks
 * the password-reset link in their email — a top-level navigation from a
 * foreign origin — which is a flow this plugin actually has.
 */
final class SessionCookie
{
    private const SESSION_PREFIX = 'fc_session_';
    private const CSRF_PREFIX    = 'fc_csrf_';

    public static function name(): string
    {
        /**
         * Filter the session cookie's name.
         *
         * Exposed because edge caches decide whether to bypass the page cache by
         * matching cookie names, and every stock rule looks for
         * `wordpress_logged_in_*`. A host with an uncooperative cache can rename
         * this to something its rules recognise. See RewriteServiceProvider for
         * the rest of the cache story.
         */
        return (string) apply_filters(
            'fitnessclub_session_cookie_name',
            self::SESSION_PREFIX . COOKIEHASH
        );
    }

    public static function csrfName(): string
    {
        return (string) apply_filters(
            'fitnessclub_csrf_cookie_name',
            self::CSRF_PREFIX . COOKIEHASH
        );
    }

    /**
     * The raw `{selector}.{verifier}` value, or null.
     */
    public static function read(): ?string
    {
        return self::readCookie(self::name());
    }

    public static function readCsrf(): ?string
    {
        return self::readCookie(self::csrfName());
    }

    /**
     * Read a cookie down to the alphabet these tokens actually use.
     *
     * Both values are hex plus a single dot, so anything else is either a
     * mangled cookie or someone probing — and stripping to that alphabet means
     * no caller has to wonder whether the value it received is safe to compare,
     * log or put in a query.
     */
    private static function readCookie(string $name): ?string
    {
        if (!isset($_COOKIE[$name])) {
            return null;
        }

        $value = preg_replace(
            '/[^a-f0-9.]/',
            '',
            sanitize_text_field(wp_unslash($_COOKIE[$name]))
        );

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Write the session cookie.
     *
     * @param int $expires Unix timestamp, or 0 for a browser-session cookie.
     */
    public static function write(string $value, int $expires = 0): void
    {
        self::emit(self::name(), $value, $expires, true);

        // Also populate the superglobal: a cookie set during this request is not
        // in $_COOKIE, and code later in the same request (the boot payload, a
        // redirect target) would otherwise see the caller as signed out.
        $_COOKIE[self::name()] = $value;
    }

    public static function writeCsrf(string $value, int $expires = 0): void
    {
        // Readable by JavaScript on purpose — the double-submit check compares
        // it against a header the SPA sets. It is not a credential on its own:
        // possession of it proves nothing without the session cookie, and its
        // hash is bound to the session row.
        self::emit(self::csrfName(), $value, $expires, false);

        $_COOKIE[self::csrfName()] = $value;
    }

    public static function clear(): void
    {
        self::emit(self::name(), '', time() - YEAR_IN_SECONDS, true);
        self::emit(self::csrfName(), '', time() - YEAR_IN_SECONDS, false);

        unset($_COOKIE[self::name()], $_COOKIE[self::csrfName()]);
    }

    private static function emit(string $name, string $value, int $expires, bool $httpOnly): void
    {
        // A cookie written after output is a PHP warning printed into the page
        // body. Degrade to "the session does not slide" instead.
        if (headers_sent()) {
            return;
        }

        $options = [
            'expires'  => $expires,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];

        setcookie($name, $value, $options);

        if (SITECOOKIEPATH !== COOKIEPATH) {
            $options['path'] = SITECOOKIEPATH;
            setcookie($name, $value, $options);
        }
    }
}
