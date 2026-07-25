<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves which of the three SPAs (D10) the backend serves at the configured
 * URL (D9), from the logged-in user's role.
 *
 * "The appropriate one is shown when they log in." Precedence for the rare
 * multi-role account is admin > trainer > user (Q17c). Unauthenticated visitors
 * get the user SPA, which shows the login screen.
 */
final class AppRouter
{
    public const SPA_USER    = 'user';
    public const SPA_TRAINER = 'trainer';
    public const SPA_ADMIN   = 'admin';

    public const ROLE_ADMIN   = 'administrator';
    public const ROLE_TRAINER = 'fc_trainer';
    public const ROLE_USER    = 'fc_user';

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
        if (!is_user_logged_in()) {
            return self::ROLE_USER;
        }

        // Admin wins — managing the platform outranks coaching or training.
        if (current_user_can('manage_options')) {
            return self::ROLE_ADMIN;
        }

        $user = wp_get_current_user();
        if (in_array(self::ROLE_TRAINER, (array) $user->roles, true) || current_user_can('fc_access_trainer_app')) {
            return self::ROLE_TRAINER;
        }

        return self::ROLE_USER;
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
