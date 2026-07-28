import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Modal } from '@shared/components/Modal';
import { Button, Card, EmptyState, ErrorState, Skeleton, Tag } from '@shared/components/ui';
import { useTrainerWorkout, useTrainerWorkouts, useWorkoutActions } from '../api/queries';
import type { TrainerWorkout } from '../api/types';

/**
 * The workout library (plans/06-trainer-app.md §4, W3.4 slice 3).
 *
 * Two collections in one list, and the distinction is the screen's whole job:
 * a trainer's **own** workouts are editable, the **platform** library is
 * assignable by everyone and editable by nobody. Hiding the platform rows would
 * make the library look empty on day one — 06 is explicit that a trainer with no
 * workouts of their own must still be able to programme — so they are shown,
 * badged, and their edit control is a "Duplicate" instead of an "Edit".
 *
 * Duplicating is how a trainer starts from a platform workout: it copies the
 * metadata and the exercises **without their ids**, so the new rows are inserts
 * rather than an upsert that would move the platform's own exercises.
 */
export function Workouts() {
  const { data, isLoading, error, refetch } = useTrainerWorkouts();
  const { create, remove } = useWorkoutActions();

  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');
  const [copying, setCopying] = useState<TrainerWorkout | null>(null);
  const [deleting, setDeleting] = useState<TrainerWorkout | null>(null);

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Workouts</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  const items = data?.items ?? [];
  const mine = items.filter((workout) => workout.is_mine);

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Workouts</h1>
          <p className="fc-text-sm fc-text-muted">
            {isLoading ? 'Loading…' : `${mine.length} of your own · ${items.length} assignable`}
          </p>
        </div>

        <Button
          variant="primary"
          icon="plus"
          onClick={() => {
            setName('');
            setNaming(true);
          }}
        >
          New workout
        </Button>
      </header>

      {isLoading && <Skeleton height={240} />}

      {!isLoading && items.length === 0 && (
        <EmptyState title="Nothing to programme with yet">
          Create a workout, and it becomes assignable to your clients.
        </EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {items.map((workout) => (
          <Card key={workout.id}>
            <div className="fc-client-row">
              <span className="fc-client-row__body">
                <span className="fc-font-bold fc-truncate">
                  {workout.workout_name}
                  {workout.is_platform && <Tag tone="blue">platform</Tag>}
                  {!workout.is_active && <Tag>inactive</Tag>}
                </span>
                <span className="fc-text-xs fc-text-muted fc-truncate">
                  {workout.difficulty ?? 'any level'} · {workout.workout_type ?? 'general'} ·{' '}
                  {workout.exercise_count ?? 0} exercises ·{' '}
                  {workout.estimated_duration_minutes || '—'} min
                </span>
              </span>

              {workout.is_mine ? (
                <>
                  <Link className="fc-btn" to={`/workouts/${workout.id}`}>
                    Edit
                  </Link>
                  <Button
                    variant="danger"
                    onClick={() => setDeleting(workout)}
                    aria-label={`Delete ${workout.workout_name}`}
                  >
                    Delete
                  </Button>
                </>
              ) : (
                /* Read-only, so the action is to take a copy rather than to be
                   refused an edit that was never on offer. */
                <Button
                  onClick={() => {
                    setName(`${workout.workout_name} (copy)`);
                    setCopying(workout);
                  }}
                >
                  Duplicate
                </Button>
              )}
            </div>
          </Card>
        ))}
      </div>

      {naming && (
        <NameDialog
          title="New workout"
          name={name}
          saving={create.isPending}
          error={create.error?.message ?? null}
          onChange={setName}
          onCancel={() => setNaming(false)}
          onConfirm={() =>
            create.mutate({ workout_name: name.trim() }, { onSuccess: () => setNaming(false) })
          }
        />
      )}

      {copying && (
        <DuplicateDialog
          source={copying}
          name={name}
          onChange={setName}
          onDone={() => setCopying(null)}
        />
      )}

      {deleting && (
        <Modal
          title={`Delete ${deleting.workout_name}?`}
          confirmLabel={remove.isPending ? 'Deleting…' : 'Delete'}
          tone="danger"
          confirmDisabled={remove.isPending}
          onCancel={() => setDeleting(null)}
          onConfirm={() =>
            remove.mutate(deleting.id, {
              onSuccess: () => setDeleting(null),
            })
          }
        >
          {/* The server refuses a workout with logged sessions (409) rather than
              emptying somebody's history. Surfacing its message is better than
              pre-empting it here with a rule this screen would have to keep in
              step. */}
          {remove.error ? (
            <span className="fc-text-danger">{remove.error.message}</span>
          ) : (
            'Clients who have already trained on it keep their history — if any have, the delete is refused and you can deactivate it instead.'
          )}
        </Modal>
      )}
    </>
  );
}

function NameDialog({
  title,
  name,
  saving,
  error,
  onChange,
  onCancel,
  onConfirm,
}: {
  title: string;
  name: string;
  saving: boolean;
  error: string | null;
  onChange: (next: string) => void;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <Modal
      title={title}
      variant="form"
      confirmLabel={saving ? 'Creating…' : 'Create'}
      confirmDisabled={saving || name.trim() === ''}
      onCancel={onCancel}
      onConfirm={onConfirm}
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-new-name">
          Name <span aria-hidden="true">*</span>
        </label>
        <input id="fc-new-name" value={name} onChange={(event) => onChange(event.target.value)} />
      </div>

      {error && <p className="fc-text-sm fc-text-danger">{error}</p>}
    </Modal>
  );
}

/**
 * Copy a workout into the trainer's own library.
 *
 * The source's exercises are only known once the full record is fetched, so the
 * dialog loads it and keeps the confirm disabled until it has arrived — creating
 * from a half-loaded source would silently produce an empty workout.
 *
 * **Ids are stripped.** They belong to the source's rows; carrying them would
 * ask the server to update the platform's exercises onto the new workout. The
 * server scopes its upsert by `workout_id` and would treat them as new anyway,
 * but sending a foreign id and relying on that is not a contract worth having.
 */
function DuplicateDialog({
  source,
  name,
  onChange,
  onDone,
}: {
  source: TrainerWorkout;
  name: string;
  onChange: (next: string) => void;
  onDone: () => void;
}) {
  const { data: full, isLoading } = useTrainerWorkout(source.id);
  const { create } = useWorkoutActions();

  return (
    <Modal
      title={`Duplicate ${source.workout_name}`}
      variant="form"
      confirmLabel={create.isPending ? 'Copying…' : 'Duplicate'}
      confirmDisabled={isLoading || create.isPending || name.trim() === ''}
      onCancel={onDone}
      onConfirm={() => {
        if (!full) {
          return;
        }

        create.mutate(
          {
            workout_name: name.trim(),
            description: full.description,
            workout_type: full.workout_type,
            difficulty: full.difficulty,
            estimated_duration_minutes: full.estimated_duration_minutes,
            muscle_groups: full.muscle_groups,
            equipment: full.equipment,
            exercises: full.exercises.map(({ id: _id, ...rest }) => rest),
          },
          { onSuccess: onDone },
        );
      }}
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-copy-name">
          Name <span aria-hidden="true">*</span>
        </label>
        <input id="fc-copy-name" value={name} onChange={(event) => onChange(event.target.value)} />
      </div>

      <p className="fc-text-xs fc-text-muted">
        {isLoading
          ? 'Loading the exercises…'
          : `Copies ${full?.exercises.length ?? 0} exercises into your library. The original is untouched.`}
      </p>

      {create.error && <p className="fc-text-sm fc-text-danger">{create.error.message}</p>}
    </Modal>
  );
}
