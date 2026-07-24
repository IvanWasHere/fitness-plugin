<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Providers\RewriteServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * The configurable front-end route (D9): the app_base rewrite rule and its query
 * vars are registered.
 *
 * @covers \FitnessClub\Providers\RewriteServiceProvider
 */
final class RewriteRouteTest extends TestCase
{
    public function testAppBaseComesFromOptions(): void
    {
        $provider = new RewriteServiceProvider(FitnessClub());
        $this->assertSame(
            FitnessClub()->options->get('routing.app_base', 'fitness'),
            $provider->appBase()
        );
    }

    public function testQueryVarsRegistered(): void
    {
        $vars = apply_filters('query_vars', []);
        $this->assertContains(RewriteServiceProvider::QUERY_FLAG, $vars);
        $this->assertContains(RewriteServiceProvider::QUERY_PATH, $vars);
    }

    public function testRewriteRuleForAppBaseExists(): void
    {
        $base  = FitnessClub()->options->get('routing.app_base', 'fitness');
        $rules = get_option('rewrite_rules') ?: [];

        $matched = false;
        foreach (array_keys($rules) as $pattern) {
            if (str_starts_with($pattern, '^' . $base)) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, "A rewrite rule for '^{$base}' should be registered.");
    }
}
