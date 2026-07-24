<?php

namespace FitnessClub\Database\Seeders;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The prototypes' seed data, transcribed verbatim.
 *
 * Sources: user-app/index.html (SEED_DATA) and admin-app/index.html (SEED).
 * Kept byte-faithful on purpose — it lets a ported screen be diffed against the
 * prototype it came from, and it means every chart has realistic data on day one.
 *
 * `metric` is added where the prototype encoded the unit in a free-text note
 * ("Plank Hold — 3 sets x 60 reps (seconds)", "Jump Rope — reps = jumps"), which
 * is exactly the ambiguity fc_exercises.metric exists to remove.
 */
final class DemoData
{
    /** @return array<int,array<string,mixed>> */
    public static function trainers(): array
    {
        return [
            ['login' => 'sarah.chen', 'email' => 'sarah@fitforge.test', 'name' => 'Sarah Chen',
             'specialization' => 'Strength & Conditioning', 'phone' => '+1 555-0101',
             'rating' => 4.9, 'rating_count' => 132, 'joined' => '2023-06-15',
             'bio' => 'Powerlifting background. Ten years coaching barbell technique and progressive overload.'],
            ['login' => 'mike.torres', 'email' => 'mike@fitforge.test', 'name' => 'Mike Torres',
             'specialization' => 'Mobility & Recovery', 'phone' => '+1 555-0102',
             'rating' => 4.7, 'rating_count' => 88, 'joined' => '2023-09-01',
             'bio' => 'Movement and mobility specialist. Works with desk-bound clients on hip and thoracic function.'],
            ['login' => 'lisa.park', 'email' => 'lisa@fitforge.test', 'name' => 'Lisa Park',
             'specialization' => 'HIIT & Cardio', 'phone' => '+1 555-0103',
             'rating' => 4.8, 'rating_count' => 154, 'joined' => '2023-03-20',
             'bio' => 'Conditioning coach. Interval work, engine building and fat loss.'],
            ['login' => 'david.kim', 'email' => 'david@fitforge.test', 'name' => 'David Kim',
             'specialization' => 'Nutrition & Weight Loss', 'phone' => '+1 555-0104',
             'rating' => 4.6, 'rating_count' => 61, 'joined' => '2024-01-10',
             'bio' => 'Registered nutritionist. Sustainable deficits, not crash diets.'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function users(): array
    {
        return [
            ['login' => 'alex.morgan', 'email' => 'alex@fitforge.test', 'name' => 'Alex Morgan',
             'plan' => 'pro', 'status' => 'Active', 'joined' => '2024-01-15', 'weight' => 78.5,
             'phone' => '+1 (555) 234-5678', 'gender' => 'male', 'height' => 175.0,
             'target_weight' => 75.0, 'body_fat' => 18.2, 'goal' => 'Build Muscle',
             'activity' => 'moderate', 'level' => 'intermediate', 'dob' => '1998-04-12'],
            ['login' => 'jamie.lee', 'email' => 'jamie@fitforge.test', 'name' => 'Jamie Lee',
             'plan' => 'free', 'status' => 'Active', 'joined' => '2024-06-20', 'weight' => 65.2,
             'phone' => null, 'gender' => 'female', 'height' => 166.0,
             'target_weight' => 62.0, 'body_fat' => 24.0, 'goal' => 'Lose Weight',
             'activity' => 'light', 'level' => 'beginner', 'dob' => '1995-11-02'],
            ['login' => 'sam.wilson', 'email' => 'sam@fitforge.test', 'name' => 'Sam Wilson',
             'plan' => 'pro', 'status' => 'Active', 'joined' => '2024-03-10', 'weight' => 88.1,
             'phone' => null, 'gender' => 'male', 'height' => 183.0,
             'target_weight' => 85.0, 'body_fat' => 20.5, 'goal' => 'Build Muscle',
             'activity' => 'active', 'level' => 'advanced', 'dob' => '1991-07-19'],
            ['login' => 'taylor.brooks', 'email' => 'taylor@fitforge.test', 'name' => 'Taylor Brooks',
             'plan' => 'team', 'status' => 'Active', 'joined' => '2024-08-05', 'weight' => 72.0,
             'phone' => null, 'gender' => 'other', 'height' => 172.0,
             'target_weight' => 70.0, 'body_fat' => 21.0, 'goal' => 'Stay Fit',
             'activity' => 'moderate', 'level' => 'intermediate', 'dob' => '1993-02-28'],
            ['login' => 'jordan.riley', 'email' => 'jordan@fitforge.test', 'name' => 'Jordan Riley',
             'plan' => 'pro', 'status' => 'Suspended', 'joined' => '2024-04-18', 'weight' => 91.3,
             'phone' => null, 'gender' => 'male', 'height' => 188.0,
             'target_weight' => 86.0, 'body_fat' => 23.4, 'goal' => 'Lose Weight',
             'activity' => 'sedentary', 'level' => 'beginner', 'dob' => '1988-09-30'],
            ['login' => 'casey.nguyen', 'email' => 'casey@fitforge.test', 'name' => 'Casey Nguyen',
             'plan' => 'free', 'status' => 'Inactive', 'joined' => '2024-09-12', 'weight' => 58.7,
             'phone' => null, 'gender' => 'female', 'height' => 160.0,
             'target_weight' => 57.0, 'body_fat' => 22.8, 'goal' => 'Improve Endurance',
             'activity' => 'light', 'level' => 'beginner', 'dob' => '2000-01-05'],
        ];
    }

    /**
     * Six workouts, 44 exercises. Progress percentages drive the workout cards.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function workouts(): array
    {
        return [
            [
                'slug' => 'upper-body-power', 'name' => 'Upper Body Power',
                'type' => 'strength', 'difficulty' => 'intermediate', 'duration' => 45,
                'calories' => 380, 'trainer' => 'sarah.chen', 'progress' => 65.0,
                'muscles' => ['Chest', 'Shoulders', 'Triceps'],
                'equipment' => ['Barbell', 'Dumbbells', 'Cables', 'Bench'],
                'description' => 'A comprehensive upper body session focusing on chest, shoulders, and triceps with compound and isolation movements.',
                'exercises' => [
                    ['Barbell Bench Press', 'compound', 4, 8, 80, 90, 'Keep feet flat on floor'],
                    ['Incline Dumbbell Press', 'compound', 3, 10, 30, 75, '30 degree incline'],
                    ['Overhead Press', 'compound', 4, 8, 50, 90, 'Brace core'],
                    ['Lateral Raises', 'isolation', 3, 15, 12, 60, 'Slight forward lean'],
                    ['Cable Flyes', 'isolation', 3, 12, 20, 60, 'Squeeze at peak'],
                    ['Tricep Dips', 'compound', 3, 12, 0, 60, 'Body weight'],
                    ['Skull Crushers', 'isolation', 3, 10, 25, 60, 'Keep elbows fixed'],
                    ['Face Pulls', 'isolation', 3, 15, 15, 45, 'Pull to nose level'],
                ],
            ],
            [
                'slug' => 'leg-day-destroyer', 'name' => 'Leg Day Destroyer',
                'type' => 'strength', 'difficulty' => 'advanced', 'duration' => 55,
                'calories' => 470, 'trainer' => 'sarah.chen', 'progress' => 40.0,
                'muscles' => ['Quads', 'Hamstrings', 'Glutes', 'Calves'],
                'equipment' => ['Barbell', 'Leg Press Machine', 'Curls Machine'],
                'description' => 'An intense lower body workout designed to build strength and size in legs and glutes.',
                'exercises' => [
                    ['Barbell Squats', 'compound', 5, 5, 100, 120, 'Below parallel'],
                    ['Romanian Deadlifts', 'compound', 4, 8, 80, 90, 'Feel hamstring stretch'],
                    ['Leg Press', 'compound', 4, 12, 180, 90, 'Full range of motion'],
                    ['Walking Lunges', 'compound', 3, 12, 20, 75, 'Each leg'],
                    ['Leg Curls', 'isolation', 3, 12, 40, 60, 'Control the negative'],
                    ['Calf Raises', 'isolation', 4, 15, 60, 45, 'Full stretch at bottom'],
                    ['Hip Thrusts', 'compound', 4, 10, 80, 75, 'Squeeze glutes at top'],
                ],
            ],
            [
                'slug' => 'core-cardio-blast', 'name' => 'Core & Cardio Blast',
                'type' => 'cardio', 'difficulty' => 'beginner', 'duration' => 30,
                'calories' => 260, 'trainer' => 'lisa.park', 'progress' => 80.0,
                'muscles' => ['Core', 'Cardio'],
                'equipment' => ['Medicine Ball', 'Jump Rope'],
                'description' => 'A high-energy core and cardio circuit to burn calories and build a strong midsection.',
                'exercises' => [
                    ['Plank Hold', 'isolation', 3, 60, 0, 30, 'Keep body straight', 'seconds'],
                    ['Mountain Climbers', 'cardio', 3, 30, 0, 30, 'Each side, fast pace'],
                    ['Bicycle Crunches', 'isolation', 3, 20, 0, 30, 'Each side'],
                    ['Burpees', 'cardio', 3, 10, 0, 45, 'Explosive movement'],
                    ['Russian Twists', 'isolation', 3, 20, 8, 30, 'Each side, with medicine ball'],
                    ['Jump Rope', 'cardio', 3, 100, 0, 30, 'Reps = jumps'],
                ],
            ],
            [
                'slug' => 'pull-day-strength', 'name' => 'Pull Day Strength',
                'type' => 'strength', 'difficulty' => 'intermediate', 'duration' => 50,
                'calories' => 420, 'trainer' => 'sarah.chen', 'progress' => 20.0,
                'muscles' => ['Back', 'Biceps', 'Rear Delts'],
                'equipment' => ['Barbell', 'Pull-up Bar', 'Cable Machine', 'Dumbbells'],
                'description' => 'Focused pull day targeting back, biceps, and rear delts for balanced upper body development.',
                'exercises' => [
                    ['Deadlifts', 'compound', 5, 5, 120, 120, 'Conventional stance'],
                    ['Pull-ups', 'compound', 4, 8, 0, 90, 'Full dead hang'],
                    ['Barbell Rows', 'compound', 4, 8, 70, 90, 'Pull to lower chest'],
                    ['Lat Pulldowns', 'compound', 3, 12, 55, 75, 'Wide grip'],
                    ['Barbell Curls', 'isolation', 3, 10, 30, 60, 'Control the negative'],
                    ['Hammer Curls', 'isolation', 3, 12, 14, 60, 'Neutral grip'],
                    ['Rear Delt Flyes', 'isolation', 3, 15, 8, 45, 'Face down on incline'],
                ],
            ],
            [
                'slug' => 'hiit-express', 'name' => 'HIIT Express',
                'type' => 'hiit', 'difficulty' => 'advanced', 'duration' => 25,
                'calories' => 330, 'trainer' => 'lisa.park', 'progress' => 55.0,
                'muscles' => ['Full Body', 'Cardio'],
                'equipment' => ['Box', 'Kettlebell', 'Battle Ropes', 'Dumbbells'],
                'description' => 'High intensity interval training for maximum calorie burn in minimum time.',
                'exercises' => [
                    ['Box Jumps', 'cardio', 4, 12, 0, 20, '24 inch box'],
                    ['Kettlebell Swings', 'compound', 4, 15, 20, 20, 'Hip hinge pattern'],
                    ['Battle Ropes', 'cardio', 4, 30, 0, 20, 'Alternating', 'seconds'],
                    ['Thrusters', 'compound', 4, 10, 30, 20, 'Squat to press'],
                    ['Sprint Intervals', 'cardio', 6, 30, 0, 30, 'All out effort', 'seconds'],
                    ['Push-up to Renegade Row', 'compound', 3, 8, 16, 20, 'Each arm'],
                    ['Box Step-ups', 'compound', 3, 12, 16, 20, 'Each leg'],
                    ['Plank to Push-up', 'compound', 3, 10, 0, 20, 'Alternate lead arm'],
                ],
            ],
            [
                'slug' => 'mobility-recovery', 'name' => 'Mobility & Recovery',
                'type' => 'recovery', 'difficulty' => 'beginner', 'duration' => 20,
                'calories' => 90, 'trainer' => 'mike.torres', 'progress' => 90.0,
                'muscles' => ['Full Body', 'Flexibility'],
                'equipment' => ['Foam Roller', 'Broomstick'],
                'description' => 'Active recovery session focusing on mobility, flexibility, and breathing techniques.',
                'exercises' => [
                    ['Cat-Cow Stretch', 'flexibility', 2, 10, 0, 0, 'Slow controlled movement'],
                    ["World's Greatest Stretch", 'flexibility', 2, 8, 0, 0, 'Each side'],
                    ['Foam Roll Quads', 'flexibility', 1, 60, 0, 0, 'Each leg', 'seconds'],
                    ['90/90 Hip Stretch', 'flexibility', 2, 30, 0, 0, 'Each side', 'seconds'],
                    ['Thoracic Rotation', 'flexibility', 2, 10, 0, 0, 'Each side'],
                    ['Shoulder Dislocates', 'flexibility', 2, 10, 0, 0, 'Use broomstick'],
                    ['Pigeon Pose', 'flexibility', 2, 45, 0, 0, 'Each side', 'seconds'],
                    ['Diaphragmatic Breathing', 'flexibility', 3, 10, 0, 0, '5 second inhale, 5 second exhale'],
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function foods(): array
    {
        return [
            ['Chicken Breast (100g)', 'protein', 165, 31.0, 0.0, 3.6],
            ['Brown Rice (1 cup)', 'grains', 216, 5.0, 45.0, 1.8],
            ['Salmon Fillet (150g)', 'protein', 312, 34.0, 0.0, 18.0],
            ['Sweet Potato (medium)', 'vegetables', 103, 2.0, 24.0, 0.1],
            ['Greek Yogurt (170g)', 'dairy', 100, 17.0, 6.0, 0.7],
            ['Banana', 'fruit', 105, 1.3, 27.0, 0.4],
            ['Almonds (28g)', 'nuts', 164, 6.0, 6.0, 14.0],
            ['Oatmeal (1 cup)', 'grains', 154, 5.0, 27.0, 2.6],
            ['Egg (large)', 'protein', 72, 6.0, 0.4, 5.0],
            ['Avocado (half)', 'fruit', 161, 2.0, 9.0, 15.0],
            ['Broccoli (1 cup)', 'vegetables', 55, 3.7, 11.0, 0.6],
            ['Whey Protein Shake', 'supplements', 120, 24.0, 3.0, 1.0],
        ];
    }

    /**
     * Alex's meals for "today" — 1650 kcal against a 2400 goal, matching the
     * prototype's nutrition screen exactly.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function meals(): array
    {
        return [
            ['breakfast', '07:30:00', 420, 28.0, 52.0, 11.0,
             [['Oatmeal (1 cup)', 1], ['Egg (large)', 2], ['Banana', 1]]],
            ['lunch', '12:30:00', 580, 46.0, 62.0, 14.0,
             [['Chicken Breast (100g)', 1.5], ['Brown Rice (1 cup)', 1], ['Broccoli (1 cup)', 1]]],
            ['dinner', '19:00:00', 520, 38.0, 44.0, 18.0,
             [['Salmon Fillet (150g)', 1], ['Sweet Potato (medium)', 1], ['Broccoli (1 cup)', 1]]],
            ['snack', '16:00:00', 130, 8.0, 22.0, 2.0,
             [['Whey Protein Shake', 1], ['Almonds (28g)', 0.5]]],
        ];
    }

    /**
     * Weight series from the prototype's health.weight, most recent last.
     *
     * @return array<int,array{0:int,1:float}>  [days ago, kg]
     */
    public static function weightSeries(): array
    {
        return [[35, 80.2], [28, 79.8], [21, 79.5], [14, 79.1], [7, 78.8], [0, 78.5]];
    }

    /** @return array<int,array{0:int,1:float}> */
    public static function bodyFatSeries(): array
    {
        return [[35, 19.5], [21, 19.0], [7, 18.5], [0, 18.2]];
    }

    /**
     * Strength progression driving the Progress screen's multi-line chart.
     *
     * @return array<string,array<int,float>>
     */
    public static function strengthSeries(): array
    {
        return [
            'Barbell Bench Press' => [70.0, 72.5, 75.0, 77.5, 80.0],
            'Barbell Squats'      => [90.0, 92.5, 95.0, 97.5, 100.0],
            'Deadlifts'           => [105.0, 110.0, 112.5, 115.0, 120.0],
        ];
    }

    /**
     * Body measurements for the radar chart, three monthly snapshots.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function measurements(): array
    {
        return [
            ['days' => 90, 'chest' => 100.0, 'waist' => 86.0, 'arms' => 35.0, 'thighs' => 57.0, 'shoulders' => 47.0],
            ['days' => 60, 'chest' => 101.0, 'waist' => 85.0, 'arms' => 35.5, 'thighs' => 57.5, 'shoulders' => 47.5],
            ['days' => 30, 'chest' => 102.0, 'waist' => 84.0, 'arms' => 36.0, 'thighs' => 58.0, 'shoulders' => 48.0],
        ];
    }

    /**
     * The two trainer conversations from the prototype.
     *
     * @return array<string,array<int,array{0:string,1:string,2:int}>>  login => [direction, text, days ago]
     */
    public static function conversations(): array
    {
        return [
            'sarah.chen' => [
                ['trainer_to_user', 'Great job on the upper body session today! Your bench press form looked much better.', 0],
                ['user_to_trainer', 'Thanks! I felt much more stable. The cue about keeping my feet flat really helped.', 0],
                ['trainer_to_user', "That's what I like to hear. For next week, I'm increasing your squat volume. Ready to push it?", 0],
                ['trainer_to_user', "Also, make sure you're hitting your protein target. 180g is crucial for recovery at this level.", 0],
            ],
            'mike.torres' => [
                ['trainer_to_user', 'Your mobility session feedback was positive. Keep doing the hip stretches daily.', 1],
                ['user_to_trainer', 'Will do! My hip flexibility has already improved noticeably.', 1],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function notifications(): array
    {
        return [
            ['workout', 'Workout Reminder', 'Time for your Upper Body Power session!', 'fa-dumbbell', 'accent', 0, 0],
            ['message', 'New Message from Sarah', 'Great job on the upper body session today!', 'fa-comment', 'info', 0, 0],
            ['achievement', 'Streak Milestone', "You've completed a 5-day workout streak!", 'fa-fire', 'accent2', 0, 1],
            ['subscription', 'Subscription Renewal', 'Your Pro plan renews in 7 days.', 'fa-crown', 'purple', 1, 2],
            ['system', 'App Update', 'New features: barcode scanning and improved charts.', 'fa-circle-info', 'muted', 1, 3],
            ['progress', 'Weight Goal Progress', "You're 85% towards your target weight!", 'fa-chart-line', 'accent', 1, 4],
        ];
    }
}
