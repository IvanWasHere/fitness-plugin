# 09 — Gap Register

Everything that does not line up between `plan.md`, the two prototypes, and a
shippable product. Verified against the files, with line references.

---

## 1. Spec ↔ prototype conflicts

| # | Conflict | Spec says | Prototype does | Resolution |
|---|----------|-----------|----------------|------------|
| 1.1 | **View framework** | React 18+ (§16.3) | Mithril 2.2 | **Resolved 2026-07-24 — React 18 + TypeScript** ([D1](00-architecture.md#d1--view-framework-react-18--typescript--confirmed-2026-07-24)). ~8–10 d of porting included in the W1.5/W2.5 estimates |
| 1.2 | Product name | "WorkoutNow", `wn_` prefix | "FitForge" | Slug `fitnessclub`, prefix `fc_`, brand configurable ([D2](00-architecture.md#d2--naming)) |
| 1.3 | PHP/WP floor | PHP 7.4, WP 5.6 (§16.3) | — | **Unreachable** — wpBones v2 needs PHP ≥8.1. Require PHP 8.1 / WP 6.2 |
| 1.4 | Storage | MySQL custom tables | Dexie/IndexedDB | Prototypes are offline mocks. Their schema *is* useful — it reveals fields the spec omits |
| 1.5 | Charts | "Line, bar, pie, radar, heatmap" (§9.3) | bar, line, radar, canvas ring — **no pie, no heatmap** | Build pie (macro split) and heatmap (consistency calendar); both are spec'd and neither exists |
| 1.6 | Theme dir | §4.1 in-plugin `wn_themes/`; §5.2 loader reads `WP_CONTENT_DIR` | no theming at all | Self-contradictory. Resolved in [D7](00-architecture.md#d7--theme-storage) |
| 1.7 | Auth | JWT everywhere (§14) | none | Cookie+nonce embedded, JWT external only ([D4](00-architecture.md#d4--authentication-cookie-first-jwt-for-external-only)) |
| 1.8 | Admin accounts | WP roles (§3.1) | separate `admins` table with roles Super Admin/Moderator/Viewer | Reject the table. WP users + `manage_options` |
| 1.9 | Plan model | trainer-owned, 4 price tiers (§2.4) | flat platform tiers Free/Pro/Team | Both, via `fc_plans.owner_type` |
| 1.10 | Session id | `POST /workouts/start`, `PUT /workouts/pause` — no id (§6.3) | local state only | Explicit `/sessions/{id}` resource — the spec's form breaks with two devices |
| 1.11 | Admin capability | "Create plans: Administrator ✗" (§3.2) | — | Wrong: §13 requires admin plan management. Admin gets it |
| 1.12 | Ticket assignee | FK → `wn_trainers` (§2.14) | — | Cannot assign to an administrator, which §13 requires. FK → WP user |
| 1.13 | Health scales | `stress_level`/`energy_level` ENUM (§2.12) | mood 1–5, energy 1–10 numeric | Numeric — you cannot chart or average an ENUM |
| 1.14 | Water | `water_intake_ml` per meal row (§2.11) | per-day count of glasses | Per-day, in `fc_nutrition_days` |
| 1.15 | Food items | `food_items JSON` (§2.11) | admin food DB with macros | Child table + `fc_foods`; JSON can't be searched or aggregated |

### Schema omissions that block specified features

| Missing | Blocks | Fix |
|---------|--------|-----|
| `exercises.default_rest_seconds` | The entire rest timer (§8.2) — **the player cannot be built** | added |
| `workout_logs` resume cursor | "Pause and continue later" (§8.1) | `current_exercise_index` + `current_set_index` |
| Per-set logging | "Volume = sets × reps × weight", PR tracking (§9.2) | `fc_set_logs` |
| Per-user workout progress | Every workout card's progress bar | `fc_user_workouts` |
| Notifications table | An entire screen | `fc_notifications` |
| Body measurements | Radar chart + profile form | `fc_body_measurements` |
| Activity feed | Dashboard "Recent Activity" | `fc_activity_log` |
| Foods table | Admin Food Database + food search | `fc_foods` |
| Food-plan assignment | "User follows food plan" (§12.1) | `fc_user_food_plans` |
| Message threads | Conversation list performance | `fc_message_threads` |
| Currency anywhere | Any non-USD deployment | `currency CHAR(3)` on money tables |
| Trainer rating | Admin trainers table column | `rating`, `rating_count` |
| User goal fields | Dashboard "Goal Progress", profile | `target_weight_kg`, `fitness_goal`, `activity_level` |

---

## 2. Prototype defects (do not port these)

The prototypes are **design references, not working code**. Several screens throw
on render. Verified by reading the source.

### User app — fatal

| Line | Defect | Effect |
|------|--------|--------|
| 597 | `].map(m => m('.flex…', [ m('span', m.l) ]))` — the callback parameter `m` shadows Mithril's `m` | **Dashboard nutrition block throws** `m is not a function` |
| 1046 | `].map((m, i) => m('.card…'))` — same shadowing | **Nutrition macro cards throw** |
| 1056 | `d.meals.map((m, i) => m('.meal-card…'))` — same | **Nutrition meals list throws** |
| 1331–1333 | `const m = state.modal; … return m('.modal-overlay', …)` — same | **Every modal throws.** All six modals are unreachable |
| 826 | `exercisesCompleted: state.workoutPlayer.exercises.length` — the property is `.workout.exercises` | Celebration shows `undefined` exercises |

Four of the eleven screens do not render. Any "port the prototype" estimate that
assumes working code is wrong.

### User app — structural

| Line(s) | Defect |
|---------|--------|
| all components | `oninit: async () => { this.x = … }` uses **arrow functions**, so `this` is module scope, not the vnode. `Dashboard`, `Workouts` and `WorkoutDetail` all write `this.workouts` on the same object — shared mutable state that works only because one screen renders at a time |
| 35 sites | `m('i.fa-solid fa-list')` — **space instead of a dot**, so the class list is just `fa-solid`. Those icons never render |
| 361/426/1499 | `state.sidebarOpen` is initialised, read, and set to `false` — but **never set to `true`**. There is no hamburger button, so on mobile (`transform: translateX(-100%)`) the sidebar is permanently unreachable |
| 461–463 | Bottom-nav "More" navigates straight to Messages instead of opening a menu — Subscription, Profile, Notifications and Support are **unreachable on mobile** |
| 955 | Weight chart hardcodes `min: 76, max: 82` — any user outside that range gets a blank chart |
| 993 | The week/month/3M/year filter sets state and re-renders **identical data**. It does nothing |
| 776–795 | Timers are `setInterval` counters, not timestamp-derived — they drift and stall in background tabs |
| 825 | `calories = elapsedSeconds * 6.5` for every user, every workout |
| 826 | Personal records are hardcoded strings |
| 874 | Native `confirm()` blocks the JS thread and looks broken on mobile |
| 1456 | **Blood pressure is fabricated.** `HealthDetailModal` takes one number and writes `{ systolic: val, diastolic: Math.round(val * 0.65) }` — a made-up reading stored beside a measured one and indistinguishable from it afterwards. This is health data. *Found during W2.2; the API now takes two inputs and refuses half a reading* |
| 1073–1085 | The Health screen builds its eight cards in the render function, and each indexes the tail of an array (`d.weight[d.weight.length - 1].date`). **The whole screen throws for a member who has logged nothing** — which is every member on day one. Same class of defect as the fatal shadowing bugs above, but arising from empty data rather than from syntax |
| 1128 | Message images render when `msg.text.includes('form')` — a placeholder heuristic |
| 1131 | Typing indicator shows permanently whenever the trainer is "online" |
| ~15 classes | `.text-center`, `.flex-column`, `.font-bold`, `.text-lg`, `.text-accent2`, `.text-info`, `.text-purple`, `.tag-accent`, `.mt-4/8/12`, `.mb-8/32`, `.gap-10/32` are used in markup but **never defined in the stylesheet** |
| — | `--text2: #7A8BA7` on `--card: #171D2A` ≈ **3.9:1 contrast — fails WCAG AA**, and it is used for nearly all secondary text |
| — | `.btn` is applied to `<div>`s with `onclick` throughout — not focusable, not keyboard-activatable, invisible to assistive tech |

### Admin app

| Line | Defect |
|------|--------|
| 305, 320, 321 | `m('.' + col.tag(val))` emits `class="tag-green"` **without the base `.tag` class** — every status/plan pill loses its padding, radius, and text-transform |
| stylesheet | Defines no `.flex`, `.flex-c`, `.gap-10`, `.text-xs`, `.text-accent`, `.mt-16`, `.mb-16` — yet the row renderers use all of them. Trainer online/offline labels and avatar rows are unstyled |
| 285 | `db[table].toCollection().sortBy()` loads **every row** then filters and paginates client-side |
| 337, 344 | Trainer dropdowns hardcode four names |
| 348 | Workout save deletes all exercises and re-inserts them — historical logs referencing those exercise ids would break |
| 250–260 | 10 unawaited counts, each calling `m.redraw()` — 10 renders per dashboard mount |
| 215 | `pageSize: 8` global and hardcoded |
| — | No authentication, no authorisation, no error handling on any promise |

### Both

- All assets from CDNs (Google Fonts, cdnjs Font Awesome, jsDelivr, unpkg) — GDPR
  exposure in the EU and a hard dependency on third-party uptime. Self-host.
- All images from `picsum.photos`.
- No loading, empty, or error states anywhere.
- The user app's `body { background: var(--bg) }` will repaint the entire host
  WordPress site when embedded via shortcode.

---

## 3. Missing from everything

Present in neither the spec's screen list nor the prototypes, but required to ship:

### 3.1 Authentication UI
Login, registration, forgot password, reset password, email verification,
logged-out landing. The spec lists the *endpoints* (§6.1) but no screens; the
prototypes boot into a seeded session. **~3 d.**

### 3.2 Onboarding
A new user has no height, weight, goal, or plan. Every chart is empty and the
dashboard's "Goal Progress" divides by zero. Needs a first-run wizard: profile →
goal → plan selection → trainer assignment. **~3 d.**

### 3.3 Plan selection & checkout
The subscription screen shows an *existing* Pro plan and three buttons that fire
"coming soon" toasts. There is no plan comparison, no checkout, no payment form.
**~4 d** on top of the gateway work.

### 3.4 Empty and error states
Every list, every chart. A new user's app is currently blank rectangles.

### 3.5 Admin: Plans, Subscriptions, Tickets, Themes, Settings
Five areas the spec requires (§13) with no prototype. Roughly half the admin app's
remaining work.

### 3.6 Trainer app
Entirely. See [06](06-trainer-app.md). **~28 d.**

### 3.7 Operational
Uninstall routine, GDPR export/erase hooks, DB-version upgrade path, admin
notices for misconfiguration (no gateway keys, no cron), Site Health checks,
`readme.txt`, changelog.

---

## 4. Open questions

Ordered by how much they change the build. The first three should be answered
before Phase 1 ends; the rest before Phase 3.

### Answered

| # | Question | Answer (2026-07-24) | Consequence |
|---|----------|---------------------|-------------|
| Q1 | React or Mithril? | **React 18 + TypeScript** | [D1](00-architecture.md#d1--view-framework-react-18--typescript--confirmed-2026-07-24). ~8–10 d porting in W1.5/W2.5 |
| Q2 | Are trainers paid through the platform? | **No — traced, not paid** | No payouts, commissions, ledgers, KYC, or Stripe Connect. Trainer contribution is *attributed* for reporting. See [§4.1](#41-q2--trainers-are-traced-not-paid) |
| Q3 | Can a user have multiple trainers? | **Yes** | Many-to-many confirmed. Forces an entitlement-merge rule and per-thread message quotas. See [§4.2](#42-q3--multiple-trainers-per-user) |
| Q10 | May admins edit users' health data? | **Yes** | Full CRUD on meals + health entries in admin. Audit-logged with before/after. See [§4.3](#43-q10--admins-may-edit-health-data) |
| Q4 | Who initiates trainer assignment? | **User request** | Users browse a trainer directory and request; the trainer accepts or declines. Adds 3 screens that exist nowhere. See [§4.4](#44-q4--users-request-trainers) |
| Q13 | May trainer A see what trainer B assigned? | **Yes** | Full shared read of the client record, including other trainers' assignments. Writes stay private. See [§4.5](#45-q13--trainers-see-each-others-assignments) |
| Q14 | Does requesting a trainer require an active plan? | **Yes — and the plan caps how many trainers** | New `max_trainers` entitlement. Trainers may also reject. See [§4.6](#46-q14--an-active-plan-gates-trainer-access) |
| Q15 | Are trainer notes still private? | **Yes** | `fc_client_notes` stays scoped per trainer. Closed — no change to the plan |
| Q17a | How is the front-end built and shaped? | **Two sections: wpBones backend + three Vite React SPAs** (user/trainer/admin) | Vite, not wpBones' webpack; SPAs decoupled from the framework asset pipeline. [D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas) |
| Q17b | Where does the UI live? | **One configurable URL** (`example.com/{base}`, default `/fitness`), **role selects the SPA** at that URL | wpBones has no front-end routing → native WP rewrite in `RewriteServiceProvider`. Admin leaves wp-admin. [D9](00-architecture.md#d9--front-end-routing-configurable-app-url) |

### Still open

| # | Question | Blocks | Why it matters |
|---|----------|--------|----------------|
| Q17c | **New —** if one account holds several roles (e.g. an admin who is also a trainer), which SPA loads, and do we offer a switcher? | W1.3 | Planned default: precedence **admin > trainer > user**; no switcher at launch. Rare in practice. Confirm |
| Q5 | Which payment gateway first, and which regions/currencies? | W3.2 | Stripe assumed. Affects tax handling and SCA. Simplified by Q2 — standard Stripe, not Connect |
| Q6 | Free tier: is there one, and what does it include? | Entitlements | The admin prototype has a `Free` plan; the spec has no free tier. **Sharpened by Q14**: modelling free as an active subscription to a `Free` plan with `max_trainers: 0` makes the "active plan required" rule uniform with no special case. Recommended — confirm |
| Q7 | Video hosting: uploaded, or YouTube/Vimeo links? | W3.4 | Uploads mean storage, transcoding, and bandwidth — a different cost model |
| Q8 | Multisite? | Everything | Changes table provisioning throughout |
| Q9 | Launch locales? | Phase 4 | RTL support is cheap now, expensive later |
| Q11 | Data retention: how long are sessions, meals, health rows kept? | Phase 4 | Health data under GDPR wants a stated retention period. **Sharpened by Q10**: admin-editable health data makes the audit trail itself retained personal data |
| Q12 | Is the mobile app real, or is §17 aspirational? | Phase 4 | Determines whether JWT and delta sync ship at all |
| Q16 | **New —** on downgrade to a plan with fewer trainer slots, what happens to existing trainers? | W3.2 | Falls out of Q14. Planned: **grandfather** — block new requests, never auto-sever a coaching relationship. Confirm |

### 4.1 Q2 — trainers are traced, not paid

Trainer contribution is **attributed and reported on, never disbursed**. The
platform collects all revenue; trainers are staff/contractors paid outside the
system.

**Removed from scope entirely** (was the single largest open risk in the plan):

- payouts, commission splits, revenue share, ledgers, balances
- Stripe Connect, connected-account onboarding, KYC/identity verification
- 1099/tax-form generation, payout reconciliation, marketplace tax liability
- a trainer-facing earnings or payout screen

**Retained — attribution:**

| Field / view | Purpose |
|---|---|
| `fc_plans.trainer_id` | Plan *authorship*, not entitlement to proceeds |
| `fc_subscriptions.trainer_id` | Which trainer's plan the user bought — the attribution key |
| `fc_workouts.trainer_id` | Content authorship |
| `fc_user_workouts.assigned_by_wp_user_id` | Who assigned it |
| `GET /admin/reports/trainers` | Per-trainer: active clients, sessions completed, adherence, **attributed** subscription revenue |
| Trainer dashboard | Own client/activity/adherence stats — **no money figures** |

`fc_trainers.hourly_rate` becomes **display-only metadata** on the public trainer
profile (what they charge for offline sessions). It is never used in any
calculation. Flag it as such in the column comment so nobody wires it into
billing later.

Consequences elsewhere: standard Stripe, not Connect ([D6](00-architecture.md#d6--payments-adapter-seam-stripe-first));
no marketplace compliance review; W3.4's estimate holds at ~28 d with the risk
retired rather than merely timeboxed.

### 4.2 Q3 — multiple trainers per user

Confirmed many-to-many. `fc_user_trainers` was already modelled for it and the
user prototype already ships two trainer conversations. Three things this forces:

**1. A primary trainer.** Add `fc_user_trainers.is_primary TINYINT(1) DEFAULT 0`
with at most one per user (enforced in `AssignmentService`). The dashboard, the
onboarding flow, and notification routing all need a single "your trainer" answer.

**2. An entitlement merge rule.** A user may hold several active subscriptions,
each from a different trainer's plan. `EntitlementService` resolves the *set*:

| Flag kind | Rule |
|---|---|
| Booleans (`can_log_nutrition`, `has_video_workouts`, …) | **Union** — most permissive wins. Never revoke a feature the user paid for on another plan |
| Numeric caps (`max_active_workouts`, …) | **Max** across active plans |
| `max_messages_per_week` | **Per-trainer, not global** — scoped to the subscription linking that user to that trainer |
| No active subscription | Free-tier defaults (Q6), never deny-all |

The per-thread quota is the important one: a global cap would let one trainer's
conversation consume the allowance for another's.

**3. Trainer data visibility.** Planned default, pending Q13:

- Progress, sessions, health, nutrition: **readable by every assigned trainer**
  (they are all coaching this person)
- Plans, workout assignments, food plans: **editable only by the assigning trainer**
- `fc_client_notes`: **private per trainer** — scope the table on
  `(trainer_id, user_id)`, never expose across trainers
- Every cross-trainer read is still gated by `Guard::trainerOwnsClient()`, which
  already returns true for any `active` row — so it works unchanged

UI: the user's Profile gains a "My Trainers" section; the trainer's client-detail
screen shows a "also coached by" indicator so a trainer knows they are not alone.

### 4.3 Q10 — admins may edit health data

Confirmed: administrators get **full CRUD** on `fc_nutrition_logs` and
`fc_health_stats`, not the view+delete I initially recommended. My concern is
recorded above and was overruled with it in view; building it as asked.

Implementation notes that keep it safe without limiting it:

- Capability `fc_edit_user_health`, granted to `administrator` **by default**
  (not the granted-to-nobody gate I'd sketched)
- Every create/update/delete writes `fc_activity_log` with `actor_wp_user_id`,
  the changed field set, and **before/after values** in `meta`
- The admin UI shows the last editor and timestamp on any row edited by staff, and
  warns inline that the change will alter the user's charts
- The user's own history view marks admin-edited rows as `source='admin'` rather
  than silently presenting them as self-reported
- Audit rows are exempt from the `ActivityPrune` job's 12-month cut — deletion
  history for health data should outlive the feed (feeds into Q11)

### 4.4 Q4 — users request trainers

**Users initiate.** A user browses a trainer directory, sends a request, and the
trainer accepts or declines. Admins retain the ability to assign directly as an
override (§3.2 keeps admin ✓), but that is the exception path, not the norm.

State machine on `fc_user_trainers.status`:

```
        user requests              trainer accepts
  (none) ──────────────► pending ──────────────────► active
                            │                          │
          trainer declines  │                          │ either party ends
                            ▼                          ▼
                        declined                    inactive
                            ▲                          
          user withdraws    │                          
                        withdrawn                      
```

The enum grows from `('pending','active','inactive')` to
`('pending','active','inactive','declined','withdrawn')`. `declined` and
`withdrawn` are distinct from `inactive` (a relationship that existed and ended) —
collapsing them loses the ability to stop re-prompting a user with a trainer who
already said no.

**Three screens that exist in neither prototype nor the spec:**

| Screen | App | Content |
|---|---|---|
| Trainer directory | user | Cards: avatar, name, specialization, bio, rating, client count, accepting-status. Filter by specialization; search. Only trainers with `accepting_clients=1` and under `max_clients` |
| Trainer profile + request | user | Full bio, a request form with an optional message, and the user's current request state |
| Request queue | trainer | Incoming pending requests with the user's goal/level and message; accept / decline (with optional reason) |

**Supporting work:**

- `fc_trainers.max_clients INT NULL` — a capacity cap, so a popular trainer stops
  appearing in the directory rather than drowning. `accepting_clients` already
  exists and now has a purpose.
- `fc_user_trainers.request_message VARCHAR(500)`, `responded_at DATETIME`,
  `decline_reason VARCHAR(255)`.
- Rate limit requests: **3 pending at a time, 10 per week per user**. Without this
  a user can spam every trainer on the platform.
- Notifications both ways: trainer on request, user on accept/decline.
- The directory is the one place a logged-in user reads other users' profile data,
  so it returns a **public trainer projection only** — never email, phone, or
  client list.

This is why Q4 got bigger under Q3: with multiple trainers allowed, "add another
trainer" is an ongoing flow rather than a one-time onboarding step, so the
directory needs to be reachable from the main navigation, not just the wizard.

**Cost: ~4 d** (3 d user-side directory/profile/request, 1 d trainer-side queue),
plus ~1 d backend. Not previously budgeted — see the estimate correction in
[08-roadmap.md](08-roadmap.md#estimate-correction-2026-07-24).

### 4.5 Q13 — trainers see each other's assignments

**Yes — full shared read of the client record**, including workouts, plans and
food plans assigned by any other trainer. This supersedes the narrower default I
had planned (progress-only sharing).

The read/write split therefore simplifies to one clean rule:

| Operation | Guard | Scope |
|---|---|---|
| **Read** anything about an assigned client | `trainerOwnsClient` | Any active assignment. Progress, sessions, health, nutrition, **and every trainer's assignments and plans** |
| **Write** plans, assignments, food plans | `trainerAssignedResource` | The assigning trainer only |
| **Read or write** notes | owner only | Private per trainer — confirmed (Q15) |

This is the safer answer clinically, not just the simpler one. Two trainers
independently programming heavy compound lifts for the same client in the same
week is a genuine injury risk, and it is invisible if neither can see the other's
work.

Two things that follow:

- **Every assignment is attributed in the UI** — "Assigned by Mike Torres,
  12 Jul". Shared visibility without attribution is worse than no visibility,
  because a trainer cannot tell their own programming from someone else's.
- **Conflict warnings.** When a trainer assigns a workout that overlaps another
  trainer's assignment for the same week (same primary muscle group, or same
  scheduled day), warn at assign time. Cheap to compute from
  `fc_user_workouts.scheduled_for` + `fc_workouts.muscle_groups`; it is the
  feature that makes shared visibility actually useful. **~1 d.**

### 4.6 Q14 — an active plan gates trainer access

Three rules, one of them new:

1. **Trainers may reject requests** — already modelled by `status='declined'`
   ([§4.4](#44-q4--users-request-trainers)). No change.
2. **A user must hold an active plan to request a trainer at all.**
3. **The plan defines how many trainers that user may have.** This is a new
   entitlement, not a global constant.

Rule 3 is the consequential one: it makes multi-trainer (Q3) a **sold feature**
rather than a free-for-all, and it means the trainer cap lives in
`fc_plans.features` alongside every other entitlement:

```json
{ "can_log_workouts": true, "can_log_nutrition": true,
  "max_messages_per_week": 10,
  "max_trainers": 2 }
```

**Merge across concurrent subscriptions** (Q3) follows the existing numeric rule —
**max** across active plans. A user on a 1-trainer plan who buys a 2-trainer plan
gets 2, not 3. Slots are a ceiling on the relationship count, not a per-plan
allowance that accumulates.

**Enforcement** in `AssignmentService::request()`:

```
require active subscription           → 403 fc_subscription_required
count(active) + count(pending) < merged max_trainers
                                      → 403 fc_trainer_limit_reached
                                        data: {limit, used, upgrade_url}
trainer accepting_clients && under max_clients
                                      → 409 fc_trainer_at_capacity
within 3 pending / 10 per week        → 429 fc_request_limit
```

Pending requests count against the limit. Otherwise a user on a 1-trainer plan
queues five requests and takes whichever lands first, which is both unfair to the
four trainers who spent time reviewing and a way around the cap.

**Downgrade is grandfathered** (Q16 — confirm). A user on 2 trainers who drops to
a 1-trainer plan keeps both; the system blocks *new* requests and surfaces
"2 of 1 slots used" until they end one. Auto-severing a coaching relationship
because a card expired is the kind of thing that generates support tickets and
churn — and the trainer, who did nothing wrong, loses a client silently.

**Directory for users who cannot request** — show it, disable the button, explain
why. Hiding the directory from a free user removes the single clearest reason to
upgrade. The response includes `can_request: false` and `reason:
"subscription_required" | "limit_reached"` so the UI renders the right CTA rather
than guessing.

This also resolves the awkward part of Q6: model the free tier as an active
subscription to a `Free` plan with `max_trainers: 0`, and "must have an active
plan" needs no special case anywhere in the code.

---

## 5. Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Trainer app under-specified → scope creep | Low *(was High)* | High | Q2/Q3/Q4/Q13 all answered; **marketplace/payout scope retired**. Remaining unknowns (Q14/Q15) are small. Estimate re-based bottom-up — see [08](08-roadmap.md#estimate-correction-2026-07-24) |
| Trainer-request flow was unbudgeted | — | Medium | **Realised, not a risk** — Q4 adds ~5 d of previously uncosted work (directory, profile, request, queue). Folded into Phase 3 |
| Two trainers program conflicting work for one client | Medium | **High (injury)** | Q13 shared visibility + assignment attribution + conflict warnings at assign time ([§4.5](#45-q13--trainers-see-each-others-assignments)) |
| Trainer-request spam | Low *(was Medium)* | Low | Q14: active plan required + `max_trainers` cap counts pending requests; plus 3 pending / 10 per week and `accepting_clients`/`max_clients` |
| Downgrade silently severs a coaching relationship | Medium | High | Q14/Q16: grandfather existing trainers, block only *new* requests, surface "2 of 1 slots used" |
| ~~Marketplace compliance (KYC, payouts, tax)~~ | — | — | **Retired** — Q2: trainers are traced, not paid |
| Multi-trainer entitlement bugs (feature flickers as subscriptions lapse) | Medium | Medium | Union/max merge rule fixed in [§4.2](#42-q3--multiple-trainers-per-user); unit-test the matrix of overlapping plans |
| Admin health edits corrupt user history | Medium | Medium | Q10 accepted; audit before/after, surface last-editor in both UIs, mark staff-edited rows |
| Payment integration overruns | High | Medium | Ship `ManualGateway` first so the product works without Stripe |
| Porting estimate assumes working prototypes | High | Medium | §2 above — four screens don't render. Estimates here account for it |
| No object cache → rate limiter writes to `wp_options` | Medium | Medium | Detect and degrade ([03](03-backend.md#rate-limiting)) |
| `dbDelta` mangles FK clauses on some hosts | Medium | Medium | FKs in a guarded separate `ALTER`; enforce integrity in services |
| WP-Cron unreliable → subscriptions don't expire | Medium | High | Document system cron; add a Site Health warning |
| Hardcoded colours defeat theming | High | Low | CI check for hex literals outside the token file |
| Health data breach | Low | Severe | Ownership guards on every endpoint, audit logging, no PII in logs, encrypt exports |
| Prototype CSS leaks into the host theme | High | Low | Scope to `#fc-app`; never ship the `body` rule |
