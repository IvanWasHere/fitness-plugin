import { useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { Button } from '@shared/components/ui';

/**
 * One row of a workout's exercise list.
 *
 * `id` is optional and load-bearing: a row that has one is an existing
 * `fc_exercises` record and travels back to the server with it, a row without
 * one is new. See the editor's docblock for why that matters.
 */
export interface ExerciseRow {
  id?: number;
  exercise_name: string;
  exercise_type?: string;
  muscle_groups?: string[];
  instructions?: string | null;
  notes?: string | null;
  video_url?: string | null;
  thumbnail_url?: string | null;
  default_sets?: number;
  default_reps?: number;
  default_weight_kg?: number;
  default_rest_seconds?: number;
  metric?: string;
  order_index?: number;
}

const DEFAULT_ROW: ExerciseRow = {
  exercise_name: '',
  default_sets: 3,
  default_reps: 10,
  default_weight_kg: 0,
  default_rest_seconds: 60,
  metric: 'reps',
};

/**
 * The ordered exercise list, with drag reorder (W2.5, shared in W3.4 slice 3).
 *
 * ## Ids are carried through the form
 *
 * This is the whole reason the component is shaped the way it is. The
 * prototype's editor did `delete ex.id` on every row and re-inserted the lot, so
 * every `fc_exercise_logs.exercise_id` pointing at those rows was orphaned and a
 * member's "Bench Press over time" chart silently emptied. Rows keep their ids;
 * the server updates what has one, inserts what does not, and deletes only what
 * the payload actually dropped.
 *
 * ## Order is the array order
 *
 * Reordering needs no endpoint and no `order_index` input: the server writes
 * each row's position from its index in the submitted list.
 *
 * ## Why this is shared rather than copied
 *
 * The admin resource editor and the trainer's workout builder post to different
 * routes but both land on `AdminResourceService::update`, against the same
 * upsert-by-id rule. That rule is subtle enough that a second copy of the form
 * would eventually drift from it — the same argument that put `replaceExercises`
 * in one place on the server and `ProgressChartGrid` in one place on the client.
 */
export function ExerciseEditor({
  value,
  onChange,
  readOnly = false,
}: {
  value: ExerciseRow[];
  onChange: (next: ExerciseRow[]) => void;
  readOnly?: boolean;
}) {
  const [dragging, setDragging] = useState<number | null>(null);

  const setExercise = (index: number, patch: Partial<ExerciseRow>) =>
    onChange(value.map((item, i) => (i === index ? { ...item, ...patch } : item)));

  const move = (from: number, to: number) => {
    if (to < 0 || to >= value.length || from === to) {
      return;
    }

    const next = [...value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    onChange(next);
  };

  return (
    <>
      <h4 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-12">Exercises ({value.length})</h4>

      <ol className="fc-exercise-editor">
        {value.map((exercise, index) => (
          <li
            key={exercise.id ?? `new-${index}`}
            draggable={!readOnly}
            onDragStart={() => setDragging(index)}
            onDragEnd={() => setDragging(null)}
            onDragOver={(event) => event.preventDefault()}
            onDrop={() => {
              if (dragging !== null) {
                move(dragging, index);
                setDragging(null);
              }
            }}
            className={dragging === index ? 'fc-dragging' : ''}
          >
            <span className="fc-drag-handle" aria-hidden="true">
              <Icon name="menu" size={14} />
            </span>

            <input
              aria-label={`Exercise ${index + 1} name`}
              value={exercise.exercise_name ?? ''}
              placeholder="Exercise name"
              disabled={readOnly}
              onChange={(e) => setExercise(index, { exercise_name: e.target.value })}
            />

            <input
              aria-label="Sets"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_sets ?? 3}
              disabled={readOnly}
              onChange={(e) => setExercise(index, { default_sets: Number(e.target.value) })}
            />
            <input
              aria-label="Reps"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_reps ?? 10}
              disabled={readOnly}
              onChange={(e) => setExercise(index, { default_reps: Number(e.target.value) })}
            />
            <input
              aria-label="Weight (kg)"
              type="number"
              step="0.5"
              className="fc-input-tiny"
              value={exercise.default_weight_kg ?? 0}
              disabled={readOnly}
              onChange={(e) => setExercise(index, { default_weight_kg: Number(e.target.value) })}
            />
            {/* Rest and metric are W2.5 additions: the prototype's editor had
                neither, and the player cannot run a workout without them. */}
            <input
              aria-label="Rest (seconds)"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_rest_seconds ?? 60}
              disabled={readOnly}
              onChange={(e) => setExercise(index, { default_rest_seconds: Number(e.target.value) })}
            />
            <select
              aria-label="Metric"
              value={exercise.metric ?? 'reps'}
              disabled={readOnly}
              onChange={(e) => setExercise(index, { metric: e.target.value })}
            >
              <option value="reps">reps</option>
              <option value="seconds">seconds</option>
              <option value="distance">distance</option>
            </select>

            {/* Keyboard equivalents for the drag handle: a drag-only reorder is
                unusable without a mouse. */}
            <button
              type="button"
              className="fc-btn fc-btn--icon"
              aria-label={`Move ${exercise.exercise_name || 'exercise'} up`}
              disabled={readOnly || index === 0}
              onClick={() => move(index, index - 1)}
            >
              <Icon name="arrowUp" size={12} />
            </button>
            <button
              type="button"
              className="fc-btn fc-btn--icon"
              aria-label={`Move ${exercise.exercise_name || 'exercise'} down`}
              disabled={readOnly || index === value.length - 1}
              onClick={() => move(index, index + 1)}
            >
              <Icon name="arrowDown" size={12} />
            </button>
            <button
              type="button"
              className="fc-btn fc-btn--icon fc-btn--danger"
              aria-label={`Remove ${exercise.exercise_name || 'exercise'}`}
              disabled={readOnly}
              onClick={() => onChange(value.filter((_, i) => i !== index))}
            >
              <Icon name="x" size={12} />
            </button>
          </li>
        ))}
      </ol>

      {!readOnly && (
        <Button icon="plus" onClick={() => onChange([...value, { ...DEFAULT_ROW }])}>
          Add exercise
        </Button>
      )}
    </>
  );
}
