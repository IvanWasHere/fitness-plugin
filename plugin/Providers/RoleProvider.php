<?php

namespace FitnessClub\Providers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Roles and capabilities.
 *
 * Installed on activation, removed on UNINSTALL — never on deactivation.
 * Deactivating a plugin (to debug a conflict, say) must not strip roles from live
 * accounts.
 *
 * Capability alone is never authorisation: every trainer/user endpoint also runs a
 * resource-ownership check (see FitnessClub\Support\Guard, plans/03-backend.md).
 */
final class RoleProvider
{
    public const ROLE_USER    = 'fc_user';
    public const ROLE_TRAINER = 'fc_trainer';

    /** Capabilities granted to fc_user. */
    public const USER_CAPS = [
        'read',
        'fc_access_app',
        'fc_log_workouts',
        'fc_log_nutrition',
        'fc_log_health',
        'fc_view_own_stats',
        'fc_message_trainer',
        'fc_manage_own_subscription',
        'fc_create_tickets',
    ];

    /** Capabilities granted to fc_trainer. */
    public const TRAINER_CAPS = [
        'read',
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
     * Capabilities added to the administrator role.
     *
     * fc_edit_user_health is granted by default: administrators may edit users'
     * health and nutrition records (Q10). Every such edit is audit-logged with
     * before/after values — see plans/09-gap-register.md#43-q10.
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
     * Create roles and grant administrator capabilities. Idempotent.
     */
    public static function install(): void
    {
        add_role(self::ROLE_USER, __('FitnessClub Member', 'fitnessclub'), array_fill_keys(self::USER_CAPS, true));
        add_role(self::ROLE_TRAINER, __('FitnessClub Trainer', 'fitnessclub'), array_fill_keys(self::TRAINER_CAPS, true));

        // add_role() is a no-op when the role exists, so re-apply caps explicitly to
        // pick up any added in a plugin update.
        self::syncCaps(self::ROLE_USER, self::USER_CAPS);
        self::syncCaps(self::ROLE_TRAINER, self::TRAINER_CAPS);

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ADMIN_CAPS as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /**
     * Remove roles and capabilities. Call from uninstall.php only.
     */
    public static function uninstall(): void
    {
        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ADMIN_CAPS as $cap) {
                $admin->remove_cap($cap);
            }
        }

        remove_role(self::ROLE_USER);
        remove_role(self::ROLE_TRAINER);
    }

    /**
     * @param string[] $caps
     */
    private static function syncCaps(string $role, array $caps): void
    {
        $object = get_role($role);
        if (!$object) {
            return;
        }

        foreach ($caps as $cap) {
            if (!$object->has_cap($cap)) {
                $object->add_cap($cap);
            }
        }
    }
}
