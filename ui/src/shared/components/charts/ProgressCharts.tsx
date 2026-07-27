import { useEffect, useMemo, useRef, useState } from 'react';
import type { ChartOptions } from 'chart.js';
import { Bar, Line, Radar } from 'react-chartjs-2';
import { computeDomain, readChartTheme, registerCharts, type ChartTheme } from './chart-setup';

/**
 * The four Progress charts (W2.3).
 *
 * This module is loaded lazily by the Progress screen, so Chart.js is not in
 * the bundle a member downloads to look at their dashboard — it arrives only
 * when they open the screen that draws with it.
 *
 * Every chart here takes **already-bucketed** data. Nothing recomputes a range
 * client-side: the server decides the granularity, because it is the only place
 * that can decide it from the data rather than from a guess about pixel width.
 */
registerCharts();

/** Re-read the theme whenever the themed element's tokens change. */
function useChartTheme(): ChartTheme {
  const [theme, setTheme] = useState<ChartTheme>(() => readChartTheme(null));

  useEffect(() => {
    const target = document.getElementById('fc-app');

    setTheme(readChartTheme(target));

    if (!target || typeof MutationObserver === 'undefined') {
      return;
    }

    // The tokens are written as inline custom properties on #fc-app, by the PHP
    // shell before paint and by ThemeProvider afterwards. Watching the attribute
    // is what keeps a theme change from leaving four dark charts on a light page.
    const observer = new MutationObserver(() => setTheme(readChartTheme(target)));

    observer.observe(target, { attributes: true, attributeFilter: ['style'] });

    return () => observer.disconnect();
  }, []);

  return theme;
}

/**
 * Shared axis and legend styling.
 *
 * `maintainAspectRatio: false` with a fixed-height wrapper, so the chart fills
 * its card instead of dictating the card's shape at every breakpoint.
 */
function baseOptions(theme: ChartTheme, showLegend: boolean): ChartOptions<'line' | 'bar'> {
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        display: showLegend,
        labels: { color: theme.muted, boxWidth: 12, usePointStyle: true },
      },
      tooltip: {
        backgroundColor: theme.grid,
        titleColor: theme.text,
        bodyColor: theme.text,
        borderColor: theme.muted,
        borderWidth: 1,
        displayColors: true,
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { color: theme.muted, maxRotation: 0, autoSkipPadding: 12 },
      },
      y: { grid: { color: theme.grid }, ticks: { color: theme.muted } },
    },
  };
}

/** A line of readings — weight, body fat. Gaps stay gaps. */
export function ReadingChart({
  labels,
  values,
  label,
  unit,
  toneIndex = 0,
}: {
  labels: string[];
  values: Array<number | null>;
  label: string;
  unit: string;
  toneIndex?: number;
}) {
  const theme = useChartTheme();
  const colour = theme.series[toneIndex % theme.series.length];

  const options = useMemo(() => {
    const base = baseOptions(theme, false) as ChartOptions<'line'>;
    const domain = computeDomain(values);

    return {
      ...base,
      scales: {
        ...base.scales,
        y: { ...base.scales?.y, ...(domain ?? {}) },
      },
      plugins: {
        ...base.plugins,
        tooltip: {
          ...base.plugins?.tooltip,
          callbacks: {
            label: (context: { parsed: { y: number | null } }) =>
              context.parsed.y === null ? 'No reading' : `${context.parsed.y} ${unit}`,
          },
        },
      },
    } as ChartOptions<'line'>;
  }, [theme, values, unit]);

  return (
    <ChartFrame>
      <Line
        options={options}
        data={{
          labels,
          datasets: [
            {
              label,
              data: values,
              borderColor: colour,
              backgroundColor: withAlpha(colour, 0.12),
              fill: true,
              tension: 0.4,
              pointRadius: 4,
              pointBackgroundColor: colour,
              // Connect across the nulls. The nulls exist to keep readings
              // aligned to their bucket on the axis, **not** to break the line:
              // nobody weighs in daily, so on a 30-day chart every reading is
              // surrounded by them and `spanGaps: false` draws a scatter of
              // isolated dots with no trend at all. Verified in the browser,
              // where that is exactly what it looked like — a broken chart.
              // The points still mark where the real readings are, so the line
              // never claims a measurement that was not taken.
              spanGaps: true,
            },
          ],
        }}
      />
    </ChartFrame>
  );
}

/** Top-set progression for up to three lifts. */
export function StrengthChart({
  labels,
  series,
}: {
  labels: string[];
  series: Array<{ exercise_name: string; points: Array<number | null> }>;
}) {
  const theme = useChartTheme();

  const options = useMemo(() => {
    const base = baseOptions(theme, true) as ChartOptions<'line'>;
    const domain = computeDomain(
      series.flatMap((line) => line.points),
      { minPad: 2.5 },
    );

    return {
      ...base,
      scales: { ...base.scales, y: { ...base.scales?.y, ...(domain ?? {}) } },
    } as ChartOptions<'line'>;
  }, [theme, series]);

  return (
    <ChartFrame>
      <Line
        options={options}
        data={{
          labels,
          datasets: series.map((line, index) => ({
            label: line.exercise_name,
            data: line.points,
            borderColor: theme.series[index % theme.series.length],
            backgroundColor: 'transparent',
            tension: 0.4,
            pointRadius: 3,
            borderWidth: 2,
            // Same reasoning as the reading chart: a lift trained twice a week
            // is null on every other bucket, and breaking the line there leaves
            // three colours of confetti instead of three progressions.
            spanGaps: true,
          })),
        }}
      />
    </ChartFrame>
  );
}

/** Workouts per bucket. Zeros are drawn, because a zero is a fact. */
export function ConsistencyChart({ labels, values }: { labels: string[]; values: number[] }) {
  const theme = useChartTheme();
  const colour = theme.series[1];

  const options = useMemo(() => {
    const base = baseOptions(theme, false) as ChartOptions<'bar'>;

    return {
      ...base,
      scales: {
        ...base.scales,
        y: {
          ...base.scales?.y,
          beginAtZero: true,
          // Workouts are whole things; a tick at 2.5 is meaningless.
          ticks: { ...base.scales?.y?.ticks, precision: 0 },
        },
      },
    } as ChartOptions<'bar'>;
  }, [theme]);

  return (
    <ChartFrame>
      <Bar
        options={options}
        data={{
          labels,
          datasets: [
            {
              label: 'Workouts',
              data: values,
              backgroundColor: withAlpha(colour, 0.3),
              borderColor: colour,
              borderWidth: 2,
              borderRadius: 4,
            },
          ],
        }}
      />
    </ChartFrame>
  );
}

/**
 * Body measurements, oldest and newest overlaid.
 *
 * Only two sessions are drawn even when more exist: a radar with five
 * overlapping polygons is a decoration, not a comparison. Oldest against newest
 * is the comparison the member actually wants.
 */
export function MeasurementsChart({
  points,
}: {
  points: Array<Record<string, number | null | string>>;
}) {
  const theme = useChartTheme();

  const { labels, datasets } = useMemo(() => {
    const chosen = points.length > 2 ? [points[0], points[points.length - 1]] : points;

    // Only the dimensions every chosen session actually measured: a radar axis
    // with a hole in it reads as a measurement of zero.
    const keys = Object.keys(points[0] ?? {}).filter(
      (key) => key !== 'date' && chosen.every((point) => typeof point[key] === 'number'),
    );

    return {
      labels: keys.map((key) => LABELS[key] ?? key),
      datasets: chosen.map((point, index) => ({
        label: String(point.date),
        data: keys.map((key) => point[key] as number),
        borderColor: theme.series[index % theme.series.length],
        backgroundColor: 'transparent',
        borderWidth: 2,
        pointRadius: 3,
      })),
    };
  }, [points, theme]);

  const options = useMemo(
    () =>
      ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: theme.muted, usePointStyle: true, boxWidth: 12 } } },
        scales: {
          r: {
            grid: { color: theme.grid },
            angleLines: { color: theme.grid },
            ticks: { color: theme.muted, backdropColor: 'transparent', showLabelBackdrop: false },
            pointLabels: { color: theme.muted },
          },
        },
      }) as ChartOptions<'radar'>,
    [theme],
  );

  return (
    <ChartFrame>
      <Radar options={options} data={{ labels, datasets }} />
    </ChartFrame>
  );
}

const LABELS: Record<string, string> = {
  chest_cm: 'Chest',
  waist_cm: 'Waist',
  hips_cm: 'Hips',
  arms_cm: 'Arms',
  thighs_cm: 'Thighs',
  shoulders_cm: 'Shoulders',
  neck_cm: 'Neck',
  calves_cm: 'Calves',
};

/**
 * A fixed-height box for the canvas to fill.
 *
 * Chart.js measures its parent on first paint; inside a flex or grid child with
 * no resolved height it measures zero and draws nothing, which looks exactly
 * like a data problem and is not one.
 */
function ChartFrame({ children }: { children: React.ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);

  return (
    <div className="fc-chart-frame" ref={ref}>
      {children}
    </div>
  );
}

/**
 * A themed colour at partial alpha, for fills.
 *
 * Handles the three forms a resolved custom property arrives in — `#rgb`,
 * `#rrggbb` and `rgb(...)` — and falls back to `color-mix`, which every browser
 * that supports the rest of this stylesheet also supports.
 */
function withAlpha(colour: string, alpha: number): string {
  const hex = colour.trim();

  if (/^#[0-9a-f]{6}$/i.test(hex)) {
    const [r, g, b] = [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  if (/^#[0-9a-f]{3}$/i.test(hex)) {
    const [r, g, b] = [1, 2, 3].map((offset) => parseInt(hex[offset].repeat(2), 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  if (hex.startsWith('rgb(')) {
    return hex.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`);
  }

  return `color-mix(in srgb, ${hex} ${Math.round(alpha * 100)}%, transparent)`;
}
