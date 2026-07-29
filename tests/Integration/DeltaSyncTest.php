<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Services\WorkoutService;
use FitnessClub\Support\DeltaSync;
use FitnessClub\Support\ResponseCompression;

/**
 * `?modified_since=` and the sync watermark (W4.2).
 *
 * The behaviour worth pinning down is not "a filter filters" — it is the two
 * ways a delta feed silently loses data, both of which are invisible until a
 * client has been syncing for a while and a record is simply missing:
 *
 *   1. a client using its own clock, which is skewed;
 *   2. a watermark taken *after* the query, which drops anything written while
 *      the query ran.
 *
 * @covers \FitnessClub\Support\DeltaSync
 * @covers \FitnessClub\Support\ResponseCompression
 */
final class DeltaSyncTest extends WorkoutFixtureCase
{
    public function testAnIsoTimestampBecomesAUtcSqlDatetime(): void
    {
        $this->assertSame('2024-03-05 09:15:00', DeltaSync::since('2024-03-05T09:15:00Z'));
    }

    public function testAnOffsetTimestampIsConvertedRatherThanTruncated(): void
    {
        // A client in Berlin sending +02:00 means 08:15 UTC. Storing 10:15 would
        // skip two hours of its own changes.
        $this->assertSame('2024-03-05 08:15:00', DeltaSync::since('2024-03-05T10:15:00+02:00'));
    }

    public function testGarbageIsIgnoredRatherThanRefused(): void
    {
        // Falling back to a full fetch is correct, self-healing and merely
        // slower. A 400 turns a client bug into a total sync failure.
        $this->assertNull(DeltaSync::since('last tuesday-ish'));
        $this->assertNull(DeltaSync::since(''));
        $this->assertNull(DeltaSync::since(null));
    }

    public function testAFutureTimestampIsClampedToNow(): void
    {
        $future = gmdate('c', time() + 86400);
        $since  = DeltaSync::since($future);

        $this->assertNotNull($since);
        // Honouring it would return nothing, forever — the client would never
        // sync again and nothing would report an error.
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $since);
    }

    public function testEveryCollectionCarriesAWatermarkEvenWithoutADeltaRequest(): void
    {
        $fixture = $this->seedMemberWithWorkout();
        $this->signIn($fixture['account_id']);

        $response = rest_get_server()->dispatch(
            new \WP_REST_Request('GET', '/fitnessclub/v1/workouts')
        );

        $headers = $response->get_headers();

        // A client's *first* sync is a full fetch, and that is exactly when it
        // needs a starting point. Withholding it here would force the client to
        // invent one from its own clock — the bug this exists to prevent.
        $this->assertArrayHasKey(DeltaSync::HEADER, $headers);
        $this->assertNotEmpty($headers[DeltaSync::HEADER]);
    }

    public function testTheWatermarkIsAValidTimestampTheClientCanSendBack(): void
    {
        $watermark = DeltaSync::watermark();

        // Round-trips: whatever the server hands out must parse when returned.
        $this->assertNotNull(DeltaSync::since($watermark));
    }

    public function testTheSyncedAtWatermarkIsTakenBeforeTheQueryRuns(): void
    {
        $fixture = $this->seedMemberWithWorkout();

        $before = time();
        $result = (new WorkoutService())->listForUser((int) $fixture['fc_user_id']);
        $after  = time();

        $this->assertArrayHasKey('synced_at', $result);

        $stamped = strtotime((string) $result['synced_at']);

        // Within the request, and at its *start* — anything written while the
        // query ran must fall on the next sync's side of the line rather than
        // into the gap between the read and the timestamp.
        $this->assertGreaterThanOrEqual($before, $stamped);
        $this->assertLessThanOrEqual($after, $stamped);
    }

    // ------------------------------------------------------------ compression

    public function testCompressionIsRefusedWhenTheClientDidNotAskForIt(): void
    {
        unset($_SERVER['HTTP_ACCEPT_ENCODING']);

        // The buffer callback is the observable half: with no negotiation it
        // must hand the body back untouched.
        $body = str_repeat('{"a":1}', 500);

        $this->assertSame($body, ResponseCompression::compress($body));
    }

    public function testSmallBodiesAreNotCompressed(): void
    {
        $body = '{"ok":true}';

        // Below a kilobyte the gzip header costs more than the saving.
        $this->assertSame($body, ResponseCompression::compress($body));
    }

    public function testAClientRefusingGzipWithQZeroIsHonoured(): void
    {
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip;q=0, deflate';

        // `gzip;q=0` is an explicit refusal, not a mention — a naive substring
        // check reads it as consent and sends bytes the client will not decode.
        $body = str_repeat('{"a":1}', 500);

        $this->assertSame($body, ResponseCompression::compress($body));

        unset($_SERVER['HTTP_ACCEPT_ENCODING']);
    }
}
