{{--
  Standalone full-page shell for the front-end SPAs (D9/D10).

  The plugin owns the whole HTML document here — no theme header/footer — so the
  app renders full-screen without a host theme's CSS. `data-boot` carries the boot
  payload; `$tags` is the Vite <script>/<link> block for the resolved role's SPA.

  Variables: $boot (array), $tags (raw HTML), $lang (string).
--}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $boot['brand'] ?? 'FitnessClub' }}</title>
  <style>
    html, body { margin: 0; padding: 0; background: #0B0E13; }
    #fc-app { min-height: 100vh; min-height: 100dvh; }
  </style>
  {!! $tags !!}
</head>
<body>
  <div id="fc-app" data-boot="{{ esc_attr(wp_json_encode($boot)) }}"></div>
</body>
</html>
