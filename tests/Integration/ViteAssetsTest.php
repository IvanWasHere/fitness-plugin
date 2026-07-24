<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\ViteAssets;
use PHPUnit\Framework\TestCase;

/**
 * The Vite manifest bridge (D10): each role entry resolves to its built bundle.
 *
 * @covers \FitnessClub\Support\ViteAssets
 */
final class ViteAssetsTest extends TestCase
{
    public function testProdModeByDefault(): void
    {
        $this->assertFalse(ViteAssets::isDev(), 'Without the dev constant, prod (manifest) mode.');
    }

    /**
     * @dataProvider spaProvider
     */
    public function testEachEntryResolvesToItsBundle(string $spa): void
    {
        $tags = ViteAssets::tags($spa);

        $this->assertStringContainsString('type="module"', $tags);
        $this->assertMatchesRegularExpression(
            '#/public/ui/assets/' . $spa . '-[A-Za-z0-9_-]+\.js#',
            $tags,
            "The {$spa} entry should resolve to its hashed bundle in the manifest."
        );
    }

    public function testEntryPreloadsSharedChunk(): void
    {
        // Every entry imports the shared chunk (React + src/shared/).
        $this->assertStringContainsString('rel="modulepreload"', ViteAssets::tags('user'));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function spaProvider(): array
    {
        return [
            'user'    => ['user'],
            'trainer' => ['trainer'],
            'admin'   => ['admin'],
        ];
    }
}
