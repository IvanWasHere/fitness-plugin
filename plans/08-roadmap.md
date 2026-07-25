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

### W1.4 Workout domain (6 d)
`fc_workouts`/`fc_exercises`/`fc_user_workouts` read endpoints;
`WorkoutSessionService` complete state machine; all `/sessions/*` endpoints;
PR detection; `ActivityService`; `EntitlementService`.

*Done when:* PHPUnit drives a full session — start → pause → resume across a
simulated 20-minute gap → log 24 sets → complete — and the resulting rows,
duration, volume and PRs are all correct. **This test is the phase's real exit
criterion.**

### W1.5 User SPA shell + workout screens (8 d)
React 18 + TS in the **Vite `ui/` workspace** — the `ui/src/user/` entry plus the
shared `ui/src/shared/` (API client, design system, chart/modal components)
([D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas)). Router
(basename `/{base}`), chrome, theme provider, ported CSS **with the missing utility
classes and the contrast fix**. Screens: auth, dashboard, workouts, workout detail.
The player with timestamp-derived timers, wake lock, offline set queue, and the
celebration screen.

*Done when:* a seeded user can log in, open a workout, complete it on a phone with
the screen off between sets, and see correct numbers on the celebration screen.

### W1.6 Dashboard aggregate (2 d)
`GET /user/dashboard`, transient caching + invalidation, streak calculation,
`ProgressService` weekly series.

**Phase 1 exit:** the core loop — sign in, see today's workout, do it, see it
counted — works end to end on a real device.

---

## Phase 2 — Tracking & Admin (18–22 d)

### W2.1 Nutrition (4 d)
`fc_foods` + FULLTEXT search, meal logging with items, day rollup, water, goals,
food-plan read. Nutrition screen, Add Meal / Search Food modals, calorie ring,
macro cards.

### W2.2 Health & measurements (3 d)
Upsert-by-date health stats, BMI, `fc_users.weight_kg` sync, measurements. Health
screen with 8 cards + detail modal + Add Weight modal.

### W2.3 Progress & charts (4 d)
`ProgressService` range bucketing (week/month/quarter/year — **make the filter
real**), strength progression, consistency, records. Progress screen, all four
charts, empty states, computed axis domains.

### W2.4 Notifications & activity (2 d)
`NotificationService` respecting preference toggles, notification screen,
nav badges, activity feed.

### W2.5 Admin app (7 d)
Menu registration, mount, style scoping (option A from [05](05-admin-app.md)).
Server-backed `CrudTable`. Resources: users, trainers, workouts (+ nested exercise
editor with drag reorder), foods, meals, health entries. Admin dashboard.
**Settings screen** (general, email, features) — a prerequisite for everything
after this, despite not appearing in the prototype.

**Phase 2 exit:** every user-facing tracking feature works and an administrator
can run the site without touching the database.

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

### W3.1 Messaging (4 d)
Threads, messages, **per-trainer** quota service (Q3), unread counts, polling,
attachments. User-side message screen. (Drop the decorative typing indicator —
see [02](02-api-contract.md#messaging--messages).)

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

### W3.3 Support tickets (3 d)
Tickets + replies + internal notes, user ticket screens, admin ticket queue with
assignment, trainer ticket view. FAQ from settings rather than hardcoded JS.

### W3.4 Trainer app (31 d, see [06](06-trainer-app.md))
All `/trainer/*` endpoints with **both** ownership guards — shared read across
trainers (Q13), private write to your own assignments — then dashboard, client
list, client detail (7 tabs, every assignment attributed), workout builder, plan
builder, messages, private notes, profile, and the **client request queue** (Q4:
accept/decline/capacity). Assignment-conflict warnings. Food-plan builder and
exercise library deferrable to Phase 4 (−10 d). No earnings screen (Q2).

### W3.5 Trainer directory & requests, user side (3 d, see [04](04-user-app.md))
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

**Phase 3 exit:** money flows, trainers coach, users can get help.

---

## Phase 4 — Polish (12–16 d)

| # | Work | Days |
|---|------|------|
| 4.1 | Theme system: service, validation, contrast checks, admin UI, **light theme** | 4 |
| 4.2 | JWT + mobile API: token/refresh, delta sync (`?modified_since=`), pagination audit, response compression | 3 |
| 4.3 | Performance: query profiling against the volume seed, index tuning, object-cache integration, asset budgets | 2 |
| 4.4 | i18n: `.pot` generation, JS translation loading, RTL check | 1 |
| 4.5 | Accessibility audit against WCAG AA, keyboard paths, screen-reader pass | 2 |
| 4.6 | Docs: OpenAPI from route schemas, README, admin guide, trainer guide, theme dev guide | 2 |
| 4.7 | Export/import: CSV per admin resource, GDPR exporter/eraser hooks | 2 |

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
