<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `/admin/*` (W2.5).
 *
 * The behaviours worth pinning down are the ones the prototype got wrong in ways
 * that only show up with real data or real history:
 *
 *   1. **paging, search, sort and filtering happen in SQL.** The prototype
 *      loaded every row and filtered in the browser — fine for six seeded users,
 *      fatal at ten thousand.
 *   2. **saving a workout preserves exercise ids.** The prototype deleted every
 *      exercise and re-inserted it, silently detaching every historical log from
 *      the movement it recorded.
 *   3. **staff edits to member data are audited** with before/after and stamped
 *      `source='admin'` (Q10).
 *   4. **deletes that would orphan live data are refused with a reason**, not
 *      performed silently.
 */
final class AdminApiTest extends WorkoutFixtureCase
{
    /** @var int[] */
    private array $foodIds = [];

    private int $adminAccountId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->foodIds as $id) {
            $wpdb->delete($wpdb->prefix . 'fc_foods', ['id' => $id]);
        }
        $this->foodIds = [];

        if ($this->adminAccountId > 0) {
            $wpdb->delete($wpdb->prefix . 'fc_activity_log', ['actor_account_id' => $this->adminAccountId]);
            $this->adminAccountId = 0;
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------- the gate

    public function testTheAdminApiAnswersToPluginCapabilitiesNotWordPressOnes(): void
    {
        $this->signOut();
        $this->assertSame(401, $this->get('/admin/foods')->get_status());

        // A WordPress administrator with no fc_accounts row is anonymous here —
        // the property the identity conversion exists for. `manage_options`, as
        // the contract originally specified, would have let this through.
        $wpAdmin = wp_insert_user([
            'user_login' => 'fc_wp_admin_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(20, false),
            'user_email' => 'fc_wp_admin_' . wp_generate_password(8, false) . '@example.test',
            'role'       => 'administrator',
        ]);

        $this->assertIsInt($wpAdmin);
        wp_set_current_user($wpAdmin);

        $this->assertTrue(current_user_can('manage_options'), 'A real WordPress administrator.');
        $this->assertSame(401, $this->get('/admin/foods')->get_status());

        wp_set_current_user(0);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($wpAdmin);

        // A member is signed in but lacks every admin capability.
        $member = $this->seedMemberWithWorkout();
        $this->signIn($member['account_id']);

        $this->assertSame(403, $this->get('/admin/foods')->get_status());
        $this->assertSame(403, $this->get('/admin/dashboard')->get_status());
    }

    public function testEachResourceNamesItsOwnCapability(): void
    {
        // Granted the Q10 capability and nothing else: reaches health entries
        // and meals, and nothing else. One blanket admin gate could not express
        // this.
        $accountId = $this->makeAccount(Capabilities::ROLE_USER, ['fc_edit_user_health']);
        $this->signIn($accountId);

        $this->assertSame(200, $this->get('/admin/health-entries')->get_status());
        $this->assertSame(200, $this->get('/admin/meals')->get_status());
        $this->assertSame(403, $this->get('/admin/foods')->get_status());
        $this->assertSame(403, $this->get('/admin/users')->get_status());
    }

    public function testAnUnknownResourceIsANotFoundRatherThanAQuery(): void
    {
        $this->asAdmin();

        $this->assertSame(404, $this->get('/admin/unicorns')->get_status());
    }

    // --------------------------------------------------- server-side listing

    public function testListingPagesSearchesSortsAndFiltersInSql(): void
    {
        $this->asAdmin();

        $this->makeFood(['name' => 'Zebra Steak', 'category' => 'protein', 'calories' => 300]);
        $this->makeFood(['name' => 'Apple Pie', 'category' => 'fruit', 'calories' => 200]);
        $this->makeFood(['name' => 'Apple Juice', 'category' => 'beverages', 'calories' => 100]);

        // Paging is the server's: a per_page of 2 returns 2 rows and a total
        // that describes the whole matching set, not the page.
        $page = $this->get('/admin/foods?per_page=2&q=Apple')->get_data();

        $this->assertCount(2, $page['items']);
        $this->assertSame(2, $page['total'], 'Search narrowed the set before paging.');

        // Search matches the registry's declared columns.
        $zebra = $this->get('/admin/foods?q=Zebra')->get_data();
        $this->assertSame(1, $zebra['total']);
        $this->assertSame('Zebra Steak', $zebra['items'][0]['name']);

        // Filters are declared per resource, and compose with search. The
        // search term is what isolates this test's rows: the development
        // database is seeded, so an unqualified `category=fruit` counts the
        // seeder's foods too and would assert against a moving number.
        $fruit = $this->get('/admin/foods?q=Apple&category=fruit')->get_data();
        $this->assertSame(1, $fruit['total']);
        $this->assertSame('Apple Pie', $fruit['items'][0]['name']);

        $beverages = $this->get('/admin/foods?q=Apple&category=beverages')->get_data();
        $this->assertSame(1, $beverages['total']);
        $this->assertSame('Apple Juice', $beverages['items'][0]['name']);

        // Sorting is by a registry key, not a column name from the request.
        $asc = $this->get('/admin/foods?q=Apple&sort=calories&order=asc')->get_data();
        $this->assertSame(100, $asc['items'][0]['calories']);

        $desc = $this->get('/admin/foods?q=Apple&sort=calories&order=desc')->get_data();
        $this->assertSame(200, $desc['items'][0]['calories']);
    }

    public function testAnUnknownSortKeyFallsBackInsteadOfReachingTheQuery(): void
    {
        $this->asAdmin();
        $this->makeFood(['name' => 'Sortable Food']);

        // This is the one place a request could otherwise put a string into an
        // identifier position, so it must not error *or* execute.
        $response = $this->get('/admin/foods?sort=id;DROP+TABLE+wp_fc_foods');

        $this->assertSame(200, $response->get_status());
        $this->assertGreaterThan(0, $response->get_data()['total']);
    }

    public function testTheListCarriesPaginationHeaders(): void
    {
        $this->asAdmin();

        for ($i = 0; $i < 3; $i++) {
            $this->makeFood(['name' => 'Header Food ' . $i]);
        }

        $response = $this->get('/admin/foods?q=Header Food&per_page=2');

        $this->assertSame('3', $response->get_headers()['X-WP-Total']);
        $this->assertSame('2', $response->get_headers()['X-WP-TotalPages']);
    }

    // ------------------------------------------------ nested exercise editor

    public function testSavingAWorkoutPreservesExerciseIdsThatHistoryPointsAt(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();
        $this->asAdmin();

        $workoutId = $fixture['workout_id'];
        $keepId    = $fixture['exercise_ids'][0];
        $dropId    = $fixture['exercise_ids'][7];

        $before = $this->get('/admin/workouts/' . $workoutId)->get_data();
        $this->assertCount(8, $before['exercises']);

        // Re-save with the first exercise renamed, the last one dropped, and a
        // new one appended — the shape a real edit takes.
        $exercises = array_slice($before['exercises'], 0, 7);
        $exercises[0]['exercise_name'] = 'Renamed Lift';
        $exercises[] = ['exercise_name' => 'Brand New Lift', 'default_sets' => 4];

        $after = $this->put('/admin/workouts/' . $workoutId, [
            'workout_name' => 'Edited Workout',
            'exercises'    => $exercises,
        ])->get_data();

        $this->assertCount(8, $after['exercises']);
        $this->assertSame('Edited Workout', $after['workout_name']);

        // The kept exercise still has its original id. The prototype's
        // delete-all-and-reinsert would have given it a new one, orphaning every
        // fc_exercise_logs row that references it.
        $this->assertSame($keepId, $after['exercises'][0]['id']);
        $this->assertSame('Renamed Lift', $after['exercises'][0]['exercise_name']);

        // The one genuinely removed is gone.
        $this->assertNull($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_exercises WHERE id = %d",
            $dropId
        )));

        // And the appended one exists with an id of its own.
        $this->assertSame('Brand New Lift', $after['exercises'][7]['exercise_name']);
        $this->assertGreaterThan(0, $after['exercises'][7]['id']);
    }

    public function testExerciseOrderComesFromTheSubmittedArrayOrder(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->asAdmin();

        $before    = $this->get('/admin/workouts/' . $fixture['workout_id'])->get_data();
        $reordered = array_reverse($before['exercises']);

        $after = $this->put('/admin/workouts/' . $fixture['workout_id'], [
            'exercises' => $reordered,
        ])->get_data();

        // Drag-reorder needs no endpoint of its own: position is the index in
        // the array the client submits.
        $this->assertSame('Fixture Lift 8', $after['exercises'][0]['exercise_name']);
        $this->assertSame(0, $after['exercises'][0]['order_index']);
        $this->assertSame('Fixture Lift 1', $after['exercises'][7]['exercise_name']);
    }

    // ---------------------------------------------------------- Q10 auditing

    public function testEditingAMembersHealthEntryIsStampedAndAudited(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();

        $wpdb->insert($wpdb->prefix . 'fc_health_stats', [
            'user_id'     => $fixture['fc_user_id'],
            'record_date' => gmdate('Y-m-d'),
            'weight_kg'   => 80.0,
            'source'      => 'manual',
        ]);
        $statId = (int) $wpdb->insert_id;

        $this->asAdmin();

        $updated = $this->put('/admin/health-entries/' . $statId, ['weight_kg' => 78.5])->get_data();

        $this->assertSame(78.5, $updated['weight_kg']);

        // Provenance on the row: the member's own screen labels this, so a staff
        // correction is never passed off as self-reported.
        $this->assertSame('admin', $updated['source']);
        $this->assertSame($this->adminAccountId, $updated['last_edited_by_account_id']);
        $this->assertTrue($updated['edited_by_staff']);

        // And an audit row carrying before/after, attributed to the actor and
        // filed against the subject.
        $audit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_activity_log
              WHERE actor_account_id = %d AND is_audit = 1
              ORDER BY id DESC LIMIT 1",
            $this->adminAccountId
        ), ARRAY_A);

        $this->assertNotNull($audit);
        $this->assertSame('admin.health_entries.updated', $audit['type']);
        $this->assertSame((int) $fixture['account_id'], (int) $audit['account_id']);

        $meta = json_decode((string) $audit['meta'], true);
        $this->assertSame('80.00', (string) $meta['changes']['weight_kg']['before']);
        $this->assertSame(78.5, $meta['changes']['weight_kg']['after']);
    }

    public function testAnAdminEditRecomputesWhatTheMemberSees(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();

        $wpdb->update(
            $wpdb->prefix . 'fc_users',
            ['height_cm' => 180.0],
            ['id' => $fixture['fc_user_id']]
        );
        $wpdb->insert($wpdb->prefix . 'fc_health_stats', [
            'user_id'     => $fixture['fc_user_id'],
            'record_date' => gmdate('Y-m-d'),
            'weight_kg'   => 90.0,
        ]);
        $statId = (int) $wpdb->insert_id;

        $this->asAdmin();

        // 81 kg at 1.8 m → BMI 25. The derived numbers must follow a staff edit
        // exactly as they follow the member's own.
        $updated = $this->put('/admin/health-entries/' . $statId, ['weight_kg' => 81])->get_data();

        $this->assertSame(25.0, $updated['bmi']);
        $this->assertSame(81.0, (float) $wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_users WHERE id = %d",
            $fixture['fc_user_id']
        )));
    }

    public function testTheMealListShowsMemberNamesRatherThanUserIds(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();

        $wpdb->insert($wpdb->prefix . 'fc_nutrition_logs', [
            'user_id'   => $fixture['fc_user_id'],
            'log_date'  => gmdate('Y-m-d'),
            'meal_type' => 'lunch',
            'source'    => 'manual',
        ]);

        $this->asAdmin();

        $items = $this->get('/admin/meals?user_id=' . $fixture['fc_user_id'])->get_data()['items'];

        $this->assertCount(1, $items);
        // The prototype rendered "User #1".
        $this->assertSame('Fixture Member', $items[0]['user_name']);
    }

    // ------------------------------------------------------ guarded deletes

    public function testDeletingAWorkoutWithLoggedSessionsIsRefusedWithAReason(): void
    {
        global $wpdb;

        $fixture = $this->seedMemberWithWorkout();

        $wpdb->insert($wpdb->prefix . 'fc_workout_sessions', [
            'user_id'    => $fixture['fc_user_id'],
            'workout_id' => $fixture['workout_id'],
            'log_date'   => gmdate('Y-m-d'),
            'status'     => 'completed',
        ]);

        $this->asAdmin();

        $response = $this->delete('/admin/workouts/' . $fixture['workout_id']);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('fc_workout_has_sessions', $response->get_data()['code']);

        // And it is still there.
        $this->assertSame(200, $this->get('/admin/workouts/' . $fixture['workout_id'])->get_status());
    }

    public function testAFoodWithNoDiaryEntriesDeletesCleanly(): void
    {
        $this->asAdmin();

        $id = $this->makeFood(['name' => 'Disposable Food']);

        $this->assertSame(200, $this->delete('/admin/foods/' . $id)->get_status());
        $this->assertSame(404, $this->get('/admin/foods/' . $id)->get_status());
    }

    // ------------------------------------------------------------- settings

    public function testSettingsRefuseAnAppUrlThatWouldTakeTheAppOffline(): void
    {
        $this->asAdmin();

        $empty = $this->put('/admin/settings', ['general' => ['app_base' => '']]);
        $this->assertSame(400, $empty->get_status());
        $this->assertSame('fc_app_base_empty', $empty->get_data()['code']);

        $reserved = $this->put('/admin/settings', ['general' => ['app_base' => 'wp-admin']]);
        $this->assertSame(400, $reserved->get_status());
        $this->assertSame('fc_app_base_reserved', $reserved->get_data()['code']);

        // The slug is unchanged after both refusals.
        $this->assertSame('fitness', $this->get('/admin/settings')->get_data()['general']['app_base']);
    }

    public function testSettingsWriteOnlyWhitelistedKeys(): void
    {
        $this->asAdmin();

        $before = $this->get('/admin/settings')->get_data();
        $this->assertArrayHasKey('features', $before);
        $this->assertArrayNotHasKey('billing', $before, 'Not writable until Phase 3.');

        $after = $this->put('/admin/settings', [
            'features' => ['messaging_enabled' => false],
            // A key nothing reads. It must not land in the options blob.
            'general'  => ['stowaway' => 'nope'],
        ])->get_data();

        $this->assertFalse($after['features']['messaging_enabled']);
        $this->assertArrayNotHasKey('stowaway', $after['general']);
        $this->assertFalse($after['rewrites_flushed'], 'The app URL did not move.');

        // Restore, so the development site is left as it was found.
        $this->put('/admin/settings', ['features' => ['messaging_enabled' => true]]);
    }

    public function testTheResourceListIsServedFromTheSameRegistryTheApiUses(): void
    {
        $this->asAdmin();

        $items = $this->get('/admin/resources')->get_data()['items'];
        $names = array_column($items, 'name');

        $this->assertContains('users', $names);
        $this->assertContains('health-entries', $names);

        $users = $items[array_search('users', $names, true)];

        // Creating a member is an identity operation, not a profile edit.
        $this->assertFalse($users['creatable']);
        $this->assertContains('status', $users['filters']);
    }

    public function testTheDashboardIsOneRequestAndCountsRealRows(): void
    {
        $this->seedMemberWithWorkout();
        $this->asAdmin();

        $data = $this->get('/admin/dashboard')->get_data();

        $this->assertGreaterThan(0, $data['counts']['members']);
        $this->assertGreaterThan(0, $data['counts']['workouts']);
        $this->assertArrayHasKey('mrr', $data['revenue']);
        $this->assertIsArray($data['recent_signups']);
        $this->assertIsArray($data['failed_payments']);
    }

    // --------------------------------------------------------------- helpers

    /** Sign in as an administrator, remembering the id so audit rows are cleaned. */
    private function asAdmin(): void
    {
        $this->adminAccountId = $this->makeAccount(Capabilities::ROLE_ADMIN);
        $this->signIn($this->adminAccountId);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function makeFood(array $attributes = []): int
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_foods', $attributes + [
            'name'       => 'Admin Fixture ' . wp_generate_password(6, false),
            'category'   => 'other',
            'calories'   => 100,
            'protein_g'  => 0,
            'carbs_g'    => 0,
            'fat_g'      => 0,
            'source'     => 'system',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $wpdb->insert_id;
        $this->foodIds[] = $id;

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
