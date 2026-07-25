<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Providers\RoleProvider;

/**
 * A private member with a workout of their own, built per test.
 *
 * The demo seed is deliberately *not* used for the session tests: Alex Morgan
 * already has sessions and personal records, so "24 new PRs" would depend on
 * seed history rather than on the code under test. A throwaway member with
 * known weights makes every expected number arithmetic anyone can check by hand.
 *
 * Shape: 8 exercises, 3 planned sets each — 24 planned sets, which is exactly
 * the volume the W1.4 exit criterion drives — with exercise *i* carrying
 * (i+1)×10 kg, so total volume is a number the test can state outright.
 */
abstract class WorkoutFixtureCase extends IntegrationTestCase
{
    protected const EXERCISE_COUNT = 8;
    protected const SETS_PER_EXERCISE = 3;
    protected const REPS = 10;
    protected const BODY_WEIGHT_KG = 80.0;

    /** @var int[] fc_users.id rows created here. */
    private array $fcUserIds = [];

    /** @var int[] fc_workouts.id rows created here. */
    private array $workoutIds = [];

    /** @var int[] wp user ids whose activity/notification rows need clearing. */
    private array $noisyUsers = [];

    protected function tearDown(): void
    {
        global $wpdb;

        // fc_users cascades sessions, assignments and personal records;
        // fc_workouts cascades exercises. Activity and notifications key off the
        // WordPress id and have no FK, so they go by hand.
        foreach ($this->noisyUsers as $wpUserId) {
            $wpdb->delete($wpdb->prefix . 'fc_activity_log', ['wp_user_id' => $wpUserId]);
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['wp_user_id' => $wpUserId]);
        }
        foreach ($this->fcUserIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_users', ['id' => $id]);
        }
        foreach ($this->workoutIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_workouts', ['id' => $id]);
        }

        $this->fcUserIds  = [];
        $this->workoutIds = [];
        $this->noisyUsers = [];

        parent::tearDown();
    }

    /**
     * A member, a workout, its exercises and the assignment that ties them.
     *
     * @return array{wp_user_id:int,fc_user_id:int,workout_id:int,exercise_ids:int[]}
     */
    protected function seedMemberWithWorkout(string $workoutType = 'strength'): array
    {
        global $wpdb;

        $wpUserId = $this->makeUser(RoleProvider::ROLE_USER);
        $now      = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_users', [
            'wp_user_id'   => $wpUserId,
            'display_name' => 'Fixture Member',
            'weight_kg'    => self::BODY_WEIGHT_KG,
            // UTC on purpose: log_date and the streak are computed in the user's
            // own zone, and a floating zone would make those assertions flaky.
            'timezone'     => 'UTC',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $fcUserId          = (int) $wpdb->insert_id;
        $this->fcUserIds[] = $fcUserId;
        $this->noisyUsers[] = $wpUserId;

        $wpdb->insert($wpdb->prefix . 'fc_workouts', [
            'workout_name'  => 'Fixture Workout',
            'slug'          => 'fixture-workout-' . $fcUserId,
            'description'   => 'Built by the test suite.',
            'workout_type'  => $workoutType,
            'difficulty'    => 'intermediate',
            'estimated_duration_minutes' => 45,
            'calories_burn_estimate'     => 380,
            'muscle_groups' => wp_json_encode(['Chest', 'Triceps']),
            'equipment'     => wp_json_encode(['Barbell']),
            'video_url'     => 'https://example.test/video.mp4',
            'is_template'   => 1,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $workoutId          = (int) $wpdb->insert_id;
        $this->workoutIds[] = $workoutId;

        $exerciseIds = [];
        for ($i = 0; $i < self::EXERCISE_COUNT; $i++) {
            $wpdb->insert($wpdb->prefix . 'fc_exercises', [
                'workout_id'        => $workoutId,
                'exercise_name'     => sprintf('Fixture Lift %d', $i + 1),
                'exercise_type'     => 'compound',
                'default_sets'      => self::SETS_PER_EXERCISE,
                'default_reps'      => self::REPS,
                'default_weight_kg' => ($i + 1) * 10,
                'default_rest_seconds' => 60,
                'metric'            => 'reps',
                'order_index'       => $i,
                'video_url'         => 'https://example.test/exercise.mp4',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $exerciseIds[] = (int) $wpdb->insert_id;
        }

        $wpdb->insert($wpdb->prefix . 'fc_user_workouts', [
            'user_id'       => $fcUserId,
            'workout_id'    => $workoutId,
            'assigned_date' => gmdate('Y-m-d'),
            'status'        => 'assigned',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return [
            'wp_user_id'   => $wpUserId,
            'fc_user_id'   => $fcUserId,
            'workout_id'   => $workoutId,
            'exercise_ids' => $exerciseIds,
        ];
    }

    /**
     * Simulate the passage of time by moving a session's clock backwards.
     *
     * There is no other honest way to test elapsed time: the service derives it
     * from stored timestamps (which is the point — a client cannot report it),
     * so a test that wants a 20-minute gap has to move the timestamps, not sleep
     * for twenty minutes.
     *
     * @param string[] $columns Datetime columns to rewind.
     */
    protected function rewindSession(int $sessionId, int $seconds, array $columns): void
    {
        global $wpdb;

        foreach ($columns as $column) {
            // $column comes from the caller's literal list, never from input.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed column names.
            $sql = "UPDATE {$wpdb->prefix}fc_workout_sessions
                       SET {$column} = DATE_SUB({$column}, INTERVAL %d SECOND)
                     WHERE id = %d AND {$column} IS NOT NULL";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
            $wpdb->query($wpdb->prepare($sql, $seconds, $sessionId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function sessionRow(int $sessionId): array
    {
        global $wpdb;

        return (array) $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_workout_sessions WHERE id = %d",
            $sessionId
        ), ARRAY_A);
    }
}
