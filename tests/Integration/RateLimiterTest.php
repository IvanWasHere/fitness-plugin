<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\RateLimitException;
use FitnessClub\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * The token bucket behind every public endpoint (plans/03-backend.md#rate-limiting).
 */
final class RateLimiterTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'test' . wp_generate_password(8, false);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('unit', $this->key);
        parent::tearDown();
    }

    public function testAllowsUpToTheLimitThenThrows(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $remaining = RateLimiter::hit('unit', $this->key, 3, 60);
            $this->assertSame(3 - $i, $remaining, "Hit {$i} should report the right headroom.");
        }

        $this->expectException(RateLimitException::class);
        RateLimiter::hit('unit', $this->key, 3, 60);
    }

    public function testTheExceptionCarriesEverythingA429Needs(): void
    {
        RateLimiter::hit('unit', $this->key, 1, 60);

        try {
            RateLimiter::hit('unit', $this->key, 1, 60);
            $this->fail('The second hit should have been rejected.');
        } catch (RateLimitException $e) {
            $error = $e->toWpError();

            $this->assertSame('fc_rate_limited', $error->get_error_code());
            $data = $error->get_error_data();
            $this->assertSame(429, $data['status']);
            $this->assertSame(1, $data['limit']);
            $this->assertGreaterThan(0, $data['retry_after']);
            $this->assertLessThanOrEqual(60, $data['retry_after']);
            $this->assertNotEmpty($data['resets_at']);
        }
    }

    public function testClearReleasesTheBucket(): void
    {
        RateLimiter::hit('unit', $this->key, 1, 60);
        RateLimiter::clear('unit', $this->key);

        // No exception: the window started over.
        $this->assertSame(0, RateLimiter::hit('unit', $this->key, 1, 60));
    }

    public function testRemainingDoesNotConsumeABudget(): void
    {
        RateLimiter::hit('unit', $this->key, 5, 60);

        $this->assertSame(4, RateLimiter::remaining('unit', $this->key, 5, 60));
        $this->assertSame(4, RateLimiter::remaining('unit', $this->key, 5, 60));
        $this->assertSame(3, RateLimiter::hit('unit', $this->key, 5, 60));
    }

    public function testBucketsAreIndependent(): void
    {
        RateLimiter::hit('unit', $this->key, 1, 60);

        // A different bucket with the same identity has its own budget.
        $this->assertSame(0, RateLimiter::hit('other', $this->key, 1, 60));
        RateLimiter::clear('other', $this->key);
    }

    public function testIpIsHashedNotStored(): void
    {
        $hash = RateLimiter::ipHash();

        $this->assertSame(32, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $hash);
        // A raw IP is personal data — the key must not contain one.
        $this->assertStringNotContainsString($_SERVER['REMOTE_ADDR'], $hash);
        $this->assertSame($hash, RateLimiter::ipHash(), 'The hash must be stable within a request.');
    }

    public function testKeyForIsCaseAndWhitespaceInsensitive(): void
    {
        // Otherwise " Alex@Example.test " walks straight past the per-address
        // limit that " alex@example.test " just spent.
        $this->assertSame(
            RateLimiter::keyFor('alex@example.test'),
            RateLimiter::keyFor('  Alex@Example.test  ')
        );
    }
}
