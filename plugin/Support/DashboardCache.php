<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The 60-second per-member cache in front of `GET /user/dashboard`
 * (plans/02-api-contract.md#cross-cutting).
 *
 * ## Why invalidation is a hook, not a method call
 *
 * The aggregate is fed by six subsystems, only two of which exist today —
 * nutrition, water, health and messaging all land in Phase 2 and all change
 * numbers on this screen. A cache that every future write site has to *remember*
 * to clear is a cache that goes stale, and the symptom is already in the demo
 * fixtures as a support ticket: "my last two sessions did not appear on the
 * dashboard until I refreshed."
 *
 * So writers announce, rather than clean up:
 *
 *     do_action('fitnessclub/user_data_changed', $fcUserId, 'session.completed');
 *
 * One line, no import, nothing to construct inside a transaction. This class
 * subscribes once at boot. A Phase 2 service that fires the action gets correct
 * invalidation for free; one that forgets is a stale dashboard for at most a
 * minute, not forever.
 *
 * ## The transient caveat
 *
 * Without a persistent object cache a transient is a `wp_options` write. Unlike
 * the blanket rate limiter — which would write on *every* request and so is
 * disabled without one (see RateLimiter) — this writes at most once per member
 * per minute, and it is replacing a six-query aggregate. That trade is worth
 * taking on either kind of install, so this cache always runs.
 */
final class DashboardCache
{
    private const PREFIX = 'fc_dash_';

    /** Seconds. Long enough to absorb a screen's worth of refetches. */
    public const TTL = 60;

    /**
     * Subscribe to the invalidation signal. Called once, from the API provider.
     */
    public static function listen(): void
    {
        add_action('fitnessclub/user_data_changed', [self::class, 'forget'], 10, 1);
    }

    /**
     * Cached payload for a member, or null on a miss.
     *
     * @return array<string,mixed>|null
     */
    public static function get(int $fcUserId): ?array
    {
        $cached = get_transient(self::key($fcUserId));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function put(int $fcUserId, array $payload): void
    {
        set_transient(self::key($fcUserId), $payload, self::TTL);
    }

    /**
     * Drop a member's cached dashboard. Also the action callback, which is why
     * it takes the id positionally and ignores the reason argument.
     */
    public static function forget(int $fcUserId): void
    {
        if ($fcUserId > 0) {
            delete_transient(self::key($fcUserId));
        }
    }

    private static function key(int $fcUserId): string
    {
        return self::PREFIX . $fcUserId;
    }
}
