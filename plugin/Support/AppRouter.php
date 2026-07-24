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

    /**
     * The SPA for the current user.
     */
    public static function currentSpa(): string
    {
        if (!is_user_logged_in()) {
            return self::SPA_USER;
        }

        // Admin wins — managing the platform outranks coaching or training.
        if (current_user_can('manage_options')) {
            return self::SPA_ADMIN;
        }

        $user = wp_get_current_user();
        if (in_array('fc_trainer', (array) $user->roles, true) || current_user_can('fc_access_trainer_app')) {
            return self::SPA_TRAINER;
        }

        return self::SPA_USER;
    }
}
