<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Payments\GatewayRegistry;
use FitnessClub\Services\EntitlementService;
use FitnessClub\Services\PaymentService;
use FitnessClub\Services\SubscriptionService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Subscriptions, payments and the entitlement merge (W3.2).
 *
 * `plans/03-backend.md` asks for the overlapping-plan matrix to be tested
 * **explicitly**, and names the reason: "feature flicker as subscriptions lapse
 * is the predictable bug here, and it is invisible until a user has two
 * trainers". So the merge cases are here alongside the state machine:
 *
 *   - booleans **union** — a feature granted by either plan survives;
 *   - numeric caps take the **max**, never the sum;
 *   - a lapsed subscription stops contributing;
 *   - **nothing is ever revoked retroactively** (Q16) — a downgrade blocks new
 *     actions and destroys no existing state.
 */
final class BillingApiTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $planIds = [];

    /** @var int[] */
    private array $trainerIds = [];

    /** @var int[] */
    private array $noisy = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The registry memoises adapters; a filter added by one test must not
        // leak into the next.
        GatewayRegistry::flush();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->noisy as $accountId) {
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['account_id' => $accountId]);
        }
        foreach ($this->trainerIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_trainers', ['id' => $id]);
        }
        foreach ($this->planIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_plans', ['id' => $id]);
        }

        $this->planIds = $this->trainerIds = $this->noisy = [];

        remove_all_filters('fitnessclub/payment_gateways');
        GatewayRegistry::flush();

        parent::tearDown();
    }

    // ------------------------------------------------- the entitlement matrix

    public function testBooleansUnionAcrossConcurrentPlans(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        // Opposing booleans: one plan grants nutrition and denies messaging, the
        // other the reverse. The member is paying for both, so they get both.
        $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], [
            'can_log_nutrition' => true,
            'can_message'       => false,
        ]);
        $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], [
            'can_log_nutrition' => false,
            'can_message'       => true,
        ]);

        $entitlements = (new EntitlementService())->forUser($member['fc_user_id']);

        $this->assertTrue($entitlements['can_log_nutrition'], 'Granted by A.');
        $this->assertTrue($entitlements['can_message'], 'Granted by B.');
    }

    public function testNumericCapsTakeTheMaxAndNeverTheSum(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], ['max_trainers' => 1]);
        $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], ['max_trainers' => 2]);

        // A ceiling, not an allowance that accumulates: 1 + 2 must be 2, not 3.
        $this->assertSame(2, (new EntitlementService())->limit($member['fc_user_id'], 'max_trainers'));
    }

    public function testUnlimitedBeatsAnyNumber(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], ['max_active_workouts' => 5]);
        $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], ['max_active_workouts' => null]);

        $this->assertNull(
            (new EntitlementService())->limit($member['fc_user_id'], 'max_active_workouts'),
            'Null is unlimited and wins — coercing it to 0 would be the strictest reading of the best plan.'
        );
    }

    public function testALapsedSubscriptionStopsContributingButTheOtherHolds(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $a = $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], ['has_video_workouts' => true]);
        $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], ['can_log_nutrition' => true]);

        $service = new EntitlementService();
        $this->assertTrue($service->can($member['fc_user_id'], 'has_video_workouts'));

        // A lapses. This is the "feature flicker" case: the member must lose
        // exactly what A granted and keep everything B still does.
        $wpdb->update(
            $wpdb->prefix . 'fc_subscriptions',
            ['status' => 'expired'],
            ['id' => $a]
        );
        EntitlementService::flush($member['fc_user_id']);

        $this->assertFalse($service->can($member['fc_user_id'], 'has_video_workouts'));
        $this->assertTrue($service->can($member['fc_user_id'], 'can_log_nutrition'), 'B is untouched.');
        $this->assertTrue($service->hasActiveSubscription($member['fc_user_id']));
    }

    public function testWithNothingActiveTheFreeTierAppliesRatherThanADenial(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coach  = $this->seedTrainer();
        $id     = $this->subscribe($member['fc_user_id'], $coach['trainer_id'], ['can_log_nutrition' => true]);

        $wpdb->update($wpdb->prefix . 'fc_subscriptions', ['status' => 'expired'], ['id' => $id]);
        EntitlementService::flush($member['fc_user_id']);

        $service = new EntitlementService();

        // Fail open to the floor. A failed renewal must not lock somebody out of
        // their own workout history.
        $this->assertTrue($service->can($member['fc_user_id'], 'can_log_workouts'));
        $this->assertFalse($service->can($member['fc_user_id'], 'can_log_nutrition'));
        $this->assertFalse($service->hasActiveSubscription($member['fc_user_id']));
    }

    public function testADowngradeReportsTheNewCapButRevokesNothing(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        // Two active coaching relationships under a 2-trainer plan.
        $big = $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], ['max_trainers' => 2]);
        $this->link($member['fc_user_id'], $coachA['trainer_id']);
        $this->link($member['fc_user_id'], $coachB['trainer_id']);

        $service = new EntitlementService();
        $this->assertSame(2, $service->limit($member['fc_user_id'], 'max_trainers'));
        $this->assertSame(2, $service->forUser($member['fc_user_id'])['trainers_used']);

        // Downgrade to a plan allowing one.
        $wpdb->update($wpdb->prefix . 'fc_subscriptions', ['status' => 'cancelled'], ['id' => $big]);
        $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], ['max_trainers' => 1]);

        // Q16: the cap is now 1 and **both relationships survive**. A limit
        // constrains future actions; it never destroys existing state. Severing
        // a coaching relationship because a plan changed would punish a trainer
        // who did nothing.
        $this->assertSame(1, $service->limit($member['fc_user_id'], 'max_trainers'));
        $this->assertSame(
            2,
            $service->forUser($member['fc_user_id'])['trainers_used'],
            'Grandfathered: over the cap is a valid state.'
        );
        $this->assertSame(
            2,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
                  WHERE user_id = %d AND status = 'active'",
                $member['fc_user_id']
            )),
            'Nothing was severed in the database either.'
        );
    }

    // --------------------------------------------------- the state machine

    public function testCheckoutSettlesOnTheManualGatewayAndRecordsAPayment(): void
    {
        $member = $this->seedMember();
        $planId = $this->makePlan(['can_log_nutrition' => true], 19.99);

        $this->signIn($member['account_id']);

        $response = $this->post('/billing/checkout', ['plan_id' => $planId, 'cycle' => 'monthly']);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame('settled', $data['status']);
        $this->assertSame('active', $data['subscription']['status']);
        $this->assertSame('monthly', $data['subscription']['cycle']);

        // The amount came from the plan, not from the request.
        $payments = $this->get('/billing/payments')->get_data();
        $this->assertSame(1, $payments['total']);
        $this->assertSame(19.99, $payments['items'][0]['amount']);
        $this->assertSame('completed', $payments['items'][0]['status']);

        // And the features it grants are live immediately.
        $this->assertTrue((new EntitlementService())->can($member['fc_user_id'], 'can_log_nutrition'));
    }

    public function testCheckoutNeverAcceptsAPriceFromTheClient(): void
    {
        $member = $this->seedMember();
        $planId = $this->makePlan([], 49.00);

        $this->signIn($member['account_id']);

        // The most commonly exploited endpoint in subscription plugins. There is
        // no args field it could land in, and the service reads the plan.
        $this->post('/billing/checkout', [
            'plan_id' => $planId,
            'cycle'   => 'monthly',
            'amount'  => 1,
            'price'   => 0.01,
        ]);

        $this->assertSame(49.0, $this->get('/billing/payments')->get_data()['items'][0]['amount']);
    }

    public function testASecondPlanWithTheSameTrainerReplacesTheFirst(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $planA = $this->makePlan([], 10.00, $coach['trainer_id']);
        $planB = $this->makePlan([], 20.00, $coach['trainer_id']);

        $this->signIn($member['account_id']);

        $this->post('/billing/checkout', ['plan_id' => $planA, 'cycle' => 'monthly']);
        $this->post('/billing/checkout', ['plan_id' => $planB, 'cycle' => 'monthly']);

        $active = array_values(array_filter(
            $this->get('/billing/subscription')->get_data()['items'],
            static fn(array $row): bool => 'active' === $row['status']
        ));

        // Two concurrent subscriptions to the same coach is double billing.
        $this->assertCount(1, $active);
        $this->assertSame($planB, $active[0]['plan_id']);
    }

    public function testPlansWithDifferentTrainersCoexist(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $this->signIn($member['account_id']);

        $this->post('/billing/checkout', [
            'plan_id' => $this->makePlan([], 10.00, $coachA['trainer_id']),
            'cycle'   => 'monthly',
        ]);
        $this->post('/billing/checkout', [
            'plan_id' => $this->makePlan([], 10.00, $coachB['trainer_id']),
            'cycle'   => 'monthly',
        ]);

        $active = array_filter(
            $this->get('/billing/subscription')->get_data()['items'],
            static fn(array $row): bool => 'active' === $row['status']
        );

        $this->assertCount(2, $active, 'One subscription per trainer, held at once (Q3).');
    }

    public function testCancellingKeepsWhatTheMemberPaidForAndResumeUndoesIt(): void
    {
        $member = $this->seedMember();
        $planId = $this->makePlan(['can_log_nutrition' => true], 15.00);

        $this->signIn($member['account_id']);
        $created = $this->post('/billing/checkout', ['plan_id' => $planId, 'cycle' => 'monthly'])
            ->get_data()['subscription'];

        $cancelled = $this->post('/billing/subscription/cancel', [
            'subscription_id' => $created['id'],
        ])->get_data();

        // Status stays active — ending access the moment they click would take
        // something they already bought.
        $this->assertSame('active', $cancelled['status']);
        $this->assertTrue($cancelled['cancel_at_period_end']);
        $this->assertFalse($cancelled['auto_renew']);
        $this->assertTrue(
            (new EntitlementService())->can($member['fc_user_id'], 'can_log_nutrition'),
            'Features survive until the period ends.'
        );

        $resumed = $this->post('/billing/subscription/resume', [
            'subscription_id' => $created['id'],
        ])->get_data();

        $this->assertFalse($resumed['cancel_at_period_end']);
        $this->assertTrue($resumed['auto_renew']);
    }

    public function testResumingSomethingThatWasNeverCancelledIsRefused(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $created = $this->post('/billing/checkout', [
            'plan_id' => $this->makePlan([], 5.00),
            'cycle'   => 'monthly',
        ])->get_data()['subscription'];

        $response = $this->post('/billing/subscription/resume', [
            'subscription_id' => $created['id'],
        ]);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_not_cancelled', $response->get_data()['code']);
    }

    public function testAnotherMembersSubscriptionIsNotFound(): void
    {
        $owner = $this->seedMember();
        $this->signIn($owner['account_id']);
        $created = $this->post('/billing/checkout', [
            'plan_id' => $this->makePlan([], 5.00),
            'cycle'   => 'monthly',
        ])->get_data()['subscription'];

        $intruder = $this->seedMember();
        $this->signIn($intruder['account_id']);

        $response = $this->post('/billing/subscription/cancel', [
            'subscription_id' => $created['id'],
        ]);

        $this->assertSame(404, $response->get_status());
        $this->assertSame([], $this->get('/billing/subscription')->get_data()['items']);
    }

    // ------------------------------------------------------------- the sweep

    public function testExpirySeparatesEndedFromUnpaid(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $ending = $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], []);
        $unpaid = $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], []);

        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        // One was cancelled at period end; the other is still trying to renew.
        $wpdb->update(
            $wpdb->prefix . 'fc_subscriptions',
            ['end_date' => $yesterday, 'cancel_at_period_end' => 1, 'auto_renew' => 0],
            ['id' => $ending]
        );
        $wpdb->update(
            $wpdb->prefix . 'fc_subscriptions',
            ['end_date' => $yesterday, 'cancel_at_period_end' => 0, 'auto_renew' => 1],
            ['id' => $unpaid]
        );

        (new SubscriptionService())->runExpiry();

        // Expiring the renewing one immediately would cut off a member whose
        // card merely needs re-trying, which is what dunning is for.
        $this->assertSame('expired', $this->statusOf($ending));
        $this->assertSame('past_due', $this->statusOf($unpaid));
    }

    public function testDunningNotifiesInsideTheGracePeriodAndSuspendsAfterIt(): void
    {
        global $wpdb;

        $recent = $this->seedMember();
        $stale  = $this->seedMember();
        $coach  = $this->seedTrainer();

        $recentId = $this->subscribe($recent['fc_user_id'], $coach['trainer_id'], []);
        $staleId  = $this->subscribe($stale['fc_user_id'], $coach['trainer_id'], []);

        $wpdb->update($wpdb->prefix . 'fc_subscriptions', [
            'status'   => 'past_due',
            'end_date' => gmdate('Y-m-d', strtotime('-2 days')),
        ], ['id' => $recentId]);

        $wpdb->update($wpdb->prefix . 'fc_subscriptions', [
            'status'   => 'past_due',
            'end_date' => gmdate('Y-m-d', strtotime('-60 days')),
        ], ['id' => $staleId]);

        $result = (new PaymentService())->runDunning();

        $this->assertGreaterThanOrEqual(1, $result['notified']);
        $this->assertGreaterThanOrEqual(1, $result['suspended']);

        $this->assertSame('past_due', $this->statusOf($recentId), 'Still inside the grace period.');
        $this->assertSame('expired', $this->statusOf($staleId));

        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications
              WHERE account_id = %d AND type = 'subscription'",
            $recent['account_id']
        )));
    }

    // ------------------------------------------------------------ the webhook

    public function testTheWebhookIsPublicSignatureCheckedAndIdempotent(): void
    {
        global $wpdb;

        // A fresh event id per run. The dedupe key is a transient with a
        // seven-day TTL — correct for production, where a gateway never reuses
        // an event id, and fatal for a test that hardcodes one: the second run
        // sees its own first run's marker and treats a real event as a
        // duplicate. Caught by running the suite rather than the file.
        $eventId = 'evt_' . wp_generate_password(12, false);

        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $created = $this->post('/billing/checkout', [
            'plan_id' => $this->makePlan([], 12.00),
            'cycle'   => 'monthly',
        ])->get_data()['subscription'];

        $externalId = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT gateway_subscription_id FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $created['id']
        ));

        $this->registerFakeGateway($externalId);

        // Reaches the endpoint with no session at all — a gateway cannot
        // authenticate, and the CSRF gate has to let it through.
        $this->signOut();

        $bad = $this->webhook('fake', ['signature' => 'wrong', 'event_id' => $eventId]);
        $this->assertSame(401, $bad->get_status(), 'A bad signature is the one non-2xx.');

        $ok = $this->webhook('fake', ['signature' => 'good', 'event_id' => $eventId]);
        $this->assertSame(200, $ok->get_status());

        $endAfterFirst = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT end_date FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $created['id']
        ));

        // The same event again — gateways retry. A duplicate must be a 200 that
        // changes nothing, or the subscription is extended twice.
        $again = $this->webhook('fake', ['signature' => 'good', 'event_id' => $eventId]);

        $this->assertSame(200, $again->get_status());
        $this->assertTrue($again->get_data()['duplicate']);
        $this->assertSame($endAfterFirst, (string) $wpdb->get_var($wpdb->prepare(
            "SELECT end_date FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $created['id']
        )));

        // Exactly one payment row, not two.
        $this->assertSame(2, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_payments WHERE user_id = %d",
            $member['fc_user_id']
        )), 'The checkout payment plus one renewal — not two renewals.');
    }

    public function testAnUnknownEventTypeIsAcceptedAndIgnored(): void
    {
        $this->registerFakeGateway('sub_nothing');
        $this->signOut();

        $response = $this->webhook('fake', [
            'signature' => 'good',
            'event_id'  => 'evt_' . wp_generate_password(12, false),
            'type'      => 'something.new',
        ]);

        // 200, so the gateway stops retrying an event we will never act on.
        // Gateways add event types constantly.
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['ignored']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * A gateway whose signature is the literal string "good".
     *
     * Enough to exercise verify-then-parse, dedupe and the renewal path without
     * a network or a real processor — which is the point of the seam.
     */
    private function registerFakeGateway(string $externalSubscriptionId): void
    {
        add_filter(
            'fitnessclub/payment_gateways',
            static function (array $gateways) use ($externalSubscriptionId): array {
                $gateways['fake'] = new FakeGateway($externalSubscriptionId);

                return $gateways;
            }
        );

        GatewayRegistry::flush();
    }

    private function statusOf(int $subscriptionId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $subscriptionId
        ));
    }

    /**
     * @return array{account_id:int,fc_user_id:int}
     */
    private function seedMember(): array
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_USER);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_users', [
            'account_id'   => $accountId,
            'display_name' => 'Billing Fixture',
            'timezone'     => 'UTC',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->noisy[] = $accountId;

        return ['account_id' => $accountId, 'fc_user_id' => (int) $wpdb->insert_id];
    }

    /**
     * @return array{account_id:int,trainer_id:int}
     */
    private function seedTrainer(): array
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_TRAINER);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_trainers', [
            'account_id'   => $accountId,
            'display_name' => 'Coach ' . wp_generate_password(5, false),
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $trainerId          = (int) $wpdb->insert_id;
        $this->trainerIds[] = $trainerId;

        return ['account_id' => $accountId, 'trainer_id' => $trainerId];
    }

    /**
     * @param array<string,mixed> $features
     */
    private function makePlan(array $features, float $monthly, ?int $trainerId = null): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_plans', [
            'owner_type'    => null === $trainerId ? 'platform' : 'trainer',
            'trainer_id'    => $trainerId,
            'plan_name'     => 'Billing Fixture ' . wp_generate_password(6, false),
            'slug'          => 'billing-fixture-' . wp_generate_password(8, false),
            'currency'      => 'USD',
            'price_monthly' => $monthly,
            'features'      => wp_json_encode($features),
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $id              = (int) $wpdb->insert_id;
        $this->planIds[] = $id;

        return $id;
    }

    /**
     * An active subscription written directly — for the merge tests, which are
     * about the resolution rules rather than about checkout.
     *
     * @param array<string,mixed> $features
     */
    private function subscribe(int $fcUserId, ?int $trainerId, array $features): int
    {
        global $wpdb;

        $planId = $this->makePlan($features, 9.99, $trainerId);
        $now    = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_subscriptions', [
            'user_id'           => $fcUserId,
            'plan_id'           => $planId,
            'trainer_id'        => $trainerId,
            'status'            => 'active',
            'subscription_type' => 'monthly',
            'start_date'        => gmdate('Y-m-d'),
            'end_date'          => gmdate('Y-m-d', strtotime('+30 days')),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        EntitlementService::flush($fcUserId);

        return (int) $wpdb->insert_id;
    }

    private function link(int $fcUserId, int $trainerId): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_trainers', [
            'user_id'    => $fcUserId,
            'trainer_id' => $trainerId,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        EntitlementService::flush($fcUserId);
    }

    private function get(string $route): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1' . $route));
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        $request->set_header('content-type', 'application/json');
        $request->set_header('X-FC-CSRF', $this->csrf());
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }

    /**
     * Deliberately sends **no** CSRF header: a gateway has none.
     *
     * @param array<string,mixed> $body
     */
    private function webhook(string $gateway, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/fitnessclub/v1/billing/webhook/' . $gateway);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }
}
