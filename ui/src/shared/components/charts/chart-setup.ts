import {
  BarController,
  BarElement,
  CategoryScale,
  Chart,
  Filler,
  Legend,
  LineController,
  LineElement,
  LinearScale,
  PointElement,
  RadarController,
  RadialLinearScale,
  Tooltip,
} from 'chart.js';

/**
 * Chart.js registration and theming (plans/04-user-app.md#charts, W2.3).
 *
 * **Only the pieces the four charts use are registered.** The prototype loaded
 * the UMD bundle from a CDN — every controller, scale and plugin Chart.js has,
 * about 200 kB, plus a third-party uptime dependency and a GDPR exposure in the
 * EU. Registering by hand is what lets the bundler drop the rest.
 *
 * **Colours come from theme tokens, never from literals.** The prototype
 * hardcoded `#00E676`, `rgba(37,45,63,0.5)` and `#7A8BA7` into every chart
 * config, so the charts would stay dark-themed the moment anyone activated a
 * light theme. These are read back off the live element, so whatever the PHP
 * shell or `ThemeProvider` wrote is what the charts draw.
 */
let registered = false;

export function registerCharts(): void {
  if (registered) {
    return;
  }

  Chart.register(
    LineController,
    BarController,
    RadarController,
    CategoryScale,
    LinearScale,
    RadialLinearScale,
    PointElement,
    LineElement,
    BarElement,
    Filler,
    Legend,
    Tooltip,
  );

  registered = true;
}

export interface ChartTheme {
  text: string;
  muted: string;
  grid: string;
  series: string[];
}

/**
 * Resolve the theme tokens the charts need to actual colour strings.
 *
 * Chart.js draws to a canvas, which cannot resolve `var(--accent)` — the value
 * has to be a real colour by the time it reaches the context. `getComputedStyle`
 * against the themed element is what turns one into the other.
 *
 * The grid line is derived from the border token rather than being its own
 * token: the prototype's `rgba(37,45,63,0.5)` was the border colour at half
 * alpha, and expressing that as a relationship means a theme only has to define
 * the border once.
 */
export function readChartTheme(element: HTMLElement | null): ChartTheme {
  const fallback: ChartTheme = {
    text: '#e8ecf1',
    muted: '#8fa0bc',
    grid: 'rgba(37, 45, 63, 0.5)',
    series: ['#00e676', '#ff6d00', '#40c4ff', '#b388ff'],
  };

  const target =
    element ?? (typeof document === 'undefined' ? null : document.getElementById('fc-app'));

  if (!target || typeof getComputedStyle === 'undefined') {
    return fallback;
  }

  const styles = getComputedStyle(target);
  const read = (token: string, or: string): string => {
    const value = styles.getPropertyValue(token).trim();

    return value === '' ? or : value;
  };

  return {
    text: read('--text', fallback.text),
    muted: read('--text2', fallback.muted),
    // `color-mix` resolves in the browser but not in every canvas colour parser,
    // so the grid is composed here from a plain colour plus an explicit alpha
    // via Chart.js' own border alpha instead of relying on the mix.
    grid: read('--border', '#252d3f'),
    series: [
      read('--accent', fallback.series[0]),
      read('--accent2', fallback.series[1]),
      read('--info', fallback.series[2]),
      read('--purple', fallback.series[3]),
    ],
  };
}

/**
 * Axis domain with breathing room, computed from the data.
 *
 * The prototype hardcoded `min: 76, max: 82` on the weight axis, so any member
 * outside that six-kilo band got a blank chart (gap §2, line 955) — and a new
 * member with a single reading got a flat line pinned to an arbitrary edge.
 *
 * Two cases a naive min/max gets wrong, both of which look broken rather than
 * empty:
 *
 *   - **one point, or several identical ones.** The range is zero, so Chart.js
 *     draws the line on the axis itself. A fixed pad around the value puts it in
 *     the middle of the plot where it belongs.
 *   - **nothing at all.** `Math.min()` of an empty list is `Infinity`, which
 *     poisons the scale silently.
 */
export function computeDomain(
  values: Array<number | null | undefined>,
  { pad = 0.1, minPad = 1 }: { pad?: number; minPad?: number } = {},
): { min: number; max: number } | undefined {
  const present = values.filter((value): value is number => typeof value === 'number');

  if (present.length === 0) {
    return undefined;
  }

  const low = Math.min(...present);
  const high = Math.max(...present);
  const spread = high - low;
  const padding = spread === 0 ? minPad : Math.max(spread * pad, minPad);

  return {
    min: Math.floor((low - padding) * 10) / 10,
    max: Math.ceil((high + padding) * 10) / 10,
  };
}
