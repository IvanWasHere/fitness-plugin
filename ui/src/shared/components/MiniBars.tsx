/**
 * A seven-bar column chart, in CSS.
 *
 * Deliberately not a charting library: the dashboard needs one small series and
 * every library that draws it costs 40–150 kB on a screen whose whole point is
 * first paint. W2.3's Progress screen has four real charts with axes, ranges and
 * tooltips — that is where a library earns its weight, and this component is not
 * a foundation for it.
 *
 * Two things it still has to get right:
 *
 *   - **an all-zero series is not a full chart.** Scaling to `max` alone means a
 *     week with one 90-kcal walk draws the same picture as a week of hard
 *     sessions, and a week of nothing divides by zero. Bars are drawn against
 *     `max(series)` with a floor, and a zero renders as a visible stub rather
 *     than nothing at all — "you did not train" is information.
 *   - **it is not readable by shape alone.** The whole series is announced as
 *     text for screen readers; the bars are decorative to assistive tech.
 */
export function MiniBars({
  labels,
  values,
  unit,
  title,
  tone = 'var(--accent)',
  highlightLast = true,
}: {
  labels: string[];
  values: number[];
  unit: string;
  title: string;
  tone?: string;
  highlightLast?: boolean;
}) {
  const max = Math.max(...values, 0);
  const scale = max > 0 ? max : 1;

  const description = values
    .map((value, index) => `${labels[index] ?? ''} ${value} ${unit}`)
    .join(', ');

  return (
    <div className="fc-bars">
      <div className="fc-bars__track" role="img" aria-label={`${title}: ${description}`}>
        {values.map((value, index) => {
          const isLast = index === values.length - 1;

          return (
            <div className="fc-bars__col" key={`${labels[index]}-${index}`}>
              <div
                className="fc-bars__bar"
                style={{
                  // 4% keeps an empty day visible as a stub; without it a rest
                  // day is indistinguishable from a missing one.
                  height: `${Math.max(4, (value / scale) * 100)}%`,
                  background: highlightLast && isLast ? tone : 'var(--accent-d)',
                  opacity: value === 0 ? 0.45 : 1,
                }}
              />
              <span className="fc-bars__label" aria-hidden="true">
                {labels[index]}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
