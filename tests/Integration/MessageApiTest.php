<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Services\EntitlementService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/messages/*` (W3.1).
 *
 * The quota rules are what this package is really about, and each one is
 * invisible until it bites the wrong person:
 *
 *   1. **the quota is per thread, not per user** (Q3) — a member coached by two
 *      trainers gets a separate allowance for each, or one coach's conversation
 *      silently consumes the other's;
 *   2. **only the member's own sends count** — a chatty trainer must not be able
 *      to exhaust their client's allowance;
 *   3. **the window is rolling** — seven days back from now, not a calendar week
 *      that resets at a boundary the member cannot see;
 *   4. **`limit: null` means unlimited** and must never be coerced to 0, which
 *      would silence the member on their best plan.
 */
final class MessageApiTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $threadIds = [];

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

        foreach ($this->threadIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_message_threads', ['id' => $id]);
        }
        foreach ($this->noisy as $accountId) {
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['account_id' => $accountId]);
        }
        foreach ($this->trainerIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_trainers', ['id' => $id]);
        }
        foreach ($this->planIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_plans', ['id' => $id]);
        }

        $this->threadIds = $this->trainerIds = $this->planIds = $this->noisy = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------- the quota

    public function testTheQuotaIsPerThreadSoOneTrainerCannotConsumeAnothers(): void
    {
        $member = $this->seedMember();

        // Two trainers, two subscriptions, two allowances: 2 messages with the
        // first coach and 5 with the second.
        $coachA = $this->seedTrainer('Coach A');
        $coachB = $this->seedTrainer('Coach B');

        $this->subscribe($member['fc_user_id'], $coachA['trainer_id'], 2);
        $this->subscribe($member['fc_user_id'], $coachB['trainer_id'], 5);

        $threadA = $this->seedThread($member['fc_user_id'], $coachA['trainer_id']);
        $threadB = $this->seedThread($member['fc_user_id'], $coachB['trainer_id']);

        $this->signIn($member['account_id']);

        $this->assertSame(201, $this->post("/messages/threads/{$threadA}", ['message' => 'One'])->get_status());
        $this->assertSame(201, $this->post("/messages/threads/{$threadA}", ['message' => 'Two'])->get_status());

        $blocked = $this->post("/messages/threads/{$threadA}", ['message' => 'Three']);

        $this->assertSame(429, $blocked->get_status());
        $this->assertSame('fc_quota_exceeded', $blocked->get_data()['code']);
        $this->assertSame(2, $blocked->get_data()['data']['limit']);
        $this->assertSame(2, $blocked->get_data()['data']['used']);
        $this->assertNotNull($blocked->get_data()['data']['resets_at']);

        // The other conversation is untouched. A global cap would have silenced
        // this one too.
        $this->assertSame(
            201,
            $this->post("/messages/threads/{$threadB}", ['message' => 'Still allowed'])->get_status()
        );
    }

    public function testATrainersRepliesDoNotConsumeTheClientsAllowance(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Chatty Coach');

        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], 2);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        // The trainer writes five times.
        $this->signIn($trainer['account_id']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(
                201,
                $this->post("/messages/threads/{$threadId}", ['message' => "Reply {$i}"])->get_status()
            );
        }

        // The member still has their full allowance.
        $this->signIn($member['account_id']);

        $quota = $this->get('/messages/threads')->get_data()['items'][0]['quota'];

        $this->assertSame(2, $quota['limit']);
        $this->assertSame(0, $quota['used'], 'Trainer replies are not the client\'s messages.');
        $this->assertSame(2, $quota['remaining']);

        $this->assertSame(201, $this->post("/messages/threads/{$threadId}", ['message' => 'Mine'])->get_status());
    }

    public function testTheWindowIsRollingRatherThanCalendar(): void
    {
        global $wpdb;

        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Window Coach');

        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], 2);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'One']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'Two']);

        $this->assertSame(429, $this->post("/messages/threads/{$threadId}", ['message' => 'Three'])->get_status());

        // Age the first message past the window. There is no other honest way to
        // test a rolling window than to move the clock on the data.
        $oldest = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(id) FROM {$wpdb->prefix}fc_messages WHERE thread_id = %d",
            $threadId
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_messages
                SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE id = %d",
            $oldest
        ));

        // A slot freed as that one dropped out — not because a week ticked over.
        $this->assertSame(
            201,
            $this->post("/messages/threads/{$threadId}", ['message' => 'Now allowed'])->get_status()
        );
    }

    public function testAnUnlimitedPlanIsNotTreatedAsZero(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Unlimited Coach');

        // NULL max_messages_per_week means unlimited. Coercing it to 0 would
        // silence the member on the best plan the product sells.
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);

        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(
                201,
                $this->post("/messages/threads/{$threadId}", ['message' => "Message {$i}"])->get_status()
            );
        }

        $quota = $this->get('/messages/threads')->get_data()['items'][0]['quota'];

        $this->assertNull($quota['limit']);
        $this->assertNull($quota['remaining']);
        $this->assertNull($quota['resets_at']);
    }

    public function testAMemberWithNoPlanCannotMessageAtAll(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Unpaid Coach');

        // No subscription: the free tier applies, and `can_message` is false.
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);

        $response = $this->post("/messages/threads/{$threadId}", ['message' => 'Hello?']);

        // The feature gate, not the quota — so the client can offer an upgrade
        // rather than showing "you have used this week's messages".
        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_feature_unavailable', $response->get_data()['code']);
    }

    // ------------------------------------------------------------- the thread

    public function testAThreadYouAreNotOnIsNotFoundRatherThanForbidden(): void
    {
        $owner   = $this->seedMember();
        $trainer = $this->seedTrainer('Private Coach');
        $this->subscribe($owner['fc_user_id'], $trainer['trainer_id'], 10);
        $threadId = $this->seedThread($owner['fc_user_id'], $trainer['trainer_id']);

        $intruder = $this->seedMember();
        $this->signIn($intruder['account_id']);

        // 403 would confirm the conversation exists — a disclosure in itself.
        $this->assertSame(404, $this->get("/messages/threads/{$threadId}")->get_status());
        $this->assertSame(404, $this->post("/messages/threads/{$threadId}", ['message' => 'Hi'])->get_status());
        $this->assertSame([], $this->get('/messages/threads')->get_data()['items']);
    }

    public function testHistoryPagesBackwardsFromTheNewest(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('History Coach');
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);
        for ($i = 1; $i <= 5; $i++) {
            $this->post("/messages/threads/{$threadId}", ['message' => "Message {$i}"]);
        }

        $first = $this->get("/messages/threads/{$threadId}?limit=2")->get_data();

        // Newest two, returned oldest-first for rendering.
        $this->assertCount(2, $first['messages']);
        $this->assertSame('Message 4', $first['messages'][0]['message']);
        $this->assertSame('Message 5', $first['messages'][1]['message']);
        $this->assertTrue($first['has_more']);

        $older = $this->get("/messages/threads/{$threadId}?limit=2&before={$first['oldest_id']}")->get_data();

        $this->assertSame('Message 2', $older['messages'][0]['message']);
        $this->assertSame('Message 3', $older['messages'][1]['message']);
    }

    // -------------------------------------------------------- unread & poll

    public function testUnreadCountsFollowTheRecipientAndClearOnRead(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Unread Coach');
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        // The trainer writes twice; the member's counter moves, not the
        // trainer's own.
        $this->signIn($trainer['account_id']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'First']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'Second']);

        $this->assertSame(0, $this->get('/messages/unread-count')->get_data()['unread_total']);

        $this->signIn($member['account_id']);
        $this->assertSame(2, $this->get('/messages/unread-count')->get_data()['unread_total']);

        $read = $this->post("/messages/threads/{$threadId}/read", []);

        $this->assertSame(0, $read->get_data()['unread_total']);
        $this->assertSame(0, $this->get('/messages/threads')->get_data()['items'][0]['unread_count']);
    }

    public function testPollingReturnsOnlyWhatTheOtherSideSentSinceTheLastId(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Poll Coach');
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);
        $mine = $this->post("/messages/threads/{$threadId}", ['message' => 'Mine'])->get_data();

        // Nothing new: an idle poll is one indexed read and an empty array.
        $idle = $this->get('/messages/poll?since=' . $mine['message']['id'])->get_data();
        $this->assertSame([], $idle['messages']);

        $this->signIn($trainer['account_id']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'Theirs']);

        $this->signIn($member['account_id']);
        $polled = $this->get('/messages/poll?since=' . $mine['message']['id'])->get_data();

        $this->assertCount(1, $polled['messages']);
        $this->assertSame($threadId, $polled['messages'][0]['thread_id']);
        $this->assertSame(1, $polled['unread_total']);

        // Your own sends never come back from a poll — you already have them,
        // and echoing them would duplicate every message you write.
        $this->signIn($member['account_id']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'Another of mine']);

        $after = $this->get('/messages/poll?since=' . $polled['latest_id'])->get_data();
        $this->assertSame([], $after['messages']);
    }

    public function testSendingRaisesANotificationForTheOtherSideOnly(): void
    {
        global $wpdb;

        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Notify Coach');
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);
        $this->post("/messages/threads/{$threadId}", ['message' => 'Question about my form']);

        $forTrainer = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d AND type = 'message'",
            $trainer['account_id']
        ));
        $forSelf = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d",
            $member['account_id']
        ));

        $this->assertSame(1, $forTrainer);
        $this->assertSame(0, $forSelf, 'You are not notified about your own message.');
    }

    public function testAnEmptyMessageIsRefused(): void
    {
        $member  = $this->seedMember();
        $trainer = $this->seedTrainer('Empty Coach');
        $this->subscribe($member['fc_user_id'], $trainer['trainer_id'], null);
        $threadId = $this->seedThread($member['fc_user_id'], $trainer['trainer_id']);

        $this->signIn($member['account_id']);

        $response = $this->post("/messages/threads/{$threadId}", ['message' => '   ']);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_message_empty', $response->get_data()['code']);
    }

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $this->signOut();

        $this->assertSame(401, $this->get('/messages/threads')->get_status());
        $this->assertSame(401, $this->get('/messages/unread-count')->get_status());
    }

    // --------------------------------------------------------------- helpers

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
            'display_name' => 'Message Fixture',
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
    private function seedTrainer(string $name): array
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_TRAINER);
        $now       = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_trainers', [
            'account_id'   => $accountId,
            'display_name' => $name,
            'status'       => 'active',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $trainerId          = (int) $wpdb->insert_id;
        $this->trainerIds[] = $trainerId;
        $this->noisy[]      = $accountId;

        return ['account_id' => $accountId, 'trainer_id' => $trainerId];
    }

    /**
     * A plan granting `can_message` with the given weekly cap, and a
     * subscription tying it to one trainer.
     *
     * @param int|null $perWeek Null means unlimited.
     */
    private function subscribe(int $fcUserId, int $trainerId, ?int $perWeek): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        // Unlimited is expressed through the features JSON, not the column:
        // `fc_plans.max_messages_per_week` is NOT NULL DEFAULT 10, so the column
        // cannot hold "no limit" at all. The features override is the only route,
        // and it only works because `forUser()` reads it with
        // `array_key_exists` rather than `??` — see the note there.
        $features = ['can_message' => true];

        if (null === $perWeek) {
            $features['max_messages_per_week'] = null;
        }

        $wpdb->insert($wpdb->prefix . 'fc_plans', [
            'owner_type'            => 'platform',
            'plan_name'             => 'Msg Fixture ' . wp_generate_password(6, false),
            'slug'                  => 'msg-fixture-' . wp_generate_password(8, false),
            'max_messages_per_week' => $perWeek ?? 10,
            'features'              => wp_json_encode($features),
            'is_active'             => 1,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        $planId          = (int) $wpdb->insert_id;
        $this->planIds[] = $planId;

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
    }

    private function seedThread(int $fcUserId, int $trainerId): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_message_threads', [
            'user_id'    => $fcUserId,
            'trainer_id' => $trainerId,
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id                = (int) $wpdb->insert_id;
        $this->threadIds[] = $id;

        return $id;
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
        $request = new WP_REST_Request('POST', '/fitnessclub/v1' . $route);
        $request->set_header('content-type', 'application/json');
        $request->set_header('X-FC-CSRF', $this->csrf());
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }
}
