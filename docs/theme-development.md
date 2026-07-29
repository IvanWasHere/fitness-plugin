# Building a front-end theme

The three React apps FitnessClub ships are the **default** front end, not the only
one. A WordPress theme can replace any of them.

This is the reference. For a **complete working example**, see the `fitnessTheme`
theme — it provides all three apps in about 500 lines and its README is the
condensed version of this document.

---

## What a theme is

A directory in `wp-content/themes/` that declares itself in `style.css` and ships
a Vite build:

```
wp-content/themes/your-theme/
├── style.css                  # the opt-in header
├── index.php                  # WordPress requires it
└── fitnessclub/
    ├── .vite/manifest.json    # your own Vite build output
    └── assets/user-a3f9.js, user-a3f9.css, …
```

It is **not a WordPress theme in any meaningful sense** — it renders no WordPress
front end and should never be activated under Appearance → Themes. It lives there
for one reason: `wp-content/themes/` survives plugin updates, and
`wp-content/plugins/fitnessclub/` does not. Your front end would be deleted by the
next plugin update if it lived inside the plugin.

### 1. The header

```css
/*
Theme Name: Your Theme
Version: 1.0.0
Fitness Plugin Extension Enabled: true
*/
```

That header is the entire opt-in. Without it the plugin ignores the directory.

### 2. `index.php`

WordPress will not treat a directory as a theme without **both** `style.css` and
`index.php`, and `wp_get_themes()` omits themes it considers broken — so a missing
`index.php` means your theme **silently never appears in the dropdown**. This is
the single most common reason a theme "does not work".

Because your theme *is* listed in the normal theme picker, somebody can activate
it by accident and get a site with no templates. Redirect instead:

```php
<?php
if (!defined('ABSPATH')) {
    exit();
}

wp_safe_redirect(
    function_exists('FitnessClub')
        ? \FitnessClub\Support\AppRouter::url()
        : home_url('/')
);
exit;
```

The plugin never reads this file.

### 3. The build

```ts
// vite.config.ts
export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    manifest: true,
    outDir: resolve(__dirname, 'fitnessclub'),
    emptyOutDir: true,
    rollupOptions: {
      input: {
        user:    resolve(__dirname, 'src/user/main.tsx'),
        trainer: resolve(__dirname, 'src/trainer/main.tsx'),
        admin:   resolve(__dirname, 'src/admin/main.tsx'),
      },
    },
  },
});
```

`base: './'` matters: your theme does not know where it is installed, and absolute
asset URLs would bake in one site's directory name.

**Commit the `fitnessclub/` directory.** It is the deliverable — there is no build
step on the server.

---

## There is no manifest to write

The plugin reads **your Vite manifest**, the file Vite already emits, and works out
which roles you provide from its entries. There is no `theme.json`, no `provides`
block, and no list of files to maintain. Rebuild and the plugin picks up the new
hashes automatically.

Entries are matched by `src/{role}/main.tsx` first, then by entry **name** — so
either convention works.

### Per-role fallback

Ship what you want. Anything you leave out keeps coming from the plugin:

| Your entries | Members get | Trainers get | Admins get |
|---|---|---|---|
| `user` | yours | plugin's | plugin's |
| `user`, `trainer` | yours | yours | plugin's |
| all three | yours | yours | yours |

The roles you do not claim never know a theme is installed. A theme that is
deleted, unbuilt or contract-incompatible falls back the same way rather than
erroring — the front end must not go down because a directory went away.

⚠️ **Replacing the admin app has teeth.** A broken member app inconveniences
members; a broken admin app takes away the screen an administrator would use to
fix it. That is survivable only because the theme selector lives in **wp-admin**
rather than in the app. Test it before shipping.

---

## The boot payload

The plugin renders a standalone HTML document containing your bundle and:

```html
<div id="fc-app" data-boot="{…}"></div>
```

Everything you need for the first frame is in that attribute — no loading spinner,
no `/auth/me` on mount.

```ts
const boot = JSON.parse(document.getElementById('fc-app')!.dataset.boot!);
```

| Field | Type | Use |
|---|---|---|
| `restUrl` | string | REST root, e.g. `https://site/wp-json/fitnessclub/v1/` |
| `csrf` | string | Send as `X-FC-CSRF` on every non-GET |
| `user` | object \| **null** | **Null when nobody is signed in** |
| `user.role` | `user`\|`trainer`\|`admin` | |
| `user.id` | int | `fc_users.id` — the member profile |
| `user.account_id` | int | `fc_accounts.id` — the identity anchor |
| `brand` | string | The site's configured brand name |
| `app.base` / `app.basename` | string | The configurable slug. **Never hardcode `/fitness`** |
| `app.spa` | string | Which app the server resolved |
| `counts` | object | Unread messages and notifications, right on first paint |
| `entitlements` | object | Merged across every active subscription |
| `subscriptions` / `trainers` | array | |
| `theme` | object | The plugin's `--fc-*` tokens. Use them or ignore them |
| `flags` | object | Feature switches, e.g. `registration_open` |

`GET /auth/me` returns the same object minus the transport-only keys, so a refresh
is a replacement rather than a merge. Login and register return the **full**
payload, so signing in needs no second request.

The full schema is in [`openapi.json`](openapi.json) as `components.schemas.Boot`.

---

## Talking to the API

Two things authenticate a request, both from the boot payload:

```ts
await fetch(`${boot.restUrl}user/dashboard`, {
  credentials: 'same-origin',      // the session cookie
  headers: { 'X-FC-CSRF': boot.csrf },  // required on every non-GET
});
```

The CSRF token is bound to the session and expires with it, so there is **no
refresh dance**: a rejected token means the session is gone, and the correct
response is to show the sign-in screen.

Every failure is a WordPress error envelope:

```json
{ "code": "fc_not_authenticated", "message": "…", "data": { "status": 401 } }
```

**Branch on `code`, never on `message`** — messages are translated.

All 118 endpoints are documented in [`openapi.json`](openapi.json), generated from
the live route registry. Point an OpenAPI client generator at it if you want typed
bindings.

---

## Four things that are easy to get wrong

### 1. You own the front door

The plugin serves whichever app the **role** resolves to, and an anonymous visitor
resolves to the **member** app. If your theme replaces `user`, your bundle is what
a logged-out stranger sees — **so it needs its own sign-in screen.**

```ts
const boot = await api.post('auth/login', { user_login, password });
// answers the whole boot payload — go straight to the dashboard
```

Forgetting this is the most common way a theme ships broken.

### 2. Sign-out should reload, not re-render

Which bundle is served is a *server* decision, so after signing out the correct
next screen may not be your bundle at all. `window.location.reload()`.

### 3. The shell's inline style uses an id selector

The plugin emits this in `<head>`, **before** your stylesheet:

```css
#fc-app { background: var(--fc-color-background, #0B0E13); color: … }
```

That is specificity (1,0,0), so `.your-class { background: … }` loses however late
it loads. Put base overrides on `#fc-app` too.

Related: never write `#fc-app *` for a reset. That gives every base rule id-level
specificity, which beats every class in your own stylesheet — `padding: 0` wins
over your card, `background: none` over your button, and the whole app renders as
unstyled boxes. Use `:where(#fc-app)`, which scopes without contributing
specificity.

### 4. Dev mode bypasses themes

`FITNESSCLUB_VITE_DEV` in `wp-config.php` is a switch for developing the
**plugin's own** apps against its HMR server. While it is on, your theme is
ignored. Build your theme and test with the constant off.

---

## Installing and selecting

Themes are **filesystem-installed**, like plugins — by deploy, by git, or through
WordPress's own theme installer. There is deliberately **no upload form**: a theme
is executable JavaScript, and uploading a zip of it through the browser would turn
a compromised admin session into permanent code execution. No amount of
path-traversal checking changes that, because the executable payload is the point
of the file rather than a smuggled extra.

Select at **wp-admin → FitnessClub → Front-end**.

The plugin validates your manifest against carelessness rather than malice: asset
paths must resolve inside your theme's `fitnessclub/` directory and carry a
`.js`/`.css` extension. An entry that fails is skipped and that role falls back.

---

## Checklist before shipping

- [ ] `style.css` has the header; `index.php` exists and redirects
- [ ] `npm run build` writes `fitnessclub/.vite/manifest.json`
- [ ] `fitnessclub/` is committed
- [ ] The theme appears in the dropdown and lists the roles you expect
- [ ] **Signed out**, your member app shows a working sign-in screen
- [ ] Sign in, sign out, sign in again
- [ ] Every role you ship has been opened by a real account of that role
- [ ] Roles you do not ship still load the plugin's apps
- [ ] No console errors
- [ ] Deleting your theme directory returns the site to the plugin's apps

---

## The contract, and what is not guaranteed

Your app compiles against the boot payload, `GET /auth/me`, and the versioned REST
API under `fitnessclub/v1`. Those are a published contract — the plugin cannot
reshape them freely once themes depend on them.

**Contract version negotiation is not implemented.** There is no mechanism today
for a theme to declare which version it was built against and be refused cleanly
on a mismatch. Until there is, treat a plugin upgrade as something to test your
theme against rather than something guaranteed to be safe. Check
[`plans/08-roadmap.md`](../plans/08-roadmap.md) for where this stands.
