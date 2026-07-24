<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\AppRouter;

/**
 * Role → SPA resolution (D9/D10). Precedence admin > trainer > user (Q17c).
 *
 * The `fc_trainer` role arrives with RoleProvider in W1.2; until then a "trainer"
 * is any account with the `fc_access_trainer_app` capability, which is exactly what
 * AppRouter checks.
 *
 * @covers \FitnessClub\Support\AppRouter
 */
final class AppRouterTest extends IntegrationTestCase
{
    public function testLoggedOutGetsUserSpa(): void
    {
        wp_set_current_user(0);
        $this->assertSame(AppRouter::SPA_USER, AppRouter::currentSpa());
    }

    public function testAdministratorGetsAdminSpa(): void
    {
        wp_set_current_user($this->makeUser('administrator'));
        $this->assertSame(AppRouter::SPA_ADMIN, AppRouter::currentSpa());
    }

    public function testTrainerCapabilityGetsTrainerSpa(): void
    {
        wp_set_current_user($this->makeUser('subscriber', ['fc_access_trainer_app']));
        $this->assertSame(AppRouter::SPA_TRAINER, AppRouter::currentSpa());
    }

    public function testPlainSubscriberGetsUserSpa(): void
    {
        wp_set_current_user($this->makeUser('subscriber'));
        $this->assertSame(AppRouter::SPA_USER, AppRouter::currentSpa());
    }

    public function testAdminOutranksTrainer(): void
    {
        wp_set_current_user($this->makeUser('administrator', ['fc_access_trainer_app']));
        $this->assertSame(
            AppRouter::SPA_ADMIN,
            AppRouter::currentSpa(),
            'A multi-role account resolves to the highest-precedence SPA.'
        );
    }
}
