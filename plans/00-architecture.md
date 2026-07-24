# 00 — Architecture & Decisions

## Environment (verified, 2026-07-24)

| Component | Version | Note |
|-----------|---------|------|
| WordPress | 7.0.2 | `wp-includes/version.php` |
| PHP | 8.4.23 | wpBones v2 requires ≥ 8.1 ✓ |
| Composer | 2.10.2 | ✓ |
| Node | 25.9.0 | webpack/Vite fine |
| Table prefix | `wp_` | so tables are `wp_fc_*` |
| Plugin dir | `wp-content/plugins/fitnessclub` | fresh `WPKirk-Boilerplate` + `plan.md`, `user-app/`, `admin-app/`, `plans/` |

`plan.md` §16.3 asks for WP 5.6+ / PHP 7.4+. **Recommendation: require WP 6.2+ and
PHP 8.1+** — wpBones v2 will not install on 7.4, so the spec's floor is unreachable.

## The plugin is two sections

Confirmed with the user, 2026-07-24. Everything below serves this shape:

```
┌─────────────────────────────┐        ┌──────────────────────────────────┐
│  BACKEND  (wpBones, PHP)     │  REST  │  FRONT-END  (Vite + React)       │
│                             │◄──────►│                                  │
│  • REST API /wp-json/…/v1/* │  JSON  │  three separate React SPAs:      │
│  • migrations, models       │        │    • user SPA                    │
│  • services (business logic)│        │    • trainer SPA                 │
│  • providers, options, cron │        │    • admin SPA                   │
│  • serves the role's SPA    │        │  each a pure REST client         │
└─────────────────────────────┘        └──────────────────────────────────┘
        the backend serves the SPA that matches the logged-in user's role
```

1. **Backend — wpBones, "the wpBones way."** All the framework idioms in
   [wpBones-native conventions](#wpbones-native-conventions): REST routes,
   migrations, the `DB` layer, options model, service providers, console commands.
   The PHP side owns data, business rules and authorisation, and exposes **only**
   a versioned REST API. It renders no application UI — it serves a thin HTML
   shell that boots the appropriate SPA.
2. **Front-end — three Vite-compiled React SPAs**, one each for users, trainers
   and admins, decoupled from wpBones' webpack/`@wordpress/scripts` asset pipeline.
   Vite bundles React; each SPA is a pure REST client. On login the backend
   resolves the user's role and serves **that role's SPA**
   ([D9](#d9--front-end-routing-configurable-app-url),
   [D10](#d10--front-end-three-vite-compiled-react-spas)).

The one part of wpBones we do **not** use is its asset pipeline — Vite replaces it
([D10](#d10--front-end-three-vite-compiled-react-spas)). Everything else is
wpBones-native.

## Decisions

### D1 — View framework: React 18 + TypeScript ✅ confirmed 2026-07-24

The prototypes are **Mithril 2.2**. `plan.md` §16.3 mandates **React 18+**. These
are the user's own two artefacts disagreeing, so this was a real fork.

*Chosen: React* (confirmed, 2026-07-24), **built with Vite** (see
[D10](#d10--front-end-three-vite-compiled-react-spas)) rather than wpBones' bundled
webpack pipeline. The spec mandates React; Vite gives fast HMR, a modern build,
and self-contained bundles independent of WordPress's script runtime. The library
pool (react-chartjs-2, TanStack Query, react-router) is deep.

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
dev-days of porting and shipped a ~10 KB runtime, with the prototypes becoming the
actual codebase rather than a reference. Rejected because it contradicts the spec's
React mandate and gives up React's ecosystem (react-chartjs-2, TanStack Query).

Concrete consequences now that this is settled:

- Phase 1 W1.5 and Phase 2 W2.5 carry the porting cost; the estimates in
  [08-roadmap.md](08-roadmap.md) already include it.
- **Three Vite-built SPAs** — one each for users, trainers, admins — sharing a
  common `src/shared/` (API client, design system, chart/modal components), not
  wpBones `make:app` webpack entries ([D10](#d10--front-end-three-vite-compiled-react-spas)).
- Stack: React 18 + TypeScript (Vite bundles React itself), TanStack Query (server
  state), `react-chartjs-2` (charts), `react-hook-form` + `zod` (forms, schemas
  shared with the server's REST `args`), `react-router` (client routing within
  each SPA).
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
request from `PHP_AUTH_USER`, which we do not want as a credential path. **The
boilerplate ships `'basic' => true`** in `config/api.php` — flipping it to `false`
is one of the two mechanical deltas from a fresh boilerplate (the other is the
rename; see [wpBones-native conventions](#wpbones-native-conventions)).

### D4 — Authentication: cookie-first, JWT for external only

| Client | Mechanism |
|--------|-----------|
| User / Trainer / Admin SPA (front-end, all at `/{base}`, role-selected — [D9](#d9--front-end-routing-configurable-app-url)) | WP cookie + `X-WP-Nonce` |
| Future mobile app / 3rd party | JWT bearer, `POST /auth/token` |

`plan.md` §14 specifies JWT for everything. For a browser that already holds a
valid WP session that means storing a second credential in JS-reachable storage —
strictly worse. JWT is built in Phase 4 for the mobile surface the spec
anticipates (§17), gated behind a settings toggle, off by default. Details and
threat notes in [03-backend.md](03-backend.md#authentication).

### D5 — Data access: wpBones `DB::table()` for the simple path, raw `$wpdb` for joins

wpBones' `Database\Eloquent` bootstraps Illuminate's Capsule **only if
`illuminate/database` is separately installed**. We skip it — that avoids ~4 MB of
vendor code, a second connection to the same database, and a second set of prefix
rules.

Instead, per the wpBones docs, two tools, used deliberately:

- **`DB::table('fc_x')->where(...)->get()/first()/find()/insert()/update()/delete()`**
  — the fluent builder, for straightforward single-table reads and writes. The
  prefix is applied automatically; it returns `stdClass`/`Collection`.
- **Raw `$wpdb->prepare()`** — for **joins, `GROUP BY` aggregates and date-range
  rollups**. The docs state plainly that the bundled builder is *"a light version…
  it does not include the advanced features of the original"* — there is **no
  `join()` method**. Most of our authorisation checks (the `Guard`) and analytics
  (`ProgressService`) are joins, so they use `$wpdb` with `{$wpdb->prefix}`-style
  table names and `%d`/`%s` placeholders. This is not a shortcut; it is the
  correct tool given the builder's scope. The no-raw-SQL lint gate ([03](03-backend.md))
  is written to allow exactly this pattern (placeholdered) and reject
  variable-interpolated values.

One thin `Repositories/` layer per aggregate keeps SQL — of either kind — out of
controllers.

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

### D9 — Front-end routing: configurable app URL

✅ Confirmed 2026-07-24. The three SPAs render at a URL the admin chooses — `example.com/fitness`
by default, where `fitness` is editable in Settings. **wpBones has no front-end
routing to build this on.** Confirmed against the docs (`core-concepts/pages-routing`):
`$plugin->getPageUrl()` returns `admin.php?page=…`, the `pages/` folder registers
wp-admin pages via `load-toplevel_page_*`, and the framework contains no
`add_rewrite_rule`. Every "page/route" concept in wpBones is wp-admin.

So this is native WordPress, wrapped in a wpBones service provider:

| Piece | Detail |
|-------|--------|
| Slug | option-model key `routing.app_base` (default `fitness`), sanitised with `sanitize_title()` |
| Rule | `RewriteServiceProvider` (in `config/plugin.php` → `providers`) calls `add_rewrite_rule('^{base}(/(.*))?/?$', 'index.php?fc_app=1&fc_app_path=$matches[2]', 'top')` on `init`, and registers the `fc_app`/`fc_app_path` query vars |
| Render | on `template_redirect`, when `fc_app` is set: **resolve the logged-in user's role → pick that role's SPA** (`user`/`trainer`/`admin`), print a **standalone full-page** Blade shell with the boot payload + that SPA's Vite manifest tags, then `exit`. Not logged in → the user SPA's login screen ([D10](#d10--front-end-three-vite-compiled-react-spas)) |
| Flush | `flush_rewrite_rules()` on activation and on `update_option` for the slug — never on every request |
| Client | each SPA's react-router `basename` is `/{base}`; the SPA owns its own screen routes below that |

**Product decisions (sensible defaults, revisit if needed):**

1. **One configurable base URL**, one rewrite rule. **Role, not path, selects the
   SPA**: everyone visits `/{base}`, and the backend serves whichever of the three
   apps matches their role — "the appropriate one is shown when they log in." (If
   an account somehow holds several roles, precedence admin > trainer > user, with
   a switcher later if needed — logged as [Q17c](09-gap-register.md#still-open).)
2. **Standalone full-page render** — the plugin owns the whole HTML document, not
   `get_header()/get_footer()`. Each app is full-screen, and this sidesteps the
   host theme's CSS ([04](04-user-app.md) flags this conflict). `noindex`, behind auth.
3. **wp-admin keeps only a thin launcher** — a `config/menus.php` entry linking to
   `/{base}` and holding the break-glass routing option (so a mistyped slug can't
   lock an admin out). The admin *UI* is the admin SPA on the front-end
   ([05](05-admin-app.md)), not a wp-admin React mount.

The shortcode (`[fitnessclub_app]`) is kept as an **optional secondary embed** for
admins who want an app inside an existing page, but the configured URL is the
primary surface.

Open sub-question logged as [Q17c](09-gap-register.md#still-open).

### D10 — Front-end: three Vite-compiled React SPAs

✅ Confirmed 2026-07-24. **Three separate React SPAs — one for users, one for trainers, one for admins.**
On login the backend resolves the user's role and serves that role's SPA. This is
the deliberate exception to "the wpBones way": wpBones for the backend, **Vite for
the front-end**.

**Why three SPAs (not one role-routed app):**

- Matches the product — the three audiences share almost no screens (a user tracks
  workouts; an admin runs CRUD tables; a trainer coaches clients). Three prototypes
  already exist as separate apps.
- **Hard isolation** — a user's browser never receives a byte of admin code. No
  reliance on client-side route guards to keep the admin bundle out of a member's
  hands; the backend simply never serves it to them.
- Independent release cadence and smaller bundles per app.

**Why Vite over wpBones' webpack/`@wordpress/scripts`:**

- Modern build, fast HMR; materially better DX for three real SPAs.
- **Self-contained bundles** — Vite bundles React, so the apps don't depend on
  WordPress's script runtime or handle registration. They are plain REST clients
  WordPress happens to serve.
- No coupling to `@wordpress/components`; the apps keep the prototypes' custom
  design system.

**Shape:**

- `ui/` — one Vite workspace, **three entry points** with shared code, **not**
  `resources/assets/` (built 2026-07-25 on Vite 8 + React 18):
  ```
  ui/
  ├── package.json  vite.config.ts  tsconfig.json
  ├── src/shared/         # boot reader, API client, auth, design system, charts, modals
  ├── src/user/    main.tsx
  ├── src/trainer/ main.tsx
  └── src/admin/   main.tsx
  ```
  (Shared `src/shared/` keeps the API client, design tokens and common components
  in one place. No per-entry `index.html` — WordPress serves the shell (D9), so the
  entries are `main.tsx` files given to `rollupOptions.input`.)
- Build → `public/ui/` with **one** Vite manifest (`public/ui/.vite/manifest.json`)
  and `public/ui/assets/{role}-{hash}.js`. Each entry is its own chunk; code both
  entries import (React, `src/shared/`) is a **single shared chunk**, so a member
  never downloads admin-specific code *and* React ships once (~46 KB gzip, not ×3).
  The PHP shell reads the manifest, looks up `src/{role}/main.tsx`, and emits the
  hashed `<script type="module">` for that entry + a modulepreload for its imported
  shared chunk. *(Refined from the earlier "three separate manifests" sketch —
  one manifest with per-entry chunking gives the same isolation without triplicating
  React.)*
- **Dev**: Vite dev server (HMR) at `:5173` (verified); the shell detects dev mode
  and loads `@vite/client` + `src/{role}/main.tsx` from it. **Prod**: hashed assets
  from `public/ui/`.
- Role → SPA resolution is server-side in the render step
  ([D9](#d9--front-end-routing-configurable-app-url)); auth/caps are enforced by
  the REST API, never by the client.

**Consequence for the admin app:** it is **not** a wp-admin page — it is the admin
SPA on the front-end ([05](05-admin-app.md) updated accordingly). A thin wp-admin
menu entry remains only as a launcher/redirect to the admin URL and as a
break-glass place for the routing option, so a misconfigured slug can't lock an
admin out.

## wpBones-native conventions

The plugin is built **on the `WPKirk-Boilerplate` scaffold** and follows the
framework's documented idioms rather than hand-rolling equivalents. Grounded in
`wpbones.com/docs`, read 2026-07-24:

| Concern | The wpBones way we use | Notes |
|---------|------------------------|-------|
| **Naming** | `namespace` file → `FitnessClub,FitnessClub`; `composer dump-autoload` runs `bones rename --update` (a `post-autoload-dump` script) | Native rename renames the main file, namespace, slug and text domain in one pass. It str-replaces **all** files, so move `plans/`, `user-app/`, `admin-app/` aside during the first rename, then restore |
| **Options** | JSON model in `config/options.php`; read via `$plugin->options->get('routing.app_base','fitness')` | Stored as one row keyed by the plugin slug. **Not** raw `get_option()` per key |
| **Config** | `config/*.php` return arrays; read via `$plugin->config('custom.x')` | Domain constants live in `config/fitnessclub.php` |
| **Migrations** | `php bones migrate:create`, `$this->create('fc_x', "(...)")`; run on activation | Our base extends `WPBones\…\Migration` to add guarded FKs + InnoDB ([01](01-database.md)) |
| **Seeders** | `Seeder` (single-table: `$tablename`, `run()`, `runOnce`), in `database/seeders/*.php`, run on activation | Essential data (platform plans) → a `runOnce` seeder here. Demo/volume data → a **bones console command**, never in `database/seeders/` (which runs every activation) |
| **Queries** | `DB::table()` builder (simple) + raw `$wpdb` (joins) | [D5](#d5--data-access-wpbones-dbtable-for-the-simple-path-raw-wpdb-for-joins) |
| **REST** | `api/fitnessclub/v1/*.php`, `Route::get/post/request` → `/wp-json/fitnessclub/v1/*` | [D3](#d3--routing-wpbones-route-over-wp-rest) |
| **Admin pages** | `config/menus.php` → controller resource/verb methods | The admin SPA mounts here |
| **Console** | `php bones make:console`, registered in `plugin/Console/Kernel.php` | The seed/PR-rebuild/cleanup commands live here — not `bin/` scripts |
| **Views** | Blade (`eftec/bladeone`) via `$plugin->view('name')->with(...)` | The admin mount + the front-end app shell are Blade views |
| **Assets** | **Vite**, not wpBones' webpack — `ui/` Vite workspace → `public/ui/{user,trainer,admin}/` + manifest | The one part of wpBones we replace ([D10](#d10--front-end-three-vite-compiled-react-spas)) |
| **Providers** | classes in `config/plugin.php` → `providers`, registered on `init` | `RewriteServiceProvider`, `RoleProvider`, `UpgradeProvider`, `ShortcodeProvider`, schedule provider |
| **Front-end URL** | **not a framework feature** — native WP rewrite in a provider | [D9](#d9--front-end-routing-configurable-app-url) |

Two mechanical deltas from a fresh boilerplate, both trivial: run the rename, and
flip `config/api.php` `'basic'` to `false`.

## Directory layout

Following wpBones v2 (built on `wpbones/WPKirk-Boilerplate`):

Boilerplate files kept as-is are unmarked; **★** marks what we add.

```
fitnessclub/
├── fitnessclub.php              # plugin header + autoload require (renamed from wp-kirk.php)
├── bones                        # CLI, copied by composer post-autoload-dump
├── namespace                    # "FitnessClub,FitnessClub" — drives bones rename
├── composer.json                # PSR-4 "FitnessClub\\": "plugin/"
├── package.json  webpack.config.js  jest.config.js  deploy.php   # boilerplate; webpack unused for UI
├── bootstrap/{autoload,plugin}.php
├── config/
│   ├── plugin.php               # providers, shortcodes, schedules, logging
│   ├── menus.php                # wp-admin menu → admin SPA page
│   ├── routes.php               # (wpBones admin page-routes; unused by us)
│   ├── custom.php               # ★ misc custom config
│   ├── api.php                  # custom REST enabled; basic auth flipped OFF
│   ├── options.php              # ★ options model incl. routing.app_base
│   └── fitnessclub.php          # ★ domain constants (limits, enums, defaults)
├── api/fitnessclub/v1/          # ★ scanned by RestProvider
│   ├── routes.php               # public + user routes
│   ├── admin.php                # /admin/* routes
│   └── trainer.php              # /trainer/* routes
├── plugin/
│   ├── Console/
│   │   ├── Kernel.php           # registers our bones commands
│   │   └── Commands/            # ★ SeedCommand, RebuildPrCommand, cleanup…
│   ├── Http/Controllers/        # thin wp-admin launcher + break-glass routing form
│   ├── API/V1/                  # ★ RestControllers (thin: validate → service → present)
│   │   ├── Auth/ User/ Workout/ Nutrition/ Health/ Progress/
│   │   ├── Message/ Billing/ Support/ Notification/ Theme/ Admin/ Trainer/
│   ├── Models/                  # ★ table models (extend WPBones Model)
│   ├── Repositories/            # ★ queries + aggregates (DB:: builder / raw $wpdb)
│   ├── Services/                # ★ business rules — the real logic lives here
│   │   ├── WorkoutSessionService.php   # the state machine
│   │   ├── SubscriptionService.php  EntitlementService.php
│   │   ├── MessageQuotaService.php  ProgressService.php
│   │   ├── NotificationService.php  ThemeService.php
│   │   └── Payments/{PaymentGateway,StripeGateway,ManualGateway}.php
│   ├── Support/                 # ★ Guard, RateLimiter, Clock, Presenters
│   ├── Providers/               # ★ RewriteServiceProvider, RoleProvider,
│   │                            #    UpgradeProvider, ShortcodeProvider, ScheduleProvider
│   ├── Database/                # ★ Migration base, Upgrade\Manager, Seeders\…
│   └── activation.php  deactivation.php  updated.php
├── database/
│   ├── migrations/              # ★ auto-run on activation (29 tables)
│   └── seeders/                 # ★ essential data only (platform plans) — runs every activation
├── ui/                          # ★ Vite workspace — the three React SPAs (D10)
│   ├── package.json  vite.config.ts  tsconfig.json
│   ├── src/shared/              # API client, auth, design system, charts, modals
│   ├── src/user/    main.tsx index.html
│   ├── src/trainer/ main.tsx index.html
│   └── src/admin/   main.tsx index.html
├── resources/
│   ├── views/
│   │   └── app/shell.blade.php  # ★ front-end standalone shell (D9 render, reads Vite manifest)
│   └── themes/default/theme.json # ★
├── public/
│   └── ui/{user,trainer,admin}/ # ★ Vite build output (hashed assets + manifest) — committed
├── languages/
├── plans/                       # this folder (planning artifact, not shipped)
└── tests/                       # ★ PHPUnit (integration against real WP + MySQL)
```

### Scaffolding commands

The boilerplate is already in place (cloned from `wpbones/WPKirk-Boilerplate`;
`composer create-project wpbones/wpkirk` does **not** work — it is a GitHub
template, not a Packagist package). Backend scaffold:

```bash
# 1. Rename WPKirk → FitnessClub the wpBones-native way.
#    bones rename str-replaces ALL files, so move planning artifacts aside first.
mv plans user-app admin-app ../_fc_stash/
printf 'FitnessClub,FitnessClub' > namespace
composer install            # post-autoload-dump runs `bones rename --update`
mv ../_fc_stash/* .          # restore plans/, prototypes

# 2. Flip basic auth off in config/api.php (D3), then scaffold backend pieces:
php bones make:controller Admin/AdminAppController
php bones make:provider RewriteServiceProvider
php bones migrate:create CreateFcUsersTable
php bones make:console SeedCommand

# 3. Front-end (separate Vite workspace, D10):
cd ui && npm install && npm run dev     # Vite dev server + HMR
```

Available `bones` commands (from the CLI source): `install`, `update`, `rename`,
`require`, `version`, `deploy`, `optimize`, `tinker`, `plugin`, `migrate:create`,
`migrate:to-v2`, and `make:{api,app,ajax,console,controller,cpt,ctt,eloquent-model,
model,provider,schedule,shortcode,widget}`. We use `make:controller/provider/console/
model` and `migrate:create`; **not** `make:app` (that scaffolds a webpack app — the
UI is Vite, D10).

**Note:** there is no `bones migrate` run command. wpBones executes every file in
`database/migrations/*.php` on `register_activation_hook`. Consequence: migrations
must be **idempotent** (`CREATE TABLE IF NOT EXISTS` / `dbDelta`) and versioned by
an option (`fitnessclub_db_version`) so upgrades on an already-active plugin work.
See [01-database.md](01-database.md#migration-mechanics).

## Application surfaces

Three SPAs, one configurable front-end URL, role-selected server-side ([D9](#d9--front-end-routing-configurable-app-url)/[D10](#d10--front-end-three-vite-compiled-react-spas)):

| SPA | Served at | Selected when | Bundle |
|-----|-----------|---------------|--------|
| User | `example.com/{base}` (default `/fitness`) | logged-in `fc_user` | `public/ui/user/` |
| Trainer | same URL | logged-in `fc_trainer` | `public/ui/trainer/` |
| Admin | same URL (+ thin wp-admin launcher) | logged-in `administrator` | `public/ui/admin/` |

The rewrite render resolves the role and emits a standalone Blade shell carrying
`<div id="fc-app" data-boot="…">` + that SPA's Vite manifest tags. `data-boot`
carries: REST root, nonce, current user + role, resolved theme tokens, entitlement
flags, the app base path (router `basename`), and locale. The SPA never guesses
its own configuration.

Unauthenticated visitors get the **user SPA's** login/register screen, not a blank
app — the prototypes have no auth screens at all (gap register §3.1). On success
the backend re-resolves the role and serves the matching SPA.

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
