<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Role → capability map. Replaces the WordPress roles that RoleProvider used to
 * install.
 *
 * **The capability slugs are unchanged** (`fc_access_app`, `fc_log_workouts`,
 * `fc_manage_clients`, …), so every permission callback keeps the string it
 * already had; only the authority answering the question moved. That was worth
 * preserving deliberately — a rename here would have touched every controller
 * for no benefit and made the diff impossible to review.
 *
 * Capability alone is never authorisation: every trainer and member endpoint
 * also runs a resource-ownership check (see Support\Guard). This answers "may
 * an account of this kind do this at all", never "may it do this to that row".
 *
 * ## Why the admin role does not get `fc_access_app`
 *
 * It did not have it under WordPress either, and the behaviour is worth keeping
 * rather than quietly acquiring: an administrator has no `fc_users` row, so a
 * member endpoint would resolve to no profile anyway. Refusing at the
 * capability gate produces `fc_forbidden`, which is a truthful answer, instead
 * of letting them through to the `fc_no_member_profile` fallback.
 */
final class Capabilities
{
    public const ROLE_USER    = 'user';
    public const ROLE_TRAINER = 'trainer';
    public const ROLE_ADMIN   = 'admin';

    public const ROLES = [self::ROLE_USER, self::ROLE_TRAINER, self::ROLE_ADMIN];

    /** What a member may do. */
    public const USER_CAPS = [
        'fc_access_app',
        'fc_log_workouts',
        'fc_log_nutrition',
        'fc_log_health',
        'fc_view_own_stats',
        'fc_message_trainer',
        'fc_manage_own_subscription',
        'fc_create_tickets',
    ];

    /** What a trainer may do. */
    public const TRAINER_CAPS = [
        'fc_access_trainer_app',
        'fc_manage_clients',
        'fc_create_plans',
        'fc_create_workouts',
        'fc_create_food_plans',
        'fc_assign_plans',
        'fc_view_client_stats',
        'fc_message_clients',
        'fc_handle_tickets',
    ];

    /**
     * What an administrator may do.
     *
     * `fc_edit_user_health` is granted by default: administrators may edit
     * members' health and nutrition records (Q10). Every such edit is
     * audit-logged with before/after values — see
     * plans/09-gap-register.md#43-q10.
     */
    public const ADMIN_CAPS = [
        'fc_manage_all',
        'fc_manage_trainers',
        'fc_manage_users',
        'fc_manage_payments',
        'fc_manage_themes',
        'fc_manage_settings',
        'fc_view_all_stats',
        'fc_edit_user_health',
        'fc_impersonate',
        'fc_access_trainer_app',
        'fc_manage_clients',
        'fc_handle_tickets',
    ];

    /**
     * The capabilities a role grants.
     *
     * @return string[]
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            self::ROLE_ADMIN   => self::ADMIN_CAPS,
            self::ROLE_TRAINER => self::TRAINER_CAPS,
            self::ROLE_USER    => self::USER_CAPS,
            default            => [],
        };
    }

    /**
     * Does this role — plus any per-account grants — allow the capability?
     *
     * `$extra` exists so a one-off grant (a support person who needs
     * `fc_impersonate` for a week) does not require inventing a role. It mirrors
     * what WordPress does with per-user capabilities.
     *
     * @param string[] $extra Per-account capability slugs.
     */
    public static function grants(string $role, array $extra, string $capability): bool
    {
        if (in_array($capability, self::forRole($role), true)) {
            return true;
        }

        return in_array($capability, $extra, true);
    }

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }
}
