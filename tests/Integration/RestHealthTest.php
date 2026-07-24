<?php

namespace FitnessClub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * The REST namespace is registered and the health route responds (D3).
 */
final class RestHealthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');
    }

    public function testNamespaceRegistered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/fitnessclub/v1', $routes);
    }

    public function testHealthReturns200WithExpectedShape(): void
    {
        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/fitnessclub/v1/health'));

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame('fitnessclub', $data['plugin']);
        $this->assertSame('0.1.0', $data['version']);
    }
}
