<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;
use FitnessClub\Support\Guard;
use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The trainer's view of their clients (plans/06-trainer-app.md, W3.4).
 *
 * ## Two guards, and Q13 is the line between them
 *
 * A client may be coached by several trainers at once (Q3), so "may I touch
 * this" splits in two:
 *
 *   - **Read — `Guard::trainerCoachesClient`.** Any actively assigned trainer
 *     sees the *whole* client record, including **every other trainer's**
 *     assignments and plans. That is deliberate: two coaches independently
 *     programming heavy compounds in the same week is a genuine injury risk, and
 *     shared visibility exists to catch it. Every assignment therefore carries
 *     `assigned_by`, so the UI can attribute it rather than implying the reader
 *     wrote it.
 *   - **Write — the assigner only.** `Guard::trainerMadeAssignment` for
 *     assignments, `Guard::trainerAssignedResource` for authored workouts and
 *     plans. Seeing another trainer's work is not permission to undo it.
 *
 * ## Notes are neither (Q15)
 *
 * `fc_client_notes` is private to the trainer who wrote it — not shared-read,
 * not visible to the client, not visible to a co-trainer. It is the one place a
 * trainer records a professional judgement, and it stops being that the moment
 * somebody else can read it.
 */
final class TrainerService
{
    /** How far ahead an overlapping assignment counts as a conflict. */
    private const CONFLICT_WINDOW_DAYS = 7;

    // ------------------------------------------------------------- the roster

    /**
     * `GET /trainer/clients` — everyone this trainer actively coaches.
     *
     * @param array<string,mixed> $filters status, q
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function clients(int $trainerAccountId, array $filters = []): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $where  = ['ut.trainer_id = %d'];
        $params = [$trainerId];

        // Defaults to active. A trainer's roster is who they coach *now*;
        // showing ended relationships by default would make the list grow
        // forever and never shrink.
        $status   = (string) ($filters['status'] ?? 'active');
        $where[]  = 'ut.status = %s';
        $params[] = $status;

        if (!empty($filters['q'])) {
            $where[]  = '(u.display_name LIKE %s OR a.email LIKE %s)';
            $like     = '%' . $wpdb->esc_like((string) $filters['q']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is placeholders only.
        $sql = "SELECT u.id AS user_id, u.display_name, u.avatar_url, u.fitness_goal,
                       u.weight_kg, u.target_weight_kg,
                       a.email, a.last_login_at,
                       ut.id AS link_id, ut.status, ut.is_primary, ut.assigned_date,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions s
                         WHERE s.user_id = u.id AND s.status = 'completed') AS sessions_completed,
                       (SELECT MAX(s.log_date) FROM {$wpdb->prefix}fc_workout_sessions s
                         WHERE s.user_id = u.id AND s.status = 'completed') AS last_session_date,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers o
                         WHERE o.user_id = u.id AND o.status = 'active') AS trainer_count
                  FROM {$wpdb->prefix}fc_user_trainers ut
                  JOIN {$wpdb->prefix}fc_users u ON u.id = ut.user_id
                  JOIN {$wpdb->prefix}fc_accounts a ON a.id = u.account_id
                 WHERE {$clause}
                 ORDER BY ut.is_primary DESC, u.display_name ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        $items = array_map(static fn(array $row): array => [
            'user_id'       => (int) $row['user_id'],
            'display_name'  => $row['display_name'],
            'avatar_url'    => $row['avatar_url'],
            'email'         => $row['email'],
            'fitness_goal'  => $row['fitness_goal'],
            'weight_kg'     => null === $row['weight_kg'] ? null : round((float) $row['weight_kg'], 1),
            'target_weight_kg' => null === $row['target_weight_kg']
                ? null
                : round((float) $row['target_weight_kg'], 1),
            'status'        => $row['status'],
            'is_primary'    => (bool) $row['is_primary'],
            'assigned_date' => $row['assigned_date'],
            'sessions_completed' => (int) $row['sessions_completed'],
            'last_session_date'  => $row['last_session_date'],
            // Q3 made visible: a client with two coaches should look different
            // from one with a single trainer, because the programming
            // conversation is different.
            'trainer_count' => (int) $row['trainer_count'],
        ], $rows);

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * `GET /trainer/clients/{userId}` — the whole record, shared-read.
     *
     * @return array<string,mixed>
     */
    public function client(int $trainerAccountId, int $clientUserId): array
    {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT u.*, a.email, a.last_login_at, a.status AS account_status
               FROM {$wpdb->prefix}fc_users u
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = u.account_id
              WHERE u.id = %d",
            $clientUserId
        ), ARRAY_A);

        return [
            'user_id'       => (int) $row['id'],
            'display_name'  => $row['display_name'],
            'avatar_url'    => $row['avatar_url'],
            'email'         => $row['email'],
            'date_of_birth' => $row['date_of_birth'],
            'gender'        => $row['gender'],
            'height_cm'     => null === $row['height_cm'] ? null : round((float) $row['height_cm'], 1),
            'weight_kg'     => null === $row['weight_kg'] ? null : round((float) $row['weight_kg'], 1),
            'target_weight_kg' => null === $row['target_weight_kg']
                ? null
                : round((float) $row['target_weight_kg'], 1),
            'fitness_level'  => $row['fitness_level'],
            'fitness_goal'   => $row['fitness_goal'],
            'activity_level' => $row['activity_level'],
            'account_status' => $row['account_status'],
            'last_login_at'  => $row['last_login_at'],
            // Every coach on this client, so the reader knows who else is
            // programming for them.
            'trainers'    => $this->trainersOf($clientUserId),
            'assignments' => $this->assignments($clientUserId),
        ];
    }

    /**
     * `GET /trainer/clients/{userId}/progress?range=`
     *
     * @return array<string,mixed>
     */
    public function progress(int $trainerAccountId, int $clientUserId, string $range): array
    {
        $this->assertCoaches($trainerAccountId, $clientUserId);

        return (new ProgressService())->range($clientUserId, $range);
    }

    /**
     * `GET /trainer/clients/{userId}/sessions`
     *
     * @return array<string,mixed>
     */
    public function sessions(int $trainerAccountId, int $clientUserId, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions WHERE user_id = %d",
            $clientUserId
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.log_date, s.status, s.duration_seconds, s.calories_burned,
                    s.completion_percentage, s.perceived_exertion, s.notes,
                    w.workout_name
               FROM {$wpdb->prefix}fc_workout_sessions s
               LEFT JOIN {$wpdb->prefix}fc_workouts w ON w.id = s.workout_id
              WHERE s.user_id = %d
              ORDER BY s.log_date DESC, s.id DESC
              LIMIT %d OFFSET %d",
            $clientUserId,
            $perPage,
            ($page - 1) * $perPage
        ), ARRAY_A) ?: [];

        return [
            'items' => array_map(static fn(array $row): array => [
                'id'           => (int) $row['id'],
                'workout_name' => $row['workout_name'],
                'log_date'     => $row['log_date'],
                'status'       => $row['status'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'calories_burned'  => null === $row['calories_burned'] ? null : (int) $row['calories_burned'],
                'completion_percentage' => round((float) $row['completion_percentage'], 1),
                'perceived_exertion' => null === $row['perceived_exertion'] ? null : (int) $row['perceived_exertion'],
                'notes'        => $row['notes'],
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * `GET /trainer/clients/{userId}/nutrition` — if the client's plan permits.
     *
     * The gate is the **client's** entitlement, not the trainer's: nutrition
     * data only exists if the client's plan let them log it, and a trainer
     * cannot see what was never recorded.
     *
     * @return array<string,mixed>
     */
    public function nutrition(int $trainerAccountId, int $clientUserId, ?string $from, ?string $to): array
    {
        $this->assertCoaches($trainerAccountId, $clientUserId);

        if (!(new EntitlementService())->can($clientUserId, 'can_log_nutrition')) {
            return ['items' => [], 'available' => false];
        }

        $to   = $to ?? UserClock::today($clientUserId);
        $from = $from ?? UserClock::shift($to, -29);

        return [
            'items'     => (new NutritionService())->range($clientUserId, $from, $to),
            'available' => true,
            'from'      => $from,
            'to'        => $to,
        ];
    }

    // ----------------------------------------------------------- assignments

    /**
     * `POST /trainer/clients/{userId}/workouts` — assign, with conflict warnings.
     *
     * The warning is **non-blocking**: another trainer's heavy session in the
     * same week is a thing to know about, not a thing to be stopped by. Refusing
     * would make one coach's programme silently constrain another's, which is
     * exactly the paternalism shared visibility is meant to replace.
     *
     * @return array<string,mixed>
     */
    public function assignWorkout(
        int $trainerAccountId,
        int $clientUserId,
        int $workoutId,
        ?string $scheduledFor = null
    ): array {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_workouts WHERE id = %d AND is_active = 1",
            $workoutId
        ));

        if (null === $exists) {
            throw new DomainException(
                'fc_workout_not_found',
                __('That workout does not exist.', 'fitnessclub'),
                404
            );
        }

        $scheduled = $this->normaliseDate($scheduledFor);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_workouts', [
            'user_id'       => $clientUserId,
            'workout_id'    => $workoutId,
            // The account, not the trainer profile — this is what the write
            // guard checks, and what attributes the assignment in the UI.
            'assigned_by_account_id' => $trainerAccountId,
            'assigned_date' => gmdate('Y-m-d'),
            'scheduled_for' => $scheduled,
            'status'        => 'assigned',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $assignmentId = (int) $wpdb->insert_id;

        $this->notifyClient(
            $clientUserId,
            __('A new workout is waiting', 'fitnessclub'),
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT workout_name FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
                $workoutId
            ))
        );

        return [
            'assignment' => $this->assignment($assignmentId),
            'warnings'   => $this->conflicts($trainerAccountId, $clientUserId, $scheduled, $assignmentId),
        ];
    }

    /**
     * `DELETE /trainer/clients/{userId}/workouts/{id}` — assigner only.
     */
    public function unassignWorkout(int $trainerAccountId, int $clientUserId, int $assignmentId): void
    {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        // Seeing another trainer's assignment (Q13) is not permission to undo
        // it. 403 rather than 404 here on purpose: shared read already told the
        // caller it exists, so pretending otherwise would be theatre.
        if (!Guard::trainerMadeAssignment($trainerAccountId, 'user_workouts', $assignmentId)) {
            throw new DomainException(
                'fc_not_your_assignment',
                __('Only the trainer who assigned this can remove it.', 'fitnessclub'),
                403
            );
        }

        $wpdb->delete($wpdb->prefix . 'fc_user_workouts', [
            'id'      => $assignmentId,
            'user_id' => $clientUserId,
        ]);
    }

    /**
     * `POST /trainer/clients/{userId}/food-plans`
     *
     * @return array<string,mixed>
     */
    public function assignFoodPlan(
        int $trainerAccountId,
        int $clientUserId,
        int $foodPlanId,
        ?string $startDate = null
    ): array {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        if (
            null === $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fc_food_plans WHERE id = %d",
                $foodPlanId
            ))
        ) {
            throw new DomainException(
                'fc_food_plan_not_found',
                __('That food plan does not exist.', 'fitnessclub'),
                404
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_food_plans', [
            'user_id'      => $clientUserId,
            'food_plan_id' => $foodPlanId,
            'assigned_by_account_id' => $trainerAccountId,
            'start_date'   => $this->normaliseDate($startDate) ?? gmdate('Y-m-d'),
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return ['id' => (int) $wpdb->insert_id];
    }

    // ----------------------------------------------------------- private notes

    /**
     * `GET /trainer/clients/{userId}/notes` — the caller's own only (Q15).
     *
     * Scoped by `trainer_id` as well as `user_id`. A co-trainer's notes are not
     * filtered out of a shared list; they are never selected in the first place.
     *
     * @return array<int,array<string,mixed>>
     */
    public function notes(int $trainerAccountId, int $clientUserId): array
    {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_client_notes
              WHERE user_id = %d AND trainer_id = %d
              ORDER BY pinned DESC, created_at DESC",
            $clientUserId,
            $this->trainerId($trainerAccountId)
        ), ARRAY_A) ?: [];

        return array_map(static fn(array $row): array => [
            'id'         => (int) $row['id'],
            'body'       => $row['body'],
            'pinned'     => (bool) $row['pinned'],
            'created_at' => gmdate('c', (int) strtotime((string) $row['created_at'] . ' UTC')),
            'updated_at' => gmdate('c', (int) strtotime((string) $row['updated_at'] . ' UTC')),
        ], $rows);
    }

    /**
     * @return array<string,mixed>
     */
    public function createNote(int $trainerAccountId, int $clientUserId, string $body, bool $pinned = false): array
    {
        global $wpdb;

        $this->assertCoaches($trainerAccountId, $clientUserId);

        $body = trim(sanitize_textarea_field($body));

        if ('' === $body) {
            throw new DomainException('fc_note_empty', __('Write something first.', 'fitnessclub'), 400);
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_client_notes', [
            'trainer_id' => $this->trainerId($trainerAccountId),
            'user_id'    => $clientUserId,
            'body'       => $body,
            'pinned'     => $pinned ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $wpdb->insert_id];
    }

    public function updateNote(int $trainerAccountId, int $noteId, ?string $body, ?bool $pinned): void
    {
        global $wpdb;

        $this->assertOwnsNote($trainerAccountId, $noteId);

        $fields = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if (null !== $body) {
            $clean = trim(sanitize_textarea_field($body));

            if ('' === $clean) {
                throw new DomainException('fc_note_empty', __('Write something first.', 'fitnessclub'), 400);
            }

            $fields['body'] = $clean;
        }

        if (null !== $pinned) {
            $fields['pinned'] = $pinned ? 1 : 0;
        }

        $wpdb->update($wpdb->prefix . 'fc_client_notes', $fields, ['id' => $noteId]);
    }

    public function deleteNote(int $trainerAccountId, int $noteId): void
    {
        global $wpdb;

        $this->assertOwnsNote($trainerAccountId, $noteId);

        $wpdb->delete($wpdb->prefix . 'fc_client_notes', ['id' => $noteId]);
    }

    // --------------------------------------------------------- request queue

    /**
     * `GET /trainer/requests` — inbound client requests (Q4).
     *
     * @return array<string,mixed>
     */
    public function requests(int $trainerAccountId, string $status = 'pending'): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ut.*, u.display_name, u.avatar_url, u.fitness_goal, a.email
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_users u ON u.id = ut.user_id
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = u.account_id
              WHERE ut.trainer_id = %d AND ut.status = %s
              ORDER BY ut.requested_at ASC, ut.id ASC",
            $trainerId,
            $status
        ), ARRAY_A) ?: [];

        return [
            'items' => array_map(static fn(array $row): array => [
                'id'              => (int) $row['id'],
                'user_id'         => (int) $row['user_id'],
                'display_name'    => $row['display_name'],
                'avatar_url'      => $row['avatar_url'],
                'email'           => $row['email'],
                'fitness_goal'    => $row['fitness_goal'],
                'request_message' => $row['request_message'],
                'requested_at'    => empty($row['requested_at'])
                    ? null
                    : gmdate('c', (int) strtotime((string) $row['requested_at'] . ' UTC')),
            ], $rows),
            'capacity' => $this->capacity($trainerId),
        ];
    }

    /**
     * `POST /trainer/requests/{id}/accept`.
     *
     * @return array<string,mixed>
     */
    public function acceptRequest(int $trainerAccountId, int $requestId): array
    {
        global $wpdb;

        $row       = $this->pendingRequest($trainerAccountId, $requestId);
        $trainerId = $this->trainerId($trainerAccountId);
        $capacity  = $this->capacity($trainerId);

        // Capacity is the trainer's own limit on how many people they can coach
        // properly. Checked at accept rather than at request, because it can
        // fill between somebody asking and the trainer answering.
        if (null !== $capacity['max'] && $capacity['used'] >= $capacity['max']) {
            throw new DomainException(
                'fc_trainer_at_capacity',
                __('You are at your client limit. Raise it in your profile to take more on.', 'fitnessclub'),
                409
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->update($wpdb->prefix . 'fc_user_trainers', [
            'status'        => 'active',
            'responded_at'  => $now,
            'assigned_date' => gmdate('Y-m-d'),
            'updated_at'    => $now,
        ], ['id' => $requestId]);

        EntitlementService::flush((int) $row['user_id']);

        // The conversation is part of accepting somebody, not a thing either
        // side has to go and create: without this both parties reach a messaging
        // screen with no thread on it and no way to open one. Idempotent, so a
        // re-accept keeps the existing history.
        (new MessageService())->ensureThread((int) $row['user_id'], $trainerId);

        $this->notifyClient(
            (int) $row['user_id'],
            __('Your trainer request was accepted', 'fitnessclub'),
            (string) $this->trainerName($trainerId)
        );

        return ['id' => $requestId, 'status' => 'active'];
    }

    /**
     * `POST /trainer/requests/{id}/decline`.
     *
     * @return array<string,mixed>
     */
    public function declineRequest(int $trainerAccountId, int $requestId, ?string $reason = null): array
    {
        global $wpdb;

        $row = $this->pendingRequest($trainerAccountId, $requestId);
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->update($wpdb->prefix . 'fc_user_trainers', [
            'status'         => 'declined',
            'responded_at'   => $now,
            'decline_reason' => null === $reason ? null : sanitize_textarea_field($reason),
            'updated_at'     => $now,
        ], ['id' => $requestId]);

        // A declined request still frees the slot it was consuming (Q14), so the
        // member can ask somebody else.
        EntitlementService::flush((int) $row['user_id']);

        $this->notifyClient(
            (int) $row['user_id'],
            __('Your trainer request was declined', 'fitnessclub'),
            null === $reason ? '' : (string) $reason
        );

        return ['id' => $requestId, 'status' => 'declined'];
    }

    // -------------------------------------------------------------- internals

    /**
     * Overlapping assignments from **other** trainers in the same week.
     *
     * The point of Q13's shared visibility: two coaches independently
     * programming the same client in the same week is a real injury risk, and
     * this is the only place either of them would find out.
     *
     * @return array<int,array<string,mixed>>
     */
    private function conflicts(
        int $trainerAccountId,
        int $clientUserId,
        ?string $scheduledFor,
        int $excludeAssignmentId
    ): array {
        global $wpdb;

        if (null === $scheduledFor) {
            return [];
        }

        $from = UserClock::shift($scheduledFor, -self::CONFLICT_WINDOW_DAYS);
        $to   = UserClock::shift($scheduledFor, self::CONFLICT_WINDOW_DAYS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uw.id, uw.scheduled_for, w.workout_name, w.difficulty,
                    t.display_name AS assigned_by_name
               FROM {$wpdb->prefix}fc_user_workouts uw
               JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
               LEFT JOIN {$wpdb->prefix}fc_trainers t ON t.account_id = uw.assigned_by_account_id
              WHERE uw.user_id = %d
                AND uw.id <> %d
                AND uw.assigned_by_account_id <> %d
                AND uw.scheduled_for BETWEEN %s AND %s",
            $clientUserId,
            $excludeAssignmentId,
            $trainerAccountId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        return array_map(static fn(array $row): array => [
            'code'    => 'fc_assignment_conflict',
            'message' => sprintf(
                /* translators: 1: trainer name, 2: workout name, 3: date */
                __('%1$s has already assigned %2$s for %3$s.', 'fitnessclub'),
                $row['assigned_by_name'] ?? __('Another trainer', 'fitnessclub'),
                $row['workout_name'],
                $row['scheduled_for']
            ),
            'assignment_id' => (int) $row['id'],
            'scheduled_for' => $row['scheduled_for'],
            'difficulty'    => $row['difficulty'],
        ], $rows);
    }

    /**
     * Every active assignment on a client, attributed.
     *
     * @return array<int,array<string,mixed>>
     */
    private function assignments(int $clientUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uw.id, uw.workout_id, uw.scheduled_for, uw.status, uw.assigned_date,
                    uw.progress_percentage, uw.times_completed, uw.assigned_by_account_id,
                    w.workout_name, w.difficulty, w.workout_type,
                    t.id AS assigned_by_trainer_id, t.display_name AS assigned_by_name
               FROM {$wpdb->prefix}fc_user_workouts uw
               JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
               LEFT JOIN {$wpdb->prefix}fc_trainers t ON t.account_id = uw.assigned_by_account_id
              WHERE uw.user_id = %d
              ORDER BY uw.scheduled_for DESC, uw.id DESC",
            $clientUserId
        ), ARRAY_A) ?: [];

        return array_map(static fn(array $row): array => [
            'id'            => (int) $row['id'],
            'workout_id'    => (int) $row['workout_id'],
            'workout_name'  => $row['workout_name'],
            'difficulty'    => $row['difficulty'],
            'workout_type'  => $row['workout_type'],
            'scheduled_for' => $row['scheduled_for'],
            'status'        => $row['status'],
            'progress_percentage' => round((float) $row['progress_percentage'], 1),
            'times_completed'     => (int) $row['times_completed'],
            // Q13: shown on every assignment, so a trainer reading a co-trainer's
            // work never mistakes it for their own.
            'assigned_by' => [
                'account_id'   => null === $row['assigned_by_account_id']
                    ? null
                    : (int) $row['assigned_by_account_id'],
                'trainer_id'   => null === $row['assigned_by_trainer_id']
                    ? null
                    : (int) $row['assigned_by_trainer_id'],
                'display_name' => $row['assigned_by_name'],
                'assigned_at'  => $row['assigned_date'],
            ],
        ], $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function trainersOf(int $clientUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.display_name, t.specialization, ut.is_primary, ut.status, ut.assigned_date
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = ut.trainer_id
              WHERE ut.user_id = %d AND ut.status = 'active'
              ORDER BY ut.is_primary DESC, t.display_name ASC",
            $clientUserId
        ), ARRAY_A) ?: [];

        return array_map(static fn(array $row): array => [
            'trainer_id'     => (int) $row['id'],
            'display_name'   => $row['display_name'],
            'specialization' => $row['specialization'],
            'is_primary'     => (bool) $row['is_primary'],
            'assigned_date'  => $row['assigned_date'],
        ], $rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function assignment(int $assignmentId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT uw.*, w.workout_name FROM {$wpdb->prefix}fc_user_workouts uw
               JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
              WHERE uw.id = %d",
            $assignmentId
        ), ARRAY_A);

        return [
            'id'            => (int) $row['id'],
            'workout_id'    => (int) $row['workout_id'],
            'workout_name'  => $row['workout_name'],
            'scheduled_for' => $row['scheduled_for'],
            'status'        => $row['status'],
        ];
    }

    /**
     * @return array{used:int,max:?int}
     */
    private function capacity(int $trainerId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT max_clients,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers ut
                      WHERE ut.trainer_id = %d AND ut.status = 'active') AS used
               FROM {$wpdb->prefix}fc_trainers WHERE id = %d",
            $trainerId,
            $trainerId
        ), ARRAY_A);

        $max = $row['max_clients'] ?? null;

        return [
            'used' => (int) ($row['used'] ?? 0),
            // 0 means "no limit set", not "cannot take anybody" — a trainer with
            // an unconfigured profile must not be locked out of accepting.
            'max'  => null === $max || 0 === (int) $max ? null : (int) $max,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingRequest(int $trainerAccountId, int $requestId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_user_trainers
              WHERE id = %d AND trainer_id = %d",
            $requestId,
            $trainerId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_request_not_found',
                __('That request does not exist.', 'fitnessclub'),
                404
            );
        }

        if ('pending' !== $row['status']) {
            throw new DomainException(
                'fc_request_answered',
                __('That request has already been answered.', 'fitnessclub'),
                409
            );
        }

        return $row;
    }

    private function assertCoaches(int $trainerAccountId, int $clientUserId): void
    {
        if (Guard::trainerCoachesClient($trainerAccountId, $clientUserId)) {
            return;
        }

        // Somebody else's client is a 404: a trainer should not be able to
        // enumerate the platform's membership by probing ids.
        throw new DomainException(
            'fc_client_not_found',
            __('That client is not on your roster.', 'fitnessclub'),
            404
        );
    }

    private function assertOwnsNote(int $trainerAccountId, int $noteId): void
    {
        if (Guard::trainerAssignedResource($trainerAccountId, 'note', $noteId)) {
            return;
        }

        // 404, not 403: a co-trainer must not learn that a note exists at all
        // (Q15). A 403 would confirm it.
        throw new DomainException(
            'fc_note_not_found',
            __('That note does not exist.', 'fitnessclub'),
            404
        );
    }

    private function trainerId(int $trainerAccountId): int
    {
        $trainerId = Guard::trainerId($trainerAccountId);

        if (null === $trainerId) {
            throw new DomainException(
                'fc_no_trainer_profile',
                __('This account does not have a trainer profile.', 'fitnessclub'),
                403
            );
        }

        return $trainerId;
    }

    private function trainerName(int $trainerId): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}fc_trainers WHERE id = %d",
            $trainerId
        ));
    }

    private function notifyClient(int $clientUserId, string $title, string $body): void
    {
        global $wpdb;

        $accountId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT account_id FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $clientUserId
        ));

        if ($accountId > 0) {
            (new NotificationService())->notify($accountId, 'workout', $title, $body);
        }
    }

    private function normaliseDate(?string $value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
    }
}
