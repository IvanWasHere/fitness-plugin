<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Support\UserClock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/health/*` (W2.2).
 *
 * The four rules HealthService is built on, each of which is invisible from
 * outside until it breaks:
 *
 *   1. **a day is a row and writing it is an upsert** — morning weight and
 *      evening sleep make one record, not two;
 *   2. **a partial write never erases** — the evening entry leaves the morning's
 *      weight alone;
 *   3. **BMI is derived, never accepted** — from height and the weight the row
 *      ends up holding, and NULL when height is unknown;
 *   4. **`fc_users.weight_kg` is recomputed, not assigned** — so backdating an
 *      old weigh-in does not overwrite "current weight", and deleting the newest
 *      entry falls back to the one before it rather than leaving the profile
 *      quoting a row that no longer exists.
 *
 * Rule 4 is the one worth the most tests: it is correct-looking either way on
 * the happy path, and only the backdate and the delete tell the two
 * implementations apart.
 */
final class HealthApiTest extends IntegrationTestCase
{
    private const HEIGHT_CM = 180.0;

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

    // ------------------------------------------------------------ the summary

    public function testAFreshMemberGetsEightEmptyCardsAndNoInventedNumbers(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $data = $this->get('/health/summary')->get_data();

        $this->assertSame($this->today, $data['date']);
        $this->assertCount(8, $data['cards']);

        foreach ($data['cards'] as $card) {
            $this->assertNull($card['value'], $card['key'] . ' should start empty.');
            $this->assertNull($card['display'], $card['key'] . ' should have nothing to display.');
            $this->assertNull($card['trend'], $card['key'] . ' cannot trend from one reading.');
        }

        $this->assertNull($data['measurements']);
        $this->assertSame(self::HEIGHT_CM, $data['height_cm']);
    }

    public function testBmiSaysWhyItIsMissingRatherThanShowingNothing(): void
    {
        // A member with no height on file. BMI is unknowable, and the card has
        // to say which of the two missing pieces it is waiting for — "—" with no
        // explanation is the state the prototype left it in.
        $member = $this->seedMember(['height_cm' => null]);
        $this->signIn($member['account_id']);

        $bmi = $this->card($this->get('/health/summary')->get_data(), 'bmi');

        $this->assertNull($bmi['value']);
        $this->assertSame('Add your height to see BMI', $bmi['detail']);
        $this->assertFalse($bmi['editable'], 'BMI is derived; the modal must not offer an input.');

        $this->post('/health/stats', ['weight_kg' => 80]);

        $bmi = $this->card($this->get('/health/summary')->get_data(), 'bmi');

        $this->assertNull($bmi['value'], 'A weight without a height still yields no BMI.');
        $this->assertSame('Add your height to see BMI', $bmi['detail']);
    }

    public function testACardStopsQuotingAReadingOnceItIsStale(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // A resting heart rate from four months ago is not "your resting heart
        // rate" today, and a card that shows it without qualification reads as
        // current.
        $this->post('/health/stats', [
            'record_date'        => UserClock::shift($this->today, -120),
            'heart_rate_resting' => 58,
        ]);

        $card = $this->card($this->get('/health/summary')->get_data(), 'heart_rate');

        $this->assertNull($card['value'], 'Beyond the staleness window the card goes empty.');
        $this->assertNull($card['recorded_on']);

        // The reading is not lost — it is still in the history behind the card.
        $stats = $this->getWithParams('/health/stats', [
            'from' => UserClock::shift($this->today, -200),
        ])->get_data();

        $this->assertCount(1, $stats['items']);
        $this->assertSame(58, $stats['items'][0]['heart_rate_resting']);
    }

    // -------------------------------------------------------------- the rules

    public function testTwoEntriesOnOneDayMakeOneRowAndNeitherErasesTheOther(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $morning = $this->post('/health/stats', ['weight_kg' => 79.4])->get_data();
        $evening = $this->post('/health/stats', ['sleep_hours' => 7.5])->get_data();

        $this->assertSame(
            $morning['id'],
            $evening['id'],
            'The second write of the day updates the first row, it does not add one.'
        );

        $this->assertSame(79.4, $evening['weight_kg'], 'Logging sleep must not erase the weight.');
        $this->assertSame(7.5, $evening['sleep_hours']);

        $stats = $this->get('/health/stats')->get_data();

        $this->assertCount(1, $stats['items'], 'One day, one row.');
    }

    public function testBmiIsComputedOnWriteAndIgnoresWhateverTheClientSends(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // 81 kg at 1.8 m → 25.0.
        $stat = $this->post('/health/stats', ['weight_kg' => 81, 'bmi' => 0.5])->get_data();

        $this->assertSame(25.0, $stat['bmi'], 'BMI comes from the arithmetic, not from the request.');

        // An entry that records something else entirely leaves the day's BMI
        // consistent with the weight already on the row.
        $after = $this->post('/health/stats', ['mood_score' => 4])->get_data();

        $this->assertSame(25.0, $after['bmi']);
        $this->assertSame(4, $after['mood_score']);
    }

    public function testTheProfileWeightFollowsTheNewestReadingAndNotTheNewestWrite(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $this->post('/health/stats', ['weight_kg' => 78.0]);

        $this->assertSame(78.0, $this->profileWeight($member['fc_user_id']));

        // Backdating an older weigh-in must not become "current weight". This is
        // the case that separates recomputing the cache from assigning it.
        $this->post('/health/stats', [
            'record_date' => UserClock::shift($this->today, -30),
            'weight_kg'   => 85.0,
        ]);

        $this->assertSame(
            78.0,
            $this->profileWeight($member['fc_user_id']),
            'A month-old entry is history, not the current weight.'
        );

        $summary = $this->card($this->get('/health/summary')->get_data(), 'weight');

        $this->assertSame(78.0, $summary['value']);
        $this->assertSame('down', $summary['trend']['direction']);
        $this->assertSame(-7.0, $summary['trend']['delta']);
    }

    public function testDeletingTheNewestEntryFallsBackToTheOneBeforeIt(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $older = $this->post('/health/stats', [
            'record_date' => UserClock::shift($this->today, -7),
            'weight_kg'   => 82.0,
        ])->get_data();

        $newest = $this->post('/health/stats', ['weight_kg' => 80.0])->get_data();

        $this->assertSame(80.0, $this->profileWeight($member['fc_user_id']));

        $this->delete('/health/stats/' . $newest['id']);

        $this->assertSame(
            82.0,
            $this->profileWeight($member['fc_user_id']),
            'The profile must not go on quoting a reading that was deleted.'
        );

        // And with nothing left, it holds nothing rather than the last value it
        // happened to see.
        $this->delete('/health/stats/' . $older['id']);

        $this->assertNull($this->profileWeight($member['fc_user_id']));
    }

    // --------------------------------------------------------------- refusals

    public function testHalfABloodPressureReadingIsRefusedRatherThanCompleted(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // The prototype took one number and wrote `diastolic = systolic * 0.65`,
        // storing a fabricated value indistinguishable from a measured one.
        $response = $this->post('/health/stats', ['systolic_pressure' => 120]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_blood_pressure_incomplete', $response->get_data()['code']);

        $both = $this->post('/health/stats', [
            'systolic_pressure'  => 118,
            'diastolic_pressure' => 76,
        ]);

        $this->assertSame(200, $both->get_status());

        $card = $this->card($this->get('/health/summary')->get_data(), 'blood_pressure');

        $this->assertSame('118/76', $card['display']);
    }

    public function testABloodPressureWithTheNumbersTheWrongWayRoundIsRefused(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $response = $this->post('/health/stats', [
            'systolic_pressure'  => 76,
            'diastolic_pressure' => 118,
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_blood_pressure_invalid', $response->get_data()['code']);
    }

    public function testAnImpossibleWeightIsRefusedRatherThanClamped(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // Storing 500 for a typed 5 000 would put a number on the member's chart
        // that they never entered and cannot explain.
        $response = $this->post('/health/stats', ['weight_kg' => 5000]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame([], $this->get('/health/stats')->get_data()['items']);
    }

    public function testAnEmptyEntryIsRefused(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $response = $this->post('/health/stats', ['record_date' => $this->today]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_health_empty', $response->get_data()['code']);
    }

    public function testAnotherMembersEntryIsNotFoundRatherThanForbidden(): void
    {
        $owner = $this->seedMember();
        $this->signIn($owner['account_id']);
        $stat = $this->post('/health/stats', ['weight_kg' => 77])->get_data();

        $intruder = $this->seedMember();
        $this->signIn($intruder['account_id']);

        // 404, not 403: a 403 confirms the row exists.
        $this->assertSame(404, $this->put('/health/stats/' . $stat['id'], ['weight_kg' => 99])->get_status());
        $this->assertSame(404, $this->delete('/health/stats/' . $stat['id'])->get_status());

        // The intruder's own history is empty — the read is scoped too, not
        // just the write.
        $this->assertSame([], $this->get('/health/stats')->get_data()['items']);

        // And it is still there for its owner.
        $this->signIn($owner['account_id']);
        $this->assertCount(1, $this->get('/health/stats')->get_data()['items']);
    }

    public function testMovingAnEntryOntoADayThatAlreadyHasOneIsRefusedInWords(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $yesterday = UserClock::shift($this->today, -1);

        $this->post('/health/stats', ['record_date' => $yesterday, 'weight_kg' => 81]);
        $todays = $this->post('/health/stats', ['weight_kg' => 80])->get_data();

        // The unique key would reject this with a database error the member
        // cannot act on.
        $response = $this->put('/health/stats/' . $todays['id'], ['record_date' => $yesterday]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_health_date_taken', $response->get_data()['code']);
    }

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $this->signOut();

        $this->assertSame(401, $this->get('/health/summary')->get_status());
        $this->assertSame(401, $this->get('/health/stats')->get_status());

        // A write stops earlier and differently: with no session there is no
        // CSRF token to send, so AuthProvider::guard() refuses it at 403 before
        // the permission callback is ever consulted. Asserting 401 here would
        // be asserting a code path that cannot be reached.
        $write = $this->post('/health/stats', ['weight_kg' => 70]);

        $this->assertSame(403, $write->get_status());
        $this->assertSame('fc_csrf_missing', $write->get_data()['code']);
    }

    // ----------------------------------------------------------- measurements

    public function testMeasurementsUpsertByDateAndSurfaceOnTheSummary(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $first = $this->post('/health/measurements', [
            'chest_cm' => 101.5,
            'waist_cm' => 85,
        ])->get_data();

        $second = $this->post('/health/measurements', ['arms_cm' => 36])->get_data();

        $this->assertSame($first['id'], $second['id'], 'One day, one measurement row.');
        $this->assertSame(101.5, $second['chest_cm'], 'A partial write leaves the rest alone.');
        $this->assertSame(36.0, $second['arms_cm']);
        $this->assertNull($second['hips_cm'], 'What was never measured stays unmeasured.');

        $summary = $this->get('/health/summary')->get_data();

        $this->assertSame(101.5, $summary['measurements']['fields']['chest_cm']['value']);
        $this->assertSame($this->today, $summary['measurements']['last_recorded_on']);
    }

    public function testTheSummaryKeepsEachMeasurementsOwnLatestRatherThanTheNewestSession(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // Calves in March, chest today. Taking the newest *row* would blank the
        // calves the moment anything else was recorded — the member would watch
        // a measurement they took disappear because they measured something
        // else.
        $march = UserClock::shift($this->today, -120);

        $this->post('/health/measurements', ['record_date' => $march, 'calves_cm' => 39]);
        $this->post('/health/measurements', ['chest_cm' => 102]);

        $fields = $this->get('/health/summary')->get_data()['measurements']['fields'];

        $this->assertSame(102.0, $fields['chest_cm']['value']);
        $this->assertSame($this->today, $fields['chest_cm']['date']);

        $this->assertSame(39.0, $fields['calves_cm']['value'], 'The older reading survives.');
        $this->assertSame(
            $march,
            $fields['calves_cm']['date'],
            'And carries its own date, so it is not presented as current.'
        );
    }

    // -------------------------------------------------------------- the trend

    public function testTheTrendReportsDirectionWithoutJudgingIt(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $this->post('/health/stats', [
            'record_date' => UserClock::shift($this->today, -7),
            'sleep_hours' => 6.0,
        ]);
        $this->post('/health/stats', ['sleep_hours' => 7.5]);

        $card = $this->card($this->get('/health/summary')->get_data(), 'sleep');

        $this->assertSame(7.5, $card['value']);
        $this->assertSame('up', $card['trend']['direction']);
        $this->assertSame(1.5, $card['trend']['delta']);
        $this->assertSame(6.0, $card['trend']['previous']);

        // Whether "up" is good is not the server's call — there is no verdict
        // field for the UI to colour by.
        $this->assertArrayNotHasKey('is_good', $card['trend']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * A member with a height, so BMI has something to work with.
     *
     * @param array<string,mixed> $overrides
     * @return array{account_id:int,fc_user_id:int}
     */
    private function seedMember(array $overrides = []): array
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_USER);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_users', array_merge([
            'account_id'   => $accountId,
            'display_name' => 'Health Fixture',
            'height_cm'    => self::HEIGHT_CM,
            // UTC on purpose: record_date is stamped in the member's own zone,
            // and a floating zone makes every date assertion here flaky.
            'timezone'     => 'UTC',
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $overrides));

        return ['account_id' => $accountId, 'fc_user_id' => (int) $wpdb->insert_id];
    }

    private function profileWeight(int $fcUserId): ?float
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fcUserId
        ));

        return null === $value ? null : (float) $value;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function card(array $summary, string $key): array
    {
        foreach ($summary['cards'] as $card) {
            if ($card['key'] === $key) {
                return $card;
            }
        }

        $this->fail(sprintf('The summary has no "%s" card.', $key));
    }

    private function get(string $route): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1' . $route));
    }

    /**
     * @param array<string,mixed> $params
     */
    private function getWithParams(string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/fitnessclub/v1' . $route);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body): WP_REST_Response
    {
        return $this->send('POST', $route, $body);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function put(string $route, array $body): WP_REST_Response
    {
        return $this->send('PUT', $route, $body);
    }

    private function delete(string $route): WP_REST_Response
    {
        return $this->send('DELETE', $route, []);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function send(string $method, string $route, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/fitnessclub/v1' . $route);
        $request->set_header('content-type', 'application/json');
        $request->set_header('X-FC-CSRF', $this->csrf());
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }
}
