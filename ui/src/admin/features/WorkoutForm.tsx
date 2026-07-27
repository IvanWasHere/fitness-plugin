import { useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { Button, Skeleton } from '@shared/components/ui';
import { useAdminRecord } from '../api/queries';
import type { AdminRow, WorkoutExerciseRow } from '../api/types';

/**
 * The workout editor with its nested exercise list (plans/05-admin-app.md, W2.5).
 *
 * 05 calls the prototype's nested editor "its best screen", and it is — but its
 * save was a **delete-all-and-reinsert**: `delete ex.id` on every row, then add
 * them back. `fc_exercise_logs.exercise_id` points at those ids, so every past
 * session silently lost its link to the movement it recorded and a member's
 * "Bench Press over time" chart emptied out.
 *
 * So ids are carried through the form and submitted with each row. The server
 * updates rows that have one, inserts rows that do not, and deletes only what
 * the payload actually dropped.
 *
 * **Order is the array order.** Reordering needs no endpoint and no
 * `order_index` field in the UI: the server writes each row's position from its
 * index in the submitted list.
 */
export function WorkoutForm({
  row,
  onSubmit,
  saving,
  error,
}: {
  row: AdminRow | null;
  onSubmit: (payload: Record<string, unknown>) => void;
  saving: boolean;
  error: string | null;
}) {
  // The list row carries counts but not the exercises themselves — fetching the
  // single record is what loads them.
  const { data: full, isLoading } = useAdminRecord('workouts', row ? row.id : null);
  const source = full ?? row;

  const [fields, setFields] = useState<Record<string, unknown>>({});
  const [exercises, setExercises] = useState<WorkoutExerciseRow[] | null>(null);
  const [dragging, setDragging] = useState<number | null>(null);

  // Seed once the record arrives; `??` rather than an effect so there is no
  // frame where the form renders empty over loaded data.
  const value = (key: string): string => {
    if (key in fields) {
      return String(fields[key] ?? '');
    }

    return String(source?.[key] ?? '');
  };

  const list: WorkoutExerciseRow[] =
    exercises ?? (source?.exercises as WorkoutExerciseRow[] | undefined) ?? [];

  const set = (key: string, next: unknown) => setFields((f) => ({ ...f, [key]: next }));

  const setExercise = (index: number, patch: Partial<WorkoutExerciseRow>) =>
    setExercises(list.map((item, i) => (i === index ? { ...item, ...patch } : item)));

  const move = (from: number, to: number) => {
    if (to < 0 || to >= list.length || from === to) {
      return;
    }

    const next = [...list];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setExercises(next);
  };

  if (row && isLoading) {
    return <Skeleton height={280} />;
  }

  const nameMissing = value('workout_name').trim() === '';

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();

        if (nameMissing || saving) {
          return;
        }

        onSubmit({
          ...fields,
          workout_name: value('workout_name'),
          // Ids travel with the rows. This is the whole fix.
          exercises: list,
        });
      }}
    >
      <div className="fc-admin-form-grid">
        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-name">
            Name <span aria-hidden="true">*</span>
          </label>
          <input
            id="fc-w-name"
            value={value('workout_name')}
            onChange={(e) => set('workout_name', e.target.value)}
          />
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-type">
            Type
          </label>
          <select
            id="fc-w-type"
            value={value('workout_type')}
            onChange={(e) => set('workout_type', e.target.value)}
          >
            {['strength', 'cardio', 'hiit', 'flexibility', 'recovery'].map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-diff">
            Difficulty
          </label>
          <select
            id="fc-w-diff"
            value={value('difficulty')}
            onChange={(e) => set('difficulty', e.target.value)}
          >
            {['beginner', 'intermediate', 'advanced'].map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-duration">
            Duration (min)
          </label>
          <input
            id="fc-w-duration"
            type="number"
            value={value('estimated_duration_minutes')}
            onChange={(e) => set('estimated_duration_minutes', e.target.value)}
          />
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-muscles">
            Muscle groups
          </label>
          <input
            id="fc-w-muscles"
            placeholder="Chest, Triceps"
            value={
              'muscle_groups' in fields
                ? String(fields.muscle_groups ?? '')
                : ((source?.muscle_groups as string[] | undefined) ?? []).join(', ')
            }
            onChange={(e) => set('muscle_groups', e.target.value)}
          />
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-w-active">
            Active
          </label>
          <input
            id="fc-w-active"
            type="checkbox"
            checked={'is_active' in fields ? Boolean(fields.is_active) : Boolean(source?.is_active)}
            onChange={(e) => set('is_active', e.target.checked)}
          />
        </div>
      </div>

      <h4 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-12">Exercises ({list.length})</h4>

      <ol className="fc-exercise-editor">
        {list.map((exercise, index) => (
          <li
            key={exercise.id ?? `new-${index}`}
            draggable
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
              onChange={(e) => setExercise(index, { exercise_name: e.target.value })}
            />

            <input
              aria-label="Sets"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_sets ?? 3}
              onChange={(e) => setExercise(index, { default_sets: Number(e.target.value) })}
            />
            <input
              aria-label="Reps"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_reps ?? 10}
              onChange={(e) => setExercise(index, { default_reps: Number(e.target.value) })}
            />
            <input
              aria-label="Weight (kg)"
              type="number"
              step="0.5"
              className="fc-input-tiny"
              value={exercise.default_weight_kg ?? 0}
              onChange={(e) => setExercise(index, { default_weight_kg: Number(e.target.value) })}
            />
            {/* Rest and metric are W2.5 additions: the prototype's editor had
                neither, and the player cannot run a workout without them. */}
            <input
              aria-label="Rest (seconds)"
              type="number"
              className="fc-input-tiny"
              value={exercise.default_rest_seconds ?? 60}
              onChange={(e) => setExercise(index, { default_rest_seconds: Number(e.target.value) })}
            />
            <select
              aria-label="Metric"
              value={exercise.metric ?? 'reps'}
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
              disabled={index === 0}
              onClick={() => move(index, index - 1)}
            >
              <Icon name="arrowUp" size={12} />
            </button>
            <button
              type="button"
              className="fc-btn fc-btn--icon"
              aria-label={`Move ${exercise.exercise_name || 'exercise'} down`}
              disabled={index === list.length - 1}
              onClick={() => move(index, index + 1)}
            >
              <Icon name="arrowDown" size={12} />
            </button>
            <button
              type="button"
              className="fc-btn fc-btn--icon fc-btn--danger"
              aria-label={`Remove ${exercise.exercise_name || 'exercise'}`}
              onClick={() => setExercises(list.filter((_, i) => i !== index))}
            >
              <Icon name="x" size={12} />
            </button>
          </li>
        ))}
      </ol>

      <Button
        icon="plus"
        onClick={() =>
          setExercises([
            ...list,
            {
              exercise_name: '',
              default_sets: 3,
              default_reps: 10,
              default_weight_kg: 0,
              default_rest_seconds: 60,
              metric: 'reps',
            },
          ])
        }
      >
        Add exercise
      </Button>

      {error && <p className="fc-text-sm fc-text-danger fc-mt-8">{error}</p>}

      <div className="fc-mt-16">
        <Button variant="primary" block type="submit" disabled={nameMissing || saving}>
          {saving ? 'Saving…' : row ? 'Save workout' : 'Create workout'}
        </Button>
      </div>
    </form>
  );
}
