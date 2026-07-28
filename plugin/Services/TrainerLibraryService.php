<?php

namespace FitnessClub\Services;

use FitnessClub\Services\Admin\AdminResourceService;
use FitnessClub\Support\DomainException;
use FitnessClub\Support\Guard;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * A trainer's own library: workouts, plans, food plans, profile (W3.4).
 *
 * ## Authorship is the whole access rule here
 *
 * Unlike the client record — which every assigned trainer may read (Q13) — a
 * trainer's library is theirs. Every list is scoped by `trainer_id` and every
 * write goes through `Guard::trainerAssignedResource`, so a trainer can neither
 * see nor edit another's drafts.
 *
 * The one deliberate exception is **reading a workout in order to assign it**:
 * `TrainerService::assignWorkout` accepts any active workout, because the
 * platform's own library (`trainer_id IS NULL`) is what most assignments come
 * from and a trainer with no workouts of their own must still be able to
 * programme.
 *
 * ## The exercise list is replaced whole
 *
 * `PUT /trainer/workouts/{id}/exercises` takes the entire ordered collection in
 * one call rather than exposing per-exercise CRUD. That matches the builder —
 * drag to reorder, then save — and avoids the partial-save corruption a
 * sequence of individual writes produces when one of them fails. It reuses the
 * same **upsert-by-id** the admin editor does, so historical
 * `fc_exercise_logs` keep pointing at the movements they recorded.
 */
final class TrainerLibraryService
{
    /**
     * `GET /trainer/workouts` — the trainer's own, plus the platform library.
     *
     * Both, because a trainer assigning "Upper Body Power" from the shared
     * library is the common case; showing only their own drafts would make the
     * builder look empty on day one.
     *
     * @return array<string,mixed>
     */
    public function workouts(int $trainerAccountId, bool $mineOnly = false): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $rows = $mineOnly
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT w.*, (SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercises e
                               WHERE e.workout_id = w.id) AS exercise_count
                   FROM {$wpdb->prefix}fc_workouts w
                  WHERE w.trainer_id = %d
                  ORDER BY w.id DESC",
                $trainerId
            ), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT w.*, (SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercises e
                               WHERE e.workout_id = w.id) AS exercise_count
                   FROM {$wpdb->prefix}fc_workouts w
                  WHERE w.is_active = 1 AND (w.trainer_id = %d OR w.trainer_id IS NULL)
                  ORDER BY (w.trainer_id = %d) DESC, w.id DESC",
                $trainerId,
                $trainerId
            ), ARRAY_A);

        return [
            'items' => array_map(fn(array $row): array => $this->presentWorkout($row, $trainerId), $rows ?: []),
        ];
    }

    /**
     * `GET /trainer/workouts/{id}` — with its exercises.
     *
     * @return array<string,mixed>
     */
    public function workout(int $trainerAccountId, int $workoutId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
            $workoutId
        ), ARRAY_A);

        // Readable if it is theirs or the platform's. Another trainer's private
        // draft is a 404.
        $readable = is_array($row)
            && (null === $row['trainer_id'] || (int) $row['trainer_id'] === $trainerId);

        if (!$readable) {
            throw new DomainException(
                'fc_workout_not_found',
                __('That workout does not exist.', 'fitnessclub'),
                404
            );
        }

        $workout              = $this->presentWorkout($row, $trainerId);
        $workout['exercises'] = $this->exercises($workoutId);

        return $workout;
    }

    /**
     * `POST /trainer/workouts`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createWorkout(int $trainerAccountId, array $payload): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);
        $name      = trim(sanitize_text_field((string) ($payload['workout_name'] ?? '')));

        if ('' === $name) {
            throw new DomainException(
                'fc_workout_name_required',
                __('A workout needs a name.', 'fitnessclub'),
                400
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_workouts', $this->workoutFields($payload) + [
            'workout_name' => $name,
            // Stamped from the session, never from the payload: a trainer
            // cannot author a workout into somebody else's library.
            'trainer_id'   => $trainerId,
            'is_active'    => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $workoutId = (int) $wpdb->insert_id;

        if (isset($payload['exercises'])) {
            $this->replaceExercises($workoutId, (array) $payload['exercises']);
        }

        return $this->workout($trainerAccountId, $workoutId);
    }

    /**
     * `PUT /trainer/workouts/{id}` — author only.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateWorkout(int $trainerAccountId, int $workoutId, array $payload): array
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'workout', $workoutId);

        $fields = $this->workoutFields($payload);

        if (isset($payload['workout_name'])) {
            $name = trim(sanitize_text_field((string) $payload['workout_name']));

            if ('' !== $name) {
                $fields['workout_name'] = $name;
            }
        }

        $fields['updated_at'] = gmdate('Y-m-d H:i:s');

        $wpdb->update($wpdb->prefix . 'fc_workouts', $fields, ['id' => $workoutId]);

        if (isset($payload['exercises'])) {
            $this->replaceExercises($workoutId, (array) $payload['exercises']);
        }

        return $this->workout($trainerAccountId, $workoutId);
    }

    /**
     * `PUT /trainer/workouts/{id}/exercises` — the whole ordered list, at once.
     *
     * @param array<int,mixed> $exercises
     * @return array<string,mixed>
     */
    public function replaceWorkoutExercises(int $trainerAccountId, int $workoutId, array $exercises): array
    {
        $this->assertAuthored($trainerAccountId, 'workout', $workoutId);

        $this->replaceExercises($workoutId, $exercises);

        return $this->workout($trainerAccountId, $workoutId);
    }

    /**
     * `DELETE /trainer/workouts/{id}` — author only, and never if it has history.
     */
    public function deleteWorkout(int $trainerAccountId, int $workoutId): void
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'workout', $workoutId);

        $sessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions WHERE workout_id = %d",
            $workoutId
        ));

        if ($sessions > 0) {
            // Same rule the admin table enforces: somebody's training history
            // references this, and deleting it would empty their charts.
            throw new DomainException(
                'fc_workout_has_sessions',
                __('Clients have logged sessions against this workout. Deactivate it instead.', 'fitnessclub'),
                409
            );
        }

        $wpdb->delete($wpdb->prefix . 'fc_workouts', ['id' => $workoutId]);
    }

    // ------------------------------------------------------------ plans

    /**
     * `GET /trainer/plans` — the trainer's own priced plans.
     *
     * @return array<string,mixed>
     */
    public function plans(int $trainerAccountId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, (SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriptions s
                           WHERE s.plan_id = p.id AND s.status = 'active') AS active_subscribers
               FROM {$wpdb->prefix}fc_plans p
              WHERE p.trainer_id = %d
              ORDER BY p.sort_order ASC, p.id DESC",
            $trainerId
        ), ARRAY_A) ?: [];

        return ['items' => array_map(static function (array $row): array {
            $features = json_decode((string) $row['features'], true);

            return [
                'id'          => (int) $row['id'],
                'plan_name'   => $row['plan_name'],
                'description' => $row['description'],
                'plan_type'   => $row['plan_type'],
                'difficulty'  => $row['difficulty'],
                'currency'    => $row['currency'],
                // Every price tier the editor can write, including the weekly
                // one. A form that can set a field it cannot read back blanks it
                // on the next save — the round trip has to be closed on both
                // sides or not opened at all.
                'price_weekly'    => null === $row['price_weekly'] ? null : round((float) $row['price_weekly'], 2),
                'price_monthly'   => null === $row['price_monthly'] ? null : round((float) $row['price_monthly'], 2),
                'price_quarterly' => null === $row['price_quarterly'] ? null : round((float) $row['price_quarterly'], 2),
                'price_yearly'    => null === $row['price_yearly'] ? null : round((float) $row['price_yearly'], 2),
                'weekly_sessions' => null === $row['weekly_sessions'] ? null : (int) $row['weekly_sessions'],
                'duration_weeks'  => null === $row['duration_weeks'] ? null : (int) $row['duration_weeks'],
                'max_messages_per_week' => (int) $row['max_messages_per_week'],
                'sort_order'  => (int) $row['sort_order'],
                'is_active'   => (bool) $row['is_active'],
                'active_subscribers' => (int) $row['active_subscribers'],
                // Read-only, and returned precisely *because* it is not
                // writable: `planFields()` refuses `features` and `max_trainers`
                // so a trainer cannot grant themselves platform entitlements. A
                // screen that could not show them either would leave the trainer
                // guessing what they are selling.
                'features'     => is_array($features) ? $features : [],
                'max_trainers' => (int) $row['max_trainers'],
            ];
        }, $rows)];
    }

    /**
     * `POST /trainer/plans`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPlan(int $trainerAccountId, array $payload): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);
        $name      = trim(sanitize_text_field((string) ($payload['plan_name'] ?? '')));

        if ('' === $name) {
            throw new DomainException(
                'fc_plan_name_required',
                __('A plan needs a name.', 'fitnessclub'),
                400
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_plans', $this->planFields($payload) + [
            'plan_name'  => $name,
            // A trainer's plan is always a trainer plan, whatever the payload
            // says. `owner_type` decides who collects the money (Q2) and is not
            // the seller's to choose.
            'owner_type' => 'trainer',
            'trainer_id' => $trainerId,
            'slug'       => sanitize_title($name . '-' . wp_generate_password(6, false)),
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $wpdb->insert_id];
    }

    /**
     * `PUT /trainer/plans/{id}` — author only.
     *
     * @param array<string,mixed> $payload
     */
    public function updatePlan(int $trainerAccountId, int $planId, array $payload): void
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'plan', $planId);

        $fields = $this->planFields($payload);

        if (isset($payload['plan_name'])) {
            $name = trim(sanitize_text_field((string) $payload['plan_name']));

            if ('' !== $name) {
                $fields['plan_name'] = $name;
            }
        }

        if (isset($payload['is_active'])) {
            $fields['is_active'] = (int) (bool) $payload['is_active'];
        }

        $fields['updated_at'] = gmdate('Y-m-d H:i:s');

        $wpdb->update($wpdb->prefix . 'fc_plans', $fields, ['id' => $planId]);
    }

    /**
     * `DELETE /trainer/plans/{id}` — author only, and never while sold.
     */
    public function deletePlan(int $trainerAccountId, int $planId): void
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'plan', $planId);

        $active = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriptions
              WHERE plan_id = %d AND status IN ('active', 'trialing')",
            $planId
        ));

        if ($active > 0) {
            // Deleting a plan somebody is paying for would orphan their
            // subscription and, with it, their entitlements.
            throw new DomainException(
                'fc_plan_has_subscribers',
                __('Clients are subscribed to this plan. Take it off sale instead of deleting it.', 'fitnessclub'),
                409
            );
        }

        $wpdb->delete($wpdb->prefix . 'fc_plans', ['id' => $planId]);
    }

    // -------------------------------------------------------- food plans

    /**
     * `GET /trainer/food-plans`.
     *
     * @return array<string,mixed>
     */
    public function foodPlans(int $trainerAccountId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_food_plans WHERE trainer_id = %d ORDER BY id DESC",
            $trainerId
        ), ARRAY_A) ?: [];

        return ['items' => array_map(static fn(array $row): array => [
            'id'             => (int) $row['id'],
            'plan_name'      => $row['plan_name'],
            'description'    => $row['description'],
            'daily_calories' => null === $row['daily_calories'] ? null : (int) $row['daily_calories'],
            'meal_count'     => null === $row['meal_count'] ? null : (int) $row['meal_count'],
            'is_active'      => (bool) $row['is_active'],
        ], $rows)];
    }

    /**
     * `POST /trainer/food-plans`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createFoodPlan(int $trainerAccountId, array $payload): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);
        $name      = trim(sanitize_text_field((string) ($payload['plan_name'] ?? '')));

        if ('' === $name) {
            throw new DomainException(
                'fc_plan_name_required',
                __('A food plan needs a name.', 'fitnessclub'),
                400
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_food_plans', [
            'trainer_id'     => $trainerId,
            'plan_name'      => $name,
            'description'    => $this->textarea($payload['description'] ?? null),
            'daily_calories' => isset($payload['daily_calories']) ? (int) $payload['daily_calories'] : null,
            'meal_count'     => isset($payload['meal_count']) ? (int) $payload['meal_count'] : null,
            'is_active'      => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return ['id' => (int) $wpdb->insert_id];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateFoodPlan(int $trainerAccountId, int $foodPlanId, array $payload): void
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'food_plan', $foodPlanId);

        $fields = ['updated_at' => gmdate('Y-m-d H:i:s')];

        foreach (['plan_name' => 'text', 'description' => 'textarea'] as $key => $type) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = 'text' === $type
                    ? sanitize_text_field((string) $payload[$key])
                    : $this->textarea($payload[$key]);
            }
        }

        foreach (['daily_calories', 'meal_count'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = null === $payload[$key] ? null : (int) $payload[$key];
            }
        }

        if (array_key_exists('is_active', $payload)) {
            $fields['is_active'] = (int) (bool) $payload['is_active'];
        }

        $wpdb->update($wpdb->prefix . 'fc_food_plans', $fields, ['id' => $foodPlanId]);
    }

    public function deleteFoodPlan(int $trainerAccountId, int $foodPlanId): void
    {
        global $wpdb;

        $this->assertAuthored($trainerAccountId, 'food_plan', $foodPlanId);

        $assigned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_food_plans
              WHERE food_plan_id = %d AND status = 'active'",
            $foodPlanId
        ));

        if ($assigned > 0) {
            throw new DomainException(
                'fc_food_plan_in_use',
                __('Clients are following this plan. Deactivate it instead of deleting it.', 'fitnessclub'),
                409
            );
        }

        $wpdb->delete($wpdb->prefix . 'fc_food_plans', ['id' => $foodPlanId]);
    }

    // ----------------------------------------------------------- profile

    /**
     * `GET /trainer/profile`.
     *
     * @return array<string,mixed>
     */
    public function profile(int $trainerAccountId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, a.email,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers ut
                      WHERE ut.trainer_id = t.id AND ut.status = 'active') AS client_count
               FROM {$wpdb->prefix}fc_trainers t
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = t.account_id
              WHERE t.id = %d",
            $trainerId
        ), ARRAY_A);

        return [
            'trainer_id'     => (int) $row['id'],
            'display_name'   => $row['display_name'],
            'email'          => $row['email'],
            'bio'            => $row['bio'],
            'specialization' => $row['specialization'],
            'avatar_url'     => $row['avatar_url'],
            'phone'          => $row['phone'],
            // Display-only (Q2): trainers are traced, not paid. Editable
            // because it appears on the public profile.
            'hourly_rate'    => null === $row['hourly_rate'] ? null : round((float) $row['hourly_rate'], 2),
            'currency'       => $row['currency'],
            // Computed from client feedback, never typed in.
            'rating'         => null === $row['rating'] ? null : round((float) $row['rating'], 1),
            'rating_count'   => (int) $row['rating_count'],
            'max_clients'    => (int) $row['max_clients'],
            'accepting_clients' => (bool) $row['accepting_clients'],
            'client_count'   => (int) $row['client_count'],
            'status'         => $row['status'],
        ];
    }

    /**
     * `PUT /trainer/profile`.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateProfile(int $trainerAccountId, array $payload): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);
        $fields    = ['updated_at' => gmdate('Y-m-d H:i:s')];

        foreach (['display_name', 'specialization', 'phone'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = sanitize_text_field((string) $payload[$key]);
            }
        }

        if (array_key_exists('bio', $payload)) {
            $fields['bio'] = $this->textarea($payload['bio']);
        }

        if (array_key_exists('avatar_url', $payload)) {
            $fields['avatar_url'] = esc_url_raw((string) $payload['avatar_url']) ?: null;
        }

        if (array_key_exists('hourly_rate', $payload)) {
            $fields['hourly_rate'] = null === $payload['hourly_rate']
                ? null
                : round(max(0, (float) $payload['hourly_rate']), 2);
        }

        if (array_key_exists('max_clients', $payload)) {
            $fields['max_clients'] = max(0, (int) $payload['max_clients']);
        }

        if (array_key_exists('accepting_clients', $payload)) {
            $fields['accepting_clients'] = (int) (bool) $payload['accepting_clients'];
        }

        // `rating`, `rating_count` and `status` are absent by design: a rating a
        // trainer can set is not a rating, and account status is an
        // administrator's decision.
        $wpdb->update($wpdb->prefix . 'fc_trainers', $fields, ['id' => $trainerId]);

        return $this->profile($trainerAccountId);
    }

    // --------------------------------------------------------- dashboard

    /**
     * `GET /trainer/dashboard` — one request for the overview.
     *
     * @return array<string,mixed>
     */
    public function dashboard(int $trainerAccountId): array
    {
        global $wpdb;

        $trainerId = $this->trainerId($trainerAccountId);
        $weekAgo   = gmdate('Y-m-d', strtotime('-6 days'));
        $today     = gmdate('Y-m-d');

        return [
            'generated_at' => gmdate('c'),
            'counts' => [
                'active_clients' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
                      WHERE trainer_id = %d AND status = 'active'",
                    $trainerId
                )),
                'pending_requests' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
                      WHERE trainer_id = %d AND status = 'pending'",
                    $trainerId
                )),
                'workouts_authored' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workouts WHERE trainer_id = %d",
                    $trainerId
                )),
                'sessions_this_week' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions s
                       JOIN {$wpdb->prefix}fc_user_trainers ut ON ut.user_id = s.user_id
                      WHERE ut.trainer_id = %d AND ut.status = 'active'
                        AND s.status = 'completed' AND s.log_date BETWEEN %s AND %s",
                    $trainerId,
                    $weekAgo,
                    $today
                )),
            ],
            // The list a trainer can act on, rather than a number they cannot:
            // clients who have not trained in a fortnight are the ones to
            // message today.
            'needs_attention' => $this->needsAttention($trainerId),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function needsAttention(int $trainerId): array
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d', strtotime('-14 days'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT u.id AS user_id, u.display_name,
                    (SELECT MAX(s.log_date) FROM {$wpdb->prefix}fc_workout_sessions s
                      WHERE s.user_id = u.id AND s.status = 'completed') AS last_session_date
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_users u ON u.id = ut.user_id
              WHERE ut.trainer_id = %d AND ut.status = 'active'
             HAVING last_session_date IS NULL OR last_session_date < %s
              ORDER BY last_session_date ASC
              LIMIT 10",
            $trainerId,
            $cutoff
        ), ARRAY_A) ?: [];

        return array_map(static fn(array $row): array => [
            'user_id'      => (int) $row['user_id'],
            'display_name' => $row['display_name'],
            'last_session_date' => $row['last_session_date'],
        ], $rows);
    }

    // -------------------------------------------------------------- internals

    /**
     * Replace a workout's exercises, preserving ids.
     *
     * Delegated to the admin service rather than reimplemented: the
     * upsert-by-id rule that keeps `fc_exercise_logs` pointing at the right
     * movements is subtle enough that a second copy would eventually drift from
     * the first. See its docblock for why delete-and-reinsert is wrong.
     *
     * @param array<int,mixed> $exercises
     */
    private function replaceExercises(int $workoutId, array $exercises): void
    {
        (new AdminResourceService())->update(
            'workouts',
            $workoutId,
            ['exercises' => $exercises],
            0
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function exercises(int $workoutId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_exercises WHERE workout_id = %d
              ORDER BY order_index ASC, id ASC",
            $workoutId
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            $muscles = json_decode((string) $row['muscle_groups'], true);

            return [
                'id'                   => (int) $row['id'],
                'exercise_name'        => $row['exercise_name'],
                'exercise_type'        => $row['exercise_type'],
                'muscle_groups'        => is_array($muscles) ? $muscles : [],
                'instructions'         => $row['instructions'],
                'notes'                => $row['notes'],
                'video_url'            => $row['video_url'],
                'default_sets'         => (int) $row['default_sets'],
                'default_reps'         => (int) $row['default_reps'],
                'default_weight_kg'    => round((float) $row['default_weight_kg'], 2),
                'default_rest_seconds' => (int) $row['default_rest_seconds'],
                'metric'               => $row['metric'],
                'order_index'          => (int) $row['order_index'],
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentWorkout(array $row, int $trainerId): array
    {
        $muscles   = json_decode((string) $row['muscle_groups'], true);
        $equipment = json_decode((string) $row['equipment'], true);

        return [
            'id'           => (int) $row['id'],
            'workout_name' => $row['workout_name'],
            'description'  => $row['description'],
            'workout_type' => $row['workout_type'],
            'difficulty'   => $row['difficulty'],
            'estimated_duration_minutes' => (int) $row['estimated_duration_minutes'],
            // Writable by `workoutFields()`, so it is readable here for the same
            // round-trip reason the plan list carries every price tier.
            'calories_burn_estimate' => null === $row['calories_burn_estimate']
                ? null
                : (int) $row['calories_burn_estimate'],
            'muscle_groups' => is_array($muscles) ? $muscles : [],
            'equipment'     => is_array($equipment) ? $equipment : [],
            'cover_image_url' => $row['cover_image_url'],
            'video_url'     => $row['video_url'],
            'is_active'     => (bool) $row['is_active'],
            'exercise_count' => isset($row['exercise_count']) ? (int) $row['exercise_count'] : null,
            // The builder needs to know which rows it may edit: the platform
            // library is assignable but not editable.
            'is_mine'       => null !== $row['trainer_id'] && (int) $row['trainer_id'] === $trainerId,
            'is_platform'   => null === $row['trainer_id'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function workoutFields(array $payload): array
    {
        $fields = [];

        foreach (['description' => 'textarea', 'workout_type' => 'text', 'difficulty' => 'text'] as $key => $type) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = 'textarea' === $type
                    ? $this->textarea($payload[$key])
                    : sanitize_text_field((string) $payload[$key]);
            }
        }

        foreach (['estimated_duration_minutes', 'calories_burn_estimate'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = null === $payload[$key] ? null : max(0, (int) $payload[$key]);
            }
        }

        foreach (['muscle_groups', 'equipment'] as $key) {
            if (array_key_exists($key, $payload)) {
                $list = is_array($payload[$key])
                    ? $payload[$key]
                    : array_map('trim', explode(',', (string) $payload[$key]));

                $fields[$key] = wp_json_encode(array_values(array_filter(
                    array_map('sanitize_text_field', $list),
                    static fn(string $item): bool => '' !== $item
                )));
            }
        }

        foreach (['video_url', 'cover_image_url'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = esc_url_raw((string) $payload[$key]) ?: null;
            }
        }

        if (array_key_exists('is_active', $payload)) {
            $fields['is_active'] = (int) (bool) $payload['is_active'];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function planFields(array $payload): array
    {
        $fields = [];

        if (array_key_exists('description', $payload)) {
            $fields['description'] = $this->textarea($payload['description']);
        }

        if (array_key_exists('currency', $payload)) {
            $fields['currency'] = sanitize_text_field((string) $payload['currency']);
        }

        // Descriptive, not entitlement-granting, so a trainer may set them —
        // unlike `features`. A value outside the vocabulary falls back to the
        // column default rather than raising a 400: these arrive from a
        // `<select>`, so an unknown one is a client bug or a probe, and neither
        // is worth losing the rest of somebody's edit over (the same rule
        // W3.3 applies to ticket category and priority).
        $vocabularies = [
            'plan_type'  => ['combined' => 1, 'workout' => 1, 'nutrition' => 1],
            'difficulty' => ['beginner' => 1, 'intermediate' => 1, 'advanced' => 1],
        ];

        foreach ($vocabularies as $key => $allowed) {
            if (array_key_exists($key, $payload)) {
                $value = sanitize_text_field((string) $payload[$key]);

                $fields[$key] = isset($allowed[$value])
                    ? $value
                    : (string) array_key_first($allowed);
            }
        }

        foreach (['price_weekly', 'price_monthly', 'price_quarterly', 'price_yearly'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = null === $payload[$key] || '' === $payload[$key]
                    ? null
                    : round(max(0, (float) $payload[$key]), 2);
            }
        }

        foreach (['max_messages_per_week', 'sort_order'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = max(0, (int) $payload[$key]);
            }
        }

        // These two are nullable in the schema and an empty box means "not
        // specified", not "zero per week". Casting '' to 0 the way the NOT NULL
        // columns above do would turn every plan that leaves them blank into one
        // that promises no sessions at all.
        foreach (['weekly_sessions', 'duration_weeks'] as $key) {
            if (array_key_exists($key, $payload)) {
                $fields[$key] = null === $payload[$key] || '' === $payload[$key]
                    ? null
                    : max(0, (int) $payload[$key]);
            }
        }

        // `features` and `max_trainers` are absent: those decide platform-wide
        // entitlements, and a trainer granting themselves `has_video_workouts`
        // by editing their own plan would be selling something the platform did
        // not agree to.
        return $fields;
    }

    private function assertAuthored(int $trainerAccountId, string $resource, int $resourceId): void
    {
        if (Guard::trainerAssignedResource($trainerAccountId, $resource, $resourceId)) {
            return;
        }

        throw new DomainException(
            'fc_not_yours',
            __('That is not yours to change.', 'fitnessclub'),
            403
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

    private function textarea($value): ?string
    {
        if (null === $value) {
            return null;
        }

        $clean = sanitize_textarea_field((string) $value);

        return '' === $clean ? null : $clean;
    }
}
