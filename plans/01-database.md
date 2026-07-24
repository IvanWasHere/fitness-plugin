# 01 — Database Schema

29 tables, all prefixed `{$wpdb->prefix}fc_`. Reconciles `plan.md` §2 (18 tables)
with what the two prototypes actually read and write.

## Spec → implementation mapping

| `plan.md` | Here | Change |
|-----------|------|--------|
| `wn_users` | `fc_users` | +7 profile columns the user app needs |
| `wn_trainers` | `fc_trainers` | +rating, phone, avatar, status |
| `wn_user_trainers` | `fc_user_trainers` | unchanged |
| `wn_plans` | `fc_plans` | +`owner_type` (platform vs trainer), +currency |
| `wn_user_subscriptions` | `fc_subscriptions` | +currency, +external ids, +cancel_at |
| `wn_workouts` | `fc_workouts` | +equipment JSON |
| `wn_exercises` | `fc_exercises` | **+`default_rest_seconds`** — blocker without it |
| `wn_workout_logs` | `fc_workout_sessions` | renamed; **+resume pointer** |
| `wn_exercise_logs` | `fc_exercise_logs` + `fc_set_logs` | split for per-set granularity |
| `wn_nutrition_logs` | `fc_nutrition_logs` + `fc_nutrition_log_items` | normalised off JSON |
| — | `fc_nutrition_days` | **new** — daily water + goal snapshot |
| — | `fc_foods` | **new** — admin Food Database |
| `wn_health_stats` | `fc_health_stats` | mood/energy numeric, +measurements moved out |
| — | `fc_body_measurements` | **new** — chest/waist/arms/thighs radar chart |
| `wn_messages` | `fc_messages` | +attachments, +thread_id |
| — | `fc_message_threads` | **new** — conversation list needs a parent |
| `wn_support_tickets` | `fc_tickets` | assigned_to → WP user, not trainer |
| `wn_ticket_replies` | `fc_ticket_replies` | author_wp_user_id, single column |
| `wn_payments` | `fc_payments` | +currency, +gateway |
| `wn_food_plans` | `fc_food_plans` | unchanged |
| `wn_food_plan_meals` | `fc_food_plan_meals` | unchanged |
| — | `fc_user_workouts` | **new** — assignment + per-user progress % |
| — | `fc_notifications` | **new** — Notifications screen |
| — | `fc_activity_log` | **new** — "Recent Activity" feed |
| — | `fc_personal_records` | **new** — PR cache for the celebration screen |
| — | `fc_client_notes` | **new** (Q3) — trainer-private notes, scoped per trainer |
| `wn_admins`-equivalent | ✗ **rejected** | admin prototype has an `admins` table; WP users + roles handle this |

## Design rules

1. **`BIGINT(20) UNSIGNED` for every id and FK.** The spec uses `INT`, but WP's
   `wp_users.ID` is `BIGINT(20) UNSIGNED`; `INT` FKs to it break on large installs
   and produce type-mismatch join penalties.
2. **`wp_user_id` is the identity anchor.** `fc_users.id` is an internal surrogate;
   never expose it as "the user id" in the API — always resolve through
   `wp_user_id` so WP's user management stays authoritative.
3. **Soft-delete nothing except payments and sessions.** GDPR erasure is simpler
   with hard deletes + `ON DELETE CASCADE`; financial records get
   `status='refunded'` instead of deletion.
4. **JSON columns only for genuinely schemaless data** (theme fragments, feature
   flags, gateway payloads). Anything queried or aggregated gets real columns.
   This is why `nutrition_logs.food_items JSON` (spec §2.11) becomes a child table.
5. **`created_at`/`updated_at` on everything**, UTC, `DATETIME` not `TIMESTAMP`
   (TIMESTAMP's 2038 limit and implicit TZ conversion both bite).
6. **InnoDB + `utf8mb4_unicode_520_ci`** via `$this->charsetCollate`.

## Migration mechanics

wpBones runs `database/migrations/*.php` on `register_activation_hook` — there is
no `bones migrate` command and **no `down()` is ever executed**. Therefore:

```php
// database/migrations/20260801_000100_create_fc_users_table.php
use FitnessClub\Database\Migration;          // our base, not the framework's

return new class extends Migration {
  public function up() {
    $this->create('fc_users', "(
  id bigint(20) unsigned NOT NULL auto_increment,
  wp_user_id bigint(20) unsigned NOT NULL,
  ...
  PRIMARY KEY  (id),
  UNIQUE KEY uq_wp_user (wp_user_id)
    ) {$this->charsetCollate};");

    $this->engine('fc_users');               // dbDelta sets no storage engine
    // $this->foreign('child', 'col', 'parent', 'id', 'CASCADE');
  }
};
```

Note `PRIMARY KEY` followed by **two** spaces — this is a real dbDelta
requirement, not a style choice.

Rules that follow from activation-time execution:

- Files run in `glob()` order → **name them `YYYYMMDD_HHMMSS_*`** so ordering is
  deterministic and FK-parent tables exist first.
- Every migration must be safe to re-run (activation fires on every reactivate).
  `$this->create()` wraps `dbDelta`, which is idempotent for `CREATE TABLE`.
- **Schema *changes* need a versioned upgrade path**, because `dbDelta` will not
  drop or rename columns. Build this in Phase 1, before the first production
  install — retrofitting it later is painful.

  **Correction from implementation:** this cannot live in `plugin/activation.php`.
  `Plugin::_activation()` runs in the order *options delta → activation.php →
  migrations → seeders*, so activation.php executes **before a single table
  exists**. The dispatcher therefore runs from `FitnessClub\Providers\UpgradeProvider`
  on `init` — the earliest hook guaranteed to fire after migrations have applied.
  Implemented as `FitnessClub\Database\Upgrade\Manager` (option
  `fitnessclub_db_version`, ascending `to<N>()` steps, each recorded as it
  completes so a mid-upgrade fatal does not re-run finished work).
- Foreign keys: declare them, but treat them as documentation +
  defence-in-depth. Some hosts run MyISAM-defaulted or FK-stripped MySQL, and
  `dbDelta` mangles `FOREIGN KEY` clauses. **Enforce referential integrity in the
  service layer regardless**, and add the FKs in a separate post-create
  `ALTER TABLE` guarded so a failure degrades rather than aborts activation.
  Implemented as `foreign()` / `engine()` on `FitnessClub\Database\Migration`.
- **`dbDelta` formatting is unforgiving**, and getting it wrong causes silent
  re-`ALTER`s on every activation rather than an error: each column on its own
  line, `KEY` never `INDEX`, every index named, and **two spaces** after
  `PRIMARY KEY`.
- JSON columns are declared `longtext`, not `json`. `dbDelta` compares its
  declaration against `SHOW COLUMNS` output; the `json` type does not round-trip
  cleanly across MySQL and MariaDB and provokes a repeated `ALTER`.
- `updated_at` is `DEFAULT CURRENT_TIMESTAMP` **without** `ON UPDATE
  CURRENT_TIMESTAMP`, and is set in application code. `dbDelta` cannot parse the
  `ON UPDATE` clause out of `SHOW COLUMNS` and will re-issue the `ALTER` forever.

**Verified 2026-07-24:** all 29 migrations applied against MySQL 9.7.1 →
29 tables, 35 foreign keys, 94 indexes, all InnoDB, byte-identical on a second
run (idempotent), zero SQL errors.

## Tables

### Identity & relationships

**`fc_users`** — profile extension of a WP user.

```
id                    BIGINT UNSIGNED PK
wp_user_id            BIGINT UNSIGNED  UNIQUE, NOT NULL
display_name          VARCHAR(100)
phone                 VARCHAR(32)
avatar_url            VARCHAR(500)
date_of_birth         DATE NULL
gender                ENUM('male','female','other','undisclosed')
height_cm             DECIMAL(5,2) NULL
weight_kg             DECIMAL(5,2) NULL          -- latest, denormalised from health_stats
target_weight_kg      DECIMAL(5,2) NULL          -- NEW: dashboard "Goal Progress"
body_fat_percentage   DECIMAL(5,2) NULL          -- NEW
fitness_level         ENUM('beginner','intermediate','advanced','elite')
fitness_goal          VARCHAR(64)                -- NEW: "Build Muscle" etc.
activity_level        ENUM('sedentary','light','moderate','active','very_active')  -- NEW
nutrition_goals       JSON                       -- NEW: {calories,protein,carbs,fat,water_glasses}
preferences           JSON                       -- NEW: notification toggles, privacy, units
timezone              VARCHAR(64)
locale                VARCHAR(10)
onboarded_at          DATETIME NULL
created_at updated_at DATETIME
KEY (wp_user_id)
```

`weight_kg` is a deliberate denormalisation: the dashboard reads it on every load
and `MAX(record_date)` on `fc_health_stats` would be a subquery per request.
`WorkoutSessionService`/`HealthService` keep it in sync on every weight write.

**`fc_trainers`**

```
id, wp_user_id UNIQUE, display_name, bio TEXT, specialization VARCHAR(255),
avatar_url, phone,
hourly_rate DECIMAL(10,2),    -- DISPLAY ONLY (Q2). Never used in any calculation.
currency CHAR(3) DEFAULT 'USD',
rating DECIMAL(3,2) NULL,            -- NEW: admin table column
rating_count INT UNSIGNED DEFAULT 0, -- NEW
status ENUM('active','inactive','pending') DEFAULT 'active',   -- NEW
accepting_clients TINYINT(1) DEFAULT 1,
max_clients INT UNSIGNED NULL,        -- NEW (Q4): capacity cap; NULL = unlimited
created_at, updated_at
```

`accepting_clients` and `max_clients` gate the **trainer directory** (Q4): a
trainer at capacity, or not accepting, stops appearing as requestable rather than
accumulating requests they will decline. Both are trainer-editable from their own
profile.

`hourly_rate` is what the trainer charges for offline sessions, shown on their
public profile. Because trainers are **traced, not paid** through the platform
(Q2), no table anywhere multiplies it by anything. There is no payouts table, no
balance, no ledger — trainer revenue *attribution* is a report over
`fc_subscriptions.trainer_id`, not a stored figure.

Prototype shows an `online` boolean and a `clients` count. Neither is stored:
online = `get_user_meta(wp_user_id,'fc_last_seen')` within 5 min (heartbeat);
client count = `COUNT(*)` on `fc_user_trainers WHERE status='active'`. Storing
either invites permanent drift.

**`fc_user_trainers`** — many-to-many, confirmed (Q3: a user may have several
trainers).

```
id, user_id FK→fc_users, trainer_id FK→fc_trainers,
requested_at DATETIME NULL,            -- NEW (Q4): user-initiated request
request_message VARCHAR(500) NULL,     -- NEW (Q4)
responded_at DATETIME NULL,            -- NEW (Q4)
decline_reason VARCHAR(255) NULL,      -- NEW (Q4)
assigned_date DATE NULL, ended_date DATE NULL,
assigned_by_wp_user_id BIGINT UNSIGNED NULL,   -- set only on admin override
is_primary TINYINT(1) DEFAULT 0,       -- NEW (Q3): at most one per user
status ENUM('pending','active','inactive','declined','withdrawn') DEFAULT 'pending',
created_at, updated_at
UNIQUE KEY uq_pair_active (user_id, trainer_id, status)
KEY (trainer_id, status), KEY (user_id, is_primary), KEY (status, requested_at)
```

This table is the **authorisation spine** for the whole trainer role. Every
trainer-scoped query joins it. Index accordingly.

**Users initiate** (Q4): a user requests from the trainer directory
(`status='pending'`), the trainer accepts (`active`) or declines (`declined`);
a user may withdraw a pending request (`withdrawn`). Admins can still create an
`active` row directly as an override, which is the only case where
`assigned_by_wp_user_id` is set. `declined`/`withdrawn` are deliberately distinct
from `inactive` (a real relationship that ended) — collapsing them loses the
ability to stop re-suggesting a trainer who already said no.

`KEY (status, requested_at)` serves the trainer's pending-request queue.

`is_primary` exists because several surfaces need a single answer to "your
trainer" even when there are three: the dashboard header, onboarding, and
notification routing. MySQL cannot express "at most one true per user_id", so
`AssignmentService::setPrimary()` clears the others inside a transaction.

**`fc_client_notes`** — NEW (Q3/Q13). Trainer-private notes on a client.

```
id, trainer_id FK, user_id FK,
body TEXT, pinned TINYINT(1) DEFAULT 0,
created_at, updated_at
KEY (trainer_id, user_id, created_at)
```

Scoped on `(trainer_id, user_id)` and **never readable by another trainer** — the
one piece of client data that is deliberately not shared when a client has
multiple coaches. Q13 opened up assignments and plans to all assigned trainers,
but notes are observations rather than programming, so they stay private —
**confirmed (Q15)**. Not in the spec; the migration belongs in W1.1 since adding
it later is a schema change.

### Plans, subscriptions, money

**`fc_plans`** — spec §2.4 plus the platform/trainer split (see README assumption 4).

```
id, owner_type ENUM('platform','trainer') NOT NULL DEFAULT 'trainer',   -- NEW
trainer_id BIGINT UNSIGNED NULL,     -- NULL when owner_type='platform'
plan_name VARCHAR(255), slug VARCHAR(120), description TEXT,
plan_type ENUM('workout','nutrition','combined') DEFAULT 'combined',
difficulty ENUM('beginner','intermediate','advanced'),
weekly_sessions INT, duration_weeks INT,
currency CHAR(3) DEFAULT 'USD',
price_weekly price_monthly price_quarterly price_yearly DECIMAL(10,2) NULL,
features JSON,                       -- entitlement flags, spec §2.5
includes_nutrition includes_health_stats TINYINT(1),
max_messages_per_week INT DEFAULT 10,
is_active TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 0,
created_at, updated_at
KEY (owner_type, is_active), KEY (trainer_id)
```

A NULL price tier means *that billing period is not offered* — distinct from 0.00
(free). The prototype's `Free` tier is `owner_type='platform'` with all prices 0.00.

`features` JSON is read by `EntitlementService` and is the **single source of
truth for what a user may do**. Shape per spec §2.5, extended:

```json
{ "can_log_workouts": true, "can_log_nutrition": true, "can_log_health": true,
  "can_view_stats": true, "can_message": true, "max_messages_per_week": 10,
  "has_video_workouts": true, "has_personalized_plans": false,
  "has_food_plans": true, "max_active_workouts": null,
  "max_trainers": 2 }
```

`max_trainers` (Q14) is what makes multi-trainer (Q3) a **sold feature** rather
than an unbounded one. A user must hold an active subscription to request a
trainer at all, and the merged cap across their active plans limits how many they
may hold — counting pending requests, so queuing five requests is not a way around
a one-trainer plan. `0` is meaningful: the free tier. Merge rule and enforcement:
[09 §4.6](09-gap-register.md#46-q14--an-active-plan-gates-trainer-access).

**`fc_subscriptions`** (spec `wn_user_subscriptions`)

```
id, user_id FK, plan_id FK, trainer_id FK NULL,
start_date DATE, end_date DATE NULL, trial_ends_at DATE NULL,
subscription_type ENUM('weekly','monthly','quarterly','yearly'),
status ENUM('trialing','active','past_due','paused','cancelled','expired') DEFAULT 'active',
auto_renew TINYINT(1) DEFAULT 1,
cancel_at_period_end TINYINT(1) DEFAULT 0,    -- NEW: "cancel" ≠ instant loss of access
price_paid DECIMAL(10,2), currency CHAR(3),
gateway VARCHAR(32),                          -- NEW: 'stripe' | 'manual' | 'paypal'
gateway_customer_id gateway_subscription_id VARCHAR(255) NULL,   -- NEW
created_at, updated_at
KEY (user_id, status), KEY (status, end_date), KEY (trainer_id, status)
```

`(status, end_date)` serves the daily expiry cron. Spec's flat `cancelled` status
loses the extremely common "cancelled but paid through the 28th" state — hence
`cancel_at_period_end`.

`trainer_id` is the **attribution key** (Q2): it records whose plan was bought so
`GET /admin/reports/trainers` can total revenue per trainer. It confers no
entitlement to proceeds and drives no payout.

**A user may hold several concurrent subscriptions** — one per trainer (Q3).
`trainer_id` being singular *per row* is correct; multi-trainer is expressed as
multiple rows. This is what forces the entitlement merge rule
([09 §4.2](09-gap-register.md#42-q3--multiple-trainers-per-user)): union the
booleans, take the max of numeric caps, and scope `max_messages_per_week` to the
row whose `trainer_id` matches the thread.

**`fc_payments`** — spec §2.16 plus currency/gateway.

```
id, user_id FK, subscription_id FK NULL,
amount DECIMAL(10,2), currency CHAR(3), 
payment_type ENUM('subscription','one_time','refund'),
gateway VARCHAR(32), payment_method VARCHAR(50),
transaction_id VARCHAR(255) UNIQUE,
status ENUM('pending','completed','failed','refunded','disputed') DEFAULT 'pending',
failure_reason VARCHAR(255) NULL,
gateway_payload JSON NULL,                     -- raw webhook body, for reconciliation
payment_date DATETIME NULL, created_at, updated_at
KEY (user_id, status), KEY (payment_date)
```

`transaction_id UNIQUE` is the webhook idempotency key — process a duplicate
Stripe event and the insert fails harmlessly.

### Workouts

**`fc_workouts`**

```
id, trainer_id FK NULL, plan_id FK NULL,
workout_name VARCHAR(255), slug, description TEXT,
workout_type ENUM('strength','cardio','hiit','flexibility','recovery'),
difficulty ENUM('beginner','intermediate','advanced'),
estimated_duration_minutes INT, calories_burn_estimate INT,
instructions TEXT, video_url VARCHAR(500),
cover_image_url VARCHAR(500),         -- NEW: card image in both prototypes
media_gallery JSON, equipment JSON,   -- NEW: ["Barbell","Bench"]
muscle_groups JSON,                   -- NEW: card chips; spec only had it on exercises
is_template TINYINT(1) DEFAULT 1, is_active TINYINT(1) DEFAULT 1,
created_at, updated_at
KEY (trainer_id), KEY (plan_id), KEY (difficulty, is_active)
```

**`fc_exercises`**

```
id, workout_id FK, exercise_name VARCHAR(255),
exercise_type ENUM('compound','isolation','cardio','flexibility'),
muscle_groups JSON, instructions TEXT, notes VARCHAR(500),   -- NEW: player coaching cue
video_url, thumbnail_url VARCHAR(500), media_gallery JSON,
default_sets INT, default_reps INT, default_weight_kg DECIMAL(6,2),
default_rest_seconds INT DEFAULT 60,     -- NEW ★ blocker: player rest timer
metric ENUM('reps','seconds','distance') DEFAULT 'reps',  -- NEW
order_index INT, created_at, updated_at
KEY (workout_id, order_index)
```

★ Without `default_rest_seconds` the workout player specified in §8.2 cannot be
built. The prototype's every exercise carries `restTime`. This is the single most
consequential omission in the spec.

`metric` resolves a real prototype ambiguity: "Plank Hold — 3 sets × 60 reps
(*note: seconds*)" and "Jump Rope — reps = jumps". Encoding the unit as a note
means the UI cannot label it and analytics cannot aggregate it.

**`fc_user_workouts`** — NEW. Assignment + progress.

```
id, user_id FK, workout_id FK, assigned_by_wp_user_id BIGINT NULL,
assigned_date DATE, scheduled_for DATE NULL,
progress_percentage DECIMAL(5,2) DEFAULT 0,   -- the card's progress bar
last_session_id BIGINT UNSIGNED NULL,
times_completed INT DEFAULT 0,
status ENUM('assigned','in_progress','completed','archived') DEFAULT 'assigned',
created_at, updated_at
UNIQUE KEY uq_user_workout (user_id, workout_id)
KEY (user_id, status)
```

The user prototype shows `progress: 65` per workout with Start/Resume/Restart
buttons keyed off it. Nothing in the spec's schema can produce that number.

**`fc_workout_sessions`** (spec `wn_workout_logs`) — the state machine's store.

```
id, user_id FK, workout_id FK, user_workout_id FK NULL,
log_date DATE, started_at DATETIME, ended_at DATETIME NULL,
duration_seconds INT DEFAULT 0,           -- accumulated ACTIVE time
paused_seconds INT DEFAULT 0,
last_resumed_at DATETIME NULL,            -- NEW: for crash-safe elapsed calc
status ENUM('in_progress','paused','completed','abandoned') DEFAULT 'in_progress',
current_exercise_index INT DEFAULT 0,     -- NEW ★ resume pointer
current_set_index INT DEFAULT 0,          -- NEW ★
completion_percentage DECIMAL(5,2) DEFAULT 0,
calories_burned INT NULL, perceived_exertion TINYINT NULL, difficulty_rating TINYINT NULL,
notes TEXT, created_at, updated_at
KEY (user_id, log_date), KEY (user_id, status), KEY (workout_id)
```

★ Spec §8.1 promises "Pause/Resume — can pause and continue later" but stores no
cursor. `duration_seconds` is computed server-side from `last_resumed_at` on every
pause/complete — **never trust a client-supplied elapsed time**, or the leaderboard
and calorie numbers become client-editable.

Partial unique index intent: at most one `in_progress`/`paused` session per user.
MySQL lacks partial indexes, so enforce in `WorkoutSessionService::start()` inside
a transaction (`SELECT … FOR UPDATE`).

**`fc_exercise_logs`** — one row per exercise per session (planned vs performed).

```
id, session_id FK, exercise_id FK, order_index INT,
planned_sets planned_reps INT, planned_weight_kg DECIMAL(6,2),
actual_sets actual_reps INT, actual_weight_kg DECIMAL(6,2),
total_volume_kg DECIMAL(10,2),      -- Σ(reps×weight), computed on write
was_skipped TINYINT(1) DEFAULT 0, notes TEXT,
created_at, updated_at
UNIQUE KEY uq_session_exercise (session_id, exercise_id)
```

**`fc_set_logs`** — NEW. One row per set. This is what the player actually emits.

```
id, exercise_log_id FK, set_index INT,
reps INT, weight_kg DECIMAL(6,2), duration_seconds INT NULL,
rest_taken_seconds INT NULL, rpe TINYINT NULL,
completed_at DATETIME,
UNIQUE KEY uq_set (exercise_log_id, set_index)
```

Splitting sets out is what makes §9.2's "Volume (sets × reps × weight)", per-set
progression, and PR detection possible. Aggregating into a single row (as spec'd)
throws away the data the analytics screen is specified to show.

**`fc_personal_records`** — NEW, a cache.

```
id, user_id FK, exercise_name VARCHAR(255),   -- name, not id: PRs follow the movement
record_type ENUM('max_weight','max_reps','max_volume','best_time'),
value DECIMAL(10,2), unit VARCHAR(16),
session_id FK NULL, achieved_at DATETIME,
UNIQUE KEY uq_pr (user_id, exercise_name, record_type)
```

Derivable from `fc_set_logs`, but the celebration screen needs it in <50 ms at the
exact moment a session completes. Written by `WorkoutSessionService::complete()`;
a `bones` command rebuilds it from set logs if it drifts.

### Nutrition

**`fc_foods`** — NEW. The admin app's "Food Database".

```
id, name VARCHAR(255), brand VARCHAR(120) NULL,
category ENUM('protein','grains','vegetables','fruit','dairy','nuts','fats','supplements','beverages','other'),
serving_size VARCHAR(64), serving_grams DECIMAL(7,2) NULL,
calories INT, protein_g carbs_g fat_g DECIMAL(6,2),
fiber_g sugar_g sodium_mg DECIMAL(7,2) NULL,
barcode VARCHAR(32) NULL,           -- §12/prototype "Scan Barcode"
source ENUM('system','trainer','user') DEFAULT 'system',
created_by_wp_user_id BIGINT NULL, is_verified TINYINT(1) DEFAULT 0,
created_at, updated_at
KEY (category), KEY (barcode), FULLTEXT KEY ft_name (name, brand)
```

`FULLTEXT` because the food-search modal is a typeahead; `LIKE '%x%'` will not
scale past a few thousand rows.

**`fc_nutrition_logs`** — one row per logged meal.

```
id, user_id FK, log_date DATE, logged_at DATETIME,
meal_type ENUM('breakfast','lunch','dinner','snack','other'),
total_calories INT, total_protein_g total_carbs_g total_fat_g DECIMAL(6,2),
notes TEXT, created_at, updated_at
KEY (user_id, log_date)
```

Totals are denormalised sums of the child rows — recomputed on every child write.

**`fc_nutrition_log_items`** — NEW, replaces spec's `food_items JSON`.

```
id, nutrition_log_id FK, food_id FK NULL,
custom_name VARCHAR(255) NULL,       -- free-text entry, prototype's textarea path
quantity DECIMAL(7,2) DEFAULT 1, unit VARCHAR(32),
calories INT, protein_g carbs_g fat_g DECIMAL(6,2),
KEY (nutrition_log_id)
```

Nutrient values are **copied, not joined** — if an admin corrects a food's macros
next month, historical logs must not silently change.

**`fc_nutrition_days`** — NEW. Daily rollup + water.

```
id, user_id FK, log_date DATE,
water_ml INT DEFAULT 0,
goal_calories INT, goal_protein_g goal_carbs_g goal_fat_g DECIMAL(6,2), goal_water_ml INT,
total_calories INT DEFAULT 0, total_protein_g total_carbs_g total_fat_g DECIMAL(6,2),
UNIQUE KEY uq_user_day (user_id, log_date)
```

Water is a per-*day* quantity; the spec put `water_intake_ml` on each meal row,
which makes "5 of 8 glasses today" a SUM over rows that may not exist. Goals are
snapshotted per day so a goal change next week doesn't rewrite last week's history.

**`fc_food_plans`, `fc_food_plan_meals`** — as spec §2.17/2.18, plus
`fc_food_plan_meals.food_ids JSON` and `order_index`, and a
**`fc_user_food_plans`** assignment table (user_id, food_plan_id, start/end,
status) — the spec has no way to assign a food plan to a user.

### Health

**`fc_health_stats`**

```
id, user_id FK, record_date DATE, recorded_at DATETIME,
weight_kg DECIMAL(5,2) NULL, body_fat_percentage DECIMAL(5,2) NULL,
muscle_mass_kg DECIMAL(5,2) NULL, bmi DECIMAL(5,2) NULL,
systolic_pressure diastolic_pressure INT NULL, heart_rate_resting INT NULL,
sleep_hours DECIMAL(3,1) NULL,
mood_score TINYINT NULL,        -- 1..5   (spec had stress_level ENUM; prototype uses 1-5 mood)
energy_score TINYINT NULL,      -- 1..10  (spec had ENUM; prototype uses 1-10)
stress_score TINYINT NULL,      -- 1..5   kept, numeric
notes TEXT,
source ENUM('manual','device','trainer','admin') DEFAULT 'manual',   -- 'admin' per Q10
last_edited_by_wp_user_id BIGINT UNSIGNED NULL,                       -- NEW (Q10)
created_at, updated_at
UNIQUE KEY uq_user_date (user_id, record_date)
KEY (user_id, record_date)
```

`source='admin'` and `last_edited_by_wp_user_id` exist because administrators may
edit these rows (Q10, confirmed). Without them a staff correction is
indistinguishable from a self-reported reading, and the user's chart changes with
no visible cause. Same two columns on `fc_nutrition_logs`. Every such edit also
writes a `fc_activity_log` row carrying before/after values.

Numeric scores rather than the spec's `ENUM('low','moderate','high','very_high')`:
the prototype charts these, and you cannot average an ENUM. A presenter maps
numbers back to labels for display.

`UNIQUE (user_id, record_date)` makes health logging an upsert — one record per
day per user, which is what the "Weight History" chart assumes.

BMI is **computed on write** (`weight / (height/100)²`) rather than trusted from
the client, and NULL when height is unknown.

**`fc_body_measurements`** — NEW. Radar chart + profile form.

```
id, user_id FK, record_date DATE,
chest_cm waist_cm hips_cm arms_cm thighs_cm shoulders_cm neck_cm calves_cm DECIMAL(5,2) NULL,
notes TEXT, created_at
UNIQUE KEY uq_user_date (user_id, record_date)
```

Separate from `health_stats` because measurement cadence is monthly while weight
is daily — merging them produces a table that is 90 % NULL.

### Communication

**`fc_message_threads`** — NEW.

```
id, user_id FK, trainer_id FK,
last_message_at DATETIME, last_message_preview VARCHAR(255),
user_unread_count trainer_unread_count INT DEFAULT 0,
status ENUM('open','archived') DEFAULT 'open',
created_at, updated_at
UNIQUE KEY uq_thread (user_id, trainer_id)
KEY (user_id, last_message_at), KEY (trainer_id, last_message_at)
```

The conversation list (avatar, last message, unread badge, timestamp) is the most
frequently polled query in the app. Without a thread table it is a correlated
subquery over the whole message table per conversation, per poll, per user.

**`fc_messages`**

```
id, thread_id FK, sender_wp_user_id BIGINT UNSIGNED,
direction ENUM('user_to_trainer','trainer_to_user'),
message TEXT, attachments JSON NULL,     -- NEW: prototype renders an inline image
is_read TINYINT(1) DEFAULT 0, read_at DATETIME NULL,
created_at
KEY (thread_id, created_at), KEY (thread_id, is_read)
```

`sender_wp_user_id` rather than the spec's `user_id`+`trainer_id`+`direction` trio:
one authoritative sender, direction kept as a denormalised convenience for the
quota query.

**`fc_notifications`** — NEW. Nothing in the spec, an entire screen in the prototype.

```
id, wp_user_id BIGINT UNSIGNED,
type ENUM('workout','message','achievement','subscription','system','progress','support'),
title VARCHAR(255), body TEXT, icon VARCHAR(64), color VARCHAR(32),
action_url VARCHAR(500) NULL, meta JSON NULL,
is_read TINYINT(1) DEFAULT 0, read_at DATETIME NULL,
created_at
KEY (wp_user_id, is_read, created_at)
```

**`fc_activity_log`** — NEW. "Recent Activity" feed + §15.2 audit requirement.

```
id, wp_user_id BIGINT UNSIGNED, actor_wp_user_id BIGINT UNSIGNED NULL,
type VARCHAR(48),                    -- workout.completed, nutrition.logged, health.updated…
subject_type VARCHAR(48), subject_id BIGINT UNSIGNED NULL,
title VARCHAR(255), detail VARCHAR(255), meta JSON,
created_at
KEY (wp_user_id, created_at), KEY (type, created_at)
```

Doubles as the audit trail for sensitive admin actions (`actor_wp_user_id` = who
did it, `wp_user_id` = to whom). Retention: prune > 12 months via cron.

### Support

**`fc_tickets`**

```
id, user_wp_user_id BIGINT UNSIGNED, subject VARCHAR(255), message TEXT,
category ENUM('billing','technical','general','training','other'),
priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
status ENUM('open','in_progress','waiting_user','resolved','closed') DEFAULT 'open',
assigned_to_wp_user_id BIGINT UNSIGNED NULL,     -- admin OR trainer, so WP id not trainer id
first_response_at resolved_at DATETIME NULL,     -- SLA reporting
created_at, updated_at
KEY (status, priority), KEY (assigned_to_wp_user_id, status), KEY (user_wp_user_id)
```

Spec's `assigned_to` FKs to `wn_trainers`, which makes assigning a ticket to an
administrator impossible — the exact case §13 requires.

**`fc_ticket_replies`**

```
id, ticket_id FK, author_wp_user_id BIGINT UNSIGNED,
author_role ENUM('user','trainer','admin'),
message TEXT, attachments JSON NULL,
is_internal_note TINYINT(1) DEFAULT 0,     -- staff-only, never returned to the user
created_at
KEY (ticket_id, created_at)
```

## Index strategy

Beyond the keys listed inline, add for the known hot paths:

| Query | Index |
|-------|-------|
| Dashboard weekly chart | `fc_workout_sessions (user_id, status, log_date)` |
| Streak calculation | same, covered by the above |
| Nutrition day lookup | `fc_nutrition_days (user_id, log_date)` UNIQUE |
| Trainer client list | `fc_user_trainers (trainer_id, status)` |
| Message quota (weekly count) | `fc_messages (thread_id, created_at)` |
| Unread badge (all threads) | `fc_message_threads (user_id, user_unread_count)` |
| Subscription expiry cron | `fc_subscriptions (status, end_date)` |
| Admin user table search | `fc_users (display_name)` + join to `wp_users.user_email` |

Do **not** pre-index everything else. Add indexes when `SAVEQUERIES` profiling on
seeded data (§ below) shows a scan, not before.

## Seeding

`database/seeders/DemoSeeder.php`, invoked by `php bones tinker` or a
`bones fc:seed` command, must reproduce the prototype seed data **exactly** —
Alex Morgan, the 6 workouts with their 44 exercises, Sarah Chen / Mike Torres,
the 12 foods, the billing rows. Two reasons: the ported UI can be diffed
screen-by-screen against the prototype, and every chart has realistic data on
day one.

Add a `--volume` mode generating 1 000 users × 180 days of sessions/meals/health
rows (~2 M rows) for the §19.2 performance targets. Query tuning without it is
guesswork.
