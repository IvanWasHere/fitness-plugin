<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Capabilities;
use FitnessClub\Services\BootPresenter;
use FitnessClub\Services\ThemeService;
use FitnessClub\Support\AppRouter;

/**
 * The boot payload and the theme tokens that ride with it (W1.3).
 *
 * The exit criterion for this work package is that a visitor to `/{base}` gets
 * the right SPA with their name and theme already applied — no spinner, no
 * second round trip. That is entirely a property of what BootPresenter and
 * ThemeService emit, so it is asserted here rather than through the browser.
 */
final class BootPayloadTest extends IntegrationTestCase
{
    public function testAnonymousVisitorGetsTheUserSpaWithNoIdentity(): void
    {
        $this->signOut();

        $boot = (new BootPresenter())->shell();

        // The user SPA renders the login panel for a null user — that is how an
        // unauthenticated visitor to /{base} is served (plans/04-user-app.md).
        $this->assertNull($boot['user']);
        $this->assertSame('user', $boot['app']['spa']);
        $this->assertSame(AppRouter::basePath(), $boot['app']['basename']);
        $this->assertArrayHasKey('registration_open', $boot['flags']);

        // An anonymous visitor still gets a CSRF token: the login form is a
        // write, and without one it could not be submitted at all.
        $this->assertNotEmpty($boot['csrf']);
    }

    public function testShellPayloadCarriesEverythingTheFirstFrameNeeds(): void
    {
        $accountId = $this->makeAccount(Capabilities::ROLE_USER);
        $this->signIn($accountId);

        $boot = (new BootPresenter())->shell();

        foreach (['user', 'subscriptions', 'trainers', 'entitlements', 'theme', 'app', 'counts'] as $key) {
            $this->assertArrayHasKey($key, $boot, "The /auth/me contract key '{$key}' is missing.");
        }
        foreach (['restUrl', 'csrf', 'brand', 'locale', 'flags'] as $key) {
            $this->assertArrayHasKey($key, $boot, "The shell transport key '{$key}' is missing.");
        }

        // Gone with WordPress identity, and their absence is part of the
        // contract: a client still reading these is speaking the old protocol.
        $this->assertArrayNotHasKey('nonce', $boot);
        $this->assertArrayNotHasKey('ajaxUrl', $boot);

        $this->assertSame($accountId, $boot['user']['account_id']);
        $this->assertSame(Auth::account()->displayName, $boot['user']['display_name']);
        $this->assertSame(0, $boot['counts']['unread_messages']);
        $this->assertSame(0, $boot['counts']['unread_notifications']);
    }

    public function testTrainerAndAdminResolveToTheirOwnSpa(): void
    {
        $this->signIn($this->makeAccount(Capabilities::ROLE_TRAINER));
        $this->assertSame('trainer', (new BootPresenter())->me()['app']['spa']);

        $this->signIn($this->makeAccount(Capabilities::ROLE_ADMIN));
        $this->assertSame('admin', (new BootPresenter())->me()['app']['spa']);
    }

    public function testAWordPressSessionContributesNothingToTheBootPayload(): void
    {
        $wpUser = wp_insert_user([
            'user_login' => 'fc_boot_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(),
            'user_email' => uniqid('fc_boot_', true) . '@example.test',
            'role'       => 'administrator',
        ]);

        wp_set_current_user((int) $wpUser);
        $this->signOut();

        $boot = (new BootPresenter())->shell();

        $this->assertNull($boot['user'], 'A wp-admin session is not an app session.');
        $this->assertSame('user', $boot['app']['spa']);

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user((int) $wpUser);
    }

    public function testThemeTokensMergeDownToCssVariablesScopedToTheAppRoot(): void
    {
        $service = new ThemeService();
        $tokens  = $service->activeTokens();

        $this->assertSame('default', $tokens['theme_name']);
        $this->assertNotEmpty($tokens['colors']['primary']);
        $this->assertNotEmpty($tokens['typography']['font_family']);

        $css = $service->cssVariables();

        // Scoped to #fc-app, not :root — see plans/07-theming.md#rendering.
        $this->assertStringStartsWith('#fc-app{', $css);
        $this->assertStringContainsString('--fc-color-primary:' . $tokens['colors']['primary'], $css);
        $this->assertStringContainsString('--fc-type-base-size:', $css);
        $this->assertStringContainsString('--fc-layout-radius:', $css);

        // Nothing that could close the <style> block or start a new declaration.
        $this->assertStringNotContainsString('<', $css);
    }

    public function testTextMutedUsesTheContrastCorrectedValue(): void
    {
        // The prototypes' #7A8BA7 fails WCAG AA on the card background; the plan
        // corrects it and the bundled theme must not quietly regress.
        $this->assertSame('#8FA0BC', (new ThemeService())->activeTokens()['colors']['text_muted']);
    }

    public function testShellMarkupCarriesTheBootPayloadAndTheThemeBlock(): void
    {
        $this->signOut();

        $html = FitnessClub()->view('app.shell', [
            'boot'     => (new BootPresenter())->shell(),
            'tags'     => '<!-- tags -->',
            'themeCss' => (new ThemeService())->cssVariables(),
            'lang'     => 'en-US',
        ])->toHTML();

        $this->assertStringContainsString('id="fc-app"', $html);
        $this->assertStringContainsString('data-boot="', $html);
        $this->assertStringContainsString('id="fc-theme-vars"', $html);
        $this->assertStringContainsString('--fc-color-primary', $html);

        // The payload must survive attribute escaping intact — a SPA that cannot
        // parse data-boot boots into nothing at all.
        preg_match('/data-boot="([^"]*)"/', $html, $matches);
        $decoded = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);

        $this->assertIsArray($decoded, 'data-boot must survive esc_attr as valid JSON.');
        $this->assertNull($decoded['user']);
        $this->assertSame('user', $decoded['app']['spa']);
    }

    public function testAppUrlIsBuiltFromTheConfiguredBase(): void
    {
        $this->assertSame(home_url(AppRouter::basePath() . '/'), AppRouter::url());
        $this->assertSame(home_url(AppRouter::basePath() . '/reset'), AppRouter::url('reset'));
    }
}
