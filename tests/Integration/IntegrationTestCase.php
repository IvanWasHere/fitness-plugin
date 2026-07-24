<?php

namespace FitnessClub\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base for integration tests against the real WordPress runtime.
 *
 * Provides throwaway-user creation and cleans up everything it created, so the
 * development database is left untouched.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var int[] */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        // wp_delete_user() lives in the admin includes, not loaded on a front-end
        // request context.
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        foreach ($this->createdUsers as $id) {
            wp_delete_user($id);
        }
        $this->createdUsers = [];

        parent::tearDown();
    }

    /**
     * Create a throwaway user with a role and optional extra capabilities,
     * tracked for deletion in tearDown.
     *
     * @param string[] $caps
     */
    protected function makeUser(string $role = 'subscriber', array $caps = []): int
    {
        $id = wp_insert_user([
            'user_login' => 'fc_test_' . wp_generate_password(10, false),
            'user_pass'  => wp_generate_password(),
            'user_email' => uniqid('fc_test_', true) . '@example.test',
            'role'       => $role,
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $id, 'Test user should be created.');
        $id = (int) $id;
        $this->createdUsers[] = $id;

        if ($caps) {
            $user = get_user_by('id', $id);
            foreach ($caps as $cap) {
                $user->add_cap($cap);
            }
        }

        return $id;
    }
}
