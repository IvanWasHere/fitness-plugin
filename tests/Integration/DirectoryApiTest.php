<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Services\EntitlementService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/trainers/*` and `/user/trainers/*` — the directory and request flow (W3.5).
 *
 * Three things this file exists to hold down:
 *
 *   1. **The public projection.** This is the only part of the API where one
 *      member reads another account's record, so a test asserts what is *not*
 *      in the payload — email, phone, account id — because a leak here is a leak
 *      of somebody who never agreed to be listed.
 *   2. **The Q14 ladder.** No subscription, no free slot, trainer full, already
 *      asked, too many requests — each with its own code, in that order, because
 *      telling somebody with no plan that a trainer is full sends them to the
 *      wrong fix.
 *   3. **Pending requests hold a slot.** Without it a one-trainer plan buys five
 *      requests and keeps whichever lands first.
 */
final class DirectoryApiTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $trainerIds = [];

    /** @var int[] */
    private array $planIds = [];

    /** @var int[] */
    private array $noisy = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->noisy as $accountId) {
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['account_id' => $accountId]);
        }
        foreach ($this->trainerIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_user_trainers', ['trainer_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_message_threads', ['trainer_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_trainers', ['id' => $id]);
        }
        foreach ($this->planIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_subscriptions', ['plan_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_plans', ['id' => $id]);
        }

        EntitlementService::flush();

        $this->trainerIds = $this->planIds = $this->noisy = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------- listing

    /**
     * The directory returns a public projection and nothing else.
     *
     * Asserted as an absence rather than a presence: a new column added to
     * `fc_trainers` and splatted into the response is exactly how this leaks,
     * and only a test that names the forbidden keys catches it.
     */
    public function testTheDirectoryNeverExposesContactDetails(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer('Directory Coach');

        $this->signIn($member['account_id']);

        $item = $this->trainerInDirectory($coach['trainer_id']);

        $this->assertSame('Directory Coach', $item['display_name']);

        foreach (['email', 'phone', 'account_id', 'clients'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $item, "{$forbidden} must not reach the directory");
        }
    }

    /**
     * Only trainers who are accepting and have room are listed.
     */
    public function testAClosedOrFullTrainerIsNotListed(): void
    {
        global $wpdb;

        $member  = $this->seedMember();
        $open    = $this->seedTrainer('Open Coach');
        $closed  = $this->seedTrainer('Closed Coach');
        $full    = $this->seedTrainer('Full Coach');

        $wpdb->update(
            $wpdb->prefix . 'fc_trainers',
            ['accepting_clients' => 0],
            ['id' => $closed['trainer_id']]
        );

        $wpdb->update(
            $wpdb->prefix . 'fc_trainers',
            ['max_clients' => 1],
            ['id' => $full['trainer_id']]
        );

        $this->link($this->seedMember()['fc_user_id'], $full['trainer_id'], 'active');

        $this->signIn($member['account_id']);

        $this->assertNotNull($this->trainerInDirectory($open['trainer_id']));
        $this->assertNull($this->trainerInDirectory($closed['trainer_id'], false));
        $this->assertNull($this->trainerInDirectory($full['trainer_id'], false));
    }

    /**
     * `max_clients = 0` is unconfigured, not a cap of zero.
     *
     * The same reading the accept path uses. Getting it wrong hides every
     * trainer who has never touched their profile — which on a fresh install is
     * all of them.
     */
    public function testAnUnsetClientLimitDoesNotHideATrainer(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->link($this->seedMember()['fc_user_id'], $coach['trainer_id'], 'active');

        $this->signIn($member['account_id']);

        $item = $this->trainerInDirectory($coach['trainer_id']);

        $this->assertNull($item['max_clients']);
        $this->assertTrue($item['has_capacity']);
    }

    // --------------------------------------------------------- eligibility

    /**
     * A member with no plan sees the directory and cannot request from it.
     *
     * Hiding it would remove the clearest upgrade prompt in the product, so the
     * list is populated and the reason is stated.
     */
    public function testWithoutAPlanTheDirectoryStillListsButCannotBeRequestedFrom(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->signIn($member['account_id']);

        $body = $this->get('/trainers')->get_data();

        $this->assertNotEmpty($body['items']);
        $this->assertFalse($body['eligibility']['can_request']);
        $this->assertSame('subscription_required', $body['eligibility']['reason']);

        $response = $this->post("/trainers/{$coach['trainer_id']}/request", []);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_subscription_required', $response->get_data()['code']);
    }

    /**
     * A pending request spends a slot (Q14).
     *
     * The whole point of the rule: on a one-trainer plan, asking somebody uses
     * the slot up before they have answered, so a member cannot queue five
     * requests and keep whichever lands first.
     */
    public function testAPendingRequestConsumesTheTrainerSlot(): void
    {
        $member = $this->seedMember();
        $first  = $this->seedTrainer('First Coach');
        $second = $this->seedTrainer('Second Coach');

        $this->subscribe($member['fc_user_id'], 1);
        $this->signIn($member['account_id']);

        $this->assertSame(201, $this->post("/trainers/{$first['trainer_id']}/request", [])->get_status());

        $body = $this->get('/trainers')->get_data();

        $this->assertSame(1, $body['eligibility']['trainers_used']);
        $this->assertSame('limit_reached', $body['eligibility']['reason']);

        $response = $this->post("/trainers/{$second['trainer_id']}/request", []);

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_trainer_limit_reached', $response->get_data()['code']);
        $this->assertSame(1, $response->get_data()['data']['limit']);
    }

    /**
     * Withdrawing gives the slot back.
     */
    public function testWithdrawingARequestFreesTheSlot(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], 1);
        $this->signIn($member['account_id']);

        $this->post("/trainers/{$coach['trainer_id']}/request", []);
        $withdrawn = $this->delete("/trainers/{$coach['trainer_id']}/request");

        $this->assertSame(200, $withdrawn->get_status());
        $this->assertTrue($withdrawn->get_data()['eligibility']['can_request']);
        $this->assertSame(0, $withdrawn->get_data()['eligibility']['trainers_used']);
    }

    /**
     * An unlimited plan is not a plan with zero slots.
     *
     * `max_trainers: null` means unlimited, and coercing null to 0 would lock
     * out the most generous plan on the platform.
     */
    public function testAnUnlimitedPlanCanAlwaysRequest(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], null);
        $this->signIn($member['account_id']);

        $body = $this->get('/trainers')->get_data();

        $this->assertTrue($body['eligibility']['can_request']);
        $this->assertNull($body['eligibility']['trainers_allowed']);
        $this->assertSame(201, $this->post("/trainers/{$coach['trainer_id']}/request", [])->get_status());
    }

    // ------------------------------------------------------------ requests

    /**
     * The request reaches the trainer's queue, with its message, as a
     * notification they have not muted.
     */
    public function testARequestReachesTheTrainerWithItsMessage(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], 2);
        $this->signIn($member['account_id']);

        $this->post("/trainers/{$coach['trainer_id']}/request", [
            'message' => 'I want to squat properly.',
        ]);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, request_message FROM {$wpdb->prefix}fc_user_trainers
              WHERE user_id = %d AND trainer_id = %d",
            $member['fc_user_id'],
            $coach['trainer_id']
        ), ARRAY_A);

        $this->assertSame('pending', $row['status']);
        $this->assertSame('I want to squat properly.', $row['request_message']);

        // `system`, not `workout`: a coaching request is not training activity,
        // and W2.4 drops a muted category at the write rather than at the read.
        $type = $wpdb->get_var($wpdb->prepare(
            "SELECT type FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            $coach['account_id']
        ));

        $this->assertSame('system', $type);

        // And it is in the trainer's own queue.
        $this->signIn($coach['account_id']);
        $queue = $this->get('/trainer/requests')->get_data()['items'];

        $this->assertCount(1, $queue);
        $this->assertSame('I want to squat properly.', $queue[0]['request_message']);
    }

    /**
     * Asking twice is refused, and so is asking a coach you already have.
     */
    public function testYouCannotAskTheSameTrainerTwice(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], 5);
        $this->signIn($member['account_id']);

        $this->post("/trainers/{$coach['trainer_id']}/request", []);
        $again = $this->post("/trainers/{$coach['trainer_id']}/request", []);

        $this->assertSame(409, $again->get_status());
        $this->assertSame('fc_request_exists', $again->get_data()['code']);
    }

    /**
     * Capacity is re-checked at submit — a directory is a race by design.
     */
    public function testATrainerWhoFillsUpBetweenListingAndSubmitIsRefused(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->subscribe($member['fc_user_id'], 5);
        $this->signIn($member['account_id']);

        // Listed and requestable a moment ago...
        $this->assertNotNull($this->trainerInDirectory($coach['trainer_id']));

        // ...and closed their books before the form was submitted.
        $wpdb->update(
            $wpdb->prefix . 'fc_trainers',
            ['accepting_clients' => 0],
            ['id' => $coach['trainer_id']]
        );

        $response = $this->post("/trainers/{$coach['trainer_id']}/request", []);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_trainer_at_capacity', $response->get_data()['code']);
    }

    /**
     * Three outstanding requests is the ceiling.
     */
    public function testAMemberCannotHoldMoreThanThreePendingRequests(): void
    {
        $member = $this->seedMember();

        $this->subscribe($member['fc_user_id'], null);
        $this->signIn($member['account_id']);

        for ($i = 0; $i < 3; $i++) {
            $coach = $this->seedTrainer("Coach {$i}");
            $this->assertSame(201, $this->post("/trainers/{$coach['trainer_id']}/request", [])->get_status());
        }

        $fourth  = $this->seedTrainer('Coach 4');
        $refused = $this->post("/trainers/{$fourth['trainer_id']}/request", []);

        $this->assertSame(429, $refused->get_status());
        $this->assertSame('fc_request_limit', $refused->get_data()['code']);
        $this->assertSame('pending', $refused->get_data()['data']['scope']);
    }

    // --------------------------------------------------------- my trainers

    /**
     * Leaving an active coach ends it; withdrawing a request retracts it. One
     * endpoint, and the row's status decides which happened.
     */
    public function testLeavingEndsACoachAndWithdrawsARequest(): void
    {
        global $wpdb;

        $member  = $this->seedMember();
        $active  = $this->seedTrainer('Active Coach');
        $pending = $this->seedTrainer('Pending Coach');

        $this->link($member['fc_user_id'], $active['trainer_id'], 'active');
        $this->link($member['fc_user_id'], $pending['trainer_id'], 'pending');

        $this->signIn($member['account_id']);

        $this->assertCount(2, $this->get('/user/trainers')->get_data()['items']);

        $this->delete("/user/trainers/{$active['trainer_id']}");
        $this->delete("/user/trainers/{$pending['trainer_id']}");

        $statuses = $wpdb->get_col($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fc_user_trainers WHERE user_id = %d ORDER BY trainer_id",
            $member['fc_user_id']
        ));

        // Distinct terminal states: "we stopped working together" is not the
        // same event as "I changed my mind before you answered".
        $this->assertSame(['inactive', 'withdrawn'], $statuses);
        $this->assertSame([], $this->get('/user/trainers')->get_data()['items']);
    }

    /**
     * Ending the primary relationship hands the marker on rather than leaving
     * the member with none while another coach is still active.
     */
    public function testLeavingThePrimaryCoachPromotesAnother(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $first  = $this->seedTrainer('Primary Coach');
        $second = $this->seedTrainer('Other Coach');

        $this->link($member['fc_user_id'], $first['trainer_id'], 'active');
        $this->link($member['fc_user_id'], $second['trainer_id'], 'active');

        $wpdb->update(
            $wpdb->prefix . 'fc_user_trainers',
            ['is_primary' => 1],
            ['user_id' => $member['fc_user_id'], 'trainer_id' => $first['trainer_id']]
        );

        $this->signIn($member['account_id']);
        $this->delete("/user/trainers/{$first['trainer_id']}");

        $items = $this->get('/user/trainers')->get_data()['items'];

        $this->assertCount(1, $items);
        $this->assertSame($second['trainer_id'], $items[0]['trainer_id']);
        $this->assertTrue($items[0]['is_primary']);
    }

    /**
     * One primary, and a pending request cannot be it.
     */
    public function testOnlyOneActiveCoachCanBePrimary(): void
    {
        $member  = $this->seedMember();
        $first   = $this->seedTrainer('One');
        $second  = $this->seedTrainer('Two');
        $pending = $this->seedTrainer('Not Yet');

        $this->link($member['fc_user_id'], $first['trainer_id'], 'active');
        $this->link($member['fc_user_id'], $second['trainer_id'], 'active');
        $this->link($member['fc_user_id'], $pending['trainer_id'], 'pending');

        $this->signIn($member['account_id']);

        $this->post("/user/trainers/{$first['trainer_id']}/primary", []);
        $this->post("/user/trainers/{$second['trainer_id']}/primary", []);

        $primaries = array_filter(
            $this->get('/user/trainers')->get_data()['items'],
            static fn(array $item): bool => $item['is_primary']
        );

        $this->assertCount(1, $primaries);
        $this->assertSame($second['trainer_id'], array_values($primaries)[0]['trainer_id']);

        // A trainer who has not agreed to coach them cannot be their primary.
        $refused = $this->post("/user/trainers/{$pending['trainer_id']}/primary", []);

        $this->assertSame(404, $refused->get_status());
    }

    /**
     * A trainer does not get a directory of their own.
     *
     * Refused by the **capability** gate rather than the member-profile one:
     * `fc_access_app` belongs to the member role, so a trainer never reaches
     * `asMember()`. Worth pinning either way — the directory is the one member
     * endpoint whose payload describes trainers, and "it happens to be empty for
     * them" would be a much weaker guarantee than a 403.
     */
    public function testATrainerHasNoDirectoryOfTheirOwn(): void
    {
        $coach = $this->seedTrainer();
        $this->signIn($coach['account_id']);

        $response = $this->get('/trainers');

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_forbidden', $response->get_data()['code']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>|null
     */
    private function trainerInDirectory(int $trainerId, bool $required = true): ?array
    {
        foreach ($this->get('/trainers')->get_data()['items'] as $item) {
            if ((int) $item['trainer_id'] === $trainerId) {
                return $item;
            }
        }

        if ($required) {
            $this->fail("Trainer {$trainerId} should be listed in the directory.");
        }

        return null;
    }

    /**
     * An active subscription granting `max_trainers` slots.
     */
    private function subscribe(int $fcUserId, ?int $maxTrainers): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_plans', [
            'plan_name'  => 'Directory Fixture Plan',
            'owner_type' => 'platform',
            'slug'       => 'directory-fixture-' . wp_generate_password(6, false),
            'features'   => wp_json_encode(['max_trainers' => $maxTrainers]),
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $planId          = (int) $wpdb->insert_id;
        $this->planIds[] = $planId;

        $wpdb->insert($wpdb->prefix . 'fc_subscriptions', [
            'user_id'            => $fcUserId,
            'plan_id'            => $planId,
            'status'            => 'active',
            'subscription_type' => 'monthly',
            'start_date'        => gmdate('Y-m-d'),
            'end_date'          => gmdate('Y-m-d', strtotime('+30 days')),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        EntitlementService::flush($fcUserId);
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
            'display_name' => 'Directory Fixture Member',
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
    private function seedTrainer(string $name = 'Coach'): array
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_TRAINER);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_trainers', [
            'account_id'        => $accountId,
            'display_name'      => $name,
            'specialization'    => 'Strength',
            'bio'               => 'Fixture coach.',
            // Private, and the point of the projection test: `phone` is on the
            // trainer row and must not reach a member browsing the directory.
            'phone'             => '+1 555 0000',
            'status'            => 'active',
            'accepting_clients' => 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $trainerId          = (int) $wpdb->insert_id;
        $this->trainerIds[] = $trainerId;
        $this->noisy[]      = $accountId;

        return ['account_id' => $accountId, 'trainer_id' => $trainerId];
    }

    private function link(int $fcUserId, int $trainerId, string $status = 'active'): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_trainers', [
            'user_id'       => $fcUserId,
            'trainer_id'    => $trainerId,
            'status'        => $status,
            'requested_at'  => $now,
            'assigned_date' => 'active' === $status ? gmdate('Y-m-d') : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        EntitlementService::flush($fcUserId);
    }

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

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body): WP_REST_Response
    {
        return $this->send('POST', $route, $body);
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
        // Every non-GET goes through AuthProvider::guard(), which refuses a
        // request with no CSRF token before any route code runs.
        $request->set_header('X-FC-CSRF', $this->csrf());
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }
}
