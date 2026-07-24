<?php

namespace FitnessClub\Providers;

use FitnessClub\Support\AppRouter;
use FitnessClub\Support\ViteAssets;
use FitnessClub\WPBones\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The configurable front-end URL (D9).
 *
 * wpBones has no front-end routing, so this is native WordPress rewrite wrapped in
 * a provider. It maps `example.com/{app_base}` (default `/fitness`) to a query var,
 * and on `template_redirect` resolves the logged-in role, picks that role's SPA
 * (D10), and renders a standalone full-page shell that boots the Vite bundle.
 *
 * The slug lives in the options model (`routing.app_base`); changing it in Settings
 * flushes rewrite rules. Activation sets a one-shot flush flag consumed here on the
 * next init, once the rule is registered.
 */
class RewriteServiceProvider extends ServiceProvider
{
    public const QUERY_FLAG  = 'fc_app';
    public const QUERY_PATH  = 'fc_app_path';
    public const FLUSH_OPTION = 'fitnessclub_flush_rewrites';

    public function register()
    {
        add_filter('query_vars', [$this, 'registerQueryVars']);
        $this->addRewriteRules();
        add_action('template_redirect', [$this, 'maybeRenderApp']);

        // One-shot flush requested by activation or an app_base change.
        if (get_option(self::FLUSH_OPTION)) {
            flush_rewrite_rules();
            delete_option(self::FLUSH_OPTION);
        }
    }

    /**
     * The sanitised front-end base slug, e.g. "fitness".
     */
    public function appBase(): string
    {
        $base = sanitize_title((string) $this->plugin->options->get('routing.app_base', 'fitness'));

        return '' === $base ? 'fitness' : $base;
    }

    public function addRewriteRules(): void
    {
        $base = $this->appBase();

        add_rewrite_rule('^' . $base . '/?$', 'index.php?' . self::QUERY_FLAG . '=1', 'top');
        add_rewrite_rule(
            '^' . $base . '/(.+?)/?$',
            'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_PATH . '=$matches[1]',
            'top'
        );
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function registerQueryVars($vars): array
    {
        $vars[] = self::QUERY_FLAG;
        $vars[] = self::QUERY_PATH;

        return $vars;
    }

    /**
     * Render the role's SPA when the request matched the app route.
     */
    public function maybeRenderApp(): void
    {
        if (!get_query_var(self::QUERY_FLAG)) {
            return;
        }

        $spa  = AppRouter::currentSpa();
        $base = '/' . $this->appBase();

        status_header(200);
        nocache_headers();

        // The shell is our own Blade template that renders the full HTML document;
        // dynamic values are escaped inside it (esc_attr on data-boot, {{ }} on the
        // rest). The rendered markup is therefore output as-is.
        $html = (string) $this->plugin->view('app.shell', [
            'boot' => $this->bootPayload($spa, $base),
            'tags' => ViteAssets::tags($spa),
            'lang' => get_bloginfo('language'),
        ]);

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        exit;
    }

    /**
     * The boot payload injected into the shell (plans/02-api-contract.md).
     * Minimal for now; a BootPresenter fills in theme tokens + entitlements later.
     *
     * @return array<string,mixed>
     */
    private function bootPayload(string $spa, string $base): array
    {
        $user = wp_get_current_user();

        return [
            'restUrl' => esc_url_raw(rest_url('fitnessclub/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'user'    => $user->exists() ? [
                'id'           => 0, // fc_users.id — resolved once that table is rebuilt
                'wp_user_id'   => (int) $user->ID,
                'display_name' => $user->display_name,
                'avatar_url'   => get_avatar_url($user->ID),
                'role'         => $this->primaryRole($user),
            ] : null,
            'app' => [
                'base'     => $base,
                'basename' => $base,
                'spa'      => $spa,
            ],
            'brand'  => $this->plugin->options->get('branding.name', 'FitForge'),
            'locale' => determine_locale(),
        ];
    }

    private function primaryRole(\WP_User $user): string
    {
        if (user_can($user, 'manage_options')) {
            return 'administrator';
        }
        if (in_array('fc_trainer', (array) $user->roles, true)) {
            return 'fc_trainer';
        }

        return 'fc_user';
    }
}
