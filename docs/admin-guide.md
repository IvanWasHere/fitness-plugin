# Administrator guide

Running a FitnessClub site. Written for whoever owns the platform rather than for
a developer.

---

## First, the thing that surprises everyone

**A WordPress login is not a FitnessClub login.**

FitnessClub keeps its own accounts. Being a WordPress administrator gives you no
access to the app at all — you will see the sign-in screen like anybody else.
Conversely, your members are *not* WordPress users and will never appear under
Users in wp-admin.

This is deliberate. Two systems that look like one identity but are not is how
people end up with more access than anyone intended.

### Your first account

Activation generates one administrator account and shows its password **once**,
on the **FitnessClub** screen in the wp-admin sidebar. Copy it before you refresh.

Lost it, or never copied it? You have shell access, and that is the authorisation:

```sh
wp fitnessclub account list
wp fitnessclub account reset-password adminFalcon
```

There is no email-based recovery for the last administrator, because mail is
broken on a surprising share of fresh WordPress installs and a platform whose
only admin cannot get in is not recoverable by wishing.

---

## The two screens you administer from

**wp-admin → FitnessClub** is small on purpose. It holds only what must survive
the app being broken:

- the **App URL**, because a bad value takes the app offline including the screen
  you would use to fix it;
- the **generated accounts**, because they exist precisely for when nobody can
  sign in yet;
- the **front-end theme** selector.

**Everything else** is in the admin app, at your app URL. Dashboard, all ten
resources, and the full settings screen.

---

## Settings

### App URL

The whole front end lives at `yoursite.com/{slug}` — `fitness` by default. All
three apps live at that one address; which one a visitor gets is decided by the
role on their account.

Changing it moves the app and refreshes permalinks. **Existing links break.** The
screen warns you before you save, and the same setting is editable from wp-admin
as the way back.

Slugs that collide with WordPress (`wp-admin`, `feed`, `sitemap`…) are refused
with a reason, as is one where a page already lives — that would be undefined
behaviour dressed up as a working setting.

### Brand

The name shown throughout all three apps. "FitForge" out of the box.

### Email

The From name and address for password resets and notifications. Leave blank to
use WordPress's defaults.

### Features

Switching a feature off **hides it across every app and refuses its endpoints** —
it is not a UI-only toggle:

| | |
|---|---|
| Messaging | Member ↔ trainer conversations |
| Nutrition | Meal and water logging |
| Health | Health metrics and measurements |
| Support | Tickets |
| Trainer directory | Browsing and requesting coaches |
| Open registration | Whether strangers may create accounts |
| JWT API | External/mobile clients — see below |

### Turning on the mobile API

Two steps, and **both** are required. Flipping the switch without the secret gets
you a clear 503 rather than tokens signed with something guessable.

1. Add a signing secret to `wp-config.php`:

   ```php
   define('FITNESSCLUB_JWT_SECRET', 'at-least-32-random-characters-here');
   ```

2. Switch on **JWT API** in Settings → Features.

It is a constant rather than a setting on purpose: an options row lives in a
database that gets dumped, shared with a contractor and restored into staging, and
a compromised database read should not also be able to mint valid tokens. There is
no auto-generated fallback for the same reason.

**Changing the secret signs every external client out immediately** — every
existing access token stops verifying. That is the emergency lever if you ever
believe tokens have leaked.

What clients get: a **15-minute** access token plus a refresh token valid for 30
days of use (180 days absolute). The short access token is deliberate — a JWT
cannot be revoked, so longevity lives in the refresh token, which can be and is.
Refresh tokens rotate on every use, so a stolen one surfaces as an unexpected
sign-out rather than a silent second session.

**Signing out works everywhere.** Changing an account's password, or revoking its
sessions, kills its mobile grants too — they share the session table specifically
so that cannot be forgotten.

---

## Members and trainers

### Adding a trainer

Create the account (`role=trainer`), then fill in their profile — specialisations,
bio, and **max clients**.

⚠️ **`max clients = 0` means "not configured", not "full".** A trainer left at
zero can still accept clients. Set a real number if you want a cap.

### How members get coaches

Members browse the directory and **request** a coach; the trainer accepts or
declines. You can also assign directly from the admin app as an override.

Two rules that generate support questions:

- **A member may have several coaches.** How many is capped by their plan.
- **A pending request spends a slot immediately.** Otherwise a one-trainer plan
  could queue five requests and keep whichever landed first.

### "2 of 1 trainer slots used"

Not a bug. Downgrading to a plan with fewer slots is **grandfathered**: it blocks
new requests but never severs an existing coaching relationship. The member keeps
both coaches and cannot add a third.

---

## Editing a member's data

You can edit health entries and meals on a member's behalf. This is a real
capability with real consequences, so it is visible rather than silent:

- the record is stamped `source: admin` and shows that to the member;
- an **audit row** records who, what, and the before/after of every field that
  actually changed;
- the same derivations rerun — BMI and the member's cached current weight follow
  your edit exactly as they would follow theirs.

Audit rows are **exempt from the 12-month activity prune**. An audit trail with a
retention window is not an audit trail.

---

## Deletes that are refused

Some deletes answer 409 with a reason rather than proceeding:

- a **trainer** with active clients;
- a **workout** with logged sessions — deleting it would orphan every member's
  history of doing it;
- a **food** that appears in members' diaries.

Resolve the dependency first. This is not configurable, deliberately.

---

## Billing

Plans, subscriptions and payment history are all in the admin app. What works
today:

- ✅ plans with feature flags and trainer-slot caps
- ✅ subscribe, change, cancel-at-period-end, resume
- ✅ webhooks with signature verification and idempotency
- ✅ dunning — a failed renewal becomes `past_due` with a 14-day grace window,
  then suspends
- ✅ manual payment entry

What does not:

- ❌ **Stripe.** The gateway seam is built and tested; the Stripe adapter is not
  written. Use manual entry.
- ❌ proration on plan change (currently cancel-and-restart)
- ❌ invoice PDFs, refunds beyond marking a payment refunded

**Cancelling always means at period end.** The member keeps what they paid for;
the screen says when it ends and offers to undo.

---

## Front-end themes

The three React apps are the **default** front end, not the only one. A WordPress
theme can supply its own — see [theme-development.md](theme-development.md).

To use one: install the theme in `wp-content/themes/` like any other, then pick it
at **wp-admin → FitnessClub → Front-end**.

- It works **per role**. A theme shipping only a member app leaves trainers and
  admins on the plugin's.
- A theme that is deleted, unbuilt, or broken **falls back** rather than erroring.
- The selector is in wp-admin, not in the app, precisely so a broken front end
  cannot take its own switch down with it.

⚠️ There is **no upload form**, deliberately. A theme is executable JavaScript;
uploading a zip of it through the browser would turn a compromised admin session
into permanent code execution. Install by deploy, git, or WordPress's own theme
installer.

If a theme you installed does not appear in the dropdown, check it has **both**
`style.css` (with `Fitness Plugin Extension Enabled: true`) **and** `index.php` —
WordPress hides themes it considers broken, and the plugin never sees them.

---

## Maintenance

### Scheduled jobs

Two run daily, registered on activation and cleared on deactivation:

- **stale session cleanup** — a member who closes the tab mid-workout would
  otherwise keep an open session forever and every later start would 409. The
  closed session is credited only its accumulated *active* time, never the hours
  the tab sat closed.
- **subscription expiry and dunning.**

If WP-Cron is disabled on your host, wire these to a real cron.

### Backups

All data is in `wp_fc_*` tables — a standard database backup covers it. Uploaded
attachments are in the normal WordPress uploads directory.

### Uninstalling

Deleting the plugin removes roles and capabilities but **preserves member data**.
To remove everything, define this before deleting:

```php
define('FITNESSCLUB_REMOVE_ALL_DATA', true);
```

---

## Troubleshooting

**The app URL 404s.** Visit **Settings → Permalinks** to force a rewrite flush.

**A member says their sessions did not appear until they refreshed.** The
dashboard is cached and invalidated on write. If it persists, the write path
firing the invalidation is the thing to look at, not the cache.

**A trainer cannot accept a client.** Capacity is checked at accept, not at
request — it may have filled in between. Check their max clients.

**Notifications a member expected never arrived.** Preferences are honoured at
the **write**: a muted category is never created, so it will not be waiting for
them later. Check their notification preferences.

**Everything looks unstyled / the page is blank.** Usually a front-end theme
problem. Switch back to the plugin's own apps in wp-admin and see if it clears.
