<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\UserClock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/progress/*` (W2.3).
 *
 * The behaviour this package exists to deliver is **a range filter that filters**.
 * The prototype's week/month/3M/year toggle set state and re-rendered identical
 * data (gap §2, line 993) — four buttons that did nothing. So the tests that
 * matter here are the ones that would still pass against a fake filter, and
 * therefore have to assert the things a fake cannot fake:
 *
 *   - the **granularity** changes with the span, not just the window;
 *   - a bucket with no reading is **null** (a gap in the line) while a bucket
 *     with no workout is **zero** (a real fact about the week);
 *   - the totals under the charts are scoped to the window too, or a lifetime
 *     number that never moves reads as a broken filter.
 */
final class ProgressApiTest extends WorkoutFixtureCase
{
    private string $today;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = gmdate('Y-m-d');
    }

    // -------------------------------------------------------- the real filter

    public function testEachRangeBucketsAtADifferentGranularity(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $week    = $this->progress('week');
        $month   = $this->progress('month');
        $quarter = $this->progress('quarter');
        $year    = $this->progress('year');

        $this->assertSame('day', $week['bucket']);
        $this->assertSame('day', $month['bucket']);
        $this->assertSame('week', $quarter['bucket']);
        $this->assertSame('month', $year['bucket']);

        $this->assertCount(7, $week['labels']);
        $this->assertCount(30, $month['labels']);

        // A year is twelve or thirteen months depending on where today falls in
        // the month, not 365 points. That is the whole reason bucketing exists.
        $this->assertGreaterThanOrEqual(12, count($year['labels']));
        $this->assertLessThanOrEqual(13, count($year['labels']));

        // 90 days in seven-day buckets, anchored to today.
        $this->assertCount(13, $quarter['labels']);

        // Every series is the same length as its labels, or the chart draws
        // points against the wrong axis ticks.
        foreach ([$week, $month, $quarter, $year] as $payload) {
            $this->assertCount(count($payload['labels']), $payload['weight']);
            $this->assertCount(count($payload['labels']), $payload['consistency']);
        }
    }

    public function testTheWindowEndsTodayAndStartsWhereTheRangeSays(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $week = $this->progress('week');

        $this->assertSame($this->today, $week['to']);
        $this->assertSame(UserClock::shift($this->today, -6), $week['from']);
        $this->assertSame($week['from'], $week['dates'][0]);
        $this->assertSame($this->today, end($week['dates']));
    }

    public function testAnUnknownRangeFallsBackToTheMonthRatherThanFailing(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        // The args schema rejects a bad enum before the service sees it, so the
        // 400 is the contract; the service's own fallback covers the CLI and the
        // admin screens, which do not go through HTTP.
        $this->assertSame(400, $this->get('/progress?range=fortnight')->get_status());
        $this->assertSame('month', $this->progress('month')['range']);
    }

    // ------------------------------------------------------- gaps versus zeros

    public function testAWeekWithNoWorkoutsIsZeroButAWeekWithNoWeighInIsNull(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        // One workout and one weigh-in, both six days ago. Everything since is
        // empty — and the two kinds of empty are not the same kind.
        $when = UserClock::shift($this->today, -6);
        $this->completeSession($fixture, $when, 400);
        $this->logWeight($fixture['fc_user_id'], $when, 80.0);

        $week = $this->progress('week');

        $this->assertSame(1, $week['consistency'][0], 'The day it happened.');
        $this->assertSame(
            0,
            $week['consistency'][6],
            'A day you did not train is a zero — "you did not train" is information.'
        );

        $this->assertSame(80.0, $week['weight'][0]);
        $this->assertNull(
            $week['weight'][6],
            'A day you did not weigh yourself is a gap, not a plunge to the axis.'
        );
    }

    public function testAReadingSeriesIsAveragedWithinItsBucketRatherThanSampled(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        // Three weigh-ins inside one month bucket, swinging a kilo either way as
        // hydration does. The year chart should show the trend, not whichever
        // day happened to be sampled.
        foreach ([-40 => 81.0, -39 => 79.0, -38 => 80.0] as $offset => $weight) {
            $this->logWeight($fixture['fc_user_id'], UserClock::shift($this->today, $offset), $weight);
        }

        $year   = $this->progress('year');
        $values = array_values(array_filter($year['weight'], static fn($v) => null !== $v));

        $this->assertSame([80.0], $values, '(81 + 79 + 80) / 3.');
    }

    // -------------------------------------------------------------- strength

    public function testTheStrengthChartPicksTheMostTrainedLiftsAndPlotsTheirTopSet(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $when      = UserClock::shift($this->today, -3);
        $sessionId = $this->completeSession($fixture, $when, 500);

        // Exercise 1 gets three sets topping out at 60 kg; exercises 2–5 get one
        // set each. Only the busiest three lifts should be drawn.
        $this->logSets($sessionId, $fixture['exercise_ids'][0], [[10, 40.0], [8, 50.0], [6, 60.0]]);
        $this->logSets($sessionId, $fixture['exercise_ids'][1], [[10, 30.0]]);
        $this->logSets($sessionId, $fixture['exercise_ids'][2], [[10, 20.0]]);
        $this->logSets($sessionId, $fixture['exercise_ids'][3], [[10, 10.0]]);

        $week = $this->progress('week');

        $this->assertCount(3, $week['strength'], 'Three lines, or the chart is unreadable.');

        $first = $week['strength'][0];

        $this->assertSame('Fixture Lift 1', $first['exercise_name'], 'Busiest lift leads.');
        $this->assertSame(3, $first['sets']);

        // The heaviest set in the bucket, not the average of 40/50/60: strength
        // progression is about the top end, and averaging the warm-ups in makes
        // a personal best look like a bad week.
        $this->assertSame(60.0, $first['points'][3]);
        $this->assertNull($first['points'][6], 'A day the lift was not trained is a gap.');
    }

    public function testAMemberWhoHasLiftedNothingGetsAnEmptyStrengthSeriesNotAnError(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $week = $this->progress('week');

        $this->assertSame([], $week['strength']);
        $this->assertSame([], $week['measurements']);
        $this->assertSame(0, $week['totals']['workouts_completed']);
    }

    // ---------------------------------------------------------------- totals

    public function testTheTotalsUnderTheChartsAreScopedToTheWindowToo(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $this->completeSession($fixture, UserClock::shift($this->today, -2), 300);
        $this->completeSession($fixture, UserClock::shift($this->today, -45), 900);

        $week  = $this->progress('week');
        $month = $this->progress('month');
        $year  = $this->progress('year');

        $this->assertSame(1, $week['totals']['workouts_completed']);
        $this->assertSame(300, $week['totals']['calories_burned']);

        $this->assertSame(1, $month['totals']['workouts_completed'], '45 days ago is outside a month.');

        $this->assertSame(2, $year['totals']['workouts_completed']);
        $this->assertSame(1200, $year['totals']['calories_burned']);
    }

    // --------------------------------------------------------------- records

    public function testRecordsComeFromTheTableRatherThanBeingHardcoded(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $this->assertSame([], $this->get('/progress/records')->get_data()['items']);

        $wpdb->insert($wpdb->prefix . 'fc_personal_records', [
            'user_id'       => $fixture['fc_user_id'],
            'exercise_name' => 'Fixture Lift 1',
            'record_type'   => 'max_weight',
            'value'         => 92.5,
            'unit'          => 'kg',
            'achieved_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $items = $this->get('/progress/records')->get_data()['items'];

        $this->assertCount(1, $items);
        $this->assertSame('Fixture Lift 1', $items[0]['exercise_name']);
        $this->assertSame(92.5, $items[0]['value']);
        $this->assertSame('kg', $items[0]['unit']);
        $this->assertNotNull($items[0]['achieved_at']);

        // And it counts towards the window's totals, which is what makes the
        // "Personal Records" tile move when the range changes.
        $this->assertSame(1, $this->progress('week')['totals']['personal_records']);
    }

    // ----------------------------------------------------------- consistency

    public function testTheCalendarHasEveryDayOfTheYearIncludingTheEmptyOnes(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $this->completeSession($fixture, $this->today, 250);

        $year     = (int) substr($this->today, 0, 4);
        $calendar = $this->get('/progress/consistency?year=' . $year)->get_data();

        $expected = (int) gmdate('L', (int) strtotime($this->today)) ? 366 : 365;

        $this->assertSame($year, $calendar['year']);
        $this->assertCount(
            $expected,
            $calendar['days'],
            'A heatmap with no squares to leave empty is not a heatmap.'
        );

        $this->assertSame(1, $calendar['days'][$this->today]);
        $this->assertSame(0, $calendar['days'][sprintf('%04d-01-01', $year)] ?? null);
        $this->assertSame(1, $calendar['total']);
        $this->assertSame(1, $calendar['active_days']);
    }

    // ---------------------------------------------------------------- access

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $this->signOut();

        $this->assertSame(401, $this->get('/progress')->get_status());
        $this->assertSame(401, $this->get('/progress/records')->get_status());
        $this->assertSame(401, $this->get('/progress/consistency')->get_status());
    }

    public function testOneMembersProgressNeverContainsAnothers(): void
    {
        $mine = $this->seedMemberWithWorkout();
        $this->signIn($mine['account_id']);
        $this->completeSession($mine, $this->today, 700);

        $theirs = $this->seedMemberWithWorkout();
        $this->signIn($theirs['account_id']);

        $this->assertSame(0, $this->progress('week')['totals']['workouts_completed']);
        $this->assertSame(0, $this->progress('week')['totals']['calories_burned']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>
     */
    private function progress(string $range): array
    {
        $response = $this->get('/progress?range=' . $range);

        $this->assertSame(200, $response->get_status());

        return $response->get_data();
    }

    /**
     * A completed session on a given day.
     *
     * @param array{account_id:int,fc_user_id:int,workout_id:int,exercise_ids:int[]} $fixture
     */
    private function completeSession(array $fixture, string $date, int $calories): int
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'fc_workout_sessions', [
            'user_id'         => $fixture['fc_user_id'],
            'workout_id'      => $fixture['workout_id'],
            'log_date'        => $date,
            'started_at'      => $date . ' 08:00:00',
            'ended_at'        => $date . ' 08:45:00',
            'duration_seconds' => 2700,
            'status'          => 'completed',
            'completion_percentage' => 100,
            'calories_burned' => $calories,
            'created_at'      => $date . ' 08:00:00',
            'updated_at'      => $date . ' 08:45:00',
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Sets against one exercise in a session.
     *
     * @param array<int,array{0:int,1:float}> $sets [reps, weight]
     */
    private function logSets(int $sessionId, int $exerciseId, array $sets): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'fc_exercise_logs', [
            'session_id'  => $sessionId,
            'exercise_id' => $exerciseId,
            'order_index' => 0,
            'actual_sets' => count($sets),
        ]);

        $logId = (int) $wpdb->insert_id;

        foreach ($sets as $index => [$reps, $weight]) {
            $wpdb->insert($wpdb->prefix . 'fc_set_logs', [
                'exercise_log_id' => $logId,
                'set_index'       => $index,
                'reps'            => $reps,
                'weight_kg'       => $weight,
                'completed_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    private function logWeight(int $fcUserId, string $date, float $weight): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'fc_health_stats', [
            'user_id'     => $fcUserId,
            'record_date' => $date,
            'weight_kg'   => $weight,
            'source'      => 'manual',
        ]);
    }

    /**
     * Dispatch a GET, splitting any query string into real parameters.
     *
     * `WP_REST_Request` does not parse one out of the route the way a browser
     * would — the whole string becomes the path and nothing matches — so a
     * helper that takes `?range=week` has to do it here.
     */
    private function get(string $route): WP_REST_Response
    {
        [$path, $query] = array_pad(explode('?', $route, 2), 2, '');

        $request = new WP_REST_Request('GET', '/fitnessclub/v1' . $path);

        if ('' !== $query) {
            parse_str($query, $params);

            foreach ($params as $key => $value) {
                $request->set_param($key, $value);
            }
        }

        return rest_get_server()->dispatch($request);
    }
}
