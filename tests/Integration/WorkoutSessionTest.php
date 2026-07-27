<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Services\WorkoutSessionService;
use FitnessClub\Support\DomainException;

/**
 * The workout session state machine (W1.4).
 *
 * The first test here is the **phase's exit criterion**: drive a whole session —
 * start, pause, resume across a simulated 20-minute gap, log 24 sets, complete —
 * and check that the rows, the duration, the volume and the personal records are
 * all right. Everything after it is a specific way that machine can break.
 *
 * @covers \FitnessClub\Services\WorkoutSessionService
 */
final class WorkoutSessionTest extends WorkoutFixtureCase
{
    private WorkoutSessionService $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new WorkoutSessionService();
    }

    // ------------------------------------------------------- the exit criterion

    public function testAFullSessionProducesCorrectRowsDurationVolumeAndRecords(): void
    {
        global $wpdb;

        $fixture  = $this->seedMemberWithWorkout();
        $fcUserId = $fixture['fc_user_id'];

        // --- start -----------------------------------------------------------
        $session   = $this->sessions->start($fcUserId, $fixture['workout_id']);
        $sessionId = $session['id'];

        $this->assertSame('in_progress', $session['status']);
        $this->assertCount(self::EXERCISE_COUNT, $session['exercises']);
        $this->assertSame(
            self::EXERCISE_COUNT,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercise_logs WHERE session_id = %d",
                $sessionId
            )),
            'start() snapshots the plan, so a later edit to the workout cannot rewrite history.'
        );
        $this->assertSame(
            'in_progress',
            $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fc_user_workouts WHERE user_id = %d AND workout_id = %d",
                $fcUserId,
                $fixture['workout_id']
            ))
        );

        // --- 10 minutes of work, then pause ----------------------------------
        $this->rewindSession($sessionId, 600, ['started_at', 'last_resumed_at']);
        $paused = $this->sessions->pause($fcUserId, $sessionId);

        $this->assertSame('paused', $paused['status']);
        $this->assertEqualsWithDelta(600, $paused['duration_seconds'], 1);
        $this->assertNull($paused['last_resumed_at'], 'A paused session has no running leg.');

        // --- a 20-minute gap, then resume ------------------------------------
        $this->rewindSession($sessionId, 1200, ['started_at']);
        $resumed = $this->sessions->resume($fcUserId, $sessionId);

        $this->assertSame('in_progress', $resumed['status']);
        $this->assertEqualsWithDelta(600, $resumed['duration_seconds'], 1, 'Paused time is not active time.');
        $this->assertEqualsWithDelta(1200, $resumed['paused_seconds'], 1, 'The whole gap is accounted for as paused.');

        // --- 24 sets ---------------------------------------------------------
        foreach ($fixture['exercise_ids'] as $index => $exerciseId) {
            for ($set = 0; $set < self::SETS_PER_EXERCISE; $set++) {
                $this->sessions->logSet($fcUserId, $sessionId, $exerciseId, $set, [
                    'reps'               => self::REPS,
                    'weight_kg'          => ($index + 1) * 10,
                    'rest_taken_seconds' => 60,
                    'rpe'                => 8,
                ]);
            }
        }

        $this->assertSame(24, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
              WHERE el.session_id = %d",
            $sessionId
        )));

        // --- 15 more minutes, then complete ----------------------------------
        $this->rewindSession($sessionId, 900, ['started_at', 'last_resumed_at']);
        $celebration = $this->sessions->complete($fixture['account_id'], $fcUserId, $sessionId);

        // Duration: 10 min before the pause + 15 min after. The 20-minute gap is
        // paused time and must not be in it.
        //
        // Asserted to the second **with a second of slack**, and that is not
        // sloppiness: the running leg is measured against the real clock, and
        // real time genuinely passes between `resume()` and `complete()` while
        // the 24 sets are logged. An exact `assertSame(1500, …)` fails whenever
        // that stretch happens to cross a second boundary — roughly one run in
        // five here, which is a gate nobody trusts by the third red CI build.
        // The claim under test is that the 20-minute gap is *excluded*, and a
        // one-second tolerance states it without lying about the precision the
        // harness can deliver.
        $this->assertEqualsWithDelta(1500, $celebration['duration_seconds'], 1);
        $this->assertEqualsWithDelta(1200, (int) $this->sessionRow($sessionId)['paused_seconds'], 1);
        $this->assertSame(100.0, $celebration['completion_percentage']);
        $this->assertSame(self::EXERCISE_COUNT, $celebration['exercises_completed']);
        $this->assertSame(self::EXERCISE_COUNT, $celebration['total_exercises']);

        // Volume: 3 sets x 10 reps x (10+20+…+80) kg = 30 x 360 = 10 800 kg.
        $this->assertSame(10800.0, $celebration['total_volume_kg']);

        // Calories: MET 5.0 (strength) x 80 kg x (1500/3600) h = 166.67 -> 167.
        $this->assertSame(167, $celebration['calories_burned']);

        // Records: a brand-new member sets max_weight, max_reps and max_volume
        // on all eight lifts.
        $this->assertCount(24, $celebration['personal_records']);
        $this->assertSame(24, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_personal_records WHERE user_id = %d",
            $fcUserId
        )));

        $heaviest = $this->recordFor($celebration['personal_records'], 'Fixture Lift 8', 'max_weight');
        $this->assertSame(80.0, $heaviest['value']);
        $this->assertSame('kg', $heaviest['unit']);
        $this->assertNull($heaviest['previous_value'], 'A first record has nothing to beat.');

        $volume = $this->recordFor($celebration['personal_records'], 'Fixture Lift 8', 'max_volume');
        $this->assertSame(2400.0, $volume['value'], '3 x 10 x 80 kg for this lift.');

        // First workout ever: a one-day streak, and it counts as an extension.
        $this->assertSame(1, $celebration['streak_days']);
        $this->assertTrue($celebration['streak_extended']);

        // --- the rows the rest of the product reads ---------------------------
        $row = $this->sessionRow($sessionId);
        $this->assertSame('completed', $row['status']);
        $this->assertNotEmpty($row['ended_at']);
        $this->assertNull($row['last_resumed_at']);

        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT status, progress_percentage, times_completed, last_session_id
               FROM {$wpdb->prefix}fc_user_workouts WHERE user_id = %d AND workout_id = %d",
            $fcUserId,
            $fixture['workout_id']
        ), ARRAY_A);

        $this->assertSame('completed', $assignment['status']);
        $this->assertSame('100.00', $assignment['progress_percentage']);
        $this->assertSame('1', $assignment['times_completed']);
        $this->assertSame((string) $sessionId, $assignment['last_session_id']);

        // Feed + notification: the celebration screen is not the only record.
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_activity_log
              WHERE account_id = %d AND type = 'workout.completed'",
            $fixture['account_id']
        )));
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d",
            $fixture['account_id']
        )));
    }

    public function testExercisesNobodyTouchedCountAsSkippedNotCompleted(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        // One exercise done, seven walked past.
        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $fixture['exercise_ids'][0], 0, [
            'reps'      => 8,
            'weight_kg' => 80,
        ]);

        $celebration = $this->sessions->complete(
            $fixture['account_id'],
            $fixture['fc_user_id'],
            $session['id']
        );

        // "8/8 exercises" for a workout where one exercise happened is a lie the
        // celebration screen was telling: `was_skipped` defaults to 0 and is only
        // recomputed for exercises that received a set.
        $this->assertSame(1, $celebration['exercises_completed']);
        $this->assertSame(self::EXERCISE_COUNT, $celebration['total_exercises']);

        global $wpdb;
        $this->assertSame(
            self::EXERCISE_COUNT - 1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_exercise_logs
                  WHERE session_id = %d AND was_skipped = 1",
                $session['id']
            ))
        );
    }

    // ------------------------------------------------------------ single-session

    public function testASecondStartIsRefusedAndNamesTheOpenSession(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $first   = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        try {
            $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
            $this->fail('A second concurrent session should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('fc_session_already_open', $e->code());
            $this->assertSame(409, $e->status());

            // The body names the open session so the UI can offer resume-or-discard
            // instead of a dead end.
            $data = $e->toWpError()->get_error_data();
            $this->assertSame($first['id'], $data['session_id']);
            $this->assertSame($fixture['workout_id'], $data['workout_id']);
        }
    }

    public function testAbandoningFreesTheUserToStartAgain(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $first   = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        $this->sessions->abandon($fixture['fc_user_id'], $first['id']);
        $second = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('in_progress', $second['status']);
    }

    // -------------------------------------------------------------- set logging

    public function testLoggingTheSameSetTwiceCorrectsItRatherThanDuplicating(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $exercise = $fixture['exercise_ids'][0];

        $payload = ['reps' => 10, 'weight_kg' => 60];
        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $exercise, 0, $payload);

        // The retry the player fires when gym wifi drops mid-request.
        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $exercise, 0, $payload);

        // …and then a genuine correction: it was actually 8 reps at 65.
        $corrected = $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $exercise, 0, [
            'reps'      => 8,
            'weight_kg' => 65,
        ]);

        $sets = $corrected['exercises'][0]['sets'];
        $this->assertCount(1, $sets, 'One set index, one row — never three.');
        $this->assertSame(8, $sets[0]['reps']);
        $this->assertSame(65.0, $sets[0]['weight_kg']);
        $this->assertSame(520.0, $corrected['exercises'][0]['total_volume_kg'], '8 x 65 kg.');

        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
              WHERE el.session_id = %d",
            $session['id']
        )));
    }

    public function testAnExerciseFromAnotherWorkoutCannotBeGraftedOntoASession(): void
    {
        $mine    = $this->seedMemberWithWorkout();
        $theirs  = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($mine['fc_user_id'], $mine['workout_id']);

        $this->expectException(DomainException::class);
        $this->sessions->logSet($mine['fc_user_id'], $session['id'], $theirs['exercise_ids'][0], 0, ['reps' => 5]);
    }

    public function testCompletionPercentageTracksLoggedSetsAgainstThePlan(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        // 6 of 24 planned sets.
        foreach (array_slice($fixture['exercise_ids'], 0, 2) as $index => $exerciseId) {
            for ($set = 0; $set < 3; $set++) {
                $session = $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $exerciseId, $set, [
                    'reps'      => 10,
                    'weight_kg' => 20,
                ]);
            }
        }

        $this->assertSame(25.0, $session['completion_percentage']);
    }

    // ---------------------------------------------------------- state transitions

    public function testPauseIsRejectedOnASessionThatIsNotRunning(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $this->sessions->pause($fixture['fc_user_id'], $session['id']);

        try {
            $this->sessions->pause($fixture['fc_user_id'], $session['id']);
            $this->fail('Pausing a paused session should conflict.');
        } catch (DomainException $e) {
            $this->assertSame('fc_session_state_conflict', $e->code());
            $this->assertSame(409, $e->status());
        }
    }

    public function testACompletedSessionCannotBeCompletedAgain(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $this->sessions->complete($fixture['account_id'], $fixture['fc_user_id'], $session['id']);

        $this->expectException(DomainException::class);
        $this->sessions->complete($fixture['account_id'], $fixture['fc_user_id'], $session['id']);
    }

    public function testAnotherMembersSessionIsNotFound(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        $session = $this->sessions->start($theirs['fc_user_id'], $theirs['workout_id']);

        try {
            $this->sessions->rehydrate($mine['fc_user_id'], $session['id']);
            $this->fail('A foreign session must not be readable.');
        } catch (DomainException $e) {
            // 404, not 403: the caller has no business learning the row exists.
            $this->assertSame('fc_not_found', $e->code());
            $this->assertSame(404, $e->status());
        }
    }

    public function testActiveReturnsTheOpenSessionAndNullOnceItIsFinished(): void
    {
        $fixture = $this->seedMemberWithWorkout();

        $this->assertNull($this->sessions->active($fixture['fc_user_id']));

        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $this->assertSame($session['id'], $this->sessions->active($fixture['fc_user_id'])['id']);

        // Still "active" while paused — the user is mid-workout, not finished.
        $this->sessions->pause($fixture['fc_user_id'], $session['id']);
        $this->assertSame($session['id'], $this->sessions->active($fixture['fc_user_id'])['id']);

        $this->sessions->resume($fixture['fc_user_id'], $session['id']);
        $this->sessions->complete($fixture['account_id'], $fixture['fc_user_id'], $session['id']);
        $this->assertNull($this->sessions->active($fixture['fc_user_id']));
    }

    public function testCursorSurvivesForResuming(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        $this->sessions->moveCursor($fixture['fc_user_id'], $session['id'], 3, 2);
        $rehydrated = $this->sessions->rehydrate($fixture['fc_user_id'], $session['id']);

        $this->assertSame(3, $rehydrated['current_exercise_index']);
        $this->assertSame(2, $rehydrated['current_set_index']);
    }

    // -------------------------------------------------------------- abandonment

    public function testAbandonKeepsElapsedTimeAndPartialProgress(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        foreach (range(0, 2) as $set) {
            $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $fixture['exercise_ids'][0], $set, [
                'reps'      => 10,
                'weight_kg' => 50,
            ]);
        }

        $this->rewindSession($session['id'], 420, ['started_at', 'last_resumed_at']);
        $abandoned = $this->sessions->abandon($fixture['fc_user_id'], $session['id']);

        $this->assertSame('abandoned', $abandoned['status']);
        // ±1 s for the same reason as the full-session duration above: the leg
        // is measured against the real clock, which keeps running while the
        // three sets are logged.
        $this->assertEqualsWithDelta(420, $abandoned['duration_seconds'], 1, 'Time spent is still time spent (§8.1).');
        $this->assertSame(12.5, $abandoned['completion_percentage'], '3 of 24 planned sets.');

        // The card keeps the partial credit rather than resetting to zero.
        $this->assertSame('12.50', $wpdb->get_var($wpdb->prepare(
            "SELECT progress_percentage FROM {$wpdb->prefix}fc_user_workouts
              WHERE user_id = %d AND workout_id = %d",
            $fixture['fc_user_id'],
            $fixture['workout_id']
        )));

        // An abandoned workout is not a completed one: no streak, no PRs.
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_personal_records WHERE user_id = %d",
            $fixture['fc_user_id']
        )));
    }

    // ---------------------------------------------------------------- records

    public function testASecondSessionOnlySetsRecordsThatWereActuallyBeaten(): void
    {
        $fixture  = $this->seedMemberWithWorkout();
        $fcUserId = $fixture['fc_user_id'];
        $lift     = $fixture['exercise_ids'][0];

        $first = $this->sessions->start($fcUserId, $fixture['workout_id']);
        $this->sessions->logSet($fcUserId, $first['id'], $lift, 0, ['reps' => 10, 'weight_kg' => 50]);
        $this->sessions->complete($fixture['account_id'], $fcUserId, $first['id']);

        $second = $this->sessions->start($fcUserId, $fixture['workout_id']);
        // Heavier but fewer reps: max_weight falls, max_reps does not.
        $this->sessions->logSet($fcUserId, $second['id'], $lift, 0, ['reps' => 6, 'weight_kg' => 60]);
        $celebration = $this->sessions->complete($fixture['account_id'], $fcUserId, $second['id']);

        $types = array_column($celebration['personal_records'], 'record_type');
        sort($types);

        // max_weight 50 -> 60 and max_volume 500 -> ... wait: 6 x 60 = 360 < 500,
        // so volume is NOT beaten. Only the weight record moves.
        $this->assertSame(['max_weight'], $types);

        $weight = $this->recordFor($celebration['personal_records'], 'Fixture Lift 1', 'max_weight');
        $this->assertSame(60.0, $weight['value']);
        $this->assertSame(50.0, $weight['previous_value']);
    }

    public function testMatchingYourOwnRecordIsNotANewRecord(): void
    {
        $fixture  = $this->seedMemberWithWorkout();
        $fcUserId = $fixture['fc_user_id'];
        $lift     = $fixture['exercise_ids'][0];

        $first = $this->sessions->start($fcUserId, $fixture['workout_id']);
        $this->sessions->logSet($fcUserId, $first['id'], $lift, 0, ['reps' => 10, 'weight_kg' => 50]);
        $this->sessions->complete($fixture['account_id'], $fcUserId, $first['id']);

        $second = $this->sessions->start($fcUserId, $fixture['workout_id']);
        $this->sessions->logSet($fcUserId, $second['id'], $lift, 0, ['reps' => 10, 'weight_kg' => 50]);
        $celebration = $this->sessions->complete($fixture['account_id'], $fcUserId, $second['id']);

        $this->assertSame([], $celebration['personal_records']);
    }

    public function testTimeMetricExercisesRecordTheirLongestHold(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $plank   = $fixture['exercise_ids'][0];
        $wpdb->update($wpdb->prefix . 'fc_exercises', ['metric' => 'seconds'], ['id' => $plank]);

        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $plank, 0, ['duration_seconds' => 45]);
        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $plank, 1, ['duration_seconds' => 70]);
        $celebration = $this->sessions->complete($fixture['account_id'], $fixture['fc_user_id'], $session['id']);

        $record = $this->recordFor($celebration['personal_records'], 'Fixture Lift 1', 'best_time');
        $this->assertSame(70.0, $record['value']);
        $this->assertSame('seconds', $record['unit']);

        // A hold has no weight, so it must not fabricate weight/volume records.
        $types = array_column($celebration['personal_records'], 'record_type');
        $this->assertSame(['best_time'], $types);
    }

    // ----------------------------------------------------------------- review

    public function testReviewCorrectsSetsAndReopensRecordDetection(): void
    {
        $fixture  = $this->seedMemberWithWorkout();
        $fcUserId = $fixture['fc_user_id'];
        $lift     = $fixture['exercise_ids'][0];

        $session = $this->sessions->start($fcUserId, $fixture['workout_id']);
        $this->sessions->logSet($fcUserId, $session['id'], $lift, 0, ['reps' => 10, 'weight_kg' => 50]);
        $this->sessions->complete($fixture['account_id'], $fcUserId, $session['id']);

        // "It was actually 85, I mistyped." That is exactly when a record changes.
        $reviewed = $this->sessions->review($fcUserId, $session['id'], [
            'notes'              => 'Felt strong.',
            'perceived_exertion' => 9,
            'difficulty_rating'  => 4,
            'sets'               => [
                ['exercise_id' => $lift, 'set_index' => 0, 'weight_kg' => 85],
            ],
        ]);

        $this->assertSame('Felt strong.', $reviewed['notes']);
        $this->assertSame(9, $reviewed['perceived_exertion']);
        $this->assertSame(850.0, $reviewed['exercises'][0]['total_volume_kg']);

        global $wpdb;
        $this->assertSame('85.00', $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$wpdb->prefix}fc_personal_records
              WHERE user_id = %d AND exercise_name = %s AND record_type = 'max_weight'",
            $fcUserId,
            'Fixture Lift 1'
        )));
    }

    public function testAnUnfinishedSessionCannotBeReviewed(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        try {
            $this->sessions->review($fixture['fc_user_id'], $session['id'], ['notes' => 'too soon']);
            $this->fail('Reviewing a running session should conflict.');
        } catch (DomainException $e) {
            $this->assertSame('fc_session_not_finished', $e->code());
        }
    }


    // -------------------------------------------------------- stale cleanup

    public function testStaleOpenSessionsAreClosedWithoutCreditingTheClosedTab(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $session = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);

        $this->sessions->logSet($fixture['fc_user_id'], $session['id'], $fixture['exercise_ids'][0], 0, [
            'reps'      => 10,
            'weight_kg' => 40,
        ]);

        // Two days of silence: 30 minutes of it was the workout, the rest was a
        // browser tab nobody came back to.
        $this->rewindSession($session['id'], 1800, ['last_resumed_at']);
        $this->rewindSession($session['id'], 2 * DAY_IN_SECONDS, ['updated_at']);

        $closed = $this->sessions->abandonStale(24);
        $this->assertGreaterThanOrEqual(1, $closed);

        $row = $this->sessionRow($session['id']);
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(0, (int) $row['duration_seconds'], 'The closed tab earns no time.');

        // And the user is free to start again rather than being stuck on 409.
        $next = $this->sessions->start($fixture['fc_user_id'], $fixture['workout_id']);
        $this->assertSame('in_progress', $next['status']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function recordFor(array $records, string $exercise, string $type): array
    {
        foreach ($records as $record) {
            if ($record['exercise_name'] === $exercise && $record['record_type'] === $type) {
                return $record;
            }
        }

        $this->fail("No {$type} record for {$exercise}.");
    }
}
