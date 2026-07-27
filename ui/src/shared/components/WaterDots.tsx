import { Icon } from './Icon';

/**
 * The row of water glasses.
 *
 * Two gestures, and they send different things on purpose:
 *
 *   - **the +/- buttons send a delta**, so two taps in flight add two glasses
 *     rather than racing to write the same total;
 *   - **tapping the n-th dot sends a total**, because "I have had five" is an
 *     absolute statement and computing the delta client-side would need a
 *     current value that may already be stale.
 *
 * Tapping the dot you are already on sets the total *below* it — that is the
 * undo gesture, and without it the only way down is the minus button.
 */
export function WaterDots({
  glasses,
  glassesGoal,
  glassMl,
  onSetTotal,
  onDelta,
  busy = false,
}: {
  glasses: number;
  glassesGoal: number;
  glassMl: number;
  onSetTotal: (totalMl: number) => void;
  onDelta: (deltaMl: number) => void;
  busy?: boolean;
}) {
  // Always show the goal's worth of dots, plus any the member has gone past it.
  const shown = Math.max(glassesGoal, glasses);

  return (
    <div className="fc-water">
      <div className="fc-water__dots">
        {Array.from({ length: shown }, (_, index) => {
          const filled = index < glasses;
          const isLastFilled = index === glasses - 1;

          return (
            <button
              key={index}
              type="button"
              className={`fc-water__dot${filled ? ' fc-water__dot--full' : ''}`}
              disabled={busy}
              aria-pressed={filled}
              aria-label={`${index + 1} ${index === 0 ? 'glass' : 'glasses'}`}
              onClick={() => onSetTotal((isLastFilled ? index : index + 1) * glassMl)}
            />
          );
        })}
      </div>

      <div className="fc-water__controls">
        <button
          type="button"
          className="fc-btn fc-btn--secondary fc-btn--sm"
          disabled={busy || glasses <= 0}
          onClick={() => onDelta(-glassMl)}
          aria-label="Remove a glass"
        >
          <Icon name="minus" size={14} />
        </button>
        <button
          type="button"
          className="fc-btn fc-btn--secondary fc-btn--sm"
          disabled={busy}
          onClick={() => onDelta(glassMl)}
          aria-label="Add a glass"
        >
          <Icon name="plus" size={14} />
        </button>
      </div>
    </div>
  );
}
