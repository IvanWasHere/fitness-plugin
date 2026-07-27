<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Services\ActivityService;
use FitnessClub\Services\NotificationService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/notifications/*`, `/activity` and `/user/preferences` (W2.4).
 *
 * Three things here are easy to get subtly wrong and invisible when you do:
 *
 *   1. **preferences are honoured at the write, not the read.** Filtering muted
 *      categories out of the list would leave the rows in the table and the
 *      unread count wrong — the badge would show 3 over an inbox of 1.
 *   2. **an inbox belongs to an account, not to a member profile.** A trainer has
 *      no `fc_users` row, and gating these on `fc_access_app` would answer
 *      `fc_no_member_profile` to a trainer reading their own notifications.
 *   3. **preferences merge.** `fc_users.preferences` is a shared blob; writing
 *      the notifications key wholesale silently drops the privacy settings
 *      stored beside it.
 */
final class NotificationApiTest extends IntegrationTestCase
{
    private NotificationService $notifications;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new NotificationService();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Notifications and activity key off the account id and have no foreign
        // key, so they do not cascade with the account.
        foreach ($this->noisy as $accountId) {
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['account_id' => $accountId]);
            $wpdb->delete($wpdb->prefix . 'fc_activity_log', ['account_id' => $accountId]);
        }
        $this->noisy = [];

        parent::tearDown();
    }

    /** @var int[] */
    private array $noisy = [];

    // --------------------------------------------------------- preferences

    public function testAMutedCategoryIsNeverWrittenRatherThanHiddenOnRead(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $this->assertGreaterThan(
            0,
            $this->notifications->notify($member['account_id'], 'achievement', 'First PR'),
            'On by default.'
        );

        $this->put('/user/preferences', ['notifications' => ['achievement' => false]]);

        $suppressed = $this->notifications->notify($member['account_id'], 'achievement', 'Second PR');

        $this->assertSame(0, $suppressed, 'A muted category returns 0 rather than a row id.');

        $data = $this->get('/notifications')->get_data();

        // One row, one unread. If muting filtered on read instead, the row would
        // still be here and the count would disagree with the list.
        $this->assertCount(1, $data['items']);
        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['unread_count']);
    }

    public function testACategoryNobodyHasAnOpinionAboutIsOn(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // Muting one category must not mute the rest — absent means on, so a
        // category added in a later release does not arrive silently off for
        // everyone who already has a preferences blob.
        $this->put('/user/preferences', ['notifications' => ['achievement' => false]]);

        $preferences = $this->get('/user/preferences')->get_data()['notifications'];

        $this->assertFalse($preferences['achievement']);
        $this->assertTrue($preferences['workout']);
        $this->assertTrue($preferences['system']);
        $this->assertCount(count(NotificationService::TYPES), $preferences);
    }

    public function testUpdatingNotificationSettingsLeavesTheRestOfThePreferencesBlobAlone(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        // Something else already lives in the blob — privacy settings, as the
        // profile screen will store them.
        $wpdb->update(
            $wpdb->prefix . 'fc_users',
            ['preferences' => wp_json_encode(['privacy' => ['share_progress' => false], 'locale_hint' => 'en'])],
            ['id' => $member['fc_user_id']]
        );

        $this->put('/user/preferences', ['notifications' => ['message' => false]]);

        $stored = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT preferences FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $member['fc_user_id']
        )), true);

        $this->assertFalse($stored['notifications']['message']);
        $this->assertFalse($stored['privacy']['share_progress'], 'Privacy settings survive.');
        $this->assertSame('en', $stored['locale_hint']);
    }

    public function testAnUnknownSettingIsRefusedRatherThanStored(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $response = $this->put('/user/preferences', ['notifications' => ['telepathy' => false]]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_preferences_empty', $response->get_data()['code']);
    }

    // --------------------------------------------------------- the inbox

    public function testTheInboxPagesAndCanBeNarrowedToUnread(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        for ($i = 0; $i < 5; $i++) {
            $this->notify($member['account_id'], 'workout', 'Session ' . $i);
        }

        $first = $this->get('/notifications?per_page=2')->get_data();

        $this->assertCount(2, $first['items']);
        $this->assertSame(5, $first['total']);
        $this->assertSame(5, $first['unread_count']);

        $second = $this->get('/notifications?per_page=2&page=2')->get_data();

        $this->assertCount(2, $second['items']);
        $this->assertNotSame(
            $first['items'][0]['id'],
            $second['items'][0]['id'],
            'Page two is a different page.'
        );

        $this->post('/notifications/' . $first['items'][0]['id'] . '/read', []);

        $unread = $this->get('/notifications?unread_only=true')->get_data();

        $this->assertSame(4, $unread['total']);
        $this->assertSame(4, $unread['unread_count']);
    }

    public function testEveryReadStateChangeAnswersWithTheCountTheBadgeNeeds(): void
    {
        $member = $this->seedMember();
        $this->signIn($member['account_id']);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->notify($member['account_id'], 'system', 'Notice ' . $i);
        }

        // A client that had to issue a second request to learn the count would
        // render the stale one in between — the number just cleared.
        $read = $this->post('/notifications/' . $ids[0] . '/read', [])->get_data();
        $this->assertSame(2, $read['unread_count']);

        // Idempotent: tapping an already-read notification is a no-op that still
        // answers, because the client cannot know which it was.
        $again = $this->post('/notifications/' . $ids[0] . '/read', [])->get_data();
        $this->assertSame(2, $again['unread_count']);

        $dismissed = $this->delete('/notifications/' . $ids[1])->get_data();
        $this->assertSame(1, $dismissed['unread_count']);

        $all = $this->post('/notifications/read-all', [])->get_data();
        $this->assertSame(1, $all['marked']);
        $this->assertSame(0, $all['unread_count']);
    }

    public function testAnotherAccountsNotificationIsNotFoundRatherThanForbidden(): void
    {
        $owner = $this->seedMember();
        $this->signIn($owner['account_id']);
        $id = $this->notify($owner['account_id'], 'system', 'Private');

        $intruder = $this->seedMember();
        $this->signIn($intruder['account_id']);

        // 404, not 403: a 403 confirms the row exists.
        $this->assertSame(404, $this->post('/notifications/' . $id . '/read', [])->get_status());
        $this->assertSame(404, $this->delete('/notifications/' . $id)->get_status());
        $this->assertSame([], $this->get('/notifications')->get_data()['items']);

        // read-all is scoped too — it must not reach across accounts.
        $this->post('/notifications/read-all', []);

        $this->signIn($owner['account_id']);
        $this->assertSame(1, $this->get('/notifications')->get_data()['unread_count']);
    }

    public function testATrainerHasAnInboxDespiteHavingNoMemberProfile(): void
    {
        $accountId = $this->makeAccount(Capabilities::ROLE_TRAINER);
        $this->noisy[] = $accountId;

        $this->signIn($accountId);
        $this->notify($accountId, 'message', 'A client replied');

        $response = $this->get('/notifications');

        // Gating on fc_access_app — a member-only capability — would answer 403
        // here, and asMember() would answer fc_no_member_profile.
        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $response->get_data()['items']);

        // Preferences genuinely do need a profile, and say so rather than 500.
        $this->assertSame(403, $this->get('/user/preferences')->get_status());
    }

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $this->signOut();

        $this->assertSame(401, $this->get('/notifications')->get_status());
        $this->assertSame(401, $this->get('/activity')->get_status());
    }

    // --------------------------------------------------------- the feed

    public function testTheActivityFeedPagesAndExcludesAuditRows(): void
    {
        $member = $this->seedMember();
        $this->noisy[] = $member['account_id'];
        $this->signIn($member['account_id']);

        $activity = new ActivityService();

        $activity->record($member['account_id'], 'workout.completed', 'Upper Body Power');
        $activity->record($member['account_id'], 'nutrition.logged', 'Lunch');
        $activity->record($member['account_id'], 'health.updated', 'Weight 78.5 kg');

        // An administrator correcting the member's data. It belongs in the audit
        // trail, not in the member's "here is what you did" list.
        $activity->audit(
            $member['account_id'],
            $this->makeAccount(Capabilities::ROLE_ADMIN),
            'health.updated',
            'Admin corrected weight',
            '',
            null,
            null,
            ['before' => 80, 'after' => 78.5]
        );

        $data = $this->get('/activity')->get_data();

        $this->assertSame(3, $data['total'], 'The audit row is not in the feed.');
        $this->assertSame('health.updated', $data['items'][0]['type'], 'Newest first.');

        // The filter's options come from what the member actually has, so it can
        // never offer a type that returns nothing.
        $this->assertSame(
            ['health.updated', 'nutrition.logged', 'workout.completed'],
            $data['available_types']
        );

        $filtered = $this->get('/activity?types=nutrition.logged')->get_data();

        $this->assertSame(1, $filtered['total']);
        $this->assertSame('Lunch', $filtered['items'][0]['title']);
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
            'display_name' => 'Notify Fixture',
            'timezone'     => 'UTC',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->noisy[] = $accountId;

        return ['account_id' => $accountId, 'fc_user_id' => (int) $wpdb->insert_id];
    }

    private function notify(int $accountId, string $type, string $title): int
    {
        $id = $this->notifications->notify($accountId, $type, $title);

        $this->assertGreaterThan(0, $id, 'Fixture notification should be created.');

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
