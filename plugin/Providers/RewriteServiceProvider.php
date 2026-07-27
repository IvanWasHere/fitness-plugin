<?php

namespace FitnessClub\Providers;

use FitnessClub\Services\BootPresenter;
use FitnessClub\Services\ThemeService;
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
        return AppRouter::base();
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

        // Whoever WordPress thinks is rendering this, they are not the app's
        // user. An administrator browsing wp-admin in another tab must see the
        // login panel here, not the admin SPA.
        wp_set_current_user(0);

        $spa = AppRouter::currentSpa();

        status_header(200);
        nocache_headers();

        // The shell embeds the member's identity *and* their CSRF token in
        // `data-boot`, so a cached copy served to a second visitor is a session
        // leak — not a stale page. Every stock cache-bypass rule keys on
        // `wordpress_logged_in_*`, which a cookie named `fc_session_*` does not
        // match, so the bypass has to be requested explicitly. This is the one
        // place a plugin-owned cookie is genuinely worse than WordPress' own,
        // and `/{base}/*` should also be excluded at the edge on any host with a
        // full-page cache. See Auth\SessionCookie for the filter that lets an
        // uncooperative cache be given a name it recognises.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        header('Vary: Cookie');

        // toHTML(), not (string) or render(): wpBones' render() *echoes* and
        // returns null outside ajax, so casting would emit the page as a side
        // effect and leave $html empty. Capturing it keeps the one echo below the
        // only place output happens.
        //
        // The shell is our own Blade template that renders the full HTML document;
        // dynamic values are escaped inside it (esc_attr on data-boot, {{ }} on the
        // rest). The rendered markup is therefore output as-is.
        $html = $this->plugin->view('app.shell', [
            'boot'     => (new BootPresenter())->shell(),
            'tags'     => ViteAssets::tags($spa),
            'themeCss' => (new ThemeService())->cssVariables(),
            'lang'     => get_bloginfo('language'),
        ])->toHTML();

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        exit;
    }
}
