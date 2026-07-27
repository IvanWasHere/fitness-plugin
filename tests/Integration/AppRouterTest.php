<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Auth\Capabilities;
use FitnessClub\Support\AppRouter;

/**
 * Role → SPA resolution (D9/D10).
 *
 * The role now comes from `fc_accounts.role`, which is single-valued, so the
 * admin > trainer > user precedence (Q17c) is nearly decorative — a WordPress
 * user could hold several roles at once and an account cannot. What the
 * precedence still has to survive is an *administrator who also coaches*, which
 * is a real configuration: an admin account with an fc_trainers row.
 *
 * @covers \FitnessClub\Support\AppRouter
 */
final class AppRouterTest extends IntegrationTestCase
{
    public function testLoggedOutGetsUserSpa(): void
    {
        $this->signOut();

        $this->assertSame(AppRouter::SPA_USER, AppRouter::currentSpa());
    }

    public function testAdminAccountGetsAdminSpa(): void
    {
        $this->signIn($this->makeAccount(Capabilities::ROLE_ADMIN));

        $this->assertSame(AppRouter::SPA_ADMIN, AppRouter::currentSpa());
    }

    public function testTrainerAccountGetsTrainerSpa(): void
    {
        $this->signIn($this->makeAccount(Capabilities::ROLE_TRAINER));

        $this->assertSame(AppRouter::SPA_TRAINER, AppRouter::currentSpa());
    }

    public function testMemberAccountGetsUserSpa(): void
    {
        $this->signIn($this->makeAccount(Capabilities::ROLE_USER));

        $this->assertSame(AppRouter::SPA_USER, AppRouter::currentSpa());
    }

    public function testAnAdminWhoAlsoCoachesStillGetsTheAdminSpa(): void
    {
        global $wpdb;

        $accountId = $this->makeAccount(Capabilities::ROLE_ADMIN);

        // The "admin who coaches" case: one account, admin role, plus a trainer
        // profile. The trainer capabilities come from the admin role's map, so
        // no second role is needed — and the SPA must still be the admin one.
        $wpdb->insert($wpdb->prefix . 'fc_trainers', [
            'account_id'   => $accountId,
            'display_name' => 'Coaching Admin',
            'created_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        $this->signIn($accountId);

        $this->assertSame(AppRouter::SPA_ADMIN, AppRouter::currentSpa());
    }

    /**
     * The requirement the whole identity change exists for, at the routing
     * layer: a WordPress administrator is not a FitnessClub administrator.
     */
    public function testAWordPressAdministratorWithNoAccountGetsTheLoginScreen(): void
    {
        $wpAdmin = wp_insert_user([
            'user_login' => 'fc_router_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(),
            'user_email' => uniqid('fc_router_', true) . '@example.test',
            'role'       => 'administrator',
        ]);

        wp_set_current_user((int) $wpAdmin);
        $this->assertTrue(current_user_can('manage_options'));

        $this->assertSame(
            AppRouter::SPA_USER,
            AppRouter::currentSpa(),
            'manage_options used to resolve straight to the admin SPA. It no longer means anything here.'
        );

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        wp_delete_user((int) $wpAdmin);
    }
}
