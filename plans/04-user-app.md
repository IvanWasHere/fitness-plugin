# 04 — User App

Port of `user-app/index.html` (1 509 lines, Mithril + Dexie + Chart.js) to a
**React 18 + TypeScript** SPA ([D1](00-architecture.md#d1--view-framework-react-18--typescript--confirmed-2026-07-24)),
**built with Vite** as one of three role-specific apps in the `ui/` workspace
([D10](00-architecture.md#d10--front-end-three-vite-compiled-react-spas)) —
`ui/src/user/`, sharing `ui/src/shared/` with the trainer and admin apps.

**Mounting.** No shortcode. The backend serves this SPA at the configured URL
(`example.com/{base}`, default `/fitness`) whenever the logged-in user is an
`fc_user` — the same URL serves the trainer/admin SPA to those roles
([D9](00-architecture.md#d9--front-end-routing-configurable-app-url)). The rewrite
render emits a standalone Blade shell with `<div id="fc-app" data-boot="…">` + this
app's Vite manifest tags; the app reads `data-boot` (REST root, nonce, user, role,
theme tokens, entitlements, locale) and sets its react-router `basename` to
`/{base}`. An **unauthenticated** visitor to `/{base}` gets *this* app's
login/register screen (§3.1); on success the backend re-resolves the role.

**Read [09-gap-register.md §2](09-gap-register.md#2-prototype-defects-do-not-port-these)
first.** Several prototype screens do not run — porting them literally
reproduces the bugs.

## Screen inventory

| Route | Prototype component | Feeds from | Notes |
|-------|--------------------|-----------|-------|
| `/` dashboard | `Dashboard` | `GET /user/dashboard` | 1 call replaces 6 IndexedDB reads |
| `/workouts` | `Workouts` | `GET /workouts` | grid ⇄ list toggle preserved |
| `/workouts/:id` | `WorkoutDetail` | `GET /workouts/{id}` | |
| `/progress` | `Progress` | `GET /progress?range=` | 4 charts; range filter made real |
| `/nutrition` | `Nutrition` | `GET /nutrition/day` | calorie ring + macro cards + meals |
| `/health` | `Health` | `GET /health/summary` | 8 metric cards → detail modal |
| `/messages` | `Messages` | `GET /messages/threads` | list + thread pane |
| `/subscription` | `Subscription` | `GET /billing/subscription` | plan card, history, actions |
| `/profile` | `Profile` | `GET /user/profile` | 5 sections, edit mode |
| `/notifications` | `Notifications` | `GET /notifications` | |
| `/support` | `Support` | `GET /support/tickets`, `/support/faq` | |
| — overlay | `WorkoutPlayer` | session endpoints | full-screen, own state machine |
| — overlay | `Celebration` | complete response | confetti canvas |
| — overlay | `Modal` ×6 | various | addMeal, addWeight, searchFood, faq, ticket, healthDetail |
| — | `Toasts`, `Sidebar`, `BottomNav` | — | chrome |

### Screens with no prototype (must be designed, not ported)

| Route | Screen | Why | Est. |
|-------|--------|-----|------|
| `/login` `/register` `/forgot` `/reset` | Auth | The prototype boots into a seeded session | 3 d |
| `/onboarding` | Height/weight/goal capture, plan choice, trainer request | Without it every chart is empty and "Goal Progress" divides by zero | 3 d |
| `/plans` `/checkout` | Plan comparison + checkout | Subscription screen shows an existing plan and three "coming soon" toasts | 4 d |
| **`/trainers`** | **Trainer directory** (Q4) | Users initiate assignment — browse, filter by specialization, see rating/capacity | 2 d |
| **`/trainers/:id`** | **Trainer profile + request form** (Q4) | Bio, specialization, optional request message, current request state | 1 d |
| `/profile` → My Trainers | Assigned trainers, primary marker, request status, leave-trainer | Falls out of Q3 + Q4 | ½ d |

The trainer directory is reachable from the **main navigation**, not only from
onboarding: with multiple trainers allowed (Q3), adding a coach is an ongoing
action rather than a one-time setup step.

Directory rules: only trainers with `accepting_clients=1` and under `max_clients`
are listed; the response is a **public projection** (no email, phone, or client
list); requests are rate-limited to 3 pending / 10 per week per user.

**Requesting requires an active plan, and the plan caps how many trainers you may
have** (Q14). The directory is still shown to users who cannot request — that is
the clearest upgrade prompt in the product — with the button disabled and the
reason stated. `GET /trainers` returns an `eligibility` block
(`can_request`, `reason`, `trainers_used`, `trainers_allowed`, `upgrade_url`), so
the CTA is driven by the server rather than inferred client-side. Three states to
design, not one:

| State | UI |
|---|---|
| Can request | Primary "Request" button |
| `subscription_required` | Disabled button + "Choose a plan to work with a trainer" → `/plans` |
| `limit_reached` | Disabled button + "2 of 1 trainer slots used" → upgrade, or "end a coaching relationship" → My Trainers |

The `limit_reached` copy must not imply the user did something wrong — with
grandfathering on downgrade ([09 §4.6](09-gap-register.md#46-q14--an-active-plan-gates-trainer-access))
"2 of 1 used" is a normal, expected state.

Flow detail: [09 §4.4](09-gap-register.md#44-q4--users-request-trainers).

**Also missing:** empty states, error states, offline state.

## Structure

```
ui/src/user/                    # Vite entry; shares ui/src/shared/ with trainer+admin
├── main.tsx                    # reads #fc-app data-boot, mounts, installs theme vars
├── App.tsx                     # router (basename=/{base}) + chrome + overlay outlets
├── api/
│   ├── client.ts               # fetch wrapper: nonce, error envelope, nonce-refresh retry
│   ├── queries.ts              # TanStack Query hooks, one per endpoint
│   └── types.ts                # generated from the OpenAPI doc
├── state/
│   ├── SessionProvider.tsx     # active workout session, survives navigation
│   ├── ThemeProvider.tsx       # CSS custom properties from boot payload
│   └── ToastProvider.tsx
├── screens/{Dashboard,Workouts,WorkoutDetail,Progress,Nutrition,Health,
│            Messages,Subscription,Profile,Notifications,Support,Auth}/
├── features/workout-player/    # Player, ExerciseView, Timers, Controls, Celebration
├── components/                 # Card, StatCard, Tag, ProgressBar, Modal, Toast,
│                               # Chart wrappers, WaterDots, MacroRing, EmptyState
├── hooks/                      # useCountdown, useElapsed, useInterval, usePoll
└── styles/                     # ported prototype CSS + the missing utilities
```

## State ownership

| Kind | Where | Why |
|------|-------|-----|
| Server data | TanStack Query | caching, refetch, optimistic updates, retry — replaces every `await db.x.get()` |
| Active workout session | `SessionProvider` (context + reducer), mirrored to server | must survive route changes and page reloads |
| UI (modals, toasts, layout toggle) | local `useState` / small context | ephemeral |
| Boot config (user, theme, entitlements) | read-once context | never refetched during a session |

The prototype's pattern — `oninit: async () => { this.workouts = await db… }` with
an **arrow function**, so `this` is module scope, not the component — means
`Dashboard`, `WorkoutDetail` and `Workouts` all write `this.workouts` on the same
object. That is a shared-mutable-state bug that happens to work because only one
screen renders at a time. Do not carry the pattern over; every screen owns its
query.

## Workout player — the hard part

The one screen with real complexity. Prototype: `startWorkoutPlayer()` +
`WorkoutPlayer` (lines 757–879) + `Celebration`.

### Timers

Three concurrent, and the prototype gets all three wrong in the same way:

| Timer | Prototype | Correct |
|-------|-----------|---------|
| Workout elapsed | `setInterval` incrementing a counter | derive from `Date.now() - startedAt - pausedTotal`; interval only triggers re-render |
| Rest countdown | `setInterval` decrementing | derive from a target timestamp |
| Set rest taken | not tracked | stamp on set completion, send with the set |

`setInterval` counters drift, and browsers throttle background tabs to ≥1 s (often
much worse on mobile) — leave the tab, come back, and the counter is minutes
behind. Timestamp-derived timers are immune. Implement once in
`useElapsed(startedAt, pausedMs, isPaused)` and `useCountdown(endsAt)`.

Also: the rest countdown must fire something when it reaches zero — audio cue,
vibration (`navigator.vibrate`), or a visual pulse. The prototype just displays
`0:00`, which is useless when the phone is on the floor.

### Session lifecycle

```
mount        → GET /sessions/active; if one exists, offer Resume / Discard
Start        → POST /sessions  (409 → same offer)
Next Set     → POST /sessions/{id}/sets  (queued, optimistic, retry on failure)
Pause/Resume → PATCH /sessions/{id}
Prev/Skip    → PATCH /sessions/{id}/cursor
Finish       → POST /sessions/{id}/complete → Celebration
Stop early   → POST /sessions/{id}/abandon
```

**Offline-tolerant writes.** Gyms have bad wifi. Set logs go into an in-memory
queue with retry + backoff; the UI advances immediately and shows a small
"syncing" indicator, not a blocking spinner. On unload, flush via
`navigator.sendBeacon`. The full offline-sync story in §17.2 is Phase 4; the
queue is Phase 1 because the alternative is losing a user's workout.

**Wake lock.** Request `navigator.wakeLock` while a session is active — a screen
that sleeps mid-set is the top complaint about every workout app. Release on
pause/complete.

### Prototype behaviours to fix during the port

- `finishWorkout()` reads `state.workoutPlayer.exercises.length`, which is
  `undefined` — the property is `.workout.exercises` (line 826). The celebration
  screen shows `undefined` exercises.
- PRs are hardcoded strings (`'Bench Press: 80kg x 8 (New PR!)'`). Comes from the
  complete response now.
- Calories are `elapsedSeconds * 6.5` regardless of the user, the exercise, or the
  intensity. Server-side estimate using MET values × body weight × duration.
- `confirm('Finish workout early?')` — a native modal blocks the JS thread and
  looks broken on mobile. Use the app's own dialog.
- Sets are never persisted; the player is pure local state. Every set is a write now.
- Weight/reps are read-only in the player. Users change the weight mid-workout
  constantly — the set logger must accept edited values, which is also what makes
  §8.4's post-workout adjustment coherent.

## Charts

Chart.js 4 via `react-chartjs-2`. Six chart surfaces:

| Chart | Type | Screen |
|-------|------|--------|
| Weekly calories burned | bar | Dashboard |
| Weight history | line, filled, tension .4 | Progress |
| Strength (3 lifts) | multi-line | Progress |
| Workout consistency | bar | Progress |
| Body measurements | radar | Progress |
| Calorie goal ring | hand-drawn canvas arc | Nutrition |
| Confetti | hand-drawn canvas, 120 particles | Celebration |

Port the prototype's exact Chart.js options — colours, grid `rgba(37,45,63,0.5)`,
tick colour `#7A8BA7`, border radius — but **drive them from theme tokens**, not
literals, or the charts stay dark-themed when someone activates a light theme.
Register only the Chart.js components used (tree-shaking); the UMD bundle the
prototype loads is ~200 KB.

The calorie ring is a hand-drawn arc; keep it (it is 10 lines) but make it an SVG
so it scales on hi-DPI and can be themed with `stroke` instead of a canvas colour.

Charts must handle the empty case. A new user has one weight reading and zero
sessions; `min: 76, max: 82` hardcoded on the weight axis (prototype) makes their
chart look broken. Compute domains from data with sensible padding, and render an
`EmptyState` below a threshold of points.

## CSS

The prototype stylesheet (~195 lines, lines 14–207) ports **as-is** — it is plain
CSS driven by `:root` custom properties, which is exactly the shape the theming
system needs. Two required changes:

1. Rename all custom properties to a `--fc-` prefix. (The standalone full-page
   render — [D9](00-architecture.md#d9--front-end-routing-configurable-app-url) —
   means no host-theme CSS is present, but the prefix is cheap insurance and is
   required for the optional shortcode-embed path.)
2. **Add the ~15 utility classes the prototype references but never defines**:
   `.text-center`, `.flex-column`, `.font-bold`, `.text-lg`, `.text-accent2`,
   `.text-info`, `.text-purple`, `.tag-accent`, `.mt-4`, `.mt-8`, `.mt-12`,
   `.mb-8`, `.mb-32`, `.gap-10`, `.gap-32`. They are used in markup throughout,
   so parts of the prototype are silently unstyled.

Also fix the `m('i.fa-solid fa-list')` pattern — a **space** instead of a dot, so
Mithril emits `class="fa-solid"` and the icon never renders. It occurs ~20 times.
In JSX these become explicit `className="fa-solid fa-list"` and the bug disappears
by construction, but check each icon renders.

**Scoping:** the app renders inside an arbitrary WordPress theme whose CSS will
fight it. Wrap everything in `#fc-app` and either raise specificity or use CSS
Modules / a cascade layer. Do not ship global `button {}` / `input {}` resets from
the prototype into a page that also has the theme's header and footer — the
prototype's `body { background: var(--bg) }` will repaint the whole site.

Font loading: the prototype pulls Outfit + DM Sans from Google Fonts and Font
Awesome from a CDN. **Self-host both** — GDPR (Google Fonts has been ruled a data
transfer in the EU), offline resilience, and no third-party render blocking.
Subset Font Awesome to the ~40 icons actually used; the full kit is ~1.4 MB.

## Responsive

Prototype breakpoints, preserved: ≤1024 px collapses 4/3-col grids to 2;
≤768 px hides the icon rail, shows the bottom nav, single-column everything, and
shrinks the player hero. Verify:

- Bottom nav "More" button currently navigates straight to Messages instead of
  opening a menu — the remaining 5 destinations are unreachable on mobile.
- The sidebar has an `.open` class and a mobile overlay, but **nothing sets
  `state.sidebarOpen = true`** — there is no hamburger button. On mobile the
  sidebar is permanently off-screen.
- `.chat-layout` uses `height: calc(100vh - 140px)`; on iOS Safari `100vh`
  includes the URL bar. Use `100dvh`.

## Accessibility

The prototype has `aria-label` on nav buttons and honours
`prefers-reduced-motion` — a good start. Add:

- Focus management: the player is a full-screen overlay; trap focus, restore on
  close, `Escape` to exit (with confirmation).
- Live regions: rest countdown and toasts must be announced (`aria-live="polite"`,
  `assertive` for the countdown's final seconds).
- Colour contrast: `--text2: #7A8BA7` on `--card: #171D2A` is ≈3.9:1 — below WCAG
  AA (4.5:1) for body text. It is used for nearly all secondary text. Lighten to
  ~`#8FA0BC`. Check every theme against AA as part of theme validation ([07](07-theming.md)).
- Keyboard: the whole player must be operable without touch — space = next set,
  P = pause.
- `<button>` for buttons. The prototype uses `.btn` on `<div>`s with `onclick`
  throughout (`m('.btn.btn-primary', {onclick})`) — not focusable, not activatable
  by keyboard, invisible to screen readers.

## Performance budget (§19.2: <2 s page load)

- Route-level code splitting (Vite dynamic `import()`); the player and charts load on demand
- Initial JS ≤ 180 KB gzipped (React + Query + router ≈ 60 KB — Vite bundles React
  itself, D10; Chart.js lazy). Vite's manifest + hashed chunks give long-cache immutability
- Dashboard TTI ≤ 1.5 s on 4G — the single aggregate call is what makes this reachable
- Skeletons (the prototype has a `.skeleton` shimmer class, unused) instead of spinners
- `<img loading="lazy">` on workout cards; prototype loads six 600×400 images eagerly
