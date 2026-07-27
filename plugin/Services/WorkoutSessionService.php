<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;
use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The workout session state machine — the piece to get right
 * (plans/03-backend.md, plans/02-api-contract.md#the-session-state-machine).
 *
 * ```
 *            ┌───────────────┐  pause   ┌────────┐
 *  start ──▶ │  in_progress  │ ───────▶ │ paused │
 *            └───────────────┘ ◀─────── └────────┘
 *                │      │        resume     │
 *      complete  │      │ abandon           │ complete / abandon
 *                ▼      ▼                   ▼
 *            completed  abandoned   (same terminal states)
 * ```
 *
 * Three rules hold everywhere in this class.
 *
 * **1. Time is derived, never reported.** `duration_seconds` is recomputed from
 * `started_at` / `last_resumed_at` on every pause and on complete. A client that
 * claims nine hours gets the elapsed time the server can prove; otherwise the
 * calorie counts and any future leaderboard become client-editable.
 * `paused_seconds` needs no column of its own to track *when* a pause began — at
 * resume it is exactly `(now - started_at) - duration_seconds`, i.e. wall-clock
 * minus active time, which is also self-healing if a write is ever missed.
 *
 * **2. One open session per user.** MySQL cannot express a partial unique index,
 * so `start()` takes the check inside a transaction with `SELECT … FOR UPDATE`.
 * The 409 body names the open session, so the UI can offer "resume or discard"
 * instead of a dead end.
 *
 * **3. Every transition is a transaction, and every read is user-scoped.**
 * Sessions load by `(id, user_id)`, so an id from another account is a 404 —
 * ownership is not a separate check that can be forgotten.
 *
 * Set logging is idempotent on `(session, exercise, set_index)`. The player fires
 * it mid-workout on gym wifi; a retry after a dropped connection has to update
 * the set, not log a phantom extra one.
 */
final class WorkoutSessionService
{
    /** Statuses that mean "a workout is happening right now". */
    private const OPEN_STATUSES = ['in_progress', 'paused'];

    private WorkoutService $workouts;

    private ActivityService $activity;

    private NotificationService $notifications;

    private StreakService $streaks;

    public function __construct(
        ?WorkoutService $workouts = null,
        ?ActivityService $activity = null,
        ?NotificationService $notifications = null,
        ?StreakService $streaks = null
    ) {
        $this->workouts      = $workouts ?? new WorkoutService();
        $this->activity      = $activity ?? new ActivityService();
        $this->notifications = $notifications ?? new NotificationService();
        $this->streaks       = $streaks ?? new StreakService();
    }

    // ------------------------------------------------------------------ start

    /**
     * Begin a session for an assigned workout.
     *
     * @return array<string,mixed> The rehydrated session.
     */
    public function start(int $fcUserId, int $workoutId): array
    {
        global $wpdb;

        // Outside the transaction: a 404 for an unassigned workout should not
        // hold row locks while it resolves.
        $workout   = $this->workouts->assignedWorkoutRow($fcUserId, $workoutId);
        $exercises = $this->workouts->exercisesFor($workoutId);

        if ([] === $exercises) {
            throw new DomainException(
                'fc_workout_empty',
                __('That workout has no exercises yet.', 'fitnessclub'),
                409
            );
        }

        $wpdb->query('START TRANSACTION');

        try {
            $open = $this->openSessionForUpdate($fcUserId);
            if (null !== $open) {
                throw new DomainException(
                    'fc_session_already_open',
                    __('You already have a workout in progress.', 'fitnessclub'),
                    409,
                    [
                        'session_id'   => (int) $open['id'],
                        'workout_id'   => (int) $open['workout_id'],
                        'session_status' => $open['status'],
                    ]
                );
            }

            $now = $this->now();

            $wpdb->insert($wpdb->prefix . 'fc_workout_sessions', [
                'user_id'         => $fcUserId,
                'workout_id'      => $workoutId,
                'user_workout_id' => (int) $workout['assignment_id'],
                'log_date'        => UserClock::today($fcUserId),
                'started_at'      => $now,
                'last_resumed_at' => $now,
                'duration_seconds' => 0,
                'paused_seconds'  => 0,
                'status'          => 'in_progress',
                'current_exercise_index' => 0,
                'current_set_index' => 0,
                'completion_percentage' => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $sessionId = (int) $wpdb->insert_id;

            // Snapshot the plan as it stood at start. A trainer editing the
            // workout tomorrow must not rewrite what the user did today.
            foreach ($exercises as $index => $exercise) {
                $wpdb->insert($wpdb->prefix . 'fc_exercise_logs', [
                    'session_id'        => $sessionId,
                    'exercise_id'       => (int) $exercise['id'],
                    'order_index'       => $index,
                    'planned_sets'      => (int) $exercise['default_sets'],
                    'planned_reps'      => (int) $exercise['default_reps'],
                    'planned_weight_kg' => (float) $exercise['default_weight_kg'],
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            $wpdb->update(
                $wpdb->prefix . 'fc_user_workouts',
                ['status' => 'in_progress', 'last_session_id' => $sessionId, 'updated_at' => $now],
                ['id' => (int) $workout['assignment_id']]
            );

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'session.started');

        return $this->rehydrate($fcUserId, $sessionId);
    }

    // --------------------------------------------------------------- read side

    /**
     * The user's open session, or null. The player calls this on mount.
     *
     * @return array<string,mixed>|null
     */
    public function active(int $fcUserId): ?array
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status IN ('in_progress', 'paused')
              ORDER BY id DESC LIMIT 1",
            $fcUserId
        ));

        return null === $id ? null : $this->rehydrate($fcUserId, (int) $id);
    }

    /**
     * Everything the player needs to redraw a session mid-workout: the session,
     * its cursor, the workout, and every exercise with the sets already logged.
     *
     * @return array<string,mixed>
     */
    public function rehydrate(int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT el.*, e.exercise_name, e.exercise_type, e.metric, e.default_rest_seconds,
                    e.video_url, e.thumbnail_url, e.notes
               FROM {$wpdb->prefix}fc_exercise_logs el
               JOIN {$wpdb->prefix}fc_exercises e ON e.id = el.exercise_id
              WHERE el.session_id = %d
              ORDER BY el.order_index ASC, el.id ASC",
            $sessionId
        ), ARRAY_A);

        $setsByLog = $this->setsBySessionLog($sessionId);

        $exercises = array_map(static function (array $log) use ($setsByLog): array {
            return [
                'exercise_id'       => (int) $log['exercise_id'],
                'exercise_name'     => $log['exercise_name'],
                'exercise_type'     => $log['exercise_type'],
                'metric'            => $log['metric'],
                'order_index'       => (int) $log['order_index'],
                'planned_sets'      => (int) $log['planned_sets'],
                'planned_reps'      => (int) $log['planned_reps'],
                'planned_weight_kg' => (float) $log['planned_weight_kg'],
                'rest_seconds'      => (int) $log['default_rest_seconds'],
                'video_url'         => $log['video_url'],
                'thumbnail_url'     => $log['thumbnail_url'],
                'notes'             => $log['notes'],
                'was_skipped'       => (bool) $log['was_skipped'],
                'total_volume_kg'   => (float) $log['total_volume_kg'],
                'sets'              => $setsByLog[(int) $log['id']] ?? [],
            ];
        }, $logs ?: []);

        return $this->presentSession($session) + ['exercises' => $exercises];
    }

    /**
     * Workout history, newest first.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function history(int $fcUserId, array $filters = []): array
    {
        global $wpdb;

        $limits  = (array) FitnessClub()->config('fitnessclub.limits', []);
        $perPage = max(1, min(
            (int) ($limits['per_page_max'] ?? 100),
            (int) ($filters['per_page'] ?? $limits['per_page_default'] ?? 20)
        ));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions WHERE user_id = %d",
            $fcUserId
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, w.workout_name, w.workout_type,
                    (SELECT COALESCE(SUM(el.total_volume_kg), 0)
                       FROM {$wpdb->prefix}fc_exercise_logs el
                      WHERE el.session_id = s.id) AS total_volume_kg
               FROM {$wpdb->prefix}fc_workout_sessions s
               JOIN {$wpdb->prefix}fc_workouts w ON w.id = s.workout_id
              WHERE s.user_id = %d
              ORDER BY s.started_at DESC, s.id DESC
              LIMIT %d OFFSET %d",
            $fcUserId,
            $perPage,
            ($page - 1) * $perPage
        ), ARRAY_A);

        return [
            'items'    => array_map(fn(array $row): array => $this->presentSession($row), $rows ?: []),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // ------------------------------------------------------------ transitions

    /**
     * @return array<string,mixed>
     */
    public function pause(int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);

        if ('in_progress' !== $session['status']) {
            throw $this->wrongState($session['status'], 'pause');
        }

        $now = $this->now();

        $wpdb->update(
            $wpdb->prefix . 'fc_workout_sessions',
            [
                'duration_seconds' => $this->elapsedSeconds($session, $now),
                'last_resumed_at'  => null,
                'status'           => 'paused',
                'updated_at'       => $now,
            ],
            ['id' => $sessionId]
        );

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * @return array<string,mixed>
     */
    public function resume(int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);

        if ('paused' !== $session['status']) {
            throw $this->wrongState($session['status'], 'resume');
        }

        $now = $this->now();

        $wpdb->update(
            $wpdb->prefix . 'fc_workout_sessions',
            [
                // Wall-clock minus active time — no paused_at column needed, and
                // it self-corrects even if an earlier write was lost.
                'paused_seconds'  => $this->pausedSeconds($session, $now),
                'last_resumed_at' => $now,
                'status'          => 'in_progress',
                'updated_at'      => $now,
            ],
            ['id' => $sessionId]
        );

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * Move the resume pointer (skip / previous / jump).
     *
     * @return array<string,mixed>
     */
    public function moveCursor(int $fcUserId, int $sessionId, int $exerciseIndex, int $setIndex): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);
        $this->assertOpen($session, 'move the cursor in');

        $wpdb->update(
            $wpdb->prefix . 'fc_workout_sessions',
            [
                'current_exercise_index' => max(0, $exerciseIndex),
                'current_set_index'      => max(0, $setIndex),
                'updated_at'             => $this->now(),
            ],
            ['id' => $sessionId]
        );

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * Log (or correct) one completed set.
     *
     * @param array<string,mixed> $payload reps|weight_kg|duration_seconds|rest_taken_seconds|rpe
     * @return array<string,mixed>
     */
    public function logSet(int $fcUserId, int $sessionId, int $exerciseId, int $setIndex, array $payload): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);
        $this->assertOpen($session, 'log sets in');

        if ($setIndex < 0) {
            throw new DomainException(
                'fc_invalid_set_index',
                __('That set index is not valid.', 'fitnessclub'),
                400
            );
        }

        $wpdb->query('START TRANSACTION');

        try {
            $log = $this->exerciseLogFor($sessionId, $exerciseId);
            $now = $this->now();

            $row = [
                'reps'               => $this->intOrNull($payload['reps'] ?? null),
                'weight_kg'          => $this->floatOrNull($payload['weight_kg'] ?? null),
                'duration_seconds'   => $this->intOrNull($payload['duration_seconds'] ?? null),
                'rest_taken_seconds' => $this->intOrNull($payload['rest_taken_seconds'] ?? null),
                'rpe'                => $this->intOrNull($payload['rpe'] ?? null),
                'completed_at'       => $now,
            ];

            $existingId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fc_set_logs
                  WHERE exercise_log_id = %d AND set_index = %d LIMIT 1",
                (int) $log['id'],
                $setIndex
            ));

            if (null === $existingId) {
                $wpdb->insert(
                    $wpdb->prefix . 'fc_set_logs',
                    $row + [
                        'exercise_log_id' => (int) $log['id'],
                        'set_index'       => $setIndex,
                        'created_at'      => $now,
                    ]
                );
            } else {
                // The idempotent half: a retry after a dropped connection
                // corrects the set rather than inventing another one.
                $wpdb->update($wpdb->prefix . 'fc_set_logs', $row, ['id' => (int) $existingId]);
            }

            $this->recomputeExerciseLog((int) $log['id']);
            $this->recomputeCompletion($sessionId);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * Finish the workout and build the celebration payload.
     *
     * @return array<string,mixed>
     */
    public function complete(int $accountId, int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);
        $this->assertOpen($session, 'complete');

        $wpdb->query('START TRANSACTION');

        try {
            $now      = $this->now();
            $duration = $this->elapsedSeconds($session, $now);
            $calories = $this->estimateCalories($fcUserId, (int) $session['workout_id'], $duration);

            $this->recomputeCompletion($sessionId);
            $this->markSkippedExercises($sessionId);

            $wpdb->update(
                $wpdb->prefix . 'fc_workout_sessions',
                [
                    'status'           => 'completed',
                    'ended_at'         => $now,
                    'last_resumed_at'  => null,
                    'duration_seconds' => $duration,
                    'paused_seconds'   => $this->pausedSeconds($session, $now, $duration),
                    'calories_burned'  => $calories,
                    'updated_at'       => $now,
                ],
                ['id' => $sessionId]
            );

            $records = $this->detectPersonalRecords($fcUserId, $sessionId, $now);

            if (!empty($session['user_workout_id'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}fc_user_workouts
                        SET status = 'completed', progress_percentage = 100.00,
                            times_completed = times_completed + 1,
                            last_session_id = %d, updated_at = %s
                      WHERE id = %d",
                    $sessionId,
                    $now,
                    (int) $session['user_workout_id']
                ));
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'session.completed');

        return $this->celebration($accountId, $fcUserId, $sessionId, $records);
    }

    /**
     * "Stop early" — keep the elapsed time and the sets that were logged, drop
     * the pretence that the workout was finished (§8.1).
     *
     * @return array<string,mixed>
     */
    public function abandon(int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);
        $this->assertOpen($session, 'abandon');

        $now      = $this->now();
        $duration = $this->elapsedSeconds($session, $now);

        $wpdb->query('START TRANSACTION');

        try {
            $this->recomputeCompletion($sessionId);
            $this->markSkippedExercises($sessionId);

            $wpdb->update(
                $wpdb->prefix . 'fc_workout_sessions',
                [
                    'status'           => 'abandoned',
                    'ended_at'         => $now,
                    'last_resumed_at'  => null,
                    'duration_seconds' => $duration,
                    'paused_seconds'   => $this->pausedSeconds($session, $now, $duration),
                    'updated_at'       => $now,
                ],
                ['id' => $sessionId]
            );

            // Partial credit: the assignment keeps the progress the user earned,
            // which is what the workout card's percentage shows.
            if (!empty($session['user_workout_id'])) {
                $completion = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT completion_percentage FROM {$wpdb->prefix}fc_workout_sessions WHERE id = %d",
                    $sessionId
                ));

                $wpdb->update(
                    $wpdb->prefix . 'fc_user_workouts',
                    ['progress_percentage' => $completion, 'updated_at' => $now],
                    ['id' => (int) $session['user_workout_id']]
                );
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'session.abandoned');

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * Post-workout adjustment (§8.4): correct what was actually lifted, add
     * notes, rate the session.
     *
     * Corrections re-run PR detection, because "I actually did 85, not 80" is
     * precisely when a record changes.
     *
     * @param array<string,mixed> $payload notes|perceived_exertion|difficulty_rating|sets[]
     * @return array<string,mixed>
     */
    public function review(int $fcUserId, int $sessionId, array $payload): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);

        if (!in_array($session['status'], ['completed', 'abandoned'], true)) {
            throw new DomainException(
                'fc_session_not_finished',
                __('A workout can only be reviewed once it has finished.', 'fitnessclub'),
                409,
                ['session_status' => $session['status']]
            );
        }

        $now = $this->now();

        $wpdb->query('START TRANSACTION');

        try {
            $fields = ['updated_at' => $now];
            if (array_key_exists('notes', $payload)) {
                $fields['notes'] = (string) $payload['notes'];
            }
            if (array_key_exists('perceived_exertion', $payload)) {
                $fields['perceived_exertion'] = $this->intOrNull($payload['perceived_exertion']);
            }
            if (array_key_exists('difficulty_rating', $payload)) {
                $fields['difficulty_rating'] = $this->intOrNull($payload['difficulty_rating']);
            }

            $wpdb->update($wpdb->prefix . 'fc_workout_sessions', $fields, ['id' => $sessionId]);

            $touchedLogs = [];
            foreach ((array) ($payload['sets'] ?? []) as $set) {
                $exerciseId = (int) ($set['exercise_id'] ?? 0);
                $setIndex   = (int) ($set['set_index'] ?? -1);
                if ($exerciseId <= 0 || $setIndex < 0) {
                    continue;
                }

                $log     = $this->exerciseLogFor($sessionId, $exerciseId);
                $logId   = (int) $log['id'];
                $updates = [];

                foreach (['reps', 'duration_seconds', 'rest_taken_seconds', 'rpe'] as $field) {
                    if (array_key_exists($field, $set)) {
                        $updates[$field] = $this->intOrNull($set[$field]);
                    }
                }
                if (array_key_exists('weight_kg', $set)) {
                    $updates['weight_kg'] = $this->floatOrNull($set['weight_kg']);
                }

                if ([] === $updates) {
                    continue;
                }

                $existingId = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fc_set_logs
                      WHERE exercise_log_id = %d AND set_index = %d LIMIT 1",
                    $logId,
                    $setIndex
                ));

                if (null === $existingId) {
                    $wpdb->insert(
                        $wpdb->prefix . 'fc_set_logs',
                        $updates + [
                            'exercise_log_id' => $logId,
                            'set_index'       => $setIndex,
                            'completed_at'    => $now,
                            'created_at'      => $now,
                        ]
                    );
                } else {
                    $wpdb->update($wpdb->prefix . 'fc_set_logs', $updates, ['id' => (int) $existingId]);
                }

                $touchedLogs[$logId] = true;
            }

            foreach (array_keys($touchedLogs) as $logId) {
                $this->recomputeExerciseLog($logId);
            }

            if ([] !== $touchedLogs) {
                $this->recomputeCompletion($sessionId);
                $this->detectPersonalRecords($fcUserId, $sessionId, $now);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'session.reviewed');

        return $this->rehydrate($fcUserId, $sessionId);
    }

    /**
     * Close sessions that were left open — users close the tab mid-workout
     * constantly, and an abandoned session blocks every future start with a 409.
     *
     * The recorded duration is the **accumulated** active time, not the wall
     * clock since the last write: we know the user stopped some time after their
     * last set, and crediting the hours a browser tab sat closed would inflate
     * every duration, calorie and consistency figure downstream.
     *
     * @param int|null $olderThanHours Defaults to config `limits.stale_session_hours`.
     * @return int Sessions closed.
     */
    public function abandonStale(?int $olderThanHours = null): int
    {
        global $wpdb;

        $hours = $olderThanHours ?? (int) FitnessClub()->config('fitnessclub.limits.stale_session_hours', 24);
        $hours = max(1, $hours);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));

        $stale = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_workout_id, duration_seconds, updated_at
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE status IN ('in_progress', 'paused') AND updated_at < %s
              LIMIT 500",
            $cutoff
        ), ARRAY_A);

        foreach ($stale ?: [] as $row) {
            $this->recomputeCompletion((int) $row['id']);

            $wpdb->update(
                $wpdb->prefix . 'fc_workout_sessions',
                [
                    'status'          => 'abandoned',
                    'ended_at'        => $row['updated_at'],
                    'last_resumed_at' => null,
                    'updated_at'      => $this->now(),
                ],
                ['id' => (int) $row['id']]
            );
        }

        return count($stale ?: []);
    }

    // --------------------------------------------------------------- internals

    /**
     * The session, scoped to its owner. A foreign id is a 404, not a 403 — the
     * caller has no business learning that the row exists.
     *
     * @return array<string,mixed>
     */
    private function sessionRow(int $fcUserId, int $sessionId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_workout_sessions WHERE id = %d AND user_id = %d LIMIT 1",
            $sessionId,
            $fcUserId
        ), ARRAY_A);

        if (null === $row) {
            throw new DomainException(
                'fc_not_found',
                __('That workout session was not found.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function openSessionForUpdate(int $fcUserId): ?array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %s list.
        $sql = "SELECT id, workout_id, status FROM {$wpdb->prefix}fc_workout_sessions
                 WHERE user_id = %d AND status IN ({$placeholders})
                 ORDER BY id DESC LIMIT 1 FOR UPDATE";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
        $row = $wpdb->get_row(
            $wpdb->prepare($sql, array_merge([$fcUserId], self::OPEN_STATUSES)),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function exerciseLogFor(int $sessionId, int $exerciseId): array
    {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_exercise_logs
              WHERE session_id = %d AND exercise_id = %d LIMIT 1",
            $sessionId,
            $exerciseId
        ), ARRAY_A);

        if (null === $log) {
            // The session snapshotted its exercises at start; anything else is
            // either a typo or an attempt to graft a foreign exercise onto it.
            throw new DomainException(
                'fc_exercise_not_in_session',
                __('That exercise is not part of this workout session.', 'fitnessclub'),
                404
            );
        }

        return $log;
    }

    /**
     * Summarise one exercise from its set logs. Per-set truth stays in
     * fc_set_logs; these columns are the convenience the history screen reads.
     */
    private function recomputeExerciseLog(int $exerciseLogId): void
    {
        global $wpdb;

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS sets,
                    MAX(reps) AS max_reps,
                    MAX(weight_kg) AS max_weight,
                    COALESCE(SUM(COALESCE(reps, 0) * COALESCE(weight_kg, 0)), 0) AS volume
               FROM {$wpdb->prefix}fc_set_logs
              WHERE exercise_log_id = %d",
            $exerciseLogId
        ), ARRAY_A);

        $wpdb->update(
            $wpdb->prefix . 'fc_exercise_logs',
            [
                'actual_sets'      => (int) $totals['sets'],
                'actual_reps'      => $this->intOrNull($totals['max_reps']),
                'actual_weight_kg' => $this->floatOrNull($totals['max_weight']),
                'total_volume_kg'  => (float) $totals['volume'],
                'was_skipped'      => 0 === (int) $totals['sets'] ? 1 : 0,
                'updated_at'       => $this->now(),
            ],
            ['id' => $exerciseLogId]
        );
    }

    /**
     * Completion is logged sets over planned sets — the number the progress ring
     * and the abandoned-session partial credit both use.
     */
    private function recomputeCompletion(int $sessionId): void
    {
        global $wpdb;

        $planned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(planned_sets), 0) FROM {$wpdb->prefix}fc_exercise_logs WHERE session_id = %d",
            $sessionId
        ));

        $logged = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
              WHERE el.session_id = %d",
            $sessionId
        ));

        $percentage = $planned > 0 ? min(100.0, round(($logged / $planned) * 100, 2)) : 0.0;

        $wpdb->update(
            $wpdb->prefix . 'fc_workout_sessions',
            ['completion_percentage' => $percentage, 'updated_at' => $this->now()],
            ['id' => $sessionId]
        );
    }

    /**
     * An exercise nobody logged a set against was skipped.
     *
     * `recomputeExerciseLog()` only ever runs for exercises that received a set,
     * so an exercise the user walked past keeps the column's `0` default and
     * counts as completed — which is how a celebration screen ends up claiming
     * "8/8 exercises" for a workout where one exercise was done. Settle it once
     * at the end, when "no sets" genuinely means skipped rather than "not yet".
     */
    private function markSkippedExercises(int $sessionId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_exercise_logs el
                SET el.was_skipped = 1
              WHERE el.session_id = %d
                AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}fc_set_logs sl
                       WHERE sl.exercise_log_id = el.id
                    )",
            $sessionId
        ));
    }

    /**
     * Compare this session against the user's records and upsert the winners.
     *
     * `max_volume` is the exercise's **total** volume in one session, not a
     * single set's — that is what "I had a big bench day" means. `best_time` is
     * the longest hold for time-metric exercises (planks, carries); a
     * lower-is-better variant will need its own record type when timed distance
     * work arrives.
     *
     * @return array<int,array<string,mixed>> New records only — the celebration list.
     */
    private function detectPersonalRecords(int $fcUserId, int $sessionId, string $now): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.exercise_name, e.metric,
                    MAX(sl.weight_kg) AS max_weight,
                    MAX(sl.reps) AS max_reps,
                    MAX(sl.duration_seconds) AS max_duration,
                    COALESCE(SUM(COALESCE(sl.reps, 0) * COALESCE(sl.weight_kg, 0)), 0) AS volume
               FROM {$wpdb->prefix}fc_exercise_logs el
               JOIN {$wpdb->prefix}fc_exercises e ON e.id = el.exercise_id
               JOIN {$wpdb->prefix}fc_set_logs sl ON sl.exercise_log_id = el.id
              WHERE el.session_id = %d
              GROUP BY el.id, e.exercise_name, e.metric",
            $sessionId
        ), ARRAY_A);

        $new = [];

        foreach ($rows ?: [] as $row) {
            $name       = (string) $row['exercise_name'];
            $candidates = [];

            if ('seconds' === $row['metric']) {
                $candidates['best_time'] = [(float) $row['max_duration'], 'seconds'];
            } else {
                $candidates['max_weight'] = [(float) $row['max_weight'], 'kg'];
                $candidates['max_reps']   = [(float) $row['max_reps'], 'reps'];
                $candidates['max_volume'] = [(float) $row['volume'], 'kg'];
            }

            foreach ($candidates as $type => [$value, $unit]) {
                if ($value <= 0) {
                    continue;
                }

                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, value FROM {$wpdb->prefix}fc_personal_records
                      WHERE user_id = %d AND exercise_name = %s AND record_type = %s LIMIT 1",
                    $fcUserId,
                    $name,
                    $type
                ), ARRAY_A);

                $previous = null === $existing ? null : (float) $existing['value'];

                // Strictly greater: matching your own record is not a new one.
                if (null !== $previous && $value <= $previous) {
                    continue;
                }

                if (null === $existing) {
                    $wpdb->insert($wpdb->prefix . 'fc_personal_records', [
                        'user_id'       => $fcUserId,
                        'exercise_name' => $name,
                        'record_type'   => $type,
                        'value'         => $value,
                        'unit'          => $unit,
                        'session_id'    => $sessionId,
                        'achieved_at'   => $now,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                } else {
                    $wpdb->update(
                        $wpdb->prefix . 'fc_personal_records',
                        [
                            'value'       => $value,
                            'unit'        => $unit,
                            'session_id'  => $sessionId,
                            'achieved_at' => $now,
                            'updated_at'  => $now,
                        ],
                        ['id' => (int) $existing['id']]
                    );
                }

                $new[] = [
                    'exercise_name'  => $name,
                    'record_type'    => $type,
                    'value'          => $value,
                    'unit'           => $unit,
                    'previous_value' => $previous,
                    'is_new'         => true,
                ];
            }
        }

        return $new;
    }

    /**
     * The celebration payload, plus the side effects a finished workout has:
     * feed entry, notifications, streak.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function celebration(int $accountId, int $fcUserId, int $sessionId, array $records): array
    {
        global $wpdb;

        $session = $this->sessionRow($fcUserId, $sessionId);

        $workoutName = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT workout_name FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
            (int) $session['workout_id']
        ));

        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total_exercises,
                    SUM(CASE WHEN was_skipped = 0 THEN 1 ELSE 0 END) AS completed_exercises,
                    COALESCE(SUM(total_volume_kg), 0) AS volume
               FROM {$wpdb->prefix}fc_exercise_logs WHERE session_id = %d",
            $sessionId
        ), ARRAY_A);

        $day       = (string) $session['log_date'];
        $extended  = $this->streaks->extendedBy($fcUserId, $day, $sessionId);
        $streak    = $this->streaks->current($fcUserId, $day);
        $duration  = (int) $session['duration_seconds'];

        $this->activity->record(
            $accountId,
            'workout.completed',
            $workoutName,
            sprintf(
                /* translators: 1: duration in minutes, 2: calories burned. */
                __('%1$d min · %2$d kcal', 'fitnessclub'),
                (int) round($duration / 60),
                (int) $session['calories_burned']
            ),
            'session',
            $sessionId,
            ['volume_kg' => (float) $totals['volume'], 'streak_days' => $streak]
        );

        $this->notifications->notify(
            $accountId,
            'workout',
            /* translators: %s: workout name. */
            sprintf(__('%s complete', 'fitnessclub'), $workoutName),
            sprintf(
                /* translators: 1: duration in minutes, 2: calories burned. */
                __('Nice work — %1$d minutes, %2$d kcal.', 'fitnessclub'),
                (int) round($duration / 60),
                (int) $session['calories_burned']
            ),
            null,
            'fa-dumbbell',
            'accent',
            ['session_id' => $sessionId]
        );

        foreach ($records as $record) {
            $this->activity->record(
                $accountId,
                'pr.achieved',
                /* translators: %s: exercise name. */
                sprintf(__('New personal record: %s', 'fitnessclub'), $record['exercise_name']),
                sprintf('%s %s', $this->trimNumber($record['value']), $record['unit']),
                'session',
                $sessionId,
                $record
            );

            $this->notifications->notify(
                $accountId,
                'achievement',
                __('New personal record', 'fitnessclub'),
                sprintf(
                    /* translators: 1: exercise name, 2: value with unit. */
                    __('%1$s — %2$s', 'fitnessclub'),
                    $record['exercise_name'],
                    $this->trimNumber($record['value']) . ' ' . $record['unit']
                ),
                null,
                'fa-trophy',
                'accent2',
                $record
            );
        }

        return [
            'session_id'            => $sessionId,
            'workout_name'          => $workoutName,
            'duration_seconds'      => $duration,
            'calories_burned'       => (int) $session['calories_burned'],
            'exercises_completed'   => (int) $totals['completed_exercises'],
            'total_exercises'       => (int) $totals['total_exercises'],
            'completion_percentage' => (float) $session['completion_percentage'],
            'total_volume_kg'       => (float) $totals['volume'],
            'personal_records'      => $records,
            'streak_days'           => $streak,
            'streak_extended'       => $extended,
        ];
    }

    /**
     * MET × body weight × hours — the standard estimate, and a long way from the
     * prototype's `elapsedSeconds * 6.5`, which gave every user on every workout
     * the same number.
     */
    private function estimateCalories(int $fcUserId, int $workoutId, int $durationSeconds): int
    {
        global $wpdb;

        if ($durationSeconds <= 0) {
            return 0;
        }

        $type = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT workout_type FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
            $workoutId
        ));

        $weight = $wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fcUserId
        ));

        $metValues = (array) FitnessClub()->config('fitnessclub.met_values', []);
        $met       = (float) ($metValues[$type] ?? $metValues['default'] ?? 5.0);
        $bodyWeight = (float) ($weight ?: FitnessClub()->config('fitnessclub.defaults.body_weight_kg', 70));

        return (int) round($met * $bodyWeight * ($durationSeconds / 3600));
    }

    /**
     * @param array<string,mixed> $session
     */
    private function elapsedSeconds(array $session, string $now): int
    {
        $accumulated = (int) $session['duration_seconds'];

        if ('in_progress' !== $session['status'] || empty($session['last_resumed_at'])) {
            return $accumulated;
        }

        $since = $this->toTimestamp($now) - $this->toTimestamp((string) $session['last_resumed_at']);

        return $accumulated + max(0, $since);
    }

    /**
     * Paused time = wall clock − active time. Derived rather than stored, so it
     * cannot drift out of agreement with the duration.
     *
     * @param array<string,mixed> $session
     */
    private function pausedSeconds(array $session, string $now, ?int $activeSeconds = null): int
    {
        $active = $activeSeconds ?? (int) $session['duration_seconds'];
        $wall   = $this->toTimestamp($now) - $this->toTimestamp((string) $session['started_at']);

        return max(0, $wall - $active);
    }

    /**
     * @param array<string,mixed> $session
     */
    private function assertOpen(array $session, string $verb): void
    {
        if (!in_array($session['status'], self::OPEN_STATUSES, true)) {
            throw $this->wrongState($session['status'], $verb);
        }
    }

    private function wrongState(string $status, string $verb): DomainException
    {
        return new DomainException(
            'fc_session_state_conflict',
            sprintf(
                /* translators: 1: attempted action, 2: current session status. */
                __('You cannot %1$s a session that is %2$s.', 'fitnessclub'),
                $verb,
                $status
            ),
            409,
            ['session_status' => $status]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentSession(array $row): array
    {
        $elapsed = $this->elapsedSeconds($row, $this->now());

        return [
            'id'                     => (int) $row['id'],
            'workout_id'             => (int) $row['workout_id'],
            'workout_name'           => $row['workout_name'] ?? null,
            'workout_type'           => $row['workout_type'] ?? null,
            'status'                 => $row['status'],
            'log_date'               => $row['log_date'],
            'started_at'             => $this->iso($row['started_at'] ?? null),
            'ended_at'               => $this->iso($row['ended_at'] ?? null),
            'last_resumed_at'        => $this->iso($row['last_resumed_at'] ?? null),
            // duration_seconds is the stored, authoritative active time;
            // elapsed_seconds includes the leg currently running, which is what
            // the player's timer should be seeded from.
            'duration_seconds'       => (int) $row['duration_seconds'],
            'elapsed_seconds'        => $elapsed,
            'paused_seconds'         => (int) $row['paused_seconds'],
            'current_exercise_index' => (int) $row['current_exercise_index'],
            'current_set_index'      => (int) $row['current_set_index'],
            'completion_percentage'  => (float) $row['completion_percentage'],
            'calories_burned'        => null === $row['calories_burned'] ? null : (int) $row['calories_burned'],
            'perceived_exertion'     => null === $row['perceived_exertion'] ? null : (int) $row['perceived_exertion'],
            'difficulty_rating'      => null === $row['difficulty_rating'] ? null : (int) $row['difficulty_rating'],
            'notes'                  => $row['notes'],
            'total_volume_kg'        => isset($row['total_volume_kg']) ? (float) $row['total_volume_kg'] : null,
        ];
    }

    /**
     * Logged sets grouped by exercise log id.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function setsBySessionLog(int $sessionId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sl.*
               FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
              WHERE el.session_id = %d
              ORDER BY sl.exercise_log_id ASC, sl.set_index ASC",
            $sessionId
        ), ARRAY_A);

        $grouped = [];
        foreach ($rows ?: [] as $row) {
            $grouped[(int) $row['exercise_log_id']][] = [
                'set_index'          => (int) $row['set_index'],
                'reps'               => $this->intOrNull($row['reps']),
                'weight_kg'          => $this->floatOrNull($row['weight_kg']),
                'duration_seconds'   => $this->intOrNull($row['duration_seconds']),
                'rest_taken_seconds' => $this->intOrNull($row['rest_taken_seconds']),
                'rpe'                => $this->intOrNull($row['rpe']),
                'completed_at'       => $this->iso($row['completed_at']),
            ];
        }

        return $grouped;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Say that this member's numbers moved, and let whoever cares react.
     *
     * Fired **after** the commit, never inside it: a listener that clears a
     * cache before the transaction lands would repopulate it from the old rows,
     * which is the one ordering guaranteed to produce a stale read.
     *
     * Today the only listener is the dashboard cache (Support\DashboardCache).
     * Nutrition, health and messaging fire the same action in Phase 2.
     */
    private function announce(int $fcUserId, string $reason): void
    {
        do_action('fitnessclub/user_data_changed', $fcUserId, $reason);
    }

    private function toTimestamp(string $mysqlUtc): int
    {
        return (int) strtotime($mysqlUtc . ' UTC');
    }

    /**
     * @param mixed $value
     */
    private function iso($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return gmdate('c', $this->toTimestamp((string) $value));
    }

    /**
     * @param mixed $value
     */
    private function intOrNull($value): ?int
    {
        return null === $value || '' === $value ? null : (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function floatOrNull($value): ?float
    {
        return null === $value || '' === $value ? null : (float) $value;
    }

    /**
     * 80.00 → "80", 82.50 → "82.5" — record values read as numbers, not as
     * accounting figures.
     */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
