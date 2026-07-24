# 06 — Trainer App

**No prototype exists.** `plan.md` §7.2 gives a component tree and nothing else —
no screens, no flows, no data shapes. This is one third of the product being
specified in 40 lines, and it is the largest single estimation risk in the plan.

Mounted by shortcode `[fitnessclub_trainer]`, capability `fc_access_trainer_app`.

## Why front-end and not wp-admin

Trainers are not WordPress operators. Putting them in wp-admin means they see the
Posts menu, the plugin updates nag, and a UI built for site administration. A
front-end app on a `/trainer` page with `show_admin_bar_front` disabled for the
role gives a product surface instead of a CMS surface. Same shell, same design
system, same API client as the user app — reuse is high.

## Screens

Derived from what the spec's capability matrix promises and what the data model
can actually serve.

### 1. Dashboard — `GET /trainer/dashboard`

| Block | Content |
|-------|---------|
| Stat row | active clients, sessions completed this week (across clients), unread messages, pending plan assignments, average adherence — **no revenue or earnings figures** (Q2) |
| Needs attention | clients with no session in 7 days, unanswered messages > 24 h, subscriptions expiring in 7 days, failed payments |
| Recent client activity | feed from `fc_activity_log` scoped to assigned clients |
| Today's schedule | clients with a workout scheduled today |

"Needs attention" is the screen's reason to exist. A list of counts is a report;
a list of *clients who need something* is a tool. Build that first.

### 2. Clients — `GET /trainer/clients`

List: avatar, name, plan, adherence % (last 30 d), last active, next session,
unread badge. Filters: status, plan, adherence band, "inactive 7 d+".

**Client detail** (`/clients/:id`) is the app's centre of gravity — tabs:

| Tab | Source | Multi-trainer rule |
|-----|--------|--------------------|
| Overview | profile, goal, subscription(s), **all** assigned trainers with "also coached by", quick actions | read: all |
| Progress | same charts as the user's Progress screen, `GET /trainer/clients/{id}/progress` | read: all |
| Workouts | **every trainer's** assignments + session history + drill-down; each item attributed to its assigner | read: all · edit: assigner only |
| Nutrition | daily logs vs food plan, compliance — **only if the client's plan includes it** | read: all · edit: assigner only |
| Health | weight/BF/sleep/BP trends — **health data: gate on entitlement + log the access** | read: all |
| Messages | this trainer's thread only, inline; quota is per-thread | own thread only |
| Notes | trainer-private notes — `fc_client_notes` scoped `(trainer_id, user_id)` | **private, never shared** |

Reuse the user app's chart components verbatim; they take a series and render it,
and the API returns the same shape.

### 3. Plan builder — `/plans`

CRUD over `fc_plans` where `owner_type='trainer'` and `trainer_id` = self.
Sections: details, difficulty/duration/weekly sessions, **feature flags**
(the `features` JSON from spec §2.5, rendered as toggles), pricing matrix
(4 cycles × price, each nullable), attached workouts, attached food plans.

The feature-flag editor is what makes entitlements real. It needs plain-language
labels ("Client can log nutrition") and a live preview of what the client will and
will not see.

### 4. Workout builder — `/workouts`

The highest-value screen in the trainer app and the one with the most UI work:

- Workout metadata: name, type, difficulty, duration, cover image, equipment,
  muscle groups, video
- Exercise list with **drag reorder**, each row: name, type, sets, reps/seconds,
  weight, **rest**, notes, thumbnail, video
- Exercise library: save an exercise for reuse, search, insert
- Duplicate a workout; save as template
- Preview as the client will see it (reuse the user app's WorkoutDetail component)

Saves as an ordered bulk replace (`PUT /trainer/workouts/{id}/exercises`) in one
transaction — see the note in [05](05-admin-app.md) about the prototype's
delete-and-reinsert losing historical linkage.

### 5. Food plan builder — `/food-plans`

Plan → meals (breakfast/lunch/dinner/snack) → items from `fc_foods` with
quantities. Live daily totals against a target. Duplicate-day helper (trainers
build a week by copying Monday four times). Assign to clients.

### 6. Messages — `/messages`

Same components as the user app, inverted: thread list is *clients*, and the
trainer is never quota-limited. Add canned responses and the ability to attach a
workout or food plan to a message.

### 7. Support — `/support`

Tickets assigned to this trainer (`fc_handle_tickets`). Reply, internal notes,
escalate to admin.

### 8. Profile — `/profile`

Bio, specialization, hourly rate (display-only), avatar, **accepting-clients
toggle**, **max clients**, availability. Public-facing — this is the record users
see in the directory (Q4), so it is now a acquisition surface, not just settings.

### 9. Client requests — `/requests` (NEW, Q4)

The inbound queue. Pending requests with the requester's goal, fitness level,
optional message, and time waiting. Accept → `status='active'`; decline → optional
reason, notification either way.

Needs: a nav badge (trainers will not check a tab they cannot see is populated),
an auto-decline after N days of no response (a request left pending forever is
worse than a decline), and a visible capacity indicator — "18 of 20 clients" —
so a trainer understands why the directory has stopped sending them requests.

## Settled 2026-07-24

**Trainers are traced, not paid** (Q2). The platform collects all revenue;
trainers are compensated outside the system. Therefore **no** payouts, commission
splits, ledgers, balances, Stripe Connect, KYC, or tax forms — and **no trainer
earnings screen**. What remains is *attribution*: `fc_subscriptions.trainer_id`
records whose plan was bought, `fc_plans.trainer_id` records authorship, and
`GET /admin/reports/trainers` reports per-trainer clients, sessions, adherence and
attributed revenue. The trainer's own dashboard shows activity, **never money**.
`fc_trainers.hourly_rate` is display-only profile metadata and must never be wired
into a calculation. Full detail: [09 §4.1](09-gap-register.md#41-q2--trainers-are-traced-not-paid).

**A user may have multiple trainers** (Q3). This is the change that most affects
the screens below:

- Add `fc_user_trainers.is_primary` — one per user, for the dashboard's "your
  trainer" and for notification routing.
- **Entitlements merge across concurrent subscriptions**: union of booleans, max
  of numeric caps, and `max_messages_per_week` scoped **per trainer thread**
  rather than globally. A user coached by two trainers gets each plan's features
  and a separate message allowance per conversation.
- **Visibility** (Q13, confirmed): every assigned trainer can *read* the entire
  client record — progress, sessions, health, nutrition, **and every other
  trainer's assignments, plans and food plans**. Only the assigning trainer can
  *edit* their own. `fc_client_notes` stays private per trainer — confirmed (Q15).
- Client detail shows an "also coached by" indicator, and **every assigned item is
  attributed** ("Assigned by Mike Torres, 12 Jul"). Shared visibility without
  attribution is worse than none — a trainer must be able to tell their own
  programming from someone else's.
- `Guard::trainerOwnsClient()` needs no change — it already passes for any
  `active` row, which is exactly the shared-read rule.

Merge rules and the reasoning: [09 §4.2](09-gap-register.md#42-q3--multiple-trainers-per-user)
and [§4.5](09-gap-register.md#45-q13--trainers-see-each-others-assignments).

**Users request trainers** (Q4). Users browse a directory and request; the trainer
accepts or declines. Admins may still assign directly as an override. This adds a
**request queue** screen here (§9 below) and a directory + profile + request flow
in the user app ([04](04-user-app.md)). `fc_user_trainers.status` grows to
`('pending','active','inactive','declined','withdrawn')`; `fc_trainers.max_clients`
caps directory visibility. Full flow:
[09 §4.4](09-gap-register.md#44-q4--users-request-trainers).

**Access is sold, not free** (Q14). A user must hold an active plan to request a
trainer at all, and `fc_plans.features.max_trainers` caps how many they may have —
merged as the **max** across their active plans, counting pending requests.
Trainers may reject requests. On downgrade, existing relationships are
grandfathered and only new requests are blocked
([09 §4.6](09-gap-register.md#46-q14--an-active-plan-gates-trainer-access)).

For this app that means: requests arriving in the queue are always from users who
already hold an active plan, so there is no "unqualified request" state to design
around. The trainer's own capacity (`accepting_clients`, `max_clients`) is a
separate, independent gate.

**Notes stay private** (Q15, confirmed). `fc_client_notes` is scoped
`(trainer_id, user_id)` and never readable by another trainer, even though Q13
opened up assignments and plans. No change to the plan.

## Still unanswered

1. **Can a trainer create a client account,** or only accept requests and admin
   assignments? (Small — affects one button.)
2. **On downgrade, is grandfathering correct?** (Q16) Planned yes — never
   auto-sever a coaching relationship over a billing change.

## Estimate

| Screen | Days |
|--------|------|
| Shell, auth, nav, dashboard | 3 |
| Client list + detail (7 tabs) | 6 |
| Workout builder | 5 |
| Plan builder + feature flags | 3 |
| Food plan builder | 3 |
| Messages | 2 |
| Support + profile | 2 |
| Backend endpoints + ownership guards | 4 |
| Multi-trainer visibility + attribution + `fc_client_notes` (Q3/Q13) | 1 |
| Assignment conflict warnings (Q13) | 1 |
| Client request queue: accept/decline/capacity (Q4) | 1 |
| **Total** | **~31 d** |

Reducible to ~21 d by cutting the food-plan builder and the exercise library to
Phase 4 — recommended if the timeline is tight, since neither blocks the core
coach-a-client loop.

Note this is the **trainer app only**. Q4's user-facing side — directory, trainer
profile, request flow — is ~3 d in the user app ([04](04-user-app.md)), counted
separately in Phase 3.

The estimate is unchanged in substance by Q2: no payout work was ever budgeted
here. What Q2 removed is the *risk* that this figure was wrong by a whole
subsystem — marketplace payouts would have been 15–25 d on their own, plus a
compliance review. That risk is now retired rather than timeboxed.
