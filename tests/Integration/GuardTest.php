<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Database\Seeders\DemoSeeder;
use FitnessClub\Support\Guard;
use PHPUnit\Framework\TestCase;

/**
 * Resource-level authorisation against the seeded multi-trainer dataset.
 *
 * The seed puts Alex Morgan under BOTH Sarah Chen and Mike Torres (Q3), with every
 * workout assigned by the trainer who authored it — exactly the shape needed to
 * prove the two-guard split (Q13): a co-trainer may READ a shared client but only
 * the ASSIGNING trainer may WRITE their programming.
 *
 * @covers \FitnessClub\Support\Guard
 */
final class GuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Idempotent — guarantees the Alex/Sarah/Mike fixture regardless of run order.
        (new DemoSeeder())->run();
    }

    // ---------------------------------------------------------------- coaches

    public function testAssignedTrainerCoachesClient(): void
    {
        $this->assertTrue(Guard::trainerCoachesClient($this->wpId('sarah.chen'), $this->userId('alex.morgan')));
    }

    public function testSecondTrainerAlsoCoachesSharedClient(): void
    {
        // The heart of Q3/Q13: Alex's OTHER trainer is equally a coach for reads.
        $this->assertTrue(Guard::trainerCoachesClient($this->wpId('mike.torres'), $this->userId('alex.morgan')));
    }

    public function testUnrelatedTrainerDoesNotCoachClient(): void
    {
        $this->assertFalse(Guard::trainerCoachesClient($this->wpId('lisa.park'), $this->userId('alex.morgan')));
    }

    public function testDeclinedLinkDoesNotGrantCoaching(): void
    {
        // Sam declined David in the seed; a declined request is not an assignment.
        $this->assertFalse(Guard::trainerCoachesClient($this->wpId('david.kim'), $this->userId('sam.wilson')));
    }

    public function testPendingLinkDoesNotGrantCoaching(): void
    {
        // Taylor has a pending request to David; pending is not active.
        $this->assertFalse(Guard::trainerCoachesClient($this->wpId('david.kim'), $this->userId('taylor.brooks')));
    }

    // -------------------------------------------------------- write guard (Q13)

    public function testAssigningTrainerCanWriteOwnWorkout(): void
    {
        $this->assertTrue(Guard::trainerAssignedResource(
            $this->wpId('sarah.chen'),
            'workout',
            $this->workoutId('upper-body-power')
        ));
    }

    public function testCoachCannotWriteAnotherTrainersWorkout(): void
    {
        // Mike coaches Alex too (can READ), but did NOT author Upper Body Power, so
        // he must not WRITE it. The exact Q13 boundary.
        $mike    = $this->wpId('mike.torres');
        $workout = $this->workoutId('upper-body-power');

        $this->assertTrue(
            Guard::trainerCoachesClient($mike, $this->userId('alex.morgan')),
            'Precondition: Mike can read the shared client.'
        );
        $this->assertFalse(
            Guard::trainerAssignedResource($mike, 'workout', $workout),
            "A co-trainer must NOT be able to edit another trainer's workout."
        );
    }

    public function testWriteGuardRejectsUnknownResourceType(): void
    {
        $this->assertFalse(Guard::trainerAssignedResource($this->wpId('sarah.chen'), 'not_a_real_type', 1));
    }

    // ------------------------------------------------------------ owns session

    public function testUserOwnsOwnSession(): void
    {
        $this->assertTrue(Guard::ownsSession($this->wpId('alex.morgan'), $this->alexSessionId()));
    }

    public function testOtherUserDoesNotOwnSession(): void
    {
        $this->assertFalse(Guard::ownsSession($this->wpId('sam.wilson'), $this->alexSessionId()));
    }

    public function testTrainerIsNotSessionOwner(): void
    {
        // ownsSession is a USER ownership check; a trainer coaching Alex is not the
        // owner of the session even though a trainer endpoint may read it. Never conflate.
        $this->assertFalse(Guard::ownsSession($this->wpId('sarah.chen'), $this->alexSessionId()));
    }

    // -------------------------------------------------------------- owns thread

    public function testBothPartiesParticipateInThread(): void
    {
        $thread = $this->alexSarahThreadId();
        $this->assertTrue(Guard::participatesInThread($this->wpId('alex.morgan'), $thread));
        $this->assertTrue(Guard::participatesInThread($this->wpId('sarah.chen'), $thread));
    }

    public function testOutsiderDoesNotParticipateInThread(): void
    {
        $thread = $this->alexSarahThreadId();
        $this->assertFalse(Guard::participatesInThread($this->wpId('sam.wilson'), $thread));
        $this->assertFalse(
            Guard::participatesInThread($this->wpId('mike.torres'), $thread),
            "Alex's other trainer is not a party to the Sarah thread."
        );
    }

    // ---------------------------------------------------------- generic + edges

    public function testUserOwnsResourceMapping(): void
    {
        $session = $this->alexSessionId();
        $this->assertTrue(Guard::userOwnsResource($this->wpId('alex.morgan'), 'session', $session));
        $this->assertFalse(Guard::userOwnsResource($this->wpId('alex.morgan'), 'unknown_type', $session));
    }

    /**
     * Every guard rejects non-positive and non-existent ids without error.
     *
     * @dataProvider foreignIdProvider
     */
    public function testGuardsRejectBadIds(int $id): void
    {
        $wp = $this->wpId('sarah.chen');

        $this->assertFalse(Guard::trainerCoachesClient($wp, $id));
        $this->assertFalse(Guard::trainerAssignedResource($wp, 'workout', $id));
        $this->assertFalse(Guard::ownsSession($wp, $id));
        $this->assertFalse(Guard::participatesInThread($wp, $id));
        $this->assertFalse(Guard::trainerCoachesClient($id, $this->userId('alex.morgan')));
    }

    /**
     * @return array<string,array{0:int}>
     */
    public static function foreignIdProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'nonexistent' => [99999999]];
    }

    public function testResolversReturnNullForUnknownWpUser(): void
    {
        $this->assertNull(Guard::userId(99999999));
        $this->assertNull(Guard::trainerId(99999999));
        $this->assertNull(Guard::userId(0));
    }

    // ------------------------------------------------------------------ helpers

    private function wpId(string $login): int
    {
        $user = get_user_by('login', $login);
        $this->assertNotFalse($user, "Seeded user '{$login}' is missing.");

        return (int) $user->ID;
    }

    private function userId(string $login): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE wp_user_id = %d",
            $this->wpId($login)
        ));
    }

    private function trainerFcId(string $login): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_trainers WHERE wp_user_id = %d",
            $this->wpId($login)
        ));
    }

    private function workoutId(string $slug): int
    {
        global $wpdb;

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_workouts WHERE slug = %s",
            $slug
        ));
        $this->assertGreaterThan(0, $id, "Seeded workout '{$slug}' is missing.");

        return $id;
    }

    private function alexSessionId(): int
    {
        global $wpdb;

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_workout_sessions WHERE user_id = %d ORDER BY id LIMIT 1",
            $this->userId('alex.morgan')
        ));
        $this->assertGreaterThan(0, $id, 'Expected a seeded session for Alex.');

        return $id;
    }

    private function alexSarahThreadId(): int
    {
        global $wpdb;

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_message_threads WHERE user_id = %d AND trainer_id = %d",
            $this->userId('alex.morgan'),
            $this->trainerFcId('sarah.chen')
        ));
        $this->assertGreaterThan(0, $id, 'Expected a seeded Alex-Sarah thread.');

        return $id;
    }
}
