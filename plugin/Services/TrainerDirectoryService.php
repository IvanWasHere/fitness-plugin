<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The trainer directory and the request flow (Q4/Q14, W3.5).
 *
 * ## This is the only place one member reads another account's record
 *
 * Every other endpoint in the plugin answers about the caller or about people
 * the caller coaches. The directory is the exception — a member browses
 * *trainers* — so it returns a **strict public projection** built by
 * `present()`: display name, avatar, bio, specialization, rating, capacity,
 * accepting status. Never email, never phone, never a client list.
 * ([02](plans/02-api-contract.md#trainer-directory--requests-trainers-q4).)
 *
 * The projection is one method rather than a column list repeated per query,
 * because a directory that leaks a phone number leaks it from whichever query
 * somebody forgot to update.
 *
 * ## Eligibility is the server's answer, not the client's inference
 *
 * `GET /trainers` carries an `eligibility` block, so the three call-to-action
 * states — request, `subscription_required`, `limit_reached` — are decided
 * once, here, next to the rule that enforces them. A client working it out from
 * a subscription object would eventually disagree with the endpoint that
 * refuses the request, and the member would be told they can do something the
 * server then declines.
 *
 * **The directory is shown to members who cannot request.** Hiding it removes
 * the clearest reason to upgrade in the product; the button is disabled and the
 * reason stated instead.
 *
 * ## Pending requests hold a slot
 *
 * `EntitlementService::trainersUsed()` counts `active` **and** `pending`
 * (Q14). Without that a member on a one-trainer plan queues five requests and
 * keeps whichever lands first — unfair to the four trainers who read and
 * answered, and a way around the cap.
 */
final class TrainerDirectoryService
{
    /** Outstanding requests a member may hold at once. */
    private const MAX_PENDING = 3;

    /** Requests a member may send in a rolling week. */
    private const MAX_PER_WEEK = 10;

    /**
     * `GET /trainers` — the directory, plus the caller's own eligibility.
     *
     * @return array<string,mixed>
     */
    public function directory(int $fcUserId, string $search = '', string $specialization = ''): array
    {
        global $wpdb;

        $where  = ["t.status = 'active'", 't.accepting_clients = 1'];
        $params = [];

        if ('' !== trim($search)) {
            $where[]  = '(t.display_name LIKE %s OR t.specialization LIKE %s OR t.bio LIKE %s)';
            $like     = '%' . $wpdb->esc_like(trim($search)) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ('' !== trim($specialization)) {
            $where[]  = 't.specialization = %s';
            $params[] = trim($specialization);
        }

        $clause = implode(' AND ', $where);

        // **Both NULL and 0 mean "no limit set"**, matching
        // `TrainerService::capacity()`. The column is nullable and defaults to
        // NULL, so testing only `= 0` would evaluate to NULL for every trainer
        // who has never touched their profile — and a NULL `HAVING` drops the
        // row, hiding the entire directory on a fresh install.
        //
        // A HAVING rather than a WHERE because the comparison is against the
        // counted column, which does not exist until the select has run.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is built from literals above; values are placeholders.
        $sql = "SELECT t.*,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers ut
                         WHERE ut.trainer_id = t.id AND ut.status = 'active') AS client_count
                  FROM {$wpdb->prefix}fc_trainers t
                 WHERE {$clause}
                HAVING t.max_clients IS NULL OR t.max_clients = 0 OR client_count < t.max_clients
                 ORDER BY t.rating DESC, t.rating_count DESC, t.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line when there are values to bind.
        $rows = [] === $params
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $states = $this->linkStates($fcUserId);

        return [
            'items' => array_map(
                fn(array $row): array => $this->present($row, $states),
                $rows ?: []
            ),
            'specializations' => $this->specializations(),
            'eligibility'     => $this->eligibility($fcUserId),
        ];
    }

    /**
     * `GET /trainers/{id}` — the public profile and this member's state with them.
     *
     * @return array<string,mixed>
     */
    public function profile(int $fcUserId, int $trainerId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers ut
                      WHERE ut.trainer_id = t.id AND ut.status = 'active') AS client_count
               FROM {$wpdb->prefix}fc_trainers t
              WHERE t.id = %d AND t.status = 'active'",
            $trainerId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_trainer_not_found',
                __('That trainer is not available.', 'fitnessclub'),
                404
            );
        }

        // Readable even when they have stopped accepting clients or filled up:
        // a member already coached by them must be able to open the profile, and
        // a bookmarked link should explain itself rather than 404.
        $profile = $this->present($row, $this->linkStates($fcUserId));

        $profile['eligibility'] = $this->eligibility($fcUserId);

        return $profile;
    }

    /**
     * `POST /trainers/{id}/request`.
     *
     * The rejection ladder is ordered deliberately (02, Q14): the member's own
     * gates first, the trainer's capacity second, the rate limit last. Telling
     * somebody with no subscription that a trainer is full would send them to
     * the wrong fix.
     *
     * @return array<string,mixed>
     */
    public function request(int $fcUserId, int $trainerId, ?string $message = null): array
    {
        global $wpdb;

        $entitlements = new EntitlementService();

        if (!$entitlements->hasActiveSubscription($fcUserId)) {
            throw new DomainException(
                'fc_subscription_required',
                __('Choose a plan before working with a trainer.', 'fitnessclub'),
                403
            );
        }

        $allowed = $entitlements->limit($fcUserId, 'max_trainers');
        $used    = $entitlements->trainersUsed($fcUserId);

        // `null` is unlimited and must not be coerced to 0, which would read as
        // "no trainers allowed" and lock out the most generous plan there is.
        if (null !== $allowed && $used >= $allowed) {
            throw new DomainException(
                'fc_trainer_limit_reached',
                __('Your plan does not have a free trainer slot.', 'fitnessclub'),
                403,
                ['limit' => $allowed, 'used' => $used]
            );
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND trainer_id = %d AND status IN ('pending', 'active')",
            $fcUserId,
            $trainerId
        ), ARRAY_A);

        if (is_array($existing)) {
            throw new DomainException(
                'fc_request_exists',
                'active' === $existing['status']
                    ? __('This trainer already coaches you.', 'fitnessclub')
                    : __('You have already asked this trainer.', 'fitnessclub'),
                409
            );
        }

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id, t.account_id, t.display_name, t.accepting_clients, t.max_clients,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers ut
                      WHERE ut.trainer_id = t.id AND ut.status = 'active') AS client_count
               FROM {$wpdb->prefix}fc_trainers t
              WHERE t.id = %d AND t.status = 'active'",
            $trainerId
        ), ARRAY_A);

        if (!is_array($trainer)) {
            throw new DomainException(
                'fc_trainer_not_found',
                __('That trainer is not available.', 'fitnessclub'),
                404
            );
        }

        // Re-checked at submit, not trusted from the listing: a directory is a
        // race by design, and the trainer may have filled up or closed their
        // books since the page was drawn.
        $full = (int) $trainer['max_clients'] > 0
            && (int) $trainer['client_count'] >= (int) $trainer['max_clients'];

        if (!(bool) $trainer['accepting_clients'] || $full) {
            throw new DomainException(
                'fc_trainer_at_capacity',
                __('This trainer is not taking new clients right now.', 'fitnessclub'),
                409
            );
        }

        $this->assertWithinRequestLimits($fcUserId);

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_trainers', [
            'user_id'         => $fcUserId,
            'trainer_id'      => $trainerId,
            'status'          => 'pending',
            'requested_at'    => $now,
            'request_message' => $this->requestMessage($message),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $requestId = (int) $wpdb->insert_id;

        // The slot is spent the moment the request exists, so the cached
        // entitlement set is stale from here.
        EntitlementService::flush($fcUserId);

        $this->notifyTrainer(
            (int) $trainer['account_id'],
            __('A member would like you to coach them', 'fitnessclub'),
            (string) $this->memberName($fcUserId)
        );

        return [
            'id'          => $requestId,
            'status'      => 'pending',
            'eligibility' => $this->eligibility($fcUserId),
        ];
    }

    /**
     * `DELETE /trainers/{id}/request` — withdraw a request the trainer has not answered.
     *
     * @return array<string,mixed>
     */
    public function withdraw(int $fcUserId, int $trainerId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND trainer_id = %d AND status = 'pending'",
            $fcUserId,
            $trainerId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_request_not_found',
                __('There is no request to withdraw.', 'fitnessclub'),
                404
            );
        }

        // `withdrawn`, not deleted and not `declined`: the three are different
        // events, and only a distinct value lets the platform tell "they changed
        // their mind" from "the trainer said no" later (Q4).
        $wpdb->update(
            $wpdb->prefix . 'fc_user_trainers',
            ['status' => 'withdrawn', 'responded_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => (int) $row['id']]
        );

        EntitlementService::flush($fcUserId);

        return ['ok' => true, 'eligibility' => $this->eligibility($fcUserId)];
    }

    // ---------------------------------------------------------- my trainers

    /**
     * `GET /user/trainers` — who coaches this member, and who has been asked.
     *
     * Both in one list, distinguished by `status`, because from the member's
     * side they are one thing: the coaches in their life, some of whom have not
     * answered yet. Declined and withdrawn rows are **not** returned — a list
     * that keeps showing a trainer who said no is a list nobody wants to open.
     *
     * @return array<string,mixed>
     */
    public function myTrainers(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ut.id, ut.status, ut.is_primary, ut.requested_at, ut.assigned_date,
                    ut.request_message,
                    t.id AS trainer_id, t.display_name, t.avatar_url, t.specialization,
                    t.rating, t.rating_count
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = ut.trainer_id
              WHERE ut.user_id = %d AND ut.status IN ('active', 'pending')
              ORDER BY ut.is_primary DESC, ut.status ASC, ut.id ASC",
            $fcUserId
        ), ARRAY_A) ?: [];

        return [
            'items' => array_map(static fn(array $row): array => [
                'id'             => (int) $row['id'],
                'trainer_id'     => (int) $row['trainer_id'],
                'display_name'   => $row['display_name'],
                'avatar_url'     => $row['avatar_url'],
                'specialization' => $row['specialization'],
                'rating'         => null === $row['rating'] ? null : round((float) $row['rating'], 1),
                'rating_count'   => (int) $row['rating_count'],
                'status'         => $row['status'],
                'is_primary'     => (bool) $row['is_primary'],
                'requested_at'   => $row['requested_at'],
                'assigned_date'  => $row['assigned_date'],
                'request_message' => $row['request_message'],
            ], $rows),
            'eligibility' => $this->eligibility($fcUserId),
        ];
    }

    /**
     * `DELETE /user/trainers/{trainerId}` — leave a coach, or withdraw a request.
     *
     * One endpoint for both because it is one intention: stop this from going
     * ahead. Which of the two happened is decided by the row's status, not by
     * the caller, so a client cannot withdraw an active relationship into the
     * wrong terminal state.
     *
     * @return array<string,mixed>
     */
    public function leave(int $fcUserId, int $trainerId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, is_primary FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND trainer_id = %d AND status IN ('pending', 'active')",
            $fcUserId,
            $trainerId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_trainer_link_not_found',
                __('This trainer is not coaching you.', 'fitnessclub'),
                404
            );
        }

        $now      = gmdate('Y-m-d H:i:s');
        $wasActive = 'active' === $row['status'];

        $wpdb->update(
            $wpdb->prefix . 'fc_user_trainers',
            [
                'status'       => $wasActive ? 'inactive' : 'withdrawn',
                'is_primary'   => 0,
                'responded_at' => $now,
                'ended_date'   => $wasActive ? gmdate('Y-m-d') : null,
                'updated_at'   => $now,
            ],
            ['id' => (int) $row['id']]
        );

        EntitlementService::flush($fcUserId);

        // Ending the primary relationship leaves the member with none, which the
        // dashboard reads as "no trainer" even while another coach is active. The
        // longest-standing remaining coach inherits it rather than nobody.
        if ((bool) $row['is_primary']) {
            $this->promoteAPrimary($fcUserId);
        }

        if ($wasActive) {
            $this->notifyTrainerOfDeparture($trainerId, $fcUserId);
        }

        return ['ok' => true, 'eligibility' => $this->eligibility($fcUserId)];
    }

    /**
     * `POST /user/trainers/{trainerId}/primary` — one primary per member (Q3).
     *
     * @return array<string,mixed>
     */
    public function setPrimary(int $fcUserId, int $trainerId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND trainer_id = %d AND status = 'active'",
            $fcUserId,
            $trainerId
        ), ARRAY_A);

        if (!is_array($row)) {
            // A pending request cannot be primary: the trainer has not agreed to
            // coach them yet.
            throw new DomainException(
                'fc_trainer_link_not_found',
                __('This trainer is not coaching you.', 'fitnessclub'),
                404
            );
        }

        // Cleared for every row first, so the "one primary" rule cannot be
        // broken by a partial write — the table has no constraint expressing it.
        $wpdb->update(
            $wpdb->prefix . 'fc_user_trainers',
            ['is_primary' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['user_id' => $fcUserId]
        );

        $wpdb->update(
            $wpdb->prefix . 'fc_user_trainers',
            ['is_primary' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => (int) $row['id']]
        );

        return ['ok' => true];
    }

    // ------------------------------------------------------------ internals

    /**
     * The caller's eligibility to request another trainer.
     *
     * @return array<string,mixed>
     */
    private function eligibility(int $fcUserId): array
    {
        $entitlements = new EntitlementService();

        $allowed = $entitlements->limit($fcUserId, 'max_trainers');
        $used    = $entitlements->trainersUsed($fcUserId);

        if (!$entitlements->hasActiveSubscription($fcUserId)) {
            $reason = 'subscription_required';
        } elseif (null !== $allowed && $used >= $allowed) {
            // Not an error state. With grandfathering on downgrade (Q16) a
            // member can legitimately sit at "2 of 1 used", and the copy must
            // not imply they did something wrong.
            $reason = 'limit_reached';
        } else {
            $reason = null;
        }

        return [
            'can_request'      => null === $reason,
            'reason'           => $reason,
            'trainers_used'    => $used,
            'trainers_allowed' => $allowed,
        ];
    }

    /**
     * The member's link to each trainer, keyed by trainer id.
     *
     * Fetched once per directory render rather than per card: forty trainers
     * would otherwise be forty queries for a value the member has at most a
     * handful of.
     *
     * @return array<int,string>
     */
    private function linkStates(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, status FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND status IN ('pending', 'active')",
            $fcUserId
        ), ARRAY_A) ?: [];

        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row['trainer_id']] = (string) $row['status'];
        }

        return $states;
    }

    /**
     * The public projection — the whole of what a member may see about a trainer.
     *
     * @param array<string,mixed> $row
     * @param array<int,string>   $states
     * @return array<string,mixed>
     */
    private function present(array $row, array $states): array
    {
        $trainerId = (int) $row['id'];
        // NULL and 0 both mean unconfigured; `(int)` folds them together.
        $max = (int) $row['max_clients'];

        return [
            'trainer_id'     => $trainerId,
            'display_name'   => $row['display_name'],
            'avatar_url'     => $row['avatar_url'],
            'bio'            => $row['bio'],
            'specialization' => $row['specialization'],
            'rating'         => null === $row['rating'] ? null : round((float) $row['rating'], 1),
            'rating_count'   => (int) $row['rating_count'],
            'client_count'   => (int) $row['client_count'],
            // 0 means no configured limit, so it is reported as null rather than
            // as a cap of zero.
            'max_clients'      => $max > 0 ? $max : null,
            'accepting_clients' => (bool) $row['accepting_clients'],
            'has_capacity'   => 0 === $max || (int) $row['client_count'] < $max,
            // What this member's own relationship with them is, so the card can
            // say "requested" or "your coach" instead of offering a request that
            // would 409.
            'my_status'      => $states[$trainerId] ?? null,
            // `hourly_rate` is display-only metadata (Q2) and is deliberately
            // included — it is on the public profile. `email`, `phone` and
            // `account_id` are deliberately absent: this projection is the only
            // thing standing between the directory and one member reading
            // another account's contact details.
            'hourly_rate'    => null === $row['hourly_rate'] ? null : round((float) $row['hourly_rate'], 2),
            'currency'       => $row['currency'],
        ];
    }

    /**
     * The specialization filter's options, derived from trainers who are
     * actually listable.
     *
     * A hardcoded list goes stale the moment somebody types a new one, and a
     * filter that offers a value with no matches can only return nothing — the
     * same rule W2.4 applied to the activity filter.
     *
     * @return array<int,string>
     */
    private function specializations(): array
    {
        global $wpdb;

        $values = $wpdb->get_col(
            "SELECT DISTINCT specialization FROM {$wpdb->prefix}fc_trainers
              WHERE status = 'active' AND accepting_clients = 1
                AND specialization IS NOT NULL AND specialization <> ''
              ORDER BY specialization ASC"
        );

        return array_values(array_map('strval', $values ?: []));
    }

    /**
     * 3 outstanding, 10 in a rolling week (04, 09 §4.4).
     *
     * Counted from the table rather than from a transient bucket: the limit is a
     * statement about requests that exist, and a cache eviction must not hand
     * somebody a fresh ten. The rolling week matches the messaging quota's
     * reasoning — a calendar boundary the member cannot see is one they cannot
     * plan around.
     */
    private function assertWithinRequestLimits(int $fcUserId): void
    {
        global $wpdb;

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND status = 'pending'",
            $fcUserId
        ));

        if ($pending >= self::MAX_PENDING) {
            throw new DomainException(
                'fc_request_limit',
                sprintf(
                    /* translators: %d: number of requests. */
                    __('You already have %d requests waiting for an answer.', 'fitnessclub'),
                    self::MAX_PENDING
                ),
                429,
                ['limit' => self::MAX_PENDING, 'scope' => 'pending']
            );
        }

        $week = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND requested_at >= %s",
            $fcUserId,
            gmdate('Y-m-d H:i:s', strtotime('-7 days'))
        ));

        if ($week >= self::MAX_PER_WEEK) {
            throw new DomainException(
                'fc_request_limit',
                __('You have sent a lot of requests this week. Try again in a few days.', 'fitnessclub'),
                429,
                ['limit' => self::MAX_PER_WEEK, 'scope' => 'week']
            );
        }
    }

    private function requestMessage(?string $message): ?string
    {
        if (null === $message) {
            return null;
        }

        $clean = trim(sanitize_textarea_field($message));

        // The column is varchar(500); truncating here rather than letting MySQL
        // do it keeps the stored value and the validated one the same string.
        return '' === $clean ? null : mb_substr($clean, 0, 500);
    }

    private function memberName(int $fcUserId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fcUserId
        ));
    }

    /**
     * Hand the primary marker to the longest-standing remaining coach.
     */
    private function promoteAPrimary(int $fcUserId): void
    {
        global $wpdb;

        $next = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND status = 'active'
              ORDER BY assigned_date ASC, id ASC LIMIT 1",
            $fcUserId
        ));

        if ($next > 0) {
            $wpdb->update(
                $wpdb->prefix . 'fc_user_trainers',
                ['is_primary' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $next]
            );
        }
    }

    /**
     * A trainer is told when a client leaves.
     *
     * They will otherwise find out by noticing an absence, and a coach who keeps
     * programming for somebody who has gone is wasting their time.
     */
    private function notifyTrainerOfDeparture(int $trainerId, int $fcUserId): void
    {
        global $wpdb;

        $accountId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT account_id FROM {$wpdb->prefix}fc_trainers WHERE id = %d",
            $trainerId
        ));

        $this->notifyTrainer(
            $accountId,
            __('A client ended their coaching', 'fitnessclub'),
            (string) $this->memberName($fcUserId)
        );
    }

    private function notifyTrainer(int $accountId, string $title, string $body): void
    {
        if ($accountId > 0) {
            // `system`, not `workout`: a coaching request is not training
            // activity, and filing it under a category the trainer may have
            // muted would mean the request is never written at all (W2.4
            // honours preferences at the write).
            (new NotificationService())->notify($accountId, 'system', $title, $body);
        }
    }
}
