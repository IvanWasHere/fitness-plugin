<?php

namespace FitnessClub\Auth;

use FitnessClub\Support\RateLimiter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Issues, resolves and revokes rows in fc_sessions.
 *
 * ## The split token
 *
 * The cookie is `{selector}.{verifier}`. The row is found by `selector`, which
 * is indexed and carries no secrecy requirement; the verifier is compared with
 * `hash_equals()` against the stored SHA-256. Looking the row up by a hash of
 * the whole secret would work too, but then the only handle on a session is the
 * secret itself — and sooner or later it ends up in a log line.
 *
 * ## What does not happen here
 *
 * **The verifier is not rotated per request.** It is tempting, and it breaks a
 * SPA immediately: three parallel `fetch()` calls all present the same cookie,
 * the first rotates it, and the other two arrive with a value that no longer
 * exists. Rotation happens on sign-in and on password change — the two moments
 * where a fixation or a theft actually matters.
 */
final class SessionStore
{
    /** A browser session, authenticated by cookie. */
    public const KIND_COOKIE = 'cookie';

    /** An API refresh token, authenticated by bearer (W4.2). */
    public const KIND_REFRESH = 'refresh';

    /**
     * Create a session and return the cookie value plus its CSRF token.
     *
     * @return array{cookie:string,csrf:string,expires:int}
     */
    public function issue(int $accountId, bool $remember = false): array
    {
        global $wpdb;

        $auth     = (array) FitnessClub()->config('fitnessclub.auth', []);
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $csrf     = bin2hex(random_bytes(32));

        $idleMinutes   = self::idleMinutes($remember, $auth);
        $absoluteHours = $remember
            ? (int) ($auth['remember_absolute_days'] ?? 90) * 24
            : (int) ($auth['absolute_hours'] ?? 24);

        $now      = time();
        $expires  = $now + ($idleMinutes * MINUTE_IN_SECONDS);
        $absolute = $now + ($absoluteHours * HOUR_IN_SECONDS);
        $expires  = min($expires, $absolute);

        $wpdb->insert($wpdb->prefix . 'fc_sessions', [
            'account_id'          => $accountId,
            'kind'                => self::KIND_COOKIE,
            'selector'            => $selector,
            'token_hash'          => hash('sha256', $verifier),
            'csrf_hash'           => hash('sha256', $csrf),
            'remember'            => $remember ? 1 : 0,
            'ip_hash'             => RateLimiter::ipHash(),
            'user_agent'          => self::userAgent(),
            'issued_at'           => gmdate('Y-m-d H:i:s', $now),
            'last_seen_at'        => gmdate('Y-m-d H:i:s', $now),
            'expires_at'          => gmdate('Y-m-d H:i:s', $expires),
            'absolute_expires_at' => gmdate('Y-m-d H:i:s', $absolute),
        ]);

        return [
            'cookie'  => $selector . '.' . $verifier,
            'csrf'    => $csrf,
            // A browser-session cookie unless "remember me" was asked for. The
            // server-side expiry is authoritative either way.
            'expires' => $remember ? $absolute : 0,
        ];
    }

    /**
     * Issue an API refresh token (W4.2).
     *
     * The same split token as a browser session, in the same table, so that
     * `revoke()`, `revokeAllFor()` and `gc()` cover it without a second
     * implementation — which matters most for `revokeAllFor()`, called on
     * password change. A separate table would have meant remembering to revoke
     * mobile grants there too, and the failure mode of forgetting is a password
     * reset that does not actually lock anybody out.
     *
     * No CSRF token: a bearer credential is not sent ambiently by a browser, so
     * there is no cross-site request to forge.
     *
     * @return array{token:string,session_id:int,expires_at:int}
     */
    public function issueRefreshToken(int $accountId): array
    {
        global $wpdb;

        $auth     = (array) FitnessClub()->config('fitnessclub.auth', []);
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $idleDays     = max(1, (int) ($auth['api_refresh_idle_days'] ?? 30));
        $absoluteDays = max($idleDays, (int) ($auth['api_refresh_absolute_days'] ?? 180));

        $now      = time();
        $expires  = $now + ($idleDays * DAY_IN_SECONDS);
        $absolute = $now + ($absoluteDays * DAY_IN_SECONDS);

        $wpdb->insert($wpdb->prefix . 'fc_sessions', [
            'account_id'          => $accountId,
            'kind'                => self::KIND_REFRESH,
            'selector'            => $selector,
            'token_hash'          => hash('sha256', $verifier),
            'csrf_hash'           => null,
            'remember'            => 1,
            'ip_hash'             => RateLimiter::ipHash(),
            'user_agent'          => self::userAgent(),
            'issued_at'           => gmdate('Y-m-d H:i:s', $now),
            'last_seen_at'        => gmdate('Y-m-d H:i:s', $now),
            'expires_at'          => gmdate('Y-m-d H:i:s', $expires),
            'absolute_expires_at' => gmdate('Y-m-d H:i:s', $absolute),
        ]);

        return [
            'token'      => $selector . '.' . $verifier,
            'session_id' => (int) $wpdb->insert_id,
            'expires_at' => $expires,
        ];
    }

    /**
     * Resolve a token value to its live session row.
     *
     * `$kind` is checked rather than merely returned, so a refresh token cannot
     * be presented as a session cookie or the other way round. They live in one
     * table and are the same shape; only this check keeps them from being
     * interchangeable.
     *
     * @return array{id:int,account_id:int,kind:string,csrf_hash:string,remember:int,expires_at:string,last_seen_at:string}|null
     */
    public function resolve(?string $cookie, string $kind = self::KIND_COOKIE): ?array
    {
        global $wpdb;

        if (null === $cookie || !str_contains($cookie, '.')) {
            return null;
        }

        [$selector, $verifier] = explode('.', $cookie, 2);

        if (32 !== strlen($selector) || '' === $verifier) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, account_id, kind, token_hash, csrf_hash, remember, expires_at,
                    absolute_expires_at, last_seen_at, revoked_at
               FROM {$wpdb->prefix}fc_sessions
              WHERE selector = %s
              LIMIT 1",
            $selector
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        if ($kind !== (string) $row['kind']) {
            return null;
        }

        // hash_equals, not ===: a plain comparison on a secret leaks its prefix
        // through timing.
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $verifier))) {
            return null;
        }

        if (null !== $row['revoked_at']) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($row['expires_at'] < $now || $row['absolute_expires_at'] < $now) {
            return null;
        }

        return [
            'id'           => (int) $row['id'],
            'account_id'   => (int) $row['account_id'],
            'kind'         => (string) $row['kind'],
            'csrf_hash'    => (string) $row['csrf_hash'],
            'remember'     => (int) $row['remember'],
            'expires_at'   => (string) $row['expires_at'],
            'last_seen_at' => (string) $row['last_seen_at'],
        ];
    }

    /**
     * Is this refresh-token row still live?
     *
     * Used on every API request that presents an access token. The JWT itself is
     * stateless and unrevokable, so this is what makes signing out actually sign
     * somebody out: the access token stays cryptographically valid for up to its
     * fifteen minutes, but the grant behind it is gone and the request is
     * refused.
     */
    public function grantIsLive(int $sessionId): bool
    {
        global $wpdb;

        if ($sessionId <= 0) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1
               FROM {$wpdb->prefix}fc_sessions
              WHERE id = %d
                AND kind = %s
                AND revoked_at IS NULL
                AND expires_at > UTC_TIMESTAMP()
                AND absolute_expires_at > UTC_TIMESTAMP()
              LIMIT 1",
            $sessionId,
            self::KIND_REFRESH
        ));
    }

    /**
     * Slide the idle window forward — at most once every few minutes.
     *
     * Without the floor this is a write on every authenticated request, which on
     * a busy site is a bigger cost than the whole rest of the auth system. The
     * absolute expiry is never extended.
     *
     * @param array{id:int,remember:int,last_seen_at:string} $session
     */
    public function touch(array $session): void
    {
        global $wpdb;

        $auth     = (array) FitnessClub()->config('fitnessclub.auth', []);
        $interval = max(60, (int) ($auth['touch_interval_seconds'] ?? 300));

        $lastSeen = strtotime($session['last_seen_at'] . ' UTC') ?: 0;
        if ((time() - $lastSeen) < $interval) {
            return;
        }

        $idleMinutes = self::idleMinutes((bool) $session['remember'], $auth);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_sessions
                SET last_seen_at = UTC_TIMESTAMP(),
                    expires_at = LEAST(
                        DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d MINUTE),
                        absolute_expires_at
                    )
              WHERE id = %d",
            $idleMinutes,
            $session['id']
        ));
    }

    public function revoke(int $sessionId): void
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'fc_sessions',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $sessionId]
        );
    }

    /**
     * Evict every session an account holds.
     *
     * Called on password change, which is the point: a reset whose whole
     * motivation may be "somebody else is in my account" has to remove them.
     */
    public function revokeAllFor(int $accountId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_sessions
                SET revoked_at = UTC_TIMESTAMP()
              WHERE account_id = %d AND revoked_at IS NULL",
            $accountId
        ));
    }

    /**
     * Delete long-dead rows. Kept a week past expiry so "was I signed out?"
     * support questions still have something to look at.
     */
    public function gc(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fc_sessions
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
    }

    /**
     * The idle window in minutes. One definition, used by both issue() and
     * touch(), so a "remember me" session cannot be created with one window and
     * then slid forward with another.
     *
     * @param array<string,mixed> $auth The `fitnessclub.auth` config block.
     */
    private static function idleMinutes(bool $remember, array $auth): int
    {
        $minutes = $remember
            ? (int) ($auth['remember_idle_days'] ?? 14) * 24 * 60
            : (int) ($auth['idle_minutes'] ?? 720);

        return max(1, $minutes);
    }

    /**
     * The caller's user agent, for the "your active sessions" list. Truncated
     * to the column width, and sanitised because it is attacker-controlled text
     * that an admin screen will eventually render.
     */
    private static function userAgent(): string
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }

        return substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255);
    }
}
