<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Services\MessageService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/trainer/*` — endpoints and guards (W3.4, first slice).
 *
 * The guards are the reason this slice exists, and Q13 draws the line:
 *
 *   1. **Read is shared.** Any actively assigned trainer sees the whole client
 *      record *including the other trainer's assignments* — two coaches
 *      programming heavy compounds in the same week is a real injury risk and
 *      this is what surfaces it.
 *   2. **Write is the assigner's alone.** Seeing a co-trainer's assignment is
 *      not permission to undo it.
 *   3. **Notes are neither** (Q15). Private to their author, and a co-trainer
 *      gets a 404 rather than a 403 so the note's existence is not confirmed.
 *   4. **A client not on your roster is a 404**, so the platform's membership
 *      cannot be enumerated by probing ids.
 */
final class TrainerApiTest extends IntegrationTestCase
{
    /** @var int[] */
    private array $trainerIds = [];

    /** @var int[] */
    private array $workoutIds = [];

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
        foreach ($this->workoutIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_workouts', ['id' => $id]);
        }
        foreach ($this->trainerIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_client_notes', ['trainer_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_user_trainers', ['trainer_id' => $id]);
            $wpdb->delete($wpdb->prefix . 'fc_trainers', ['id' => $id]);
        }

        $this->trainerIds = $this->workoutIds = $this->noisy = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------- the gate

    public function testOnlyAccountsWithTheCapabilityGetIn(): void
    {
        $this->signOut();
        $this->assertSame(401, $this->get('/trainer/clients')->get_status());

        // A member holds no `fc_manage_clients`.
        $member = $this->seedMember();
        $this->signIn($member['account_id']);
        $this->assertSame(403, $this->get('/trainer/clients')->get_status());

        $coach = $this->seedTrainer();
        $this->signIn($coach['account_id']);
        $this->assertSame(200, $this->get('/trainer/clients')->get_status());
    }

    public function testAClientNotOnYourRosterIsNotFound(): void
    {
        $member    = $this->seedMember();
        $mine      = $this->seedTrainer();
        $stranger  = $this->seedTrainer();

        $this->link($member['fc_user_id'], $mine['trainer_id']);

        $this->signIn($stranger['account_id']);

        // 404, not 403: a trainer must not be able to enumerate the platform's
        // membership by probing ids.
        foreach ([
            "/trainer/clients/{$member['fc_user_id']}",
            "/trainer/clients/{$member['fc_user_id']}/sessions",
            "/trainer/clients/{$member['fc_user_id']}/progress",
            "/trainer/clients/{$member['fc_user_id']}/notes",
        ] as $route) {
            $this->assertSame(404, $this->get($route)->get_status(), $route);
        }

        $this->assertSame([], $this->get('/trainer/clients')->get_data()['items']);
    }

    // ------------------------------------------------- Q13: shared read

    public function testEveryAssignedTrainerSeesTheOthersAssignmentsAttributed(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer('Coach A');
        $coachB = $this->seedTrainer('Coach B');

        $this->link($member['fc_user_id'], $coachA['trainer_id']);
        $this->link($member['fc_user_id'], $coachB['trainer_id']);

        $workout = $this->seedWorkout();

        $this->signIn($coachA['account_id']);
        $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id'    => $workout,
            'scheduled_for' => gmdate('Y-m-d'),
        ]);

        // B reads A's work. This is the whole point of Q13.
        $this->signIn($coachB['account_id']);
        $client = $this->get("/trainer/clients/{$member['fc_user_id']}")->get_data();

        $this->assertCount(1, $client['assignments']);

        $assignedBy = $client['assignments'][0]['assigned_by'];

        // Attributed, so B never mistakes A's programming for their own.
        $this->assertSame($coachA['trainer_id'], $assignedBy['trainer_id']);
        $this->assertSame('Coach A', $assignedBy['display_name']);

        // And both coaches are listed on the client.
        $this->assertCount(2, $client['trainers']);
    }

    public function testAssigningIntoAnotherTrainersWeekWarnsWithoutBlocking(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer('Coach A');
        $coachB = $this->seedTrainer('Coach B');

        $this->link($member['fc_user_id'], $coachA['trainer_id']);
        $this->link($member['fc_user_id'], $coachB['trainer_id']);

        $day = gmdate('Y-m-d');

        $this->signIn($coachA['account_id']);
        $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id'    => $this->seedWorkout('A Heavy Day'),
            'scheduled_for' => $day,
        ]);

        $this->signIn($coachB['account_id']);
        $result = $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id'    => $this->seedWorkout('B Heavy Day'),
            'scheduled_for' => $day,
        ]);

        // Non-blocking: refusing would make one coach's programme silently
        // constrain another's, which is the paternalism shared visibility
        // replaces.
        $this->assertSame(201, $result->get_status());

        $warnings = $result->get_data()['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertSame('fc_assignment_conflict', $warnings[0]['code']);
        $this->assertStringContainsString('Coach A', $warnings[0]['message']);
        $this->assertStringContainsString('A Heavy Day', $warnings[0]['message']);
    }

    public function testYourOwnAssignmentIsNotAConflictWithItself(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coach['trainer_id']);

        $day = gmdate('Y-m-d');

        $this->signIn($coach['account_id']);
        $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id'    => $this->seedWorkout(),
            'scheduled_for' => $day,
        ]);

        $second = $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id'    => $this->seedWorkout(),
            'scheduled_for' => $day,
        ]);

        // A trainer programming two sessions in a week is programming, not a
        // clash. The warning is about *other* trainers.
        $this->assertSame([], $second->get_data()['warnings']);
    }

    // ------------------------------------------------ write is the assigner's

    public function testOnlyTheAssigningTrainerMayUnassign(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coachA['trainer_id']);
        $this->link($member['fc_user_id'], $coachB['trainer_id']);

        $this->signIn($coachA['account_id']);
        $assignment = $this->post("/trainer/clients/{$member['fc_user_id']}/workouts", [
            'workout_id' => $this->seedWorkout(),
        ])->get_data()['assignment'];

        // B can see it (Q13) but must not undo it. 403 rather than 404 here on
        // purpose: shared read already told B it exists.
        $this->signIn($coachB['account_id']);
        $refused = $this->delete(
            "/trainer/clients/{$member['fc_user_id']}/workouts/{$assignment['id']}"
        );

        $this->assertSame(403, $refused->get_status());
        $this->assertSame('fc_not_your_assignment', $refused->get_data()['code']);

        $this->signIn($coachA['account_id']);
        $this->assertSame(
            200,
            $this->delete("/trainer/clients/{$member['fc_user_id']}/workouts/{$assignment['id']}")->get_status()
        );
    }

    public function testATrainerCannotEditAnotherTrainersWorkout(): void
    {
        $author  = $this->seedTrainer();
        $outsider = $this->seedTrainer();

        $this->signIn($author['account_id']);
        $workout = $this->post('/trainer/workouts', ['workout_name' => 'Private Draft'])->get_data();
        $this->workoutIds[] = (int) $workout['id'];

        $this->signIn($outsider['account_id']);

        // Not even visible: another trainer's draft is a 404, and the library
        // list is scoped by authorship.
        $this->assertSame(404, $this->get("/trainer/workouts/{$workout['id']}")->get_status());
        $this->assertSame(403, $this->put("/trainer/workouts/{$workout['id']}", [
            'workout_name' => 'Hijacked',
        ])->get_status());

        $mine = $this->get('/trainer/workouts?mine=1')->get_data()['items'];
        $this->assertSame([], $mine);
    }

    public function testATrainerCannotAuthorAWorkoutIntoAnothersLibrary(): void
    {
        global $wpdb;

        $author  = $this->seedTrainer();
        $victim  = $this->seedTrainer();

        $this->signIn($author['account_id']);

        // `trainer_id` in the payload is ignored — it is stamped from the
        // session, so a trainer cannot plant work in somebody else's library.
        $created = $this->post('/trainer/workouts', [
            'workout_name' => 'Stamped',
            'trainer_id'   => $victim['trainer_id'],
        ])->get_data();

        $this->workoutIds[] = (int) $created['id'];

        $this->assertSame($author['trainer_id'], (int) $wpdb->get_var($wpdb->prepare(
            "SELECT trainer_id FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
            $created['id']
        )));
    }

    public function testThePlatformLibraryIsAssignableButNotEditable(): void
    {
        global $wpdb;

        $coach = $this->seedTrainer();

        // A platform workout has no trainer_id.
        $platform = $this->seedWorkout('Platform Workout', null);

        $this->signIn($coach['account_id']);

        $found = $this->get("/trainer/workouts/{$platform}")->get_data();

        $this->assertTrue($found['is_platform']);
        $this->assertFalse($found['is_mine']);

        // Readable and assignable, but the write guard keys on authorship.
        $this->assertSame(403, $this->put("/trainer/workouts/{$platform}", [
            'workout_name' => 'Rebranded',
        ])->get_status());

        $this->assertSame(
            'Platform Workout',
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT workout_name FROM {$wpdb->prefix}fc_workouts WHERE id = %d",
                $platform
            ))
        );
    }

    // ------------------------------------------------------ Q15: private notes

    public function testNotesAreInvisibleToACoTrainer(): void
    {
        $member = $this->seedMember();
        $coachA = $this->seedTrainer();
        $coachB = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coachA['trainer_id']);
        $this->link($member['fc_user_id'], $coachB['trainer_id']);

        $this->signIn($coachA['account_id']);
        $note = $this->post("/trainer/clients/{$member['fc_user_id']}/notes", [
            'body' => 'Struggles with overhead mobility — avoid strict press.',
        ])->get_data();

        $this->assertCount(1, $this->get("/trainer/clients/{$member['fc_user_id']}/notes")->get_data()['items']);

        // B coaches the same client and sees nothing. Not filtered from a shared
        // list — never selected.
        $this->signIn($coachB['account_id']);
        $this->assertSame([], $this->get("/trainer/clients/{$member['fc_user_id']}/notes")->get_data()['items']);

        // 404 rather than 403: a co-trainer must not learn the note exists.
        $this->assertSame(404, $this->put("/trainer/notes/{$note['id']}", ['body' => 'Edited'])->get_status());
        $this->assertSame(404, $this->delete("/trainer/notes/{$note['id']}")->get_status());
    }

    // ------------------------------------------------------ Q4: the queue

    public function testAcceptingARequestActivatesTheLinkAndNotifiesTheMember(): void
    {
        global $wpdb;

        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coach['trainer_id'], 'pending');

        $this->signIn($coach['account_id']);

        $queue = $this->get('/trainer/requests')->get_data();
        $this->assertCount(1, $queue['items']);

        $requestId = $queue['items'][0]['id'];

        $this->assertSame(200, $this->post("/trainer/requests/{$requestId}/accept", [])->get_status());

        $this->assertSame('active', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fc_user_trainers WHERE id = %d",
            $requestId
        )));

        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_notifications WHERE account_id = %d",
            $member['account_id']
        )));

        // The client is now on the roster.
        $this->assertCount(1, $this->get('/trainer/clients')->get_data()['items']);
    }

    public function testARequestCannotBeAnsweredTwice(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coach['trainer_id'], 'pending');

        $this->signIn($coach['account_id']);
        $requestId = $this->get('/trainer/requests')->get_data()['items'][0]['id'];

        $this->post("/trainer/requests/{$requestId}/decline", ['reason' => 'Full right now']);

        $again = $this->post("/trainer/requests/{$requestId}/accept", []);

        $this->assertSame(409, $again->get_status());
        $this->assertSame('fc_request_answered', $again->get_data()['code']);
    }

    public function testAFullTrainerCannotAcceptMore(): void
    {
        global $wpdb;

        $coach = $this->seedTrainer();

        // A limit of one, already used.
        $wpdb->update($wpdb->prefix . 'fc_trainers', ['max_clients' => 1], ['id' => $coach['trainer_id']]);

        $existing = $this->seedMember();
        $this->link($existing['fc_user_id'], $coach['trainer_id']);

        $waiting = $this->seedMember();
        $this->link($waiting['fc_user_id'], $coach['trainer_id'], 'pending');

        $this->signIn($coach['account_id']);
        $requestId = $this->get('/trainer/requests')->get_data()['items'][0]['id'];

        $refused = $this->post("/trainer/requests/{$requestId}/accept", []);

        // Checked at accept, not at request: capacity can fill between somebody
        // asking and the trainer answering.
        $this->assertSame(409, $refused->get_status());
        $this->assertSame('fc_trainer_at_capacity', $refused->get_data()['code']);
    }

    public function testAnUnsetClientLimitMeansNoLimitRatherThanNone(): void
    {
        $coach  = $this->seedTrainer();
        $member = $this->seedMember();

        $this->link($member['fc_user_id'], $coach['trainer_id'], 'pending');

        $this->signIn($coach['account_id']);

        // max_clients defaults to 0. That must read as "unconfigured", not as
        // "cannot take anybody" — otherwise a fresh trainer is locked out of
        // accepting their first client.
        $this->assertNull($this->get('/trainer/requests')->get_data()['capacity']['max']);

        $requestId = $this->get('/trainer/requests')->get_data()['items'][0]['id'];
        $this->assertSame(200, $this->post("/trainer/requests/{$requestId}/accept", [])->get_status());
    }

    /**
     * Accepting opens the conversation.
     *
     * Nothing created `fc_message_threads` rows before W3.4 slice 3, so every
     * client accepted through the app reached a messaging screen with no thread
     * on it and no way to open one — W3.1 worked only against the seeded
     * fixtures. Asserted from **both** sides, because the thread is what each of
     * them sees the other through.
     */
    public function testAcceptingAClientOpensAConversationBothSidesCanSee(): void
    {
        $member = $this->seedMember();
        $coach  = $this->seedTrainer();

        $this->link($member['fc_user_id'], $coach['trainer_id'], 'pending');
        $this->signIn($coach['account_id']);

        $this->assertSame([], $this->get('/messages/threads')->get_data()['items']);

        $requestId = $this->get('/trainer/requests')->get_data()['items'][0]['id'];
        $this->post("/trainer/requests/{$requestId}/accept", []);

        $trainerSide = $this->get('/messages/threads')->get_data()['items'];

        $this->assertCount(1, $trainerSide);
        $this->assertSame($member['fc_user_id'], $trainerSide[0]['user_id']);
        // A trainer's replies are not metered — the quota is the member's.
        $this->assertNull($trainerSide[0]['quota']);

        $this->signIn($member['account_id']);
        $memberSide = $this->get('/messages/threads')->get_data()['items'];

        $this->assertCount(1, $memberSide);
        $this->assertSame($trainerSide[0]['id'], $memberSide[0]['id']);
    }

    /**
     * Opening a conversation twice does not open two.
     *
     * `uq_thread` is the guarantee; this asserts the service leans on it rather
     * than on a check that a concurrent accept could slip past.
     */
    public function testEnsuringAThreadTwiceReusesTheSameOne(): void
    {
        $member  = $this->seedMember();
        $coach   = $this->seedTrainer();
        $service = new MessageService();

        $first  = $service->ensureThread($member['fc_user_id'], $coach['trainer_id']);
        $second = $service->ensureThread($member['fc_user_id'], $coach['trainer_id']);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);
    }

    // ------------------------------------------------------------------ plans

    /**
     * Every field the plan endpoint accepts, it hands back.
     *
     * The builder seeds its form from `GET /trainer/plans`, so a field the
     * update accepts but the list omits renders as an empty box on a plan that
     * has a value — a priced plan showing no price. The screen sends only the
     * fields it changed, which limits the blast radius to display; a client that
     * resubmits what it read would write the omission back as null. This asserts
     * the symmetry itself rather than one client's use of it, which is why it
     * saves twice and the second save echoes the whole payload back.
     */
    public function testEveryWritablePlanFieldSurvivesASecondEdit(): void
    {
        $coach = $this->seedTrainer();
        $this->signIn($coach['account_id']);

        $planId = $this->post('/trainer/plans', ['plan_name' => 'Round Trip'])->get_data()['id'];

        $written = [
            'description'     => 'Twice a week, twelve weeks.',
            'plan_type'       => 'workout',
            'difficulty'      => 'advanced',
            'currency'        => 'EUR',
            'price_weekly'    => 9.5,
            'price_monthly'   => 30,
            'price_quarterly' => 85,
            'price_yearly'    => 300,
            'weekly_sessions' => 2,
            'duration_weeks'  => 12,
            'max_messages_per_week' => 7,
            'sort_order'      => 3,
        ];

        $this->put("/trainer/plans/{$planId}", $written);

        $plan = $this->planById($planId);

        foreach ($written as $key => $value) {
            $this->assertEquals($value, $plan[$key], "{$key} did not survive the first save");
        }

        // The second save is the one that matters: the editor resubmits what it
        // read, so anything the list dropped is now written back as null.
        $this->put("/trainer/plans/{$planId}", $plan + ['plan_name' => 'Round Trip']);

        $again = $this->planById($planId);

        foreach ($written as $key => $value) {
            $this->assertEquals($value, $again[$key], "{$key} was lost on the second save");
        }
    }

    /**
     * `features` and `max_trainers` are readable and not writable.
     *
     * They decide platform-wide entitlements, so a trainer setting
     * `has_video_workouts` on their own plan would be selling something the
     * platform never agreed to. The screen still needs to *show* what the plan
     * grants, which is why they are in the payload at all.
     */
    public function testATrainerCanReadButNotGrantPlanFeatures(): void
    {
        global $wpdb;

        $coach = $this->seedTrainer();
        $this->signIn($coach['account_id']);

        $planId = $this->post('/trainer/plans', ['plan_name' => 'Granted'])->get_data()['id'];

        $wpdb->update(
            $wpdb->prefix . 'fc_plans',
            ['features' => wp_json_encode(['can_message' => true]), 'max_trainers' => 2],
            ['id' => $planId]
        );

        $this->put("/trainer/plans/{$planId}", [
            'features'     => ['has_video_workouts' => true, 'can_message' => true],
            'max_trainers' => 99,
        ]);

        $plan = $this->planById($planId);

        $this->assertSame(['can_message' => true], $plan['features']);
        $this->assertSame(2, $plan['max_trainers']);
    }

    /**
     * A value outside the vocabulary falls back rather than 400s.
     *
     * These arrive from a `<select>`, so an unknown one is a client bug or a
     * probe — neither worth discarding the rest of somebody's edit over.
     */
    public function testAnUnknownPlanEnumFallsBackInsteadOfFailing(): void
    {
        $coach = $this->seedTrainer();
        $this->signIn($coach['account_id']);

        $planId = $this->post('/trainer/plans', ['plan_name' => 'Enum'])->get_data()['id'];

        $response = $this->put("/trainer/plans/{$planId}", [
            'difficulty'  => 'godlike',
            'plan_type'   => '; DROP TABLE plans',
            'description' => 'Saved anyway.',
        ]);

        $this->assertSame(200, $response->get_status());

        $plan = $this->planById($planId);

        $this->assertSame('beginner', $plan['difficulty']);
        $this->assertSame('combined', $plan['plan_type']);
        $this->assertSame('Saved anyway.', $plan['description']);
    }

    // ---------------------------------------------------------------- profile

    public function testAProfileUpdateCannotSetItsOwnRating(): void
    {
        global $wpdb;

        $coach = $this->seedTrainer();

        $wpdb->update(
            $wpdb->prefix . 'fc_trainers',
            ['rating' => 4.2, 'rating_count' => 9],
            ['id' => $coach['trainer_id']]
        );

        $this->signIn($coach['account_id']);

        $updated = $this->put('/trainer/profile', [
            'bio'          => 'Strength and conditioning.',
            'max_clients'  => 25,
            'rating'       => 5.0,
            'rating_count' => 999,
            'status'       => 'suspended',
        ])->get_data();

        $this->assertSame('Strength and conditioning.', $updated['bio']);
        $this->assertSame(25, $updated['max_clients']);

        // A rating a trainer can set is not a rating, and account status is an
        // administrator's decision.
        $this->assertSame(4.2, $updated['rating']);
        $this->assertSame(9, $updated['rating_count']);
        $this->assertSame('active', $updated['status']);
    }

    public function testAnAccountWithNoTrainerProfileIsRefusedClearly(): void
    {
        // Capability without a profile — the state an administrator is in.
        $accountId = $this->makeAccount(Capabilities::ROLE_ADMIN);
        $this->signIn($accountId);

        $response = $this->get('/trainer/profile');

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_no_trainer_profile', $response->get_data()['code']);
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
            'display_name' => 'Trainer Fixture Client',
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
     * @return array<string,mixed>
     */
    private function planById(int $planId): array
    {
        foreach ($this->get('/trainer/plans')->get_data()['items'] as $plan) {
            if ((int) $plan['id'] === $planId) {
                return $plan;
            }
        }

        $this->fail("Plan {$planId} is not in the trainer's own list.");
    }

    private function link(int $fcUserId, int $trainerId, string $status = 'active'): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_user_trainers', [
            'user_id'      => $fcUserId,
            'trainer_id'   => $trainerId,
            'status'       => $status,
            'requested_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    private function seedWorkout(string $name = 'Fixture Workout', ?int $trainerId = null): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        // Named exactly as asked. Uniqueness comes from the auto-increment id,
        // not from a random suffix — the conflict-warning message quotes the
        // workout name, and a suffix would make that assertion unreadable.
        $wpdb->insert($wpdb->prefix . 'fc_workouts', [
            'workout_name' => $name,
            'trainer_id'   => $trainerId,
            'difficulty'   => 'intermediate',
            'workout_type' => 'strength',
            'is_active'    => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id                 = (int) $wpdb->insert_id;
        $this->workoutIds[] = $id;

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
