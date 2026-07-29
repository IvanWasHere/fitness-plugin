# 08 — Roadmap

Four phases, mapped to `plan.md` §18 but resequenced: the spec puts the theme
system in Phase 2 and support tickets in Phase 3, while leaving auth screens and
settings unscheduled entirely. Corrected below.

Estimates are one full-stack developer, in dev-days, excluding design, QA cycles,
and payment compliance review.

> ## ⟳ Reset — read this first (2026-07-24)
>
> The earlier hand-rolled implementation (migrations, base classes, `RoleProvider`,
> `Guard`, seeders, PHPCS/PHPUnit/CI) was **deleted**, and the plugin folder now
> holds a **fresh `WPKirk-Boilerplate`**. What survives: **`plans/`**, the two
> **prototypes**, and the **29 `wp_fc_*` tables** still in the dev database.
>
> So the "✅ done" marks in Phase 0 / W1.1 / W1.2 below describe work that no longer
> exists on disk — treat them as **"proven once, to be redone on the boilerplate."**
> The schema is validated and the migration/Guard/seeder designs are known-good, so
> redoing them is transcription, not rediscovery. The build notes at the end of this
> doc are kept as a record of what was learned (the `functions.php` CLI-exit trap,
> the dbDelta rules, the activation-ordering fix), all of which still apply.
>
> Two architectural shifts landed with the reset (see [00](00-architecture.md)):
> the plugin is now explicitly **two sections** — a wpBones backend + **three Vite
> React SPAs** ([D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas)) —
> and the front-end lives at a **configurable URL** served by role
> ([D9](00-architecture.md#d9--front-end-routing-configurable-app-url)), not a
> shortcode.

---

## Phase 0 — Foundation (3–4 d)

Starting from the **fresh boilerplate** now in place.

| # | Work | Done when |
|---|------|-----------|
| 0.1 | **Rename** WPKirk→FitnessClub the native way: stash `plans/`+prototypes, set `namespace` file, `composer install` (runs `bones rename`), restore; flip `config/api.php` basic-auth **off** | Plugin boots as FitnessClub; `GET /wp-json/fitnessclub/v1/health` → 200 |
| 0.2 | `config/{plugin,menus,api,options,fitnessclub}.php` — providers, options model (incl. `routing.app_base`), domain constants | Config reads back via `$plugin->options`/`$plugin->config` |
| 0.3 ✅ | **Vite `ui/` workspace** — three entries (`user`/`trainer`/`admin`) + `src/shared/`, `vite.config.ts` manifest mode, dev-server config | **Done 2026-07-25** (Vite 8 + React 18, 0 npm vulns): `npm run build` → `public/ui/` + `.vite/manifest.json`, 3 isolated entries + 1 shared React chunk; `npm run dev` HMR verified at :5173 |
| 0.4 ✅ | `RewriteServiceProvider` + Blade shell — `/{base}` renders the role's SPA from the manifest | **Done 2026-07-25**: `GET /fitness/` → 200 standalone shell; unauth → user SPA, admin → admin SPA; `ViteAssets` reads the manifest (prod) or dev server; boot payload carries base/nonce/role/spa. `AppRouter` precedence admin>trainer>user |
| 0.5 ✅ | PHPCS (no-raw-SQL gate), PHPUnit harness (integration vs real WP+MySQL), ESLint/Prettier for `ui/` | **Done 2026-07-25**: `composer check` = phpcs (0 err) + phpunit 15/15; `ui/` lint+format+typecheck+build green. See build note on the `functions.php` guard + the JS audit exception |
| 0.6 | GitHub Actions: PHP lint/test matrix (8.1–8.4), migration-idempotency job, `ui/` build+lint job | Green on an empty PR |
| 0.7 | `git init`; local WP env note (`wp-env` or the existing dev site) | reviewable diffs |

**Exit:** a renamed plugin that activates, exposes the versioned REST namespace,
serves an (empty) role-selected SPA at the configured URL, and has CI.

---

## Phase 1 — Core (20–25 d)

The spine. Nothing after this phase is architecturally interesting.

### W1.1 Database (4 d) — ✅ **complete (rebuilt on the boilerplate 2026-07-25)**
All 29 migrations, named `YYYYMMDD_HHMMSS_*`, idempotent. Includes
`fc_user_trainers.is_primary` and the Q10 provenance columns (`source='admin'`,
`last_edited_by_wp_user_id`) on health and nutrition. The `fitnessclub_db_version`
upgrade dispatcher runs from `UpgradeProvider` on `init` (*not* `activation.php`,
which executes before any table exists). Demo seeder reproducing the prototype
data exactly; volume seeder for perf profiling.

**Done 2026-07-25 (on the fresh boilerplate):**
- `FitnessClub\Database\Migration` base (guarded FKs, InnoDB enforcement,
  idempotency helpers) + 29 migrations → **29 tables, 35 FKs, 94 indexes, all
  InnoDB**; idempotent on reactivate (verified).
- `Database\Upgrade\Manager` + `UpgradeProvider` (schema version option = 1).
- **Essential platform tiers** via wpBones' *native* `Seeder`
  (`database/seeders/0100_platform_plans.php`), idempotent, on activation.
- **Demo + volume seeders** (`plugin/Database/Seeders/`), invoked by a **WP-CLI**
  command `wp fitnessclub seed --demo|--volume=N:D|--purge-volume`.
- **Why WP-CLI, not a `php bones` command:** bones does *not* bootstrap WordPress
  for custom kernel commands (only for `tinker`/`deploy`), so a `$wpdb`-dependent
  seeder can't run under it. WP-CLI loads the full runtime; `CliServiceProvider`
  registers the command. The bones Console/Kernel stays for scaffolding-type
  commands.
- Verified: demo seed idempotent; Alex coached by Sarah (primary) + Mike (Q3);
  all trainer links within plan `max_trainers` (Q14); volume 10 k sessions hit
  `idx_weekly`; purge removes only synthetic rows. Gate: phpcs 0 errors,
  phpunit 24/24 (incl. `SchemaTest`).

*Done when:* activate → 29 tables; deactivate/reactivate → no errors, no data loss;
seeder produces the prototype's exact dataset — including Alex Morgan assigned to
**both** Sarah Chen and Mike Torres, so multi-trainer paths are exercised from
day one rather than discovered in Phase 3.

> ### ⟳ W1.2R / W1.3R — Plugin-owned identity, 2026-07-27
>
> **W1.2 and W1.3 below describe a WordPress-backed identity that no longer
> exists.** Roles, capabilities, `wp_signon`, the auth cookie and the `wp_rest`
> nonce were all replaced by `fc_accounts` + `fc_sessions` + the plugin's own
> CSRF token. A WordPress administrator has no access to the app without a plugin
> account. The `Guard` work in W1.2 survives unchanged — it never called a
> WordPress identity function — and only the id it is handed changed name.
>
> The decision and its costs are recorded as
> [D4a](00-architecture.md#d4a--identity-the-plugin-owns-its-accounts-supersedes-half-of-d4-2026-07-27);
> the schema is in [01](01-database.md#identity--relationships); the build notes
> are at the end of this document. Read those, not the two sections below, for
> how auth works today.

### W1.2 Roles, capabilities, Guard (2 d) — ✅ done
`RoleProvider`, capability constants (including `fc_edit_user_health` for admins —
Q10), `Guard` with `trainerOwnsClient` / **`trainerAssignedResource`** /
`ownsSession` / `ownsThread` / `ownsResource`. Uninstall cleanup.

*Done when:* a test asserts every ownership rule returns false for a foreign id,
**and** that a second trainer assigned to the same client can read their progress
but cannot edit the first trainer's assignments (Q3).

**Build notes.** `RoleProvider` installs `fc_user` (9 caps) and `fc_trainer` (10
caps), and grants admins 12 `fc_*` caps including `fc_edit_user_health` (Q10);
verified on the live install. `Guard` implements the two-guard split — the read
guard `trainerCoachesClient()` (any active assignment) and the strict write guard
`trainerAssignedResource()` (assigning trainer only), each resolving the internal
id from a WP user id, joining via raw `$wpdb` (D5) with `{$wpdb->prefix}` table
names and placeholder-prepared values (phpcs: **0 errors** on the request path).
`activation.php` runs `RoleProvider::install()`; `uninstall.php` tears roles down
and preserves user data unless `FITNESSCLUB_REMOVE_ALL_DATA` is set.

*Exit criterion met* by `tests/Integration/GuardTest.php` (18 tests, 81
assertions) against the seeded multi-trainer fixture: Mike Torres (Alex's second
trainer, Q3) **can read** the shared client but **cannot write** Sarah's
`upper-body-power` workout (Q13 boundary); declined/pending links grant nothing; a
trainer is not a session owner; and every guard returns false for id 0 / negative
/ nonexistent. Full gate: **42 tests, 116 assertions green.**

### W1.3 Auth + boot (3 d) — ✅ **complete 2026-07-25**
Cookie/nonce plumbing, **nonce-expiry refresh-and-retry in the API client**,
`GET /auth/me`, login/register/forgot/reset endpoints, the **role→SPA resolution**
in `RewriteServiceProvider`, the Blade shell + Vite-manifest enqueue, boot payload.
Rate limiter (with the object-cache detection from [03](03-backend.md#rate-limiting)).

*Done when:* an unauthenticated visitor to `/{base}` sees the login panel; a
logged-in `fc_user` gets the user SPA, a trainer the trainer SPA, an admin the
admin SPA — each an empty shell with their name and theme applied.

**Build notes.**

- **Endpoints** (`Http/Controllers/Api/AuthController`, full `args` schemas in
  `api/fitnessclub/v1/routes.php`): `login`, `register`, `logout`, `me`,
  `password/forgot`, `password/reset`. Public routes are guarded by
  `requireGuest()` rather than `__return_true`, so a call made *with* a session
  cannot silently swap users — except `password/reset`, where the **key** is the
  authorisation and someone still signed in on that browser must be able to
  follow the link they were emailed (the SPA shows the reset form over the
  signed-in shell for the same reason). Login and forgot are deliberately flat — a wrong
  password, an unknown email and an unknown username return the identical
  `fc_invalid_credentials`, and forgot answers the same 200 either way, so
  neither endpoint is an account-enumeration oracle. Registration cannot hide a
  taken address, so it says so plainly (`fc_email_taken`, 409). Login and
  register return **the boot payload**, not a token: the app goes from the login
  panel to the dashboard with no second round trip.
- **The nonce-after-login trap.** `wp_set_auth_cookie()` sends the cookie but
  never populates `$_COOKIE`, and `wp_create_nonce()` reads the session token out
  of `$_COOKIE`. A nonce minted in the login response is therefore signed against
  the *anonymous* session and every subsequent call 403s.
  `AuthServiceProvider` bridges the cookie on `set_logged_in_cookie` (and clears
  it on `clear_auth_cookie`). Verified over real HTTP, not only in PHPUnit: login
  → `/auth/me` with the returned nonce → 200.
- **Nonce refresh is admin-ajax, not REST** (`Ajax\NonceProvider`, action
  `fc_nonce`). A `GET /auth/nonce` cannot work: `rest_cookie_check_errors()`
  rejects the whole request when a cookie-authenticated caller sends a stale
  nonce, and dropping the header instead makes WordPress treat the caller as
  anonymous — the route would mint a nonce for user 0. admin-ajax authenticates
  from the cookie with no nonce of its own, which is what core's Heartbeat uses.
  `ApiClient` intercepts `rest_cookie_invalid_nonce`, refreshes (one shared
  in-flight refresh for a burst), retries **once**, then surfaces re-login.
- **Rate limiter** (`Support/RateLimiter` + `RateLimitException`): fixed-window
  buckets `fc_rl_{bucket}_{userId|ipHash}`, IPs hashed with the site salt (a raw
  IP is personal data). Honest split on the object-cache caveat — the targeted
  buckets (login 5/min/IP, register 5/h/IP, forgot 3/h per address *and* 9/h per
  IP, reset-confirm 10/h/IP) always run; the blanket 100/10-per-minute bucket in
  `ApiServiceProvider` only engages when `wp_using_ext_object_cache()`, because a
  per-request limiter writing to `wp_options` is the problem it claims to solve.
  `shouldThrottleEveryRequest()` reports that decision rather than implying a
  protection that is not there. The dedicated lightweight table that would lift
  the restriction is deferred — it needs a migration and a pruning job. 429s
  carry `limit`, `resets_at`, `retry_after` and a `Retry-After` header.
- **Boot payload** (`Services/BootPresenter`): one object, two carriers —
  `me()` is the `/auth/me` body, `shell()` is the same plus `restUrl`/`nonce`/
  `ajaxUrl`/`brand`/`locale`/`flags` for `data-boot`. Built in one place so the
  app cannot paint one thing and then re-render into another.
  `Services/EntitlementService` implements the Q3 merge (booleans union, caps
  max, `max_messages_per_week` per trainer, free-tier floor when nothing is
  active); W1.4 adds the consumption side. `Services/ThemeService` merges
  bundled ← selected ← overrides and emits `--fc-*` custom properties scoped to
  `#fc-app`, inlined in the shell (<2 KB — a request would cost more).
- **Front end** (`ui/src/shared/`): `api.ts` (client + `ApiError` with stable
  `fc_*` codes), `session.tsx`/`session-context.ts` (session state, starts
  *resolved* — no `/auth/me` on mount), `AuthPanel.tsx` (login/register/forgot/
  reset; hand-rolled view switching until react-router lands in W1.5, with
  `reset` reachable by URL because it is the emailed link's target),
  `AppShell.tsx` (login panel ⇄ role shell). Sign-in that resolves to a
  different role **navigates** rather than re-renders — which bundle loads is a
  server decision (D9/D10), so a trainer signing in through the user bundle's
  login panel must be handed off.
- **Password reset lands in the app**, not wp-login: `retrieve_password_message`
  is rewritten to `/{base}/reset?key=…&login=…` for non-administrators.
  Administrators keep the core flow on purpose — they work in wp-admin, and an
  admin locked out by a broken app route is a support incident.
- **Q17c** is implemented as the planned default (precedence admin > trainer >
  user, no switcher) in `AppRouter::currentRole()`, and covered by a test. It is
  still listed as open in [09](09-gap-register.md) pending confirmation; nothing
  else depends on the answer.
- Test-harness note: PHPUnit has already written to stdout by the time a test
  signs a user in, so `setcookie()` raises "headers already sent". The bootstrap
  filters `send_auth_cookies` to false — that filter is checked *after* the
  `set_logged_in_cookie` action fires, so the cookie-bridging path stays fully
  exercised while the wire write is skipped.

*Exit criterion met*, verified both in PHPUnit and over real HTTP against the dev
site: anonymous `GET /fitness/` → 200, user bundle, `user: null`, theme block
present → login panel; sign in → boot payload with the member's name, `spa:
user`; the returned nonce authenticates `/auth/me`; a stale nonce yields exactly
`rest_cookie_invalid_nonce` (what the client retries on) and `fc_nonce` returns a
working replacement; logout → 200 and `/auth/me` → 401. Gate: **phpcs 0 errors,
phpunit 73 tests / 276 assertions, `ui/` typecheck + lint + format + build
green.**

### W1.4 Workout domain (6 d) — ✅ **complete 2026-07-25**
`fc_workouts`/`fc_exercises`/`fc_user_workouts` read endpoints;
`WorkoutSessionService` complete state machine; all `/sessions/*` endpoints;
PR detection; `ActivityService`; `EntitlementService`.

*Done when:* PHPUnit drives a full session — start → pause → resume across a
simulated 20-minute gap → log 24 sets → complete — and the resulting rows,
duration, volume and PRs are all correct. **This test is the phase's real exit
criterion.**

**Build notes.**

- **Reads** (`Services/WorkoutService`, `Http/Controllers/Api/WorkoutController`):
  `GET /workouts` (status/difficulty/type/q + paging with `X-WP-Total`),
  `GET /workouts/{id}` with ordered exercises, `GET /exercises/{id}`. Every query
  joins `fc_user_workouts`, so scope is **in the query rather than in a check
  that can be forgotten** — a foreign id is a 404, not a leak. Video URLs are
  *withheld* rather than the resource refused when a plan lacks
  `has_video_workouts` (`video_locked: true`), because a readable workout with a
  locked video is an upgrade prompt and a 403 is a dead end.
- **The state machine** (`Services/WorkoutSessionService`) and all ten
  `/sessions/*` routes. Three invariants, each with a test:
  - *Time is derived, never reported.* `duration_seconds` is recomputed from
    `started_at`/`last_resumed_at` on pause and complete.
    **`paused_seconds` needs no `paused_at` column** — at resume it is exactly
    `(now − started_at) − duration_seconds`, i.e. wall-clock minus active time,
    which is self-healing if a write is ever lost. (01-database.md's schema has
    no `paused_at`; this is why none was added.)
  - *One open session per user*, enforced in a transaction with
    `SELECT … FOR UPDATE` since MySQL has no partial unique index. The 409 names
    the open session so the UI can offer resume-or-discard.
  - *Every read is user-scoped and every transition is a transaction.*
- **Set logging is idempotent** on `(session, exercise, set_index)` — the player
  fires it mid-workout on gym wifi, so a retry corrects the set instead of
  inventing a phantom one. Verified over HTTP: four POSTs, three set rows.
- **PR detection** compares the session against `fc_personal_records` and upserts
  strictly-greater winners (matching your own record is not a new one).
  `max_volume` is the exercise's **total** volume in one session, and `best_time`
  is the **longest** hold for `metric='seconds'` exercises — right for planks and
  carries, and the reason a lower-is-better timed event will need its own record
  type rather than reusing this one. Weight/volume records are not fabricated for
  bodyweight or timed work.
- **Calories** are MET × body weight × hours, from a `met_values` table in
  `config/fitnessclub.php`, falling back to a configured body weight until
  onboarding collects one. This replaces the prototype's `elapsedSeconds * 6.5`,
  which gave every user on every workout the same number.
- **Abandon keeps partial credit** (§8.1): elapsed time and logged sets stand,
  and the assignment's `progress_percentage` keeps what was earned rather than
  resetting — which is what the prototype's percentage-bearing workout cards show.
- **`ActivityService`** separates feed rows from audit rows in one table
  (`is_audit`), with the actor recorded — audit rows are exempt from the 12-month
  prune, because an audit trail with a retention window is not one. A minimal
  `NotificationService` (create + per-category preference) and `StreakService`
  (calendar days in the *user's* timezone; a live streak counts today or
  yesterday) land here because the celebration payload needs both.
- **`EntitlementService`** gained `can()` / `limit()` / `hasActiveSubscription()`
  / `messageQuotaFor()` / `unavailable()` over W1.3's merge, plus a per-request
  memo (a gated endpoint asks several questions and must not re-run the
  subscription join for each).
- **Stale-session cleanup**, which the plan puts with the state machine: without
  it a user who closes the tab mid-workout keeps an open session forever and
  every later start answers 409. `ScheduleProvider` registers a daily job; the
  closed session is credited only its **accumulated** active time, never the
  hours a tab sat closed, or every duration and calorie figure downstream
  inflates. Deactivation unschedules it.
  **Plan correction:** 03-backend.md says "registered via wpBones' schedule
  provider (`config/plugin.php → schedules`)" — wpBones v2 has no such feature
  (it covers CPTs, taxonomies, shortcodes, widgets and ajax, not WP-Cron), so
  this is native `wp_schedule_event` wrapped in a provider.
- `MemberController` holds what every member endpoint needs once: the caller's
  `fc_users.id` resolved from the WordPress user (**never** accepted as a
  parameter), entitlement gates that answer `fc_feature_unavailable` +
  `required_feature`, pagination headers, and one place where a service's
  `DomainException` becomes the contract's error envelope.
- phpcs: `WordPress.Security.EscapeOutput.ExceptionNotEscaped` is now excluded
  with a written rationale — exception messages here are JSON-encoded into
  `WP_Error`, never echoed, and `esc_html()`-ing them would double-escape the API.
  The rest of EscapeOutput stays on, including for the one place that does echo.

*Exit criterion met* by `tests/Integration/WorkoutSessionTest.php`: start → 10 min
→ pause → 20-minute gap → resume → 24 sets → 15 min → complete yields
`duration 1500 s`, `paused 1200 s`, `completion 100%`, `volume 10 800 kg`,
`calories 167` (MET 5.0 × 80 kg × 1500 s), **24 new personal records**, the
assignment at 100% with `times_completed = 1`, and the feed and notification rows
written. Time is simulated by moving the stored timestamps — the only honest way
to test a duration the client is not allowed to report. Also driven end to end
over real HTTP against the dev site. Gate: **phpcs 0 errors, phpunit 105 tests /
445 assertions.**

### W1.5 User SPA shell + workout screens (8 d) — ✅ **complete 2026-07-25**
React 18 + TS in the **Vite `ui/` workspace** — the `ui/src/user/` entry plus the
shared `ui/src/shared/` (API client, design system, chart/modal components)
([D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas)). Router
(basename `/{base}`), chrome, theme provider, ported CSS **with the missing utility
classes and the contrast fix**. Screens: auth, dashboard, workouts, workout detail.
The player with timestamp-derived timers, wake lock, offline set queue, and the
celebration screen.

*Done when:* a seeded user can log in, open a workout, complete it on a phone with
the screen off between sets, and see correct numbers on the celebration screen.

**Build notes.**

- **Router + chrome** (`ui/src/user/App.tsx`): react-router with `basename` from
  the boot payload — never a constant, because the front-end URL is configurable
  (D9). Deep links work (`/fitness/workouts/1` loads directly). Navigation is
  data-driven from one `NAV` array, so each later screen is one line. Both
  prototype navigation bugs are fixed: the off-canvas sidebar now has a way to
  open on mobile (`sidebarOpen` was set to `false` and never to `true`, leaving
  the whole primary nav unreachable under 768px), and the bottom bar lists real
  destinations instead of sending "More" to Messages.
- **Design system** (`shared/styles/app.css`): the prototype CSS ported with its
  four documented corrections — tokens aliased onto the theme's `--fc-*`
  properties instead of `:root` literals, `--text2` at the WCAG-passing
  `#8FA0BC`, the ~15 utility classes the prototype used but never defined, and
  **no CDN anything** (icons are inline SVG in `components/Icon.tsx`, which also
  retires the `m('i.fa-solid fa-list')` space-instead-of-dot bug in 35 places).
  `.fc-btn` is only ever on real buttons and links.
  **The bug worth remembering:** scoping the reset as `#fc-app *` gives it an
  id's specificity (1,0,0), which beats *every class in the file* — `padding: 0`
  won over `.fc-card`, `background: none` over `.fc-btn--primary`, and the whole
  app rendered as unstyled boxes. Every base rule now goes through
  `:where(#fc-app)`, which is scoped and contributes zero specificity.
- **Timers are timestamp-derived** (`shared/hooks/timers.ts`): an interval only
  triggers a re-render; the value is computed at render time. Elapsed is measured
  from **`elapsed_seconds` plus the local delta since that response arrived**,
  not by parsing a server timestamp into local time — so a device whose clock is
  ten minutes off still shows the right duration. Verified in the browser: a
  reload mid-workout came back reading 5:23, not 0:00.
- **Offline set queue** (`user/state/setQueue.ts`): optimistic enqueue,
  deduplicated on the same `(exercise, set)` key the server is idempotent on,
  sequential delivery with capped backoff, persisted to localStorage against a
  crash, and flushed with `sendBeacon` on pagehide (which is why `ApiClient`
  grew `beaconUrl()` — a beacon cannot set `X-WP-Nonce`, but WordPress accepts
  `_wpnonce` as a parameter).
- **Wake lock** (`shared/hooks/useWakeLock.ts`) re-acquires on `visibilitychange`,
  because the API drops the lock whenever the page is hidden and does not restore
  it. A refusal (battery saver, Firefox, insecure origin) is not surfaced — the
  timers are timestamp-derived precisely so a sleeping screen costs nothing.
- **`confirm()` is gone.** Stopping a workout uses the app's own dialog
  (`components/Modal.tsx`) with Escape-to-close and focus return; the native one
  blocks the JS thread and looks broken on mobile.
- **Dashboard is composed client-side for now**, in a single `useDashboard()`
  hook so W1.6 swaps one function rather than rewriting the screen. Its streak
  uses the same rule as the server's `StreakService`, deliberately — a temporary
  second answer is better than two different answers on two screens.

**Three bugs this package found in W1.3/W1.4 code, each now covered by a test:**

1. **`GET /sessions/active` returned a zero-byte 200** when nothing was running.
   WordPress does not serialise a bare `null` body, and the client's parser
   turned "empty" into `{}` — truthy, with no fields, so the player crashed on
   `session.exercises`. Now **204**, and the client parses an empty body as
   `null` rather than `{}`.
2. **Set logging silently discarded sets.** `duration_seconds`/`rest_taken_seconds`
   were declared as plain `integer` in the args schema, so an explicit `null`
   was rejected with 400 — and the queue treats 4xx as unretryable, so the set
   vanished while the badge still read "Saved". The schema now accepts
   `['integer', 'null']`, the client omits unmeasured fields, and a dropped write
   is *reported* ("1 not saved") instead of swallowed.
3. **"8/8 exercises" for a workout with one exercise done.** `was_skipped` is
   only recomputed for exercises that receive a set, so untouched ones kept the
   `0` default and counted as completed. `complete()`/`abandon()` now settle it
   at the end, when "no sets" really does mean skipped.

**Dependency note.** react-router-dom is pinned to the latest 7.18.1: *no*
version is currently advisory-free — ≤7.17.0 carries fourteen advisories that do
apply to us (open-redirect XSS, SSR/hydration issues), while ≥7.12 carries one
RSC-mode CSRF advisory that does not, since RSC mode is not enabled. The
remaining audit findings are dev-only (`brace-expansion` via eslint).

*Exit criterion met*, driven in a real browser against the dev site: signed in as
a seeded member → dashboard → started **Upper Body Power** → logged sets with a
90-second rest countdown → reloaded mid-workout and the session, cursor and
elapsed time all rehydrated → finished → celebration showed 6:38, 46 kcal
(MET 5.0 × 82.5 kg), 640 kg volume, three personal records and a 1-day streak,
with every number matching the database. Gate: **phpcs 0 errors, phpunit 108
tests / 456 assertions, `ui/` typecheck + lint + format + build green.**
Mobile layout is verified by the responsive CSS and the fixed hamburger path, not
on a physical device — the wake lock in particular cannot be exercised here.

### W1.6 Dashboard aggregate (2 d) — ✅ **complete 2026-07-27**
`GET /user/dashboard`, transient caching + invalidation, streak calculation,
`ProgressService` weekly series.

**Build notes.**

- **The payload is contract-complete now, not in Phase 2.** Nutrition, water and
  messaging have no services yet, but they do have **rollup tables** —
  `fc_nutrition_days` carries the day's totals and goals, `fc_message_threads`
  the preview and unread count. `DashboardService` reads those rollups. That is
  not pre-empting W2.1/W2.4: those packages own *recomputing* the rows on write,
  and this only reads them, so the wire shape stops moving today and the screen
  gets its real sections rather than four commented-out placeholders.
- **Empty means empty.** Every section that has no data returns its zero-state —
  `consumed_ml: 0`, a `null` goal, `[]` — and never a plausible-looking number.
  `goal_progress_percentage` is null without a target weight *and* a starting
  weight to measure from; `consistency_percentage` is null when nothing was
  scheduled. The client renders "set a goal" instead of a ring against an
  imaginary 2 000 kcal, and no card draws a bar it cannot justify.
- **Consistency is adherence, not attendance.** Completed sessions ÷ workouts
  *scheduled* in the 30-day window, capped at 100. The obvious alternative —
  active days ÷ days in the window — reports 13 % to somebody who did every
  session they were given, which is the opposite of what the number is for.
- **Invalidation is an announcement, not a cleanup call.** Writers fire
  `do_action('fitnessclub/user_data_changed', $fcUserId, $reason)` after the
  commit; `Support\DashboardCache` subscribes at boot. Six subsystems feed this
  screen and four of them do not exist yet — a cache that every future write site
  has to *remember* to clear is a cache that goes stale, and that exact symptom
  is already sitting in the demo fixtures as a support ticket ("my last two
  sessions did not appear until I refreshed"). Firing after the commit, never
  inside it, is the other half: a listener that clears early repopulates from the
  pre-transaction rows.
- **The second streak implementation is gone.** W1.5 carried a deliberate
  stand-in copy of the streak rule in `Dashboard.tsx`; the screen now takes the
  server's number, so the celebration screen and the dashboard can no longer
  disagree. `todayForUser()` — which had been private in `StreakService` and
  again in `WorkoutSessionService`, and which `ProgressService` needed a third
  copy of — is now `Support\UserClock`. It also fixed a latent DST bug in
  passing: the streak walked days with `strtotime('-1 day')` on a timestamp,
  which is wrong twice a year in any zone that shifts, so a streak would break
  every spring in a way nobody could reproduce in July.
- **Empty buckets are drawn.** `GROUP BY log_date` only returns days that have
  sessions; the series is built by walking the window and reading that map, so a
  rest day is a zero bar rather than a missing one. A chart that drops empty days
  draws a line through the gap and turns three workouts in a fortnight into a
  plausible-looking habit.
- **No charting library.** `MiniBars` is ~60 lines of flexbox for one seven-bar
  series. A library costs 40–150 kB on the screen whose entire point is first
  paint; W2.3's four real charts with axes and ranges are where one earns its
  weight, and this component is explicitly not the foundation for them.

**A pre-existing flaky gate, found and fixed here.** The suite failed roughly
one run in five, on W1.4's `WorkoutSessionTest` and never on the same assertion
twice: `1501 is not 1500`, then `421 is not 420`. Not a W1.6 regression and not a
service bug — the running leg of a session is measured against the **real**
clock, and real time passes while a test logs 24 sets between `resume()` and
`complete()`. Whenever that stretch crossed a second boundary the exact
`assertSame(1500, …)` was simply wrong about what it could know. Every duration
assertion that spans a real-clock leg now carries ±1 s (the exact ones that do
not — `0` for a stale-abandoned session — are unchanged, because zero is a claim
about the code, not about the clock). Verified with 14 consecutive green runs.
Worth catching before 0.6: the first thing a flaky suite costs is the habit of
believing CI.

*Exit criterion met.* Driven against the seeded dev site as Alex Morgan: one
request returns all ten contract sections — a 3-day streak, `Upper Body Power`
scheduled today with `Leg Day Destroyer` up next, 1 250/2 000 ml water,
1 650/2 400 kcal, three workouts and 888 kcal over 30 days, both trainer threads
with unread counts, and a seven-day chart whose three active days sit in the
right buckets. Gate: **phpcs 0 errors, phpunit 117 tests / 516 assertions,
`ui/` typecheck + lint + format + build green.** The member SPA has not been
eyeballed in a browser for this package — the dev session is signed in as an
administrator, which by D9 precedence routes to the admin SPA.

**Phase 1 exit:** the core loop — sign in, see today's workout, do it, see it
counted — works end to end on a real device.

---

## Phase 2 — Tracking & Admin (18–22 d)

### W2.1 Nutrition (4 d) — ✅ **complete 2026-07-27**
`fc_foods` + FULLTEXT search, meal logging with items, day rollup, water, goals.
Nutrition screen, Add Meal / Search Food modal, calorie ring, macro cards.

**Build notes.**

- **Three rules hold the domain up**, and each is invisible from outside until it
  breaks, so each has a test named after it. *Nutrients are copied, never
  joined* — an item stores the macros as they were when logged, so an
  administrator correcting a food next month does not rewrite last month's
  diary. *Totals are denormalised and recomputed on every write* — at the meal
  and the day, because summing on read would put a two-table aggregate on the
  dashboard's critical path. *Goals are snapshotted per day* — raising a target
  today does not retroactively change whether yesterday was met.
- **The client never sends a known food's nutrients.** Only `food_id` and
  `quantity` go up; the server copies from the food row. Otherwise the
  copied-not-joined rule would have two implementations, one of them in a place
  anybody can edit with devtools.
- **The typeahead is two queries, not one.** `MATCH … AGAINST` in *boolean* mode
  with a trailing `*`, because natural-language mode has no prefix matching and
  "chick" would find nothing; plus a `LIKE 'x%'` fallback under three
  characters, because InnoDB ignores tokens below `innodb_ft_min_token_size` and
  the contract fires the typeahead at two. Without the fallback a two-letter
  query returns nothing on a stock MySQL, which reads as "we have no foods"
  rather than "your server has a setting".
- **A tokeniser bug the tests caught.** Splitting the query on whitespace and
  then stripping boolean operators turns "low-fat" into `lowfat`, which matches
  nothing — the row says "Low Fat". Leaving the hyphen in is worse: a bare `-`
  means NOT, so the search would ask for rows containing "low" but *excluding*
  "fat", hiding exactly what was being looked for. Punctuation now separates
  words rather than being deleted from them.
- **Water is per-day and takes both gestures.** `delta_ml` for the +/- buttons,
  so two taps in flight add two glasses instead of racing to write the same
  total; `total_ml` for tapping the n-th dot, because "I have had five" is an
  absolute statement. Deltas apply in one SQL statement with a `GREATEST(0, …)`
  floor.
- **Nothing invents a goal.** A member who has not set a calorie target gets the
  total and *no ring* — not a ring drawn against a plausible 2 000 they never
  chose. The goal editor is new: the prototype displayed goals with no way to
  change them.
- `Modal` gained a `form` variant (unmuted body, wider panel, disableable
  confirm) rather than a second dialog component.

*Exit criterion met*, driven against the seeded dev site as Alex Morgan: logged
a meal of 2 × Chicken Breast plus a free-text item → 420 kcal, day rollup 1 650 →
2 070; water +250 and =1 000 both landed; goals merged without clobbering the
untouched macros; deleting the meal recomputed the day back to 1 650. Gate:
**phpcs 0 errors, phpunit 154 tests / 731 assertions, `ui/` typecheck + lint +
format + build green.**

**Deferred from this package:** the food-plan read endpoints
(`/food-plans`, `/food-plans/{id}`, `/compliance`) and barcode *lookup against an
external database* — the route exists and answers from `fc_foods`, but there is
no third-party source behind it. The Nutrition screen has not been eyeballed in a
browser: the dev browser session is a WordPress administrator, which by D4a sees
only the login panel.

### W2.2 Health & measurements (3 d) — ✅ **complete 2026-07-27**
Upsert-by-date health stats, BMI, `fc_users.weight_kg` sync, measurements. Health
screen with 8 cards + detail modal + Add Weight modal.

**Build notes.**

- **Four rules hold this domain up**, and each has a test named after it.
  *A day is a row and writing it is an upsert* — `UNIQUE (user_id, record_date)`
  is the whole storage model. *A partial write never erases* — only the keys
  present in the payload are written, so the evening's sleep entry leaves the
  morning's weight alone; the alternative is a form that silently deletes what
  it did not ask about. *BMI is derived, never accepted* — recomputed from the
  weight the row **ends up** holding, not the weight in the payload, so an entry
  recording only sleep still leaves the day's BMI consistent. *The profile's
  cached weight is recomputed, not assigned.*
- **That last rule is the one worth the tests.** Assigning
  `fc_users.weight_kg` on write looks equivalent and is correct only on the happy
  path: backdating last month's weigh-in would overwrite "current weight" with an
  older number, and deleting today's entry would leave the profile quoting a
  reading that no longer exists. Both are re-derived from the newest non-null row
  on every write *and* every delete. Two tests exist purely to separate the two
  implementations, since nothing else does.
- **The prototype fabricated blood pressure and we do not.** `HealthDetailModal`
  took one number and wrote `diastolic = systolic * 0.65` — a made-up value
  stored beside a measured one and indistinguishable from it afterwards. This is
  a defect that was **not** in [09 §2](09-gap-register.md#2-prototype-defects-do-not-port-these)
  (line 1456); it has been added there, along with the Health screen's
  throws-on-empty-data card list. Blood pressure is two inputs, and half a
  reading is a 400 (`fc_blood_pressure_incomplete`), as is a diastolic above the
  systolic.
- **BMI has no input, and that is now the server's statement rather than the
  view's.** The prototype got this right by hand (`d.id !== 'bmi' ? … : null` in
  two places); `editable: false` moves the same rule to where the derivation
  lives, so a second screen cannot reintroduce the box.
- **Cards are described by the server, not built in the view.** The prototype
  assembled its eight cards in the render function, and every one of them indexed
  the tail of an array (`d.weight[d.weight.length - 1].date`), so the whole
  screen threw for a member who had logged nothing — which is every member on day
  one. `HealthService::CARDS` is now the single source of what a card is, and
  adding a metric is one entry rather than an edit to the screen.
- **A card stops quoting a reading after 90 days.** A resting heart rate from
  March is not "your resting heart rate" in July, and a card that shows it
  unqualified reads as current. Past the window the card goes empty; the reading
  is still in the history behind it.
- **Trends report direction, never a verdict.** Whether falling weight is
  progress depends on what the member is training for and the server does not
  know that, so there is no `is_good` field and the arrow is deliberately not
  coloured. A test asserts the field's absence, because the obvious "improvement"
  is to add it.
- **Measurements are latest-per-field, not latest-per-row** — and this was a
  bug I wrote before catching it. Taking the newest measurement *session* blanks
  every measurement the member did not repeat: someone who measures chest monthly
  and calves twice a year would watch calves vanish the moment they recorded
  anything else. Each field now carries its own date, and the form **prefills
  nothing** — prefilling would stamp today's date on a chest measurement taken a
  month ago, putting a reading on the progress chart that was never taken.
- `toIconName()` and the icon paths moved to `icon-paths.ts`: card icons are
  named in PHP and arrive as `string`, and casting blindly renders
  `<path d={undefined}>` — an invisible icon and no error. Splitting the file
  also keeps Fast Refresh working, matching the existing
  `session-context.ts` / `session.tsx` split.

*Exit criterion met*, driven against the seeded dev site as Alex Morgan over a
real session (the member SPA's own cookie + CSRF, not a test harness): all eight
cards render from seed data — 78.5 kg, 18.2 %, BMI 25.6 "Overweight range",
6.8 hrs, 60 bpm, 118/76, mood "Okay", energy 5/10 — plus five measurements dated
2026-06-27. Logging 77.2 kg moved BMI to 25.2, set the weight trend to
`down −1.3`, and propagated to the dashboard aggregate
(`current_weight_kg` 77.2, goal progress 57.7 %); a follow-up sleep-only entry
updated the same row id and left the weight intact; half a blood pressure and a
5 000 kg weight were both refused. The row was restored to its seeded values
afterwards. Gate: **phpcs 0 errors, phpunit 171 tests / 853 assertions, `ui/`
typecheck + lint + format + build green.**

**Deferred from this package:** the radar chart for measurements (charting
arrives with W2.3, which owns the chart library decision) and `muscle_mass_kg` /
`stress_score`, which the API accepts and stores but no card displays — the
prototype has eight cards and these are not among them.

### W2.3 Progress & charts (4 d) — ✅ **complete 2026-07-27**
`ProgressService` range bucketing (week/month/quarter/year — **make the filter
real**), strength progression, consistency, records. Progress screen, all four
charts, empty states, computed axis domains.

**Build notes.**

- **The filter is real, and "real" means the granularity changes, not just the
  window.** The obvious reading of "make the filter work" is to fetch 365 points
  for a year — but that is 365 values a 300-pixel chart cannot draw, and drawing
  them produces noise that hides the trend the member came for. So: days close
  up, weeks at a quarter, calendar months across a year, every range landing
  between 7 and 30 buckets. The response carries `bucket` and the screen prints
  it ("monthly averages"), because a chart of monthly means labelled like daily
  readings misrepresents its own points.
- **Two kinds of empty, and they are not interchangeable.** Reading series
  (weight, body fat, strength) use **null** for a bucket with no reading — a gap.
  Consistency uses **zero**, because a week with no workouts is a fact rather
  than missing data. Getting this backwards draws a weight line plunging to the
  axis every week the member skipped the scale.
- **`spanGaps` was wrong on the first pass, and only the browser showed it.**
  Reasoning from principle — "connecting across a null invents a trend out of
  missing data" — gave `spanGaps: false`, which passes every test, because no
  test asserts what a line looks like. Rendered, it was a scatter of isolated
  dots: nobody weighs in daily, so on a 30-day chart *every* reading is
  surrounded by nulls and there were no segments left to draw. Now `true`; the
  points still mark real readings, so the line never claims a measurement that
  was not taken. This is the argument for eyeballing a chart rather than
  asserting its data.
- **Strength lines are chosen, not hardcoded.** The prototype fixed Bench, Squat
  and Deadlift — the wrong chart for anyone whose programme is not that
  programme. The three most-performed lifts in the window are picked by set
  count, and the plotted value is the **heaviest set in the bucket**, not the
  average: averaging warm-ups in makes a personal best look like a bad week.
- **Axis domains are computed, with the two degenerate cases handled.** The
  prototype pinned the weight axis to `min: 76, max: 82`, so anyone outside that
  band got a blank chart (gap §2, line 955). `computeDomain` pads from the data
  and handles both *one point / all-identical points* (zero spread would draw the
  line on the axis) and *no points at all* (`Math.min()` of nothing is `Infinity`,
  which poisons the scale silently).
- **Chart.js is registered by hand and loaded lazily.** Only the controllers,
  scales and elements these four charts use, so the bundler drops the rest; the
  screen `lazy()`-imports it, and — because the *empty* states live outside the
  boundary — a member with no data never downloads it either. Result: a 185 kB
  chunk that is entirely separate from the 150 kB member bundle. Colours are read
  off the live themed element with `getComputedStyle`, never hardcoded, so the
  charts follow a light theme; a `MutationObserver` on `#fc-app`'s style
  attribute re-reads them when the theme changes.
- **`fc_view_own_stats`, not an invented capability.** The first draft gated on
  `fc_view_stats`, which does not exist — `Capabilities::USER_CAPS` has
  `fc_view_own_stats`. The `can_view_stats` entitlement is checked on top.
- **`personal_records` counts PRs set inside the window**, not lifetime: on a
  screen where every other number is range-scoped, a lifetime count that never
  moves reads as a broken filter.

*Exit criterion met*, and verified in a real browser rather than only over the
API — the first time on this project, and it earned its keep by catching the
`spanGaps` defect. As the generated "Example Member" (who has no data) all four
cards render their empty states, which is exactly the case that threw in the
prototype. Seeded with temporary data, all four charts drew: weight declining
79.5 → 77.9 with a filled area and a computed 76.9–80.5 domain, three strength
progressions climbing, daily consistency bars, and a radar. Switching to **Year**
re-labelled the subtitle to "monthly averages", rescaled the weight axis to
77.7–84.8, collapsed consistency to monthly counts (Jun 2, Jul 5), and brought
the second measurement session into the radar as an oldest-vs-newest overlay. No
console errors. The temporary data was removed afterwards and the cascade
verified. Gate: **phpcs 0 errors, phpunit 183 tests / 954 assertions, `ui/`
typecheck + lint + format + build green.**

**Two things found on the way, neither introduced here.**

1. **`react-router-dom` carries 2 high-severity advisories** (GHSA-qwww-vcr4-c8h2,
   RSC-mode CSRF bypass, affecting 7.12.0–8.2.0). 7.18.1 is the newest 7.x, so
   there is no patched release to move to — `npm audit fix` downgrades to 7.11.0,
   a breaking change. The app uses client-side `BrowserRouter` with no RSC and no
   server actions, so the advisory does not describe a reachable path here.
   Pre-existing; chart.js and react-chartjs-2 added **zero** vulnerabilities.
2. **476 orphaned `fc_exercise_logs` rows in the dev database**, referencing
   session ids that no longer exist, in groups of seven — the shape the volume
   seeder produces. The `ON DELETE CASCADE` is present and works (verified by
   creating and deleting a session), and `fc_set_logs` has no orphans, so these
   predate the constraint or were removed with checks disabled. **No effect on
   this package**: every progress query inner-joins through `fc_workout_sessions`,
   so unreachable logs are excluded. Left in place rather than deleted — clearing
   someone's dev data is their call.

**Deferred from this package:** the consistency **calendar heatmap**
(`GET /progress/consistency` is built and tested, but §9.3's year grid is a
screen of its own and the prototype has no design for it) and the
`/progress/exercises/{name}` **drill-down UI** — the endpoint and its query hook
exist, but the chart-click interaction that opens it belongs with the exercise
library in W2.5.

### W2.4 Notifications & activity (2 d) — ✅ **complete 2026-07-27**
`NotificationService` respecting preference toggles, notification screen,
nav badges, activity feed.

**Build notes.**

- **Preferences are honoured at the write, not at the read**, and that is the
  whole design. Filtering muted categories out of the list would leave the rows
  in the table and the unread count wrong — the badge would say 3 over an inbox
  of 1. `notify()` returns 0 for a muted category and writes nothing. The screen
  says so in as many words ("stops those notifications being created at all —
  they will not be waiting for you later"), because a member who expects muted
  notifications to pile up unseen and later finds them missing has been misled
  by vaguer wording.
- **Absent means on.** A category nobody has an opinion about is enabled, so one
  added in a later release does not arrive silently muted for everyone who
  already has a preferences blob.
- **Preferences merge rather than replace.** `fc_users.preferences` is shared —
  privacy settings will live beside these — so a wholesale write of the
  `notifications` key would silently drop them. The screen sends only the switch
  that was flipped, and a test asserts an unrelated key survives.
- **The unread count travels with every mutation.** Each read/dismiss/read-all
  endpoint answers with the resulting `unread_count`, and the client writes it
  straight into the badge's cache entry rather than invalidating and refetching.
  A client that had to make a second request would render the stale number in
  between — the very number the member just cleared. The badge is seeded from
  the boot payload so it is right on first paint, and opening the inbox corrects
  it for anything raised on another device.
- **An inbox belongs to an account, not to a member profile** — so these routes
  do **not** go through `asMember()`, and are not gated on `fc_access_app`. A
  trainer has notifications and no `fc_users` row; the first draft would have
  answered 403 to a trainer reading their own inbox, and `asMember()` would have
  answered `fc_no_member_profile`. Preferences are the exception and genuinely do
  need a profile, so they keep the member gate. A test signs in as a trainer and
  asserts both halves.
- **Audit rows stay out of the feed.** `GET /activity` excludes `is_audit = 1`
  as `feed()` does: those record what someone *else* did to the member's data and
  carry before/after values. The member still sees staff edits — as the
  `source: 'admin'` label on the affected record, where it means something —
  rather than as a diff in a list of things they did.
- **The activity filter offers only types the member actually has**, derived
  from their own rows. Offering "nutrition.logged" to somebody who has never
  logged a meal is a filter that can only return nothing.
- **The feed lives on `/notifications` rather than getting its own nav entry**,
  because [04](04-user-app.md) lists `/notifications` and no `/activity`. Three
  panels — inbox, activity, settings — are the same question asked three ways.

**Two CSS reuses that were wrong, both caught in the browser and neither
catchable by a test.**

1. `.fc-badge-dot` is `position: absolute` for a positioned ancestor the card
   does not provide, so four unread dots escaped and stacked in the **top-right
   corner of the page**. Removed: the unread state is carried by the row's left
   accent border, plus visually-hidden text, since a border is still colour
   alone.
2. `.fc-tab` is the segmented control — `flex: 1`, active style keyed off
   `aria-selected`. Reused for the activity filter it stretched four chips across
   1 400 px with no highlight on the selected one. Replaced with a real
   `.fc-chip`.

*Exit criterion met*, verified in the browser as the generated "Example Member":
four seeded notifications rendered with per-type icons and tones, the sidebar
badge read **4**, tapping a row cleared its border and moved the badge to **3**
without a refetch, a second tap took it to **2**, the activity panel listed three
entries with chips derived from the member's own types, and all seven switches
rendered on. Flipping "Personal records and milestones" off persisted as
`{"notifications":{"achievement":false}}` and was then proven at the write:
`notify(achievement)` returned 0 while `notify(workout)` created a row. All
temporary data and the preferences blob were removed afterwards. Gate: **phpcs 0
errors, phpunit 193 tests / 1026 assertions, `ui/` typecheck + lint + format +
build green.**

**Deferred from this package:** email and push fan-out and the digest schedule
(Phase 4 — this writes the row the screen reads), and the **privacy** half of
`PUT /user/preferences`, which belongs with the Profile screen rather than here.

### W2.5 Admin app (7 d) — ✅ **complete 2026-07-27**
~~Menu registration, mount, style scoping (option A from [05](05-admin-app.md)).~~
Server-backed `CrudTable`. Resources: users, trainers, workouts (+ nested exercise
editor with drag reorder), foods, meals, health entries. Admin dashboard.
**Settings screen** (general, email, features) — a prerequisite for everything
after this, despite not appearing in the prototype.

> **Two lines of this package's own brief were stale, and both were followed to
> the newer decision rather than the letter.** (a) "Menu registration, mount,
> style scoping (option A)" predates the D9/D10 reset — [05](05-admin-app.md#mounting--front-end-spa-not-wp-admin)
> says the A/B/C style decision is *moot*, because the admin UI is now a
> standalone SPA at `/{base}` with no host CSS to fight, and wp-admin keeps only
> the thin launcher already built in W1.3R. (b) [02](02-api-contract.md#admin--admin-cap-manage_options)
> gates `/admin/*` on `manage_options`; that predates plugin-owned identity
> (W1.2R/W1.3R), and `current_user_can()` now answers for a wp-admin session with
> nothing to do with this app. Gated on the plugin's own `fc_*` capabilities
> instead, with a test that signs in a **real WordPress administrator** and
> asserts 401.

**Build notes.**

- **The declarative registry exists on both sides.** The prototype's one good
  idea was `CrudTable(config)`; the server half is
  `Services\Admin\ResourceRegistry`, so a resource is one config entry per side
  and no new controller or component. `GET /admin/resources` serves the server's
  own registry, which is what a test uses to catch the two drifting.
- **Loading every row is the bug the port exists to fix.** 05 calls it "the
  single most important change in the admin port": `db[table].toCollection()`
  then filter-and-paginate in the browser is fine for six seeded users and fatal
  at ten thousand. Search, filter, sort and paging are all SQL now.
- **Identifiers come from the registry, values from the request.** Column names
  cannot be bound as placeholders, so they are interpolated — safe only because
  every one is a literal in the registry. A request names a *sort key*; the
  registry decides what SQL that means and an unknown key falls back to the
  default. A test posts `sort=id;DROP TABLE …` and asserts a 200 with rows.
- **Saving a workout preserves exercise ids**, which is the prototype's worst
  defect: `WorkoutForm` did `delete ex.id` and re-inserted every row, and
  `fc_exercise_logs.exercise_id` points at those ids — so every past session
  silently lost its link to the movement it recorded. Rows with an id are
  updated, rows without are inserted, and only what the payload dropped is
  deleted. **Order is the array order**, so drag-reorder needs no endpoint.
- **Deletes that would orphan live data are refused with a reason** (409):
  a trainer with active clients, a workout with logged sessions, a food that
  appears in members' diaries. The prototype deleted unconditionally.
- **Q10 is enforced in the service, not the screen.** Meal and health writes
  stamp `source='admin'` and `last_edited_by_account_id`, write an audit row
  carrying only the fields that actually moved, and re-run the same derivations a
  member's own edit runs — BMI and the profile's cached weight both follow a
  staff edit. Both screens carry an inline warning saying so.
- **A member's name, not "User #1"** — the meal and health lists join
  `fc_users`, which is what the prototype rendered instead.

**Three defects found by opening it in a browser, none catchable by a test.**

1. **The admin SPA had no `QueryClientProvider`.** Every screen uses TanStack
   Query; without it the first render throws "No QueryClient set" and the page is
   **blank**, with the error only in the console. Every test passed throughout —
   they exercise the API, not the mount. This is the strongest argument yet for
   the browser pass being part of the package rather than optional.
2. `select` carries `width: 100%` from the base stylesheet, so every toolbar
   filter took a row of its own. Scoped to `auto` inside `.fc-admin-toolbar`.
3. `.fc-modal--form` maxes at 34rem — too narrow for an exercise row's six
   controls, which pushed the remove button off the edge. Widened to 56rem via a
   descendant selector under `.fc-admin`, so one app's forms get roomier without
   changing the shared Modal's API, and the row now wraps as well.

*Exit criterion met*, verified in the browser signed in as the administrator
account: the dashboard renders in one request (7 members, 4 trainers, 6 workouts,
12 foods, $59.97 MRR, a failed payment for Jordan Riley, five recent signups);
`/foods?q=chicken&sort=calories&order=desc` restores search, sort and result from
the URL alone; the workout editor loads all eight exercises with sets/reps/weight/
rest/metric and reorder controls. Moving Bench Press down and saving left
`id=1` and `id=2` **swapped in position but identical in identity**, with all
**16 exercise logs still linked** and `MAX(id)` unmoved — the delete-and-reinsert
bug demonstrably fixed. Typing `wp-admin` into the App URL field raised the
reserved-slug error inline and disabled Save. The exercise order was restored and
no setting was saved. Gate: **phpcs 0 errors, phpunit 210 tests / 1140
assertions, `ui/` typecheck + lint + format + build green.**

**Deferred, and all of it named as out of scope by the roadmap line rather than
dropped:** the resources [05](05-admin-app.md#resources) lists that W2.5 does not
— payments, plans, subscriptions, tickets, themes, the trainer report and the
trainer-request queue — plus bulk actions, CSV import/export, column-visibility
toggles and the row detail drawers. The `react-hook-form` + `zod` schemas shared
with the server's `args` (05 §Forms) are also deferred: the six forms here are
flat field lists, and generating both sides from one source is tooling in its own
right. What the plan names as the *behaviour* — required marks,
disabled-while-submitting, server errors surfaced on the field — is delivered.

**Phase 2 exit:** every user-facing tracking feature works and an administrator
can run the site without touching the database. ✅ **Met 2026-07-27.**

---

## Phase 3 — Social & Commerce (38–48 d)

> **Estimate correction 2026-07-24.** This phase previously read 22–28 d, carrying
> 14 d for the trainer app while [06-trainer-app.md](06-trainer-app.md)'s
> bottom-up breakdown totalled ~29 d for the same work. That was an inconsistency
> in my plan, not a change in scope — the detailed figure is the credible one, and
> Phase 3 is re-based on it. Q4 then added ~5 d of genuinely new, previously
> uncosted work (trainer directory, profile, request flow, request queue). Net:
> Phase 3 grows by ~16–20 d and the project total by the same. Deferring the
> food-plan builder and exercise library recovers ~10 d.

### W3.1 Messaging (4 d) — ✅ **complete 2026-07-27**
Threads, messages, **per-trainer** quota service (Q3), unread counts, polling,
attachments. User-side message screen. (Drop the decorative typing indicator —
see [02](02-api-contract.md#messaging--messages).)

**Build notes.**

- **The quota is per thread, and that is the whole point.** A member coached by
  two trainers holds one subscription per trainer, and each conversation draws on
  *that* trainer's plan. A global cap would let one coach's conversation consume
  the other's — the member silenced with a coach they are paying for because they
  had been chatty with a different one. A test asserts exactly that: exhausting
  thread A leaves thread B sending.
- **Only the member's own sends count.** Trainer replies never touch the
  allowance; counting both directions would let a chatty coach exhaust their own
  client. Tested by having the trainer write five times against a 2/week plan and
  asserting the member still has 2 remaining.
- **The window is rolling, not calendar.** Seven days back from now, so the limit
  does not reset at a boundary the member cannot see. `resets_at` is seven days
  after the **oldest send still inside the window** — the moment a slot actually
  frees, which is the honest answer to "when can I write again". Tested by ageing
  a message past the window and asserting a slot opened.
- **Two gates, both after the thread is resolved.** `can_message` answers
  `fc_feature_unavailable` (so the client can offer an upgrade rather than the
  quota message, which would be wrong for somebody who never had an allowance);
  the quota answers 429 with limit/used/resets_at. Both run *after*
  `Guard::participatesInThread()`, because gating first would answer 403 to a
  stranger probing a thread id and thereby confirm the conversation exists.
- **History pages backwards from newest by id**, not by offset: a conversation is
  read newest-first, and offset paging renumbers every page as messages arrive
  mid-scroll.
- **Polling returns ids, not threads.** An idle 15 s poll is one indexed read and
  an empty array. It also excludes your own sends — echoing them back would
  duplicate every message you write.
- **No typing indicator**, per [02](02-api-contract.md#messaging--messages). The
  prototype's showed permanently whenever the trainer was "online" and was tied
  to nothing.

**A latent bug found in `EntitlementService`, not in this package's own code.**
`forUser()` resolved the per-trainer quota with
`$features['max_messages_per_week'] ?? $row['max_messages_per_week']` — and `??`
treats an **explicit null as absent**, so a features-JSON override of `null`
fell through to the column. Since `fc_plans.max_messages_per_week` is
`NOT NULL DEFAULT 10`, that left **no way to express an unlimited plan by either
route**. Fixed to `array_key_exists`. It had lain dormant since W1.3 because
nothing consumed the quota until now; W3.1 is the first code to enforce it.

*Exit criterion partly verified in the browser.* The thread list, the mail nav
badge (2), the conversation with both bubbles and timestamps, and — the headline
behaviour — the per-thread quota note **"3 of 3 messages left with this trainer"**
all render against a seeded conversation. **The send round-trip was not verified
in the browser**: mid-session I cleared the CSRF cookie from the page, which
permanently desynced it from the session row's stored `csrf_hash`, and every
write then answered `fc_csrf_mismatch`. Send is covered by the integration tests
(12 of them, including every quota path). Temporary data removed; Alex Morgan's
two seeded threads left intact. Gate: **phpcs 0 errors, phpunit 222 tests / 1234
assertions (run twice, stable), `ui/` typecheck + lint + format + build green.**

**Two things worth fixing, neither introduced here.**

1. **Losing the CSRF cookie bricks a session's writes permanently.**
   `Csrf::verify()` requires `sha256(presented) === fc_sessions.csrf_hash`, and
   `ensureCookie()` mints a fresh token without re-syncing the stored hash — so
   once the cookie is cleared (a privacy extension, a cookie purge, a user
   clearing site data) every write 403s for the life of the session, and the
   panel's advice, "Reload and try again", does not help because reloading is
   exactly what does not fix it. A logout would clear it, except:
2. **A failed sign-out is silent.** `onClick={() => void logout()}` discards the
   rejected promise, so a 403 from the logout POST produces no message, no toast
   and no state change — the button simply appears dead. This is how (1)
   presented, and it cost real time to diagnose. Both belong to W1.3's auth
   surface rather than to messaging.

**Deferred from this package:** the **attachment UI**. `POST /messages/attachments`
is built — multipart, ≤5 MB, type checked by reading the bytes rather than
trusting the filename — and the composer renders attachments that exist, but
there is no picker wired to it yet. Also deferred: loading older pages in the
conversation (the API pages backwards and the screen says when there is more, but
the "load earlier" control is not built).

### W3.2 Subscriptions & payments (7 d)
`fc_plans` admin CRUD with the feature-flag editor (including `max_trainers`).
`SubscriptionService` (create/change/cancel-at-period-end/resume/expire)
supporting **concurrent subscriptions per user**, one per trainer.
`EntitlementService` merge rules with the overlapping-plan test matrix —
**including the downgrade-grandfathering case**, which is the one most likely to
be got wrong and the most damaging when it is. `PaymentGateway` interface, `StripeGateway`
(Checkout + Payment Element — **standard Stripe, not Connect**, per Q2),
`ManualGateway`, webhook with signature verification + idempotency. Dunning cron.
Subscription + plan-selector + payment-history screens. Admin
plans/subscriptions/payments screens + `/admin/reports/trainers` attribution.

*Risk:* gateway integration always exceeds its estimate. Webhooks need a tunnel in
dev, the test-mode/live-mode split leaks into config, and refunds/proration are
fiddly. Treat 7 d as optimistic. Q2 removed the far larger Connect/payout/KYC
branch this could otherwise have become.

#### Status — ✅ **complete except the Stripe adapter, 2026-07-27**

**Scope decision, taken with the owner.** D6 says ship `StripeGateway` +
`ManualGateway`. Stripe means a new composer dependency and, with no API keys
available, a few hundred lines of adapter and webhook code that could not be
exercised even once. Agreed instead: **build the seam and everything above it,
defer the Stripe adapter until keys exist.** It slots into
`GatewayRegistry` through the `fitnessclub/payment_gateways` filter without
touching anything else — which is the claim D6 makes for the seam, and the claim
`FakeGateway` in the test suite already cashes.

**Build notes.**

- **The seam is the deliverable.** `PaymentGateway` has five methods and three
  value objects, and nothing above it — `SubscriptionService`, the webhook route,
  the admin screens — knows what a processor is. `GatewayResult` deliberately has
  **three** states rather than a boolean: *redirect* (nothing is active yet, the
  webhook decides), *settled* (active now), *failed*. Collapsing the first two is
  exactly how a member gets features they have not paid for by abandoning a
  hosted checkout page.
- **A member holds a set of subscriptions, not one.** One per trainer (Q3), with
  the platform tier as `trainer_id IS NULL`. So a second plan with the *same*
  coach replaces the first — two concurrent subscriptions to one coach is double
  billing — while plans with *different* coaches coexist. Both are tested.
- **Cancelling always means at period end.** The status stays `active`,
  `cancel_at_period_end` is set, and the expiry sweep flips it when the date
  passes. Ending access at the click would take something the member already
  bought; the screen says when it ends and offers to undo.
- **Expiry and dunning are separate transitions.** A period that ended on a plan
  set to renew becomes `past_due`, not `expired` — cutting somebody off because a
  card needs re-trying is what dunning exists to avoid. Past-due members keep
  their features through a 14-day grace window, then suspend.
- **The webhook is the only public write endpoint in the plugin**, and it needed
  an explicit CSRF exemption: `AuthProvider::guard()` refuses every non-GET
  without a token, so without `isSignatureVerifiedRoute()` naming the path, every
  delivery would have answered 403. **I had written the opposite in the route
  comment before checking** — the guard does not skip sessionless routes, it
  rejects them. Its protection is the adapter's signature check, which runs
  before anything parses the body.
- **Idempotency is a transient keyed on the gateway's own event id.** Gateways
  retry on any non-2xx and sometimes deliver twice on a 2xx; without it a retried
  `payment_succeeded` extends a subscription twice. A duplicate answers **200**,
  not 409 — an error makes the gateway keep retrying. So does an unknown event
  type, because gateways add them constantly.
- **`ScheduleProvider::unschedule()` was already one job out of date** the moment
  a second was added — it cleared only `STALE_SESSIONS`. Now iterates every job,
  so deactivation leaves no hook firing into nothing.
- **The overlapping-plan matrix is tested explicitly**, as
  [03](03-backend.md#merging-across-multiple-subscriptions) asks, and for the
  reason it gives: feature flicker as subscriptions lapse is invisible until a
  member has two trainers. Booleans union; caps take the **max, never the sum**
  (1 + 2 trainers is 2, not 3); null beats any number; a lapsed plan stops
  contributing while the other holds; nothing active falls to the free tier
  rather than to a denial. And **Q16**: a downgrade to a 1-trainer plan while
  holding two reports a cap of 1 and severs neither — asserted against
  `fc_user_trainers` directly, not just against the reported number.

*Exit criterion met for everything built*, by test rather than in a browser: 17
new tests covering the state machine, the merge matrix, the sweep, dunning and
the webhook. Gate: **phpcs 0 errors, phpunit 239 tests / 1333 assertions, `ui/`
typecheck + lint + format + build green.** The billing screens were **not**
eyeballed — the CSRF desync recorded under W3.1 still blocks writes in the dev
browser, and unpicking it is W1.3's fix rather than this package's.

**A test-isolation bug worth recording**, because only the *full* suite found it:
the webhook test hardcoded `evt_1` as its event id. Correct for one run; on the
second the seven-day dedupe transient from the first was still set, so a real
event was treated as a duplicate and the assertion on payment count failed. The
test now mints a fresh id per run, as a real gateway does. Running the file alone
passed both times — the suite is what caught it.

**Deferred:** `StripeGateway` (above); **proration** on plan change, which is
currently cancel-and-restart rather than a credited swap; invoice PDFs
(`GET /billing/invoices/{id}`); refunds beyond marking a payment refunded; and
`/admin/reports/trainers`, the Q2 attribution report — its data is all present in
`fc_subscriptions.trainer_id` but the report itself is unbuilt.

### W3.3 Support tickets (3 d) — ✅ **complete 2026-07-27**
Tickets + replies + internal notes, user ticket screens, admin ticket queue with
assignment, trainer ticket view. FAQ from settings rather than hardcoded JS.

**Build notes.**

- **Internal notes are the rule everything else is arranged around.**
  `is_internal_note` marks staff-only commentary written on the *same ticket the
  member reads*, and the only thing keeping the two apart is a WHERE clause. So
  the filter lives in one method every read path goes through, and there is one
  set of endpoints rather than a member tree and a staff tree — two route trees
  would be two places to forget it. Asserted three ways: the detail view, the
  reply count (a member seeing "3 replies" on a thread with one has been told
  staff are talking about them), and a member's attempt to write one, which is
  dropped rather than refused.
- **A member's payload omits the operational fields entirely** — assignee, first
  response time, the reporter's email. Not hidden in the UI: absent from the
  response, so nothing downstream can render them by accident. They say more
  about staffing than about the member's problem.
- **`resolved` and `closed` are not the same status**, and my first pass
  conflated them — a member replying to a *resolved* ticket got a 409. Resolved
  is staff's opinion that it is fixed, and the member gets to disagree by
  replying, which reopens it. Closed is the end. Two constants now, `SETTLED` and
  `FINAL`, because one list could not express both.
- **First response is recorded once and never moved**, and an internal note does
  not count as one — it is staff talking to each other, and stamping it would
  tell the member something happened when nothing they can see did. A note also
  does not move the status, for the same reason.
- **Priority is a request, not a promise.** The member's ticket form does not
  offer it at all and the server floors it to `medium`; staff triage from the
  queue. A picker would mean every ticket arrives urgent.
- **A bad enum falls back rather than failing.** Category and priority arrive
  from a `<select>`; a value outside the list is a client bug or a probe, and
  neither deserves a 400 that blocks somebody's genuine support request.
- **The FAQ comes from the options model.** The prototype shipped five answers
  inline in the JS bundle, so correcting a wrong one meant a rebuild and a
  deploy. Bundled defaults are the same five, so an install that never touches
  the setting still has an FAQ.
- **Gated only on "signed in and active"** — not on a feature capability.
  Raising a ticket is how somebody reports that the rest of the app is refusing
  them; gating it behind a plan would lock the door and post the key inside.

**A wpBones options quirk worth recording.** The FAQ was first stored at
`support.faq`. The options model resolves a dotted path by walking the stored
blob, and on this install — whose row predates the key — that walk hit a value it
could not index and threw from inside the framework. Moved to a **flat**
`support_faq` key: one level has nothing to walk. Worth knowing before adding any
other nested option to an existing install.

*Exit criterion met by test*, not in a browser: 14 new tests, most of them about
what a member must not be able to see. Gate: **phpcs 0 errors, phpunit 253 tests
/ 1446 assertions, `ui/` typecheck + lint + format + build green.** The screens
were not eyeballed — the dev browser's CSRF desync recorded under W3.1 still
blocks writes there.

**Deferred:** ticket **attachments** (the column and the reply shape carry them;
no upload is wired — `POST /messages/attachments` from W3.1 is the endpoint it
would reuse), and the **trainer** ticket view, which belongs with the trainer app
in W3.4. Staff assignment is by account id in the admin form rather than a
picker; a directory of assignable staff arrives with the trainer app, which is
where the list of who exists comes from.

### W3.4 Trainer app (31 d, see [06](06-trainer-app.md))

#### Slice 1 — endpoints and guards ✅ **complete 2026-07-27**

Split at the owner's direction: 31 d is roughly the whole rest of the project in
one package, so it is being taken in slices. This one is the **API and its
authorisation model** — every `/trainer/*` route from
[02](02-api-contract.md#trainer--trainer-cap-fc_manage_clients), no screens.

**Build notes.**

- **Q13's line runs through two different guards, and they ask different
  questions of different columns.** `Guard::trainerAssignedResource` asks "did
  you *author* this", keyed on `trainer_id`. Assignments needed a new one —
  `Guard::trainerMadeAssignment`, keyed on `assigned_by_account_id`, because the
  assignment tables record the *account* that handed something over, not the
  trainer profile. The distinction is load-bearing: two trainers may both assign
  the same shared workout to the same client, and only the one who assigned it
  may take it back.
- **Read is shared, and every assignment is attributed.** Any actively assigned
  trainer sees the whole client record including co-trainers' work, with
  `assigned_by: {trainer_id, display_name, assigned_at}` on each row so a reader
  never mistakes another coach's programming for their own.
- **The conflict warning is non-blocking**, and that is the point. Assigning
  into a week another trainer has already programmed returns
  `warnings: [fc_assignment_conflict]` with who, what and when — refusing would
  make one coach's programme silently constrain another's, which is the
  paternalism shared visibility replaces. Your *own* prior assignment is not a
  conflict: a trainer programming twice in a week is programming.
- **Three different "no" codes, chosen deliberately.** A client not on your
  roster is **404** — a trainer must not be able to enumerate the platform's
  membership by probing ids. A co-trainer's assignment is **403**, because
  shared read already told you it exists and pretending otherwise would be
  theatre. A co-trainer's *note* is **404** again (Q15), because its existence is
  itself the private thing.
- **Authorship is stamped from the session, never taken from the payload.** A
  `trainer_id` in a create body is ignored, so a trainer cannot plant work in
  somebody else's library — asserted directly against the stored row.
- **The platform library is assignable but not editable.** Workouts with
  `trainer_id IS NULL` are readable and assignable by every trainer (otherwise
  the builder looks empty on day one) and writable by none of them.
- **`features` and `max_trainers` are absent from the trainer plan builder.**
  Those decide platform-wide entitlements; a trainer granting themselves
  `has_video_workouts` by editing their own plan would be selling something the
  platform never agreed to. `owner_type` is forced to `trainer` for the same
  reason — it decides who collects the money (Q2).
- **A trainer cannot set their own rating or account status.** Both are absent
  from the profile update: a rating you can set is not a rating.
- **Capacity is checked at accept, not at request** — it can fill between
  somebody asking and the trainer answering. And `max_clients = 0` reads as
  *unconfigured*, not as "cannot take anybody", or a fresh trainer would be
  locked out of accepting their first client.
- **The exercise list is replaced whole**, reusing the admin editor's
  upsert-by-id rather than a second copy: the rule that keeps
  `fc_exercise_logs` pointing at the right movements is subtle enough that two
  implementations would eventually drift.

*Exit criterion for this slice met by test*: 16 tests, almost all of them about
what one trainer must not be able to do to another's work. Gate: **phpcs 0
errors, phpunit 269 tests / 1555 assertions.** No browser pass — there are no
screens in this slice.

**Remaining slices**, unstarted: the trainer SPA shell and client-facing screens
(dashboard, client list, the seven-tab client detail, request queue); then the
builders (workout, plan, food plan) and the trainer profile; then trainer
messaging, which reuses W3.1's API unchanged. `/trainer/clients/{id}/food-plans`
assigns an existing plan but the **food-plan builder's meal editor** is not
built, and [06](06-trainer-app.md) marks it deferrable to Phase 4 (−10 d) along
with the exercise library.

#### Slice 2 — SPA shell and client screens ✅ **complete 2026-07-28**

The trainer app itself: shell, dashboard, client list, the seven-tab client
record, and the request queue. No new endpoints — slice 1's API unchanged.

**Build notes.**

- **The dashboard leads with people, not counts.** [06](06-trainer-app.md) is
  blunt about it — "a list of counts is a report; a list of *clients who need
  something* is a tool" — so "Needs attention" is the first card and the stat row
  sits under it. **No money appears anywhere** (Q2): trainers are traced, not
  paid, and a figure they cannot act on and are not owed invites exactly the
  wrong conversation.
- **Q3 is surfaced on the client *list*, not just the record.** A row carries a
  "+1 coach" tag, because a shared client is a different programming
  conversation and the trainer should know before they open the record rather
  than after.
- **Co-trainers' assignments are shown but disabled, and the disabled control
  says why.** Its accessible name is "Assigned by another coach" — a trainer who
  cannot remove a row should be told why, not left clicking at nothing, and
  hiding the row instead would hide that the client already has that work.
  Verified against the seeded case: six assignments on one client across three
  coaches, remove enabled on exactly the three that are yours.
- **Tabs fetch on open.** Seven eager queries for a screen where a trainer looks
  at one of them is six wasted requests.
- **The Progress tab draws the member app's charts**, because
  `TrainerService::progress()` delegates to `ProgressService::range()` — it *is*
  the member's payload after a roster check. The grid moved to
  `@shared/components/charts/ProgressChartGrid`; two renderings of one payload
  would have drifted. Only the empty-state copy differs, via a `subject` prop:
  "log a weight on the Health screen" is advice a trainer cannot take, and copy
  addressed to the wrong person is worse than no copy.
- **Declining asks for a reason and the screen states capacity up front**, since
  capacity is enforced at accept and discovering you are full *after* clicking is
  worse than being told first. `max: null` renders as "no limit set", never as
  "full" — a fresh trainer with an unconfigured profile is not at capacity.

**A shared-component bug the browser found, and tests could not.** `Modal`'s
focus effect listed `[onCancel]` in its dependencies, and every caller passes an
inline arrow — so the identity changed on every render, the effect re-ran, and it
moved focus again. In a form dialog that is a live defect: type one character,
state changes, focus jumps from the field to the confirm button, and **the next
space bar press activates it**. Typing "My lower-body slots are full…" into the
decline reason declined the request on the space after "My", with
`decline_reason` saved as the single letter `M`. The effect now runs once and
reads the current handler through a ref; a `form` dialog also opens on its first
field rather than on its confirm button, because autofocusing a destructive
button one stray space away from firing is the same hazard in slower motion.
This affected **every** form modal in the app — W2.2's health dialogs included —
and it survived four work packages of green tests because no test types a second
character into a modal.

*Verified in the browser* as `sarah.chen`: dashboard, client list, all seven
tabs, the assign dialog, and a private note written and deleted (which also
confirms the W3.1 CSRF desync was an artefact of my clearing the cookie, not a
defect — a fresh session writes fine). The request queue was exercised against a
seeded pending request, declined with a full reason, and the seed row removed.
Member Progress re-checked after the chart extraction; `Modal` re-checked on the
member's "Log weight" dialog. Gate: **phpcs 0 errors, phpunit 269 tests / 1555
assertions, `ui/` typecheck + lint + format + build green.**

**Deferred to the next slices:** the builders (workout, plan, food plan) and the
trainer profile; trainer messaging (the Messages tab points at it). The Health
tab shows weight and body fat derived from the progress payload — the fuller
health record needs its own endpoint with access logging (Q10), which does not
exist yet.

#### Slice 3 — builders, profile and messaging ✅ **complete 2026-07-29**

The trainer's own library — workout builder, plan builder, food plans — plus the
profile and messaging. Almost entirely front-end: slice 1 built every endpoint
these screens need, and the three server changes below were found by trying to
use them.

**Build notes.**

- **A messaging feature nobody could reach.** Nothing in the plugin created
  `fc_message_threads` rows. Every path — `threads()`, `thread()`, `send()` —
  starts from an existing thread, and the only ones in existence were the demo
  seeder's. So W3.1 worked perfectly against the fixtures and **not at all for a
  client accepted through the app**: both sides opened Messages, saw an empty
  list, and had no way to start a conversation. `MessageService::ensureThread()`
  now runs at **accept**, which is when the relationship begins and the first
  point at which both parties are known. Idempotent on `uq_thread`, so a
  re-accept reuses the existing history and two concurrent accepts cannot race.
  Creating it lazily on first send was the alternative and is worse: it needs an
  endpoint keyed on a *pair* rather than a thread id, and leaves the other side
  looking at nothing until somebody speaks first. Asserted from both sides,
  because the thread is what each of them sees the other through.
- **Read/write asymmetry in the plan payload.** `planFields()` accepted
  `price_weekly`, `weekly_sessions`, `duration_weeks` and `sort_order`;
  `plans()` returned none of them. A priced plan therefore renders with an empty
  price box, and a client that resubmits what it read writes the omission back
  as null. Fixed on the read side, and the test asserts the symmetry rather than
  one screen's use of it — the builder happens to send only changed fields,
  which limits this to display, but that is the client's choice and not the
  contract's guarantee. `calories_burn_estimate` had the same shape on the
  workout payload.
- **Two nullable columns were being flattened to zero.** `weekly_sessions` and
  `duration_weeks` are nullable and went through the same `max(0, (int))` cast as
  the NOT NULL columns, so an empty box wrote `0` — a plan that promises no
  sessions rather than one that does not say. They now take null, like the price
  tiers, and the same rule is stated in the UI: empty is *not offered*, 0 is
  *free*.
- **`plan_type` and `difficulty` were unwritable**, though 06 §3 asks for them.
  They are descriptive rather than entitlement-granting, so they were added — with
  the W3.3 fallback rule rather than a 400, since they arrive from a `<select>`
  and an unknown value is a client bug or a probe, neither worth discarding the
  rest of somebody's edit over.
- **The exercise editor is now shared.** The admin form owned the drag-reorder
  list; the trainer builder needed the same rows against the same upsert-by-id
  contract, and that rule is subtle enough that a second copy would have drifted
  from it — the argument that already put `replaceExercises` in one place on the
  server and `ProgressChartGrid` in one place on the client.
- **Metadata and exercises save together**, in one `PUT /trainer/workouts/{id}`
  rather than a metadata write plus `PUT .../exercises`. Two requests can
  half-succeed and leave a workout whose name says one thing and whose contents
  say another.
- **A platform workout is read-only, and says so before it is typed into.** The
  server would answer 403 to the save, so the form disables rather than letting a
  trainer spend a minute on a request that cannot land. The library offers
  "Duplicate" in place of "Edit"; the copy strips exercise ids, because they
  belong to the source's rows.
- **The feature-flag editor 06 §3 asks for is deliberately not built.** Slice 1
  decided `features` and `max_trainers` are not a trainer's to set — granting
  yourself `has_video_workouts` is selling something the platform never agreed
  to — and that outranks the older line. They are now returned **read-only** so
  the builder can state what the plan grants; a screen that showed neither would
  leave the trainer guessing what they are selling.
- **Messaging is one component for two readers.** The endpoints are account-keyed
  and symmetric — `MessageService` resolves the caller and returns the
  counterpart — so the screen and its hooks moved to `@shared`, parameterised
  only by who the other person is. The quota note needed no condition: the wire
  already sends `quota: null` to a trainer, because a reply spends nobody's plan.
  The `Conversation` pane is exported separately so the client record can embed
  the trainer's own thread inline, which is what 06 §2 specifies.
- **A slice-2 CSS regression that reached the login screen.** Slice 2 styled the
  client record's seven-tab bar as a bare `.fc-tabs` — the same selector the auth
  panel's segmented control has used since W1.3, and later in the file. So the
  **login and registration tabs** silently took on the trainer strip: `flex: 0 0
  auto` instead of `flex: 1`, so "Sign in" and "Create account" sized to their
  labels instead of splitting the width, and `.fc-tabs .fc-tab[aria-selected]`
  (0,2,0) beat `.fc-tab` (0,1,0) whatever the order, replacing the filled pill
  with a green underline drawn *inside* the pill container. Nothing in the auth
  rules changed — they are byte-identical to `a3c5bfb` — which is why it survived
  review: the whole defect was in the cascade, on a screen no trainer package
  opens. The strip is now `.fc-tabstrip`, which also drops the pill background
  and 4px padding it had been inheriting by accident. This is the third time this
  file has been bitten by a shared class name (W1.5's `#fc-app *` specificity,
  W2.4's `.fc-tab` reuse for the activity filter) and it has the same fix each
  time: a control that is not the segmented control gets its own name.
- **A positional selector that rendered two different wrong rows.**
  `.fc-history-list li > span:nth-child(2)` encodes "the second child is the
  title, and the title takes the slack". Seven of the eight lists are built
  date-then-title and satisfy it; the trainer's roster row reads name, optional
  `primary` tag, date — so the rule picked whichever happened to be second. With
  the tag present it stretched a green pill into a bar across the row; without it
  the **date** took the slack and went bold. One rule, one conditional, two wrong
  renderings. A row can now declare its own `fc-history-list__grow`, which
  switches the positional rule off for that row via `:has()`. The other seven
  were left alone deliberately — they are correct, and rewriting them to prove a
  point is seven screens to re-eyeball for no change in output.
- **The profile states capacity rather than implying it.** "2 of 40 clients" with
  the reason underneath, because a queue that simply goes quiet reads as a
  platform fault. `max_clients = 0` is *unconfigured*, never "full". The card
  deliberately reads the **saved** profile, not the form: it describes what the
  directory is doing right now, and an unticked box that has not been saved has
  not changed that.

*Exit criterion met*, verified in the browser as `sarah.chen` against the seeded
dev site. The workout builder loaded Upper Body Power with all eight exercises;
moving Bench Press down and saving left **ids 1 and 2 swapped in position but
identical in identity**, `MAX(id)` unmoved at 44 and all **24 exercise logs still
linked** — the W2.5 upsert rule holding through the new route. A plan created,
priced at 49.99 monthly and 129 quarterly with weekly and yearly left empty,
stored **NULL rather than 0** for the untouched tiers, and a second edit changing
only the description left every other field intact. The profile read 2 of 40
clients, open to requests, 4.9 from 132 clients. Messages listed **a client**
rather than a trainer with no client-side change, sent, and rendered inline on the
client record with no back button. No console errors. Every row this pass created
— one message, its notification, one plan — was removed afterwards and the
exercise order restored. Gate: **phpcs 0 errors, phpunit 274 tests / 1605
assertions, `ui/` typecheck + lint + format + build green.**

**Deferred, and named rather than dropped:** the **food-plan meal editor** (06 §5
and the Phase-4 line both mark it deferrable; `fc_food_plan_meals` exists, the
endpoints do not, so it is a service and routes rather than a screen) and the
**exercise library** (same Phase-4 line). Also: **attached workouts and food
plans on a plan** — 06 §3 lists them but no join table exists in the 29
migrations, so they need schema before they need a screen; **canned responses**
and **attaching a workout to a message** (`POST /messages/attachments` uploads an
image, not a resource reference); the **workout preview as the client sees it**;
and the trainer **Support** view (§7). The Health tab still shows only what the
progress payload carries — the fuller record needs its own endpoint with Q10
access logging.

#### Full package scope
All `/trainer/*` endpoints with **both** ownership guards — shared read across
trainers (Q13), private write to your own assignments — then dashboard, client
list, client detail (7 tabs, every assignment attributed), workout builder, plan
builder, messages, private notes, profile, and the **client request queue** (Q4:
accept/decline/capacity). Assignment-conflict warnings. Food-plan builder and
exercise library deferrable to Phase 4 (−10 d). No earnings screen (Q2).

### W3.5 Trainer directory & requests, user side — ✅ **complete 2026-07-29**
Directory with specialization filter and capacity/accepting gating, public trainer
profile, request form with optional message, "My Trainers" in profile, request
rate limiting, notifications both directions. Reachable from main navigation, not
just onboarding — with multiple trainers allowed (Q3), adding a coach is an
ongoing action.

**Entitlement gating** (Q14): requests require an active subscription and respect
the merged `max_trainers` cap counting pending requests. Three directory states to
build — can request / `subscription_required` / `limit_reached` — each with its own
CTA, driven by the server's `eligibility` block. Depends on `EntitlementService`
from W3.2, so sequence W3.5 after it.

Q2, Q3, Q4, Q13, Q14 and Q15 are all settled. Only **Q16** (grandfathering on
downgrade — planned yes) remains, and it does not block the build.

**Build notes.**

- **The projection is the feature.** These are the only endpoints where one
  member reads another account's record, so `TrainerDirectoryService::present()`
  is the single place a trainer becomes public — and the test asserts what is
  *absent* (`email`, `phone`, `account_id`) rather than what is present, because
  the way this leaks is somebody adding a column and splatting the row. A second
  query building its own column list would leak from whichever one was forgotten.
- **A nullable column that would have hidden the whole directory.**
  `fc_trainers.max_clients` is `DEFAULT NULL`, and the listing filter first read
  `max_clients = 0 OR client_count < max_clients`. In SQL that evaluates to NULL
  for every trainer who has never configured a limit — and a NULL `HAVING` drops
  the row, so the directory would have been **empty on a fresh install** while
  being perfectly correct on the seeded one. `TrainerService::capacity()` already
  treats NULL and 0 alike; the filter now does too, and a test pins it.
- **Eligibility is computed once, on the server.** The three CTA states come from
  the `eligibility` block rather than being inferred from a subscription object,
  so the button and the endpoint that would refuse it cannot disagree. Every
  mutation returns the recomputed block, which is why requesting flips the
  screen to `limit_reached` without a refetch.
- **A pending request spends the slot immediately** (Q14) — the rule that stops a
  one-trainer plan queueing five requests and keeping whichever lands first.
  Withdrawing gives it back. Both directions are tested and both were driven in
  the browser.
- **`limit_reached` is not an error, and a plan with no slots is not a full
  one.** Grandfathering (Q16) makes "2 of 1 used" a state somebody reaches by
  changing plan, so the copy never implies a mistake. Separately, the seeded free
  tier has `max_trainers: 0`, where "end a coaching relationship to free a slot"
  is advice the member cannot act on — they have none. That gets its own copy:
  the plan does not include a trainer.
- **The rate limit counts rows, not a transient.** 3 outstanding and 10 in a
  rolling week are statements about requests that exist, so a cache eviction must
  not hand somebody a fresh ten. Rolling rather than calendar, for the reason the
  messaging quota is: a boundary the member cannot see is one they cannot plan
  around.
- **Leaving and withdrawing are one endpoint and two terminal states.** Which
  happened is decided by the row's status, not by the caller, so a client cannot
  withdraw an active relationship into the wrong state. `inactive`,
  `withdrawn` and `declined` stay distinct — collapsing them loses the ability to
  tell "we stopped working together" from "I changed my mind" from "they said
  no".
- **Ending the primary relationship promotes another.** Otherwise a member with
  two coaches drops to zero primaries and the dashboard reads "no trainer" while
  somebody is actively coaching them.
- **A notification nobody would ever have received.** `TrainerService::notifyClient`
  hardcoded type `workout` for *every* message including "Your trainer request was
  accepted" — and W2.4 honours preferences at the **write**, so a member who had
  switched workout notifications off was not told a trainer had taken them on.
  The row was not misfiled; it was never created. Relationship events are `system`
  now, and the type is a parameter so the next caller has to choose one.

*Exit criterion met*, verified in the browser as Sam Wilson (Pro, one slot free)
against the seeded dev site: the directory listed all four coaches with ratings,
bios and capacity, Lisa Park correctly tagged **YOUR COACH** rather than offered
a request that would 409, and the header read "1 of 2 trainer slots used".
Requesting Mike Torres with a 61-character message stored the message intact,
raised a **`system`** notification on his account, moved the screen to "2 of 2"
with every Request button disabled and the upgrade CTA shown — then withdrawing
returned it to "1 of 2" and re-enabled them, all without a manual refresh. No
console errors. The request row and its notification were removed afterwards.
Gate: **phpcs 0 errors, phpunit 289 tests / 1716 assertions, `ui/` typecheck +
lint + format + build green.**

**Deferred:** **My Trainers lives on `/trainers` as a second panel, not under
`/profile`** as [04](04-user-app.md) has it — the member app has no profile
screen yet, and inventing one to host half a list is a larger change than this
package. Coaches you have and coaches you might have are one subject and share
one slot counter, so they sit together; when the profile screen lands this can
move or link. Also deferred: **auto-decline after N days** (09 §4.4 asks for it;
it needs a scheduled job and a policy on how long is too long) and the
**onboarding** trainer-request step, which belongs with the unbuilt
`/onboarding` wizard.

**Phase 3 exit:** money flows, trainers coach, users can get help.

---

## Phase 4 — Polish (12–16 d)

| # | Work | Days |
|---|------|------|
| 4.1 | **Front-end themes** — WordPress themes shipping React apps, per-role, with the plugin's apps as fallback. **Re-scoped 2026-07-29, see below** | 2 |
| 4.2 | JWT + mobile API: token/refresh, delta sync (`?modified_since=`), pagination audit, response compression | 3 |
| 4.3 | Performance: query profiling against the volume seed, index tuning, object-cache integration, asset budgets | 2 |
| 4.4 | i18n: `.pot` generation, JS translation loading, RTL check | 1 |
| 4.5 | Accessibility audit against WCAG AA, keyboard paths, screen-reader pass | 2 |
| 4.6 | Docs: OpenAPI from route schemas, README, admin guide, trainer guide, theme dev guide | 2 |
| 4.7 | Export/import: CSV per admin resource, GDPR exporter/eraser hooks | 2 |

### W4.1 Front-end themes — re-scoped 2026-07-29

> **What changed.** Every plan document described a theme as a `theme.json` of
> colours. The owner corrected this before any of it was built: **a theme is a
> WordPress theme that ships React apps.** Recorded as
> [D11](00-architecture.md#d11--a-theme-is-a-front-end-not-a-palette-supersedes-d7-extends-d10-2026-07-29).
>
> **The palette system is deferred indefinitely** — tokens, JSON validation,
> contrast checks, the light theme, the theme editor, the `/admin/themes`
> endpoints. Not built, not scheduled. When a theme is selected everything comes
> from that theme's app; when one is not, the plugin's apps keep their existing
> styling. [07](07-theming.md) keeps the design as a record.
>
> 4 d → **2 d**, and the five other deliverables the line used to carry are gone.

**Scope.** A theme is a directory in `wp-content/themes/` declaring
`Fitness Plugin Extension Enabled: true` in `style.css`, with a Vite build under
`fitnessclub/`. There is **no bespoke manifest**: the plugin reads the theme's own
`.vite/manifest.json` the way `Support\ViteAssets` already reads the plugin's, so
whichever of `user`/`trainer`/`admin` it has an entry for, the theme provides.

- `ThemeExtension` — discovery via `wp_get_themes()` filtered on the header (read
  with `get_file_data()`, not `WP_Theme::get()`, for the caching reason in
  [07](07-theming.md#discovery-and-selection)), manifest read, `realpath()`
  containment on asset paths, per-role entry resolution.
- `Support\ViteAssets::tags()` — ask the selected theme first, fall back to the
  plugin's manifest. One lookup in front of an existing seam.
- `theme.extension` in `config/options.php`, empty meaning the plugin's own apps.
- The **dropdown** on `Http\Controllers\Admin\SettingsController` + its Blade view.
- A **demo theme fixture** under `tests/`, providing exactly one role — a fallback
  nothing exercises is a fallback that does not work.

*Done when:* a theme providing only `user` is dropped in `wp-content/themes/`,
selected from the dropdown, and serves its own member app while trainers and
admins keep ours. Deleting the theme directory under a running site returns
everyone to the plugin's apps rather than 500ing.

**Sequencing consequence:** 4.6's OpenAPI documentation is no longer optional.
The boot payload and REST API are now a contract third-party apps compile against.

#### Status — ⏳ **built, browser pass outstanding, 2026-07-29**

**Build notes.**

- **The fallback is the feature, so the tests are almost all about it.** A swap
  that works is easy; a swap that degrades correctly when the theme is partial,
  unbuilt, deleted underneath the site, or shipping a manifest that points
  outside itself is the thing worth building. Eight of the twelve tests are
  refusal paths, and every one of them ends in "this role gets the plugin's app"
  rather than an error — a selected theme is a preference, never a dependency.
- **`realpath()`, not a `../` string check.** A symlink out of the theme's
  `fitnessclub/` directory is the same escape written differently, and only
  resolving the path catches both. The containment check compares against
  `rtrim($root) . DIRECTORY_SEPARATOR` deliberately: without the trailing
  separator, `…/fitnessclub-evil` passes as a child of `…/fitnessclub`.
- **A theme's entry is matched by convention with a fallback.** `src/{role}/main.tsx`
  is tried first, so a theme built like the plugin matches immediately; failing
  that, entries are scanned for a matching `name` or a `{role}/main.*` suffix.
  Requiring a byte-identical source path would make our directory layout a build
  constraint on every theme rather than a default.
- **Dev mode short-circuits past themes on purpose.** `FITNESSCLUB_VITE_DEV` is a
  wp-config switch for developing *this plugin's* apps against its HMR server;
  honouring a theme there would serve a built bundle to somebody who just turned
  hot reload on.
- **An unusable theme is listed with its reason, not hidden.** "My theme does not
  appear in the dropdown" is a worse support call than "my theme appears and says
  why it cannot be used". The one case that genuinely cannot be listed is a theme
  WordPress rejects outright — `wp_get_themes()` returns only error-free themes,
  so a theme missing `index.php` is invisible here whatever its header says, and
  the screen states that rather than leaving it to be discovered.
- **Saving names the roles the theme does *not* cover.** "It works, but trainers
  still see the stock app" is otherwise found out by a trainer.
- **`sanitize_text_field`, not `sanitize_title`, on the submitted value.** It is a
  stylesheet *directory name*: `sanitize_title()` would lowercase `fitnessTheme`
  into a directory that does not exist. The value is then only accepted if it is
  a theme currently declaring the header, so a stale POST cannot point the front
  end anywhere. Clearing it is always accepted — it is the way back, and it has
  to work when the selected theme is the thing that broke.

**`fitnessTheme`, the demo theme, is the deliverable the fixtures could not be.**
Built at `wp-content/themes/fitnessTheme` with a real Vite workspace — React 18,
`outDir: fitnessclub/`, `base: './'` because a theme does not know where it is
installed and absolute asset URLs would bake in one site's directory name. It
provides **all three roles**: sign-in and chrome shared in `Shell.tsx`, then the
member dashboard, the trainer roster and the admin overview, each against one real
endpoint. Its `README.md` doubles as the theme-developer guide 4.6 owes.

It shipped `user` and `trainer` first and gained `admin` on the owner's
instruction. Worth noting what that cost: **the demo no longer exercises the
per-role fallback**, because there is no role left for the plugin to serve. That
path is still covered — `fc-fixture-apps` provides `user` alone precisely so it
keeps a test — and the README documents deleting an entry from `input` as the way
to see it live. A demo that covers everything covers nothing about what happens
when a theme covers only part.

**The admin role is the one with teeth**, and it is the argument for where the
selector lives. A broken member app inconveniences members; a broken admin app
takes away the screen an administrator would use to fix it. Putting the dropdown
in wp-admin rather than in the admin SPA is what makes replacing the admin app a
recoverable decision rather than a one-way door.

**The demo build immediately found a hole in the resolver.** Vite hangs the
stylesheet off the **shared chunk** whenever two entries import the same CSS —
which is the normal outcome for a theme with more than one app — so the entry's
own `css` array is *empty* and both apps would have rendered unstyled. The
manifest read now walks `imports` for CSS as well, and preloads those chunks while
it is there. The single-entry fixture could never have caught this: it has no
shared chunk.

**Two traps worth recording.**

1. `wp-settings.php` uses `$plugin` as a global loop variable and `unset()`s it
   when the loop ends, so any script that loads WordPress at global scope and
   keeps a `$plugin` of its own silently loses it. The fixture themes appeared
   undiscoverable until the variable was renamed, which briefly looked like a bug
   in `register_theme_directory()`.
2. **`ViteAssetsTest` was environment-dependent and the full suite caught it** —
   the same defect as W3.2's hardcoded `evt_1` webhook id, and found the same way.
   It asserted that every role resolves into `/public/ui/assets/`, which D11 makes
   true only when *no* theme is selected; the moment `fitnessTheme` was selected on
   the dev install, two of its cases failed. That test is about the plugin's own
   manifest resolution, so it now pins `theme.extension` to empty for its duration
   and restores it. A test that passes only because nobody has changed a global
   yet is not passing for a reason.

*Verified against the real WordPress runtime*: with `fitnessTheme` selected, all
three roles resolve **from the theme** — `user-q4A3YOwb.js`, `trainer-OqSNO57D.js`
and `admin-CGQF8sAF.js` — each with the shared chunk preloaded and the shared
stylesheet linked. Before the admin entry existed, the same check showed `admin`
resolving to the plugin's `admin-DxhRdYGl.js` with its own `Modal`/`ExerciseEditor`
chunks, which is the fallback working. Rebuilding the theme changed every hash and
the plugin picked them up with no change on its side, which is the whole claim for
reading the theme's own Vite manifest instead of a hand-written file list.
Selections naming a deleted theme, and a theme whose manifest escapes its own
directory, both return all three roles to the plugin. The dev site's **Twenty
Twenty-Five already carries the header** and is correctly listed as unusable with
"No Vite build found".

*Verified in a browser* at `/fitness/`: the theme's own sign-in screen renders —
light and violet against the plugin's dark green, so the swap is unmistakable —
with the brand read from `data-boot` and **no console messages at all**. Gate:
**phpcs 0 errors, phpunit 301 tests / 1742 assertions** (was 289/1716), green
*with the theme selected*, which is the stronger claim.

**Outstanding:** the signed-in screens of the demo theme (member dashboard,
trainer roster, admin overview) have not been eyeballed — that needs a sign-in,
and the plugin-side feature it would exercise is already covered. `theme.extension`
is currently set to `fitnessTheme` on the dev install, so **all three** apps now
come from the theme; the dropdown's first option puts it back.

---

## Sequencing rules

**Hard dependencies:**

```
0 ──► 1.1 ──► 1.2 ──► 1.3 ──► 1.4 ──► 1.5 ──► 1.6
                              │
                              ├──► 2.1  2.2  2.3  2.4   (parallel after 1.4)
                              └──► 2.5  (needs 1.2 + 1.3)
2.5(settings) ──► 3.2 ──► 3.4 ──► 3.5   (3.5 needs the trainer accept/decline side)
1.3 ──► 3.1
all ──► 4.1 (theming touches every component's CSS)
```

**Do first, regardless of pressure:**

1. The DB-version upgrade dispatcher (W1.1) — unbuildable retroactively without a
   migration to fix migrations.
2. The ownership `Guard` (W1.2) — retrofitting authorisation across 80 endpoints
   is a rewrite.
3. Theme tokens instead of hex literals (W1.5) — see [07](07-theming.md#migration-hazard).
4. The session-state-machine test (W1.4) — it is the product.

**Safe to cut for a v1:** food-plan builder, exercise library, barcode scanning,
live chat, video hosting (link to YouTube/Vimeo instead), calendar heatmap,
CSV import, PayPal, the typing indicator, assignment-conflict warnings.

**Not safe to cut,** though the prototypes might suggest otherwise: auth screens,
settings, plan management, empty/error states, the nonce-refresh retry, and — now
that users initiate assignment (Q4) — the trainer directory and request flow,
which are the only path from signup to being coached.

## Definition of done (per work package)

- [ ] Endpoints have `args` schemas with sanitise + validate callbacks
- [ ] Capability **and** ownership checks, with a test asserting 403 on a foreign id
- [ ] Service unit tests; ≥85 % coverage on services
- [ ] UI: loading, empty, and error states — not just the happy path
- [ ] Responsive at 375 / 768 / 1440 px
- [ ] No hex literals outside the token file
- [ ] Strings wrapped in `__()` with the `fitnessclub` text domain
- [ ] No PHP notices with `WP_DEBUG` on; no console errors
- [ ] Migration applies to an empty DB *and* to the previous release's DB

## Milestones

| Milestone | After | Demo |
|-----------|-------|------|
| M1 Skeleton | Phase 0 | Plugin activates, REST responds |
| M2 First workout | W1.5 | Complete a workout on a phone |
| M3 Full tracker | Phase 2 | Log everything, admin runs the site |
| M4 Commerce | W3.2 | Subscribe with a real test card |
| M5 Coaching | Phase 3 | User requests a trainer, trainer accepts and assigns, client receives — and a second trainer can see the first's programming |
| M6 Ship | Phase 4 | Themed, documented, accessible, fast |

---

## Build notes — historical (from the deleted first implementation), 2026-07-24

> ⚠️ These record what was learned during the **first, now-deleted** hand-rolled
> implementation (see the reset banner at the top). The *approach* here — building
> directly on `wpbones/wpbones` and patching the namespace by hand — has been
> **superseded**: we now start from the actual `WPKirk-Boilerplate` and rename it
> the native way (Phase 0.1). The *technical facts* below (the CLI-exit trap, dbDelta
> rules, activation ordering, dbDelta idempotency) still hold and are the reason the
> redo is transcription, not rediscovery.

### `bones rename` and the plans-corruption risk — now handled by stashing

`bones rename` performs a global `str_replace` across **every file in the project**
(only `node_modules` and the bones binary excluded — Markdown included), so it would
rewrite `plans/00-architecture.md`'s legitimate "WPKirk-Boilerplate" citations.

The first implementation avoided the command entirely and hand-patched the vendor
namespace. **Superseded.** With the boilerplate as the base we *do* use native
`bones rename` — but stash `plans/`, `user-app/`, `admin-app/` out of the tree for
the one rename pass, then restore them (Phase 0.1). That keeps us on the framework's
blessed path without corrupting the planning artifacts. `php bones make:*` reads the
`namespace` file and works normally.

### The accessor function is not in the framework

### The accessor function is not in the framework

`vendor/.../src/helpers.php` calls `FitnessClub()` but never defines it — the
boilerplate defines it in `bootstrap/autoload.php`, along with a static class
holding the Plugin instance. Missing this is a runtime fatal the moment any
helper is used. Now present.

### Activation ordering (corrected in [01](01-database.md#migration-mechanics))

`Plugin::_activation()` runs *options delta → plugin/activation.php →
migrations → seeders*. The schema-version dispatcher cannot live in
`activation.php` because no table exists yet; it runs from `UpgradeProvider`
on `init`.

### Verification performed

| Check | Result |
|---|---|
| PHP lint, all plugin files | 51/51 pass |
| Migrations vs MySQL 9.7.1 (throwaway DB) | 29 tables, 35 FKs, 94 indexes, all InnoDB |
| Re-run migrations (idempotency) | table count unchanged, 0 SQL errors |
| Plugin boot inside WP 7.0.2 | instance, config, providers, classes all resolve |
| `GET /wp-json/fitnessclub/v1/health` | 200 |
| Side effects on the real database | **none** — 0 tables, 0 roles, still inactive |

The migration run used a scratch database (`fitnessclub_migration_test`,
created and dropped by the harness). The development database was never
written to; activating the plugin is left to you.

### Still open from Phase 0

0.4 webpack/TS/Jest for three entries · 0.5 PHPCS/ESLint/Prettier ·
0.6 GitHub Actions · 0.7 local WP env. Plus `git init`, which has not been run.

### Known upstream wart

`wpbones/wpbones` v2.0.3 emits a PHP 8.4 deprecation from
`src/Support/Str.php:425` (implicitly nullable parameter). Harmless, but it will
surface in logs with `WP_DEBUG` on. Upstream fix or a suppression is a Phase 4
housekeeping item.

---

## Build notes — activation + seeders, 2026-07-24

### A silent activation failure worth knowing about

The first `wp plugin activate fitnessclub` reported **"Plugin activated. Success."**
and did essentially nothing: no tables, no roles. WordPress does not verify that
`register_activation_hook()` was given a file that exists.

Cause: `Plugin.php:101` hardcodes

```php
$this->file = $this->basePath . '/wp-kirk.php';
```

WP Bones expects `php bones rename` to rewrite the plugin **slug** as well as the
namespace. `bin/patch-vendor-namespace.php` was only rewriting the namespace, so
the activation and deactivation hooks were bound to a non-existent file and never
fired. `load_plugin_textdomain('wp-kirk')` on line 252 was broken the same way.

Fixed by extending the patcher with slug substitutions (`wp-kirk` → `fitnessclub`,
`wp_kirk` → `fitnessclub`). **Anyone replacing the rename step must patch the slug,
not just the namespace** — and the failure mode is silent, so verify by counting
tables rather than trusting the success message.

### Seeder split

WP Bones includes `database/seeders/*.php` on **every activation**, which makes it
the wrong home for demo data.

| What | Where | Runs |
|---|---|---|
| Platform tiers (free/pro/team) — the product cannot work without them | `database/seeders/0100_platform_plans.php` → `PlatformPlansSeeder` | every activation, idempotent |
| Prototype dataset | `PlatformPlansSeeder` + `DemoSeeder`, via `php bin/seed.php --demo` | on demand only |
| Synthetic bulk data | `VolumeSeeder`, via `php bin/seed.php --volume=N:D` | on demand only |

Everything upserts on a natural key, so re-running converges instead of
duplicating. Verified by running `--all` twice: identical row counts.

Volume users are `fc_users` rows with `wp_user_id >= 900000` and no WordPress
account, so they can never be confused with real users and
`--purge-volume` can remove them safely.

### The demo fixtures contradicted the Q14 rule

First seed run produced two states the specification forbids:

- Alex Morgan on Pro (`max_trainers` 1) with **2** active trainers
- Jamie Lee on Free (`max_trainers` 0) with a **pending** trainer request

The prototype's own canonical user is "Pro with two trainer conversations", so the
plan tier was the thing that was wrong, not the prototype. **Pro is now
`max_trainers: 2`**, which also makes Alex sit exactly at his cap — a free fixture
for the `limit_reached` directory state. The free-tier requests were moved onto
users who actually have slots.

Fixture data that contradicts the spec is worse than no fixture data: it teaches
the wrong model and hides the bug it should expose. There is now a consistency
check to re-run after changing plans or links.

### Index strategy validated under load

Profiled against 17 145 sessions / 30 001 nutrition days / 15 006 health rows
(250 users × 120 days, generated in ~3 s):

| Query | Index used | Rows | Time |
|---|---|---|---|
| Dashboard weekly chart | `idx_user_date` (range) | 5 | 0.53 ms |
| Streak scan | `idx_weekly` (ref) | 69 | 0.63 ms |
| Session history page | `idx_user_date` (ref) | 69 | 0.53 ms |
| Nutrition day lookup | `uq_user_day` (const) | 1 | 0.23 ms |
| Health 90-day range | `uq_user_date` (range) | 46 | 0.47 ms |
| Trainer client list | `idx_trainer_status` (ref) | 2 | 0.49 ms |

No full scans. Note MySQL 9 defaults `EXPLAIN` to TREE format — use
`EXPLAIN FORMAT=TRADITIONAL` for the classic `key`/`rows` columns.

### Current state of the development site

Plugin **active**; 29 tables, 35 FKs, 94 indexes; roles `fc_user` (9 caps) and
`fc_trainer` (10 caps); 12 `fc_*` caps on administrator; schema version 1;
10 WordPress accounts created (`*.fitforge.test` emails, random passwords);
demo dataset loaded; volume data purged.

### W1.1 is complete

Migrations, base migration class, upgrade dispatcher, demo seeder, volume seeder.
Remaining Phase 0: 0.4 webpack/TS/Jest · 0.5 PHPCS/ESLint · 0.6 CI · 0.7 wp-env,
plus `git init`.

---

## Build notes — Phase 0.5 (gates), 2026-07-25

### `functions.php` — the one justified edit

Running the PHP tools empirically confirmed the CLI-exit trap: launching phpcs or
phpunit loads Composer's autoloader, which loads `functions.php`, whose bare
`exit()` silently kills the process (`php -r 'require "vendor/autoload.php"'`
produced no output, exit 0). There is **no wpBones-native alternative** — the
boilerplate ships no PHP test harness — so the direct-access guard was scoped to
non-CLI SAPIs:

```php
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') { exit(); }
```

This is the standard WP+Composer fix, minimal, and the documented last-resort
`functions.php` edit. Nothing else in `functions.php` changed.

### The gate

- **PHP** — `phpcs.xml`: PSR-12 + `WordPress.DB.*` (no-raw-SQL, ERROR on the request
  path, WARNING on future migration/seeder tooling) + `WordPress.Security.*` +
  PHPCompatibilityWP; `ignore_warnings_on_exit` so CI gates on errors. Two
  documented `phpcs:ignore`s: the intentional global `FitnessClub` accessor class,
  and the shell's raw-HTML echo (values escaped inside the Blade template).
- **PHPUnit** — integration only, against the real running site (`tests/bootstrap.php`
  loads `wp-load.php`). 15 tests: `AppRouter` role→SPA precedence, `ViteAssets`
  manifest resolution per role, the REST health route, and the rewrite rule/query
  vars. `IntegrationTestCase` creates throwaway users and deletes them in
  `tearDown` — verified zero leftover after a clean run.
- **JS (`ui/`)** — ESLint 9 flat config (typescript-eslint + react-hooks +
  react-refresh, Prettier owns formatting) + Prettier + `tsc --noEmit`. All green.
- `composer check` and the four `npm` scripts are the CI gate for 0.6.

### Accepted dev-only advisory (JS)

`npm audit` reports 5 high `brace-expansion` advisories (GHSA-mh99-v99m-4gvg,
DoS/OOM, range `<=5.0.7`) reached transitively through ESLint's config-file glob
matcher (`minimatch`). Not accepted lightly:

- **Not exploitable here** — it is ESLint expanding its own config globs at
  lint time, never untrusted input, and never shipped to production.
- The forced fixes both regress the toolchain: overriding `brace-expansion` to the
  patched `5.0.8` **breaks ESLint's own path-matching** (incompatible with the
  `minimatch` it uses), and ESLint 10 (npm's suggested fix) is **rejected by
  `eslint-plugin-react-hooks`** (peer caps at ESLint 9) — and react-hooks catches
  real bugs, so dropping it is a net loss.
- **Clears itself** once `eslint-plugin-react-hooks` supports ESLint 10; revisit
  then. Tracked here rather than papered over.

### Phase 0 remaining

0.6 GitHub Actions CI (run both gates + migration idempotency), 0.7 `git init` /
local env note. Then Phase 1 proper.

---

## Build notes — plugin-owned identity (W1.2R/W1.3R), 2026-07-27

Requested change: *"admins for the fitness plugin are different from wordpress
admins, so an admin logged in as wp admin would still need to separately log in
to a plugin"*, plus generated first-admin credentials shown once, plus three
example accounts. Decisions taken up front: a **fully separate credential
system** (not a second WordPress account), WordPress admins get **nothing**
without a plugin account, one example account **per role**, generated passwords
keep the readable shape with added entropy, and the dev database is **wiped and
re-seeded** rather than backfilled.

Recorded as [D4a](00-architecture.md#d4a--identity-the-plugin-owns-its-accounts-supersedes-half-of-d4-2026-07-27).

### What the change actually cost, measured

Narrower in code than in schema, which is the opposite of the intuition. Only
four choke points read WordPress identity — `MemberController::asMember()`,
`MemberController::requireCapability()`, `AuthController::requireSession/Guest`
and `AppRouter::currentRole()` — but **13 of 29 migrations** carried a
`*_wp_user_id` column. `Guard`, the class everyone expects to be the hard part,
needed four SQL literals and a parameter rename: it calls no identity function
at all, so it was always pure SQL over whatever id it was handed.

### The pieces that got deleted, and why that is the interesting part

- **`Ajax/NonceProvider`** existed because a `wp_rest` nonce expires on its own
  12–24 hour clock **independently of the session** — the app silently stopped
  saving while still looking signed in — and a REST refresh route is rejected
  before it can run, so the refresh had to go through admin-ajax. Our CSRF token
  is minted with the session row and lives exactly as long as it. There is no
  state where the session is good and the token is stale, so there is nothing to
  refresh: session death is now a clean 401 and the login panel.
- **`AuthServiceProvider::bridgeLoggedInCookie()`** existed because
  `wp_create_nonce()` reads the session token out of `$_COOKIE` and
  `wp_set_auth_cookie()` never populates it, so a nonce minted later in the login
  request was bound to the wrong session. Ours is generated in memory in the same
  request. No round trip, no bridge.
- **`RoleProvider`** — leaving the WP roles in place would have left a second,
  stale authority that granted nothing but read as if it did.

That is ~120 lines of PHP and ~90 of client code that stopped existing. The
client's whole retry-on-stale-nonce path went with them.

### Decisions worth keeping

- **`password_hash()`, not `wp_hash_password()`.** The latter is a *pluggable*
  function: any plugin or a host's mu-plugin may redefine it, which would change
  the hashing of the table this plugin owns without the plugin knowing. For a
  credential store whose premise is independence from WordPress, delegating the
  one irreplaceable operation to a function a third party may replace is
  self-defeating. (Also: it fires `check_password` with a `$user_id` that would
  be an fc_accounts id in a slot every listener reads as a wp_users id, and on
  WP < 6.8 it produces phpass hashes.)
- **The bcrypt pre-hash is keyed on a fixed literal, never `wp_salt()`.**
  Rotating salts in `wp-config.php` is routine and offered as a one-click button
  by several security plugins; keyed on them, every password in `fc_accounts`
  would become unverifiable the moment somebody pressed it, with no recovery but
  a site-wide forced reset.
- **A dummy `password_verify()` on unknown logins.** Owning the hash introduced a
  timing oracle `wp_signon()` never had: an unknown login returns in
  microseconds, a known one spends ~390 ms in bcrypt, and no amount of careful
  wording hides that. The dummy hash has to be a *valid* one — verifying against
  a malformed hash returns immediately, which is the fast path it exists to
  avoid.
- **`SameSite=Lax`, not `Strict`.** Lax is the structural CSRF defence and it is
  free, because every state-changing route here is a non-GET. Strict would
  additionally withhold the cookie when a member clicks the password-reset link
  in their email, which is a flow this plugin has.
- **The CSRF token is bound to the session** (`fc_sessions.csrf_hash`). Plain
  double-submit is defeated by anything that can set a cookie on the parent
  domain — a sibling subdomain is same-site — and the binding is what closes it.
- **The token rides in the beacon's JSON body, not the query string.** The old
  `?_wpnonce=` fallback wrote a bearer value into access logs, `Referer` headers
  and every proxy in between.
- **Reset redemption is a conditional `UPDATE` with `rows_affected` checked**,
  not read-then-write. A mail client that prefetches links plus the human
  clicking one is two concurrent redemptions, and that race is not theoretical.
- **Never filter `determine_current_user`** with an account id, however tempting
  it is to make `current_user_can()` "just work". `fc_accounts.id = 7` and
  `wp_users.ID = 7` are unrelated rows. Instead `wp_set_current_user(0)` runs on
  our REST namespace and on the app render, so anything missed in the conversion
  fails closed rather than honouring a wp-admin session.

### Bootstrap: where it had to live, and why not two other places

`plugin/activation.php` runs **before** the migrations, so it cannot touch
`fc_accounts`. And `Manager::run()` short-circuits on a fresh install, so an
upgrade step would have worked on this dev database and silently done nothing on
every new site — the failure mode that tests clean. So `AccountBootstrap` carries
its own option guard and runs from `UpgradeProvider` on `init`.

It creates four accounts — the first administrator plus one example of each role
— and holds their plaintext passwords in a **15-minute transient** that the admin
notice renders and deletes in the same request. Refresh and they are gone, as
asked. The cost, stated rather than hidden: four plaintext passwords sit in
`wp_options` for up to fifteen minutes, which is why the TTL is short, the delete
is unconditional, and the notice says to change them.

`passFalcon` on its own is one dictionary word behind a public login endpoint —
about twelve bits against a format anyone can read in the source. The four-
character suffix (`passFalcon7K3Q`) takes it to roughly thirty-six while staying
short enough to copy off a screen; the alphabet omits `0/O/1/l`.

### Two things the conversion exposed in the test harness

1. **Signing in creates rows.** `ensureProfileRow()` heals accounts made outside
   the app, so *any* member login writes an `fc_users` row — which then blocks
   the account's deletion through the new `ON DELETE RESTRICT`. Teardown now
   removes profiles before accounts, and the FK is what made a pre-existing
   sloppiness visible.
2. **A rate-limit bucket keyed on a constant is a time bomb.** The
   forgot-password test used a hard-coded unknown address; the per-address bucket
   is a one-hour transient, so it passed three times an hour and then reported a
   broken endpoint. Now randomised per run.

*Exit criteria met.* Database wiped and rebuilt to **32 tables**; four bootstrap
accounts created with the credentials shown once and gone on refresh; login,
CSRF rejection (`fc_csrf_missing` / `fc_csrf_mismatch`), flat credential errors
and the W1.6 dashboard all driven end to end against the dev site as a seeded
member. **The requirement verified directly:** signed in to wp-admin as a
WordPress administrator with `manage_options` and no plugin account, `/fitness/`
resolves to the *user* SPA (the login screen) and
`GET /user/dashboard` answers 401. Gate: **phpcs 0 errors, phpunit 131 tests /
602 assertions, `ui/` typecheck + lint + format + build green.**

**Not verified:** the member SPA has still not been eyeballed in a browser — the
dev browser session is a WordPress administrator, which by construction now sees
only the login panel, and signing in as a member means typing a password.

### Follow-up — the wp-admin settings screen, 2026-07-27

Requested: *"plugin should have its own options page in the root not under
Settings, there admins could set urlRoot endpoint after domain.com/{rootPlugin}
and also example users should be shown there until they are deleted from db"*.

**Top-level menu**, not a child of Settings — `config/menus.php`, position 26,
`dashicons-heart`. This is now the only place in the plugin where a WordPress
capability decides anything, and it has to be: `admin.php` calls
`auth_redirect()` before any plugin callback runs, so a wp-admin page cannot be
gated on a plugin account however much D4a would prefer it.

**Two things live there**, and both belong in wp-admin rather than in the SPA
for the same underlying reason — they are what you need *before* you can use the
app:

- **The app URL.** The SPA cannot own the setting that decides where the SPA is
  served. Validation rejects an empty slug, the reserved WordPress paths, and a
  slug an existing page already occupies (which one wins there depends on rewrite
  rule order, and that is not something an administrator should discover by
  clicking).
- **The generated accounts**, listed until their row is gone from the database.
  They exist precisely because nobody could sign in yet, so the screen that lists
  them cannot require signing in.

**Three things worth keeping from the build.**

1. **Form handling goes on `load-{$hook}`, not the `post` verb.** wpBones
   dispatches the `post` route inside the page render — by which point wp-admin
   has printed its header and `wp_safe_redirect()` can only emit "Cannot modify
   header information". Found by clicking the button, not by reading: the first
   attempt reset the password correctly and then rendered two PHP warnings
   instead of a confirmation. A settings screen must redirect after a write, or a
   refresh re-submits it — and one of these actions deletes an account.
2. **Which accounts are "generated" is an option, not a column.** It is a fact
   about the *installation*, not about the account — the row is an ordinary
   account in every other respect, and a flag on it would invite code to treat it
   as a lesser one. The screen intersects the recorded ids with the rows that
   still exist, so deleting an account is all it takes to remove it from the
   list, from anywhere: the screen, the CLI, or SQL.
3. **The delete button refuses the last administrator.** There is no WordPress
   account to fall back on, so that click would lock everyone out of the app with
   no route back except WP-CLI. It also refuses any account the bootstrap did not
   create: real accounts have history behind them, and a settings screen is the
   wrong place to destroy it.

*Verified in the browser:* the page renders; a password reset shows the new
credential once and is gone on refresh; changing the slug to `club` and back
moves the app (`/club/` 200 + `/fitness/` 404, then the reverse) because the
change sets the flush flag and the redirect is the request that consumes it. The
delete guards were exercised directly rather than clicked — the button carries a
JavaScript `confirm()`, and driving a modal dialog through the browser extension
wedges the session. Gate: **phpcs 0 errors, phpunit 133 tests / 610 assertions.**

**One rough edge fixed on the way:** `BootstrapAccountsTest` runs the real
bootstrap, which overwrites the generated-accounts option, and then deletes the
accounts it made — leaving the *actual* install's settings screen empty. The
suite now saves and restores that option. A test that quietly edits the
development site is worse than a failing one.
