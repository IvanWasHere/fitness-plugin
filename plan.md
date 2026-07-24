WorkoutNow Plugin Specifications
Built with wpBones Framework
1. PLUGIN OVERVIEW
1.1 General Description
WorkoutNow is a comprehensive fitness management WordPress plugin that enables users to track workouts, nutrition, health metrics, and interact with trainers. The plugin utilizes a custom database structure (no Custom Post Types) with React-based frontends for three distinct user roles: Users, Trainers, and Administrators.

1.2 Core Architecture
Backend: PHP API endpoints using wpBones Framework

Frontend: Three React applications (User Dashboard, Trainer Dashboard, Admin Dashboard)

Database: Custom tables (no CPTs)

Theming: JSON-based theme configuration system

Authentication: WordPress user system with custom roles

2. DATABASE STRUCTURE
2.1 Users Table
sql
CREATE TABLE wn_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    wp_user_id INT UNIQUE,
    display_name VARCHAR(100),
    weight_kg DECIMAL(5,2),
    height_cm DECIMAL(5,2),
    date_of_birth DATE,
    gender ENUM('male','female','other'),
    default_weight_kg DECIMAL(5,2),
    fitness_level ENUM('beginner','intermediate','advanced','elite'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
2.2 Trainers Table
sql
CREATE TABLE wn_trainers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    wp_user_id INT UNIQUE,
    display_name VARCHAR(100),
    bio TEXT,
    specialization VARCHAR(255),
    hourly_rate DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
2.3 User-Trainer Assignments
sql
CREATE TABLE wn_user_trainers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    trainer_id INT,
    assigned_date DATE,
    status ENUM('active','inactive','pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.4 Plans Table
sql
CREATE TABLE wn_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT,
    plan_name VARCHAR(255),
    description TEXT,
    plan_type ENUM('workout','nutrition','combined') DEFAULT 'combined',
    difficulty ENUM('beginner','intermediate','advanced'),
    weekly_sessions INT,
    duration_weeks INT,
    price_weekly DECIMAL(10,2),
    price_monthly DECIMAL(10,2),
    price_quarterly DECIMAL(10,2),
    price_yearly DECIMAL(10,2),
    features JSON,
    includes_nutrition BOOLEAN DEFAULT FALSE,
    includes_health_stats BOOLEAN DEFAULT TRUE,
    max_messages_per_week INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.5 Plan Features JSON Structure
json
{
    "can_log_workouts": true,
    "can_log_nutrition": true,
    "can_log_health": true,
    "can_view_stats": true,
    "can_messages": true,
    "max_messages_per_week": 10,
    "has_video_workouts": true,
    "has_personalized_plans": false
}
2.6 User Subscriptions
sql
CREATE TABLE wn_user_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    plan_id INT,
    trainer_id INT,
    start_date DATE,
    end_date DATE,
    subscription_type ENUM('weekly','monthly','quarterly','yearly'),
    status ENUM('active','paused','cancelled','expired') DEFAULT 'active',
    auto_renew BOOLEAN DEFAULT TRUE,
    price_paid DECIMAL(10,2),
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES wn_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.7 Workouts Table
sql
CREATE TABLE wn_workouts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT,
    plan_id INT,
    workout_name VARCHAR(255),
    description TEXT,
    workout_type ENUM('strength','cardio','hiit','flexibility','recovery'),
    difficulty ENUM('beginner','intermediate','advanced'),
    estimated_duration_minutes INT,
    calories_burn_estimate INT,
    instructions TEXT,
    video_url VARCHAR(500),
    media_gallery JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES wn_plans(id) ON DELETE CASCADE
);
2.8 Exercises Table
sql
CREATE TABLE wn_exercises (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workout_id INT,
    exercise_name VARCHAR(255),
    exercise_type ENUM('compound','isolation','cardio','flexibility'),
    muscle_groups JSON,
    instructions TEXT,
    video_url VARCHAR(500),
    media_gallery JSON,
    default_sets INT,
    default_reps INT,
    default_weight_kg DECIMAL(5,2),
    order_index INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (workout_id) REFERENCES wn_workouts(id) ON DELETE CASCADE
);
2.9 Workout Logs
sql
CREATE TABLE wn_workout_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    workout_id INT,
    log_date DATE,
    start_time DATETIME,
    end_time DATETIME,
    duration_minutes INT,
    status ENUM('started','paused','completed','stopped') DEFAULT 'started',
    paused_duration_minutes INT DEFAULT 0,
    completion_percentage DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (workout_id) REFERENCES wn_workouts(id) ON DELETE CASCADE
);
2.10 Exercise Logs
sql
CREATE TABLE wn_exercise_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workout_log_id INT,
    exercise_id INT,
    sets INT,
    reps INT,
    weight_kg DECIMAL(5,2),
    actual_sets INT,
    actual_reps INT,
    actual_weight_kg DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (workout_log_id) REFERENCES wn_workout_logs(id) ON DELETE CASCADE,
    FOREIGN KEY (exercise_id) REFERENCES wn_exercises(id) ON DELETE CASCADE
);
2.11 Nutrition Logs
sql
CREATE TABLE wn_nutrition_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    log_date DATE,
    meal_type ENUM('breakfast','lunch','dinner','snack','other'),
    food_items JSON,
    total_calories INT,
    total_protein DECIMAL(5,2),
    total_carbs DECIMAL(5,2),
    total_fat DECIMAL(5,2),
    water_intake_ml INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE
);
2.12 Health Stats
sql
CREATE TABLE wn_health_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    record_date DATE,
    weight_kg DECIMAL(5,2),
    body_fat_percentage DECIMAL(5,2),
    muscle_mass_kg DECIMAL(5,2),
    bmi DECIMAL(5,2),
    systolic_pressure INT,
    diastolic_pressure INT,
    heart_rate_resting INT,
    sleep_hours DECIMAL(3,1),
    stress_level ENUM('low','moderate','high','very_high'),
    energy_level ENUM('low','moderate','high','very_high'),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE
);
2.13 Messages
sql
CREATE TABLE wn_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    trainer_id INT,
    message TEXT,
    direction ENUM('user_to_trainer','trainer_to_user'),
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.14 Support Tickets
sql
CREATE TABLE wn_support_tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    subject VARCHAR(255),
    message TEXT,
    category ENUM('billing','technical','general','other'),
    priority ENUM('low','medium','high','urgent'),
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    assigned_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES wn_trainers(id) ON DELETE SET NULL
);
2.15 Support Ticket Replies
sql
CREATE TABLE wn_ticket_replies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id INT,
    user_id INT,
    trainer_id INT,
    message TEXT,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES wn_support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.16 Payments
sql
CREATE TABLE wn_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    subscription_id INT,
    amount DECIMAL(10,2),
    payment_type ENUM('subscription','one_time','refund'),
    payment_method VARCHAR(50),
    transaction_id VARCHAR(255) UNIQUE,
    status ENUM('pending','completed','failed','refunded') DEFAULT 'pending',
    payment_date TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wn_users(id) ON DELETE CASCADE,
    FOREIGN KEY (subscription_id) REFERENCES wn_user_subscriptions(id) ON DELETE SET NULL
);
2.17 Food Plans
sql
CREATE TABLE wn_food_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT,
    plan_name VARCHAR(255),
    description TEXT,
    daily_calories INT,
    meal_count INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES wn_trainers(id) ON DELETE CASCADE
);
2.18 Food Plan Meals
sql
CREATE TABLE wn_food_plan_meals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    food_plan_id INT,
    meal_name VARCHAR(255),
    meal_type ENUM('breakfast','lunch','dinner','snack','other'),
    description TEXT,
    calories INT,
    protein DECIMAL(5,2),
    carbs DECIMAL(5,2),
    fat DECIMAL(5,2),
    order_index INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (food_plan_id) REFERENCES wn_food_plans(id) ON DELETE CASCADE
);
3. ROLE PERMISSIONS AND CAPABILITIES
3.1 User Roles
php
- administrator: Full WordPress admin + WorkoutNow admin capabilities
- wn_trainer: Manage assigned users, create plans, review progress
- wn_user: Access user dashboard, track workouts, interact with trainers
3.2 Capabilities Matrix
Capability	Administrator	Trainer	User
Manage users	✓	✓ (assigned only)	✗
Create plans	✗	✓	✗
Assign plans	✓	✓ (own plans)	✗
View user progress	✓	✓ (assigned only)	✗
Send messages	✓	✓ (assigned only)	✓ (own trainers)
Log workouts	✗	✗	✓
Log nutrition	✗	✗	✓ (plan dependent)
Log health stats	✗	✗	✓ (plan dependent)
View stats	✓	✓ (assigned)	✓ (own)
Manage payments	✓	✗	✗ (view own)
Support tickets	✓ (all)	✓ (assigned)	✓ (own)
Theme management	✓	✗	✗
API access	✓	✓	✓
4. WP BONES FRAMEWORK IMPLEMENTATION
4.1 Directory Structure
text
workoutNow/
├── app/
│   ├── Admin/
│   │   ├── Controllers/
│   │   ├── Views/
│   │   └── Routes/
│   ├── API/
│   │   ├── v1/
│   │   │   ├── Controllers/
│   │   │   ├── Models/
│   │   │   └── Routes/
│   │   └── Middleware/
│   ├── Models/
│   ├── Services/
│   ├── Database/
│   │   ├── Migrations/
│   │   └── Seeders/
│   └── Core/
├── assets/
│   ├── js/
│   │   ├── admin/
│   │   ├── trainer/
│   │   └── user/
│   ├── css/
│   └── images/
├── react-apps/
│   ├── admin-dashboard/
│   ├── trainer-dashboard/
│   └── user-dashboard/
├── wn_themes/
│   ├── default/
│   │   └── theme.json
│   └── custom/
└── wpBones/
    └── (framework files)
4.2 Database Migrations
All database tables must be created through wpBones migration system.

php
// Example migration
class CreateWorkoutNowTables extends \WPBones\Database\Migration
{
    public function up()
    {
        // Create all tables
    }
    
    public function down()
    {
        // Drop all tables
    }
}
5. THEME SYSTEM (WN_THEMES)
5.1 Theme JSON Structure
json
{
    "theme_name": "default",
    "version": "1.0.0",
    "colors": {
        "primary": "#2E86DE",
        "secondary": "#F39C12",
        "success": "#27AE60",
        "danger": "#E74C3C",
        "warning": "#F1C40F",
        "info": "#3498DB",
        "light": "#ECF0F1",
        "dark": "#2C3E50",
        "background": "#F8F9FA",
        "text": "#2C3E50",
        "card_bg": "#FFFFFF"
    },
    "typography": {
        "font_family": "Roboto, sans-serif",
        "heading_font": "Poppins, sans-serif",
        "base_size": "16px",
        "line_height": "1.6"
    },
    "layout": {
        "container_width": "1200px",
        "sidebar_width": "280px",
        "card_radius": "12px",
        "border_radius": "8px",
        "spacing": "20px"
    },
    "components": {
        "dashboard": {
            "show_workout_progress": true,
            "show_nutrition_summary": true,
            "show_stats_cards": true,
            "show_charts": true,
            "columns": "2"
        },
        "workout_tracker": {
            "layout": "vertical",
            "show_video": true,
            "show_timer": true,
            "show_set_tracker": true
        },
        "navigation": {
            "position": "top",
            "sticky": true,
            "show_icons": true
        }
    }
}
5.2 Theme Loading
php
// Load theme configuration
public function loadTheme()
{
    $selected_theme = get_option('wn_selected_theme', 'default');
    $theme_path = WP_CONTENT_DIR . '/wn_themes/' . $selected_theme . '/theme.json';
    
    if (file_exists($theme_path)) {
        $theme_config = json_decode(file_get_contents($theme_path), true);
        return $theme_config;
    }
    
    // Fallback to default
    return json_decode(file_get_contents(WP_CONTENT_DIR . '/wn_themes/default/theme.json'), true);
}
6. API ENDPOINTS
6.1 Authentication
POST /api/v1/auth/login - User login

POST /api/v1/auth/register - User registration

POST /api/v1/auth/logout - User logout

GET /api/v1/auth/verify - Verify authentication

POST /api/v1/auth/reset-password - Password reset

6.2 User Endpoints
GET /api/v1/user/profile - Get user profile

PUT /api/v1/user/profile - Update user profile

GET /api/v1/user/dashboard - Dashboard data

GET /api/v1/user/stats - User statistics

GET /api/v1/user/subscriptions - User subscriptions

POST /api/v1/user/subscribe - Subscribe to plan

6.3 Workout Endpoints
GET /api/v1/workouts - List workouts

GET /api/v1/workouts/{id} - Get workout details

POST /api/v1/workouts/start - Start workout

PUT /api/v1/workouts/pause - Pause workout

PUT /api/v1/workouts/resume - Resume workout

PUT /api/v1/workouts/stop - Stop workout

POST /api/v1/workouts/complete - Complete workout

GET /api/v1/workouts/logs - Get workout logs

PUT /api/v1/workouts/logs/{id} - Update workout log

6.4 Exercise Endpoints
GET /api/v1/exercises - List exercises

GET /api/v1/exercises/{id} - Get exercise details

PUT /api/v1/exercise-logs/{id} - Update exercise log

6.5 Nutrition Endpoints
GET /api/v1/nutrition - Get nutrition logs

POST /api/v1/nutrition - Log nutrition

PUT /api/v1/nutrition/{id} - Update nutrition log

DELETE /api/v1/nutrition/{id} - Delete nutrition log

6.6 Health Stats Endpoints
GET /api/v1/health-stats - Get health stats

POST /api/v1/health-stats - Log health stats

PUT /api/v1/health-stats/{id} - Update health stats

6.7 Trainer Endpoints
GET /api/v1/trainers - List trainers

GET /api/v1/trainers/{id} - Get trainer details

GET /api/v1/trainers/assigned-users - Get assigned users

GET /api/v1/trainers/user-progress/{user_id} - Get user progress

6.8 Message Endpoints
GET /api/v1/messages - Get messages

POST /api/v1/messages - Send message

PUT /api/v1/messages/{id}/read - Mark message as read

GET /api/v1/messages/unread-count - Get unread count

6.9 Payment Endpoints
GET /api/v1/payments - Get payment history

POST /api/v1/payments/create - Create payment

POST /api/v1/payments/cancel-subscription - Cancel subscription

POST /api/v1/payments/upgrade-subscription - Upgrade subscription

6.10 Support Endpoints
GET /api/v1/support/tickets - Get tickets

POST /api/v1/support/tickets - Create ticket

PUT /api/v1/support/tickets/{id} - Update ticket

POST /api/v1/support/tickets/{id}/reply - Reply to ticket

6.11 Theme Endpoints
GET /api/v1/theme - Get current theme

POST /api/v1/theme - Update theme (Admin only)

7. REACT APPLICATION STRUCTURE
7.1 User Dashboard Components
text
user-dashboard/
├── src/
│   ├── components/
│   │   ├── common/
│   │   │   ├── Header.js
│   │   │   ├── Footer.js
│   │   │   ├── Navigation.js
│   │   │   ├── ThemeProvider.js
│   │   │   └── Loader.js
│   │   ├── dashboard/
│   │   │   ├── Dashboard.js
│   │   │   ├── WorkoutProgress.js
│   │   │   ├── NutritionSummary.js
│   │   │   ├── StatsCards.js
│   │   │   └── RecentActivity.js
│   │   ├── workouts/
│   │   │   ├── WorkoutList.js
│   │   │   ├── WorkoutDetail.js
│   │   │   ├── WorkoutTracker.js
│   │   │   ├── WorkoutTimer.js
│   │   │   └── ExerciseTracker.js
│   │   ├── nutrition/
│   │   │   ├── NutritionLog.js
│   │   │   ├── FoodPlans.js
│   │   │   └── FoodDiary.js
│   │   ├── stats/
│   │   │   ├── StatsOverview.js
│   │   │   ├── ProgressCharts.js
│   │   │   ├── HealthMetrics.js
│   │   │   └── WorkoutAnalytics.js
│   │   ├── messages/
│   │   │   ├── MessageList.js
│   │   │   ├── MessageThread.js
│   │   │   └── MessageComposer.js
│   │   ├── billing/
│   │   │   ├── BillingPage.js
│   │   │   ├── SubscriptionManager.js
│   │   │   ├── PaymentHistory.js
│   │   │   └── PlanSelector.js
│   │   ├── support/
│   │   │   ├── TicketList.js
│   │   │   ├── TicketDetail.js
│   │   │   └── TicketForm.js
│   │   └── profile/
│   │       ├── ProfileSettings.js
│   │       └── AccountSettings.js
│   ├── hooks/
│   │   ├── useAuth.js
│   │   ├── useWorkout.js
│   │   ├── useNutrition.js
│   │   ├── useStats.js
│   │   └── useTheme.js
│   ├── contexts/
│   │   ├── AuthContext.js
│   │   ├── ThemeContext.js
│   │   └── UserContext.js
│   ├── services/
│   │   ├── api.js
│   │   └── auth.js
│   ├── utils/
│   │   ├── themeHelper.js
│   │   └── formatters.js
│   └── App.js
7.2 Trainer Dashboard Components
text
trainer-dashboard/
├── src/
│   ├── components/
│   │   ├── common/
│   │   │   ├── Header.js
│   │   │   ├── Navigation.js
│   │   │   └── ThemeProvider.js
│   │   ├── dashboard/
│   │   │   ├── TrainerDashboard.js
│   │   │   ├── ClientOverview.js
│   │   │   ├── RecentActivity.js
│   │   │   └── PerformanceMetrics.js
│   │   ├── clients/
│   │   │   ├── ClientList.js
│   │   │   ├── ClientDetail.js
│   │   │   ├── ClientProgress.js
│   │   │   └── ClientWorkouts.js
│   │   ├── plans/
│   │   │   ├── PlanList.js
│   │   │   ├── PlanEditor.js
│   │   │   ├── WorkoutBuilder.js
│   │   │   ├── FoodPlanBuilder.js
│   │   │   └── PlanPricing.js
│   │   ├── messages/
│   │   │   ├── MessageList.js
│   │   │   ├── MessageThread.js
│   │   │   └── MessageComposer.js
│   │   ├── support/
│   │   │   ├── TicketList.js
│   │   │   └── TicketDetail.js
│   │   └── profile/
│   │       └── TrainerProfile.js
│   ├── hooks/
│   │   ├── useAuth.js
│   │   ├── useClients.js
│   │   └── usePlans.js
│   ├── contexts/
│   │   └── ThemeContext.js
│   ├── services/
│   │   └── api.js
│   └── App.js
7.3 Admin Dashboard Components
text
admin-dashboard/
├── src/
│   ├── components/
│   │   ├── common/
│   │   │   ├── Header.js
│   │   │   ├── Navigation.js
│   │   │   └── ThemeProvider.js
│   │   ├── dashboard/
│   │   │   ├── AdminDashboard.js
│   │   │   ├── OverviewStats.js
│   │   │   └── RecentActivity.js
│   │   ├── users/
│   │   │   ├── UserList.js
│   │   │   ├── UserDetail.js
│   │   │   └── UserManager.js
│   │   ├── trainers/
│   │   │   ├── TrainerList.js
│   │   │   ├── TrainerDetail.js
│   │   │   └── TrainerManager.js
│   │   ├── support/
│   │   │   ├── TicketList.js
│   │   │   ├── TicketDetail.js
│   │   │   └── TicketManager.js
│   │   ├── themes/
│   │   │   ├── ThemeManager.js
│   │   │   └── ThemeEditor.js
│   │   └── settings/
│   │       ├── GeneralSettings.js
│   │       └── PaymentSettings.js
│   ├── hooks/
│   │   └── useAuth.js
│   ├── contexts/
│   │   └── ThemeContext.js
│   ├── services/
│   │   └── api.js
│   └── App.js
8. WORKOUT TRACKING FEATURES
8.1 Workout Flow
User selects a workout from available plan

Start workout - Timer begins

Follow along:

View exercise instructions

Watch video demonstrations

Track sets, reps, weights

Pause/Resume - Can pause and continue later

Complete/Stop:

Completed: Full workout logged

Stopped: Only elapsed time logged

Paused: State saved for later

8.2 Workout Timer Features
Real-time countdown between exercises

Cooldown period display

Rest timer between sets

Elapsed time tracking

Pause/Resume functionality

8.3 Set Tracking
javascript
// Example exercise tracking state
const exerciseState = {
    exercise_id: 1,
    total_sets: 4,
    current_set: 2,
    reps_per_set: 12,
    weight_kg: 20.5,
    completed: false,
    rest_timer: {
        enabled: true,
        duration: 60,
        remaining: 45
    }
};
8.4 Post-Workout Adjustment
Log completion percentage

Adjust actual reps/sets/weights performed

Add notes

Rate difficulty

Track perceived exertion

9. STATISTICS AND VISUALIZATION
9.1 Dashboard Statistics
Weekly workout frequency

Total calories burned

Weight progress

Strength improvements

Consistency streaks

9.2 Detailed Statistics Page
Workout Analytics

Volume (sets × reps × weight)

Progress by exercise

PR tracking

Workout frequency

Nutrition Analytics

Calorie intake trends

Macro distribution

Water intake patterns

Health Metrics

Weight trends

Body fat percentage

Sleep patterns

Heart rate trends

9.3 Chart Types
Line charts for trends

Bar charts for comparisons

Pie charts for macro distribution

Radar charts for fitness assessment

Calendar heatmaps for consistency

10. PAYMENT SYSTEM
10.1 Subscription Tiers
javascript
const subscriptionTiers = {
    weekly: {
        period: 'week',
        duration_days: 7
    },
    monthly: {
        period: 'month',
        duration_days: 30
    },
    quarterly: {
        period: 'quarter',
        duration_days: 90
    },
    yearly: {
        period: 'year',
        duration_days: 365
    }
};
10.2 Payment Flow
Select Plan - User chooses plan and subscription tier

Payment Processing - Integration with payment gateway

Subscription Created - Record in database

Access Granted - Features enabled based on plan

Billing Management:

View current subscription

Upgrade/Downgrade

Cancel subscription

Payment history

10.3 Payment Gateway Integration
Support for PayPal, Stripe, or similar

Webhook handling for payment confirmations

Automated subscription renewal/expiry

11. MESSAGING SYSTEM
11.1 Message Features
One-to-one messaging between user and trainer

Real-time message delivery

Message read receipts

Message limits based on subscription

File attachment support

11.2 Message Quotas
php
// Check message quota
public function checkMessageQuota($user_id, $trainer_id)
{
    $plan = getUserCurrentPlan($user_id);
    $max_messages = $plan['max_messages_per_week'] ?? 10;
    $messages_sent = getMessagesThisWeek($user_id, $trainer_id);
    
    return $max_messages - $messages_sent;
}
12. FOOD PLAN SYSTEM
12.1 Food Plan Components
Trainer creates food plan:

Daily meals (breakfast, lunch, dinner, snacks)

Nutritional values (calories, macros)

Portion sizes

Meal preparation instructions

User follows food plan:

View daily meals

Log food intake

Compare to plan

Track compliance

12.2 Food Diary
javascript
const foodDiaryEntry = {
    date: '2026-07-22',
    meals: [
        {
            type: 'breakfast',
            foods: [
                { name: 'Oatmeal', quantity: '1 cup', calories: 150 },
                { name: 'Banana', quantity: '1 medium', calories: 105 }
            ],
            total_calories: 255
        }
    ],
    water_intake: 2000 // ml
};
13. ADMIN FEATURES
13.1 User Management
View all users

Create/Edit/Delete users

Reset passwords

View user activity

Manage user subscriptions

13.2 Trainer Management
Create trainer accounts

Assign trainers to users

View trainer performance

Manage trainer permissions

13.3 Theme Management
Upload new themes

Preview themes

Set default theme

Delete themes

Edit theme JSON

13.4 System Settings
General configuration

Email settings

Payment gateway settings

Feature toggles

API settings

14. API AUTHENTICATION
14.1 JWT Implementation
php
// JWT Token generation
public function generateToken($user_id, $role)
{
    $payload = [
        'user_id' => $user_id,
        'role' => $role,
        'exp' => time() + (24 * 60 * 60) // 24 hours
    ];
    
    return JWT::encode($payload, get_option('wn_jwt_secret'));
}
14.2 API Request Headers
text
Authorization: Bearer {jwt_token}
Content-Type: application/json
X-WN-Agent: mobile-app/1.0
14.3 Rate Limiting
100 requests per minute for authenticated users

10 requests per minute for unauthenticated endpoints

15. SECURITY CONSIDERATIONS
15.1 Data Protection
All sensitive data encrypted at rest

HTTPS required for all API calls

JWT tokens with short expiration

Rate limiting on all endpoints

Input validation and sanitization

15.2 User Permissions
Role-based access control

Resource-level permissions

Strict validation on all operations

Audit logging for sensitive actions

15.3 WordPress Security
Nonce verification for all forms

Capability checks on admin pages

SQL injection prevention

XSS protection

16. DEVELOPMENT GUIDELINES
16.1 Coding Standards
Follow WordPress coding standards

Use PSR-4 autoloading

Document all functions and classes

Use meaningful variable names

Comment complex logic

16.2 Testing Requirements
Unit tests for all models

Integration tests for API endpoints

End-to-end testing for React apps

Performance testing for database queries

16.3 Deployment Requirements
WordPress 5.6+ compatible

PHP 7.4+ required

React 18+ for frontends

MySQL 5.7+ or MariaDB 10.2+

17. MOBILE APP CONSIDERATIONS
17.1 API Design
RESTful API endpoints

Proper HTTP status codes

Pagination for large datasets

Compression for large responses

Support for offline sync

17.2 Data Synchronization
Last modified timestamps

Delta sync capability

Conflict resolution strategies

Background sync support

17.3 Mobile-Friendly Features
Push notification support

Biometric authentication

Camera integration for food logging

Location services for workout tracking

18. IMPLEMENTATION PRIORITIES
Phase 1 - Core Foundation (MVP)
Database tables creation

User authentication

Basic API endpoints

User dashboard (React)

Workout tracking

Trainer dashboard basics

Phase 2 - Features Enhancement
Payment system

Messaging system

Statistics and charts

Food plans

Theme system

Phase 3 - Advanced Features
Support tickets

Mobile API optimization

Advanced workout builder

Video integration

Reporting system

Phase 4 - Polish and Optimization
Performance optimization

Caching implementation

Advanced analytics

Custom theme editor

Import/Export functionality

19. TESTING REQUIREMENTS
19.1 Functional Testing
User registration/login

Workout creation and tracking

Payment processing

Message delivery

Data synchronization

19.2 Performance Testing
Page load times (< 2 seconds)

API response times (< 200ms)

Database query optimization

Concurrent user handling

19.3 Security Testing
Vulnerability scanning

Penetration testing

Data encryption verification

Access control validation

20. DOCUMENTATION REQUIREMENTS
20.1 Technical Documentation
Database schema diagrams

API documentation (Swagger/OpenAPI)

Code documentation (PHPDoc/JSDoc)

Deployment guide

Configuration guide

20.2 User Documentation
User manual

Trainer guide

Administrator guide

FAQ section

Video tutorials

20.3 Developer Documentation
Architecture overview

Development environment setup

Extension guidelines

API integration examples

Theme development guide

21. SUPPORT AND MAINTENANCE
21.1 Support Plan
Email support for issues

Bug reporting system

Feature request tracking

Regular updates and patches

21.2 Maintenance Schedule
Weekly: Security updates

Monthly: Feature updates

Quarterly: Major releases

As needed: Emergency patches

This comprehensive specification provides a complete blueprint for developing the WorkoutNow plugin using the wpBones framework. All components, from database structure to frontend implementation, are detailed to ensure smooth development and deployment.

