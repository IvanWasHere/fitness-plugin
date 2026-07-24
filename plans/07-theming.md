# 07 — Theme System

`plan.md` §5 specifies JSON theme files. The prototypes make this unusually cheap
to implement: **both are already driven entirely by CSS custom properties on
`:root`**. Theming is therefore "generate a `:root` block from validated JSON",
not a refactor.

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

Emitted once per page as an inline `<style>` (it is <2 KB, so a request would cost
more than it saves), cached in a transient keyed by theme slug + override hash:

```html
<style id="fc-theme-vars">
#fc-app, #fc-admin-app { --fc-color-primary:#00E676; --fc-color-bg:#0B0E13; … }
</style>
```

Scoped to the app roots, **not `:root`** — the app is embedded in someone else's
WordPress theme and must not repaint their site.

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
