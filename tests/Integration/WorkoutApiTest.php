<?php

namespace FitnessClub\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;

/**
 * `/workouts/*`, `/exercises/{id}` and `/sessions/*` over the real REST server
 * (W1.4).
 *
 * WorkoutSessionTest proves the state machine; this proves the wiring around it
 * — permissions, ownership, status codes, pagination headers and the entitlement
 * gate — which is where an endpoint quietly becomes readable by the wrong person.
 */
final class WorkoutApiTest extends WorkoutFixtureCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    // ---------------------------------------------------------------- workouts

    public function testWorkoutsListReturnsTheCallersAssignmentsWithPaginationHeaders(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $response = $this->get('/workouts');

        $this->assertSame(200, $response->get_status());
        $this->assertSame('1', $response->get_headers()['X-WP-Total']);

        $data = $response->get_data();
        $this->assertCount(1, $data['items']);
        $this->assertSame($fixture['workout_id'], $data['items'][0]['id']);
        $this->assertSame(self::EXERCISE_COUNT, $data['items'][0]['exercise_count']);
        $this->assertSame('assigned', $data['items'][0]['status']);
        $this->assertNull($data['items'][0]['resumable_session_id']);
    }

    public function testWorkoutsListDoesNotLeakAnotherMembersProgramme(): void
    {
        $mine = $this->seedMemberWithWorkout();
        $this->seedMemberWithWorkout(); // Someone else's workout, same shape.

        wp_set_current_user($mine['wp_user_id']);
        $data = $this->get('/workouts')->get_data();

        $this->assertCount(1, $data['items'], 'Only my assignments.');
        $this->assertSame($mine['workout_id'], $data['items'][0]['id']);
    }

    public function testWorkoutDetailIncludesOrderedExercises(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $data = $this->get("/workouts/{$fixture['workout_id']}")->get_data();

        $this->assertCount(self::EXERCISE_COUNT, $data['exercises']);
        $this->assertSame('Fixture Lift 1', $data['exercises'][0]['exercise_name']);
        $this->assertSame(['Chest', 'Triceps'], $data['muscle_groups']);
        $this->assertSame(self::SETS_PER_EXERCISE, $data['exercises'][0]['default_sets']);
    }

    public function testAnotherMembersWorkoutIsNotFound(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        wp_set_current_user($mine['wp_user_id']);
        $response = $this->get("/workouts/{$theirs['workout_id']}");

        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_workout_not_assigned', $response->get_data()['code']);
    }

    public function testAnotherMembersExerciseIsNotFound(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        wp_set_current_user($mine['wp_user_id']);
        $response = $this->get("/exercises/{$theirs['exercise_ids'][0]}");

        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_not_found', $response->get_data()['code']);
    }

    public function testVideoUrlsAreWithheldOnAPlanThatDoesNotIncludeThem(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        // The fixture member has no subscription, so the free-tier floor applies
        // and has_video_workouts is false.
        $workout = $this->get("/workouts/{$fixture['workout_id']}")->get_data();

        $this->assertTrue($workout['video_locked']);
        $this->assertNull($workout['video_url']);
        $this->assertNull($workout['exercises'][0]['video_url']);

        // Withheld, not refused: the workout itself is still readable, which is
        // what makes an upgrade prompt possible instead of a dead end.
        $this->assertSame('Fixture Workout', $workout['workout_name']);
    }

    public function testWorkoutsRequireASignedInMember(): void
    {
        wp_set_current_user(0);

        $this->assertSame(401, $this->get('/workouts')->get_status());
    }

    // ---------------------------------------------------------------- sessions

    public function testTheSessionLifecycleOverRest(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $idle = $this->get('/sessions/active');
        $this->assertSame(204, $idle->get_status(), 'No open session is 204, never a null body.');

        $started = $this->post('/sessions', ['workout_id' => $fixture['workout_id']]);
        $this->assertSame(201, $started->get_status());
        $sessionId = $started->get_data()['id'];

        $active = $this->get('/sessions/active');
        $this->assertSame($sessionId, $active->get_data()['id']);

        $paused = $this->request('PATCH', "/sessions/{$sessionId}", ['action' => 'pause']);
        $this->assertSame('paused', $paused->get_data()['status']);

        $resumed = $this->request('PATCH', "/sessions/{$sessionId}", ['action' => 'resume']);
        $this->assertSame('in_progress', $resumed->get_data()['status']);

        $cursor = $this->request('PATCH', "/sessions/{$sessionId}/cursor", [
            'exercise_index' => 2,
            'set_index'      => 1,
        ]);
        $this->assertSame(2, $cursor->get_data()['current_exercise_index']);

        $logged = $this->post("/sessions/{$sessionId}/sets", [
            'exercise_id' => $fixture['exercise_ids'][0],
            'set_index'   => 0,
            'reps'        => 10,
            'weight_kg'   => 42.5,
            'rpe'         => 7,
        ]);
        $this->assertSame(200, $logged->get_status());
        $this->assertSame(425.0, $logged->get_data()['exercises'][0]['total_volume_kg']);

        $completed = $this->post("/sessions/{$sessionId}/complete");
        $this->assertSame(200, $completed->get_status());

        $celebration = $completed->get_data();
        $this->assertSame($sessionId, $celebration['session_id']);
        $this->assertSame(425.0, $celebration['total_volume_kg']);
        $this->assertNotEmpty($celebration['personal_records']);
        $this->assertSame(1, $celebration['streak_days']);

        $history = $this->get('/sessions');
        $this->assertSame('1', $history->get_headers()['X-WP-Total']);
        $this->assertSame('completed', $history->get_data()['items'][0]['status']);
        $this->assertSame('Fixture Workout', $history->get_data()['items'][0]['workout_name']);
    }

    public function testStartingASecondSessionReturns409NamingTheOpenOne(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $first = $this->post('/sessions', ['workout_id' => $fixture['workout_id']]);
        $again = $this->post('/sessions', ['workout_id' => $fixture['workout_id']]);

        $this->assertSame(409, $again->get_status());

        $error = $again->get_data();
        $this->assertSame('fc_session_already_open', $error['code']);
        $this->assertSame($first->get_data()['id'], $error['data']['session_id']);
    }

    public function testAForeignSessionIsNotReadable(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        wp_set_current_user($theirs['wp_user_id']);
        $sessionId = $this->post('/sessions', ['workout_id' => $theirs['workout_id']])->get_data()['id'];

        wp_set_current_user($mine['wp_user_id']);
        $this->assertSame(404, $this->get("/sessions/{$sessionId}")->get_status());
        $this->assertSame(404, $this->post("/sessions/{$sessionId}/complete")->get_status());
    }

    public function testStartingAWorkoutThatIsNotYoursIsRefused(): void
    {
        $mine   = $this->seedMemberWithWorkout();
        $theirs = $this->seedMemberWithWorkout();

        wp_set_current_user($mine['wp_user_id']);
        $response = $this->post('/sessions', ['workout_id' => $theirs['workout_id']]);

        $this->assertSame(404, $response->get_status());
        $this->assertSame('fc_workout_not_assigned', $response->get_data()['code']);
    }

    public function testSetLoggingValidatesItsArguments(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $sessionId = $this->post('/sessions', ['workout_id' => $fixture['workout_id']])->get_data()['id'];

        // rpe is 1–10 per the args schema; WordPress rejects it before the
        // controller runs, which is the point of declaring the schema.
        $response = $this->post("/sessions/{$sessionId}/sets", [
            'exercise_id' => $fixture['exercise_ids'][0],
            'set_index'   => 0,
            'rpe'         => 99,
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('rest_invalid_param', $response->get_data()['code']);
    }

    public function testASetWithExplicitNullMeasuresIsAccepted(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        $sessionId = $this->post('/sessions', ['workout_id' => $fixture['workout_id']])->get_data()['id'];

        // A timed hold has no reps, a bodyweight set has no weight, and the
        // first set of a workout has no rest before it. Declaring those args as
        // plain integers made WordPress reject an explicit null with 400 — and
        // the player's queue treats 4xx as unretryable, so the set was lost
        // while the UI reported it saved.
        $response = $this->post("/sessions/{$sessionId}/sets", [
            'exercise_id'        => $fixture['exercise_ids'][0],
            'set_index'          => 0,
            'reps'               => 10,
            'weight_kg'          => 40,
            'duration_seconds'   => null,
            'rest_taken_seconds' => null,
            'rpe'                => null,
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $response->get_data()['exercises'][0]['sets']);
    }

    public function testActiveSessionAnswers204WhenNothingIsRunning(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        wp_set_current_user($fixture['wp_user_id']);

        // A bare `null` body does not survive WP's REST serialisation — it goes
        // out as a zero-byte 200, which a client reading "empty means no
        // content" turns into an empty *object*. That crashed the player once;
        // this test is why it cannot come back.
        $this->assertSame(204, $this->get('/sessions/active')->get_status());

        $this->post('/sessions', ['workout_id' => $fixture['workout_id']]);

        $running = $this->get('/sessions/active');
        $this->assertSame(200, $running->get_status());
        $this->assertIsArray($running->get_data()['exercises']);
    }

    public function testAnAccountWithoutAMemberProfileIsToldSoPlainly(): void
    {
        // A trainer has fc_access_app-adjacent capabilities but no fc_users row.
        wp_set_current_user($this->makeUser('administrator', ['fc_access_app', 'fc_log_workouts']));

        $response = $this->get('/sessions/active');

        $this->assertSame(403, $response->get_status());
        $this->assertSame('fc_no_member_profile', $response->get_data()['code']);
    }

    // ---------------------------------------------------------------- helpers

    private function get(string $route): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1' . $route));
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $route, array $body = []): WP_REST_Response
    {
        return $this->request('POST', $route, $body);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function request(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/fitnessclub/v1' . $route);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_get_server()->dispatch($request);
    }
}
