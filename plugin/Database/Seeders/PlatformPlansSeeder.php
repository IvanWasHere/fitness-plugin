<?php

namespace FitnessClub\Database\Seeders;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Essential reference data: the platform subscription tiers.
 *
 * This is NOT demo data — it runs on every activation because the product does
 * not function without it. `max_trainers` here is what gates the trainer
 * directory (Q14): a user must hold an active plan to request a trainer, and the
 * plan says how many they may have.
 *
 * The Free tier is modelled as a real plan with max_trainers = 0 rather than as
 * "no subscription", so the "active plan required" rule needs no special case
 * anywhere in the code. See plans/09-gap-register.md#46-q14.
 */
class PlatformPlansSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plans() as $plan) {
            $features = $plan['features'];
            unset($plan['features']);

            $this->upsert(
                'plans',
                ['slug' => $plan['slug']],
                $plan + [
                    'owner_type' => 'platform',
                    'trainer_id' => null,
                    'features'   => wp_json_encode($features),
                    'updated_at' => $this->now(),
                ]
            );
        }

        $this->note('platform plans: ' . count($this->plans()) . ' upserted (free, pro, team)');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'slug'                  => 'free',
                'plan_name'             => 'Free',
                'description'           => 'Track your own workouts. No coaching.',
                'plan_type'             => 'workout',
                'difficulty'            => 'beginner',
                'currency'              => 'USD',
                'price_weekly'          => 0.00,
                'price_monthly'         => 0.00,
                'price_quarterly'       => 0.00,
                'price_yearly'          => 0.00,
                'includes_nutrition'    => 0,
                'includes_health_stats' => 1,
                'max_messages_per_week' => 0,
                'max_trainers'          => 0,
                'sort_order'            => 10,
                'features'              => [
                    'can_log_workouts'       => true,
                    'can_log_nutrition'      => false,
                    'can_log_health'         => true,
                    'can_view_stats'         => true,
                    'can_message'            => false,
                    'max_messages_per_week'  => 0,
                    'max_trainers'           => 0,
                    'max_active_workouts'    => 3,
                    'has_video_workouts'     => false,
                    'has_personalized_plans' => false,
                    'has_food_plans'         => false,
                ],
            ],
            [
                'slug'                  => 'pro',
                'plan_name'             => 'Pro',
                'description'           => 'Full tracking plus up to two personal trainers.',
                'plan_type'             => 'combined',
                'difficulty'            => 'intermediate',
                'currency'              => 'USD',
                'price_weekly'          => 4.99,
                'price_monthly'         => 14.99,
                'price_quarterly'       => 39.99,
                'price_yearly'          => 149.99,
                'includes_nutrition'    => 1,
                'includes_health_stats' => 1,
                'max_messages_per_week' => 10,
                'max_trainers'          => 2,
                'sort_order'            => 20,
                'features'              => [
                    'can_log_workouts'       => true,
                    'can_log_nutrition'      => true,
                    'can_log_health'         => true,
                    'can_view_stats'         => true,
                    'can_message'            => true,
                    'max_messages_per_week'  => 10,
                    'max_trainers'           => 2,
                    'max_active_workouts'    => null,
                    'has_video_workouts'     => true,
                    'has_personalized_plans' => true,
                    'has_food_plans'         => true,
                ],
            ],
            [
                'slug'                  => 'team',
                'plan_name'             => 'Team',
                'description'           => 'Everything in Pro, with up to three trainers.',
                'plan_type'             => 'combined',
                'difficulty'            => 'advanced',
                'currency'              => 'USD',
                'price_weekly'          => 9.99,
                'price_monthly'         => 29.99,
                'price_quarterly'       => 79.99,
                'price_yearly'          => 299.99,
                'includes_nutrition'    => 1,
                'includes_health_stats' => 1,
                'max_messages_per_week' => 25,
                'max_trainers'          => 3,
                'sort_order'            => 30,
                'features'              => [
                    'can_log_workouts'       => true,
                    'can_log_nutrition'      => true,
                    'can_log_health'         => true,
                    'can_view_stats'         => true,
                    'can_message'            => true,
                    'max_messages_per_week'  => 25,
                    'max_trainers'           => 3,
                    'max_active_workouts'    => null,
                    'has_video_workouts'     => true,
                    'has_personalized_plans' => true,
                    'has_food_plans'         => true,
                ],
            ],
        ];
    }
}
