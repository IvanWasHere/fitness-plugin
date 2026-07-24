# 03 — Backend Implementation

## Layering

```
Route (api/fitnessclub/v1/*.php)
  └─ permission_callback  → Guard: capability + resource ownership
  └─ args schema          → validation + sanitisation (WP does the work)
       └─ RestController  → thin: unpack request, call service, present
            └─ Service    → business rules, transactions, events  ← all logic here
                 └─ Repository → DB::table() (simple)  |  $wpdb->prepare() (joins)
                      └─ Model  → table name, casts, column list
            └─ Presenter  → row/DTO → API shape
```

**Controllers contain no business logic and no SQL.** If a controller method is
longer than ~15 lines it is doing a service's job. This is the rule that keeps the
same logic usable from the REST API, WP-CLI commands, and cron jobs — the
subscription-expiry cron and the checkout webhook must run *identical* code.

### Data access: two tools ([D5](00-architecture.md#d5--data-access-wpbones-dbtable-for-the-simple-path-raw-wpdb-for-joins))

wpBones' `DB::table()` builder is a **light version with no `join()`** (confirmed
in the query-builder docs). So Repositories use:

- **`DB::table('fc_x')->where(...)->get()/first()/insert()/update()`** for simple
  single-table reads and writes (prefix applied automatically).
- **Raw `$wpdb->prepare()`** for joins, `GROUP BY` and date-range rollups —
  interpolating only `{$wpdb->prefix}`-style table names, never request values.
  The `Guard` and `ProgressService` are join-heavy and use this path.

The no-raw-SQL lint gate (below) is written to permit exactly this: placeholdered
`$wpdb` with prefix-interpolated table names, and to reject any value interpolated
into SQL.

**Options** are read through the wpBones model, not `get_option()`:
`FitnessClub()->options->get('routing.app_base', 'fitness')`.

## Roles & capabilities

Registered in `RoleProvider` on activation; removed on uninstall (not deactivation
— deactivating a plugin should not strip user roles).

```php
'fc_user' => [
  'read' => true,
  'fc_access_app', 'fc_log_workouts', 'fc_log_nutrition', 'fc_log_health',
  'fc_view_own_stats', 'fc_message_trainer', 'fc_manage_own_subscription',
  'fc_create_tickets',
],
'fc_trainer' => [
  'read' => true,
  'fc_access_trainer_app', 'fc_manage_clients', 'fc_create_plans',
  'fc_create_workouts', 'fc_create_food_plans', 'fc_assign_plans',
  'fc_view_client_stats', 'fc_message_clients', 'fc_handle_tickets',
],
// administrator additionally gets:
'fc_manage_all', 'fc_manage_trainers', 'fc_manage_users', 'fc_manage_payments',
'fc_manage_themes', 'fc_manage_settings', 'fc_view_all_stats', 'fc_impersonate',
'fc_edit_user_health',   // Q10: admins MAY edit users' health/nutrition rows
```

Mapping to `plan.md` §3.2's matrix — with the corrections the matrix needs:

| Capability | Admin | Trainer | User |
|---|---|---|---|
| Manage users | ✓ | ✓ assigned only | ✗ |
| Create plans | ✓ *(spec says ✗ — wrong: §13 requires admin plan management)* | ✓ | ✗ |
| Assign plans | ✓ | ✓ own plans | ✗ |
| View progress | ✓ | ✓ assigned | ✓ own |
| Send messages | ✓ | ✓ assigned | ✓ own trainers, quota'd |
| Log workouts/nutrition/health | ✗ | ✗ | ✓ entitlement-gated |
| Manage payments | ✓ | ✗ | view own |
| Themes / settings | ✓ | ✗ | ✗ |

### Capability is not authorisation

Every trainer and user endpoint runs a second, resource-level check. This is the
most common security failure in plugins of this shape:

```php
final class Guard
{
    // Passes for ANY active assignment — a client may have several trainers (Q3),
    // and every one of them may read that client's progress.
    // A JOIN, so raw $wpdb — the wpBones builder has no join() (D5).
    public static function trainerCoachesClient(int $trainerWpUserId, int $userId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ut.id
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = ut.trainer_id
              WHERE t.wp_user_id = %d AND ut.user_id = %d AND ut.status = 'active'
              LIMIT 1",
            $trainerWpUserId, $userId
        ));
    }

    // Stricter: only the trainer who created/assigned the resource may modify it.
    public static function trainerAssignedResource(
        int $trainerWpUserId, string $table, int $resourceId
    ): bool { … }

    public static function ownsSession(int $wpUserId, int $sessionId): bool { … }
    public static function ownsThread(int $wpUserId, int $threadId): bool { … }
}
```

The two trainer guards are **not interchangeable**, and Q13 fixed the line between
them:

| Operation | Guard | Scope |
|---|---|---|
| Read anything about an assigned client — progress, sessions, health, nutrition, **and other trainers' assignments and plans** | `trainerCoachesClient` | any active assignment |
| Write plans, workout assignments, food plans | `trainerAssignedResource` | the assigning trainer only |
| Read or write client notes | neither — owner check on `fc_client_notes.trainer_id` | that trainer only (Q15) |

Picking the looser guard for a write endpoint is the specific bug multi-trainer
support introduces — call it out in review, and keep the 403-on-foreign-id test
for every write.

A `permission_callback` that only calls `current_user_can('fc_manage_clients')`
lets any trainer read every user's health data by changing an id. Enumerate the
ownership rule for every `{id}` in the contract and assert it before the service
runs. Add an integration test per endpoint that asserts 403 for a foreign id —
this is the test suite's highest-value section.

## Authentication

### Cookie + nonce (primary)

Assets are enqueued with a boot object:

```php
wp_localize_script('fc-user-app', 'FC_BOOT', [
    'restUrl'  => esc_url_raw(rest_url('fitnessclub/v1/')),
    'nonce'    => wp_create_nonce('wp_rest'),
    'user'     => $bootPresenter->forCurrentUser(),
    'theme'    => $themeService->activeTokens(),
    'features' => $entitlements->all(),
    'locale'   => get_user_locale(),
]);
```

The nonce is valid ~12–24 h. A SPA left open overnight will start getting
`403 rest_cookie_invalid_nonce`. **Handle it explicitly**: the API client
intercepts that code, calls `GET /wp/v2/users/me?_wpnonce=` refresh (or a small
`/auth/nonce` endpoint), retries once, and only then surfaces a re-login prompt.
Skipping this produces the classic "app silently stops saving" bug.

### JWT (Phase 4, external clients only)

```php
add_filter('determine_current_user', function ($user) {
    if (!empty($user)) return $user;
    $token = TokenExtractor::fromAuthorizationHeader();
    if (!$token) return $user;
    return JwtService::resolveUserId($token) ?: $user;   // never throw here
}, 20);
```

Corrections to `plan.md` §14.1:

- **Secret in `wp-config.php`** (`define('FC_JWT_SECRET', …)`), not
  `get_option('wn_jwt_secret')`. An option lives in a database that gets dumped,
  shared, and restored into staging; a compromised DB read should not mint tokens.
  Fall back to an auto-generated option only if the constant is absent, and warn
  in Site Health.
- **HS256 with explicit algorithm pinning.** Reject `alg: none` and any algorithm
  other than the configured one — the canonical JWT vulnerability.
- **15-minute access token + 30-day refresh token**, not the spec's 24 h access
  token. A 24 h bearer token with no revocation is a 24 h window after theft.
- **Refresh tokens are stored hashed** in user meta with a jti, so logout and
  "sign out everywhere" actually revoke.
- Include `iss`, `aud`, `iat`, `exp`, `jti`; validate all of them.
- Disabled by default; enabled per-site in Settings → API.

Use `firebase/php-jwt` via Composer — do not hand-roll signing.

## Service providers

Registered in `config/plugin.php → providers`, booted on `init`:

| Provider | Owns |
|----------|------|
| `RewriteServiceProvider` | The front-end URL ([D9](00-architecture.md#d9--front-end-routing-configurable-app-url)). On `init`: reads `routing.app_base` from the options model, registers `add_rewrite_rule('^{base}(/(.*))?/?$', …)` + the `fc_app`/`fc_app_path` query vars. On `template_redirect`: when `fc_app` is set, resolve the user's role → pick the SPA → render the standalone Blade shell (boot payload + that SPA's Vite manifest tags) → `exit`. Hooks `update_option_fitnessclub` + activation to `flush_rewrite_rules()` |
| `RoleProvider` | Roles + capabilities (below); install on activation, remove on uninstall |
| `UpgradeProvider` | Runs the schema-version dispatcher on `init` (migrations having applied on activation) |
| `ShortcodeProvider` | The optional `[fitnessclub_app]` embed |
| `ScheduleProvider` | Registers the cron jobs (below) |

The **role → SPA** resolution lives in `RewriteServiceProvider` (or a small
`AppRouter` support class it calls): `administrator` → admin SPA, `fc_trainer` →
trainer SPA, else user SPA; unauthenticated → user SPA (login screen). Precedence
admin > trainer > user for multi-role accounts (Q17c).

## Services

| Service | Owns |
|---------|------|
| `WorkoutSessionService` | The state machine. Start/pause/resume/cursor/set/complete/abandon, elapsed-time authority, progress %, PR detection, activity + notification emission |
| `EntitlementService` | Resolves active subscription → plan `features` JSON → boolean checks. Every gated action asks it |
| `SubscriptionService` | Create/change/cancel/resume, proration, expiry, grace period |
| `PaymentService` + gateways | Checkout sessions, webhook handling, refunds, reconciliation |
| `MessageQuotaService` | Rolling 7-day count, remaining, reset time — **per trainer thread**, not per user (Q3) |
| `ProgressService` | Range bucketing, streaks, volume, consistency, all chart series |
| `NutritionService` | Meal totals recompute, day rollup, water, goal snapshots |
| `HealthService` | Upsert-by-date, BMI computation, `fc_users.weight_kg` sync |
| `NotificationService` | Create + fan-out, respects per-user preference toggles |
| `ActivityService` | Feed writes + audit trail |
| `ThemeService` | Discovery, validation, merge with defaults, CSS var emission |
| `AssignmentService` | Trainer↔user request lifecycle (request/accept/decline/withdraw, **subscription + `max_trainers` gate**, trainer capacity, single primary), workout↔user, food-plan↔user, assignment-conflict detection |
| `TrainerDirectoryService` | Public trainer projection, availability filtering, request rate limits |

### `WorkoutSessionService` — the piece to get right

```
start(userId, workoutId)
  ├─ 409 if an in_progress|paused session exists (return it so the UI can offer resume)
  ├─ transaction: INSERT session, snapshot planned sets/reps/weight into fc_exercise_logs
  └─ mark fc_user_workouts.status = in_progress

pause(sessionId)
  └─ duration_seconds += (now - last_resumed_at); last_resumed_at = NULL; status = paused

resume(sessionId)
  └─ paused_seconds += (now - paused_at); last_resumed_at = now; status = in_progress

logSet(sessionId, exerciseId, setIndex, payload)
  ├─ upsert fc_set_logs (idempotent on the triple)
  ├─ recompute fc_exercise_logs actuals + total_volume_kg
  └─ recompute session completion_percentage

complete(sessionId)
  ├─ finalise duration; calories = f(duration, workout.type, user.weight)
  ├─ detect PRs vs fc_personal_records; upsert winners
  ├─ update fc_user_workouts (progress 100, times_completed++)
  ├─ emit activity + notification; recompute streak
  └─ return the celebration payload
```

Every transition is a transaction. Elapsed time is **always** derived from stored
timestamps — a client that reports 9 hours must not produce a 9-hour session.

Stale-session cleanup: a daily cron marks `in_progress` sessions older than 24 h
as `abandoned` (users close the tab mid-workout constantly).

## Entitlements

```php
if (!$entitlements->can('can_log_nutrition')) {
    return $this->responseError(
        'fc_feature_unavailable',
        __('Nutrition logging is not included in your plan.', 'fitnessclub'),
        403
    ); // + data.required_feature so the UI renders an upgrade CTA
}
```

Resolution order: **all active subscriptions** → each plan's `features` JSON →
merged → layered over platform defaults from `config/fitnessclub.php`. Cached
per-request; invalidated on any subscription write. When no subscription is
active, the free-tier defaults apply — never "deny everything", which locks users
out during a failed renewal.

### Merging across multiple subscriptions

A user may be coached by several trainers simultaneously (Q3), holding one
subscription per trainer. `EntitlementService` merges the set:

| Flag kind | Rule | Why |
|---|---|---|
| Booleans | **Union** — any plan granting it wins | Never revoke a feature the user is paying for on another plan |
| Numeric caps (`max_active_workouts`, `max_trainers`, …) | **Max** across active plans | Same reasoning. Caps are a ceiling, **not** a per-plan allowance that accumulates — a 1-trainer plan plus a 2-trainer plan gives 2, not 3 |
| `max_messages_per_week` | **Per-trainer** — resolved from the subscription whose `trainer_id` matches the thread | A global cap would let one conversation eat another's allowance |
| Nothing active | Free-tier defaults (Q6) | Fail open to the floor, not closed |

```php
$entitlements->can('can_log_nutrition');              // union across all active subs
$entitlements->limit('max_active_workouts');          // max across all active subs
$entitlements->limit('max_trainers');                 // max; counts pending requests
$entitlements->messageQuotaFor($trainerId);           // per-trainer, NOT global
$entitlements->hasActiveSubscription();               // gate for trainer requests (Q14)
```

**Caps grandfather on downgrade.** `EntitlementService` reports the limit; it
never retroactively enforces it. If a user drops to a plan with `max_trainers: 1`
while holding two, both relationships survive and only *new* requests are blocked
(Q16). The same principle applies to any cap: a limit constrains future actions,
never destroys existing state. Auto-severing a coaching relationship because a
card expired generates churn and punishes a trainer who did nothing wrong.

Unit-test the overlapping-plan matrix explicitly: two active plans with opposing
booleans, one lapsing mid-week, a downgrade that should *not* revoke a feature
still granted elsewhere. Feature flicker as subscriptions lapse is the predictable
bug here, and it is invisible until a user has two trainers.

## Rate limiting

Transient token bucket, keyed `fc_rl_{bucket}_{userId|ipHash}`:

```php
RateLimiter::hit('login', $ipHash, limit: 5, window: 60);   // throws RateLimitException → 429
```

Honest caveat to record: on a site with an object cache, transients are in
Memcached/Redis and this works well. **Without one, transients hit the options
table on every request**, and a rate limiter that writes to `wp_options` on every
API call is itself a performance problem. Detect `wp_using_ext_object_cache()`;
if false, fall back to a coarser limit and a dedicated lightweight table. Do not
pretend the naive version scales.

IPs are hashed with a site salt before storage (GDPR: a raw IP is personal data).

## Scheduled jobs

Registered via wpBones' schedule provider (`config/plugin.php` → `schedules`).

| Job | Cadence | Work |
|-----|---------|------|
| `SubscriptionExpiry` | hourly | expire past `end_date`, apply `cancel_at_period_end`, notify T-7/T-1 |
| `RetryFailedPayments` | daily | dunning: retry, then `past_due`, then suspend |
| `WorkoutReminders` | hourly | per-user local-time reminders, respects preferences |
| `StaleSessionCleanup` | daily | abandon sessions > 24 h |
| `StaleRequestExpiry` | daily | auto-decline trainer requests pending > N days, notify the user (Q4) |
| `StreakRecalculation` | daily 00:15 UTC | recompute + "streak at risk" notifications |
| `ActivityPrune` | weekly | drop `fc_activity_log` > 12 months |
| `PrRebuild` | manual/WP-CLI | rebuild `fc_personal_records` from set logs |

WP-Cron only fires on traffic. Document that production should use a real system
cron hitting `wp-cron.php` with `DISABLE_WP_CRON` set — otherwise "expire at
midnight" means "expire when someone next visits".

## Security checklist (§15)

- [ ] Every write endpoint: capability check **and** ownership check
- [ ] Every `args` schema declares `type`, `required`, `sanitize_callback`, `validate_callback`
- [ ] All SQL through `DB::table()` builder or `$wpdb->prepare()`. **Zero** string-interpolated queries. Enforce with a PHPCS sniff in CI
- [ ] All output escaped in Blade views; React escapes by default — audit any `dangerouslySetInnerHTML` (the theme CSS injection is the one legitimate case, and it is emitted from a whitelist, not from user JSON)
- [ ] Uploads: extension + MIME + `getimagesize()` validation, re-encode images, store in a plugin-scoped uploads subdir with `index.php` guards
- [ ] Health/nutrition data is health data — treat exports and the impersonation tool as sensitive; audit-log both
- [ ] Nonce on every admin form; REST nonce on every embedded call
- [ ] `fc_manage_settings` gated on `manage_options`, never on a custom cap alone
- [ ] No PII in logs. Log user *ids*, not emails or health values
- [ ] Uninstall (`uninstall.php`): remove roles, options, transients; ask before dropping tables — default to keeping data
- [ ] GDPR: register WP's `wp_privacy_personal_data_exporters` and `_erasers` hooks so the built-in export/erase tools cover `fc_*` tables

## Theme JSON is untrusted input

The theme editor accepts JSON that becomes CSS. Do **not** interpolate values into
a stylesheet. `ThemeService` validates against a schema — each key has an expected
type and pattern (`colors.*` must match `#rgb|#rrggbb|rgb()/rgba()`, sizes must
match `\d+(px|rem|em|%)`) — drops unknown keys, and emits only whitelisted
properties. Otherwise `"primary": "red; } body { background: url(//evil/x.png"` is
CSS injection with an admin-only entry point and a site-wide blast radius.

## Testing

| Layer | Tool | Priority |
|-------|------|----------|
| Services | PHPUnit + Brain Monkey | **highest** — session state machine, entitlements, quota, proration |
| REST endpoints | `WP_UnitTestCase` REST harness | high — one auth-failure test per endpoint |
| Repositories | integration against a test DB | medium |
| React components | Jest + RTL (wpBones ships Jest config) | medium — player, charts |
| E2E | Playwright | the two critical journeys: signup→subscribe→first workout; trainer→assign→client sees it |

Coverage targets: services ≥ 85 %, controllers ≥ 60 %, UI smoke-level. Chasing a
uniform number across a codebase that is largely presentational wastes effort.

Add a CI job asserting **migrations apply cleanly to an empty DB and to the
previous release's DB** — the upgrade path is what breaks in production, and it
is invisible to every other test.
