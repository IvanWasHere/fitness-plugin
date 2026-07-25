{{--
  Standalone full-page shell for the front-end SPAs (D9/D10).

  The plugin owns the whole HTML document here — no theme header/footer — so the
  app renders full-screen without a host theme's CSS. `data-boot` carries the boot
  payload; `$tags` is the Vite <script>/<link> block for the resolved role's SPA;
  `$themeCss` is the token block (plans/07-theming.md), scoped to #fc-app rather
  than :root so the optional shortcode embed can coexist with a host theme.

  The tokens are inlined rather than fetched: the whole block is under 2 KB, so a
  request would cost more than it saves — and a flash of unthemed app is worse
  than either.

  Variables: $boot (array), $tags (raw HTML), $themeCss (string), $lang (string).
--}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $boot['brand'] ?? 'FitnessClub' }}</title>
  <style id="fc-theme-vars">{!! $themeCss !!}</style>
  <style>
    html, body { margin: 0; padding: 0; background: {{ $boot['theme']['colors']['background'] ?? '#0B0E13' }}; }
    #fc-app {
      min-height: 100vh;
      min-height: 100dvh;
      font-family: var(--fc-type-font-family, system-ui, sans-serif);
      font-size: var(--fc-type-base-size, 16px);
      line-height: var(--fc-type-line-height, 1.6);
      color: var(--fc-color-text, #E8ECF1);
      background: var(--fc-color-background, #0B0E13);
    }
  </style>
  {!! $tags !!}
</head>
<body>
  <div id="fc-app" data-boot="{{ esc_attr(wp_json_encode($boot)) }}"></div>
</body>
</html>
