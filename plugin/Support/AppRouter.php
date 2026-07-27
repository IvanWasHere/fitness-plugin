<?php

namespace FitnessClub\Support;

use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Capabilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves which of the three SPAs (D10) the backend serves at the configured
 * URL (D9), from the signed-in **account's** role.
 *
 * "The appropriate one is shown when they log in." Precedence is admin >
 * trainer > user (Q17c), though it is now nearly decorative: `fc_accounts.role`
 * is single-valued, where a WordPress user could hold several roles at once.
 *
 * Anyone without a plugin account — including a WordPress administrator sitting
 * in wp-admin — gets the user SPA, which shows the login screen.
 */
final class AppRouter
{
    public const SPA_USER    = 'user';
    public const SPA_TRAINER = 'trainer';
    public const SPA_ADMIN   = 'admin';

    // The plugin's own role names, not WordPress role slugs. These travel to the
    // client in the boot payload, so they are the vocabulary the SPAs speak.
    public const ROLE_ADMIN   = Capabilities::ROLE_ADMIN;
    public const ROLE_TRAINER = Capabilities::ROLE_TRAINER;
    public const ROLE_USER    = Capabilities::ROLE_USER;

    /**
     * The SPA for the current user.
     */
    public static function currentSpa(): string
    {
        return self::spaForRole(self::currentRole());
    }

    /**
     * The effective role of the current user, applying the same precedence.
     * Unauthenticated visitors resolve to fc_user — they get the user SPA, which
     * shows the login panel.
     */
    public static function currentRole(): string
    {
        $account = Auth::account();

        // No plugin account, no app — and that includes a WordPress
        // administrator with a live wp-admin session. `manage_options` used to
        // resolve straight to the admin SPA here; it now means nothing, which is
        // the whole point of the separation.
        if (null === $account) {
            return self::ROLE_USER;
        }

        return match ($account->role) {
            // Admin wins — managing the platform outranks coaching or training.
            Capabilities::ROLE_ADMIN   => self::ROLE_ADMIN,
            Capabilities::ROLE_TRAINER => self::ROLE_TRAINER,
            default                    => self::ROLE_USER,
        };
    }

    public static function spaForRole(string $role): string
    {
        return match ($role) {
            self::ROLE_ADMIN   => self::SPA_ADMIN,
            self::ROLE_TRAINER => self::SPA_TRAINER,
            default            => self::SPA_USER,
        };
    }

    /**
     * The configured front-end slug, e.g. "fitness" (D9). Single source of truth
     * for the rewrite rule, the boot payload and every URL the plugin emails out.
     */
    public static function base(): string
    {
        $base = sanitize_title((string) FitnessClub()->options->get('routing.app_base', 'fitness'));

        return '' === $base ? 'fitness' : $base;
    }

    /** The same slug as a root-relative path, e.g. "/fitness". */
    public static function basePath(): string
    {
        return '/' . self::base();
    }

    /** An absolute URL into the app, e.g. https://site/fitness/reset. */
    public static function url(string $path = ''): string
    {
        return home_url(self::basePath() . ('' === $path ? '/' : '/' . ltrim($path, '/')));
    }
}
