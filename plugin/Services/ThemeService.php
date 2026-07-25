<?php

namespace FitnessClub\Services;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Theme tokens → CSS custom properties (plans/07-theming.md).
 *
 * Both prototypes are already driven entirely by custom properties, so theming
 * is "generate a token block from validated JSON", not a refactor.
 *
 * Resolution order, so a partial theme file stays valid and a single colour
 * tweak never requires forking a whole theme:
 *
 *     bundled default  ←  selected theme  ←  admin overrides
 *
 * W1.3 ships the read path the boot payload and the shell need. Phase 2 adds the
 * uploads directory (`wp-content/uploads/fitnessclub-themes/{slug}/`), the JSON
 * schema validation, the contrast check and the admin editor; the merge order and
 * the emitted variable names are already final, so those are additive.
 */
final class ThemeService
{
    public const DEFAULT_SLUG = 'default';

    /** Scoped to the app root, not :root — see plans/07-theming.md#rendering. */
    private const SELECTOR = '#fc-app';

    private const CSS_TRANSIENT = 'fitnessclub_theme_css';

    /** @var array<string,array<string,mixed>> */
    private static array $fileCache = [];

    /**
     * The merged, active token set — what `/auth/me` and the shell's boot payload
     * carry as `theme`.
     *
     * @return array<string,mixed>
     */
    public function activeTokens(): array
    {
        $tokens = $this->bundled(self::DEFAULT_SLUG);

        $slug = $this->activeSlug();
        if (self::DEFAULT_SLUG !== $slug) {
            $tokens = $this->merge($tokens, $this->bundled($slug));
        }

        return $this->merge($tokens, $this->overrides());
    }

    /**
     * The `<style>` body for the shell: one custom property per token.
     *
     * Cached in a transient keyed by slug + override hash, because this runs on
     * every front-end render and the JSON never changes between edits.
     */
    public function cssVariables(): string
    {
        $key    = self::CSS_TRANSIENT . '_' . $this->fingerprint();
        $cached = get_transient($key);
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $tokens = $this->activeTokens();
        $vars   = [];

        // One prefix per token group, so `colors.primary` becomes
        // `--fc-color-primary` and `typography.base_size` `--fc-type-base-size`.
        foreach (['colors' => 'color', 'typography' => 'type', 'layout' => 'layout'] as $group => $prefix) {
            foreach ((array) ($tokens[$group] ?? []) as $name => $value) {
                if (is_scalar($value)) {
                    $vars[] = sprintf(
                        '--fc-%s-%s:%s',
                        $prefix,
                        str_replace('_', '-', (string) $name),
                        $this->sanitizeValue((string) $value)
                    );
                }
            }
        }

        $css = self::SELECTOR . '{' . implode(';', $vars) . '}';

        set_transient($key, $css, DAY_IN_SECONDS);

        return $css;
    }

    public function activeSlug(): string
    {
        $slug = sanitize_key((string) FitnessClub()->options->get('theme.active', self::DEFAULT_SLUG));

        return '' === $slug ? self::DEFAULT_SLUG : $slug;
    }

    /**
     * Admin overrides, stored as a JSON string in the options model.
     *
     * @return array<string,mixed>
     */
    private function overrides(): array
    {
        $raw = (string) FitnessClub()->options->get('theme.overrides', '');
        if ('' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read a bundled theme file. Missing or malformed files resolve to an empty
     * set, which the merge then falls through — a broken upload must not blank
     * out the app.
     *
     * @return array<string,mixed>
     */
    private function bundled(string $slug): array
    {
        if (isset(self::$fileCache[$slug])) {
            return self::$fileCache[$slug];
        }

        $path = FitnessClub()->basePath . '/resources/themes/' . $slug . '/theme.json';

        if (!is_readable($path)) {
            return self::$fileCache[$slug] = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$fileCache[$slug] = is_array($decoded) ? $decoded : [];
    }

    /**
     * Recursive merge where the override wins per leaf key (so `colors.primary`
     * can be replaced without restating the other sixteen colours).
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private function fingerprint(): string
    {
        return substr(md5($this->activeSlug() . '|' . wp_json_encode($this->overrides())), 0, 12);
    }

    /**
     * Token values land inside a `<style>` block, so anything that could close it
     * or start a declaration of its own has to go: `<` (`</style>`), `;` and `{}`
     * (a new declaration or rule). Quotes stay — `font_family` needs them.
     * Overrides are admin-supplied, but "admin" is not "trusted with raw CSS
     * injection into every front-end page".
     */
    private function sanitizeValue(string $value): string
    {
        return trim(str_replace(['<', '>', '{', '}', ';'], '', $value));
    }
}
