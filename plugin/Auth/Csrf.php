<?php

namespace FitnessClub\Auth;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * CSRF, replacing the `wp_rest` nonce.
 *
 * ## Two layers
 *
 * **1. `SameSite=Lax` on the session cookie** (see SessionCookie) is the
 * structural defence. A `Lax` cookie is never sent on a cross-site
 * POST/PUT/PATCH/DELETE, and every state-changing route in this API is a
 * non-GET — so a forged request simply arrives with no session and gets a 401.
 * WordPress cannot take this posture wholesale because too many legacy flows
 * depend on cross-site cookies; a plugin that owns its cookie can.
 *
 * **2. A session-bound double-submit token** covers what `Lax` does not: a
 * sibling subdomain *is* same-site, so `evil.example.com` can both reach
 * `www.example.com` with the cookie attached and overwrite cookies on the
 * parent domain. Plain double-submit fails there — the attacker sets both
 * halves and they match. Storing `sha256(token)` in `fc_sessions.csrf_hash`
 * closes it: the attacker can forge a matching *pair*, but cannot make it match
 * the hash recorded against the victim's session.
 *
 * ## One gate, not sixty-one
 *
 * Verification is a single `rest_pre_dispatch` filter over the whole namespace,
 * not a per-route callback. Per-route is how route sixty-one ends up
 * unprotected.
 *
 * ## Why the nonce apparatus is gone
 *
 * A `wp_rest` nonce carries its own 12–24 hour lifetime, independent of the
 * session — so the app would silently stop saving while the user was still
 * signed in, which is why `Ajax\NonceProvider` existed to refresh it through
 * admin-ajax. This token's lifetime *is* the session's lifetime. There is no
 * window where the session is valid and the token is stale, so there is nothing
 * to refresh: when the session dies the answer is a clean 401 and the login
 * panel.
 */
final class Csrf
{
    public const HEADER = 'X-FC-CSRF';

    /** Body/query parameter, for callers that cannot set headers. */
    public const PARAM = '_csrf';

    /**
     * Methods that must not change anything, and so need no token.
     *
     * This is a contract, not an observation: a GET route with a side effect is
     * a bug on its own terms, and this list is the reason it would also be a
     * CSRF hole.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The token the caller presented, from the header or the request body.
     *
     * `navigator.sendBeacon()` cannot set headers — the offline set queue uses
     * it on `pagehide` — so the token also travels in the JSON body. It is
     * deliberately *not* read from the query string: a query parameter lands in
     * web-server access logs, in `Referer` headers and in every proxy between
     * the browser and the site, which is exactly where a bearer value should
     * never be. (The old `?_wpnonce=` fallback had this problem.)
     */
    public static function fromRequest(WP_REST_Request $request): ?string
    {
        $header = $request->get_header(self::HEADER);
        if (is_string($header) && '' !== $header) {
            return $header;
        }

        $body = $request->get_json_params();
        $sent = is_array($body) ? ($body[self::PARAM] ?? null) : null;

        return is_string($sent) && '' !== $sent ? $sent : null;
    }

    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * Does the presented token match both the cookie and — when there is a
     * session — the hash bound to it?
     *
     * @param string|null $sessionCsrfHash sha256 of the session's token, or null
     *                                     for an anonymous caller.
     */
    public static function verify(?string $presented, ?string $sessionCsrfHash): bool
    {
        $cookie = SessionCookie::readCsrf();

        if (null === $presented || null === $cookie) {
            return false;
        }

        if (!hash_equals($cookie, $presented)) {
            return false;
        }

        // Anonymous callers (sign-in, password reset) get the cookie comparison
        // only — there is no session to bind to yet. That still closes login
        // CSRF, where an attacker forces a victim's browser into *the
        // attacker's* account, which `requireGuest()` alone never did.
        if (null === $sessionCsrfHash) {
            return true;
        }

        return hash_equals($sessionCsrfHash, hash('sha256', $presented));
    }

    /**
     * Ensure an anonymous caller has a CSRF cookie, so the login form has a
     * token to present. Returns the token either way.
     */
    public static function ensureCookie(): string
    {
        $existing = SessionCookie::readCsrf();

        if (is_string($existing) && 64 === strlen($existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        SessionCookie::writeCsrf($token);

        return $token;
    }
}
