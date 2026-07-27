/**
 * The calorie ring, in SVG.
 *
 * Two stroked circles and a `stroke-dasharray`, which is all a progress ring
 * ever needs — the prototype drew this on a `<canvas>`, which meant it had to be
 * redrawn on every resize and theme change and could not inherit a CSS custom
 * property. This one restyles itself when the theme does.
 *
 * **Overshoot is shown, not clamped.** Eating 2 600 of a 2 400 calorie target is
 * information the member wants; a ring silently pinned at 100 % hides the one
 * number they would act on. The arc stops at full turn, and the label carries
 * the real figure with an "over" tone.
 */
export function MacroRing({
  value,
  goal,
  unit = 'kcal',
  label = 'Calories',
  size = 168,
}: {
  value: number;
  goal: number | null;
  unit?: string;
  label?: string;
  size?: number;
}) {
  const stroke = 12;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;

  const hasGoal = goal !== null && goal > 0;
  const ratio = hasGoal ? value / goal : 0;
  const over = hasGoal && ratio > 1;
  const drawn = Math.min(1, Math.max(0, ratio));

  return (
    <div className="fc-ring" style={{ width: size, height: size }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
        {/* Rotated so the arc starts at twelve o'clock rather than three. */}
        <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke="var(--border)"
            strokeWidth={stroke}
          />
          {hasGoal && (
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke={over ? 'var(--warn, var(--accent2))' : 'var(--accent)'}
              strokeWidth={stroke}
              strokeLinecap="round"
              strokeDasharray={circumference}
              strokeDashoffset={circumference * (1 - drawn)}
              className="fc-ring__arc"
            />
          )}
        </g>
      </svg>

      <div className="fc-ring__label">
        <div className="fc-ring__value">{Math.round(value)}</div>
        <div className="fc-ring__goal">{hasGoal ? `of ${Math.round(goal)} ${unit}` : unit}</div>
        <div className="fc-ring__caption">{label}</div>
      </div>
    </div>
  );
}

/**
 * One macro against its target. Renders without a bar when no goal is set —
 * a progress bar with no target is a decoration.
 */
export function MacroBar({
  label,
  value,
  goal,
  unit = 'g',
  tone = 'var(--accent)',
}: {
  label: string;
  value: number;
  goal: number | null;
  unit?: string;
  tone?: string;
}) {
  const hasGoal = goal !== null && goal > 0;
  const pct = hasGoal ? Math.min(100, (value / goal) * 100) : 0;

  return (
    <div className="fc-macro">
      <div className="fc-flex fc-flex-between fc-text-xs fc-mb-4">
        <span className="fc-text-muted">{label}</span>
        <span>
          {Math.round(value)}
          {hasGoal ? ` / ${Math.round(goal)}` : ''} {unit}
        </span>
      </div>
      {hasGoal && (
        <div className="fc-progress">
          <div className="fc-progress__fill" style={{ width: `${pct}%`, background: tone }} />
        </div>
      )}
    </div>
  );
}
