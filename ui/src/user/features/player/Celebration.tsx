import { useEffect, useRef } from 'react';
import { Icon } from '@shared/components/Icon';
import { Button } from '@shared/components/ui';
import { formatDuration } from '@shared/hooks/timers';
import type { Celebration as CelebrationPayload } from '../../api/types';

/**
 * The post-workout screen.
 *
 * Every number here comes from the `POST /sessions/{id}/complete` response, which
 * the server computes — the prototype's version read
 * `state.workoutPlayer.exercises.length` (the property is `.workout.exercises`,
 * so it rendered `undefined`) and hard-coded its personal records as strings.
 */
export function Celebration({
  payload,
  onDone,
}: {
  payload: CelebrationPayload;
  onDone: () => void;
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useConfetti(canvasRef);

  return (
    <section className="fc-celebration" aria-label="Workout complete">
      <canvas ref={canvasRef} className="fc-celebration__canvas" aria-hidden="true" />

      <div style={{ position: 'relative', zIndex: 1 }}>
        <div
          className="fc-stat__icon"
          style={{
            margin: '0 auto',
            background: 'var(--accent-d)',
            color: 'var(--accent)',
            width: 64,
            height: 64,
          }}
        >
          <Icon name="check" size={32} />
        </div>

        <h1 className="fc-mt-16">Workout complete</h1>
        <p className="fc-text-muted fc-mt-4">{payload.workout_name}</p>

        <div className="fc-summary">
          <Summary value={formatDuration(payload.duration_seconds)} label="Duration" />
          <Summary value={String(payload.calories_burned)} label="Calories" />
          <Summary
            value={`${payload.exercises_completed}/${payload.total_exercises}`}
            label="Exercises"
          />
          <Summary
            value={`${Math.round(payload.total_volume_kg).toLocaleString()} kg`}
            label="Volume"
          />
        </div>

        {payload.personal_records.length > 0 && (
          <div className="fc-flex fc-flex-column fc-gap-8 fc-mb-24">
            {payload.personal_records.map((record) => (
              <div key={`${record.exercise_name}:${record.record_type}`} className="fc-pr">
                <Icon name="trophy" />
                <div>
                  <div className="fc-font-bold">{record.exercise_name}</div>
                  <div className="fc-text-xs">
                    {recordLabel(record.record_type)} · {trimNumber(record.value)} {record.unit}
                    {record.previous_value !== null &&
                      ` (was ${trimNumber(record.previous_value)})`}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {payload.streak_days > 0 && (
          <p className="fc-text-muted fc-mb-24">
            <Icon name="flame" size={14} />{' '}
            {payload.streak_extended
              ? `${payload.streak_days}-day streak — keep it going.`
              : `Still on a ${payload.streak_days}-day streak.`}
          </p>
        )}

        <Button variant="primary" size="lg" onClick={onDone}>
          Done
        </Button>
      </div>
    </section>
  );
}

function Summary({ value, label }: { value: string; label: string }) {
  return (
    <div>
      <div className="fc-summary__value">{value}</div>
      <div className="fc-summary__label">{label}</div>
    </div>
  );
}

function recordLabel(type: CelebrationPayload['personal_records'][number]['record_type']): string {
  switch (type) {
    case 'max_weight':
      return 'Heaviest set';
    case 'max_reps':
      return 'Most reps';
    case 'max_volume':
      return 'Most volume';
    default:
      return 'Longest hold';
  }
}

function trimNumber(value: number): string {
  return String(Number(value.toFixed(2)));
}

/**
 * Confetti on a canvas, self-contained and cheap — no library, no CDN.
 * Skipped entirely for `prefers-reduced-motion`, where a screenful of falling
 * shapes is at best unpleasant and at worst a trigger.
 */
function useConfetti(ref: React.RefObject<HTMLCanvasElement>): void {
  useEffect(() => {
    const canvas = ref.current;
    if (!canvas || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    const context = canvas.getContext('2d');
    if (!context) {
      return;
    }

    const dpr = window.devicePixelRatio || 1;
    canvas.width = canvas.offsetWidth * dpr;
    canvas.height = canvas.offsetHeight * dpr;
    context.scale(dpr, dpr);

    const colours = ['#00E676', '#FF6D00', '#40C4FF', '#B388FF', '#FFB300'];
    const pieces = Array.from({ length: 90 }, () => ({
      x: Math.random() * canvas.offsetWidth,
      y: -20 - Math.random() * canvas.offsetHeight,
      size: 5 + Math.random() * 6,
      speed: 1.5 + Math.random() * 2.5,
      drift: -0.6 + Math.random() * 1.2,
      spin: -0.1 + Math.random() * 0.2,
      angle: Math.random() * Math.PI,
      colour: colours[Math.floor(Math.random() * colours.length)],
    }));

    let frame = 0;
    let raf = 0;

    const draw = () => {
      frame += 1;
      context.clearRect(0, 0, canvas.offsetWidth, canvas.offsetHeight);

      for (const piece of pieces) {
        piece.y += piece.speed;
        piece.x += piece.drift;
        piece.angle += piece.spin;

        context.save();
        context.translate(piece.x, piece.y);
        context.rotate(piece.angle);
        context.fillStyle = piece.colour;
        context.fillRect(-piece.size / 2, -piece.size / 2, piece.size, piece.size * 0.6);
        context.restore();
      }

      // Roughly five seconds at 60fps, then stop — a permanently animating
      // canvas keeps a phone's GPU (and battery) busy for no reason.
      if (frame < 300) {
        raf = window.requestAnimationFrame(draw);
      }
    };

    raf = window.requestAnimationFrame(draw);

    return () => window.cancelAnimationFrame(raf);
  }, [ref]);
}
