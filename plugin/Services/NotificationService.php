<?php

namespace FitnessClub\Services;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * In-app notifications (plans/03-backend.md).
 *
 * W1.4 needs the create path — completing a session and setting a personal
 * record both raise one. Preferences are honoured from the start rather than
 * bolted on later: a user who turned achievements off and still gets an
 * achievement badge has been told their setting is decorative.
 *
 * Email/push fan-out and the digest scheduling are Phase 2; this writes the row
 * the Notifications screen reads.
 */
final class NotificationService
{
    /**
     * Raise a notification unless the user has that category switched off.
     *
     * @param string              $type One of config('fitnessclub.enums.notification_type').
     * @param array<string,mixed> $meta Payload for the renderer (ids, values).
     *
     * @return int Row id, or 0 when suppressed by preference.
     */
    public function notify(
        int $wpUserId,
        string $type,
        string $title,
        string $body = '',
        ?string $actionUrl = null,
        ?string $icon = null,
        ?string $color = null,
        array $meta = []
    ): int {
        global $wpdb;

        if ($wpUserId <= 0 || !$this->wants($wpUserId, $type)) {
            return 0;
        }

        $wpdb->insert($wpdb->prefix . 'fc_notifications', [
            'wp_user_id' => $wpUserId,
            'type'       => $type,
            'title'      => mb_substr($title, 0, 255),
            'body'       => $body,
            'icon'       => $icon,
            'color'      => $color,
            'action_url' => $actionUrl,
            'meta'       => $meta === [] ? null : wp_json_encode($meta),
            'is_read'    => 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Per-category opt-out, stored in `fc_users.preferences` as
     * `notifications: { achievement: false, … }`. Absent means on — a new
     * category must not be silently muted for existing users.
     */
    private function wants(int $wpUserId, string $type): bool
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT preferences FROM {$wpdb->prefix}fc_users WHERE wp_user_id = %d LIMIT 1",
            $wpUserId
        ));

        if (null === $raw) {
            return true;
        }

        $preferences = json_decode((string) $raw, true);
        if (!is_array($preferences)) {
            return true;
        }

        $setting = $preferences['notifications'][$type] ?? true;

        return (bool) $setting;
    }
}
