# 08 — Roadmap

Four phases, mapped to `plan.md` §18 but resequenced: the spec puts the theme
system in Phase 2 and support tickets in Phase 3, while leaving auth screens and
settings unscheduled entirely. Corrected below.

Estimates are one full-stack developer, in dev-days, excluding design, QA cycles,
and payment compliance review.

---

## Phase 0 — Foundation (3–4 d)

> **Phase 0 is done** (2026-07-24) for items 0.1–0.3; see the build notes at the
> end of this document. 0.4–0.7 remain.

| # | Work | Done when |
|---|------|-----------|
| 0.1 | ~~`composer create-project wpbones/wpkirk`~~ — **that package is not on Packagist** (the boilerplate is a GitHub template). Skeleton authored directly + `composer require wpbones/wpbones:^2.0` | ✅ Plugin boots on WP 7.0.2 / PHP 8.4.23 |
| 0.2 | `.gitignore` (vendor, node_modules — but **commit `public/`**, wpBones expects built assets present) | ✅ written; `git init` still pending |
| 0.3 | `config/{plugin,menus,api,options,fitnessclub}.php` | ✅ `GET /wp-json/fitnessclub/v1/health` returns 200 |
| 0.4 | Webpack/TS/Jest config for three entry points | `npm run dev` watches all three apps |
| 0.5 | PHPCS (WP standard + a no-raw-SQL sniff), ESLint, Prettier, pre-commit hook | CI red on violation |
| 0.6 | GitHub Actions: PHP lint/test matrix (8.1–8.4), JS lint/test, migration-upgrade job | Green on an empty PR |
| 0.7 | Local WP env (`wp-env` or Docker) + a seeded DB | `npm run env:start` gives a working site |

**Exit:** an empty plugin that activates, exposes a versioned REST namespace,
builds three empty SPAs, and has CI.

---

## Phase 1 — Core (20–25 d)

The spine. Nothing after this phase is architecturally interesting.

### W1.1 Database (4 d) — ✅ **complete**
All 29 migrations, named `YYYYMMDD_HHMMSS_*`, idempotent. Includes
`fc_user_trainers.is_primary` and the Q10 provenance columns (`source='admin'`,
`last_edited_by_wp_user_id`) on health and nutrition. The `fitnessclub_db_version`
upgrade dispatcher — **build it now**, before the first install exists to upgrade
(it runs from `UpgradeProvider` on `init`, *not* `activation.php`; see the build
notes). `DemoSeeder` reproducing the prototype seed data exactly. `--volume`
seeder (1 000 users × 180 days).

**Done 2026-07-24:** 29 migrations + `FitnessClub\Database\Migration` base
(guarded FKs, InnoDB enforcement, idempotency helpers) +
`Database\Upgrade\Manager` + `PlatformPlansSeeder` + `DemoSeeder` +
`VolumeSeeder` + `bin/seed.php`. Plugin activated on the dev site: 29 tables,
35 FKs, 94 indexes, both roles, demo dataset loaded. Index strategy validated
against 62 k synthetic rows — every hot query hits an index, none exceed 0.7 ms.

*Done when:* activate → 29 tables; deactivate/reactivate → no errors, no data loss;
seeder produces the prototype's exact dataset — including Alex Morgan assigned to
**both** Sarah Chen and Mike Torres, so multi-trainer paths are exercised from
day one rather than discovered in Phase 3.

### W1.2 Roles, capabilities, Guard (2 d)
`RoleProvider`, capability constants (including `fc_edit_user_health` for admins —
Q10), `Guard` with `trainerOwnsClient` / **`trainerAssignedResource`** /
`ownsSession` / `ownsThread` / `ownsResource`. Uninstall cleanup.

*Done when:* a test asserts every ownership rule returns false for a foreign id,
**and** that a second trainer assigned to the same client can read their progress
but cannot edit the first trainer's assignments (Q3).

### W1.3 Auth + boot (3 d)
Cookie/nonce plumbing, **nonce-expiry refresh-and-retry in the API client**,
`GET /auth/me`, login/register/forgot/reset endpoints, the shortcode provider,
asset enqueueing, boot payload. Rate limiter (with the object-cache detection from
[03](03-backend.md#rate-limiting)).

*Done when:* an unauthenticated visitor sees a login panel; a logged-in `fc_user`
sees an empty app shell with their name and theme applied.

### W1.4 Workout domain (6 d)
`fc_workouts`/`fc_exercises`/`fc_user_workouts` read endpoints;
`WorkoutSessionService` complete state machine; all `/sessions/*` endpoints;
PR detection; `ActivityService`; `EntitlementService`.

*Done when:* PHPUnit drives a full session — start → pause → resume across a
simulated 20-minute gap → log 24 sets → complete — and the resulting rows,
duration, volume and PRs are all correct. **This test is the phase's real exit
criterion.**

### W1.5 User app shell + workout screens (8 d)
React 18 + TS ([D1](00-architecture.md#d1--view-framework-react-18--typescript--confirmed-2026-07-24),
settled — no decision pending). `php bones make:app user`. Router, chrome, theme provider,
API client, design system components, ported CSS **with the missing utility
classes and the contrast fix**. Screens: auth, dashboard, workouts, workout
detail. The player with timestamp-derived timers, wake lock, offline set queue,
and the celebration screen.

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

## Build notes — Phase 0 / W1.1, 2026-07-24

What actually happened when the plan met the toolchain.

### `composer create-project wpbones/wpkirk` does not work

`wpbones/wpkirk` is not published on Packagist — only `wpbones/wpbones` (the
framework) is. The boilerplate is a **GitHub template repository**
(`wpbones/WPKirk-Boilerplate`), not a Composer package. The skeleton was
therefore authored directly against `composer require wpbones/wpbones:^2.0`,
which also avoids importing the boilerplate's demo content (Prism, greeting
scripts, Italian translations, sample views) that would all have been deleted.

### `php bones rename` is destructive here — deliberately not used

WP Bones ships the framework hardcoded in the `WPKirk\WPBones\` namespace and
expects `php bones rename` to rewrite it. That command performs a global
`str_replace` across **every file in the project**, excluding only
`node_modules` and the bones binary itself — Markdown and HTML included. It
would have rewritten `plans/00-architecture.md`, which legitimately cites
"wpbones/WPKirk-Boilerplate", into "wpbones/FitnessClub-Boilerplate".

Replaced with `bin/patch-vendor-namespace.php`: same job, scoped strictly to
`vendor/wpbones/wpbones/`, idempotent, wired into `post-autoload-dump` so it
survives every `composer install|update`. It rewrites the namespace, the
`FitnessClub()` accessor, the package's PSR-4 declaration and the generated
autoload maps. Verified: 58/58 framework files rewritten, all classes resolve.

**Consequence for `php bones make:*`:** those commands read the plugin name and
namespace from the `namespace` file (`FitnessClub,FitnessClub`), which is
present, so scaffolding commands work normally. Do **not** run `php bones rename`.

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
