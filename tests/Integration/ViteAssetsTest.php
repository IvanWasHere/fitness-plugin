<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\ThemeExtension;
use FitnessClub\Support\ViteAssets;
use PHPUnit\Framework\TestCase;

/**
 * The Vite manifest bridge (D10): each role entry resolves to its built bundle.
 *
 * ## Why this pins `theme.extension`
 *
 * These assertions are about the **plugin's own** manifest resolution, and since
 * D11 that is only what `tags()` returns when no front-end theme is selected —
 * a selected theme legitimately answers with its own bundle instead. Reading the
 * site's live option here made the test a statement about the dev install's
 * current settings rather than about the code, and it duly failed the moment a
 * theme was selected on that install.
 *
 * That is the same defect the W3.2 webhook test had (a hardcoded `evt_1` that
 * collided with its own leftover transient): a test that depends on mutable
 * global state and passes only because nothing has changed it yet. The fix is
 * the same — own the state for the duration and put it back.
 *
 * @covers \FitnessClub\Support\ViteAssets
 */
final class ViteAssetsTest extends TestCase
{
    private string $originalExtension = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalExtension = (string) FitnessClub()->options->get('theme.extension', '');
        FitnessClub()->options->set('theme.extension', '');
        ThemeExtension::flushCache();
    }

    protected function tearDown(): void
    {
        FitnessClub()->options->set('theme.extension', $this->originalExtension);
        ThemeExtension::flushCache();

        parent::tearDown();
    }

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
