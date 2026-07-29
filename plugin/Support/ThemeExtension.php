<?php

namespace FitnessClub\Support;

use WP_Theme;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Front-end themes — WordPress themes that ship React apps (D11).
 *
 * A theme opts in with a `style.css` header and carries a Vite build:
 *
 *     wp-content/themes/fitnessTheme/
 *     ├── style.css          # Fitness Plugin Extension Enabled: true
 *     ├── index.php          # WordPress requires it
 *     └── fitnessclub/
 *         ├── .vite/manifest.json
 *         └── assets/user-a3f9.js, user-a3f9.css, …
 *
 * Select one on the wp-admin options page and its apps are served instead of the
 * plugin's — **per role**. Whichever of user/trainer/admin the theme's manifest
 * has an entry for, the theme provides; every other role falls back to ours and
 * never knows a theme is installed.
 *
 * ## There is no bespoke manifest
 *
 * An earlier draft of D11 invented a `theme.json` declaring which roles a theme
 * provided. It was dropped as redundant: a theme is built with Vite, Vite already
 * emits a manifest, and this reads *the theme's* manifest exactly the way
 * {@see ViteAssets} reads the plugin's own. Nothing is hand-written, and the
 * hashed filenames Vite produces are handled for free — a hand-maintained list
 * would be wrong after every rebuild.
 *
 * ## Why the header is not read through WP_Theme
 *
 * `WP_Theme::get()` exposes custom headers only when they are registered through
 * the `extra_theme_headers` filter, *and* it caches parsed headers in the object
 * cache. A filter registered after that cache is warm therefore reads `false` on
 * a theme that plainly declares the header — a bug that appears only on warm
 * caches and is close to unreproducible locally. `get_file_data()` against the
 * theme's own `style.css` has neither the ordering nor the caching hazard. The
 * filter is registered anyway, so the field shows in WordPress's theme details,
 * but nothing here depends on it.
 *
 * ## Trust
 *
 * Installation is filesystem-only — no upload endpoint — so a theme author is
 * trusted at the level a plugin author is. What is still validated is the
 * manifest, against carelessness rather than malice: every asset path must
 * resolve inside the theme's own `fitnessclub/` directory and carry the right
 * extension, or a typo'd manifest emits a `<script>` tag pointing anywhere.
 */
final class ThemeExtension
{
    /** The `style.css` header a theme sets to appear in the dropdown. */
    public const HEADER = 'Fitness Plugin Extension Enabled';

    /** Everything the plugin reads lives under this directory inside the theme. */
    public const SUBDIR = 'fitnessclub';

    /** @var string[] */
    public const ROLES = ['user', 'trainer', 'admin'];

    /** @var array<string,array<string,mixed>|null> */
    private static array $manifestCache = [];

    /**
     * Themes offering the extension, keyed by stylesheet directory.
     *
     * `wp_get_themes()` returns only error-free themes by default, so a theme
     * missing `index.php` is absent here rather than listed-and-broken. That is
     * the right behaviour and the wrong error message, which is why the settings
     * screen says so in as many words.
     *
     * @return array<string,array{name:string,version:string,provides:string[],error:?string}>
     */
    public static function available(): array
    {
        $out = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            if (!self::declaresHeader($theme)) {
                continue;
            }

            $provides = [];
            $error    = null;

            $manifest = self::manifest((string) $stylesheet);

            if (null === $manifest) {
                $error = __('No Vite build found in the theme\'s fitnessclub/ directory.', 'fitnessclub');
            } else {
                foreach (self::ROLES as $role) {
                    if (null !== self::entryFor((string) $stylesheet, $role)) {
                        $provides[] = $role;
                    }
                }

                if ([] === $provides) {
                    $error = __('The theme\'s build has no user, trainer or admin entry.', 'fitnessclub');
                }
            }

            $out[(string) $stylesheet] = [
                'name'     => (string) $theme->get('Name'),
                'version'  => (string) $theme->get('Version'),
                'provides' => $provides,
                'error'    => $error,
            ];
        }

        return $out;
    }

    /**
     * The selected theme's stylesheet directory, or '' for the plugin's own apps.
     *
     * A selection naming a theme that no longer resolves answers '' rather than
     * erroring: a theme directory can vanish under a running site — a WordPress
     * theme delete, a deploy that drops it — and the front end must not go down
     * because a directory went away.
     */
    public static function selected(): string
    {
        $slug = (string) FitnessClub()->options->get('theme.extension', '');

        if ('' === $slug) {
            return '';
        }

        $theme = wp_get_theme($slug);

        if (!$theme->exists() || !self::declaresHeader($theme)) {
            return '';
        }

        return $slug;
    }

    /**
     * The `<script>`/`<link>` tags for a role, or null when the plugin's own app
     * should be served — no theme selected, or this theme does not provide this
     * role.
     */
    public static function tags(string $role): ?string
    {
        $stylesheet = self::selected();

        if ('' === $stylesheet) {
            return null;
        }

        $entry = self::entryFor($stylesheet, $role);

        if (null === $entry) {
            return null;
        }

        $base = self::baseUrl($stylesheet);
        $out  = '';

        foreach ($entry['styles'] as $css) {
            $out .= '<link rel="stylesheet" href="' . esc_url("{$base}/{$css}") . '">';
        }

        // Preload the chunks the entry imports. The browser would find them
        // anyway by walking the module graph, but only after parsing the entry —
        // which on a theme that splits React into a shared chunk is the whole
        // first paint waiting on a round trip it could have started immediately.
        foreach ($entry['preload'] as $chunk) {
            $out .= '<link rel="modulepreload" href="' . esc_url("{$base}/{$chunk}") . '">';
        }

        $out .= '<script type="module" src="' . esc_url("{$base}/{$entry['script']}") . '"></script>';

        return $out;
    }

    // ------------------------------------------------------------- internals

    /**
     * Resolve one role against a theme's Vite manifest.
     *
     * The plugin's own convention is `src/{role}/main.tsx`, and a theme built the
     * same way matches on the first try. A theme with its own source layout is
     * matched on the entry *name* or on a `{role}/main.*` suffix instead —
     * requiring a byte-identical source path would make the convention a build
     * constraint rather than a default.
     *
     * @return array{script:string,styles:string[],preload:string[]}|null
     */
    private static function entryFor(string $stylesheet, string $role): ?array
    {
        if (!in_array($role, self::ROLES, true)) {
            return null;
        }

        $manifest = self::manifest($stylesheet);

        if (null === $manifest) {
            return null;
        }

        $node = $manifest["src/{$role}/main.tsx"] ?? null;

        if (!is_array($node)) {
            $node = self::findEntry($manifest, $role);
        }

        if (!is_array($node) || !isset($node['file']) || !is_string($node['file'])) {
            return null;
        }

        $script = self::safeAsset($stylesheet, $node['file'], 'js');

        if (null === $script) {
            return null;
        }

        $styles = [];

        // CSS from the entry itself *and* from every chunk it imports. Vite hangs
        // the stylesheet off the shared chunk whenever two entries import the
        // same CSS, which is the normal outcome for a theme with more than one
        // app — reading only the entry's own `css` renders both of them unstyled.
        foreach (self::cssFor($manifest, $node) as $css) {
            $safe = self::safeAsset($stylesheet, $css, 'css');

            if (null !== $safe) {
                $styles[] = $safe;
            }
        }

        $preload = [];

        foreach ((array) ($node['imports'] ?? []) as $key) {
            if (!is_string($key) || !isset($manifest[$key]['file']) || !is_string($manifest[$key]['file'])) {
                continue;
            }

            $safe = self::safeAsset($stylesheet, $manifest[$key]['file'], 'js');

            if (null !== $safe) {
                $preload[] = $safe;
            }
        }

        return [
            'script'  => $script,
            'styles'  => array_values(array_unique($styles)),
            'preload' => array_values(array_unique($preload)),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $node
     * @return string[]
     */
    private static function cssFor(array $manifest, array $node): array
    {
        $css = [];

        foreach ((array) ($node['css'] ?? []) as $file) {
            if (is_string($file)) {
                $css[] = $file;
            }
        }

        foreach ((array) ($node['imports'] ?? []) as $key) {
            if (!is_string($key) || !isset($manifest[$key]) || !is_array($manifest[$key])) {
                continue;
            }

            foreach ((array) ($manifest[$key]['css'] ?? []) as $file) {
                if (is_string($file)) {
                    $css[] = $file;
                }
            }
        }

        return $css;
    }

    /**
     * Fallback lookup for a theme whose Vite root is not laid out like ours.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>|null
     */
    private static function findEntry(array $manifest, string $role): ?array
    {
        foreach ($manifest as $key => $node) {
            if (!is_array($node) || empty($node['isEntry'])) {
                continue;
            }

            if (isset($node['name']) && $node['name'] === $role) {
                return $node;
            }

            if (preg_match('#(^|/)' . preg_quote($role, '#') . '/main\.[jt]sx?$#', (string) $key)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * A manifest-declared path, confirmed to be a real file of the right kind
     * inside the theme's own `fitnessclub/` directory.
     *
     * `realpath()` rather than a string check on `../`: a symlink out of the
     * directory is the same escape written differently, and only resolving the
     * path catches both.
     */
    private static function safeAsset(string $stylesheet, string $relative, string $extension): ?string
    {
        if ('' === $relative || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $relative)) {
            // Absolute or protocol-relative URL — a theme's assets are local files.
            return null;
        }

        if (strtolower((string) pathinfo($relative, PATHINFO_EXTENSION)) !== $extension) {
            return null;
        }

        $root = realpath(self::basePath($stylesheet));
        $full = realpath(self::basePath($stylesheet) . '/' . $relative);

        if (false === $root || false === $full || !is_file($full)) {
            return null;
        }

        // Trailing separator so /themes/x/fitnessclub-evil cannot pass as a child
        // of /themes/x/fitnessclub.
        if (!str_starts_with($full, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return ltrim($relative, '/');
    }

    /**
     * @return array<string,mixed>|null Null when the theme ships no build.
     */
    private static function manifest(string $stylesheet): ?array
    {
        if (array_key_exists($stylesheet, self::$manifestCache)) {
            return self::$manifestCache[$stylesheet];
        }

        $path = self::basePath($stylesheet) . '/.vite/manifest.json';

        if (!is_readable($path)) {
            return self::$manifestCache[$stylesheet] = null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$manifestCache[$stylesheet] = is_array($decoded) ? $decoded : null;
    }

    private static function basePath(string $stylesheet): string
    {
        return wp_get_theme($stylesheet)->get_stylesheet_directory() . '/' . self::SUBDIR;
    }

    private static function baseUrl(string $stylesheet): string
    {
        return wp_get_theme($stylesheet)->get_stylesheet_directory_uri() . '/' . self::SUBDIR;
    }

    /**
     * Read the opt-in header straight from the theme's `style.css`.
     *
     * See the class docblock for why this does not go through `WP_Theme::get()`.
     * Anything other than a truthy string is "not enabled" — a theme that writes
     * `false`, `0` or nothing at all is simply not offering the extension.
     */
    private static function declaresHeader(WP_Theme $theme): bool
    {
        $style = $theme->get_stylesheet_directory() . '/style.css';

        if (!is_readable($style)) {
            return false;
        }

        $data = get_file_data($style, ['enabled' => self::HEADER], 'theme');

        return in_array(strtolower(trim((string) ($data['enabled'] ?? ''))), ['true', '1', 'yes', 'on'], true);
    }

    /** Test seam — the manifest memo is per-request and per-theme. */
    public static function flushCache(): void
    {
        self::$manifestCache = [];
    }
}
