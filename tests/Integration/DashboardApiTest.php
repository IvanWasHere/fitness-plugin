<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Services\DashboardService;
use FitnessClub\Services\ProgressService;
use FitnessClub\Support\DashboardCache;
use FitnessClub\Support\UserClock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `GET /user/dashboard` — the one-call aggregate (W1.6).
 *
 * Three things are worth proving here and are hard to see by eye:
 *
 *   1. the **buckets line up** — a workout logged three days ago lands in the
 *      fourth bar of a seven-day chart, and the days between it and today are
 *      zeros rather than missing;
 *   2. the **cache serves and then gets out of the way** — a second read is a
 *      hit, and completing a workout drops it, because "my session isn't on the
 *      dashboard" is the exact complaint this endpoint exists to avoid;
 *   3. the aggregate **stays inside the caller's own data**.
 *
 * The bucketing tests drive the services directly with an **explicit** day
 * rather than going over HTTP. Every figure on this screen is relative to
 * "today", so a test that inserts a session at 23:59:59 and reads it back at
 * 00:00:00 is asking about a different day than the one it set up — and a suite
 * that fails once a day, always on someone else's machine, is worse than no
 * suite. The REST tests below cover the wiring, where the day does not matter.
 */
final class DashboardApiTest extends WorkoutFixtureCase
{
    /**
     * The day every fixture and assertion in one test agrees on. Read once, so
     * a run that straddles midnight still tests a coherent day.
     */
    private string $today;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The fixture member is stamped UTC, so the member's calendar and
        // gmdate() are the same calendar.
        $this->today = gmdate('Y-m-d');
    }

    public function testDashboardReturnsEveryContractSectionForAFreshMember(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $response = $this->get('/user/dashboard');
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();

        foreach (
            [
            'greeting', 'stats', 'todays_workout', 'upcoming_workout', 'water',
            'nutrition', 'weekly_chart', 'monthly_stats', 'recent_activity',
            'message_previews',
            ] as $section
        ) {
            $this->assertArrayHasKey($section, $data, "Contract section: {$section}");
        }

        // A member with nothing logged gets zeros and nulls, never invented numbers.
        $this->assertSame(0, $data['stats']['streak_days']);
        $this->assertSame(0, $data['stats']['calories_burned_today']);
        $this->assertNull($data['stats']['goal_progress_percentage'], 'No target weight, no percentage.');
        $this->assertSame(0, $data['water']['consumed_ml']);
        $this->assertSame(2000, $data['water']['goal_ml'], 'Falls back to the configured default.');
        $this->assertNull($data['nutrition']['calories']['goal'], 'No goal set is null, not 2000.');
        $this->assertSame([], $data['message_previews']);

        // The assigned workout is the card, and there is nothing after it.
        $this->assertSame($fixture['workout_id'], $data['todays_workout']['id']);
        $this->assertNull($data['upcoming_workout']);
    }

    public function testWeeklyChartBucketsSessionsByDayAndKeepsTheEmptyOnes(): void
    {
        $fixture = $this->seedMemberWithWorkout();

        // Two sessions three days ago, one today: the day is one bucket however
        // many workouts it holds, and the days between are real zeros.
        $this->completedSessionOn($fixture, 3, 200);
        $this->completedSessionOn($fixture, 3, 150);
        $this->completedSessionOn($fixture, 0, 90);

        $chart = (new ProgressService())->weeklySeries($fixture['fc_user_id'], $this->today);

        $this->assertCount(7, $chart['calories']);
        $this->assertCount(7, $chart['labels']);

        // Index 6 is today, so three days ago is index 3.
        $this->assertSame(350, $chart['calories'][3], 'Both of that day’s sessions.');
        $this->assertSame(2, $chart['workouts'][3]);
        $this->assertSame(90, $chart['calories'][6]);
        $this->assertSame(0, $chart['calories'][4], 'A rest day is a zero, not a gap.');
        $this->assertSame(0, $chart['calories'][5]);

        $this->assertSame($this->today, $chart['dates'][6], 'The last bucket is today.');
    }

    public function testMonthlyStatsAndStreakAgreeWithTheLoggedSessions(): void
    {
        $fixture = $this->seedMemberWithWorkout();

        // Today, yesterday, and one outside the streak but inside the month.
        $this->completedSessionOn($fixture, 0, 100, 1800);
        $this->completedSessionOn($fixture, 1, 200, 1800);
        $this->completedSessionOn($fixture, 10, 300, 3600);

        $data = (new DashboardService())
            ->forUser($fixture['account_id'], $fixture['fc_user_id'], $this->today);

        $this->assertSame(2, $data['stats']['streak_days'], 'Today and yesterday.');
        $this->assertSame(2, $data['greeting']['streak_days'], 'Same number in both places.');
        $this->assertSame(100, $data['stats']['calories_burned_today'], 'Today only.');

        $monthly = $data['monthly_stats'];
        $this->assertSame(3, $monthly['workouts_completed']);
        $this->assertSame(600, $monthly['calories_burned']);
        $this->assertSame(3, $monthly['active_days']);
        $this->assertSame(120, $monthly['total_minutes'], '30 + 30 + 60 minutes.');
        // 7200 s = 2 h across a 30-day window = 30/7 weeks → 0.5 h/week.
        $this->assertSame(0.5, $monthly['avg_hours_per_week']);
        $this->assertNull($monthly['consistency_percentage'], 'Nothing was scheduled to be consistent with.');
    }

    public function testConsistencyIsAdherenceToWhatWasScheduledAndIsCappedAtOneHundred(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();

        $wpdb->update(
            $wpdb->prefix . 'fc_user_workouts',
            ['scheduled_for' => $this->today],
            ['user_id' => $fixture['fc_user_id'], 'workout_id' => $fixture['workout_id']]
        );

        $this->completedSessionOn($fixture, 0, 100);

        $progress = new ProgressService();
        $stats    = $progress->monthlyStats($fixture['fc_user_id'], $this->today);
        $this->assertSame(100.0, $stats['consistency_percentage'], 'One scheduled, one done.');

        // Doing the same workout twice is enthusiasm, not 200 % adherence.
        $this->completedSessionOn($fixture, 0, 100);
        $stats = $progress->monthlyStats($fixture['fc_user_id'], $this->today);
        $this->assertSame(100.0, $stats['consistency_percentage']);
    }

    public function testTodaysCardPrefersASessionLeftRunning(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $started = $this->post('/sessions', ['workout_id' => $fixture['workout_id']]);
        $this->assertSame(201, $started->get_status());
        $sessionId = $started->get_data()['id'];

        $card = $this->get('/user/dashboard')->get_data()['todays_workout'];

        $this->assertSame($fixture['workout_id'], $card['id']);
        $this->assertSame($sessionId, $card['resumable_session_id'], 'The card offers Resume.');
    }

    public function testTheSecondReadIsCachedAndCompletingASessionDropsIt(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $this->assertSame('miss', $this->get('/user/dashboard')->get_headers()['X-FC-Cache']);
        $this->assertSame('hit', $this->get('/user/dashboard')->get_headers()['X-FC-Cache']);

        // Stale-by-a-minute is exactly the complaint in the demo fixtures'
        // support ticket, so a completed workout has to invalidate immediately.
        $sessionId = $this->post('/sessions', ['workout_id' => $fixture['workout_id']])->get_data()['id'];
        $this->post("/sessions/{$sessionId}/complete");

        $response = $this->get('/user/dashboard');
        $this->assertSame('miss', $response->get_headers()['X-FC-Cache'], 'Completing a session cleared it.');
        $this->assertSame(1, $response->get_data()['monthly_stats']['workouts_completed']);
    }

    public function testRefreshBypassesTheCache(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $this->get('/user/dashboard');

        $request = new WP_REST_Request('GET', '/fitnessclub/v1/user/dashboard');
        $request->set_param('refresh', true);
        $response = rest_get_server()->dispatch($request);

        $this->assertSame('miss', $response->get_headers()['X-FC-Cache']);
    }

    public function testOneMembersDashboardNeverCarriesAnothersWork(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        $this->completedSessionOn($theirs, 0, 500);

        $this->signIn($mine['account_id']);
        $data = $this->get('/user/dashboard')->get_data();

        $this->assertSame(0, $data['stats']['calories_burned_today']);
        $this->assertSame(0, $data['monthly_stats']['workouts_completed']);
        $this->assertSame($mine['workout_id'], $data['todays_workout']['id']);
    }

    public function testSignedOutIsRefused(): void
    {
        $this->signOut();

        $response = $this->get('/user/dashboard');

        $this->assertSame(401, $response->get_status());
    }

    protected function tearDown(): void
    {
        // The cache outlives the rows it was built from; a leaked entry would
        // make the next test's first read a hit.
        foreach ($this->cachedUsers as $fcUserId) {
            DashboardCache::forget($fcUserId);
        }
        $this->cachedUsers = [];

        parent::tearDown();
    }

    /** @var int[] */
    private array $cachedUsers = [];

    /**
     * A completed session `$daysAgo` days back, with known calories and duration.
     *
     * Written straight to the table rather than driven through the state
     * machine: WorkoutSessionTest already proves the machine, and what this test
     * needs is a session on a *specific past day*, which the machine will never
     * produce.
     *
     * @param array{fc_user_id:int,workout_id:int} $fixture
     */
    private function completedSessionOn(array $fixture, int $daysAgo, int $calories, int $seconds = 1800): int
    {
        global $wpdb;

        $this->cachedUsers[] = $fixture['fc_user_id'];

        $date = UserClock::shift($this->today, -$daysAgo);
        $now  = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_workout_sessions', [
            'user_id'          => $fixture['fc_user_id'],
            'workout_id'       => $fixture['workout_id'],
            'log_date'         => $date,
            'started_at'       => $date . ' 09:00:00',
            'ended_at'         => $date . ' 09:30:00',
            'duration_seconds' => $seconds,
            'status'           => 'completed',
            'completion_percentage' => 100.00,
            'calories_burned'  => $calories,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DashboardCache::forget($fixture['fc_user_id']);

        return (int) $wpdb->insert_id;
    }

    private function get(string $route): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1' . $route));
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        // Every write goes through the CSRF gate now.
        $request->set_header('X-FC-CSRF', $this->csrf());

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }
}
