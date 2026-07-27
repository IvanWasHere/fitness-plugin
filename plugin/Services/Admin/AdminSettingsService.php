<?php

namespace FitnessClub\Services\Admin;

use FitnessClub\Providers\RewriteServiceProvider;
use FitnessClub\Support\AppRouter;
use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `GET`/`PUT /admin/settings` (plans/05-admin-app.md#settings--app-url, W2.5).
 *
 * ## The App URL is the dangerous one
 *
 * It decides where the entire front-end lives (D9), which means a bad value
 * takes the app offline — including the screen you would use to fix it. So it is
 * validated hard here, and it is **also** editable from the thin wp-admin
 * launcher as break-glass. That duplication is deliberate and is the only
 * setting that has it.
 *
 * Changing it sets the flush flag; `RewriteServiceProvider` consumes that on the
 * next request, which is the pattern the wp-admin screen already established
 * (see the 2026-07-27 build note in the roadmap).
 *
 * Only whitelisted keys are writable. The options row is a single JSON blob, so
 * accepting arbitrary keys would let a typo in the client write a permanent
 * orphan into it.
 */
final class AdminSettingsService
{
    /**
     * The writable surface, grouped as the Settings screen presents it.
     *
     * `billing.*` and `theme.*` exist in the options model but are not here:
     * payments arrive in Phase 3 and theming in Phase 4, and a settings form
     * that writes keys nothing reads yet is a form that lies about what it does.
     *
     * @var array<string,array<string,string>>
     */
    private const WRITABLE = [
        'general' => [
            'branding.name'     => 'text',
            'routing.app_base'  => 'slug',
        ],
        'email' => [
            'email.from_name'    => 'text',
            'email.from_address' => 'email',
        ],
        'features' => [
            'features.messaging_enabled' => 'bool',
            'features.nutrition_enabled' => 'bool',
            'features.health_enabled'    => 'bool',
            'features.support_enabled'   => 'bool',
            'features.trainer_directory' => 'bool',
            'features.jwt_api_enabled'   => 'bool',
            'features.registration_open' => 'bool',
        ],
    ];

    /**
     * Paths WordPress owns, plus the ones that would shadow the API.
     *
     * A slug that collides here does not error — it silently loses, because
     * which rule wins depends on rewrite order. An administrator should not
     * discover that by clicking.
     */
    private const RESERVED = [
        'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json',
        'admin', 'login', 'feed', 'rss', 'sitemap', 'robots', 'xmlrpc',
    ];

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $options = FitnessClub()->options;
        $out     = [];

        foreach (self::WRITABLE as $group => $keys) {
            foreach ($keys as $path => $type) {
                $value = $options->get($path);

                $out[$group][$this->leaf($path)] = 'bool' === $type
                    ? (bool) $value
                    : (null === $value ? '' : $value);
            }
        }

        // Read-only context the screen needs to render the App URL field and its
        // live preview without guessing at the site's own URL.
        $out['context'] = [
            'site_url' => home_url('/'),
            'app_url'  => AppRouter::url(),
            'reserved' => self::RESERVED,
        ];

        return $out;
    }

    /**
     * Write the settings the payload actually contains.
     *
     * @param array<string,mixed> $payload Groups → key → value.
     * @return array<string,mixed> The settings as they now stand.
     */
    public function update(array $payload): array
    {
        $options    = FitnessClub()->options;
        $oldBase    = AppRouter::base();
        $baseMoved  = false;

        foreach (self::WRITABLE as $group => $keys) {
            if (!isset($payload[$group]) || !is_array($payload[$group])) {
                continue;
            }

            foreach ($keys as $path => $type) {
                $leaf = $this->leaf($path);

                if (!array_key_exists($leaf, $payload[$group])) {
                    continue;
                }

                $value = $this->coerce($type, $payload[$group][$leaf], $path);

                if ('routing.app_base' === $path && $value !== $oldBase) {
                    $baseMoved = true;
                }

                $options->set($path, $value);
            }
        }

        if ($baseMoved) {
            // Same mechanism the wp-admin launcher uses: set the flag, let the
            // next request flush. Flushing inline from a REST call rebuilds the
            // rules against the *old* option value on some setups.
            update_option(RewriteServiceProvider::FLUSH_OPTION, true);
        }

        $result = $this->all();
        $result['rewrites_flushed'] = $baseMoved;

        return $result;
    }

    private function coerce(string $type, $value, string $path)
    {
        switch ($type) {
            case 'bool':
                return (bool) $value;

            case 'email':
                $email = sanitize_email((string) $value);

                if ('' !== (string) $value && '' === $email) {
                    throw new DomainException(
                        'fc_invalid_email',
                        __('That is not a valid email address.', 'fitnessclub'),
                        400
                    );
                }

                return $email;

            case 'slug':
                return $this->slug($value);

            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Validate the App URL slug.
     *
     * Three ways it can be wrong, and each one takes the front-end down in a way
     * the administrator would otherwise have to diagnose from a 404.
     */
    private function slug($value): string
    {
        $slug = sanitize_title((string) $value);

        if ('' === $slug) {
            throw new DomainException(
                'fc_app_base_empty',
                __('The app URL needs a slug — the front-end cannot live at the site root.', 'fitnessclub'),
                400
            );
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw new DomainException(
                'fc_app_base_reserved',
                sprintf(
                    /* translators: %s: the slug */
                    __('"%s" is reserved by WordPress. Pick another slug.', 'fitnessclub'),
                    $slug
                ),
                400
            );
        }

        // An existing page at the same path wins or loses depending on rewrite
        // order — undefined behaviour dressed as a working setting.
        $page = get_page_by_path($slug);

        if ($page instanceof \WP_Post) {
            throw new DomainException(
                'fc_app_base_taken',
                sprintf(
                    /* translators: %s: the slug */
                    __('A page already lives at "%s". Pick another slug or move the page.', 'fitnessclub'),
                    $slug
                ),
                409
            );
        }

        return $slug;
    }

    private function leaf(string $path): string
    {
        $parts = explode('.', $path);

        return end($parts);
    }
}
