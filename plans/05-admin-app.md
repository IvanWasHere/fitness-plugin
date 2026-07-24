# 05 — Admin App

Port of `admin-app/index.html` (405 lines, Mithril + Dexie). Mounted on a wp-admin
page registered through `config/menus.php`.

The admin prototype is **structurally better than the user prototype** — one
generic `CrudTable(config)` factory drives all eight resources with a declarative
column spec. That factory is the design to keep; it just needs a server behind it.

## Mounting

```php
// config/menus.php
return [
  'fitnessclub' => [
    'page_title' => 'FitnessClub',
    'menu_title' => 'FitnessClub',
    'capability' => 'manage_options',
    'icon'       => 'fc-menu-icon.png',
    'items' => [
      ['page_title'=>'Dashboard','menu_title'=>'Dashboard','capability'=>'manage_options',
       'route'=>['get'=>'Admin\\AdminAppController@index']],
      ['page_title'=>'Settings','menu_title'=>'Settings','capability'=>'manage_options',
       'route'=>['get'=>'Admin\\SettingsController@index']],
    ],
  ],
];
```

The controller renders a Blade view containing only `<div id="fc-admin-app">` and
enqueues the bundle with `->withAdminAppsScript('admin')`. The SPA owns
navigation below that point (`?page=fitnessclub#/users`), so there is one WP menu
entry rather than nine.

### Style conflict — decide early

The prototype is a **dark, full-bleed panel** that looks nothing like wp-admin.
Rendered inside `#wpcontent`, WordPress's admin CSS and the prototype's CSS will
fight (WP styles `input`, `select`, `table`, `.button` globally).

Three options, pick one before writing CSS:

| Option | Cost | Result |
|--------|------|--------|
| **A. Scope + reset (recommended)** | ~1 d | All rules under `#fc-admin-app`, explicit resets for WP's input/table styles. Keeps the prototype look, stays inside wp-admin chrome |
| B. Adopt wp-admin styling | ~3 d rework | Native feel, uses `@wordpress/components`. Throws away the prototype's design |
| C. Shadow DOM | ~1 d + friction | Perfect isolation; breaks portals, third-party pickers, and WP's media modal |

Option A. Note that the prototype's own dark palette differs from the user app's
(`--bg:#090C10` vs `#0B0E13`) — unify both on the theme token set ([07](07-theming.md)).

## The CrudTable contract

Prototype `CrudTable(config)` (line 282) takes:

```js
{ table, title, singular, sortKey, searchFields[], filterField, filterOptions[],
  columns: [{ key, label, render?, tag? }] }
```

and provides search, single-select filter, client-side pagination (8/page),
Add/Edit modal, delete confirmation, toasts, and a sidebar count badge. Keep the
API; change the substrate:

| Prototype | Ported |
|-----------|--------|
| `db[table].toCollection().sortBy()` — loads **all** rows | `GET /admin/{resource}?page&per_page&q&sort&filters` |
| Client-side filter/search over the full set | Server-side; typeahead debounced 300 ms |
| Client-side pagination | Server pagination, `X-WP-Total` |
| Single `filterField` | Multiple filters (Users needs status **and** plan **and** trainer) |
| No sorting UI | Sortable column headers |
| No bulk actions | Checkbox column + bulk delete/export/status-change |
| Delete = hard delete, no dependency check | Server rejects with 409 and explains (deleting a trainer with active clients) |

Loading all rows is fine for 6 seeded users and fatal at 10 000. This is the single
most important change in the admin port.

Also add: column visibility toggle, per-page selector, URL-synced state (so an
admin can share a filtered view), and optimistic row updates.

## Resources

| Screen | Endpoint | Prototype | Change |
|--------|----------|-----------|--------|
| Dashboard | `/admin/dashboard` | 8 count cards | add revenue/MRR, charts, recent signups, failed payments list |
| Users | `/admin/users` | ✓ | + subscription(s), last active, **trainer list** (a user may have several — Q3), row → detail drawer |
| Trainers | `/admin/trainers` | ✓ | client count derived, rating read-only, + assigned-clients drawer, `hourly_rate` marked display-only |
| **Trainer report** | `/admin/reports/trainers` | ✗ missing | Q2 attribution: per-trainer clients, sessions, adherence, **attributed** subscription revenue. The only place trainer money figures appear |
| **Trainer requests** | `/admin/trainer-requests` | ✗ missing | Q4 oversight: pending/declined requests across the platform, stuck queues, trainers at capacity. Admin can force-assign as an override |
| Workouts | `/admin/workouts` | ✓ w/ nested exercise editor | keep the nested editor — it is the prototype's best screen; add drag-reorder + `rest_seconds` + `metric` |
| Food Database | `/admin/foods` | ✓ | + CSV import/export, barcode, verified flag |
| Meal Logs | `/admin/meals` | ✓ | user name not "User #1"; **full CRUD, audit-logged** (Q10) |
| Health Entries | `/admin/health-entries` | ✓ | user name; **full CRUD, audit-logged** (Q10) |
| Billing | `/admin/payments` | ✓ manual CRUD | becomes read-mostly + refund action; manual entry stays for the `ManualGateway` |
| **Plans** | `/admin/plans` | ✗ missing | platform tiers + trainer plans, feature-flag editor (**including `max_trainers`** — Q14), pricing matrix |
| **Subscriptions** | `/admin/subscriptions` | ✗ missing | status, cancel, extend, change plan |
| **Tickets** | `/admin/tickets` | ✗ missing | queue, assign, reply, internal notes (spec §13) |
| **Themes** | `/admin/themes` | ✗ missing | list/preview/activate/upload/edit ([07](07-theming.md)) |
| **Settings** | `/admin/settings` | ✗ missing | general, email, payments, features, API (spec §13.4) |
| **Admins** | — | ✓ exists | **removed** — becomes a read-only view of WP users with `manage_options` |

Five of the spec's required admin areas do not exist in the prototype and three of
those (Plans, Settings, Themes) are prerequisites for the rest of the product
working at all. Budget accordingly — this is roughly half the admin app's work.

## Meal/Health logs: full CRUD, audit-backed

Confirmed (Q10): administrators get **full create/read/update/delete** on users'
meal logs and health entries, as the prototype has it. I'd initially recommended
view+delete; that was overruled with the trade-off in view, so build it as
specified. What makes it safe without limiting it:

| Control | Detail |
|---|---|
| Capability | `fc_edit_user_health`, granted to `administrator` **by default** |
| Audit | Every create/update/delete writes `fc_activity_log` with `actor_wp_user_id` and **before/after values** in `meta` |
| Provenance | Row stamps `source='admin'` and `last_edited_by_wp_user_id` ([01](01-database.md#health)) |
| Admin UI | Shows last editor + timestamp on staff-edited rows; inline warning that saving alters the user's charts |
| User UI | Staff-edited entries are visibly marked, not passed off as self-reported |
| Retention | Audit rows are exempt from `ActivityPrune`'s 12-month cut |

The columns and the audit hook are the only real work (~0.5 d); the CRUD screens
were already budgeted.

## Fixes carried from the prototype

- `col.tag(val)` returns `'tag-green'` and is rendered as `m('.' + tag)`, producing
  `class="tag-green"` **without the base `.tag` class** — so every status/plan pill
  loses its padding, radius and text-transform (lines 305, 320–321).
- The admin stylesheet defines no utility classes, but the row renderers use
  `.flex`, `.flex-c`, `.gap-10`, `.text-xs`, `.text-accent`, `.mt-16`, `.mb-16`.
  Trainer online/offline labels and avatar rows are unstyled.
- `UserForm` and `WorkoutForm` hardcode the trainer dropdown to four names
  (`Sarah Chen`…`David Kim`). Must load from `/admin/trainers`.
- `WorkoutForm` save does `delete ex.id` then re-adds every exercise — a
  delete-all-and-reinsert that loses ids referenced by historical logs. Server-side
  this must be an upsert-by-id with explicit deletes, or every past workout log
  loses its exercise linkage.
- `DashboardPage` fires 10 unawaited `db.count()` calls each triggering
  `m.redraw()` — 10 renders per mount. One `/admin/dashboard` call.
- No auth at all. Every request needs `manage_options` + nonce.
- No error handling: every promise assumes success.
- `state.pageSize = 8` is hardcoded and global across resources.

## Structure

```
resources/assets/apps/admin/
├── main.tsx  App.tsx
├── api/{client,queries}.ts
├── components/
│   ├── CrudTable/            # Table, Toolbar, Filters, Pagination, BulkActions, ColumnPicker
│   ├── Modal, ConfirmDialog, Toast, StatCard, EmptyState, ErrorBoundary
├── resources/                # one config module per resource: columns, filters, form schema
│   ├── users.tsx  trainers.tsx  workouts.tsx  foods.tsx  meals.tsx
│   ├── healthEntries.tsx  payments.tsx  plans.tsx  subscriptions.tsx  tickets.tsx
├── screens/{Dashboard,Themes,Settings,TicketDetail}/
└── styles/
```

The `resources/*` modules stay declarative exactly as the prototype's configs are —
that is the pattern worth preserving. A new admin resource should be one file.

## Forms

Prototype forms mutate `state.modal.data` directly on every keystroke with no
validation beyond `if (!d.name) return showToast('required')`. Replace with
`react-hook-form` + `zod` schemas **shared with the server's `args` schemas**
(generate both from one source where possible), so client and server agree on what
is valid instead of drifting.

Every form needs: field-level errors, disabled-while-submitting, server-error
mapping to fields, and unsaved-changes warning on close.
