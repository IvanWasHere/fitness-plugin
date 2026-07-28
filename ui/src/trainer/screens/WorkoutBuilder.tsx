import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ExerciseEditor, type ExerciseRow } from '@shared/components/ExerciseEditor';
import { Button, Card, ErrorState, Skeleton } from '@shared/components/ui';
import { useTrainerWorkout, useWorkoutActions } from '../api/queries';

const TYPES = ['strength', 'cardio', 'hiit', 'flexibility', 'recovery'];
const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

/**
 * The workout builder (plans/06-trainer-app.md §4, W3.4 slice 3).
 *
 * 06 calls this "the highest-value screen in the trainer app". Metadata and the
 * ordered exercise list save **together**, in one `PUT /trainer/workouts/{id}`:
 * the alternative — metadata here, exercises through
 * `PUT /trainer/workouts/{id}/exercises` — is two requests that can half-succeed,
 * leaving a workout whose name says one thing and whose contents say another.
 *
 * The exercise list is `@shared/components/ExerciseEditor`, the same component
 * the admin editor uses, against the same upsert-by-id rule that keeps
 * `fc_exercise_logs` pointing at the movements they recorded.
 *
 * **Editing is refused before it is offered.** A platform workout is readable
 * here (it is what "Duplicate" reads) but the server would answer 403 to a save,
 * so the form is read-only and says why rather than letting a trainer type for a
 * minute into a request that cannot land.
 */
export function WorkoutBuilder() {
  const { id } = useParams();
  const workoutId = Number(id);
  const navigate = useNavigate();

  const { data, isLoading, error, refetch } = useTrainerWorkout(workoutId);
  const { update } = useWorkoutActions();

  const [fields, setFields] = useState<Record<string, unknown>>({});
  const [exercises, setExercises] = useState<ExerciseRow[] | null>(null);
  const [saved, setSaved] = useState(false);

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return <Skeleton height={420} />;
  }

  const readOnly = !data.is_mine;
  const list = exercises ?? data.exercises;

  // `??` against the loaded record rather than an effect that seeds state: there
  // is no frame where the form renders empty over data that has already arrived.
  const value = (key: keyof typeof data): string =>
    key in fields ? String(fields[key] ?? '') : String(data[key] ?? '');

  const set = (key: string, next: unknown) => {
    setSaved(false);
    setFields((current) => ({ ...current, [key]: next }));
  };

  const nameMissing = value('workout_name').trim() === '';
  const dirty = Object.keys(fields).length > 0 || exercises !== null;

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>{data.workout_name}</h1>
          <p className="fc-text-sm fc-text-muted">
            <Link to="/workouts">Workouts</Link> · {list.length} exercises
            {readOnly && ' · read-only'}
          </p>
        </div>

        {!readOnly && (
          <Button
            variant="primary"
            disabled={nameMissing || update.isPending || !dirty}
            onClick={() =>
              update.mutate(
                {
                  id: workoutId,
                  body: {
                    ...fields,
                    workout_name: value('workout_name'),
                    // Ids travel with the rows; order is the array order.
                    exercises: list,
                  },
                },
                {
                  onSuccess: () => {
                    setFields({});
                    setExercises(null);
                    setSaved(true);
                  },
                },
              )
            }
          >
            {update.isPending ? 'Saving…' : 'Save workout'}
          </Button>
        )}
      </header>

      {readOnly && (
        <Card className="fc-mb-16">
          <p className="fc-text-sm fc-text-muted">
            This is a platform workout. You can assign it to any client, but only an administrator
            can change it — duplicate it from the library to make a version of your own.
          </p>
        </Card>
      )}

      <Card>
        <div className="fc-admin-form-grid">
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-name">
              Name <span aria-hidden="true">*</span>
            </label>
            <input
              id="fc-b-name"
              value={value('workout_name')}
              disabled={readOnly}
              onChange={(event) => set('workout_name', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-type">
              Type
            </label>
            <select
              id="fc-b-type"
              value={value('workout_type')}
              disabled={readOnly}
              onChange={(event) => set('workout_type', event.target.value)}
            >
              {TYPES.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-diff">
              Difficulty
            </label>
            <select
              id="fc-b-diff"
              value={value('difficulty')}
              disabled={readOnly}
              onChange={(event) => set('difficulty', event.target.value)}
            >
              {DIFFICULTIES.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-duration">
              Duration (min)
            </label>
            <input
              id="fc-b-duration"
              type="number"
              min={0}
              value={value('estimated_duration_minutes')}
              disabled={readOnly}
              onChange={(event) => set('estimated_duration_minutes', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-muscles">
              Muscle groups
            </label>
            <input
              id="fc-b-muscles"
              placeholder="Chest, Triceps"
              value={
                'muscle_groups' in fields
                  ? String(fields.muscle_groups ?? '')
                  : data.muscle_groups.join(', ')
              }
              disabled={readOnly}
              onChange={(event) => set('muscle_groups', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-equipment">
              Equipment
            </label>
            <input
              id="fc-b-equipment"
              placeholder="Barbell, Bench"
              value={
                'equipment' in fields ? String(fields.equipment ?? '') : data.equipment.join(', ')
              }
              disabled={readOnly}
              onChange={(event) => set('equipment', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-video">
              Video URL
            </label>
            <input
              id="fc-b-video"
              type="url"
              value={value('video_url')}
              disabled={readOnly}
              onChange={(event) => set('video_url', event.target.value)}
            />
          </div>

          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-b-active">
              Active
            </label>
            <input
              id="fc-b-active"
              type="checkbox"
              checked={'is_active' in fields ? Boolean(fields.is_active) : data.is_active}
              disabled={readOnly}
              onChange={(event) => set('is_active', event.target.checked)}
            />
          </div>
        </div>

        <div className="fc-field">
          <label className="fc-label" htmlFor="fc-b-desc">
            Description
          </label>
          <textarea
            id="fc-b-desc"
            rows={3}
            value={value('description')}
            disabled={readOnly}
            onChange={(event) => set('description', event.target.value)}
          />
        </div>

        <ExerciseEditor
          value={list}
          readOnly={readOnly}
          onChange={(next) => {
            setSaved(false);
            setExercises(next);
          }}
        />

        {update.error && (
          <p className="fc-text-sm fc-text-danger fc-mt-8">{update.error.message}</p>
        )}

        {/* Confirmation is worth a line: with no toast, a save that changes
            nothing visible on screen is indistinguishable from one that failed. */}
        {saved && !dirty && (
          <p className="fc-text-sm fc-text-muted fc-mt-8" role="status">
            Saved.
          </p>
        )}

        {!readOnly && (
          <div className="fc-mt-16">
            <Button onClick={() => navigate('/workouts')}>Back to library</Button>
          </div>
        )}
      </Card>
    </>
  );
}
