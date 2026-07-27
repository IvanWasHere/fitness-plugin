<?php

namespace FitnessClub\Providers;

use FitnessClub\Services\PaymentService;
use FitnessClub\Services\SubscriptionService;
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

    /** Subscription expiry and dunning, both daily (W3.2). */
    public const EXPIRE_SUBSCRIPTIONS = 'fitnessclub_expire_subscriptions';
    public const RETRY_PAYMENTS       = 'fitnessclub_retry_failed_payments';

    /** @var array<string,string> hook => handler method. */
    private const JOBS = [
        self::STALE_SESSIONS       => 'abandonStaleSessions',
        self::EXPIRE_SUBSCRIPTIONS => 'expireSubscriptions',
        self::RETRY_PAYMENTS       => 'retryFailedPayments',
    ];

    public function register()
    {
        $offset = HOUR_IN_SECONDS;

        foreach (self::JOBS as $hook => $handler) {
            add_action($hook, [$this, $handler]);

            if (!wp_next_scheduled($hook)) {
                // Staggered rather than all at the same minute: expiry must run
                // before dunning, or dunning chases subscriptions that expiry
                // has not yet moved to past_due.
                wp_schedule_event(time() + $offset, 'daily', $hook);
            }

            $offset += 15 * MINUTE_IN_SECONDS;
        }
    }

    /**
     * Move ended subscriptions to `expired` or `past_due` (W3.2).
     */
    public function expireSubscriptions(): void
    {
        $result = (new SubscriptionService())->runExpiry();

        if ($result['expired'] > 0 || $result['past_due'] > 0) {
            $this->plugin->log()->info(sprintf(
                'Subscription sweep: %d expired, %d past due.',
                $result['expired'],
                $result['past_due']
            ));
        }
    }

    /**
     * Dunning: chase past-due members, then suspend after the grace period.
     */
    public function retryFailedPayments(): void
    {
        $result = (new PaymentService())->runDunning();

        if ($result['notified'] > 0 || $result['suspended'] > 0) {
            $this->plugin->log()->info(sprintf(
                'Dunning: %d notified, %d suspended.',
                $result['notified'],
                $result['suspended']
            ));
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
        // Every job, not just the first one registered: a hook left scheduled
        // after deactivation fires an action nothing is listening for, and the
        // single-hook version of this was already one job out of date the moment
        // a second was added.
        foreach (array_keys(self::JOBS) as $hook) {
            $timestamp = wp_next_scheduled($hook);

            while (false !== $timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }
}
