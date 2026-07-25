<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fixed-window request counter, keyed `fc_rl_{bucket}_{userId|ipHash}`
 * (plans/03-backend.md#rate-limiting).
 *
 *     RateLimiter::hit('login', RateLimiter::ipHash(), 5, MINUTE_IN_SECONDS);
 *
 * ## The object-cache caveat, honestly
 *
 * With a persistent object cache (Redis/Memcached) a transient is a memory
 * read+write and this is cheap. **Without one, every transient write is a
 * `wp_options` write** — so a limiter that runs on every API request is itself
 * the performance problem it was meant to prevent.
 *
 * So the limiter is split by cost:
 *
 *   - **Targeted buckets** (login, registration, password reset) always run.
 *     They are bounded by their own limit — five failed logins per minute per IP
 *     is at most a handful of option writes, and the alternative is an
 *     unthrottled credential-stuffing endpoint.
 *   - **The blanket per-request bucket** (`api`, 100/min authenticated,
 *     10/min public) only runs when `wp_using_ext_object_cache()` is true.
 *     `shouldThrottleEveryRequest()` reports that decision so Settings and Site
 *     Health can say *why* the global limit is inactive rather than implying a
 *     protection that is not there.
 *
 * The dedicated lightweight table that would let the blanket bucket run without
 * an object cache is deferred; it needs a migration and a pruning job.
 *
 * IPs are hashed with the site salt before they are stored — a raw IP is
 * personal data under GDPR and a rate-limit key does not need to be reversible.
 */
final class RateLimiter
{
    private const PREFIX = 'fc_rl_';

    /**
     * Count one request against a bucket.
     *
     * @param string $bucket Bucket name, e.g. "login".
     * @param string $key    Identity within the bucket — a user id or an ipHash().
     * @param int    $limit  Requests allowed per window.
     * @param int    $window Window length in seconds.
     *
     * @return int Requests remaining in the current window.
     *
     * @throws RateLimitException When the bucket is exhausted.
     */
    public static function hit(string $bucket, string $key, int $limit, int $window): int
    {
        $window = max(1, $window);
        $state  = self::state($bucket, $key, $window);

        $state['count']++;

        set_transient(
            self::transientKey($bucket, $key),
            $state,
            max(1, $state['reset'] - time())
        );

        if ($state['count'] > $limit) {
            throw new RateLimitException($bucket, $limit, $state['reset']);
        }

        return max(0, $limit - $state['count']);
    }

    /**
     * Requests remaining without consuming one. Used by tests and by responses
     * that want to advertise headroom.
     */
    public static function remaining(string $bucket, string $key, int $limit, int $window): int
    {
        $state = self::state($bucket, $key, max(1, $window));

        return max(0, $limit - $state['count']);
    }

    /**
     * Forget a bucket for one identity — called after a *successful* login so a
     * user who mistyped their password four times is not throttled afterwards.
     */
    public static function clear(string $bucket, string $key): void
    {
        delete_transient(self::transientKey($bucket, $key));
    }

    /**
     * A stable, non-reversible identifier for the caller's IP.
     *
     * REMOTE_ADDR only: `X-Forwarded-For` is attacker-controlled unless the site
     * sits behind a proxy that overwrites it, and trusting it blindly turns the
     * limiter into a no-op (spoof a new IP per request).
     */
    public static function ipHash(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : 'unknown';

        return substr(wp_hash($ip . '|fc_rate_limit', 'nonce'), 0, 32);
    }

    /**
     * A stable key for an arbitrary string identity (e.g. an email address for
     * the forgot-password bucket), hashed for the same reason as the IP.
     */
    public static function keyFor(string $value): string
    {
        return substr(wp_hash(strtolower(trim($value)) . '|fc_rate_limit', 'nonce'), 0, 32);
    }

    /**
     * Is a blanket per-request limit affordable on this install? See the class
     * docblock — false means the site has no persistent object cache.
     */
    public static function shouldThrottleEveryRequest(): bool
    {
        // Cast, not trust: wp_using_ext_object_cache() returns the *previous*
        // value of the global, which is null before anything has set it.
        return (bool) wp_using_ext_object_cache();
    }

    /**
     * Current window state, restarting the window when it has rolled over.
     *
     * @return array{count:int,reset:int}
     */
    private static function state(string $bucket, string $key, int $window): array
    {
        $state = get_transient(self::transientKey($bucket, $key));
        $now   = time();

        if (!is_array($state) || !isset($state['count'], $state['reset']) || $state['reset'] <= $now) {
            return ['count' => 0, 'reset' => $now + $window];
        }

        return ['count' => (int) $state['count'], 'reset' => (int) $state['reset']];
    }

    private static function transientKey(string $bucket, string $key): string
    {
        // Transient names are capped at 172 chars; both parts are already short
        // and sanitised, but be explicit rather than trusting callers.
        return self::PREFIX . preg_replace('/[^a-z0-9_]/', '', strtolower($bucket)) . '_'
            . preg_replace('/[^A-Za-z0-9_]/', '', $key);
    }
}
