<?php

namespace FitnessClub\Services;

use FitnessClub\Auth\Auth;
use FitnessClub\Support\DomainException;
use FitnessClub\Support\Guard;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Support tickets, replies and internal notes (spec §13, W3.3).
 *
 * ## Internal notes are the thing to get right
 *
 * `fc_ticket_replies.is_internal_note` marks staff-only commentary — "this is
 * the third refund request from this account", "escalate to the gym owner". It
 * is written on the same ticket the member reads, and the **only** thing keeping
 * it from them is a WHERE clause. So the filtering lives here, in one method
 * every read path goes through, rather than in each caller: a leak is not a
 * cosmetic bug, it is showing a customer what staff said about them.
 *
 * ## Everything is keyed on the account, not the member profile
 *
 * A trainer raises tickets too, and has no `fc_users` row. Ownership is
 * `user_account_id`, and staff reach every ticket via `fc_handle_tickets`.
 *
 * ## First response is recorded, not computed
 *
 * `first_response_at` is stamped by the first staff reply and never moved.
 * Deriving it later from the replies table would mean deciding, at read time,
 * which reply counted — and internal notes are not responses.
 */
final class TicketService
{
    /** Statuses a ticket can be worked through. */
    public const STATUSES = ['open', 'in_progress', 'waiting_user', 'resolved', 'closed'];

    /**
     * Statuses that mean nobody is waiting on staff.
     *
     * `resolved` and `closed` are **not** interchangeable, and conflating them
     * is easy: `resolved` is staff's opinion that the problem is fixed, and the
     * member gets to disagree by replying — which reopens it. `closed` is the
     * end, and a reply has to become a new ticket.
     */
    private const SETTLED = ['resolved', 'closed'];

    /** The one status that refuses a member's reply outright. */
    private const FINAL = ['closed'];

    /**
     * `GET /support/tickets` — the caller's own, or the whole queue for staff.
     *
     * @param array<string,mixed> $filters status, priority, assigned_to, q
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function index(int $accountId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $isStaff = $this->isStaff($accountId);
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];

        if (!$isStaff) {
            // A member sees their own and nothing else. This is the whole
            // access model for the list.
            $where[]  = 't.user_account_id = %d';
            $params[] = $accountId;
        }

        foreach (['status' => 't.status', 'priority' => 't.priority', 'category' => 't.category'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]  = $column . ' = %s';
                $params[] = (string) $filters[$key];
            }
        }

        if ($isStaff && !empty($filters['assigned_to'])) {
            $where[]  = 't.assigned_to_account_id = %d';
            $params[] = (int) $filters['assigned_to'];
        }

        if (!empty($filters['q'])) {
            $where[]  = '(t.subject LIKE %s OR t.message LIKE %s)';
            $like     = '%' . $wpdb->esc_like((string) $filters['q']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is placeholders only.
        $countSql = "SELECT COUNT(*) FROM {$wpdb->prefix}fc_tickets t WHERE {$clause}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $total = (int) $wpdb->get_var($params === [] ? $countSql : $wpdb->prepare($countSql, ...$params));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is placeholders only.
        $sql = "SELECT t.*, a.display_name AS author_name, a.email AS author_email,
                       s.display_name AS assignee_name,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fc_ticket_replies r
                         WHERE r.ticket_id = t.id AND r.is_internal_note = 0) AS reply_count
                  FROM {$wpdb->prefix}fc_tickets t
                  JOIN {$wpdb->prefix}fc_accounts a ON a.id = t.user_account_id
                  LEFT JOIN {$wpdb->prefix}fc_accounts s ON s.id = t.assigned_to_account_id
                 WHERE {$clause}
                 ORDER BY FIELD(t.status, 'open', 'in_progress', 'waiting_user', 'resolved', 'closed'),
                          FIELD(t.priority, 'urgent', 'high', 'medium', 'low'),
                          t.created_at DESC
                 LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        ) ?: [];

        return [
            'items'    => array_map(fn(array $row): array => $this->present($row, $isStaff), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'is_staff' => $isStaff,
        ];
    }

    /**
     * `GET /support/tickets/{id}` — the ticket with its replies.
     *
     * @return array<string,mixed>
     */
    public function show(int $accountId, int $ticketId): array
    {
        $isStaff = $this->isStaff($accountId);
        $row     = $this->readable($accountId, $ticketId, $isStaff);

        $ticket             = $this->present($row, $isStaff);
        $ticket['replies']  = $this->replies($ticketId, $isStaff);
        $ticket['message']  = $row['message'];

        return $ticket;
    }

    /**
     * `POST /support/tickets`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function create(int $accountId, array $payload): array
    {
        global $wpdb;

        $subject = trim(sanitize_text_field((string) ($payload['subject'] ?? '')));
        $message = trim(sanitize_textarea_field((string) ($payload['message'] ?? '')));

        if ('' === $subject || '' === $message) {
            throw new DomainException(
                'fc_ticket_incomplete',
                __('A ticket needs a subject and a description.', 'fitnessclub'),
                400
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_tickets', [
            'user_account_id' => $accountId,
            'subject'         => mb_substr($subject, 0, 255),
            'message'         => $message,
            'category'        => $this->oneOf($payload['category'] ?? null, 'ticket_category', 'general'),
            // Priority is the member's *request*, not a promise. Staff reset it
            // from the queue; letting it through unfiltered would mean every
            // ticket arrives urgent.
            'priority'        => $this->oneOf($payload['priority'] ?? null, 'ticket_priority', 'medium'),
            'status'          => 'open',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $ticketId = (int) $wpdb->insert_id;

        $this->notifyStaff($ticketId, $subject);

        return $this->show($accountId, $ticketId);
    }

    /**
     * `POST /support/tickets/{id}/replies`.
     *
     * @return array<string,mixed>
     */
    public function reply(int $accountId, int $ticketId, string $message, bool $internal = false): array
    {
        global $wpdb;

        $isStaff = $this->isStaff($accountId);
        $row     = $this->readable($accountId, $ticketId, $isStaff);

        $message = trim(sanitize_textarea_field($message));

        if ('' === $message) {
            throw new DomainException(
                'fc_reply_empty',
                __('Write something first.', 'fitnessclub'),
                400
            );
        }

        // Only staff may write an internal note. A member's request for one is
        // dropped rather than refused — the field simply is not theirs.
        $internal = $internal && $isStaff;

        if (in_array($row['status'], self::FINAL, true) && !$isStaff) {
            throw new DomainException(
                'fc_ticket_closed',
                __('This ticket is closed. Open a new one and we will pick it up.', 'fitnessclub'),
                409
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_ticket_replies', [
            'ticket_id'         => $ticketId,
            'author_account_id' => $accountId,
            'author_role'       => $isStaff ? $this->staffRole($accountId) : 'user',
            'message'           => $message,
            'is_internal_note'  => $internal ? 1 : 0,
            'created_at'        => $now,
        ]);

        $fields = ['updated_at' => $now];

        // An internal note is not a response and does not move the ticket: it is
        // staff talking to each other, and stamping first_response_at or
        // flipping the status on one would tell the member something happened
        // when nothing they can see did.
        if (!$internal) {
            if ($isStaff) {
                if (empty($row['first_response_at'])) {
                    $fields['first_response_at'] = $now;
                }

                // The ball is now with the member.
                $fields['status'] = 'waiting_user';
            } else {
                // A member replying to a resolved ticket reopens it: staff
                // thought it was fixed and the member is saying otherwise.
                $fields['status'] = in_array($row['status'], self::SETTLED, true)
                    ? 'open'
                    : 'in_progress';
            }
        }

        $wpdb->update($wpdb->prefix . 'fc_tickets', $fields, ['id' => $ticketId]);

        if (!$internal) {
            $this->notifyCounterpart($row, $accountId, $isStaff);
        }

        return $this->show($accountId, $ticketId);
    }

    /**
     * `PATCH /support/tickets/{id}`.
     *
     * A member may close and reopen their own ticket; everything else —
     * assignment, priority, arbitrary status — is staff-only. Splitting on the
     * caller rather than exposing two endpoints keeps the client from having to
     * know which it is.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function update(int $accountId, int $ticketId, array $payload): array
    {
        global $wpdb;

        $isStaff = $this->isStaff($accountId);
        $row     = $this->readable($accountId, $ticketId, $isStaff);

        $fields = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if ($isStaff) {
            if (array_key_exists('status', $payload)) {
                $fields['status'] = $this->oneOf($payload['status'], 'ticket_status', 'open');

                if (in_array($fields['status'], self::SETTLED, true) && empty($row['resolved_at'])) {
                    $fields['resolved_at'] = gmdate('Y-m-d H:i:s');
                }
            }

            if (array_key_exists('priority', $payload)) {
                $fields['priority'] = $this->oneOf($payload['priority'], 'ticket_priority', 'medium');
            }

            if (array_key_exists('assigned_to_account_id', $payload)) {
                $assignee = (int) $payload['assigned_to_account_id'];

                $fields['assigned_to_account_id'] = $assignee > 0 ? $assignee : null;

                // Picking a ticket up is itself progress worth showing in the
                // queue, so an untouched open ticket does not sit there looking
                // unclaimed once somebody has claimed it.
                if ($assignee > 0 && 'open' === $row['status']) {
                    $fields['status'] = 'in_progress';
                }
            }
        } else {
            // The member's whole vocabulary: close it, or reopen it.
            $requested = (string) ($payload['status'] ?? '');

            if (!in_array($requested, ['closed', 'open'], true)) {
                throw new DomainException(
                    'fc_not_allowed',
                    __('You can close or reopen a ticket, nothing else.', 'fitnessclub'),
                    403
                );
            }

            $fields['status'] = $requested;

            if ('closed' === $requested && empty($row['resolved_at'])) {
                $fields['resolved_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $wpdb->update($wpdb->prefix . 'fc_tickets', $fields, ['id' => $ticketId]);

        return $this->show($accountId, $ticketId);
    }

    /**
     * `GET /support/faq` — from settings, not from hardcoded JavaScript.
     *
     * The prototype shipped five answers inline in the bundle, which means an
     * administrator cannot correct a wrong one without a rebuild. Stored in the
     * options model instead; the bundled defaults are the same five, so an
     * install that has never touched them still has an FAQ.
     *
     * @return array<int,array{q:string,a:string}>
     */
    public function faq(): array
    {
        $stored = FitnessClub()->options->get('support_faq');

        // Stored as a JSON *string*: the options model is a single blob and
        // reading a nested array back out of it is not something it promises.
        // A string round-trips through `update_option` unambiguously.
        if (is_string($stored) && '' !== trim($stored)) {
            $decoded = json_decode($stored, true);
            $stored  = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($stored) || [] === $stored) {
            return $this->defaultFaq();
        }

        $items = [];

        foreach ($stored as $entry) {
            if (!is_array($entry) || empty($entry['q']) || empty($entry['a'])) {
                continue;
            }

            $items[] = [
                'q' => sanitize_text_field((string) $entry['q']),
                'a' => sanitize_textarea_field((string) $entry['a']),
            ];
        }

        return [] === $items ? $this->defaultFaq() : $items;
    }

    // -------------------------------------------------------------- internals

    /**
     * @return array<int,array{q:string,a:string}>
     */
    private function defaultFaq(): array
    {
        return [
            [
                'q' => __('How do I track my workouts?', 'fitnessclub'),
                'a' => __('Open Workouts, pick a programme and press Start. The player walks you through each exercise with timers and set logging.', 'fitnessclub'),
            ],
            [
                'q' => __('Can I change my meal plan?', 'fitnessclub'),
                'a' => __('Yes. Use Add meal on the Nutrition screen to log anything you like. Your trainer can also build a plan for you.', 'fitnessclub'),
            ],
            [
                'q' => __('How does the streak work?', 'fitnessclub'),
                'a' => __('Complete at least one workout in a day and the streak grows. Miss a day and it resets.', 'fitnessclub'),
            ],
            [
                'q' => __('How do I cancel my subscription?', 'fitnessclub'),
                'a' => __('Open Plan and choose Cancel. You keep everything until the period you have paid for ends.', 'fitnessclub'),
            ],
            [
                'q' => __('Who can see my health data?', 'fitnessclub'),
                'a' => __('You, any trainer you are working with, and administrators of this site. Entries made on your behalf by staff are labelled as such.', 'fitnessclub'),
            ],
        ];
    }

    /**
     * Replies, with internal notes removed for anybody who is not staff.
     *
     * The single choke point for the one rule that matters here.
     *
     * @return array<int,array<string,mixed>>
     */
    private function replies(int $ticketId, bool $isStaff): array
    {
        global $wpdb;

        $rows = $isStaff
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, a.display_name AS author_name
                   FROM {$wpdb->prefix}fc_ticket_replies r
                   LEFT JOIN {$wpdb->prefix}fc_accounts a ON a.id = r.author_account_id
                  WHERE r.ticket_id = %d
                  ORDER BY r.created_at ASC, r.id ASC",
                $ticketId
            ), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT r.*, a.display_name AS author_name
                   FROM {$wpdb->prefix}fc_ticket_replies r
                   LEFT JOIN {$wpdb->prefix}fc_accounts a ON a.id = r.author_account_id
                  WHERE r.ticket_id = %d AND r.is_internal_note = 0
                  ORDER BY r.created_at ASC, r.id ASC",
                $ticketId
            ), ARRAY_A);

        return array_map(static function (array $row): array {
            $attachments = json_decode((string) ($row['attachments'] ?? ''), true);

            return [
                'id'          => (int) $row['id'],
                'author_name' => $row['author_name'],
                'author_role' => $row['author_role'],
                'message'     => $row['message'],
                'attachments' => is_array($attachments) ? $attachments : [],
                'is_internal_note' => (bool) $row['is_internal_note'],
                'created_at'  => gmdate('c', (int) strtotime((string) $row['created_at'] . ' UTC')),
            ];
        }, $rows ?: []);
    }

    /**
     * A ticket the caller may read, or a 404.
     *
     * @return array<string,mixed>
     */
    private function readable(int $accountId, int $ticketId, bool $isStaff): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, a.display_name AS author_name, a.email AS author_email,
                    s.display_name AS assignee_name
               FROM {$wpdb->prefix}fc_tickets t
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = t.user_account_id
               LEFT JOIN {$wpdb->prefix}fc_accounts s ON s.id = t.assigned_to_account_id
              WHERE t.id = %d",
            $ticketId
        ), ARRAY_A);

        if (!is_array($row) || (!$isStaff && (int) $row['user_account_id'] !== $accountId)) {
            // Somebody else's ticket is a 404, not a 403 — a support queue
            // should not confirm which ticket ids exist.
            throw new DomainException(
                'fc_ticket_not_found',
                __('That ticket does not exist.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, bool $isStaff): array
    {
        $ticket = [
            'id'          => (int) $row['id'],
            'subject'     => $row['subject'],
            'category'    => $row['category'],
            'priority'    => $row['priority'],
            'status'      => $row['status'],
            'author_name' => $row['author_name'] ?? null,
            'reply_count' => isset($row['reply_count']) ? (int) $row['reply_count'] : null,
            'created_at'  => gmdate('c', (int) strtotime((string) $row['created_at'] . ' UTC')),
            'updated_at'  => gmdate('c', (int) strtotime((string) $row['updated_at'] . ' UTC')),
            'resolved_at' => empty($row['resolved_at'])
                ? null
                : gmdate('c', (int) strtotime((string) $row['resolved_at'] . ' UTC')),
        ];

        // Who it is assigned to and how fast it was answered are operational
        // facts about the queue. A member seeing "assigned to Dave, first
        // response 4 h" learns nothing useful and rather a lot about staffing.
        if ($isStaff) {
            $ticket['author_email']  = $row['author_email'] ?? null;
            $ticket['assigned_to_account_id'] = null === $row['assigned_to_account_id']
                ? null
                : (int) $row['assigned_to_account_id'];
            $ticket['assignee_name'] = $row['assignee_name'] ?? null;
            $ticket['first_response_at'] = empty($row['first_response_at'])
                ? null
                : gmdate('c', (int) strtotime((string) $row['first_response_at'] . ' UTC'));
        }

        return $ticket;
    }

    private function isStaff(int $accountId): bool
    {
        $account = Auth::account();

        return null !== $account
            && $account->id === $accountId
            && $account->can('fc_handle_tickets');
    }

    private function staffRole(int $accountId): string
    {
        $trainerId = Guard::trainerId($accountId);

        return null === $trainerId ? 'admin' : 'trainer';
    }

    /**
     * Coerce to a configured enum, falling back rather than failing.
     *
     * These arrive from a `<select>`; a value outside the list is a client bug
     * or a probe, and neither deserves a 400 that blocks a genuine support
     * request.
     */
    private function oneOf($value, string $enum, string $fallback): string
    {
        $allowed = (array) FitnessClub()->config('fitnessclub.enums.' . $enum, []);
        $value   = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function notifyStaff(int $ticketId, string $subject): void
    {
        global $wpdb;

        // Every account that can work the queue. A support ticket nobody is told
        // about is a support ticket nobody answers.
        $staff = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}fc_accounts WHERE role = 'admin' AND status = 'active'"
        );

        $notifications = new NotificationService();

        foreach ($staff ?: [] as $accountId) {
            $notifications->notify(
                (int) $accountId,
                'support',
                __('New support ticket', 'fitnessclub'),
                $subject,
                '/support/' . $ticketId
            );
        }
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function notifyCounterpart(array $ticket, int $senderAccountId, bool $isStaff): void
    {
        if (!$isStaff) {
            $this->notifyStaff((int) $ticket['id'], (string) $ticket['subject']);

            return;
        }

        $recipient = (int) $ticket['user_account_id'];

        if ($recipient === $senderAccountId) {
            return;
        }

        (new NotificationService())->notify(
            $recipient,
            'support',
            __('Reply to your support ticket', 'fitnessclub'),
            (string) $ticket['subject'],
            '/support/' . (int) $ticket['id']
        );
    }
}
