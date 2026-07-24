# 00 — Architecture & Decisions

## Environment (verified, 2026-07-24)

| Component | Version | Note |
|-----------|---------|------|
| WordPress | 7.0.2 | `wp-includes/version.php` |
| PHP | 8.4.23 | wpBones v2 requires ≥ 8.1 ✓ |
| Composer | 2.10.2 | ✓ |
| Node | 25.9.0 | webpack/Vite fine |
| Table prefix | `wp_` | so tables are `wp_fc_*` |
| Plugin dir | `wp-content/plugins/fitnessclub` | contains only `plan.md`, `user-app/`, `admin-app/`, `plans/` |

`plan.md` §16.3 asks for WP 5.6+ / PHP 7.4+. **Recommendation: require WP 6.2+ and
PHP 8.1+** — wpBones v2 will not install on 7.4, so the spec's floor is unreachable.

## Decisions

### D1 — View framework: React 18 + TypeScript ✅ confirmed 2026-07-24

The prototypes are **Mithril 2.2**. `plan.md` §16.3 mandates **React 18+**. These
are the user's own two artefacts disagreeing, so this was a real fork.

*Chosen: React* (confirmed, 2026-07-24). wpBones ships first-class React tooling — `php bones make:app`
scaffolds `resources/assets/apps/app.tsx` with webpack + TypeScript + Jest, built
to `public/apps/app.js` and enqueued with `->withAdminAppsScript('app')`. The spec
mandates it. The hiring and library pool (react-chartjs-2, TanStack Query,
react-router) is deeper.

*Cost:* ~2 000 lines of Mithril view code to port. It is mechanical —
`m('.card', [...])` → `<div className="card">…</div>` — but it is real work, and
the prototype's component-local state (`this.workouts` set in `oninit`) has to
become hooks. Budgeted in phases 1–2.

*What transfers unchanged either way:* **all the CSS.** Both prototypes are
plain CSS driven entirely by `:root` custom properties, which is exactly the
substrate the `theme.json` system needs ([07-theming.md](07-theming.md)). Also
unchanged: the Chart.js configs, the seed data shapes (they become API fixtures),
and the whole information architecture.

*Alternative considered and rejected — keep Mithril:* would have saved ~8–10
dev-days of porting and shipped a 10 KB runtime instead of ~45 KB, with the
prototypes becoming the actual codebase rather than a reference. Rejected because
it contradicts the spec and forgoes wpBones' `make:app` scaffolding.

Concrete consequences now that this is settled:

- Phase 1 W1.5 and Phase 2 W2.5 carry the porting cost; the estimates in
  [08-roadmap.md](08-roadmap.md) already include it.
- Scaffold each app with `php bones make:app {user,trainer,admin}` rather than
  hand-rolling webpack entry points.
- Stack: React 18, TypeScript, TanStack Query (server state), `react-chartjs-2`
  (charts), `react-hook-form` + `zod` (forms, schemas shared with the server's
  REST `args`), hash router. Budget ≈ 60 KB gzipped before lazy chunks.
- The prototypes' Mithril component objects (`{oninit, view}`) become function
  components with hooks; their module-scope `this` state (a latent bug — see
  [09 §2](09-gap-register.md#user-app--structural)) becomes per-screen queries.
- All prototype CSS still transfers unchanged; only the view layer is rewritten.

### D2 — Naming

Three names are in play: directory `fitnessclub`, spec `WorkoutNow` (`wn_` prefix),
prototypes `FitForge`. Settled as:

| Thing | Value |
|-------|-------|
| Plugin slug / text domain | `fitnessclub` |
| Main file | `fitnessclub.php` |
| PHP namespace | `FitnessClub\` (PSR-4 → `plugin/`) |
| DB table prefix | `{$wpdb->prefix}fc_` → `wp_fc_users` etc. |
| REST namespace | `fitnessclub/v1` |
| CSS variable prefix | `--fc-` |
| Option prefix | `fitnessclub_` |
| Roles | `fc_user`, `fc_trainer` |
| Display brand | "FitForge", editable in Settings → General |

A `wn_` → `fc_` mapping table is in [01-database.md](01-database.md) so the spec
stays cross-referenceable.

### D3 — Routing: wpBones `Route::` over WP REST

wpBones' `RestProvider` scans `/api/**` and registers each file's routes under a
namespace derived from the folder path. So `api/fitnessclub/v1/routes.php`
containing `Route::get('/user/profile', 'FitnessClub\API\V1\UserController@profile')`
yields `GET /wp-json/fitnessclub/v1/user/profile`.

This is strictly better than a bespoke router (`plan.md` §6 implies `/api/v1/...`):
we inherit `permission_callback`, per-arg `validate_callback`/`sanitize_callback`
schemas, cookie-nonce auth, `WP_REST_Response`, and standard error envelopes.

Enable in `config/api.php`:

```php
'custom' => ['path' => '/api', 'enabled' => true],
'auth'   => ['basic' => false],   // basic auth OFF — see D4
'wp'     => ['require_authentication' => false],
```

Turn wpBones' bundled HTTP Basic handler **off**: it authenticates on every REST
request from `PHP_AUTH_USER`, which we do not want as a credential path.

### D4 — Authentication: cookie-first, JWT for external only

| Client | Mechanism |
|--------|-----------|
| User app (shortcode, front-end) | WP cookie + `X-WP-Nonce` |
| Trainer app (shortcode, front-end) | WP cookie + `X-WP-Nonce` |
| Admin app (wp-admin page) | WP cookie + `X-WP-Nonce` |
| Future mobile app / 3rd party | JWT bearer, `POST /auth/token` |

`plan.md` §14 specifies JWT for everything. For a browser that already holds a
valid WP session that means storing a second credential in JS-reachable storage —
strictly worse. JWT is built in Phase 4 for the mobile surface the spec
anticipates (§17), gated behind a settings toggle, off by default. Details and
threat notes in [03-backend.md](03-backend.md#authentication).

### D5 — Data access: wpBones `DB::table()`, not Eloquent

wpBones' `Database\Eloquent` bootstraps Illuminate's Capsule **only if
`illuminate/database` is separately installed**. Our queries are joins,
aggregates and date-range rollups that `DB::table()` (a thin `$wpdb` wrapper with
a query builder) handles directly. Skipping Eloquent avoids ~4 MB of vendor code,
a second connection to the same database, and a second set of prefix rules.

One thin `Repositories/` layer per aggregate keeps SQL out of controllers.
Revisit only if relationship-heavy code appears in the trainer app.

### D6 — Payments: adapter seam, Stripe first

```php
interface PaymentGateway {
    public function createSubscription(SubscriptionRequest $r): GatewayResult;
    public function cancelSubscription(string $externalId): GatewayResult;
    public function handleWebhook(WP_REST_Request $r): WebhookEvent;
}
```

Ship `StripeGateway` + `ManualGateway` (admin records payments by hand — needed
regardless, since the admin prototype's Billing table is manual CRUD). PayPal
becomes a later adapter, not a rewrite. **No card data ever touches our server**:
Stripe Checkout / Payment Element only, so PCI scope stays SAQ-A.

**Standard Stripe, not Stripe Connect** (Q2, confirmed 2026-07-24): the platform
collects all revenue and trainers are paid outside the system. No connected
accounts, no onboarding/KYC flow, no transfers, no marketplace tax liability.
Trainer contribution is *attributed* via `fc_subscriptions.trainer_id` and
reported at `/admin/reports/trainers` — never disbursed.

### D7 — Theme storage

`plan.md` contradicts itself: §4.1 puts `wn_themes/` *inside* the plugin, §5.2's
loader reads `WP_CONTENT_DIR . '/wn_themes/'`. Both are wrong for us — in-plugin
themes are destroyed by plugin updates, and `wp-content/` root is frequently not
writable.

Settled: bundled read-only themes at `resources/themes/{slug}/theme.json`;
user-created/uploaded themes at `wp-content/uploads/fitnessclub-themes/{slug}/`
(uploads dir is writable by definition and survives updates). Active theme slug in
option `fitnessclub_active_theme`. Loader checks uploads first, falls back to
bundled, then to `default`. See [07-theming.md](07-theming.md).

### D8 — Money handling

`DECIMAL(10,2)` at rest (as spec'd), converted to integer minor units only at the
gateway boundary. Add a `currency CHAR(3)` column to every money-bearing table —
the spec has no currency field anywhere, which silently assumes USD forever.
Never do arithmetic on money in floats; sum in SQL with `DECIMAL` or in PHP with
integer cents.

## Directory layout

Following wpBones v2 (verified against `wpbones/WPKirk-Boilerplate`):

```
fitnessclub/
├── fitnessclub.php              # plugin header + autoload require
├── bones                        # CLI, copied by composer post-autoload-dump
├── composer.json                # PSR-4 "FitnessClub\\": "plugin/"
├── package.json  webpack.config.js  tsconfig.json
├── bootstrap/{autoload,plugin}.php
├── config/
│   ├── plugin.php               # shortcodes, providers, schedules, logging
│   ├── menus.php                # wp-admin menu → admin SPA page
│   ├── api.php                  # custom REST enabled, basic auth off
│   ├── options.php              # settings schema
│   └── fitnessclub.php          # domain constants (limits, enums, defaults)
├── api/fitnessclub/v1/
│   ├── routes.php               # public + user routes
│   ├── admin.php                # /admin/* routes
│   └── trainer.php              # /trainer/* routes
├── plugin/
│   ├── API/V1/                  # RestControllers (thin: validate → service → present)
│   │   ├── Auth/  User/  Workout/  Nutrition/  Health/  Progress/
│   │   ├── Message/  Billing/  Support/  Notification/  Theme/
│   │   ├── Admin/  Trainer/
│   ├── Http/Controllers/        # wp-admin page controllers (render SPA mount)
│   ├── Models/                  # table gateways (thin, DB::table based)
│   ├── Repositories/            # queries + aggregates
│   ├── Services/                # business rules — the real logic lives here
│   │   ├── WorkoutSessionService.php   # the state machine
│   │   ├── SubscriptionService.php  EntitlementService.php
│   │   ├── MessageQuotaService.php  ProgressService.php
│   │   ├── NotificationService.php  ThemeService.php
│   │   └── Payments/{PaymentGateway,StripeGateway,ManualGateway}.php
│   ├── Support/                 # Guard, RateLimiter, Presenters, Validators
│   ├── Providers/               # RoleProvider, ShortcodeProvider, AssetProvider
│   ├── Console/Commands/        # bones commands (seed, backfill, cleanup)
│   └── activation.php  deactivation.php
├── database/migrations/         # auto-run on activation
├── database/seeders/            # demo data (mirrors prototype seed exactly)
├── resources/
│   ├── assets/apps/{user,trainer,admin}/   # the three SPAs
│   ├── assets/css/                          # shared design system
│   ├── views/                               # Blade mount points
│   └── themes/default/theme.json
├── public/                      # webpack output — committed, per wpBones
├── languages/
└── plans/                       # this folder
```

### Scaffolding commands

```bash
cd wp-content/plugins
composer create-project wpbones/wpkirk fitnessclub-tmp   # then merge into fitnessclub/
cd fitnessclub
php bones rename                     # → name "FitnessClub", namespace FitnessClub
php bones make:api V1/User/UserController
php bones make:controller Dashboard/DashboardController
php bones migrate:create create_fc_users_table
php bones make:provider RoleProvider
npm install && npm run dev           # watch build
```

Available `bones` commands (read from the CLI source): `install`, `update`,
`rename`, `require`, `version`, `deploy`, `optimize`, `tinker`, `plugin`,
`migrate:create`, `migrate:to-v2`, and `make:{api,app,ajax,console,controller,
cpt,ctt,eloquent-model,model,provider,schedule,shortcode,widget}`.

**Note:** there is no `bones migrate` run command. wpBones executes every file in
`database/migrations/*.php` on `register_activation_hook`. Consequence: migrations
must be **idempotent** (`CREATE TABLE IF NOT EXISTS` / `dbDelta`) and versioned by
an option (`fitnessclub_db_version`) so upgrades on an already-active plugin work.
See [01-database.md](01-database.md#migration-mechanics).

## Application surfaces

| App | Mount | Route | Audience |
|-----|-------|-------|----------|
| User | shortcode `[fitnessclub_app]` on a front-end page | hash router | `fc_user` |
| Trainer | shortcode `[fitnessclub_trainer]` | hash router | `fc_trainer` |
| Admin | wp-admin page via `config/menus.php` | `?page=fitnessclub&view=` | `administrator` |

Each mount renders `<div id="fc-app" data-boot="…">` where `data-boot` (or a
`wp_localize_script` object) carries: REST root, nonce, current user + role,
resolved theme tokens, feature flags from the plan's entitlements, and locale.
The SPA never guesses its own configuration.

Unauthenticated visitors hitting the shortcode get a login/register panel, not a
blank app — the prototypes have no auth screens at all (gap register §3.1).

## Non-negotiables

- **No custom post types.** Spec §1.2. All domain data in `fc_*` tables.
- **Every write endpoint** checks capability *and* resource ownership. A
  `fc_trainer` may read client X only if an `active` row exists in
  `fc_user_trainers`. Capability alone is not authorisation.
- **Shared read, private write** for trainers. A client may have several trainers
  (Q3): all of them may read that client's progress; only the assigning trainer
  may edit their own plans, assignments and notes. Two distinct guards, never
  interchanged — see [03-backend.md](03-backend.md#capability-is-not-authorisation).
- **Every list endpoint** paginates. Default 20, max 100, `X-WP-Total` header.
- **Every timestamp** stored UTC (`DATETIME`), rendered in the user's timezone
  client-side. The prototypes' `"2 hours ago"` strings are presentation only.
- **All output** through a Presenter — never return raw table rows; the API
  contract must not be a mirror of the schema.
