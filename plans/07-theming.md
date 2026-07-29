# 07 — Theme System

> ## ⟳ Corrected 2026-07-29 — a theme is a front-end, not a palette
>
> Everything below the "Deferred" heading describes a **colour system**, and that
> is not what a theme is here. Corrected by the owner before any of it was built
> and recorded as
> [D11](00-architecture.md#d11--a-theme-is-a-front-end-not-a-palette-supersedes-d7-extends-d10-2026-07-29):
>
> **A theme is a WordPress theme that ships React apps.** Select one and its
> `user`/`trainer`/`admin` bundles are served instead of the plugin's, per role,
> with the plugin's own app as the fallback for any role it does not ship.
>
> The palette system — tokens, JSON validation, contrast checks, the light theme,
> the theme editor — is **deferred indefinitely**. Not built, not scheduled. When
> a theme is selected everything comes from that theme's app; when one is not, the
> plugin's apps keep their existing styling. The design is kept below as a record
> of what was considered, not as a plan.

## What a theme is

A directory in `wp-content/themes/` that opts in with a `style.css` header and
carries a Vite build under `fitnessclub/`:

```css
/*
Theme Name: fitnessTheme
Version: 1.0
Fitness Plugin Extension Enabled: true
*/
```

```
wp-content/themes/fitnessTheme/
├── style.css               # the header above
├── index.php               # WordPress requires it
└── fitnessclub/
    ├── .vite/manifest.json # the theme's own Vite build output
    └── assets/user-a3f9.js, user-a3f9.css, trainer-b12c.js, …
```

**No bespoke manifest file.** The plugin reads *the theme's* Vite manifest the
same way `Support\ViteAssets` already reads its own. Whichever of
`user`/`trainer`/`admin` it has an entry for, the theme provides; the rest fall
back to the plugin's apps. Nothing is hand-written, and Vite's hashed filenames
are handled for free.

## Discovery and selection

`wp_get_themes()` enumerates every theme; the ones whose `style.css` carries
`Fitness Plugin Extension Enabled: true` are offered in a **dropdown on the
wp-admin options page**, defaulting to the plugin's own apps.

The header is parsed with `get_file_data()` rather than `WP_Theme::get()` —
`WP_Theme` exposes custom headers only via the `extra_theme_headers` filter and
caches parsed headers, so a filter registered after that cache is warm reads
`false` on a theme that plainly declares it.

The dropdown lives in wp-admin rather than the admin SPA for the reason the App
URL setting does: a setting that decides which front-end is served cannot live
inside the front-end it might break. A selection naming a theme that no longer
resolves falls back to the plugin's apps rather than erroring.

## Resolution

```
theme selected?  ── no ──►  plugin's app for this role
       │
      yes
       ▼
theme's manifest has this role?  ── no ──►  plugin's app for this role
       │
      yes
       ▼
theme's app
```

Per role, every request. A theme replacing only the member app leaves trainers and
admins on ours, and they never know a theme is installed.

## Validation

Installation is filesystem-only — no upload endpoint, no zip handling — so a theme
author is trusted at the level a plugin author is. What is checked is the
*manifest*, against carelessness rather than malice: asset paths resolve with
`realpath()` containment inside the theme directory and must end `.js`/`.css`. A
malformed manifest would otherwise emit a `<script>` tag pointing anywhere.

## The published contract

A theme's apps compile against the `data-boot` payload (`BootPresenter::shell()`),
`GET /auth/me`, and the versioned REST API under `fitnessclub/v1`. Those stop
being internal details the moment code we did not write depends on them — which is
this decision's real cost, and why 4.6's OpenAPI documentation is no longer
optional.

---

# Deferred — the token/palette system

**Not built and not scheduled** (D11, 2026-07-29). Kept as a record of the design.
Everything from here down describes the colour system that a theme was *going* to
be. If a palette editor is ever wanted for installs running the plugin's stock
apps, this is the design to start from.

## Storage

| Kind | Location | Writable |
|------|----------|----------|
| Bundled | `resources/themes/{slug}/theme.json` | no |
| User | `wp-content/uploads/fitnessclub-themes/{slug}/` | yes |
| Active slug | option `fitnessclub_active_theme` | — |
| Overrides | option `fitnessclub_theme_overrides` (JSON) | — |

Resolution: uploads → bundled → `default`. Then merge
`bundled default ← selected theme ← admin overrides` so a partial theme file is
valid and a single colour tweak does not require forking a whole theme.

Rejecting `plan.md` §5.2's `WP_CONTENT_DIR . '/wn_themes/'`: `wp-content/` root is
frequently read-only on managed hosts, is not backed up as user content, and the
spec contradicts itself by also listing `wn_themes/` inside the plugin directory
(§4.1) where plugin updates delete it.

## Schema

Extends §5.1 with the tokens the prototypes actually use:

```json
{
  "$schema": "https://fitnessclub.local/theme-schema.json",
  "theme_name": "default",
  "version": "1.0.0",
  "color_scheme": "dark",
  "colors": {
    "primary": "#00E676", "primary_contrast": "#0B0E13",
    "secondary": "#FF6D00", "success": "#00E676", "danger": "#FF5252",
    "warning": "#FFB300", "info": "#40C4FF", "purple": "#B388FF",
    "background": "#0B0E13", "background_alt": "#111621",
    "card": "#171D2A", "card_hover": "#1E2538", "border": "#252D3F",
    "text": "#E8ECF1", "text_muted": "#8FA0BC", "text_subtle": "#4A5568"
  },
  "typography": {
    "font_family": "'DM Sans', sans-serif",
    "heading_font": "'Outfit', sans-serif",
    "base_size": "16px", "line_height": "1.6", "heading_weight": "700"
  },
  "layout": {
    "container_width": "1400px", "sidebar_width": "72px",
    "radius": "14px", "radius_small": "8px", "radius_large": "20px",
    "spacing": "20px", "shadow": "0 4px 24px rgba(0,0,0,.35)"
  },
  "components": {
    "dashboard":       { "show_workout_progress": true, "show_nutrition_summary": true,
                         "show_stats_cards": true, "show_charts": true, "columns": 2 },
    "workout_tracker": { "layout": "vertical", "show_video": true, "show_timer": true,
                         "show_set_tracker": true, "rest_sound": true },
    "navigation":      { "position": "left", "sticky": true, "show_icons": true,
                         "show_labels": false }
  }
}
```

Note `text_muted` is `#8FA0BC`, not the prototypes' `#7A8BA7` — that value fails
WCAG AA against the card background (≈3.9:1). See "Validation" below.

## Rendering

```php
final class ThemeService
{
    public function activeTokens(): array;   // merged, validated JSON
    public function cssVariables(): string;  // ":root{--fc-color-primary:#00E676;…}"
}
```

Emitted into the front-end shell (D9) as an inline `<style>` (it is <2 KB, so a
request would cost more than it saves), cached in a transient keyed by theme slug +
override hash. All three SPAs mount on `#fc-app`:

```html
<style id="fc-theme-vars">
#fc-app { --fc-color-primary:#00E676; --fc-color-bg:#0B0E13; … }
</style>
```

Scoped to the app root, **not `:root`** — even though the render is standalone
(D9), scoping keeps the tokens contained and lets the optional shortcode-embed
path coexist with a host theme.

`components.*` are not CSS; they go into the boot payload as feature flags and the
React apps branch on them. This is what makes `show_charts: false` or
`navigation.position` actually do something — in the prototypes those keys exist
in the spec but nothing reads them.

## Validation — this is untrusted input

The theme editor accepts JSON from an admin and turns it into CSS. Treat it as
hostile:

```php
'colors.*'          => hex | rgb() | rgba() | hsl()          — regex, no fallthrough
'typography.*_size' => /^\d+(\.\d+)?(px|rem|em)$/
'typography.*_font' => whitelist of registered font stacks    — not free text
'layout.*'          => /^\d+(\.\d+)?(px|rem|em|%)$/ | shadow whitelist
'components.*'      => boolean | enum from a fixed list
unknown keys        => dropped
```

Never interpolate a raw value into a declaration. `"primary": "red;} body{display:none"`
is a site-wide defacement with an admin-only entry point — and admin-only is not a
defence when the vector is a stored XSS or a compromised admin session.

Additionally, **validate contrast**: on save, compute WCAG contrast for
text/background, text_muted/card, primary_contrast/primary. Warn below 4.5:1 for
body text and 3:1 for large text and UI borders. Block activation below 3:1. A
theme system that lets an admin ship an unreadable app is a support burden.

Also cap: max 40 KB per theme.json, max 100 themes, zip uploads unpacked with path
traversal checks (`../` in a zip entry is the classic archive-extraction bug), only
`theme.json` + `preview.png` + `assets/*.{png,jpg,svg,woff2}` extracted.

## Admin UI (`/admin/themes`)

| Feature | Detail |
|---------|--------|
| Gallery | Cards with preview image + palette swatches; active theme badged |
| Live preview | Apply tokens to an iframe of the user app without activating |
| Editor | Grouped colour pickers + typography + layout + component toggles; JSON view for power users |
| Duplicate | Fork a bundled theme into uploads — the only way to edit a bundled theme |
| Import/export | `.zip` in, `.zip` out |
| Reset | Drop overrides, return to the theme file |
| Contrast report | Pass/fail per token pair, shown live in the editor |

Ship two bundled themes: `default` (dark, the prototypes' palette with the
contrast fix) and `light`. Building a light theme immediately is the only way to
prove the token set is complete — a single-theme theme system always has hardcoded
colours hiding in it. Do it in Phase 4 week 1, not last.

## Migration hazard

Every hardcoded colour in the ported CSS and in the Chart.js option objects is a
theming bug. The prototypes have many: `'#00E676'`, `'rgba(37,45,63,0.5)'`,
`'#7A8BA7'` in chart configs, `#0B0E13` in `.btn-primary`, the confetti palette
array, the `.toast-success` background, the `meal.color`/`notification.color`
values baked into seed data.

Port CSS by **grepping for `#` and `rgba(` and replacing every literal with a
token**, then keeping a CI check that fails on a hex literal outside
`styles/tokens.css`. Doing this during the port costs hours; doing it after the
light theme reveals the problem costs days.
