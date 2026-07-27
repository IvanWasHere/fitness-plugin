<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;
use FitnessClub\Support\Guard;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Threads, messages and the send quota (plans/02-api-contract.md#messaging, W3.1).
 *
 * ## The quota is per thread, not per user (Q3)
 *
 * A member coached by two trainers holds one subscription per trainer, and each
 * conversation gets its own allowance resolved from *that trainer's* plan. A
 * single global cap would let one trainer's conversation consume the other's —
 * the member would be silenced with a coach they are paying for because they had
 * been chatty with a different one.
 *
 * ## Only the member's own messages count against it
 *
 * The quota counts `user_to_trainer` messages in a **rolling seven days**.
 * Trainer replies are never counted: a chatty coach must not be able to exhaust
 * their client's allowance, which is what counting both directions would do.
 * Rolling rather than calendar, so the limit does not reset at a boundary the
 * member cannot see and cannot plan around.
 *
 * ## No typing indicator
 *
 * The prototype rendered one permanently whenever the trainer was "online" — it
 * was decorative, tied to nothing. Real typing state costs a write per
 * keystroke-burst for cosmetic value, and [02](plans/02-api-contract.md) recommends
 * dropping it at launch. Dropped.
 *
 * ## Counters are denormalised and maintained on write
 *
 * `last_message_at`, `last_message_preview` and the two unread counters live on
 * the thread. The conversation list is the most-read screen in the app and
 * deriving those per row would be three correlated subqueries against
 * `fc_messages` on every poll.
 */
final class MessageService
{
    /** How far back the rolling quota window reaches. */
    private const QUOTA_DAYS = 7;

    /** Messages returned per page when the client does not say. */
    private const PAGE_SIZE = 30;

    /**
     * `GET /messages/threads` — the conversation list for whoever is asking.
     *
     * Works for both sides: a member sees their trainers, a trainer sees their
     * clients. Which one you are decides the counterpart, the unread column and
     * whether a quota applies at all.
     *
     * @return array<string,mixed>
     */
    public function threads(int $accountId): array
    {
        global $wpdb;

        $fcUserId  = Guard::userId($accountId);
        $trainerId = Guard::trainerId($accountId);

        if (null === $fcUserId && null === $trainerId) {
            return ['items' => [], 'unread_total' => 0];
        }

        $rows = null !== $fcUserId
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT th.*, t.display_name AS counterpart_name, t.avatar_url AS counterpart_avatar,
                        t.id AS counterpart_trainer_id
                   FROM {$wpdb->prefix}fc_message_threads th
                   JOIN {$wpdb->prefix}fc_trainers t ON t.id = th.trainer_id
                  WHERE th.user_id = %d
                  ORDER BY th.last_message_at DESC, th.id DESC",
                $fcUserId
            ), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT th.*, u.display_name AS counterpart_name, u.avatar_url AS counterpart_avatar,
                        th.trainer_id AS counterpart_trainer_id
                   FROM {$wpdb->prefix}fc_message_threads th
                   JOIN {$wpdb->prefix}fc_users u ON u.id = th.user_id
                  WHERE th.trainer_id = %d
                  ORDER BY th.last_message_at DESC, th.id DESC",
                $trainerId
            ), ARRAY_A);

        $items  = [];
        $unread = 0;

        foreach ($rows ?: [] as $row) {
            $mine = null !== $fcUserId
                ? (int) $row['user_unread_count']
                : (int) $row['trainer_unread_count'];

            $unread += $mine;

            $items[] = [
                'id'                 => (int) $row['id'],
                'trainer_id'         => (int) $row['trainer_id'],
                'user_id'            => (int) $row['user_id'],
                'counterpart_name'   => $row['counterpart_name'],
                'counterpart_avatar' => $row['counterpart_avatar'],
                'preview'            => $row['last_message_preview'],
                'last_message_at'    => $this->iso($row['last_message_at']),
                'unread_count'       => $mine,
                'status'             => $row['status'],
                // Only the member has a send allowance; a trainer replying to
                // their own client is not spending anybody's plan.
                'quota' => null === $fcUserId
                    ? null
                    : $this->quota((int) $row['id'], $fcUserId, (int) $row['trainer_id']),
            ];
        }

        return ['items' => $items, 'unread_total' => $unread];
    }

    /**
     * `GET /messages/threads/{id}?before=&limit=` — reverse-paginated history.
     *
     * Paged **backwards from newest**, because that is the direction a
     * conversation is read: opening a thread shows the latest, and scrolling up
     * asks for what came before a known id. Offset paging would renumber every
     * page as new messages arrive mid-scroll.
     *
     * @return array<string,mixed>
     */
    public function thread(int $accountId, int $threadId, ?int $before = null, ?int $limit = null): array
    {
        global $wpdb;

        $row   = $this->threadRow($accountId, $threadId);
        $limit = max(1, min(100, $limit ?? self::PAGE_SIZE));

        $messages = null !== $before && $before > 0
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_messages
                  WHERE thread_id = %d AND id < %d
                  ORDER BY id DESC LIMIT %d",
                $threadId,
                $before,
                $limit
            ), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_messages
                  WHERE thread_id = %d
                  ORDER BY id DESC LIMIT %d",
                $threadId,
                $limit
            ), ARRAY_A);

        $messages = $messages ?: [];

        // Oldest-first for rendering; the query had to be newest-first to page.
        $ordered = array_reverse($messages);

        return [
            'thread'   => $this->presentThread($row, $accountId),
            'messages' => array_map(fn(array $m): array => $this->present($m), $ordered),
            // The client asks for more only when there might be more.
            'has_more' => count($messages) === $limit,
            'oldest_id' => $ordered === [] ? null : (int) $ordered[0]['id'],
        ];
    }

    /**
     * `POST /messages/threads/{id}` — send.
     *
     * @param array<int,string> $attachments Upload URLs from POST /messages/attachments.
     * @return array<string,mixed>
     */
    public function send(int $accountId, int $threadId, string $body, array $attachments = []): array
    {
        global $wpdb;

        $row  = $this->threadRow($accountId, $threadId);
        $body = trim(sanitize_textarea_field($body));

        if ('' === $body && [] === $attachments) {
            throw new DomainException(
                'fc_message_empty',
                __('Write something first.', 'fitnessclub'),
                400
            );
        }

        $fcUserId  = Guard::userId($accountId);
        $isMember  = null !== $fcUserId && (int) $row['user_id'] === $fcUserId;
        $direction = $isMember ? 'user_to_trainer' : 'trainer_to_user';

        if ($isMember) {
            // Both member-side gates live here, *after* `threadRow()` has
            // established that the caller is on the thread. Checking the plan
            // first would answer 403 "not in your plan" to a stranger probing a
            // thread id — telling them the conversation exists, which is the
            // disclosure the 404 is there to prevent.
            $this->assertCanMessage($fcUserId);
            $this->assertWithinQuota($threadId, $fcUserId, (int) $row['trainer_id']);
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_messages', [
            'thread_id'         => $threadId,
            'sender_account_id' => $accountId,
            'direction'         => $direction,
            'message'           => '' === $body ? null : $body,
            'attachments'       => [] === $attachments ? null : wp_json_encode(array_values($attachments)),
            'is_read'           => 0,
            'created_at'        => $now,
        ]);

        $messageId = (int) $wpdb->insert_id;

        // The counter that moves is the *recipient's*. Incrementing in SQL
        // rather than reading-then-writing keeps two messages in flight from
        // both reading the same value and writing the same increment.
        $recipientColumn = $isMember ? 'trainer_unread_count' : 'user_unread_count';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is a literal chosen above.
        $sql = "UPDATE {$wpdb->prefix}fc_message_threads
                   SET {$recipientColumn} = {$recipientColumn} + 1,
                       last_message_at = %s,
                       last_message_preview = %s,
                       updated_at = %s
                 WHERE id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $wpdb->query($wpdb->prepare(
            $sql,
            $now,
            mb_substr('' === $body ? __('Sent an image', 'fitnessclub') : $body, 0, 255),
            $now,
            $threadId
        ));

        $this->notifyRecipient($row, $accountId, $isMember, $body);

        return [
            'message' => $this->present((array) $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_messages WHERE id = %d",
                $messageId
            ), ARRAY_A)),
            'quota' => $isMember
                ? $this->quota($threadId, (int) $row['user_id'], (int) $row['trainer_id'])
                : null,
        ];
    }

    /**
     * `POST /messages/threads/{id}/read`.
     *
     * @return array<string,mixed>
     */
    public function markRead(int $accountId, int $threadId): array
    {
        global $wpdb;

        $row      = $this->threadRow($accountId, $threadId);
        $fcUserId = Guard::userId($accountId);
        $isMember = null !== $fcUserId && (int) $row['user_id'] === $fcUserId;

        // Mark the messages the *other* side sent — you cannot read your own
        // into being read.
        $direction = $isMember ? 'trainer_to_user' : 'user_to_trainer';
        $column    = $isMember ? 'user_unread_count' : 'trainer_unread_count';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_messages
                SET is_read = 1, read_at = UTC_TIMESTAMP()
              WHERE thread_id = %d AND direction = %s AND is_read = 0",
            $threadId,
            $direction
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is a literal chosen above.
        $reset = "UPDATE {$wpdb->prefix}fc_message_threads SET {$column} = 0 WHERE id = %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $wpdb->query($wpdb->prepare($reset, $threadId));

        return ['ok' => true, 'unread_total' => $this->unreadCount($accountId)];
    }

    /**
     * `GET /messages/unread-count` — the nav badge.
     */
    public function unreadCount(int $accountId): int
    {
        global $wpdb;

        $total     = 0;
        $fcUserId  = Guard::userId($accountId);
        $trainerId = Guard::trainerId($accountId);

        if (null !== $fcUserId) {
            $total += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(user_unread_count), 0)
                   FROM {$wpdb->prefix}fc_message_threads WHERE user_id = %d",
                $fcUserId
            ));
        }

        if (null !== $trainerId) {
            $total += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_unread_count), 0)
                   FROM {$wpdb->prefix}fc_message_threads WHERE trainer_id = %d",
                $trainerId
            ));
        }

        return $total;
    }

    /**
     * `GET /messages/poll?since=` — what has arrived since a known message id.
     *
     * Polling at 15 s is the launch answer to real-time (assumption 5); this is
     * the endpoint that makes it cheap. It returns **ids, not whole threads**,
     * so an idle poll is one indexed read and an empty array rather than the
     * conversation list rebuilt every fifteen seconds.
     *
     * @return array<string,mixed>
     */
    public function poll(int $accountId, ?int $since = null): array
    {
        global $wpdb;

        $fcUserId  = Guard::userId($accountId);
        $trainerId = Guard::trainerId($accountId);

        if (null === $fcUserId && null === $trainerId) {
            return ['messages' => [], 'latest_id' => $since ?? 0, 'unread_total' => 0];
        }

        $scope = null !== $fcUserId
            ? $wpdb->prepare('th.user_id = %d', $fcUserId)
            : $wpdb->prepare('th.trainer_id = %d', $trainerId);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $scope is prepared above.
        $sql = "SELECT m.id, m.thread_id, m.direction, m.message, m.created_at
                  FROM {$wpdb->prefix}fc_messages m
                  JOIN {$wpdb->prefix}fc_message_threads th ON th.id = m.thread_id
                 WHERE {$scope} AND m.id > %d AND m.sender_account_id <> %d
                 ORDER BY m.id ASC LIMIT 50";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, (int) ($since ?? 0), $accountId), ARRAY_A) ?: [];

        $latest = (int) ($since ?? 0);

        foreach ($rows as $row) {
            $latest = max($latest, (int) $row['id']);
        }

        return [
            'messages' => array_map(static fn(array $row): array => [
                'id'        => (int) $row['id'],
                'thread_id' => (int) $row['thread_id'],
            ], $rows),
            'latest_id'    => $latest,
            'unread_total' => $this->unreadCount($accountId),
        ];
    }

    // ------------------------------------------------------------------ quota

    /**
     * The member's allowance on one thread.
     *
     * `limit: null` means unlimited and must not be coerced to 0 — that would
     * read as "none allowed" and silence the member on their best plan.
     *
     * @return array{limit:?int,used:int,remaining:?int,resets_at:?string}
     */
    public function quota(int $threadId, int $fcUserId, int $trainerId): array
    {
        $entitlements = (new EntitlementService())->forUser($fcUserId);
        $byTrainer    = $entitlements['message_quota_by_trainer'] ?? [];

        $limit = array_key_exists((string) $trainerId, $byTrainer)
            ? $byTrainer[(string) $trainerId]
            // No subscription with this trainer: the free-tier floor applies,
            // which is 0 — messaging is a paid feature.
            : ($entitlements['max_messages_per_week'] ?? 0);

        $used = $this->used($threadId);

        return [
            'limit'     => null === $limit ? null : (int) $limit,
            'used'      => $used,
            'remaining' => null === $limit ? null : max(0, (int) $limit - $used),
            'resets_at' => null === $limit ? null : $this->resetsAt($threadId),
        ];
    }

    /**
     * Messages the member has sent on this thread inside the rolling window.
     */
    private function used(int $threadId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_messages
              WHERE thread_id = %d AND direction = 'user_to_trainer'
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $threadId,
            self::QUOTA_DAYS
        ));
    }

    /**
     * When the window frees a slot: seven days after the **oldest** send still
     * inside it. That is the moment one drops out, which is the honest answer to
     * "when can I write again" — not the end of a calendar week.
     */
    private function resetsAt(int $threadId): ?string
    {
        global $wpdb;

        $oldest = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(created_at) FROM {$wpdb->prefix}fc_messages
              WHERE thread_id = %d AND direction = 'user_to_trainer'
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
            $threadId,
            self::QUOTA_DAYS
        ));

        if (null === $oldest) {
            return null;
        }

        $timestamp = strtotime((string) $oldest . ' UTC');

        return false === $timestamp
            ? null
            : gmdate('c', $timestamp + (self::QUOTA_DAYS * DAY_IN_SECONDS));
    }

    /**
     * Messaging is a paid feature; the free tier does not grant it.
     *
     * Thrown with the contract's `fc_feature_unavailable` code and the feature
     * name attached, so the client can offer an upgrade rather than showing the
     * quota message — "you have used this week's messages" is wrong and
     * confusing for somebody who never had any.
     */
    private function assertCanMessage(int $fcUserId): void
    {
        if ((new EntitlementService())->can($fcUserId, 'can_message')) {
            return;
        }

        throw new DomainException(
            'fc_feature_unavailable',
            __('Messaging is not included in your plan.', 'fitnessclub'),
            403,
            ['required_feature' => 'can_message']
        );
    }

    private function assertWithinQuota(int $threadId, int $fcUserId, int $trainerId): void
    {
        $quota = $this->quota($threadId, $fcUserId, $trainerId);

        if (null === $quota['limit']) {
            return;
        }

        if ($quota['used'] < $quota['limit']) {
            return;
        }

        throw new DomainException(
            'fc_quota_exceeded',
            0 === $quota['limit']
                ? __('Messaging is not included in your plan.', 'fitnessclub')
                : __('You have used this week\'s messages for this trainer.', 'fitnessclub'),
            429,
            [
                'limit'     => $quota['limit'],
                'used'      => $quota['used'],
                'resets_at' => $quota['resets_at'],
            ]
        );
    }

    // -------------------------------------------------------------- internals

    /**
     * Raise a notification for whoever did not send it.
     *
     * Goes through NotificationService, so a member who muted the `message`
     * category gets no badge — the preference is honoured at the write (W2.4).
     *
     * @param array<string,mixed> $thread
     */
    private function notifyRecipient(array $thread, int $senderAccountId, bool $isMember, string $body): void
    {
        global $wpdb;

        $recipientAccountId = (int) ($isMember
            ? $wpdb->get_var($wpdb->prepare(
                "SELECT account_id FROM {$wpdb->prefix}fc_trainers WHERE id = %d",
                (int) $thread['trainer_id']
            ))
            : $wpdb->get_var($wpdb->prepare(
                "SELECT account_id FROM {$wpdb->prefix}fc_users WHERE id = %d",
                (int) $thread['user_id']
            )));

        if ($recipientAccountId <= 0 || $recipientAccountId === $senderAccountId) {
            return;
        }

        $senderName = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}fc_accounts WHERE id = %d",
            $senderAccountId
        ));

        (new NotificationService())->notify(
            $recipientAccountId,
            'message',
            sprintf(
                /* translators: %s: the sender's name */
                __('%s sent you a message', 'fitnessclub'),
                '' === $senderName ? __('Someone', 'fitnessclub') : $senderName
            ),
            mb_substr($body, 0, 140),
            '/messages',
            'bell',
            null,
            ['thread_id' => (int) $thread['id']]
        );
    }

    /**
     * Load a thread the caller is a party to.
     *
     * A thread the caller is not on is a 404, never a 403 — a 403 confirms the
     * conversation exists, which for private messages is itself a disclosure.
     *
     * @return array<string,mixed>
     */
    private function threadRow(int $accountId, int $threadId): array
    {
        global $wpdb;

        if (!Guard::participatesInThread($accountId, $threadId)) {
            throw new DomainException(
                'fc_thread_not_found',
                __('That conversation does not exist.', 'fitnessclub'),
                404
            );
        }

        return (array) $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_message_threads WHERE id = %d",
            $threadId
        ), ARRAY_A);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentThread(array $row, int $accountId): array
    {
        global $wpdb;

        $fcUserId = Guard::userId($accountId);
        $isMember = null !== $fcUserId && (int) $row['user_id'] === $fcUserId;

        $counterpart = $isMember
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, avatar_url FROM {$wpdb->prefix}fc_trainers WHERE id = %d",
                (int) $row['trainer_id']
            ), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, avatar_url FROM {$wpdb->prefix}fc_users WHERE id = %d",
                (int) $row['user_id']
            ), ARRAY_A);

        return [
            'id'                 => (int) $row['id'],
            'trainer_id'         => (int) $row['trainer_id'],
            'user_id'            => (int) $row['user_id'],
            'counterpart_name'   => $counterpart['display_name'] ?? null,
            'counterpart_avatar' => $counterpart['avatar_url'] ?? null,
            'status'             => $row['status'],
            'unread_count'       => $isMember
                ? (int) $row['user_unread_count']
                : (int) $row['trainer_unread_count'],
            'quota' => $isMember
                ? $this->quota((int) $row['id'], $fcUserId, (int) $row['trainer_id'])
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $attachments = json_decode((string) ($row['attachments'] ?? ''), true);

        return [
            'id'                => (int) $row['id'],
            'thread_id'         => (int) $row['thread_id'],
            'sender_account_id' => (int) $row['sender_account_id'],
            'direction'         => $row['direction'],
            'message'           => $row['message'],
            'attachments'       => is_array($attachments) ? $attachments : [],
            'is_read'           => (bool) $row['is_read'],
            'created_at'        => $this->iso($row['created_at']),
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
