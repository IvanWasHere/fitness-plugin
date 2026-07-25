<?php

namespace FitnessClub\Providers;

use FitnessClub\Services\WorkoutSessionService;
use FitnessClub\WPBones\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Recurring jobs (plans/03-backend.md#scheduled-jobs).
 *
 * plans/03 says "registered via wpBones' schedule provider (config/plugin.php →
 * schedules)". wpBones v2 has no such feature — the framework covers custom post
 * types, taxonomies, shortcodes, widgets and ajax, but not WP-Cron — so this is
 * native `wp_schedule_event`, wrapped in a provider so the wiring still lives
 * where the plan said it would.
 *
 * W1.4 registers the one job the session state machine needs. The rest
 * (subscription expiry, dunning, reminders, streak recalculation, activity
 * pruning) arrive with the domains that own them.
 */
class ScheduleProvider extends ServiceProvider
{
    public const STALE_SESSIONS = 'fitnessclub_abandon_stale_sessions';

    public function register()
    {
        add_action(self::STALE_SESSIONS, [$this, 'abandonStaleSessions']);

        if (!wp_next_scheduled(self::STALE_SESSIONS)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::STALE_SESSIONS);
        }
    }

    /**
     * Close sessions left open past `limits.stale_session_hours`.
     *
     * Without this a user who closes the tab mid-workout keeps an open session
     * forever, and every later `POST /sessions` answers 409. The player offers
     * "resume or discard" for the recent case; this is the long tail nobody
     * comes back to.
     */
    public function abandonStaleSessions(): void
    {
        $closed = (new WorkoutSessionService())->abandonStale();

        if ($closed > 0) {
            $this->plugin->log()->info("Abandoned {$closed} stale workout session(s).");
        }
    }

    /**
     * Clear the schedule on deactivation — a plugin that leaves cron entries
     * behind fires actions nothing is listening for.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::STALE_SESSIONS);

        while (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::STALE_SESSIONS);
            $timestamp = wp_next_scheduled(self::STALE_SESSIONS);
        }
    }
}
