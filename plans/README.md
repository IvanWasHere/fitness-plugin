# FitnessClub — Implementation Plan

This folder is the implementation plan for the plugin described in `../plan.md`
("WorkoutNow" spec), reconciled against the two working UI prototypes in
`../user-app/index.html` and `../admin-app/index.html`.

It is *my* plan, not a restatement of the spec. Where the spec and the prototypes
disagree, the disagreement is recorded and resolved explicitly in
[`09-gap-register.md`](09-gap-register.md).

## Read in this order

| # | Document | What it settles |
|---|----------|-----------------|
| 00 | [architecture.md](00-architecture.md) | Stack, wpBones conventions, directory layout, the 11 binding decisions |
| 01 | [database.md](01-database.md) | Reconciled schema — 29 tables, migrations, indexes |
| 02 | [api-contract.md](02-api-contract.md) | Every REST endpoint, keyed to the screen that needs it |
| 03 | [backend.md](03-backend.md) | Roles/caps, auth (cookie + JWT), services, rate limiting, security |
| 04 | [user-app.md](04-user-app.md) | Porting the user prototype: 11 screens, the workout player, 6 charts |
| 05 | [admin-app.md](05-admin-app.md) | Porting the admin prototype: generic CRUD table + 8 resources + what's missing |
| 06 | [trainer-app.md](06-trainer-app.md) | The third app, which has no prototype — designed from scratch |
| 07 | [theming.md](07-theming.md) | Front-end themes: WordPress themes shipping React apps, per-role, with the plugin's apps as fallback (D11). The palette system it used to describe is deferred |
| 08 | [roadmap.md](08-roadmap.md) | 4 phases, work packages, sequencing, estimates |
| 09 | [gap-register.md](09-gap-register.md) | Spec↔prototype conflicts, prototype defects, open questions |

## Executive summary

**What exists today:** a `plan.md` specification and two single-file HTML
prototypes (Mithril 2.2 + Dexie/IndexedDB + Chart.js, ~160 KB total). No PHP, no
build tooling, no wpBones install. The prototypes are *design references* — they
run against seeded IndexedDB, not a server, and several screens crash (see
[gap register §2](09-gap-register.md#2-prototype-defects-do-not-port-these)).

**What we build — two sections** ([00 §two-sections](00-architecture.md#the-plugin-is-two-sections)):
a **wpBones backend** exposing a versioned REST API over 29 custom tables, and a
**Vite front-end of three React SPAs** — user, trainer, admin. All three live at
one configurable URL (`example.com/{base}`, default `/fitness`); the backend serves
whichever SPA matches the logged-in role ([D9](00-architecture.md#d9--front-end-routing-configurable-app-url)/[D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas)).
No custom post types, per spec.

**The critical path** is not the UI. It is: schema → roles/capabilities → auth →
workout-session state machine. Everything else is CRUD hung off that spine. The
workout player is the one genuinely stateful piece of the product (start / pause /
resume across devices / per-set logging / completion summary with PR detection)
and it is the thing the spec under-describes most — `wn_exercises` has no rest-time
column and `wn_workout_logs` has no resume pointer, so as specified the player
cannot be built. Fixed in [01-database.md](01-database.md).

**Biggest scope risk:** the trainer app. It is one third of the product, it has
no prototype, and `plan.md` §7.2 gives only a component tree. Budgeted in
[06-trainer-app.md](06-trainer-app.md) as its own phase.

## The 11 binding decisions

Full rationale in [00-architecture.md](00-architecture.md#decisions).

| # | Decision | Chosen | Because |
|---|----------|--------|---------|
| D1 | View framework | **React 18 + TypeScript**, built with **Vite** — ✅ | Spec mandates React; Vite for HMR + self-contained bundles (D10). Prototypes are Mithril — mechanical view porting |
| D2 | Naming / prefix | `fitnessclub` slug, `FitnessClub\` namespace, `{$wpdb->prefix}fc_` tables | Folder is `fitnessclub`; spec's `wn_`/"WorkoutNow"/prototypes' "FitForge" all conflict |
| D3 | Routing (API) | wpBones `Route::` in `api/fitnessclub/v1/*.php` → `/wp-json/fitnessclub/v1/*` | Native WP REST: nonces, `permission_callback`, arg schemas for free |
| D4 | Auth | Cookie + `X-WP-Nonce` for the SPAs; JWT only for mobile/external | Spec's JWT-everywhere adds a credential to browsers that already have a session |
| D5 | Data access | `DB::table()` builder for simple; **raw `$wpdb` for joins** (light builder has no `join`) | Eloquent avoided; the builder's scope is confirmed in the docs |
| D6 | Payments | Adapter interface, Stripe first, manual-entry fallback | Spec says "PayPal, Stripe, or similar" — build the seam, not both gateways |
| D7 | Theme storage | Bundled defaults in plugin + user themes in `uploads/fitnessclub-themes/` — ⚠ **superseded by D11** | Spec contradicts itself (`wn_themes/` in-plugin vs `WP_CONTENT_DIR` in the loader); `wp-content/` is often unwritable |
| D8 | Money | `DECIMAL(10,2)` + minor-unit ints at the gateway boundary, currency column | Spec has no currency field anywhere |
| D9 | **Front-end URL** | One **configurable** slug (`example.com/{base}`, default `/fitness`), role-selected SPA, via a `RewriteServiceProvider` | wpBones has no front-end routing — native WP rewrite. ✅ confirmed |
| D10 | **Front-end build** | **Three Vite React SPAs** (user/trainer/admin), one shared workspace, decoupled from wpBones' webpack | Hard bundle isolation per role; the one part of wpBones we replace. ✅ confirmed |
| D11 | **What a theme is** | A **WordPress theme that ships React apps** — `wp-content/themes/{x}` with `Fitness Plugin Extension Enabled: true`, picked from a wp-admin dropdown, served per role with the plugin's apps as fallback. Palette system deferred | A theme that can only recolour a fixed app is not what the product is for. `wp-content/themes/` because it survives plugin updates. Cost: the boot payload + REST API become a published contract. ✅ confirmed 2026-07-29 |

## Assumptions in force

Stated so they can be corrected rather than discovered later:

1. Single-site WordPress (not multisite). Multisite adds per-site table
   provisioning throughout.
2. English-only at launch; strings wrapped in `__()` from day one so i18n is
   a translation job, not a refactor.
3. "FitForge" in the prototypes is placeholder branding, configurable in settings.
4. The `Free / Pro / Team` tiers in the admin prototype are *platform* tiers, and
   the trainer-owned priced plans in `plan.md` §2.4 are a *separate* concept.
   Both are modelled — see `fc_plans.owner_type` in [01-database.md](01-database.md).
5. Real-time messaging means polling (15 s) at launch, not WebSockets.
6. No mobile app is being built now; the API is designed to serve one later.

## Answered 2026-07-24

Three questions from the gap register are now settled and propagated through every
affected document:

- **Q2 — trainers are traced, not paid.** No payouts, commissions, ledgers, KYC,
  or Stripe Connect. Trainer contribution is *attributed* for reporting
  (`/admin/reports/trainers`); `hourly_rate` is display-only. This retires the
  plan's largest scope risk — a marketplace payout subsystem would have been
  15–25 d plus compliance review.
- **Q3 — a user may have multiple trainers.** Forces `is_primary`, an entitlement
  merge across concurrent subscriptions (union booleans, max caps), **per-thread**
  message quotas, and a read/write guard split so trainer A cannot edit trainer
  B's assignments. Adds `fc_client_notes`.
- **Q10 — admins may edit users' health data.** Full CRUD as the prototype has it,
  backed by `fc_edit_user_health`, before/after audit logging, and provenance
  columns so staff edits are visible rather than silent.
- **Q4 — users request trainers.** Users browse a directory and request; the
  trainer accepts or declines; admins may still assign directly as an override.
  Adds three screens that exist in neither the spec nor the prototypes, a
  five-state request lifecycle, and capacity caps. ~5 d of new work.
- **Q13 — trainers see each other's assignments.** Full shared read of the client
  record; writes stay private to the assigning trainer. Every assignment is
  attributed in the UI, and overlapping assignments raise a conflict warning —
  two trainers independently programming heavy compounds in the same week is a
  genuine injury risk that shared visibility exists to catch.

- **Q14 — an active plan gates trainer access, and the plan caps how many.**
  Adds a `max_trainers` entitlement to `fc_plans.features`, merged as the max
  across active plans and counting pending requests. This makes multi-trainer a
  sold feature rather than an unbounded one. Trainers may also reject requests.
- **Q15 — trainer notes stay private.** No change to the plan.

Detail and reasoning: [09-gap-register.md §4](09-gap-register.md#4-open-questions).

Still open and non-blocking: **Q16** — on downgrade to a plan with fewer trainer
slots, existing relationships are **grandfathered** (new requests blocked, nothing
severed). That is the planned behaviour; confirm if you want it stricter.

## Effort

| Phase | Scope | Estimate |
|-------|-------|----------|
| 0 | Scaffold, tooling, CI | 3–4 d |
| 1 | Schema, auth, roles, workout core, user app shell | 20–25 d |
| 2 | Nutrition, health, progress, notifications, admin app | 18–22 d |
| 3 | Messaging, payments, tickets, trainer app, trainer directory | 38–48 d |
| 4 | Theming, JWT/mobile, caching, tests, docs | 12–16 d |
| | **Total** | **~91–115 dev-days** |

One full-stack developer. Estimates exclude design, QA cycles, and payment-gateway
compliance review. See [08-roadmap.md](08-roadmap.md) for the breakdown.

> **Revised 2026-07-24 from ~75–95 d.** Two causes, one of them mine. (a) Phase 3
> carried 14 d for the trainer app while [06](06-trainer-app.md)'s bottom-up
> breakdown said ~29 d for the same scope — an inconsistency in my plan, now
> re-based on the detailed figure. (b) Q4 added ~5 d of genuinely new work
> (trainer directory, profile, request flow, request queue). Deferring the
> food-plan builder and exercise library recovers ~10 d if the timeline is tight.
