<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Prose for the generated OpenAPI document (W4.6).
 *
 * {@see OpenApi} derives every *mechanical* fact — paths, methods, parameter
 * types, enums, bounds, required flags — from the live route registry. What it
 * cannot derive is what an endpoint is **for**, and that is this file.
 *
 * ## The rule for what goes in a description
 *
 * A description that restates the URL earns nothing: "Gets the workouts" above
 * `GET /workouts` is noise a reader has to skim past. So the `description` key is
 * used only where there is a **rule a caller would otherwise get wrong** — an
 * endpoint that answers 204 rather than an empty object, a write that is
 * idempotent on a key the caller has to supply, a quota that is per-thread rather
 * than per-user, a 404 that means "not yours" rather than "does not exist".
 * Endpoints without such a rule carry a summary and nothing else, deliberately.
 *
 * ## Staying in step with the code
 *
 * This list is hand-maintained and therefore rots unless something stops it.
 * Three things do: `OpenApi::undocumented()` reports routes with no entry here,
 * `OpenApi::orphanedDocs()` reports entries whose route is gone, and
 * `OpenApiTest` asserts both are empty. Adding or renaming a route fails the
 * suite until this file is updated.
 */
final class ApiDocs
{
    /**
     * Tag order is the reading order of the rendered document — the member's own
     * journey first (sign in, train, eat, measure), then the people around them,
     * then the back office.
     *
     * @return array<int,array<string,string>>
     */
    public static function tags(): array
    {
        return [
            ['name' => 'System', 'description' => 'Liveness.'],
            [
                'name'        => 'Authentication',
                'description' => 'Sign in, register, recover. The plugin owns its accounts — '
                    . 'a WordPress login is not a FitnessClub login.',
            ],
            ['name' => 'Member', 'description' => 'The member\'s own dashboard and preferences.'],
            ['name' => 'Workouts', 'description' => 'The workout library and its exercises.'],
            ['name' => 'Sessions', 'description' => 'Doing a workout: the state machine behind the player.'],
            ['name' => 'Nutrition', 'description' => 'Meals, macros, water and goals.'],
            ['name' => 'Foods', 'description' => 'The food database and its search.'],
            ['name' => 'Health', 'description' => 'Daily health metrics and body measurements.'],
            ['name' => 'Progress', 'description' => 'Charted series, personal records and consistency.'],
            ['name' => 'Notifications', 'description' => 'The inbox and the activity feed.'],
            ['name' => 'Messaging', 'description' => 'Conversations between a member and each of their trainers.'],
            ['name' => 'Billing', 'description' => 'Plans, subscriptions, payments and gateway webhooks.'],
            ['name' => 'Support', 'description' => 'Tickets and the FAQ.'],
            ['name' => 'Trainer directory', 'description' => 'How a member finds and requests a coach.'],
            ['name' => 'Trainer', 'description' => 'The coaching side: clients, library, notes, requests.'],
            [
                'name'        => 'Admin',
                'description' => 'Back office. Gated on FitnessClub capabilities, not on `manage_options`.',
            ],
        ];
    }

    /**
     * Turn a permission callback into a security requirement and a sentence.
     *
     * The callback name is the *only* machine-readable statement of who may call
     * an endpoint, so it is the input rather than a second hand-maintained map of
     * roles — which could disagree with the code that enforces it.
     *
     * @return array{security:array<int,array<string,array<int,string>>>,description:string}
     */
    public static function auth(string $callback): array
    {
        $signedIn = [['sessionCookie' => [], 'csrfToken' => []]];

        $public = static fn(string $why): array => ['security' => [], 'description' => "**Public.** {$why}"];

        $role = static fn(string $who): array => [
            'security'    => $signedIn,
            'description' => "**Requires a signed-in {$who}.**",
        ];

        return match ($callback) {
            '__return_true', 'unknown' => $public('No session required.'),

            'AuthController::requireGuest' => $public(
                'Refused *with* a session, so a call made while signed in cannot silently swap accounts.'
            ),
            'AuthController::requireSession' => $role('account'),

            'AdminController::canAccess' => [
                'security'    => $signedIn,
                'description' => '**Requires a signed-in account holding the relevant `fc_*` capability.** '
                    . 'The capability is decided per resource by the server\'s registry. A WordPress '
                    . 'administrator with no FitnessClub account is anonymous here by design.',
            ],

            'TrainerController::canAccess' => $role('trainer'),

            'DirectoryController::canRead',
            'UserController::canRead',
            'NotificationController::canRead',
            'NotificationController::canManagePreferences',
            'WorkoutController::canRead',
            'SessionController::canRead',
            'SessionController::canLog',
            'NutritionController::canRead',
            'NutritionController::canLog',
            'HealthController::canRead',
            'HealthController::canLog',
            'ProgressController::canRead',
            'BillingController::canRead',
            'BillingController::canManage',
            'MessageController::canAccess',
            'SupportController::canAccess' => $role('member'),

            default => ['security' => $signedIn, 'description' => '**Requires a signed-in account.**'],
        };
    }

    /**
     * @return array{summary:string,tag:string,description?:string,returns?:string,status?:int,response?:string}|null
     */
    public static function describe(string $method, string $path): ?array
    {
        return self::all()["{$method} {$path}"] ?? null;
    }

    /**
     * Every documented operation, keyed `"METHOD /path"`.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::system()
            + self::authentication()
            + self::member()
            + self::workouts()
            + self::sessions()
            + self::nutrition()
            + self::foods()
            + self::health()
            + self::progress()
            + self::notifications()
            + self::messaging()
            + self::billing()
            + self::support()
            + self::directory()
            + self::trainer()
            + self::admin();
    }

    /** @return array<string,array<string,mixed>> */
    private static function system(): array
    {
        return [
            'GET /health' => [
                'tag'     => 'System',
                'summary' => 'Liveness check',
                'returns' => 'The plugin is active and the REST namespace is reachable.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function authentication(): array
    {
        return [
            'POST /auth/login' => [
                'tag'         => 'Authentication',
                'summary'     => 'Sign in',
                'description' => 'Returns **the whole boot payload**, not a token, so the app goes from the '
                    . 'sign-in screen to the dashboard with no second request. Deliberately flat about '
                    . 'failure: a wrong password, an unknown email and an unknown username all answer the '
                    . 'identical `fc_invalid_credentials`, so this is not an account-enumeration oracle.',
                'returns'     => 'The boot payload for the newly signed-in account.',
                'status'      => 200,
                'response'    => 'Boot',
            ],
            'POST /auth/register' => [
                'tag'         => 'Authentication',
                'summary'     => 'Create an account',
                'description' => 'Unlike login, this **cannot** hide a taken address — registration would be '
                    . 'unusable if it did — so it says so plainly with `fc_email_taken` (409).',
                'returns'     => 'The boot payload for the new account.',
                'status'      => 200,
                'response'    => 'Boot',
            ],
            'POST /auth/logout' => [
                'tag'     => 'Authentication',
                'summary' => 'Sign out',
                'returns' => 'The session is revoked. `GET /auth/me` then answers 401.',
                'status'  => 200,
            ],
            'GET /auth/me' => [
                'tag'         => 'Authentication',
                'summary'     => 'The current session',
                'description' => 'The same object the shell embeds as `data-boot`, minus the transport-only '
                    . 'keys. A refresh is a straight replacement rather than a merge.',
                'returns'     => 'The boot payload.',
                'response'    => 'Boot',
            ],
            'POST /auth/password/forgot' => [
                'tag'         => 'Authentication',
                'summary'     => 'Request a password reset',
                'description' => 'Answers the same 200 whether or not the address exists — for the same '
                    . 'anti-enumeration reason as login. Non-administrators are sent to the app\'s own '
                    . 'reset screen; administrators keep WordPress\'s flow, because an admin locked out '
                    . 'by a broken app route is a support incident.',
                'returns'     => 'Accepted, whether or not an account matched.',
                'status'      => 200,
            ],
            'POST /auth/password/reset' => [
                'tag'         => 'Authentication',
                'summary'     => 'Set a new password with a reset key',
                'description' => 'The only public endpoint that is *not* refused when a session exists: the '
                    . 'key is the authorisation, and somebody still signed in on that browser has to be '
                    . 'able to follow the link they were emailed.',
                'returns'     => 'The password is changed and every existing session for that account is revoked.',
                'status'      => 200,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function member(): array
    {
        return [
            'GET /user/dashboard' => [
                'tag'         => 'Member',
                'summary'     => 'Everything on the home screen, in one request',
                'description' => 'Ten sections in one payload. **Every section that has no data returns its '
                    . 'zero state** — `0`, `null`, `[]` — and never a plausible-looking default: no ring is '
                    . 'drawn against an imaginary 2 000 kcal goal nobody set.',
                'returns'     => 'Greeting, stats, today\'s and next workout, water, nutrition, weekly chart, '
                    . 'monthly stats, recent activity and message previews.',
            ],
            'GET /user/preferences' => [
                'tag'     => 'Member',
                'summary' => 'Notification and privacy preferences',
            ],
            'PUT /user/preferences' => [
                'tag'         => 'Member',
                'summary'     => 'Update preferences',
                'description' => 'Merges rather than replaces — send only the keys you are changing. The '
                    . 'preferences blob is shared between subsystems, so a wholesale write would silently '
                    . 'drop settings this screen never showed. A category nobody has an opinion about is '
                    . '**on**, so a category added in a later release does not arrive muted for everyone.',
                'returns'     => 'The merged preferences.',
            ],
            'GET /user/trainers' => [
                'tag'     => 'Trainer directory',
                'summary' => 'The coaches this member has',
            ],
            'DELETE /user/trainers/{trainerId}' => [
                'tag'         => 'Trainer directory',
                'summary'     => 'End a coaching relationship, or withdraw a pending request',
                'description' => 'One endpoint, two terminal states. **Which one happens is decided by the '
                    . 'row\'s status, not by the caller**, so a client cannot withdraw an active '
                    . 'relationship into the wrong state. Ending the primary relationship promotes another '
                    . 'coach, so a member with two never drops to zero primaries.',
                'returns'     => 'The relationship is ended or the request withdrawn, and the slot is freed.',
            ],
            'POST /user/trainers/{trainerId}/primary' => [
                'tag'     => 'Trainer directory',
                'summary' => 'Set the primary coach',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function workouts(): array
    {
        return [
            'GET /workouts' => [
                'tag'         => 'Workouts',
                'summary'     => 'The member\'s workout library',
                'description' => 'Scoping is **in the query**, not in a check that can be forgotten, so a '
                    . 'foreign id is a 404 rather than a leak.',
            ],
            'GET /workouts/{id}' => [
                'tag'         => 'Workouts',
                'summary'     => 'One workout, with its exercises in order',
                'description' => 'When the member\'s plan lacks `has_video_workouts` the video URL is '
                    . '**withheld** and `video_locked: true` is set, rather than the whole workout being '
                    . 'refused — a readable workout with a locked video is an upgrade prompt; a 403 is a '
                    . 'dead end.',
            ],
            'GET /exercises/{id}' => [
                'tag'     => 'Workouts',
                'summary' => 'One exercise',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function sessions(): array
    {
        return [
            'GET /sessions' => [
                'tag'     => 'Sessions',
                'summary' => 'Past workout sessions',
            ],
            'GET /sessions/active' => [
                'tag'         => 'Sessions',
                'summary'     => 'The session currently in progress, if any',
                'description' => 'Answers **204 with no body** when nothing is running — not 200 with an '
                    . 'empty object. Parse an empty body as `null`; treating it as `{}` yields a truthy '
                    . 'value with no fields, which is exactly how the player used to crash.',
                'returns'     => 'The open session. 204 when there is none.',
            ],
            'GET /sessions/{id}' => [
                'tag'     => 'Sessions',
                'summary' => 'One session',
            ],
            'POST /sessions' => [
                'tag'         => 'Sessions',
                'summary'     => 'Start a workout',
                'description' => '**One open session per member**, enforced in a transaction. If one is '
                    . 'already running this answers 409 **naming that session**, so the UI can offer '
                    . 'resume-or-discard rather than a dead end.',
                'returns'     => 'The new session.',
            ],
            'PATCH /sessions/{id}' => [
                'tag'         => 'Sessions',
                'summary'     => 'Pause or resume',
                'description' => '**Duration is derived server-side, never reported by the client.** It is '
                    . 'recomputed from `started_at` and `last_resumed_at` on every transition, and paused '
                    . 'time is wall-clock minus active time — self-healing if a write is ever lost.',
            ],
            'PATCH /sessions/{id}/cursor' => [
                'tag'         => 'Sessions',
                'summary'     => 'Move the exercise/set cursor',
                'description' => 'What makes a session resumable on another device.',
            ],
            'POST /sessions/{id}/sets' => [
                'tag'         => 'Sessions',
                'summary'     => 'Log a set',
                'description' => '**Idempotent on `(exercise, set_index)`.** The player fires this mid-workout '
                    . 'on gym wifi, so a retry corrects the set rather than inventing a phantom one — send '
                    . 'the same indices to retry safely. Omit `duration_seconds` and `rest_taken_seconds` '
                    . 'entirely when unmeasured; they accept null but not a wrong number.',
            ],
            'POST /sessions/{id}/complete' => [
                'tag'         => 'Sessions',
                'summary'     => 'Finish the workout',
                'description' => 'Settles duration, volume and calories, detects personal records, advances '
                    . 'the streak and writes the feed and notification rows. Records are upserted only when '
                    . '**strictly greater** — matching your own best is not a new record.',
                'returns'     => 'The completed session with its summary and any new personal records.',
            ],
            'POST /sessions/{id}/abandon' => [
                'tag'         => 'Sessions',
                'summary'     => 'Give up, keeping partial credit',
                'description' => 'Elapsed time and logged sets stand, and the assignment keeps the progress '
                    . 'it earned rather than resetting to zero.',
            ],
            'PATCH /sessions/{id}/review' => [
                'tag'     => 'Sessions',
                'summary' => 'Add a rating or note after finishing',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function nutrition(): array
    {
        return [
            'GET /nutrition/day' => [
                'tag'     => 'Nutrition',
                'summary' => 'One day\'s totals, goals and meals',
            ],
            'GET /nutrition/logs' => [
                'tag'     => 'Nutrition',
                'summary' => 'Logged meals',
            ],
            'POST /nutrition/logs' => [
                'tag'         => 'Nutrition',
                'summary'     => 'Log a meal',
                'description' => '**Send `food_id` and `quantity` — never the nutrients.** The server copies '
                    . 'macros from the food row at the moment of logging, so correcting a food next month '
                    . 'does not rewrite last month\'s diary. Free-text items may carry their own values.',
                'returns'     => 'The meal, with the day\'s totals recomputed.',
            ],
            'PUT /nutrition/logs/{id}' => [
                'tag'     => 'Nutrition',
                'summary' => 'Edit a logged meal',
            ],
            'DELETE /nutrition/logs/{id}' => [
                'tag'         => 'Nutrition',
                'summary'     => 'Delete a logged meal',
                'description' => 'The day\'s rollup is recomputed, not decremented.',
            ],
            'POST /nutrition/water' => [
                'tag'         => 'Nutrition',
                'summary'     => 'Record water',
                'description' => 'Takes **either** `delta_ml` or `total_ml`, and the distinction matters: '
                    . 'two `+250` taps in flight add two glasses, whereas two absolute writes would race to '
                    . 'the same number. Use `delta_ml` for +/- buttons and `total_ml` for "I have had five".',
            ],
            'PUT /nutrition/goals' => [
                'tag'         => 'Nutrition',
                'summary'     => 'Set calorie and macro goals',
                'description' => 'Goals are **snapshotted per day**, so raising a target today does not '
                    . 'retroactively change whether yesterday was met.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function foods(): array
    {
        return [
            'GET /foods' => [
                'tag'         => 'Foods',
                'summary'     => 'Search the food database',
                'description' => 'FULLTEXT boolean-mode search with a prefix wildcard, plus a `LIKE` fallback '
                    . 'below three characters — MySQL ignores tokens under `innodb_ft_min_token_size`, and '
                    . 'without the fallback a two-letter query would look like "we have no foods" rather '
                    . 'than "your server has a setting".',
            ],
            'POST /foods' => [
                'tag'         => 'Foods',
                'summary'     => 'Add a food',
                'description' => 'Members may contribute foods the database lacks.',
            ],
            'GET /foods/barcode/{code}' => [
                'tag'         => 'Foods',
                'summary'     => 'Look up a food by barcode',
                'description' => 'Answers from the local food database only — **there is no third-party '
                    . 'barcode source behind this yet.**',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function health(): array
    {
        return [
            'GET /health/summary' => [
                'tag'         => 'Health',
                'summary'     => 'The eight health cards',
                'description' => 'The server describes what a card *is* — including whether it is editable, '
                    . 'which is how BMI has no input anywhere. A reading older than 90 days leaves its card '
                    . 'empty rather than quoting March\'s resting heart rate as though it were today\'s. '
                    . 'Trends report **direction only**: whether falling weight is progress depends on what '
                    . 'the member is training for, and the server does not know that, so there is no '
                    . '`is_good` field.',
            ],
            'GET /health/stats' => [
                'tag'     => 'Health',
                'summary' => 'Daily health entries',
            ],
            'POST /health/stats' => [
                'tag'         => 'Health',
                'summary'     => 'Record health metrics for a day',
                'description' => 'A day is one row and this is an **upsert**. Only the keys you send are '
                    . 'written, so an evening sleep entry leaves the morning\'s weight alone. BMI is '
                    . 'derived from the weight the row *ends up* holding, never accepted from the client. '
                    . 'Blood pressure is two inputs — half a reading is a 400.',
            ],
            'PUT /health/stats/{id}' => [
                'tag'     => 'Health',
                'summary' => 'Edit a health entry',
            ],
            'DELETE /health/stats/{id}' => [
                'tag'         => 'Health',
                'summary'     => 'Delete a health entry',
                'description' => 'The profile\'s cached current weight is **re-derived** from the newest '
                    . 'remaining reading, so deleting today\'s entry cannot leave the profile quoting a '
                    . 'weight that no longer exists.',
            ],
            'GET /health/measurements' => [
                'tag'         => 'Health',
                'summary'     => 'Body measurements, latest per field',
                'description' => 'Latest **per field**, not per session — somebody who measures chest monthly '
                    . 'and calves twice a year would otherwise watch calves vanish. Each field carries its '
                    . 'own date.',
            ],
            'POST /health/measurements' => [
                'tag'     => 'Health',
                'summary' => 'Record body measurements',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function progress(): array
    {
        return [
            'GET /progress' => [
                'tag'         => 'Progress',
                'summary'     => 'Charted series for a range',
                'description' => 'The range changes the **granularity**, not just the window: days close up, '
                    . 'weeks at a quarter, calendar months across a year, always landing between 7 and 30 '
                    . 'buckets. The response carries `bucket` so a chart of monthly means can label itself '
                    . 'as such. Two kinds of empty, and they are not interchangeable: reading series use '
                    . '**null** for a bucket with no measurement (a gap), consistency uses **zero** (a week '
                    . 'with no workouts is a fact).',
            ],
            'GET /progress/exercises/{name}' => [
                'tag'         => 'Progress',
                'summary'     => 'Strength progression for one exercise',
                'description' => 'The plotted value is the **heaviest set** in each bucket, not the average — '
                    . 'averaging warm-ups in makes a personal best look like a bad week.',
            ],
            'GET /progress/records' => [
                'tag'         => 'Progress',
                'summary'     => 'Personal records',
                'description' => 'Counts records set **inside the window**, not lifetime: on a screen where '
                    . 'every other number is range-scoped, a lifetime count that never moves reads as a '
                    . 'broken filter.',
            ],
            'GET /progress/consistency' => [
                'tag'         => 'Progress',
                'summary'     => 'Adherence over time',
                'description' => 'Completed sessions divided by workouts **scheduled**, capped at 100 — not '
                    . 'active days over calendar days, which would report 13% to somebody who did every '
                    . 'session they were given.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function notifications(): array
    {
        return [
            'GET /notifications' => [
                'tag'         => 'Notifications',
                'summary'     => 'The inbox',
                'description' => 'An inbox belongs to an **account**, not to a member profile, so trainers '
                    . 'and administrators have one too.',
            ],
            'POST /notifications/{id}/read' => [
                'tag'         => 'Notifications',
                'summary'     => 'Mark one as read',
                'description' => 'Answers with the resulting `unread_count`, so the badge can be written '
                    . 'directly rather than invalidated and refetched — a second request would render the '
                    . 'stale number in between.',
            ],
            'POST /notifications/read-all' => [
                'tag'     => 'Notifications',
                'summary' => 'Mark everything as read',
            ],
            'DELETE /notifications/{id}' => [
                'tag'     => 'Notifications',
                'summary' => 'Dismiss a notification',
            ],
            'GET /activity' => [
                'tag'         => 'Notifications',
                'summary'     => 'The activity feed',
                'description' => 'Excludes audit rows — those record what somebody *else* did to the '
                    . 'member\'s data and surface as a `source: admin` label on the affected record, where '
                    . 'they mean something, rather than as a diff in a list of things the member did.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function messaging(): array
    {
        return [
            'GET /messages/threads' => [
                'tag'         => 'Messaging',
                'summary'     => 'Conversations',
                'description' => 'One thread per coaching relationship, created when a trainer accepts.',
            ],
            'GET /messages/threads/{id}' => [
                'tag'     => 'Messaging',
                'summary' => 'One conversation',
            ],
            'POST /messages/threads/{id}' => [
                'tag'         => 'Messaging',
                'summary'     => 'Send a message',
                'description' => 'The message quota is **per thread**, drawn from that trainer\'s plan. A '
                    . 'member coached by two trainers cannot be silenced with one coach because they were '
                    . 'chatty with the other. Only the member\'s own sends count — a trainer\'s replies '
                    . 'never spend the allowance.',
            ],
            'POST /messages/threads/{id}/read' => [
                'tag'     => 'Messaging',
                'summary' => 'Mark a conversation read',
            ],
            'GET /messages/unread-count' => [
                'tag'     => 'Messaging',
                'summary' => 'Total unread messages',
            ],
            'GET /messages/poll' => [
                'tag'         => 'Messaging',
                'summary'     => 'Poll for new messages',
                'description' => 'Real-time means polling at launch, not WebSockets.',
            ],
            'POST /messages/attachments' => [
                'tag'         => 'Messaging',
                'summary'     => 'Upload an attachment',
                'description' => 'Images only — this does not attach a workout or a food plan by reference.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function billing(): array
    {
        return [
            'GET /billing/plans' => [
                'tag'     => 'Billing',
                'summary' => 'Plans available to this member',
            ],
            'GET /billing/subscription' => [
                'tag'         => 'Billing',
                'summary'     => 'Current subscriptions',
                'description' => 'A member holds a **set** of subscriptions — one per trainer, plus the '
                    . 'platform tier. Entitlements merge across all active ones: booleans union, caps take '
                    . 'the **maximum, never the sum** (a 1-trainer and a 2-trainer plan is 2, not 3).',
            ],
            'GET /billing/payments' => [
                'tag'     => 'Billing',
                'summary' => 'Payment history',
            ],
            'POST /billing/checkout' => [
                'tag'         => 'Billing',
                'summary'     => 'Start a checkout',
                'description' => 'The result has **three** states, not two: *redirect* (nothing is active yet '
                    . '— the webhook decides), *settled* (active now), and *failed*. Collapsing the first '
                    . 'two is how a member gets features they have not paid for by abandoning a hosted '
                    . 'checkout page.',
            ],
            'POST /billing/subscription/cancel' => [
                'tag'         => 'Billing',
                'summary'     => 'Cancel at period end',
                'description' => 'Always at period end. The status stays `active` and `cancel_at_period_end` '
                    . 'is set — ending access at the click would take something the member already bought.',
            ],
            'POST /billing/subscription/resume' => [
                'tag'     => 'Billing',
                'summary' => 'Undo a pending cancellation',
            ],
            'POST /billing/subscription/change' => [
                'tag'         => 'Billing',
                'summary'     => 'Switch plan',
                'description' => 'A second plan with the *same* coach replaces the first — two concurrent '
                    . 'subscriptions to one coach is double billing — while plans with *different* coaches '
                    . 'coexist. Downgrading below your current trainer count is **grandfathered**: it '
                    . 'blocks new requests and never severs an existing relationship.',
            ],
            'POST /billing/webhook/{gateway}' => [
                'tag'         => 'Billing',
                'summary'     => 'Gateway webhook',
                'description' => '**The only public write endpoint in the plugin.** Its protection is the '
                    . 'adapter\'s signature check, which runs before anything parses the body. Idempotent on '
                    . 'the gateway\'s own event id, and a duplicate answers **200** rather than 409 — an '
                    . 'error makes the gateway keep retrying. So does an unknown event type.',
                'returns'     => 'Acknowledged. Also returned for duplicates and unrecognised event types.',
                'status'      => 200,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function support(): array
    {
        return [
            'GET /support/faq' => [
                'tag'         => 'Support',
                'summary'     => 'Frequently asked questions',
                'description' => 'Edited in settings rather than hardcoded, so correcting a wrong answer is '
                    . 'not a release.',
            ],
            'GET /support/tickets' => [
                'tag'     => 'Support',
                'summary' => 'The member\'s tickets',
            ],
            'POST /support/tickets' => [
                'tag'     => 'Support',
                'summary' => 'Open a ticket',
            ],
            'GET /support/tickets/{id}' => [
                'tag'         => 'Support',
                'summary'     => 'One ticket with its replies',
                'description' => 'Internal staff notes are never included in a member\'s view.',
            ],
            'PATCH /support/tickets/{id}' => [
                'tag'     => 'Support',
                'summary' => 'Update a ticket',
            ],
            'POST /support/tickets/{id}/replies' => [
                'tag'     => 'Support',
                'summary' => 'Reply to a ticket',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function directory(): array
    {
        return [
            'GET /trainers' => [
                'tag'         => 'Trainer directory',
                'summary'     => 'Browse coaches',
                'description' => 'The **only** place one member reads another account\'s record, so the '
                    . 'projection is deliberate and narrow: no email, no phone, no account id. Carries an '
                    . '`eligibility` block computed server-side, so the button and the endpoint that would '
                    . 'refuse it cannot disagree — `can_request`, `subscription_required` or '
                    . '`limit_reached`.',
            ],
            'GET /trainers/{id}' => [
                'tag'     => 'Trainer directory',
                'summary' => 'A coach\'s public profile',
            ],
            'POST /trainers/{id}/request' => [
                'tag'         => 'Trainer directory',
                'summary'     => 'Ask a coach to take you on',
                'description' => 'Requires an active subscription and respects the merged `max_trainers` cap. '
                    . '**A pending request spends the slot immediately**, which is what stops a one-trainer '
                    . 'plan queueing five requests and keeping whichever lands first. Rate limited by '
                    . 'counting rows, not a cache entry: 3 outstanding and 10 in a rolling week are '
                    . 'statements about requests that exist.',
            ],
            'DELETE /trainers/{id}/request' => [
                'tag'         => 'Trainer directory',
                'summary'     => 'Withdraw a request',
                'description' => 'Gives the slot back.',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function trainer(): array
    {
        $shared = '**Shared read, private write** (Q13): any actively assigned trainer sees the whole client '
            . 'record including co-trainers\' work, with `assigned_by` on every row so one coach\'s '
            . 'programming is never mistaken for another\'s. Only the trainer who created something may '
            . 'change it.';

        return [
            'GET /trainer/dashboard' => [
                'tag'         => 'Trainer',
                'summary'     => 'Clients who need something',
                'description' => 'Leads with people rather than counts. **No money appears anywhere** — '
                    . 'trainers are attributed, not paid, and a figure they cannot act on and are not owed '
                    . 'invites the wrong conversation.',
            ],
            'GET /trainer/profile' => [
                'tag'     => 'Trainer',
                'summary' => 'The trainer\'s own profile',
            ],
            'PUT /trainer/profile' => [
                'tag'         => 'Trainer',
                'summary'     => 'Update the profile',
                'description' => 'Rating and account status are **absent by design** — a rating you can set '
                    . 'is not a rating. `max_clients = 0` means *unconfigured*, never "cannot take '
                    . 'anybody", or a new trainer could never accept a first client.',
            ],
            'GET /trainer/requests' => [
                'tag'     => 'Trainer',
                'summary' => 'Pending client requests',
            ],
            'POST /trainer/requests/{id}/accept' => [
                'tag'         => 'Trainer',
                'summary'     => 'Take on a client',
                'description' => 'Capacity is checked **here**, not when the request was made — it can fill '
                    . 'in between. Accepting also opens the message thread, which is the point at which '
                    . 'both parties are known.',
            ],
            'POST /trainer/requests/{id}/decline' => [
                'tag'     => 'Trainer',
                'summary' => 'Decline a request, with a reason',
            ],
            'GET /trainer/clients' => [
                'tag'         => 'Trainer',
                'summary'     => 'The roster',
                'description' => 'Active relationships by default. `trainer_count` above 1 marks a shared '
                    . 'client, because that is a different programming conversation and the trainer should '
                    . 'know before opening the record.',
            ],
            'GET /trainer/clients/{userId}' => [
                'tag'         => 'Trainer',
                'summary'     => 'A client\'s full record',
                'description' => $shared . ' A client who is not on your roster is a **404**, not a 403 — a '
                    . 'trainer must not be able to enumerate the platform\'s membership by probing ids.',
            ],
            'GET /trainer/clients/{userId}/progress' => [
                'tag'         => 'Trainer',
                'summary'     => 'A client\'s progress charts',
                'description' => 'The member\'s own progress payload, after a roster check — one shape, so '
                    . 'the two renderings cannot drift.',
            ],
            'GET /trainer/clients/{userId}/sessions' => [
                'tag'     => 'Trainer',
                'summary' => 'A client\'s workout history',
            ],
            'GET /trainer/clients/{userId}/nutrition' => [
                'tag'     => 'Trainer',
                'summary' => 'A client\'s nutrition history',
            ],
            'POST /trainer/clients/{userId}/workouts' => [
                'tag'         => 'Trainer',
                'summary'     => 'Assign a workout',
                'description' => 'Assigning into a week another trainer has already programmed returns a '
                    . '**non-blocking** `fc_assignment_conflict` warning naming who, what and when. '
                    . 'Refusing would let one coach\'s programme silently constrain another\'s. Your own '
                    . 'prior assignment is not a conflict — a trainer programming twice in a week is '
                    . 'programming.',
            ],
            'DELETE /trainer/clients/{userId}/workouts/{id}' => [
                'tag'         => 'Trainer',
                'summary'     => 'Unassign a workout',
                'description' => 'Only the trainer who **made** the assignment may take it back, keyed on '
                    . 'the assigning account rather than on who authored the workout — two trainers may '
                    . 'both assign the same shared workout to the same client. A co-trainer\'s assignment '
                    . 'is a **403**, not a 404: shared read already told you it exists, and pretending '
                    . 'otherwise would be theatre.',
            ],
            'POST /trainer/clients/{userId}/food-plans' => [
                'tag'     => 'Trainer',
                'summary' => 'Assign a food plan',
            ],
            'GET /trainer/clients/{userId}/notes' => [
                'tag'         => 'Trainer',
                'summary'     => 'Your private notes on a client',
                'description' => 'Private to you. A co-trainer\'s note is a **404** rather than a 403, '
                    . 'because its existence is itself the private thing (Q15).',
            ],
            'POST /trainer/clients/{userId}/notes' => [
                'tag'     => 'Trainer',
                'summary' => 'Write a private note',
            ],
            'PUT /trainer/notes/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Edit a note',
            ],
            'DELETE /trainer/notes/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Delete a note',
            ],
            'GET /trainer/workouts' => [
                'tag'         => 'Trainer',
                'summary'     => 'The trainer\'s workout library',
                'description' => 'Includes the platform library (`trainer_id` null), which every trainer may '
                    . 'assign but none may edit — otherwise the builder looks empty on day one.',
            ],
            'POST /trainer/workouts' => [
                'tag'         => 'Trainer',
                'summary'     => 'Create a workout',
                'description' => 'Authorship is stamped from the session. A `trainer_id` in the body is '
                    . 'ignored, so nobody can plant work in someone else\'s library.',
            ],
            'GET /trainer/workouts/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'One of the trainer\'s workouts',
            ],
            'PUT /trainer/workouts/{id}' => [
                'tag'         => 'Trainer',
                'summary'     => 'Update a workout and its exercises together',
                'description' => 'Metadata and exercises save in **one** request: two can half-succeed and '
                    . 'leave a workout whose name says one thing and whose contents say another. Exercise '
                    . 'rows are **upserted by id** — rows with an id are updated, rows without are '
                    . 'inserted, and only what the payload dropped is deleted. Delete-and-reinsert would '
                    . 'silently orphan every past session\'s link to the movement it recorded. Order is '
                    . 'the array order, so reordering needs no separate call.',
            ],
            'PUT /trainer/workouts/{id}/exercises' => [
                'tag'         => 'Trainer',
                'summary'     => 'Replace a workout\'s exercise list',
                'description' => 'Same upsert-by-id rule as the combined update. Prefer `PUT '
                    . '/trainer/workouts/{id}`, which saves metadata and exercises atomically.',
            ],
            'DELETE /trainer/workouts/{id}' => [
                'tag'         => 'Trainer',
                'summary'     => 'Delete a workout',
                'description' => 'Refused with a reason (409) if sessions have been logged against it.',
            ],
            'GET /trainer/plans' => [
                'tag'     => 'Trainer',
                'summary' => 'The trainer\'s coaching plans',
            ],
            'POST /trainer/plans' => [
                'tag'         => 'Trainer',
                'summary'     => 'Create a coaching plan',
                'description' => '`features` and `max_trainers` are **read-only**: those decide '
                    . 'platform-wide entitlements, and a trainer granting themselves `has_video_workouts` '
                    . 'would be selling something the platform never agreed to. An empty price tier stores '
                    . '**null** (not offered), not 0 (free).',
            ],
            'PUT /trainer/plans/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Update a plan',
            ],
            'DELETE /trainer/plans/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Delete a plan',
            ],
            'GET /trainer/food-plans' => [
                'tag'     => 'Trainer',
                'summary' => 'The trainer\'s food plans',
            ],
            'POST /trainer/food-plans' => [
                'tag'         => 'Trainer',
                'summary'     => 'Create a food plan',
                'description' => 'The plan and its metadata only — **the per-meal editor is not built**, so '
                    . '`fc_food_plan_meals` has no endpoints yet.',
            ],
            'PUT /trainer/food-plans/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Update a food plan',
            ],
            'DELETE /trainer/food-plans/{id}' => [
                'tag'     => 'Trainer',
                'summary' => 'Delete a food plan',
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function admin(): array
    {
        $resources = '`users`, `trainers`, `workouts`, `foods`, `meals`, `plans`, `subscriptions`, '
            . '`payments`, `tickets`, `health-entries`';

        return [
            'GET /admin/dashboard' => [
                'tag'     => 'Admin',
                'summary' => 'Platform overview',
            ],
            'GET /admin/resources' => [
                'tag'         => 'Admin',
                'summary'     => 'The resource registry',
                'description' => 'The server\'s own registry — what each resource allows, sorts and filters '
                    . 'by — so an admin UI is built from it rather than from a second hardcoded list that '
                    . 'can drift.',
            ],
            'GET /admin/settings' => [
                'tag'     => 'Admin',
                'summary' => 'Settings',
            ],
            'PUT /admin/settings' => [
                'tag'         => 'Admin',
                'summary'     => 'Update settings',
                'description' => 'Only whitelisted keys are writable — the options row is a single JSON blob, '
                    . 'so accepting arbitrary keys would let a client typo write a permanent orphan into '
                    . 'it. Changing the app URL re-flushes permalinks on the next request.',
            ],
            'GET /admin/{resource}' => [
                'tag'         => 'Admin',
                'summary'     => 'List any resource',
                'description' => "`{resource}` is one of {$resources}. Search, filter, sort and paging are "
                    . 'all done in SQL. **`sort` names a sort key, not a column** — the registry decides '
                    . 'what SQL that means, and an unknown key falls back to the default rather than '
                    . 'reaching the query.',
            ],
            'POST /admin/{resource}' => [
                'tag'         => 'Admin',
                'summary'     => 'Create a resource',
                'description' => "One of {$resources}.",
            ],
            'GET /admin/{resource}/{id}' => [
                'tag'         => 'Admin',
                'summary'     => 'Read one resource',
                'description' => "One of {$resources}.",
            ],
            'PUT /admin/{resource}/{id}' => [
                'tag'         => 'Admin',
                'summary'     => 'Update a resource',
                'description' => "One of {$resources}. Writes to a member's health or nutrition data are "
                    . 'stamped with `source: admin` and the editing account, write an audit row carrying '
                    . 'only the fields that actually moved, and re-run the same derivations a member\'s own '
                    . 'edit would (Q10).',
            ],
            'DELETE /admin/{resource}/{id}' => [
                'tag'         => 'Admin',
                'summary'     => 'Delete a resource',
                'description' => "One of {$resources}. Deletes that would orphan live data are refused with "
                    . 'a reason (409): a trainer with active clients, a workout with logged sessions, a '
                    . 'food that appears in members\' diaries.',
            ],
        ];
    }
}
