import { useState } from 'react';
import { ExerciseEditor, type ExerciseRow } from '@shared/components/ExerciseEditor';
import { Button, Skeleton } from '@shared/components/ui';
import { useAdminRecord } from '../api/queries';
import type { AdminRow } from '../api/types';

/**
 * The workout editor with its nested exercise list (plans/05-admin-app.md, W2.5).
 *
 * 05 calls the prototype's nested editor "its best screen", and it is — but its
 * save was a **delete-all-and-reinsert**: `delete ex.id` on every row, then add
 * them back. `fc_exercise_logs.exercise_id` points at those ids, so every past
 * session silently lost its link to the movement it recorded and a member's
 * "Bench Press over time" chart emptied out.
 *
 * The list itself is `@shared/components/ExerciseEditor` as of W3.4 slice 3 —
 * the trainer's workout builder posts the same rows against the same
 * upsert-by-id rule, and this form keeps only the metadata around it.
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
  const [exercises, setExercises] = useState<ExerciseRow[] | null>(null);

  // Seed once the record arrives; `??` rather than an effect so there is no
  // frame where the form renders empty over loaded data.
  const value = (key: string): string => {
    if (key in fields) {
      return String(fields[key] ?? '');
    }

    return String(source?.[key] ?? '');
  };

  const list: ExerciseRow[] = exercises ?? (source?.exercises as ExerciseRow[] | undefined) ?? [];

  const set = (key: string, next: unknown) => setFields((f) => ({ ...f, [key]: next }));

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

      <ExerciseEditor value={list} onChange={setExercises} />

      {error && <p className="fc-text-sm fc-text-danger fc-mt-8">{error}</p>}

      <div className="fc-mt-16">
        <Button variant="primary" block type="submit" disabled={nameMissing || saving}>
          {saving ? 'Saving…' : row ? 'Save workout' : 'Create workout'}
        </Button>
      </div>
    </form>
  );
}
