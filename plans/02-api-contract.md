# 02 — API Contract

Base: `/wp-json/fitnessclub/v1`. Every endpoint below is traced to the screen that
needs it — an endpoint with no consumer does not get built.

## Conventions

**Auth headers**

```
X-FC-CSRF: <token from the boot payload>      # embedded apps (default)
Authorization: Bearer <jwt>                   # external clients only, Phase 4
```

**Success envelope** — bare payload, HTTP status carries the outcome:

```json
{ "id": 12, "workout_name": "Upper Body Power", "…": "…" }
```

**Collection envelope** — data array + headers `X-WP-Total`, `X-WP-TotalPages`:

```json
{ "items": [ … ], "total": 148, "page": 1, "per_page": 20 }
```

**Error envelope** — WP's native `WP_Error` serialisation, so clients get one shape:

```json
{ "code": "fc_quota_exceeded",
  "message": "Weekly message limit reached (10 of 10).",
  "data": { "status": 429, "limit": 10, "resets_at": "2026-07-27T00:00:00Z" } }
```

Error codes are namespaced `fc_*` and stable — clients branch on `code`, never on
`message` (which is translated).

**Common list params:** `page`, `per_page` (≤100), `q`, `sort` (`field:asc|desc`),
plus resource-specific filters. Date ranges use `from`/`to` (ISO-8601 dates, UTC).

**Status codes:** 200 read / 201 create / 204 delete / 400 validation /
401 unauthenticated / 403 unauthorised or entitlement-blocked / 404 /
409 state conflict (e.g. session already running) / 422 semantic / 429 rate or
quota / 500.

**Entitlement failures are 403 with `code: "fc_feature_unavailable"` and
`data.required_feature`** so the UI can render an upgrade prompt rather than a
generic error. This is the plumbing that makes `plan.md` §2.5's feature flags
mean anything.

---

## Auth — `/auth/*`

| Method | Path | Screen / purpose | Auth |
|--------|------|------------------|------|
| POST | `/auth/register` | Registration panel (**no prototype — new**) | public, rate-limited |
| POST | `/auth/login` | Login panel (**no prototype — new**) | public, rate-limited |
| POST | `/auth/logout` | Profile menu | user |
| GET | `/auth/me` | App boot: identity, role, entitlements | user |
| POST | `/auth/password/forgot` | Login panel | public, rate-limited |
| POST | `/auth/password/reset` | Reset link target | public |
| POST | `/auth/token` | Mobile login → JWT (Phase 4) | public |
| POST | `/auth/token/refresh` | Mobile (Phase 4) | refresh token |

`GET /auth/me` is the single boot call and returns everything the SPA needs to
render its first frame without a spinner cascade. Note `subscriptions` and
`trainers` are **arrays** — a user may hold one subscription per trainer (Q3), and
entitlements are the merged result across all of them
([03](03-backend.md#merging-across-multiple-subscriptions)):

```json
{ "user": { "id": 1, "account_id": 5, "display_name": "Alex Morgan",
            "avatar_url": "…", "role": "fc_user", "timezone": "Europe/Belgrade" },
  "subscriptions": [ { "plan_name": "Pro", "status": "active", "cycle": "monthly",
                       "renews_at": "2026-08-15", "trainer_id": 1 } ],
  "trainers": [ { "id": 1, "display_name": "Sarah Chen", "is_primary": true },
                { "id": 2, "display_name": "Mike Torres", "is_primary": false } ],
  "entitlements": { "can_log_nutrition": true, "can_message": true,
                    "has_video_workouts": true,
                    "max_trainers": 2, "trainers_used": 2,
                    "message_quota_by_trainer": { "1": 10, "2": 5 } },
  "theme": { "colors": {…}, "typography": {…}, "layout": {…}, "components": {…} },
  "app": { "base": "/fitness", "basename": "/fitness", "spa": "user" },
  "counts": { "unread_messages": 2, "unread_notifications": 3 } }
```

`app.base` is the configured front-end slug ([D9](00-architecture.md#d9--front-end-routing-configurable-app-url));
the SPA uses `app.basename` for its react-router and `app.spa` (`user`|`trainer`|`admin`)
is which app the backend resolved for this role. The same object is also injected
into the render shell's `data-boot` so the app has it before the first fetch.

Note login/registration screens **do not exist in either prototype**. They are net
new UI (gap register §3.1).

---

## User profile — `/user/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/user/profile` | Profile |
| PUT | `/user/profile` | Profile → Save Changes |
| PUT | `/user/preferences` | Profile → Notifications + Privacy toggles |
| PUT | `/user/password` | Profile → Change Password |
| POST | `/user/avatar` | Profile → avatar upload (multipart) |
| GET | `/user/dashboard` | **Dashboard — one call** |
| GET | `/user/trainers` | Profile → My Trainers (assigned + pending requests) |
| DELETE | `/user/trainers/{trainerId}` | Leave a trainer, or withdraw a pending request |
| POST | `/user/trainers/{trainerId}/primary` | Set primary (Q3) |
| DELETE | `/user/account` | GDPR erasure (**new**, §15.1 implies it) |

### Trainer directory & requests — `/trainers/*` (Q4)

Users initiate assignment, so a logged-in user needs to read other users' trainer
profiles. These are the only endpoints that expose one account's data to another,
and they return a **strict public projection** — display name, avatar, bio,
specialization, rating, client count, accepting status. Never email, phone, or
client list.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/trainers` | Directory. Params: `q`, `specialization`, `sort`. Lists only `accepting_clients=1` **and** under `max_clients` |
| GET | `/trainers/{id}` | Public profile + this user's current request state |
| POST | `/trainers/{id}/request` | `{message?}` → creates `status='pending'` |
| DELETE | `/trainers/{id}/request` | Withdraw → `status='withdrawn'` |

`GET /trainers` returns, alongside the list, the caller's own eligibility so the
UI renders the right call-to-action instead of guessing:

```json
{ "items": [ … ],
  "eligibility": { "can_request": false, "reason": "limit_reached",
                   "trainers_used": 2, "trainers_allowed": 1,
                   "upgrade_url": "…" } }
```

`reason` is `subscription_required` | `limit_reached` | `null`. The directory is
still **shown** to users who cannot request — hiding it removes the clearest
reason to upgrade — with the request button disabled and explained.

`POST /trainers/{id}/request` rejection ladder (Q14):

| Status | Code | When |
|---|---|---|
| 403 | `fc_subscription_required` | No active subscription |
| 403 | `fc_trainer_limit_reached` | `active + pending >= max_trainers`; `data: {limit, used, upgrade_url}` |
| 409 | `fc_trainer_at_capacity` | Trainer filled up between page load and submit |
| 409 | `fc_request_exists` | Already pending or active with this trainer |
| 429 | `fc_request_limit` | Beyond 3 pending or 10 per week |

Pending requests count against `max_trainers` — otherwise a user on a one-trainer
plan queues five requests and keeps whichever lands first. The capacity race is a
real state, not an edge case: a directory is a race by design.

### `GET /user/dashboard` — the aggregate

The dashboard prototype reads six independent datasets. Six round-trips on a
mobile connection is the difference between a 400 ms and a 2 s first paint, so it
is one endpoint, assembled server-side, cached 60 s per user:

```json
{ "greeting": { "name": "Alex", "streak_days": 5 },
  "stats": { "streak_days": 5, "calories_burned_today": 390,
             "current_weight_kg": 78.5, "goal_progress_percentage": 68 },
  "todays_workout":  { "id": 1, "workout_name": "…", "difficulty": "intermediate",
                       "duration_minutes": 45, "exercise_count": 8,
                       "muscle_groups": ["Chest"], "progress_percentage": 65,
                       "resumable_session_id": 42 },
  "upcoming_workout": { "id": 2, "…": "…" },
  "water": { "consumed_ml": 1250, "goal_ml": 2000, "glass_ml": 250 },
  "nutrition": { "calories": {"value":1650,"goal":2400},
                 "protein_g": {"value":120,"goal":180}, "…": "…" },
  "weekly_chart": { "labels": ["Mon","…"], "calories": [320,450,…] },
  "monthly_stats": { "workouts_completed": 20, "calories_burned": 8420,
                     "avg_hours_per_week": 4.2, "consistency_percentage": 92 },
  "recent_activity": [ { "type":"workout.completed", "title":"…",
                         "detail":"…", "occurred_at":"2026-07-24T09:00:00Z" } ],
  "message_previews": [ { "thread_id":1, "trainer_name":"Sarah Chen",
                          "avatar_url":"…", "preview":"…",
                          "unread_count":2, "last_message_at":"…" } ] }
```

Cache invalidated on any write that feeds it (session complete, meal log, water,
weight, message receipt).

---

## Workouts — `/workouts/*`, `/sessions/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/workouts` | Workouts list. Params: `status`, `difficulty`, `type`, `q` |
| GET | `/workouts/{id}` | Workout Detail — includes ordered exercises |
| GET | `/exercises/{id}` | Exercise detail modal |

### The session state machine

This is the most important contract in the product. `plan.md` §6.3 specifies
`POST /workouts/start`, `PUT /workouts/pause|resume|stop`, `POST /workouts/complete`
without an identifier — meaning the server must infer which session, which breaks
the moment a user has two devices. Replaced with an explicit resource:

| Method | Path | Transition |
|--------|------|------------|
| POST | `/sessions` | `{workout_id}` → creates `in_progress`. **409** if one is already open (body names it, so the client can offer "resume or discard") |
| GET | `/sessions/active` | Boot/resume — returns the open session + cursor, or `null` |
| GET | `/sessions/{id}` | Rehydrate a specific session |
| PATCH | `/sessions/{id}` | `{action: "pause"\|"resume"}` — server stamps time |
| POST | `/sessions/{id}/sets` | Log one completed set (see below) |
| PATCH | `/sessions/{id}/cursor` | `{exercise_index, set_index}` — skip/prev navigation |
| POST | `/sessions/{id}/complete` | Finish → returns the celebration payload |
| POST | `/sessions/{id}/abandon` | "Stop" — logs elapsed time only, per §8.1 |
| GET | `/sessions` | Workout history list |
| PATCH | `/sessions/{id}/review` | §8.4 post-workout adjustment: actual reps/sets/weights, notes, RPE, difficulty |

`POST /sessions/{id}/sets` body:

```json
{ "exercise_id": 101, "set_index": 0, "reps": 8,
  "weight_kg": 80, "duration_seconds": null, "rest_taken_seconds": 92, "rpe": 8 }
```

Idempotent on `(session_id, exercise_id, set_index)` — a retry after a dropped
connection updates rather than duplicates. This matters: the player fires this
call mid-workout, on gym wifi.

`POST /sessions/{id}/complete` response drives the celebration screen wholesale:

```json
{ "session_id": 42, "duration_seconds": 2730, "calories_burned": 296,
  "exercises_completed": 8, "total_exercises": 8, "completion_percentage": 100,
  "total_volume_kg": 12480,
  "personal_records": [ { "exercise_name": "Barbell Bench Press",
                          "record_type": "max_weight", "value": 80, "unit": "kg",
                          "previous_value": 77.5, "is_new": true } ],
  "streak_days": 6, "streak_extended": true }
```

Timer authority: the client renders elapsed time optimistically, but the server
recomputes `duration_seconds` from `started_at`/`last_resumed_at`/`paused_seconds`
on every pause and on complete. Client values are never persisted.

---

## Nutrition — `/nutrition/*`, `/foods/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/nutrition/day?date=` | Nutrition page — meals + totals + goals + water |
| GET | `/nutrition/logs?from=&to=` | History |
| POST | `/nutrition/logs` | Add Meal modal |
| PUT | `/nutrition/logs/{id}` | Edit meal |
| DELETE | `/nutrition/logs/{id}` | Delete meal |
| POST | `/nutrition/water` | Water dots (`{delta_ml}` or `{total_ml}`) |
| PUT | `/nutrition/goals` | Goal editor (**new** — prototype has goals but no editor) |
| GET | `/foods?q=&category=` | Search Food modal (typeahead, ≥2 chars) |
| GET | `/foods/barcode/{code}` | Scan Barcode (prototype: "coming soon") |
| POST | `/foods` | User/trainer-created custom food |
| GET | `/food-plans` | Assigned food plans |
| GET | `/food-plans/{id}` | Plan detail with meals |
| GET | `/food-plans/{id}/compliance?from=&to=` | §12.1 "track compliance" |

`POST /nutrition/water` takes `{delta_ml}` for the +1 button and `{total_ml}` for
clicking the *n*-th dot directly — the prototype does both.

Entitlement: all `/nutrition/*` writes require `can_log_nutrition`, else 403
`fc_feature_unavailable`.

---

## Health & progress — `/health/*`, `/progress/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/health/stats?from=&to=&metrics=` | Health page cards + history modal |
| POST | `/health/stats` | "Add Entry" (upserts on `record_date`) |
| PUT | `/health/stats/{id}` | Edit |
| DELETE | `/health/stats/{id}` | Delete |
| GET | `/health/measurements` | Profile measurements + radar chart |
| POST | `/health/measurements` | Save measurements |
| GET | `/health/summary` | Health card values + BMI + trend arrows |
| GET | `/progress?range=week\|month\|quarter\|year` | **Progress page, all charts** |
| GET | `/progress/exercises/{name}` | Per-exercise progression |
| GET | `/progress/records` | PR list |
| GET | `/progress/consistency?year=` | Calendar heatmap (§9.3) |

`GET /progress?range=` returns every series the page charts, pre-bucketed
server-side to the range:

```json
{ "range": "month", "from": "2026-06-24", "to": "2026-07-24",
  "weight": [ {"date":"2026-06-24","value":80.2}, … ],
  "body_fat": [ … ],
  "strength": [ { "exercise_name":"Bench Press", "points":[{"date":"…","value":70}] } ],
  "consistency": { "labels":["W1",…], "workouts":[4,5,3,5] },
  "measurements": [ { "date":"2026-06-01", "chest":101, "waist":85, … } ],
  "totals": { "workouts_completed":20, "calories_burned":8420, "personal_records":3 } }
```

The prototype's week/month/3M/year toggle is **wired but inert** — it sets state
and re-renders identical data (gap §2.6). Bucketing belongs server-side anyway:
a year of daily points is 365 values the chart cannot usefully draw.

---

## Messaging — `/messages/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/messages/threads` | Conversation list |
| GET | `/messages/threads/{id}?before=&limit=` | Thread, reverse-paginated |
| POST | `/messages/threads/{id}` | Send |
| POST | `/messages/threads/{id}/read` | Mark thread read |
| GET | `/messages/unread-count` | Nav badge |
| GET | `/messages/poll?since=` | 15 s poll for new messages + typing state |
| POST | `/messages/attachments` | Upload (multipart, images only, ≤5 MB) |

`POST` returns `429 fc_quota_exceeded` with `data.limit`/`data.used`/`data.resets_at`
when the plan's `max_messages_per_week` is hit (spec §11.2). The quota counts
**user→trainer** messages in a rolling 7 days; trainer replies are never counted
(otherwise a trainer can exhaust a client's quota).

The quota is **per thread, not per user** (Q3): a user coached by two trainers
gets a separate allowance for each conversation, resolved from the subscription
whose `trainer_id` matches that thread. A global cap would let one trainer's
conversation consume the other's. `GET /messages/threads` returns
`quota: {limit, used, resets_at}` per thread so the composer can show remaining
sends without a second call.

The prototype renders a permanent typing indicator whenever the trainer is
"online" — it is decorative. Real typing state needs either a transient-backed
`POST /messages/threads/{id}/typing` + poll, or dropping the feature. **Recommend
dropping it at launch**; it costs a write per keystroke-burst for cosmetic value.

---

## Subscriptions & billing — `/billing/*`

| Method | Path | Screen |
|--------|------|--------|
| GET | `/billing/subscription` | Subscription page — current plan + benefits |
| GET | `/billing/plans` | Plan selector (available platform + trainer plans) |
| POST | `/billing/checkout` | Start checkout → gateway session URL |
| POST | `/billing/subscription/cancel` | Cancel (`{at_period_end: true}`) |
| POST | `/billing/subscription/resume` | Undo a pending cancellation |
| POST | `/billing/subscription/change` | Upgrade/downgrade with proration preview |
| GET | `/billing/payments` | Payment history table |
| GET | `/billing/invoices/{id}` | Invoice PDF/detail |
| POST | `/billing/webhook/{gateway}` | **Public**, signature-verified, idempotent |

The webhook is the only public write endpoint. It verifies the gateway signature
before parsing, dedupes on `transaction_id`, and returns 200 even on a duplicate
so the gateway stops retrying. It never trusts amounts from the request body over
what the gateway API reports.

`POST /billing/checkout` never accepts a price from the client — only a
`plan_id` + `cycle`; the server looks up the amount. This is the single most
commonly exploited endpoint in subscription plugins.

---

## Notifications & support

| Method | Path | Screen |
|--------|------|--------|
| GET | `/notifications?unread_only=` | Notifications page |
| POST | `/notifications/{id}/read` | Tap a notification |
| POST | `/notifications/read-all` | "Mark All Read" |
| DELETE | `/notifications/{id}` | Dismiss |
| GET | `/support/tickets` | Ticket list |
| POST | `/support/tickets` | Create Ticket modal |
| GET | `/support/tickets/{id}` | Ticket detail + replies |
| POST | `/support/tickets/{id}/replies` | Reply |
| PATCH | `/support/tickets/{id}` | Close/reopen (user); status/assign/priority (staff) |
| GET | `/support/faq` | FAQ modal — currently hardcoded in JS |

---

## Trainer — `/trainer/*` (cap `fc_manage_clients`)

Every one of these is filtered by an `active` row in `fc_user_trainers`. No
trainer endpoint accepts an arbitrary `user_id` without that check.

Because a client may have several trainers (Q3), two guards apply. Q13 settled the
boundary between them:

- **Reads** — `Guard::trainerOwnsClient`. Any assigned trainer may read the entire
  client record: progress, sessions, health, nutrition, **and every other
  trainer's assignments, plans and food plans**. Responses carry
  `assigned_by: {trainer_id, display_name, assigned_at}` on every assignment, so
  the UI can attribute it.
- **Writes** — `Guard::trainerAssignedResource`. Only the trainer who created a
  plan/assignment/food plan may modify or delete it. Marked **write** below.
- **Notes** — owner only, neither guard (Q15).

`POST /trainer/clients/{userId}/workouts` returns a non-blocking
`warnings: [{code: "fc_assignment_conflict", …}]` when the new assignment overlaps
another trainer's for the same week — the point of shared visibility.

| Method | Path | Screen ([06](06-trainer-app.md)) |
|--------|------|--------|
| GET | `/trainer/dashboard` | Trainer overview aggregate |
| GET | `/trainer/requests` | **Pending client requests** (Q4) — inbound queue |
| POST | `/trainer/requests/{id}/accept` | → `status='active'`, notifies the user |
| POST | `/trainer/requests/{id}/decline` | `{reason?}` → `status='declined'` |
| GET | `/trainer/clients` | Client list. Params: `status`, `q`, `plan_id` |
| GET | `/trainer/clients/{userId}` | Client detail |
| GET | `/trainer/clients/{userId}/progress?range=` | Client progress charts |
| GET | `/trainer/clients/{userId}/sessions` | Client workout history |
| GET | `/trainer/clients/{userId}/nutrition` | Client nutrition (if plan permits) |
| POST | `/trainer/clients/{userId}/workouts` | Assign a workout — **write guard** |
| DELETE | `/trainer/clients/{userId}/workouts/{id}` | Unassign — **write guard**, assigner only |
| POST | `/trainer/clients/{userId}/food-plans` | Assign a food plan — **write guard** |
| GET/POST/PUT/DELETE | `/trainer/clients/{userId}/notes[/{id}]` | Private notes — **never visible to another trainer** |
| GET/POST/PUT/DELETE | `/trainer/plans[/{id}]` | Plan builder + pricing |
| GET/POST/PUT/DELETE | `/trainer/workouts[/{id}]` | Workout builder |
| PUT | `/trainer/workouts/{id}/exercises` | Bulk replace exercise list (ordered) |
| GET/POST/PUT/DELETE | `/trainer/food-plans[/{id}]` | Food plan builder |
| GET | `/trainer/profile`, PUT same | Trainer profile |

`PUT /trainer/workouts/{id}/exercises` replaces the whole ordered collection in
one transaction rather than exposing per-exercise CRUD — matches how the builder
UI works (drag to reorder, then save) and avoids partial-save corruption.

---

## Admin — `/admin/*` (cap `manage_options`)

Eight resources, uniform CRUD, matching the admin prototype's generic `CrudTable`:

```
GET    /admin/{resource}          ?page&per_page&q&sort&<filters>
POST   /admin/{resource}
GET    /admin/{resource}/{id}
PUT    /admin/{resource}/{id}
DELETE /admin/{resource}/{id}
```

| Resource | Filters | Prototype table |
|----------|---------|-----------------|
| `users` | `status`, `plan_id`, `trainer_id` | Users |
| `trainers` | `status`, `specialization` | Trainers |
| `workouts` | `difficulty`, `type`, `trainer_id` | Workouts (+ nested exercises) |
| `foods` | `category`, `source` | Food Database |
| `meals` | `user_id`, `meal_type`, date range | Meal Logs |
| `health-entries` | `user_id`, `metric`, date range | Health Entries |
| `payments` | `status`, `gateway`, date range | Billing |
| `plans` | `owner_type`, `is_active` | **missing from prototype** |
| `tickets` | `status`, `priority`, `assigned_to` | **missing from prototype** |
| `subscriptions` | `status`, `plan_id` | **missing from prototype** |

Plus non-CRUD admin operations:

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/admin/dashboard` | Stat cards |
| GET | `/admin/reports/trainers` | Q2 attribution: clients, sessions, adherence, attributed revenue per trainer |
| POST | `/admin/users/{id}/trainers` | **Add** a trainer — a user may have several (Q3). Prototype hardcodes 4 trainers in a `<select>` |
| DELETE | `/admin/users/{id}/trainers/{trainerId}` | Unassign one |
| POST | `/admin/users/{id}/trainers/{trainerId}/primary` | Set the primary trainer |
| POST | `/admin/users/{id}/reset-password` | §13.1 |
| POST | `/admin/users/{id}/impersonate` | Support tool — **audit-logged**, admins only, off by default |
| GET | `/admin/themes` | Theme list |
| POST | `/admin/themes` | Upload theme zip |
| PUT | `/admin/themes/{slug}` | Edit theme JSON |
| POST | `/admin/themes/{slug}/activate` | Set active |
| DELETE | `/admin/themes/{slug}` | Delete (never the bundled default) |
| GET/PUT | `/admin/settings` | §13.4 general/email/payment/feature toggles, **+ `routing.app_base`** (the front-end slug) |
| POST | `/admin/settings/flush-rewrites` | Re-flush rewrite rules after an `app_base` change (also fired automatically on save) |
| GET | `/admin/export/{resource}` | CSV export (§ Phase 4) |
| POST | `/admin/import/foods` | Bulk food CSV import |

**The `admins` resource from the prototype is deliberately absent.** Administrator
accounts are WP users; a parallel `fc_admins` table would be a second, unenforced
identity store. The prototype's Admins screen becomes a filtered view of WP users
holding `manage_options`.

---

## Cross-cutting

**Rate limits** (spec §14.3): 100 req/min authenticated, 10 req/min
unauthenticated, plus tighter per-endpoint buckets — `/auth/login` 5/min per IP,
`/auth/password/forgot` 3/hour per email, `/messages` 30/min,
`/billing/checkout` 10/hour, **`/trainers/{id}/request` 3 pending / 10 per week
per user** (Q4). `429` carries `Retry-After`.

**Caching:** `GET /user/dashboard`, `/progress`, `/admin/dashboard` are cached in
transients keyed by user + range. Everything else is uncached; add per-endpoint
caching only against measured latency.

**Versioning:** the folder `api/fitnessclub/v1/` *is* the version. A breaking
change means `v2/` alongside, with `v1` kept for two releases. Additive fields are
not breaking; removed or retyped fields are.

**OpenAPI:** generate `docs/openapi.yaml` from the route definitions + arg schemas
(spec §20.1). Because wpBones passes `args` straight to `register_rest_route`,
declaring full `args` schemas per route gives validation, sanitisation, and the
spec document from one definition. **Write the `args` schemas — do not skip them.**
