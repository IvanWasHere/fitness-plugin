# FitnessClub

A coaching platform for WordPress: members track workouts, meals and health;
trainers coach them; administrators run the site. Built on
[WP Bones](https://wpbones.com), with a Vite/React front end.

> Looking for **user-facing** documentation? That is [`readme.txt`](readme.txt),
> in WordPress plugin format. This file is for people working on the code.

## The shape of it

The plugin is **two things, deliberately separable**:

```
┌─ WordPress backend ──────────────┐     ┌─ Front end ─────────────────┐
│  29 custom tables                │     │  Vite workspace in ui/      │
│  118 REST endpoints              │◄────┤  3 React SPAs:              │
│  plugin-owned accounts + guards  │ API │    user / trainer / admin   │
│  services, no logic in views     │     │  served at /{app_base}      │
└──────────────────────────────────┘     └─────────────────────────────┘
```

Every architectural decision is recorded in [`plans/00-architecture.md`](plans/00-architecture.md)
as a numbered decision (D1–D11) with its rationale, its cost, and what was
rejected. **Read that before changing anything structural** — several decisions
supersede earlier ones, and the reasoning matters more than the outcome.

The four that shape everything else:

| | |
|---|---|
| **D4a** | The plugin owns its accounts. A WordPress login is *not* a FitnessClub login, and a WP administrator with no `fc_accounts` row is anonymous to this API. |
| **D5** | `DB::table()` for simple queries, raw `$wpdb` for joins. No ORM. PHPCS blocks unprepared SQL. |
| **D9/D10** | One configurable front-end URL; the server resolves the role and serves that role's SPA. |
| **D11** | A theme can replace any of the three apps. This makes the REST API and boot payload a **published contract**. |

## Layout

```
fitnessclub/
├── api/fitnessclub/v1/routes.php   every endpoint, with its args schema
├── plugin/
│   ├── Auth/                       accounts, sessions, CSRF, capabilities, Guard
│   ├── Cli/                        WP-CLI: seed, account, openapi
│   ├── Database/                   migrations, seeders, upgrade dispatcher
│   ├── Http/Controllers/           thin — they resolve, delegate, present
│   ├── Providers/                  rewrite, auth, api, schedule, cli
│   ├── Services/                   the domain. Almost all the logic lives here
│   └── Support/                    Guard helpers, ViteAssets, ThemeExtension, OpenApi
├── ui/                             Vite workspace, three entries + src/shared
├── database/migrations/            29 migrations
├── docs/                           generated + written documentation
├── plans/                          the implementation plan and decision record
└── tests/Integration/              PHPUnit, against a real WordPress + MySQL
```

**Controllers stay thin.** They resolve the caller's id (never accept it as a
parameter), check the gate, call a service, and turn a `DomainException` into the
error envelope. Business rules belong in `plugin/Services/`.

## Getting set up

Requires PHP 8.1+, WordPress 6.2+, MySQL 5.7+/8, Node 20+.

```sh
composer install          # also copies the `bones` CLI
cd ui && npm install && npm run build && cd ..
wp plugin activate fitnessclub
```

Activation creates the tables, seeds the platform plans, and generates one
administrator account. **Its password is shown once**, on the
wp-admin → FitnessClub screen.

```sh
wp fitnessclub seed --demo    # optional: the demo dataset
```

### Front-end development

```sh
cd ui && npm run dev          # Vite dev server with HMR on :5173
```

Then add to `wp-config.php`:

```php
define('FITNESSCLUB_VITE_DEV', true);
```

The shell detects it and loads modules from the dev server instead of the built
manifest. Note this **bypasses any selected front-end theme** — it is a switch
for developing the plugin's own apps.

## Checks

```sh
composer check                # phpcs + phpunit
composer phpcs                # PSR-12 + WordPress security sniffs + no-raw-SQL gate
./vendor/bin/phpunit          # integration tests against the real WP runtime

cd ui
npm run typecheck && npm run lint && npm run format:check && npm run build
```

Tests are **integration tests against a real WordPress and MySQL**, not mocks.
The Guard, the rewrite render and the REST endpoints are all about behaviour
against the real runtime, so mocking would test nothing. `tests/bootstrap.php`
locates `wp-load.php` above the plugin.

Anything that touches the database cleans up after itself — a test run leaves the
development database as it found it.

## Documentation

| | |
|---|---|
| [`docs/openapi.json`](docs/openapi.json) | The REST API. **Generated** — see below |
| [`docs/admin-guide.md`](docs/admin-guide.md) | Running a site |
| [`docs/trainer-guide.md`](docs/trainer-guide.md) | Coaching in the app |
| [`docs/theme-development.md`](docs/theme-development.md) | Building a front-end theme |
| [`plans/`](plans/README.md) | Architecture, schema, API contract, roadmap, gap register |
| [`readme.txt`](readme.txt) | User-facing, WordPress plugin format |

### The API document is generated, and the build enforces it

```sh
wp fitnessclub openapi          # regenerate docs/openapi.json
wp fitnessclub openapi --check  # fail if it is out of date (CI)
```

It is built from `rest_get_server()->get_routes()` — the registry WordPress
actually dispatches from — so every type, enum, bound and required flag comes
from the schema the request validator enforces. It cannot describe a parameter
the API does not have.

What *cannot* be derived is prose, which lives in `plugin/Support/ApiDocs.php`.
That is hand-maintained, so three things stop it rotting: undocumented routes are
reported, documented-but-deleted routes are reported, and `OpenApiTest` asserts
both sets are empty and that the committed file matches the code.

**Adding a route therefore fails the suite until you describe it.** That is
deliberate. It is the only mechanism that has ever kept API documentation current.

## Conventions worth knowing before your first change

- **Never accept a user id as a parameter.** Resolve it from the session. Every
  controller does this and it is the reason no endpoint can be made to act on
  someone else's data by changing a number in a URL.
- **Scope in the query, not in a check.** `WHERE user_id = %d` beats a separate
  `if` that a later refactor can drop. A foreign id should be a 404.
- **Errors carry stable `fc_*` codes.** Clients branch on the code; messages are
  translated and may change.
- **Derived values are derived on read or recomputed on write — never assigned.**
  BMI, session duration, day totals and the profile's cached weight are all
  recomputed, because assignment is correct only on the happy path.
- **Announce changes, don't call cleanups.** Writers fire
  `do_action('fitnessclub/user_data_changed', $userId, $reason)` *after* commit;
  caches subscribe. A cache every future write site must remember to clear is a
  cache that goes stale.
- **No hex literals outside the token layer**, and no `#fc-app *` selectors —
  that gives a reset id-level specificity and it beats every class in the file.
- **Strings go through `__()`** with the `fitnessclub` text domain.

## Where the bodies are buried

Hard-won details, each of which cost real time. All are written up at length in
[`plans/08-roadmap.md`](plans/08-roadmap.md) under the package that found them.

- `activation.php` runs **before any table exists** — schema upgrades dispatch
  from `UpgradeProvider` on `init` instead.
- wpBones does **not** bootstrap WordPress for custom console commands, so
  anything needing `$wpdb` is a WP-CLI command, not a `php bones` one.
- `wp-settings.php` uses `$plugin` as a global loop variable and `unset()`s it —
  a script that loads WordPress and keeps its own `$plugin` silently loses it.
- `WP_Theme::get()` caches parsed headers, so custom headers must be read with
  `get_file_data()` or they answer `false` on a warm cache.
- Exact duration assertions need ±1s tolerance when the test spans a real-clock
  leg, or the suite is flaky one run in five.

## Contributing

1. Read the relevant plan document first. If your change contradicts a decision,
   the decision needs amending — that is a normal thing to do, and the process is
   to supersede it explicitly rather than to diverge quietly.
2. `composer check` and the `ui/` gate must be green.
3. Add or update `plugin/Support/ApiDocs.php` for any route change.
4. Add a build note to `plans/08-roadmap.md` describing what you learned, not
   just what you did.

## Licence

GPL-2.0-or-later.
