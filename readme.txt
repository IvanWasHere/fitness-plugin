=== FitnessClub ===
Contributors: fitnessclub
Tags: fitness, workout, nutrition, personal trainer, health
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

🏋️ A complete fitness platform for WordPress — workout tracking, nutrition, health metrics, trainer coaching, messaging and subscriptions, served as three React apps over a versioned REST API.

== Description ==

FitnessClub turns a WordPress site into a coaching platform. Members track workouts and meals, trainers coach them, administrators run the whole thing — each from their own app.

It is **two things bolted together on purpose**:

* 🧠 A **WordPress backend** — 29 custom tables, 118 REST endpoints, roles, capabilities and ownership guards.
* 💻 A **front-end of three React apps** — member, trainer and admin — that talk to that API and nothing else.

That separation is what lets you replace the entire front-end with your own (see *Front-end themes* below) without touching a line of PHP.

= ✨ For members =

* 🏋️ **Workout player** — start, pause, resume across devices, log every set, finish with a summary.
* ⏱️ **Timestamp-derived timers** — lock your phone between sets; the clock stays right.
* 📴 **Offline set queue** — sets logged on bad gym wifi are retried, not lost.
* 🏆 **Automatic personal records** — heaviest set, best volume, longest hold.
* 🥗 **Nutrition diary** — food database with search, per-meal macros, daily rollups, water tracking.
* ❤️ **Health tracking** — weight, body fat, sleep, resting heart rate, blood pressure, mood, energy, BMI, body measurements.
* 📈 **Progress charts** — weight, body fat, strength progression and consistency, over week / month / quarter / year.
* 🔥 **Streaks and activity feed** — in your own timezone.
* 💬 **Messaging with your trainers** — per-trainer quotas, attachments.
* 🔔 **Notifications** — with per-category preferences that stop them being created, not just hidden.
* 🔎 **Trainer directory** — browse coaches, see capacity, send a request.
* 💳 **Subscriptions** — plans, checkout, payment history, cancel and resume.
* 🎫 **Support tickets** — with an FAQ you edit in settings rather than in code.

= 🧑‍🏫 For trainers =

* 📋 **Client roster** — with a "needs attention" list first, because a list of counts is a report and a list of people is a tool.
* 🗂️ **Seven-tab client record** — overview, workouts, nutrition, progress, health, notes, messages.
* 🏗️ **Workout builder** — drag-reorder exercises, sets, reps, weight, rest, metric.
* 💵 **Plan builder** — your own priced coaching plans.
* 🍱 **Food-plan assignment.**
* 🔒 **Private client notes** — invisible to other trainers.
* ✅ **Request queue** — accept or decline new clients, with capacity respected.
* 👥 **Shared clients** — a member may have several coaches; you can see the others' programming but never edit it.

= 🛠️ For administrators =

* 📊 **Dashboard** — members, trainers, MRR, sessions, failed payments, recent signups.
* 🗄️ **CRUD for 10 resources** — users, trainers, workouts, foods, meals, plans, subscriptions, payments, tickets, health entries.
* ⚙️ **Settings** — brand, app URL, email, feature toggles.
* 📝 **Audit trail** — staff edits to a member's health or nutrition data are recorded with actor, subject and before/after values.
* 🚫 **Safe deletes** — removing a trainer with active clients, or a workout with logged sessions, is refused with a reason.

= 🎨 Front-end themes =

The three React apps that ship with the plugin are the **default** front-end, not the only one. A WordPress theme can supply its own.

* 📦 Add `Fitness Plugin Extension Enabled: true` to a theme's `style.css`.
* 🏗️ Put a Vite build in that theme's `fitnessclub/` directory.
* 🎛️ Pick it at **wp-admin → FitnessClub → Front-end**.

It works **per role**: ship a member app only, and trainers and admins keep the plugin's. Nothing degrades, and a theme that is deleted or unbuilt falls back rather than erroring.

There is no `theme.json` and no file list to maintain — the plugin reads the theme's own Vite manifest, so a rebuild is picked up automatically.

⚠️ Themes are installed from the filesystem, like plugins. There is deliberately **no upload form**: a zip of JavaScript uploaded through the browser would turn a compromised admin session into permanent code execution.

= 🧱 Built on =

WP Bones, Vite, React 18 and TypeScript. Custom tables — no custom post types.

== Installation ==

1. 📁 Upload the `fitnessclub` folder to `/wp-content/plugins/`.
2. 🔌 Activate it through **Plugins** in wp-admin. This creates 29 tables and seeds the platform plans.
3. 🔑 Go to **FitnessClub** in the wp-admin sidebar. Activation generates one administrator account — **copy the password, it is shown once.**
4. 🌐 Check the **App URL** on that screen. The apps live at `example.com/fitness` by default.
5. 🚪 Open that URL and sign in with the generated account.

= 🌱 Optional: demo data =

    wp fitnessclub seed --demo

== Frequently Asked Questions ==

= Can I log in with my WordPress account? =

**No, and this is deliberate.** FitnessClub owns its own accounts. A WordPress administrator has no access to the app without a FitnessClub account, and a FitnessClub member is not a WordPress user. Two systems that look like one identity but are not is how people end up with more access than anyone intended.

Activation generates a starter administrator account. Create more with:

    wp fitnessclub account create --login=name --email=you@example.com --role=admin

= Where does the app live? =

At a slug you choose — `example.com/fitness` by default, editable in **FitnessClub → Settings**. All three apps live at that one address; which one you get is decided by the role on your account.

= I changed the App URL and now everything 404s =

The setting is **also** editable from the wp-admin FitnessClub screen, precisely so a bad value can be undone. Changing it re-flushes permalinks on the next request.

= Does it work on multisite? =

Not currently. Single-site only.

= Which payment gateways are supported? =

Manual entry works today. The gateway seam, webhooks with signature verification, dunning and the full subscription state machine are all built and tested — but **the Stripe adapter itself is not yet written.**

= Is there a mobile app? =

Not yet. The REST API is designed to serve one, and JWT authentication for external clients is planned.

== REST API ==

Base: `/wp-json/fitnessclub/v1/`

🔐 Requests authenticate with the FitnessClub session cookie plus an `X-FC-CSRF` header, both delivered in the boot payload. Every list endpoint paginates (default 20, max 100) and returns `X-WP-Total`. Errors use stable `fc_*` codes — branch on the code, never the message.

= 🩺 Health check =

* `GET /health`

= 🔑 Authentication =

* `POST /auth/login`
* `POST /auth/register`
* `POST /auth/logout`
* `GET /auth/me`
* `POST /auth/password/forgot`
* `POST /auth/password/reset`

= 🏠 Member dashboard =

* `GET /user/dashboard`
* `GET /user/preferences`
* `PUT /user/preferences`

= 🏋️ Workouts and exercises =

* `GET /workouts`
* `GET /workouts/{id}`
* `GET /exercises/{id}`

= ⏱️ Workout sessions =

* `GET /sessions`
* `GET /sessions/active`
* `GET /sessions/{id}`
* `POST /sessions`
* `PATCH /sessions/{id}`
* `PATCH /sessions/{id}/cursor`
* `POST /sessions/{id}/sets`
* `POST /sessions/{id}/complete`
* `POST /sessions/{id}/abandon`
* `PATCH /sessions/{id}/review`

= 🥗 Nutrition =

* `GET /nutrition/day`
* `GET /nutrition/logs`
* `POST /nutrition/logs`
* `PUT /nutrition/logs/{id}`
* `DELETE /nutrition/logs/{id}`
* `POST /nutrition/water`
* `PUT /nutrition/goals`

= 🍎 Foods =

* `GET /foods`
* `POST /foods`
* `GET /foods/barcode/{code}`

= ❤️ Health and measurements =

* `GET /health/summary`
* `GET /health/stats`
* `POST /health/stats`
* `PUT /health/stats/{id}`
* `DELETE /health/stats/{id}`
* `GET /health/measurements`
* `POST /health/measurements`

= 📈 Progress =

* `GET /progress`
* `GET /progress/exercises/{name}`
* `GET /progress/records`
* `GET /progress/consistency`

= 🔔 Notifications and activity =

* `GET /notifications`
* `POST /notifications/read-all`
* `POST /notifications/{id}/read`
* `DELETE /notifications/{id}`
* `GET /activity`

= 💬 Messaging =

* `GET /messages/threads`
* `GET /messages/threads/{id}`
* `POST /messages/threads/{id}`
* `POST /messages/threads/{id}/read`
* `GET /messages/unread-count`
* `GET /messages/poll`
* `POST /messages/attachments`

= 💳 Billing =

* `GET /billing/subscription`
* `GET /billing/plans`
* `GET /billing/payments`
* `POST /billing/checkout`
* `POST /billing/subscription/cancel`
* `POST /billing/subscription/resume`
* `POST /billing/subscription/change`
* `POST /billing/webhook/{gateway}`

= 🎫 Support =

* `GET /support/faq`
* `GET /support/tickets`
* `POST /support/tickets`
* `GET /support/tickets/{id}`
* `PATCH /support/tickets/{id}`
* `POST /support/tickets/{id}/replies`

= 🔎 Trainer directory (member side) =

* `GET /trainers`
* `GET /trainers/{id}`
* `POST /trainers/{id}/request`
* `DELETE /trainers/{id}/request`
* `GET /user/trainers`
* `DELETE /user/trainers/{trainerId}`
* `POST /user/trainers/{trainerId}/primary`

= 🧑‍🏫 Trainer =

* `GET /trainer/dashboard`
* `GET /trainer/profile`
* `PUT /trainer/profile`
* `GET /trainer/requests`
* `POST /trainer/requests/{id}/accept`
* `POST /trainer/requests/{id}/decline`

= 👥 Trainer — clients =

* `GET /trainer/clients`
* `GET /trainer/clients/{userId}`
* `GET /trainer/clients/{userId}/progress`
* `GET /trainer/clients/{userId}/sessions`
* `GET /trainer/clients/{userId}/nutrition`
* `POST /trainer/clients/{userId}/workouts`
* `DELETE /trainer/clients/{userId}/workouts/{id}`
* `POST /trainer/clients/{userId}/food-plans`
* `GET /trainer/clients/{userId}/notes`
* `POST /trainer/clients/{userId}/notes`
* `PUT /trainer/notes/{id}`
* `DELETE /trainer/notes/{id}`

= 🏗️ Trainer — library =

* `GET /trainer/workouts`
* `POST /trainer/workouts`
* `GET /trainer/workouts/{id}`
* `PUT /trainer/workouts/{id}`
* `PUT /trainer/workouts/{id}/exercises`
* `DELETE /trainer/workouts/{id}`
* `GET /trainer/plans`
* `POST /trainer/plans`
* `PUT /trainer/plans/{id}`
* `DELETE /trainer/plans/{id}`
* `GET /trainer/food-plans`
* `POST /trainer/food-plans`
* `PUT /trainer/food-plans/{id}`
* `DELETE /trainer/food-plans/{id}`

= 🛠️ Admin =

* `GET /admin/dashboard`
* `GET /admin/resources`
* `GET /admin/settings`
* `PUT /admin/settings`

Plus uniform CRUD over each resource:

* `GET /admin/{resource}` — with `q`, `sort`, `order`, paging and per-resource filters
* `POST /admin/{resource}`
* `GET /admin/{resource}/{id}`
* `PUT /admin/{resource}/{id}`
* `DELETE /admin/{resource}/{id}`

Where `{resource}` is one of: `users`, `trainers`, `workouts`, `foods`, `meals`, `plans`, `subscriptions`, `payments`, `tickets`, `health-entries`.

⚠️ `/admin/*` is gated on FitnessClub's own capabilities, **not** on `manage_options`. A WordPress administrator with no FitnessClub account is anonymous here by design.

== WP-CLI ==

= 🌱 Seeding =

The platform tiers seed themselves on activation. Demo and volume data are opt-in here, so they never land on a production site by accident. Every seeder upserts on a natural key, so re-running converges rather than duplicating.

    wp fitnessclub seed --demo             # the demo dataset
    wp fitnessclub seed --volume=1000:180  # 1000 sessions over 180 days, for perf work
    wp fitnessclub seed --purge-volume     # remove only the synthetic rows

= 👤 Accounts =

🆘 This is the recovery path. Because the plugin owns its own credentials, a site whose only administrator lost their password has no way back in short of editing the database by hand. Shell access is the authorisation — there is no session in WP-CLI.

    wp fitnessclub account list
    wp fitnessclub account create --login=coach --email=c@example.com --role=trainer
    wp fitnessclub account reset-password adminFalcon
    wp fitnessclub account promote adminFalcon --role=admin
    wp fitnessclub account revoke-sessions adminFalcon

== Changelog ==

= 0.1.0 =

* 🎉 First release.
* 🏋️ Workout domain — sessions, per-set logging, personal records, streaks.
* 🥗 Nutrition — food search, meal logging, daily rollups, water, goals.
* ❤️ Health and body measurements with an audit trail on staff edits.
* 📈 Progress charts with real range bucketing.
* 💬 Messaging with per-trainer quotas.
* 🔔 Notifications honoured at write time, with preferences.
* 💳 Subscriptions, entitlement merging across concurrent plans, dunning, webhooks.
* 🎫 Support tickets with an editable FAQ.
* 🧑‍🏫 The full trainer app — clients, builders, notes, request queue, profile.
* 🔎 Trainer directory and request flow.
* 🛠️ Admin app over 10 resources, plus settings.
* 🎨 Front-end themes — replace any of the three apps from a WordPress theme.

== Upgrade Notice ==

= 0.1.0 =
First release. Pre-1.0: the REST contract may still change between versions.

== Known limitations ==

Stated plainly rather than discovered:

* 💳 **No Stripe adapter yet.** The gateway seam, webhook handling and subscription lifecycle are built and tested against a fake gateway; the Stripe implementation is not written. Manual payment entry works.
* 🌍 **Single-site only.** Multisite is not supported.
* 📱 **No JWT yet**, so no external or mobile clients. Cookie authentication only.
* 🎨 **No colour-theme editor.** A front-end theme replaces the whole app; there is no palette or light-mode switcher for the plugin's own apps.
* 🧾 **No invoice PDFs, no proration** on plan change, and no CSV import/export.
* 🍱 **No food-plan meal editor** and no exercise library browser.
* 🗓️ **No onboarding wizard.**
