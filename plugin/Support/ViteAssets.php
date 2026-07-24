<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Bridges the Vite front-end build (D10) to the WordPress-served shell (D9).
 *
 * The three SPAs are built by Vite to `public/ui/` with a manifest. This class
 * turns a role entry (`user` | `trainer` | `admin`) into the exact <script>/<link>
 * tags the shell must emit — reading the production manifest, or pointing at the
 * running Vite dev server for HMR.
 *
 * Dev mode is opt-in via a wp-config constant so production never probes a dev
 * server:  define('FITNESSCLUB_VITE_DEV', true);  (optionally FITNESSCLUB_VITE_DEV_URL).
 */
final class ViteAssets
{
    private const DEV_URL = 'http://localhost:5173';

    /** @var array<string,mixed>|null */
    private static ?array $manifestCache = null;

    public static function isDev(): bool
    {
        return defined('FITNESSCLUB_VITE_DEV') && FITNESSCLUB_VITE_DEV;
    }

    private static function devUrl(): string
    {
        return defined('FITNESSCLUB_VITE_DEV_URL') ? rtrim(FITNESSCLUB_VITE_DEV_URL, '/') : self::DEV_URL;
    }

    /** Public URL of the built assets, e.g. https://site/wp-content/plugins/fitnessclub/public/ui */
    private static function baseUrl(): string
    {
        return FitnessClub()->baseUri . '/public/ui';
    }

    /** Filesystem path to the Vite manifest. */
    private static function manifestPath(): string
    {
        return FitnessClub()->basePath . '/public/ui/.vite/manifest.json';
    }

    /**
     * The HTML tags that load the given role's SPA.
     *
     * @param string $entry One of user|trainer|admin.
     */
    public static function tags(string $entry): string
    {
        return self::isDev() ? self::devTags($entry) : self::prodTags($entry);
    }

    /**
     * Dev: load @vite/client + the entry straight from the dev server, with the
     * React Refresh preamble @vitejs/plugin-react needs for HMR.
     */
    private static function devTags(string $entry): string
    {
        $dev = self::devUrl();

        $preamble = <<<HTML
<script type="module">
  import RefreshRuntime from "{$dev}/@react-refresh";
  RefreshRuntime.injectIntoGlobalHook(window);
  window.\$RefreshReg\$ = () => {};
  window.\$RefreshSig\$ = () => (type) => type;
  window.__vite_plugin_react_preamble_installed__ = true;
</script>
HTML;

        return $preamble
            . '<script type="module" src="' . esc_url("{$dev}/@vite/client") . '"></script>'
            . '<script type="module" src="' . esc_url("{$dev}/src/{$entry}/main.tsx") . '"></script>';
    }

    /**
     * Prod: resolve the entry through the manifest to its hashed file, preload
     * its shared chunks, and include any CSS.
     */
    private static function prodTags(string $entry): string
    {
        $manifest = self::manifest();
        $key      = "src/{$entry}/main.tsx";

        if (!isset($manifest[$key]['file'])) {
            return '<!-- fitnessclub: missing build for ' . esc_html($entry) . ' (run: cd ui && npm run build) -->';
        }

        $base = self::baseUrl();
        $node = $manifest[$key];
        $out  = '';

        // CSS emitted by the entry itself.
        foreach (($node['css'] ?? []) as $css) {
            $out .= '<link rel="stylesheet" href="' . esc_url("{$base}/{$css}") . '">';
        }

        // Preload shared chunks the entry imports (React, src/shared/…) + their CSS.
        foreach (($node['imports'] ?? []) as $importKey) {
            if (isset($manifest[$importKey]['file'])) {
                $out .= '<link rel="modulepreload" href="' . esc_url("{$base}/{$manifest[$importKey]['file']}") . '">';
            }
            foreach (($manifest[$importKey]['css'] ?? []) as $css) {
                $out .= '<link rel="stylesheet" href="' . esc_url("{$base}/{$css}") . '">';
            }
        }

        $out .= '<script type="module" src="' . esc_url("{$base}/{$node['file']}") . '"></script>';

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function manifest(): array
    {
        if (null !== self::$manifestCache) {
            return self::$manifestCache;
        }

        $path = self::manifestPath();
        if (!is_readable($path)) {
            return self::$manifestCache = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$manifestCache = is_array($decoded) ? $decoded : [];
    }
}
