<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\ThemeExtension;
use FitnessClub\Support\ViteAssets;

/**
 * Front-end themes (D11, W4.1).
 *
 * Almost every test here is about the **fallback**, because that is the property
 * the feature lives or dies on: a theme provides some roles, and every role it
 * does not provide has to keep working exactly as it did before the theme was
 * installed. A swap that works is easy; a swap that degrades correctly when the
 * theme is partial, unbuilt, or deleted out from under the site is the feature.
 *
 * The fixtures live in `tests/fixtures/themes` and are registered as a second
 * theme root, so nothing here writes to the site's real theme directory.
 */
final class ThemeExtensionTest extends IntegrationTestCase
{
    private const PROVIDES  = 'fc-fixture-apps';
    private const NO_BUILD  = 'fc-fixture-nobuild';
    private const ESCAPES   = 'fc-fixture-escape';

    private string $originalChoice = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        register_theme_directory(dirname(__DIR__) . '/fixtures/themes');
        wp_clean_themes_cache();
    }

    public static function tearDownAfterClass(): void
    {
        wp_clean_themes_cache();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalChoice = (string) FitnessClub()->options->get('theme.extension', '');
        ThemeExtension::flushCache();
    }

    protected function tearDown(): void
    {
        FitnessClub()->options->set('theme.extension', $this->originalChoice);
        ThemeExtension::flushCache();

        parent::tearDown();
    }

    private function choose(string $stylesheet): void
    {
        FitnessClub()->options->set('theme.extension', $stylesheet);
        ThemeExtension::flushCache();
    }

    // ------------------------------------------------------------- discovery

    public function testListsOnlyThemesDeclaringTheHeader(): void
    {
        $available = ThemeExtension::available();

        $this->assertArrayHasKey(self::PROVIDES, $available);
        $this->assertArrayHasKey(self::NO_BUILD, $available);

        // The site's own active theme does not declare the header, so it must be
        // absent — the header is the entire opt-in and nothing else grants it.
        $this->assertArrayNotHasKey(get_stylesheet(), $available);
    }

    public function testReportsTheRolesAThemeActuallyProvides(): void
    {
        $available = ThemeExtension::available();

        $this->assertSame(['user'], $available[self::PROVIDES]['provides']);
        $this->assertNull($available[self::PROVIDES]['error']);
        $this->assertSame('FC Fixture Apps', $available[self::PROVIDES]['name']);
    }

    public function testAThemeWithNoBuildIsListedWithAReasonRatherThanHidden(): void
    {
        $available = ThemeExtension::available();

        // Listed, not silently dropped: "my theme does not appear" is a worse
        // support call than "my theme appears and says why it cannot be used".
        $this->assertSame([], $available[self::NO_BUILD]['provides']);
        $this->assertNotNull($available[self::NO_BUILD]['error']);
    }

    // ------------------------------------------------------------- selection

    public function testNothingSelectedMeansThePluginsOwnApps(): void
    {
        $this->choose('');

        $this->assertSame('', ThemeExtension::selected());
        $this->assertNull(ThemeExtension::tags('user'));
    }

    public function testASelectionNamingAMissingThemeFallsBackRatherThanErroring(): void
    {
        // The theme was deleted, or a deploy dropped it. The front end must not
        // go down because a directory went away.
        $this->choose('fc-theme-that-was-deleted');

        $this->assertSame('', ThemeExtension::selected());
        $this->assertNull(ThemeExtension::tags('user'));
    }

    // ------------------------------------------------------------ resolution

    public function testProvidedRoleIsServedFromTheTheme(): void
    {
        $this->choose(self::PROVIDES);

        $tags = ThemeExtension::tags('user');

        $this->assertNotNull($tags);
        $this->assertStringContainsString('fc-fixture-apps/fitnessclub/assets/user-a3f9c1.js', $tags);
        $this->assertStringContainsString('fc-fixture-apps/fitnessclub/assets/user-a3f9c1.css', $tags);
        $this->assertStringContainsString('<script type="module"', $tags);
    }

    public function testUnprovidedRolesFallBackToThePluginsApps(): void
    {
        $this->choose(self::PROVIDES);

        // The fixture provides `user` only. This is the whole per-role promise:
        // a theme replacing the member app leaves trainers and admins alone.
        $this->assertNull(ThemeExtension::tags('trainer'));
        $this->assertNull(ThemeExtension::tags('admin'));
    }

    public function testViteAssetsPrefersTheThemeAndFallsBackPerRole(): void
    {
        $this->choose(self::PROVIDES);

        $user    = ViteAssets::tags('user');
        $trainer = ViteAssets::tags('trainer');

        $this->assertStringContainsString('fc-fixture-apps', $user);

        // Whatever the plugin's own build resolves to, it is not the theme's.
        $this->assertStringNotContainsString('fc-fixture-apps', $trainer);
    }

    public function testAnUnknownRoleIsNeverServed(): void
    {
        $this->choose(self::PROVIDES);

        $this->assertNull(ThemeExtension::tags('superadmin'));
        $this->assertNull(ThemeExtension::tags(''));
    }

    // ------------------------------------------------------------ containment

    public function testAssetPathsEscapingTheThemeDirectoryAreRefused(): void
    {
        $this->choose(self::ESCAPES);

        // `../outside.js` exists and ends .js, so only the realpath containment
        // check stands between a careless manifest and a script tag pointing
        // wherever it likes. The role falls back rather than serving it.
        $this->assertNull(ThemeExtension::tags('user'));
    }

    public function testAssetPathsWithTheWrongExtensionAreRefused(): void
    {
        $this->choose(self::ESCAPES);

        $this->assertNull(ThemeExtension::tags('trainer'));
    }

    public function testAThemeWhoseEveryEntryIsRefusedProvidesNothing(): void
    {
        $available = ThemeExtension::available();

        // It has a manifest with two entries and still provides no roles, which
        // is the honest answer: an entry that cannot be served is not provided.
        $this->assertSame([], $available[self::ESCAPES]['provides']);
        $this->assertNotNull($available[self::ESCAPES]['error']);
    }
}
