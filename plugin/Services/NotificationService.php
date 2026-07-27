<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * In-app notifications (plans/03-backend.md, W1.4 write path + W2.4 read path).
 *
 * Preferences are honoured at the **write**, not at the read: a user who turned
 * achievements off and still gets an achievement badge has been told their
 * setting is decorative. Filtering on read would leave the rows in the table and
 * the unread count wrong.
 *
 * ## The unread count travels with every mutation
 *
 * Every endpoint that changes read state returns the resulting `unread_count`.
 * The nav badge is the reason: a client that has to issue a second request to
 * find out what the badge should say will render the stale number in between,
 * and the number it renders is the one the user just cleared.
 *
 * Email/push fan-out and the digest scheduling remain Phase 4.
 */
final class NotificationService
{
    /** Categories a member can switch off, from config('fitnessclub.enums.notification_type'). */
    public const TYPES = [
        'workout', 'message', 'achievement',
        'subscription', 'system', 'progress', 'support',
    ];

    /**
     * `GET /notifications` — the member's inbox, newest first.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,unread_count:int}
     */
    public function list(int $accountId, bool $unreadOnly = false, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        // Two statements rather than SQL_CALC_FOUND_ROWS, which MySQL 8 has
        // deprecated and which is slower than a second COUNT on an index the
        // table already carries.
        if ($unreadOnly) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications
                  WHERE account_id = %d AND is_read = 0",
                $accountId
            ));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_notifications
                  WHERE account_id = %d AND is_read = 0
                  ORDER BY created_at DESC, id DESC
                  LIMIT %d OFFSET %d",
                $accountId,
                $perPage,
                $offset
            ), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d",
                $accountId
            ));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_notifications
                  WHERE account_id = %d
                  ORDER BY created_at DESC, id DESC
                  LIMIT %d OFFSET %d",
                $accountId,
                $perPage,
                $offset
            ), ARRAY_A);
        }

        return [
            'items'        => array_map(fn(array $row): array => $this->present($row), $rows ?: []),
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'unread_count' => $this->unreadCount($accountId),
        ];
    }

    /**
     * `POST /notifications/{id}/read`.
     *
     * Idempotent: tapping an already-read notification is a no-op that still
     * answers with the count, because the client cannot know which it was.
     *
     * @return array{ok:bool,unread_count:int}
     */
    public function markRead(int $accountId, int $notificationId): array
    {
        global $wpdb;

        $this->assertOwned($accountId, $notificationId);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_notifications
                SET is_read = 1, read_at = UTC_TIMESTAMP()
              WHERE id = %d AND account_id = %d AND is_read = 0",
            $notificationId,
            $accountId
        ));

        return ['ok' => true, 'unread_count' => $this->unreadCount($accountId)];
    }

    /**
     * `POST /notifications/read-all`.
     *
     * @return array{ok:bool,marked:int,unread_count:int}
     */
    public function markAllRead(int $accountId): array
    {
        global $wpdb;

        $marked = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_notifications
                SET is_read = 1, read_at = UTC_TIMESTAMP()
              WHERE account_id = %d AND is_read = 0",
            $accountId
        ));

        return ['ok' => true, 'marked' => $marked, 'unread_count' => $this->unreadCount($accountId)];
    }

    /**
     * `DELETE /notifications/{id}` — dismiss.
     *
     * @return array{ok:bool,unread_count:int}
     */
    public function dismiss(int $accountId, int $notificationId): array
    {
        global $wpdb;

        $this->assertOwned($accountId, $notificationId);

        // Scoped by account_id as well as id, so a mismatched pair can never
        // remove somebody else's row.
        $wpdb->delete($wpdb->prefix . 'fc_notifications', [
            'id'         => $notificationId,
            'account_id' => $accountId,
        ]);

        return ['ok' => true, 'unread_count' => $this->unreadCount($accountId)];
    }

    public function unreadCount(int $accountId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications
              WHERE account_id = %d AND is_read = 0",
            $accountId
        ));
    }

    /**
     * The member's per-category switches, with every known category present.
     *
     * Absent means **on**: a category added in a later release must not arrive
     * silently muted for everyone who already has a preferences blob.
     *
     * @return array<string,bool>
     */
    public function preferences(int $accountId): array
    {
        $stored = $this->storedPreferences($accountId)['notifications'] ?? [];
        $result = [];

        foreach (self::TYPES as $type) {
            $result[$type] = !isset($stored[$type]) || (bool) $stored[$type];
        }

        return $result;
    }

    /**
     * Update the switches, merging rather than replacing.
     *
     * `fc_users.preferences` is a shared blob — privacy settings and the
     * profile's own flags live beside these — so writing the notifications key
     * wholesale would silently drop everything else stored there.
     *
     * @param array<string,mixed> $toggles
     * @return array<string,bool>
     */
    public function setPreferences(int $accountId, array $toggles): array
    {
        global $wpdb;

        $known = [];

        foreach ($toggles as $type => $enabled) {
            if (in_array($type, self::TYPES, true)) {
                $known[$type] = (bool) $enabled;
            }
        }

        if ([] === $known) {
            throw new DomainException(
                'fc_preferences_empty',
                __('No known notification settings were supplied.', 'fitnessclub'),
                400
            );
        }

        $preferences = $this->storedPreferences($accountId);
        $preferences['notifications'] = array_merge(
            is_array($preferences['notifications'] ?? null) ? $preferences['notifications'] : [],
            $known
        );

        $wpdb->update(
            $wpdb->prefix . 'fc_users',
            ['preferences' => wp_json_encode($preferences), 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['account_id' => $accountId]
        );

        return $this->preferences($accountId);
    }

    /**
     * Raise a notification unless the user has that category switched off.
     *
     * @param string              $type One of config('fitnessclub.enums.notification_type').
     * @param array<string,mixed> $meta Payload for the renderer (ids, values).
     *
     * @return int Row id, or 0 when suppressed by preference.
     */
    public function notify(
        int $accountId,
        string $type,
        string $title,
        string $body = '',
        ?string $actionUrl = null,
        ?string $icon = null,
        ?string $color = null,
        array $meta = []
    ): int {
        global $wpdb;

        if ($accountId <= 0 || !$this->wants($accountId, $type)) {
            return 0;
        }

        $wpdb->insert($wpdb->prefix . 'fc_notifications', [
            'account_id' => $accountId,
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
    private function wants(int $accountId, string $type): bool
    {
        $setting = $this->storedPreferences($accountId)['notifications'][$type] ?? true;

        return (bool) $setting;
    }

    /**
     * The whole preferences blob, or an empty array.
     *
     * @return array<string,mixed>
     */
    private function storedPreferences(int $accountId): array
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT preferences FROM {$wpdb->prefix}fc_users WHERE account_id = %d LIMIT 1",
            $accountId
        ));

        $preferences = json_decode((string) $raw, true);

        return is_array($preferences) ? $preferences : [];
    }

    /**
     * A notification belonging to somebody else is a 404, never a 403 — a 403
     * confirms the row exists.
     */
    private function assertOwned(int $accountId, int $notificationId): void
    {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_notifications WHERE id = %d AND account_id = %d LIMIT 1",
            $notificationId,
            $accountId
        ));

        if (null === $exists) {
            throw new DomainException(
                'fc_notification_not_found',
                __('That notification does not exist.', 'fitnessclub'),
                404
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $meta = json_decode((string) ($row['meta'] ?? ''), true);

        return [
            'id'         => (int) $row['id'],
            'type'       => $row['type'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'icon'       => $row['icon'],
            'color'      => $row['color'],
            'action_url' => $row['action_url'],
            'meta'       => is_array($meta) ? $meta : [],
            'is_read'    => (bool) $row['is_read'],
            'read_at'    => $this->iso($row['read_at'] ?? null),
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    private function iso($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value . ' UTC');

        return false === $timestamp ? null : gmdate('c', $timestamp);
    }
}
