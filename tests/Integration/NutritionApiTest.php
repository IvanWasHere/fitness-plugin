<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Services\EntitlementService;
use FitnessClub\Services\NutritionService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/nutrition/*` and `/foods/*` (W2.1).
 *
 * The behaviours worth pinning down are the three rules the service is built
 * on, each of which is invisible from the outside until it breaks:
 *
 *   1. **nutrients are copied, not joined** — correcting a food later must not
 *      rewrite the diary that already recorded it;
 *   2. **totals are recomputed on every write** — at both the meal and the day
 *      level, including when a meal moves to another date;
 *   3. **goals are snapshotted per day** — raising a target today does not
 *      change whether yesterday was met.
 */
final class NutritionApiTest extends WorkoutFixtureCase
{
    /** @var int[] fc_foods rows created here. */
    private array $foodIds = [];

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

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->foodIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_foods', ['id' => $id]);
        }
        $this->foodIds = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------- the day

    public function testAFreshDayIsEmptyAndInventsNoGoals(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $data = $this->get('/nutrition/day')->get_data();

        $this->assertSame($this->today, $data['date']);
        $this->assertSame([], $data['meals']);
        $this->assertSame(0, $data['totals']['calories']);

        // The fixture member has no nutrition_goals, so every goal is null —
        // not a plausible default the member never chose.
        $this->assertNull($data['goals']['calories']);
        $this->assertNull($data['goals']['protein_g']);

        // Water still has a goal, because that one *is* a configured site
        // default rather than a claim about this member.
        $this->assertSame(0, $data['water']['consumed_ml']);
        $this->assertSame(2000, $data['water']['goal_ml']);
        $this->assertSame(8, $data['water']['glasses_goal']);
    }

    // -------------------------------------------------------------- the rules

    public function testAFoodsNutrientsAreCopiedAtLogTimeAndScaledByQuantity(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $foodId  = $this->makeFood(['name' => 'Test Chicken', 'calories' => 100, 'protein_g' => 20]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $meal = $this->post('/nutrition/logs', [
            'meal_type' => 'lunch',
            'items'     => [['food_id' => $foodId, 'quantity' => 2.5]],
        ])->get_data();

        $this->assertSame(250, $meal['calories'], '100 kcal × 2.5.');
        $this->assertSame(50.0, $meal['protein_g']);

        // Correct the food afterwards, as an administrator would.
        $wpdb->update($wpdb->prefix . 'fc_foods', ['calories' => 999], ['id' => $foodId]);

        $after = $this->get('/nutrition/day')->get_data();

        $this->assertSame(
            250,
            $after['meals'][0]['calories'],
            'The diary records what was logged, not what the food says today.'
        );
    }

    public function testMealAndDayTotalsAreRecomputedOnEveryWrite(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $foodId  = $this->makeFood(['calories' => 200, 'protein_g' => 10, 'carbs_g' => 30, 'fat_g' => 5]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $first = $this->post('/nutrition/logs', [
            'meal_type' => 'breakfast',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ])->get_data();

        $this->post('/nutrition/logs', [
            'meal_type' => 'lunch',
            'items'     => [
                ['food_id' => $foodId, 'quantity' => 1],
                ['custom_name' => 'Toast', 'calories' => 80, 'carbs_g' => 15],
            ],
        ]);

        $day = $this->get('/nutrition/day')->get_data();
        $this->assertCount(2, $day['meals']);
        $this->assertSame(480, $day['totals']['calories'], '200 + 200 + 80.');
        $this->assertSame(75.0, $day['totals']['carbs_g'], '30 + 30 + 15.');

        // Deleting a meal has to take its numbers with it.
        $this->assertSame(200, $this->delete('/nutrition/logs/' . $first['id'])->get_status());

        $after = $this->get('/nutrition/day')->get_data();
        $this->assertCount(1, $after['meals']);
        $this->assertSame(280, $after['totals']['calories']);
    }

    public function testMovingAMealToAnotherDayRecomputesBothDays(): void
    {
        $fixture   = $this->seedMemberWithWorkout();
        $foodId    = $this->makeFood(['calories' => 300]);
        $yesterday = gmdate('Y-m-d', strtotime($this->today . ' -1 day'));
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $meal = $this->post('/nutrition/logs', [
            'meal_type' => 'dinner',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ])->get_data();

        $this->assertSame(300, $this->get('/nutrition/day')->get_data()['totals']['calories']);

        $this->put('/nutrition/logs/' . $meal['id'], ['log_date' => $yesterday]);

        // The day it left must not keep the calories — the bug this guards
        // against leaves them counted on both days at once.
        $this->assertSame(
            0,
            $this->get('/nutrition/day')->get_data()['totals']['calories'],
            'The old day was recomputed.'
        );
        $this->assertSame(
            300,
            $this->getWithParams('/nutrition/day', ['date' => $yesterday])->get_data()['totals']['calories'],
            'The new day picked them up.'
        );
    }

    public function testGoalsAreSnapshottedPerDaySoHistoryIsNotRewritten(): void
    {
        $fixture   = $this->seedMemberWithWorkout();
        $yesterday = gmdate('Y-m-d', strtotime($this->today . ' -1 day'));
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        // Yesterday was judged against 2 000.
        $this->put('/nutrition/goals', ['calories' => 2000, 'date' => $yesterday]);

        // Today the member raises it.
        $this->put('/nutrition/goals', ['calories' => 2600]);

        $this->assertSame(
            2000,
            $this->getWithParams('/nutrition/day', ['date' => $yesterday])->get_data()['goals']['calories'],
            'Yesterday keeps the goal it was measured against.'
        );
        $this->assertSame(2600, $this->get('/nutrition/day')->get_data()['goals']['calories']);
    }

    public function testGoalsMergeRatherThanReplace(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $this->put('/nutrition/goals', ['calories' => 2400, 'protein_g' => 180]);
        $goals = $this->put('/nutrition/goals', ['calories' => 2500])->get_data();

        $this->assertSame(2500, $goals['calories']);
        $this->assertSame(180.0, $goals['protein_g'], 'A partial update leaves the rest alone.');
    }

    // ---------------------------------------------------------------- water

    public function testWaterAcceptsADeltaAndAnAbsoluteTotal(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $this->post('/nutrition/water', ['delta_ml' => 250]);
        $water = $this->post('/nutrition/water', ['delta_ml' => 250])->get_data();

        $this->assertSame(500, $water['consumed_ml']);
        $this->assertSame(2, $water['glasses']);

        // Tapping the fifth dot directly.
        $water = $this->post('/nutrition/water', ['total_ml' => 1250])->get_data();
        $this->assertSame(1250, $water['consumed_ml']);
        $this->assertSame(5, $water['glasses']);
    }

    public function testWaterNeverGoesNegative(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $water = $this->post('/nutrition/water', ['delta_ml' => -1000])->get_data();

        $this->assertSame(0, $water['consumed_ml'], 'A stray "-1 glass" floors at zero.');
    }

    public function testWaterWithNoAmountIsRefused(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $response = $this->post('/nutrition/water', []);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_water_missing_amount', $response->get_data()['code']);
    }

    // ---------------------------------------------------------------- meals

    public function testAnEmptyMealIsRefused(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $response = $this->post('/nutrition/logs', ['meal_type' => 'lunch', 'items' => []]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_meal_empty', $response->get_data()['code']);
    }

    public function testAnUnknownFoodIsRefused(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $response = $this->post('/nutrition/logs', [
            'meal_type' => 'lunch',
            'items'     => [['food_id' => 99999999, 'quantity' => 1]],
        ]);

        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_food_not_found', $response->get_data()['code']);
    }

    public function testAnotherMembersMealIsNotFound(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();
        $foodId = $this->makeFood(['calories' => 100]);

        $this->subscribeToPro($theirs['fc_user_id']);
        $this->signIn($theirs['account_id']);
        $meal = $this->post('/nutrition/logs', [
            'meal_type' => 'lunch',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ])->get_data();

        // Both members are entitled, so what this test reaches is the
        // *ownership* check rather than the feature gate — which sits in front
        // of it and would otherwise answer first.
        $this->subscribeToPro($mine['fc_user_id']);
        $this->signIn($mine['account_id']);
        $response = $this->delete('/nutrition/logs/' . $meal['id']);

        // 404, not 403: a 403 would confirm the row exists.
        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_meal_not_found', $response->get_data()['code']);
    }

    public function testEditingAMealReplacesItsItems(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $foodId  = $this->makeFood(['calories' => 100]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $meal = $this->post('/nutrition/logs', [
            'meal_type' => 'snack',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ])->get_data();

        $updated = $this->put('/nutrition/logs/' . $meal['id'], [
            'items' => [
                ['custom_name' => 'Apple', 'calories' => 52],
                ['custom_name' => 'Banana', 'calories' => 89],
            ],
        ])->get_data();

        $this->assertCount(2, $updated['items']);
        $this->assertSame(141, $updated['calories']);
    }

    public function testAnEditThatOmitsItemsLeavesThemAlone(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $foodId  = $this->makeFood(['calories' => 100]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $meal = $this->post('/nutrition/logs', [
            'meal_type' => 'snack',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ])->get_data();

        $updated = $this->put('/nutrition/logs/' . $meal['id'], ['meal_type' => 'dinner'])->get_data();

        $this->assertSame('dinner', $updated['meal_type']);
        $this->assertCount(1, $updated['items'], 'Omitting items must not empty the meal.');
        $this->assertSame(100, $updated['calories']);
    }

    // ---------------------------------------------------------------- foods

    public function testFoodSearchPrefixMatchesThroughTheFulltextIndex(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->makeFood(['name' => 'Zzchickenzz Breast', 'calories' => 165]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $items = $this->getWithParams('/foods', ['q' => 'zzchicken'])->get_data()['items'];

        $this->assertNotEmpty($items, 'A prefix must match — natural-language mode would not.');
        $this->assertSame('Zzchickenzz Breast', $items[0]['name']);
    }

    public function testAShortQueryStillFindsSomething(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->makeFood(['name' => 'Qy Test Food', 'calories' => 10]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        // Two characters is below InnoDB's default token size, so this only
        // works because the service falls back to a prefix LIKE. Without the
        // fallback it silently returns nothing on a stock MySQL.
        $items = $this->getWithParams('/foods', ['q' => 'qy'])->get_data()['items'];

        $this->assertNotEmpty($items);
        $this->assertSame('Qy Test Food', $items[0]['name']);
    }

    public function testHyphensInASearchDoNotExcludeResults(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->makeFood(['name' => 'Zqlow Zqfat Yoghurt', 'calories' => 60]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        // In boolean mode a bare `-` means NOT. Unstripped, "zqlow-zqfat" would
        // ask for rows containing "zqlow" but *not* "zqfat" — excluding the very
        // row being searched for.
        $items = $this->getWithParams('/foods', ['q' => 'zqlow-zqfat'])->get_data()['items'];

        $this->assertNotEmpty($items, 'A hyphen is punctuation here, not an operator.');
    }

    public function testACustomFoodIsNeverCreatedVerifiedOrAsASystemFood(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $food = $this->post('/foods', [
            'name'        => 'Mums Lasagne',
            'calories'    => 600,
            'protein_g'   => 30,
            // Attempting to plant a verified system food.
            'source'      => 'system',
            'is_verified' => 1,
        ])->get_data();

        $this->foodIds[] = $food['id'];

        $this->assertSame('user', $food['source']);
        $this->assertFalse($food['is_verified']);
    }

    public function testBarcodeMissIsA404(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        $response = $this->get('/foods/barcode/000000000000');

        $this->assertSame(404, $response->get_status());
    }

    // ------------------------------------------------------------ the gate

    public function testWritesNeedTheNutritionEntitlement(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        // NOTE: deliberately *not* subscribed — that is the point of this test.
        // The fixture member has no subscription, and the free tier sets
        // `can_log_nutrition` to false — so the write is refused with the
        // feature named, which is what lets the client offer an upgrade.
        $free = (array) FitnessClub()->config('fitnessclub.free_tier', []);
        $this->assertFalse($free['can_log_nutrition'], 'Precondition: the free tier cannot log nutrition.');

        $response = $this->post('/nutrition/logs', [
            'meal_type' => 'lunch',
            'items'     => [['custom_name' => 'Toast', 'calories' => 80]],
        ]);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_feature_unavailable', $response->get_data()['code']);

        // Reading is still allowed: the screen shows what a plan would unlock.
        $this->assertSame(200, $this->get('/nutrition/day')->get_status());
    }

    public function testTheDashboardPicksUpALoggedMeal(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $foodId  = $this->makeFood(['calories' => 450, 'protein_g' => 40]);
        $this->subscribeToPro($fixture['fc_user_id']);
        $this->signIn($fixture['account_id']);

        // Straight through the service: this asserts the rollup the dashboard
        // reads, not the entitlement gate, which has its own test above.
        (new NutritionService())->logMeal($fixture['fc_user_id'], [
            'meal_type' => 'lunch',
            'items'     => [['food_id' => $foodId, 'quantity' => 1]],
        ]);

        $dashboard = $this->get('/user/dashboard')->get_data();

        $this->assertSame(450, $dashboard['nutrition']['calories']['value']);
        $this->assertSame(40.0, $dashboard['nutrition']['protein_g']['value']);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Give a member an active plan that grants `can_log_nutrition`.
     *
     * Most tests here are about the *mechanics* of logging, and the free tier
     * cannot log at all — so without this they would all assert the entitlement
     * gate instead, over and over. The gate has one test of its own, which
     * deliberately does not call this.
     */
    private function subscribeToPro(int $fcUserId): void
    {
        global $wpdb;

        $planId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}fc_plans WHERE slug = 'pro' LIMIT 1"
        );

        $this->assertGreaterThan(0, $planId, 'The pro plan is seeded on activation.');

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_subscriptions', [
            'user_id'           => $fcUserId,
            'plan_id'           => $planId,
            'status'            => 'active',
            'subscription_type' => 'monthly',
            'start_date'        => gmdate('Y-m-d'),
            'end_date'          => gmdate('Y-m-d', strtotime('+30 days')),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        // Entitlements are cached per member; the new subscription has to be
        // visible to the very next request.
        EntitlementService::flush($fcUserId);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function makeFood(array $attributes = []): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_foods', $attributes + [
            'name'         => 'Fixture Food ' . wp_generate_password(6, false),
            'category'     => 'other',
            'serving_size' => '1 serving',
            'calories'     => 100,
            'protein_g'    => 0,
            'carbs_g'      => 0,
            'fat_g'        => 0,
            'source'       => 'system',
            'is_verified'  => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $wpdb->insert_id;
        $this->foodIds[] = $id;

        return $id;
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
