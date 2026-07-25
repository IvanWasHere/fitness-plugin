<?php

namespace FitnessClub\Services;

use FitnessClub\Support\AppRouter;
use FitnessClub\Support\Guard;
use WP_User;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The single boot payload (plans/02-api-contract.md#auth).
 *
 * One object, two carriers:
 *
 *   - `me()`    — the body of `GET /auth/me`, re-fetched after login and on
 *                 nonce refresh.
 *   - `shell()` — the same object plus transport bits (REST root, nonce,
 *                 admin-ajax URL, branding), injected into the render shell's
 *                 `data-boot` so the SPA has everything before its first fetch.
 *
 * Building both from one place is the point: the app must not render one thing
 * on first paint and a different thing after `/auth/me` resolves.
 */
final class BootPresenter
{
    private ThemeService $theme;

    private EntitlementService $entitlements;

    public function __construct(?ThemeService $theme = null, ?EntitlementService $entitlements = null)
    {
        $this->theme        = $theme ?? new ThemeService();
        $this->entitlements = $entitlements ?? new EntitlementService();
    }

    /**
     * The `GET /auth/me` body for the current user.
     *
     * @return array<string,mixed>
     */
    public function me(): array
    {
        $wpUser   = wp_get_current_user();
        $loggedIn = $wpUser->exists();
        $fcUserId = $loggedIn ? (Guard::userId((int) $wpUser->ID) ?? 0) : 0;

        return [
            'user'          => $loggedIn ? $this->user($wpUser, $fcUserId) : null,
            'subscriptions' => $fcUserId > 0 ? $this->subscriptions($fcUserId) : [],
            'trainers'      => $fcUserId > 0 ? $this->trainers($fcUserId) : [],
            'entitlements'  => $this->presentEntitlements($fcUserId),
            'theme'         => $this->theme->activeTokens(),
            'app'           => $this->app(),
            'counts'        => $loggedIn ? $this->counts((int) $wpUser->ID, $fcUserId) : self::emptyCounts(),
        ];
    }

    /**
     * The `data-boot` payload for the render shell.
     *
     * @return array<string,mixed>
     */
    public function shell(): array
    {
        return $this->me() + [
            'restUrl' => esc_url_raw(rest_url('fitnessclub/v1/')),
            'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'brand'   => (string) FitnessClub()->options->get('branding.name', 'FitForge'),
            'locale'  => determine_locale(),
            'flags'   => [
                // The login panel hides its "create an account" tab when closed.
                'registration_open' => (bool) FitnessClub()->options->get('features.registration_open', true),
            ],
        ];
    }

    /**
     * Entitlements as the client expects them. The per-trainer quota map is an
     * *object* on the wire even when empty — PHP would encode an empty array as
     * `[]`, and a client doing `quota[trainerId]` on a JSON array is a bug
     * waiting for the first user with no trainers.
     *
     * @return array<string,mixed>
     */
    private function presentEntitlements(int $fcUserId): array
    {
        $entitlements = $this->entitlements->forUser($fcUserId);

        $entitlements['message_quota_by_trainer'] = (object) ($entitlements['message_quota_by_trainer'] ?? []);

        return $entitlements;
    }

    /**
     * Identity. `id` is the internal fc_users.id and is deliberately not "the
     * user id" anywhere in the API — `wp_user_id` is the identity anchor.
     *
     * @return array<string,mixed>
     */
    private function user(WP_User $wpUser, int $fcUserId): array
    {
        $profile = $this->profileRow($fcUserId);
        $role    = AppRouter::currentRole();

        $user = [
            'id'           => $fcUserId,
            'wp_user_id'   => (int) $wpUser->ID,
            'display_name' => $profile['display_name'] ?: $wpUser->display_name,
            'email'        => $wpUser->user_email,
            'avatar_url'   => $profile['avatar_url'] ?: get_avatar_url($wpUser->ID),
            'role'         => $role,
            'timezone'     => $profile['timezone'] ?: wp_timezone_string(),
            'onboarded'    => null !== $profile['onboarded_at'],
        ];

        if (AppRouter::ROLE_USER !== $role) {
            // The trainer SPA needs its fc_trainers.id; an admin may also coach.
            $user['trainer_id'] = Guard::trainerId((int) $wpUser->ID) ?? 0;
        }

        return $user;
    }

    /**
     * @return array{display_name:?string,avatar_url:?string,timezone:?string,onboarded_at:?string}
     */
    private function profileRow(int $fcUserId): array
    {
        global $wpdb;

        $empty = ['display_name' => null, 'avatar_url' => null, 'timezone' => null, 'onboarded_at' => null];

        if ($fcUserId <= 0) {
            return $empty;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name, avatar_url, timezone, onboarded_at
               FROM {$wpdb->prefix}fc_users WHERE id = %d LIMIT 1",
            $fcUserId
        ), ARRAY_A);

        return is_array($row) ? $row + $empty : $empty;
    }

    /**
     * Every subscription the user holds — an array, because one subscription per
     * trainer is normal (Q3).
     *
     * @return array<int,array<string,mixed>>
     */
    private function subscriptions(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.plan_name, p.slug AS plan_slug, s.status, s.subscription_type AS cycle,
                    s.end_date, s.trainer_id, s.cancel_at_period_end
               FROM {$wpdb->prefix}fc_subscriptions s
               JOIN {$wpdb->prefix}fc_plans p ON p.id = s.plan_id
              WHERE s.user_id = %d AND s.status <> 'expired'
              ORDER BY s.id ASC",
            $fcUserId
        ), ARRAY_A);

        return array_map(static fn(array $r): array => [
            'plan_name'            => $r['plan_name'],
            'plan_slug'            => $r['plan_slug'],
            'status'               => $r['status'],
            'cycle'                => $r['cycle'],
            'renews_at'            => $r['end_date'],
            'trainer_id'           => null === $r['trainer_id'] ? null : (int) $r['trainer_id'],
            'cancel_at_period_end' => (bool) $r['cancel_at_period_end'],
        ], $rows ?: []);
    }

    /**
     * Coaching links. Pending requests are included with their status so the
     * profile screen can show "requested" without a second call (Q4).
     *
     * @return array<int,array<string,mixed>>
     */
    private function trainers(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.display_name, t.avatar_url, t.specialization, ut.is_primary, ut.status
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = ut.trainer_id
              WHERE ut.user_id = %d AND ut.status IN ('active', 'pending')
              ORDER BY ut.is_primary DESC, t.id ASC",
            $fcUserId
        ), ARRAY_A);

        return array_map(static fn(array $r): array => [
            'id'             => (int) $r['id'],
            'display_name'   => $r['display_name'],
            'avatar_url'     => $r['avatar_url'],
            'specialization' => $r['specialization'],
            'is_primary'     => (bool) $r['is_primary'],
            'status'         => $r['status'],
        ], $rows ?: []);
    }

    /**
     * Nav badges. Message counts come off the denormalised per-thread counters,
     * so this is two indexed reads rather than a scan of fc_messages.
     *
     * @return array{unread_messages:int,unread_notifications:int}
     */
    private function counts(int $wpUserId, int $fcUserId): array
    {
        global $wpdb;

        $unreadMessages = 0;

        if ($fcUserId > 0) {
            $unreadMessages += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(user_unread_count), 0)
                   FROM {$wpdb->prefix}fc_message_threads WHERE user_id = %d",
                $fcUserId
            ));
        }

        $trainerId = Guard::trainerId($wpUserId);
        if (null !== $trainerId) {
            $unreadMessages += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_unread_count), 0)
                   FROM {$wpdb->prefix}fc_message_threads WHERE trainer_id = %d",
                $trainerId
            ));
        }

        $unreadNotifications = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications
              WHERE wp_user_id = %d AND is_read = 0",
            $wpUserId
        ));

        return [
            'unread_messages'      => $unreadMessages,
            'unread_notifications' => $unreadNotifications,
        ];
    }

    /**
     * @return array{base:string,basename:string,spa:string}
     */
    private function app(): array
    {
        $base = AppRouter::basePath();

        return [
            'base'     => $base,
            'basename' => $base,
            'spa'      => AppRouter::currentSpa(),
        ];
    }

    /**
     * @return array{unread_messages:int,unread_notifications:int}
     */
    private static function emptyCounts(): array
    {
        return ['unread_messages' => 0, 'unread_notifications' => 0];
    }
}
