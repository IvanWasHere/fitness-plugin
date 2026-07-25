<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads over `fc_workouts` / `fc_exercises` / `fc_user_workouts`
 * (plans/02-api-contract.md#workouts-workouts-sessions).
 *
 * ## Everything is scoped through the assignment
 *
 * A user sees a workout because it was **assigned** to them — there is a
 * `fc_user_workouts` row. Nothing here reads `fc_workouts` without joining that
 * row, so a user cannot walk another trainer's programming by guessing ids, and
 * the per-user progress the cards show comes back in the same query.
 *
 * Self-serve browsing of a public template library is a later decision; when it
 * arrives it widens the join, it does not remove it.
 */
final class WorkoutService
{
    /** Assignment statuses a member can act on; archived is hidden. */
    private const VISIBLE_STATUSES = ['assigned', 'in_progress', 'completed'];

    /**
     * The Workouts list: the user's assignments, filtered and paginated.
     *
     * @param array<string,mixed> $filters status|difficulty|type|q|page|per_page
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function listForUser(int $fcUserId, array $filters = []): array
    {
        global $wpdb;

        $limits  = (array) FitnessClub()->config('fitnessclub.limits', []);
        $perPage = (int) ($filters['per_page'] ?? $limits['per_page_default'] ?? 20);
        $perPage = max(1, min((int) ($limits['per_page_max'] ?? 100), $perPage));
        $page    = max(1, (int) ($filters['page'] ?? 1));

        // Every fragment is a placeholder expression; only the fragment *shape*
        // is built here, never a value.
        $where  = ['uw.user_id = %d', 'w.is_active = 1'];
        $values = [$fcUserId];

        if (!empty($filters['status'])) {
            $where[]  = 'uw.status = %s';
            $values[] = (string) $filters['status'];
        } else {
            $where[]  = 'uw.status IN (' . implode(',', array_fill(0, count(self::VISIBLE_STATUSES), '%s')) . ')';
            $values   = array_merge($values, self::VISIBLE_STATUSES);
        }

        if (!empty($filters['difficulty'])) {
            $where[]  = 'w.difficulty = %s';
            $values[] = (string) $filters['difficulty'];
        }

        if (!empty($filters['type'])) {
            $where[]  = 'w.workout_type = %s';
            $values[] = (string) $filters['type'];
        }

        if (!empty($filters['q'])) {
            $where[]  = '(w.workout_name LIKE %s OR w.description LIKE %s)';
            $like     = '%' . $wpdb->esc_like((string) $filters['q']) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is placeholders only; values are bound below.
        $countSql = "SELECT COUNT(*)
                       FROM {$wpdb->prefix}fc_user_workouts uw
                       JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
                      WHERE {$clause}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, $values));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
        $sql = "SELECT w.*, uw.id AS assignment_id, uw.status AS assignment_status,
                       uw.progress_percentage, uw.times_completed, uw.scheduled_for,
                       uw.last_session_id,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercises e WHERE e.workout_id = w.id) AS exercise_count
                  FROM {$wpdb->prefix}fc_user_workouts uw
                  JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
                 WHERE {$clause}
                 ORDER BY uw.scheduled_for IS NULL, uw.scheduled_for ASC, w.workout_name ASC
                 LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge($values, [$perPage, ($page - 1) * $perPage])),
            ARRAY_A
        );

        $resumable = $this->openSessionsByWorkout($fcUserId);

        return [
            'items'    => array_map(
                fn(array $row): array => $this->presentWorkout($row, $resumable[(int) $row['id']] ?? null),
                $rows ?: []
            ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Workout detail with its exercises in order.
     *
     * @return array<string,mixed>
     */
    public function detailForUser(int $fcUserId, int $workoutId): array
    {
        $row = $this->assignedWorkoutRow($fcUserId, $workoutId);

        $resumable = $this->openSessionsByWorkout($fcUserId);
        $workout   = $this->presentWorkout($row, $resumable[$workoutId] ?? null);

        $workout['exercises'] = array_map(
            fn(array $exercise): array => $this->presentExercise($exercise),
            $this->exercisesFor($workoutId)
        );

        return $workout;
    }

    /**
     * One exercise, reachable only through a workout the caller was assigned.
     *
     * @return array<string,mixed>
     */
    public function exerciseForUser(int $fcUserId, int $exerciseId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*
               FROM {$wpdb->prefix}fc_exercises e
               JOIN {$wpdb->prefix}fc_user_workouts uw ON uw.workout_id = e.workout_id
              WHERE e.id = %d AND uw.user_id = %d
              LIMIT 1",
            $exerciseId,
            $fcUserId
        ), ARRAY_A);

        if (null === $row) {
            // Same answer for "no such exercise" and "not yours": an id prober
            // learns nothing either way.
            throw new DomainException(
                'fc_not_found',
                __('That exercise was not found.', 'fitnessclub'),
                404
            );
        }

        return $this->presentExercise($row);
    }

    /**
     * The workout row plus the caller's assignment, or 404. Shared by detail and
     * by WorkoutSessionService::start().
     *
     * @return array<string,mixed>
     */
    public function assignedWorkoutRow(int $fcUserId, int $workoutId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT w.*, uw.id AS assignment_id, uw.status AS assignment_status,
                    uw.progress_percentage, uw.times_completed, uw.scheduled_for, uw.last_session_id,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercises e WHERE e.workout_id = w.id) AS exercise_count
               FROM {$wpdb->prefix}fc_user_workouts uw
               JOIN {$wpdb->prefix}fc_workouts w ON w.id = uw.workout_id
              WHERE uw.user_id = %d AND w.id = %d
              LIMIT 1",
            $fcUserId,
            $workoutId
        ), ARRAY_A);

        if (null === $row) {
            throw new DomainException(
                'fc_workout_not_assigned',
                __('That workout is not in your programme.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * A workout's exercises, ordered as the trainer arranged them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function exercisesFor(int $workoutId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_exercises
              WHERE workout_id = %d
              ORDER BY order_index ASC, id ASC",
            $workoutId
        ), ARRAY_A) ?: [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function presentWorkout(array $row, ?int $resumableSessionId = null): array
    {
        return [
            'id'                        => (int) $row['id'],
            'workout_name'              => $row['workout_name'],
            'slug'                      => $row['slug'],
            'description'               => $row['description'],
            'workout_type'              => $row['workout_type'],
            'difficulty'                => $row['difficulty'],
            'estimated_duration_minutes' => (int) $row['estimated_duration_minutes'],
            'calories_burn_estimate'    => null === $row['calories_burn_estimate']
                ? null
                : (int) $row['calories_burn_estimate'],
            'muscle_groups'             => $this->decodeList($row['muscle_groups'] ?? null),
            'equipment'                 => $this->decodeList($row['equipment'] ?? null),
            'cover_image_url'           => $row['cover_image_url'],
            'video_url'                 => $row['video_url'],
            'exercise_count'            => (int) ($row['exercise_count'] ?? 0),
            'progress_percentage'       => (float) ($row['progress_percentage'] ?? 0),
            'times_completed'           => (int) ($row['times_completed'] ?? 0),
            'status'                    => $row['assignment_status'] ?? null,
            'scheduled_for'             => $row['scheduled_for'] ?? null,
            'last_session_id'           => empty($row['last_session_id']) ? null : (int) $row['last_session_id'],
            'resumable_session_id'      => $resumableSessionId,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function presentExercise(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'workout_id'           => (int) $row['workout_id'],
            'exercise_name'        => $row['exercise_name'],
            'exercise_type'        => $row['exercise_type'],
            'muscle_groups'        => $this->decodeList($row['muscle_groups'] ?? null),
            'instructions'         => $row['instructions'],
            'notes'                => $row['notes'],
            'video_url'            => $row['video_url'],
            'thumbnail_url'        => $row['thumbnail_url'],
            'default_sets'         => (int) $row['default_sets'],
            'default_reps'         => (int) $row['default_reps'],
            'default_weight_kg'    => (float) $row['default_weight_kg'],
            'default_rest_seconds' => (int) $row['default_rest_seconds'],
            // reps | seconds | distance — the player renders a different control
            // per metric, and "3 × 60" means minutes of plank, not sixty reps.
            'metric'               => $row['metric'],
            'order_index'          => (int) $row['order_index'],
        ];
    }

    /**
     * Open sessions keyed by workout id, so a list can offer "resume" without a
     * query per card.
     *
     * @return array<int,int>
     */
    private function openSessionsByWorkout(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT workout_id, id FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status IN ('in_progress', 'paused')
              ORDER BY id ASC",
            $fcUserId
        ), ARRAY_A);

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row['workout_id']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * JSON list columns are stored as text; a malformed value degrades to an
     * empty list rather than breaking the screen that renders it.
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function decodeList($value): array
    {
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
