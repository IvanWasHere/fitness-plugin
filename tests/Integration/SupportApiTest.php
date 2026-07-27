<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/support/*` (W3.3).
 *
 * **Internal notes are the rule worth the most tests.** They are staff-only
 * commentary written on the same ticket the member reads, and the only thing
 * keeping them apart is a WHERE clause. A leak here is not a cosmetic bug — it
 * is showing a customer what staff said about them — so it is asserted on the
 * detail view, on the reply count, and on a member's attempt to write one.
 *
 * The rest is the queue's own behaviour: who may change what, what counts as a
 * first response, and what reopens a closed ticket.
 */
final class SupportApiTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $ticketIds = [];

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

        foreach ($this->ticketIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_ticket_replies', ['ticket_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_tickets', ['id' => $id]);
        }
        foreach ($this->noisy as $accountId) {
            $wpdb->delete($wpdb->prefix . 'fc_notifications', ['account_id' => $accountId]);
        }

        $this->ticketIds = $this->noisy = [];

        parent::tearDown();
    }

    // ------------------------------------------------------- internal notes

    public function testAnInternalNoteIsInvisibleToTheMemberItIsAbout(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Refund please');

        $this->signIn($staff);
        $this->post("/support/tickets/{$ticketId}/replies", [
            'message'          => 'Third refund request from this account — check with the owner.',
            'is_internal_note' => true,
        ]);
        $this->post("/support/tickets/{$ticketId}/replies", [
            'message' => 'Thanks for getting in touch, we are looking into it.',
        ]);

        // Staff see both.
        $staffView = $this->get("/support/tickets/{$ticketId}")->get_data();
        $this->assertCount(2, $staffView['replies']);
        $this->assertTrue($staffView['replies'][0]['is_internal_note']);

        // The member sees exactly one, and it is the public one.
        $this->signIn($member);
        $memberView = $this->get("/support/tickets/{$ticketId}")->get_data();

        $this->assertCount(1, $memberView['replies']);
        $this->assertFalse($memberView['replies'][0]['is_internal_note']);
        $this->assertStringContainsString('looking into it', (string) $memberView['replies'][0]['message']);

        // And nothing in the payload hints at the note's existence.
        $this->assertStringNotContainsString(
            'refund request from this account',
            wp_json_encode($memberView)
        );
    }

    public function testTheReplyCountExcludesInternalNotes(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Counting');

        $this->signIn($staff);
        $this->post("/support/tickets/{$ticketId}/replies", ['message' => 'Note', 'is_internal_note' => true]);

        $this->signIn($member);
        $list = $this->get('/support/tickets')->get_data();

        // A member seeing "3 replies" over a thread with one is being told
        // staff have been talking about them.
        $this->assertSame(0, $list['items'][0]['reply_count']);
    }

    public function testAMemberCannotWriteAnInternalNoteEvenByAsking(): void
    {
        global $wpdb;

        $member   = $this->member();
        $ticketId = $this->openTicket($member, 'Sneaky');

        $this->signIn($member);
        $this->post("/support/tickets/{$ticketId}/replies", [
            'message'          => 'Not a note',
            'is_internal_note' => true,
        ]);

        // Dropped rather than refused — the field simply is not theirs.
        $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT is_internal_note FROM {$wpdb->prefix}fc_ticket_replies
              WHERE ticket_id = %d ORDER BY id DESC LIMIT 1",
            $ticketId
        )));
    }

    public function testOperationalFieldsAreStaffOnly(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Assignment');

        $this->signIn($staff);
        $this->patch("/support/tickets/{$ticketId}", ['assigned_to_account_id' => $staff]);
        $staffView = $this->get("/support/tickets/{$ticketId}")->get_data();

        $this->assertArrayHasKey('assignee_name', $staffView);
        $this->assertSame($staff, $staffView['assigned_to_account_id']);

        $this->signIn($member);
        $memberView = $this->get("/support/tickets/{$ticketId}")->get_data();

        // Who it is assigned to and how fast it was answered say more about
        // staffing than about the member's problem.
        $this->assertArrayNotHasKey('assignee_name', $memberView);
        $this->assertArrayNotHasKey('first_response_at', $memberView);
        $this->assertArrayNotHasKey('author_email', $memberView);
    }

    // ------------------------------------------------------------- the queue

    public function testAMemberSeesOnlyTheirOwnAndStaffSeeEverything(): void
    {
        $mine   = $this->member();
        $theirs = $this->member();
        $staff  = $this->staff();

        $mineId   = $this->openTicket($mine, 'Mine');
        $theirsId = $this->openTicket($theirs, 'Theirs');

        $this->signIn($mine);
        $list = $this->get('/support/tickets')->get_data();

        $this->assertSame(1, $list['total']);
        $this->assertFalse($list['is_staff']);
        $this->assertSame('Mine', $list['items'][0]['subject']);

        // Somebody else's ticket is a 404 — a support queue should not confirm
        // which ids exist.
        $this->assertSame(404, $this->get("/support/tickets/{$theirsId}")->get_status());

        $this->signIn($staff);
        $staffList = $this->get('/support/tickets')->get_data();

        $this->assertTrue($staffList['is_staff']);
        $this->assertGreaterThanOrEqual(2, $staffList['total']);
        $this->assertSame(200, $this->get("/support/tickets/{$mineId}")->get_status());
    }

    public function testStaffMayReprioritiseButAMemberMayOnlyCloseAndReopen(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Priority');

        $this->signIn($member);

        // The member's whole vocabulary.
        $refused = $this->patch("/support/tickets/{$ticketId}", ['priority' => 'urgent']);
        $this->assertSame(403, $refused->get_status());
        $this->assertSame('fc_not_allowed', $refused->get_data()['code']);

        $closed = $this->patch("/support/tickets/{$ticketId}", ['status' => 'closed'])->get_data();
        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['resolved_at']);

        $this->signIn($staff);
        $bumped = $this->patch("/support/tickets/{$ticketId}", ['priority' => 'urgent'])->get_data();
        $this->assertSame('urgent', $bumped['priority']);
    }

    public function testAssigningAnOpenTicketPicksItUp(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Unclaimed');

        $this->signIn($staff);
        $assigned = $this->patch("/support/tickets/{$ticketId}", [
            'assigned_to_account_id' => $staff,
        ])->get_data();

        // Claiming it is progress; leaving it `open` would keep it looking
        // unclaimed in the queue.
        $this->assertSame('in_progress', $assigned['status']);
    }

    public function testTheFirstStaffReplyStampsFirstResponseAndOnlyTheFirst(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Timing');

        $this->signIn($staff);

        // An internal note is not a response — it is staff talking to each
        // other, and the member sees nothing happen.
        $this->post("/support/tickets/{$ticketId}/replies", [
            'message'          => 'Looks like a duplicate',
            'is_internal_note' => true,
        ]);

        $afterNote = $this->get("/support/tickets/{$ticketId}")->get_data();
        $this->assertNull($afterNote['first_response_at']);
        $this->assertSame('open', $afterNote['status'], 'A note does not move the ticket.');

        $this->post("/support/tickets/{$ticketId}/replies", ['message' => 'On it!']);
        $first = $this->get("/support/tickets/{$ticketId}")->get_data();

        $this->assertNotNull($first['first_response_at']);
        $this->assertSame('waiting_user', $first['status']);

        $this->post("/support/tickets/{$ticketId}/replies", ['message' => 'Any update?']);
        $second = $this->get("/support/tickets/{$ticketId}")->get_data();

        $this->assertSame(
            $first['first_response_at'],
            $second['first_response_at'],
            'Recorded once and never moved.'
        );
    }

    public function testAMemberReplyingToAResolvedTicketReopensIt(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Reopen');

        $this->signIn($staff);
        $this->patch("/support/tickets/{$ticketId}", ['status' => 'resolved']);

        $this->signIn($member);

        // Resolved is not closed: the member can still answer, and doing so
        // means staff have to look again.
        $reopened = $this->post("/support/tickets/{$ticketId}/replies", [
            'message' => 'That did not fix it.',
        ])->get_data();

        $this->assertSame('open', $reopened['status']);
    }

    public function testAMemberCannotReplyToAClosedTicketButStaffCan(): void
    {
        $member = $this->member();
        $staff  = $this->staff();

        $ticketId = $this->openTicket($member, 'Closed');

        $this->signIn($staff);
        $this->patch("/support/tickets/{$ticketId}", ['status' => 'closed']);

        $this->signIn($member);
        $refused = $this->post("/support/tickets/{$ticketId}/replies", ['message' => 'Hello?']);

        $this->assertSame(409, $refused->get_status());
        $this->assertSame('fc_ticket_closed', $refused->get_data()['code']);

        $this->signIn($staff);
        $this->assertSame(
            201,
            $this->post("/support/tickets/{$ticketId}/replies", ['message' => 'Following up'])->get_status()
        );
    }

    public function testAnIncompleteTicketIsRefused(): void
    {
        $member = $this->member();
        $this->signIn($member);

        $response = $this->post('/support/tickets', ['subject' => 'No body', 'message' => '   ']);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('fc_ticket_incomplete', $response->get_data()['code']);
    }

    public function testAnUnknownCategoryFallsBackRatherThanFailing(): void
    {
        $member = $this->member();
        $this->signIn($member);

        $created = $this->post('/support/tickets', [
            'subject'  => 'Odd category',
            'message'  => 'Something is wrong.',
            'category' => 'not-a-category',
            'priority' => 'catastrophic',
        ]);

        $this->ticketIds[] = (int) $created->get_data()['id'];

        // A bad enum from a `<select>` is a client bug or a probe; neither
        // deserves a 400 that blocks a genuine support request.
        $this->assertSame(201, $created->get_status());
        $this->assertSame('general', $created->get_data()['category']);
        $this->assertSame('medium', $created->get_data()['priority']);
    }

    // ----------------------------------------------------------------- FAQ

    public function testTheFaqComesFromSettingsAndFallsBackToTheBundledAnswers(): void
    {
        $member = $this->member();
        $this->signIn($member);

        $bundled = $this->get('/support/faq')->get_data()['items'];

        // The prototype shipped these inline in the JS bundle, so correcting a
        // wrong answer meant a rebuild.
        $this->assertNotEmpty($bundled);
        $this->assertArrayHasKey('q', $bundled[0]);

        $options = FitnessClub()->options;
        $before  = $options->get('support_faq');

        $options->set(
            'support_faq',
            wp_json_encode([['q' => 'Custom question?', 'a' => 'Custom answer.']])
        );

        $custom = $this->get('/support/faq')->get_data()['items'];

        $this->assertCount(1, $custom);
        $this->assertSame('Custom question?', $custom[0]['q']);

        $options->set('support_faq', is_string($before) ? $before : '');
    }

    public function testAnAnonymousCallerGetsNothing(): void
    {
        $this->signOut();

        $this->assertSame(401, $this->get('/support/tickets')->get_status());
        $this->assertSame(401, $this->get('/support/faq')->get_status());
    }

    // --------------------------------------------------------------- helpers

    private function member(): int
    {
        $accountId       = $this->makeAccount(Capabilities::ROLE_USER);
        $this->noisy[]   = $accountId;

        return $accountId;
    }

    /** An account that can work the queue. */
    private function staff(): int
    {
        $accountId     = $this->makeAccount(Capabilities::ROLE_ADMIN);
        $this->noisy[] = $accountId;

        return $accountId;
    }

    private function openTicket(int $accountId, string $subject): int
    {
        $this->signIn($accountId);

        $response = $this->post('/support/tickets', [
            'subject' => $subject,
            'message' => 'Details about ' . $subject,
        ]);

        $this->assertSame(201, $response->get_status(), 'Fixture ticket should be created.');

        $id                = (int) $response->get_data()['id'];
        $this->ticketIds[] = $id;

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
    private function patch(string $route, array $body): WP_REST_Response
    {
        return $this->send('PATCH', $route, $body);
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
