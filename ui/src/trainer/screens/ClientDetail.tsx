import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  LazyProgressCharts,
  type ProgressChartData,
} from '@shared/components/charts/ProgressChartGrid';
import { useThreads } from '@shared/api/messages';
import { Conversation } from '@shared/features/Messages';
import { Icon } from '@shared/components/Icon';
import { Modal } from '@shared/components/Modal';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Skeleton,
  Tag,
} from '@shared/components/ui';
import { useSession } from '@shared/session-context';
import {
  useClient,
  useClientActions,
  useClientNotes,
  useClientNutrition,
  useClientProgress,
  useClientSessions,
  useTrainerWorkouts,
} from '../api/queries';
import type { Assignment, ClientDetail as Client } from '../api/types';

/**
 * The client record — seven tabs (plans/06-trainer-app.md §2, W3.4).
 *
 * 06 calls this "the app's centre of gravity", and the multi-trainer rules are
 * what make it more than a profile page:
 *
 *   - **Overview, Progress, Workouts, Nutrition, Health** are shared-read: every
 *     coach on this client sees them, and every assignment says who made it.
 *   - **Messages** is your thread only.
 *   - **Notes** are private to you and to nobody else (Q15).
 *
 * Tab data loads only when its tab is open. Seven eager queries for a screen
 * where a trainer looks at one of them is six wasted requests.
 */
type TabKey = 'overview' | 'progress' | 'workouts' | 'nutrition' | 'health' | 'messages' | 'notes';

const TABS: Array<{ key: TabKey; label: string }> = [
  { key: 'overview', label: 'Overview' },
  { key: 'progress', label: 'Progress' },
  { key: 'workouts', label: 'Workouts' },
  { key: 'nutrition', label: 'Nutrition' },
  { key: 'health', label: 'Health' },
  { key: 'messages', label: 'Messages' },
  { key: 'notes', label: 'Notes' },
];

export function ClientDetail() {
  const { id } = useParams();
  const clientId = Number(id ?? 0);
  const [tab, setTab] = useState<TabKey>('overview');

  const { data, isLoading, error, refetch } = useClient(clientId);

  if (error) {
    return (
      <>
        <PageHeader title="Client" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Client" />
        <Skeleton height={280} />
      </>
    );
  }

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <Link to="/clients" className="fc-link fc-text-xs">
            ← All clients
          </Link>
          <h1>{data.display_name ?? `Client #${data.user_id}`}</h1>
          <p className="fc-text-sm fc-text-muted">
            {data.email} · {data.fitness_goal ?? 'no goal set'}
          </p>
        </div>
      </header>

      {/* Shown above the tabs, not buried in one: who else is coaching this
          person changes how you read everything below. */}
      <CoTrainers client={data} />

      {/* `fc-tabstrip`, not `fc-tabs`: the latter is the auth panel's segmented
          control, and sharing the name overwrote the login and registration
          tabs. */}
      <div className="fc-tabstrip fc-mb-16" role="tablist">
        {TABS.map((entry) => (
          <button
            key={entry.key}
            type="button"
            role="tab"
            aria-selected={tab === entry.key}
            className="fc-tab"
            onClick={() => setTab(entry.key)}
          >
            {entry.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <Overview client={data} />}
      {tab === 'progress' && <ProgressTab clientId={clientId} client={data} />}
      {tab === 'workouts' && <WorkoutsTab clientId={clientId} client={data} />}
      {tab === 'nutrition' && <NutritionTab clientId={clientId} />}
      {tab === 'health' && <HealthTab clientId={clientId} />}
      {tab === 'messages' && <MessagesTab clientId={clientId} />}
      {tab === 'notes' && <NotesTab clientId={clientId} />}
    </>
  );
}

function CoTrainers({ client }: { client: Client }) {
  const { boot } = useSession();

  const others = client.trainers.filter((trainer) => trainer.trainer_id !== boot.user?.trainer_id);

  if (others.length === 0) {
    return null;
  }

  return (
    <div className="fc-admin-notice fc-mb-16">
      <span aria-hidden="true">👥</span> Also coached by{' '}
      {others.map((trainer) => trainer.display_name).join(', ')}. You can see their programming;
      only they can change it.
    </div>
  );
}

function Overview({ client }: { client: Client }) {
  return (
    <div className="fc-grid fc-grid-2 fc-gap-16">
      <Card>
        <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Profile</h3>
        <ul className="fc-measure-grid">
          <Fact label="Goal" value={client.fitness_goal} />
          <Fact label="Level" value={client.fitness_level} />
          <Fact label="Activity" value={client.activity_level} />
          <Fact label="Height" value={client.height_cm ? `${client.height_cm} cm` : null} />
          <Fact label="Weight" value={client.weight_kg ? `${client.weight_kg} kg` : null} />
          <Fact
            label="Target"
            value={client.target_weight_kg ? `${client.target_weight_kg} kg` : null}
          />
        </ul>
      </Card>

      <Card>
        <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Coaching</h3>
        <ul className="fc-history-list">
          {client.trainers.map((trainer) => (
            <li key={trainer.trainer_id}>
              {/* This row reads name-first, unlike the date-first lists the
                  positional rule was written for, so it names its own growing
                  element instead of letting `nth-child(2)` guess. */}
              <span className="fc-history-list__grow">{trainer.display_name}</span>
              {trainer.is_primary && <Tag tone="green">primary</Tag>}
              <span className="fc-text-xs fc-text-muted">since {trainer.assigned_date}</span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string | null }) {
  return (
    <li>
      <span className="fc-text-xs fc-text-muted">{label}</span>
      <strong>{value ?? '—'}</strong>
    </li>
  );
}

/**
 * The client's progress, drawn with the member app's own charts.
 *
 * The endpoint is the member's `/progress` after a roster check, so the trainer
 * gets the identical series. Only the empty-state copy differs — `subject` names
 * the client, because "log a weight on the Health screen" is advice the trainer
 * cannot take.
 */
function ProgressTab({ clientId, client }: { clientId: number; client: Client }) {
  const [range, setRange] = useState('month');
  const { data, isLoading, isFetching } = useClientProgress(clientId, range, true);

  if (isLoading || !data) {
    return <Skeleton height={220} />;
  }

  const name = (client.display_name ?? 'This client').split(' ')[0];

  return (
    <>
      <div className="fc-flex fc-flex-c fc-gap-12 fc-mb-16">
        <div className="fc-layout-toggle" role="group" aria-label="Range">
          {['week', 'month', 'quarter', 'year'].map((option) => (
            <button
              key={option}
              type="button"
              className={range === option ? 'active' : ''}
              aria-pressed={range === option}
              onClick={() => setRange(option)}
            >
              {option}
            </button>
          ))}
        </div>
        <span className="fc-text-xs fc-text-muted">
          {data.from} to {data.to} · {BUCKET_NOTE[data.bucket]}
        </span>
      </div>

      {/* Dimmed rather than unmounted between ranges: swapping four charts for
          four spinners on every click reads as the page breaking. */}
      <div className={isFetching ? 'fc-refreshing' : undefined}>
        <LazyProgressCharts data={data satisfies ProgressChartData} subject={name} />

        <div className="fc-grid fc-grid-3 fc-gap-16">
          <Card>
            <div className="fc-stat__value">{data.totals.workouts_completed}</div>
            <div className="fc-stat__label">Workouts completed</div>
          </Card>
          <Card>
            <div className="fc-stat__value">{data.totals.total_minutes}</div>
            <div className="fc-stat__label">Minutes trained</div>
          </Card>
          <Card>
            <div className="fc-stat__value">{data.totals.personal_records}</div>
            <div className="fc-stat__label">Personal records</div>
          </Card>
        </div>
      </div>
    </>
  );
}

const BUCKET_NOTE: Record<'day' | 'week' | 'month', string> = {
  day: 'daily',
  week: 'weekly averages',
  month: 'monthly averages',
};

/**
 * Every trainer's assignments, attributed — and only yours are removable.
 *
 * The disabled remove button on a co-trainer's row is the visible half of Q13:
 * you can see their programming, and you cannot undo it. Hiding the row instead
 * would defeat the purpose; hiding the button would leave the trainer guessing
 * why nothing happens.
 */
function WorkoutsTab({ clientId, client }: { clientId: number; client: Client }) {
  const { boot } = useSession();
  const { data: sessions, isLoading } = useClientSessions(clientId, true);
  const { assign, unassign } = useClientActions(clientId);
  const [assigning, setAssigning] = useState(false);

  const myAccountId = boot.user?.account_id ?? 0;

  return (
    <>
      <div className="fc-flex fc-flex-between fc-flex-c fc-mb-16">
        <h3 className="fc-text-lg">Assigned workouts</h3>
        <Button variant="primary" icon="plus" onClick={() => setAssigning(true)}>
          Assign a workout
        </Button>
      </div>

      {client.assignments.length === 0 ? (
        <EmptyState title="Nothing assigned">
          Assign a workout and it appears on the client&rsquo;s home screen.
        </EmptyState>
      ) : (
        <div className="fc-flex fc-flex-column fc-gap-8 fc-mb-24">
          {client.assignments.map((assignment) => (
            <AssignmentRow
              key={assignment.id}
              assignment={assignment}
              mine={assignment.assigned_by.account_id === myAccountId}
              busy={unassign.isPending}
              onRemove={() => unassign.mutate(assignment.id)}
            />
          ))}
        </div>
      )}

      {unassign.error && <p className="fc-text-sm fc-text-danger">{unassign.error.message}</p>}

      <h3 className="fc-text-lg fc-mb-16">Session history</h3>

      {isLoading && <Skeleton height={140} />}

      {!isLoading && (sessions?.items.length ?? 0) === 0 && (
        <EmptyState title="No sessions yet">
          Completed workouts show up here with duration and effort.
        </EmptyState>
      )}

      {(sessions?.items ?? []).length > 0 && (
        <Card>
          <ul className="fc-history-list fc-history-list--tall">
            {(sessions?.items ?? []).map((session) => (
              <li key={session.id}>
                <span className="fc-text-xs fc-text-muted">{session.log_date}</span>
                <span>{session.workout_name ?? '—'}</span>
                <Tag tone={session.status === 'completed' ? 'green' : 'orange'}>
                  {session.status}
                </Tag>
                <span className="fc-text-xs fc-text-muted">
                  {Math.round(session.duration_seconds / 60)} min
                  {session.perceived_exertion ? ` · RPE ${session.perceived_exertion}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {assigning && (
        <AssignModal
          onClose={() => setAssigning(false)}
          onAssign={(workoutId, scheduledFor) =>
            assign.mutate(
              { workout_id: workoutId, scheduled_for: scheduledFor || undefined },
              { onSuccess: () => setAssigning(false) },
            )
          }
          busy={assign.isPending}
          error={assign.error?.message ?? null}
          warnings={assign.data?.warnings ?? []}
        />
      )}
    </>
  );
}

function AssignmentRow({
  assignment,
  mine,
  busy,
  onRemove,
}: {
  assignment: Assignment;
  mine: boolean;
  busy: boolean;
  onRemove: () => void;
}) {
  return (
    <Card>
      <div className="fc-flex fc-flex-c fc-gap-12">
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="fc-font-bold fc-truncate">{assignment.workout_name}</div>
          <div className="fc-text-xs fc-text-muted">
            {assignment.scheduled_for ?? 'unscheduled'} · {assignment.difficulty}
            {' · '}
            {mine ? (
              'assigned by you'
            ) : (
              <strong>assigned by {assignment.assigned_by.display_name ?? 'another coach'}</strong>
            )}
          </div>
        </div>

        <Tag tone={assignment.status === 'completed' ? 'green' : 'blue'}>{assignment.status}</Tag>

        <button
          type="button"
          className="fc-btn fc-btn--icon fc-btn--danger"
          disabled={!mine || busy}
          // Disabled rather than hidden: a trainer who cannot remove a row
          // should be told why, not left clicking at nothing.
          title={mine ? 'Remove assignment' : 'Only the coach who assigned this can remove it'}
          aria-label={mine ? 'Remove assignment' : 'Assigned by another coach'}
          onClick={onRemove}
        >
          <Icon name="x" size={14} />
        </button>
      </div>
    </Card>
  );
}

function AssignModal({
  onClose,
  onAssign,
  busy,
  error,
  warnings,
}: {
  onClose: () => void;
  onAssign: (workoutId: number, scheduledFor: string) => void;
  busy: boolean;
  error: string | null;
  warnings: Array<{ code: string; message: string }>;
}) {
  const { data, isLoading } = useTrainerWorkouts();
  const [workoutId, setWorkoutId] = useState(0);
  const [scheduledFor, setScheduledFor] = useState('');

  return (
    <Modal
      title="Assign a workout"
      variant="form"
      confirmLabel={busy ? 'Assigning…' : 'Assign'}
      confirmDisabled={workoutId === 0 || busy}
      onCancel={onClose}
      onConfirm={() => onAssign(workoutId, scheduledFor)}
    >
      {isLoading && <Skeleton height={100} />}

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-assign-workout">
          Workout
        </label>
        <select
          id="fc-assign-workout"
          value={workoutId}
          onChange={(event) => setWorkoutId(Number(event.target.value))}
        >
          <option value={0}>Choose…</option>
          {(data?.items ?? []).map((workout) => (
            <option key={workout.id} value={workout.id}>
              {workout.workout_name}
              {workout.is_platform ? ' (library)' : ''}
            </option>
          ))}
        </select>
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-assign-date">
          Scheduled for
        </label>
        <input
          id="fc-assign-date"
          type="date"
          value={scheduledFor}
          onChange={(event) => setScheduledFor(event.target.value)}
        />
        <span className="fc-text-xs fc-text-muted">Leave blank to assign without a date.</span>
      </div>

      {/* Non-blocking by design: another coach's week is a thing to know, not a
          thing to be stopped by. The assignment has already been made when this
          shows. */}
      {warnings.map((warning) => (
        <p key={warning.message} className="fc-admin-notice fc-text-sm">
          <span aria-hidden="true">⚠</span> {warning.message}
        </p>
      ))}

      {error && <p className="fc-text-sm fc-text-danger">{error}</p>}
    </Modal>
  );
}

function NutritionTab({ clientId }: { clientId: number }) {
  const { data, isLoading } = useClientNutrition(clientId, true);

  if (isLoading || !data) {
    return <Skeleton height={180} />;
  }

  // The gate is the *client's* entitlement, not the trainer's: nutrition data
  // only exists if their plan let them log it.
  if (!data.available) {
    return (
      <EmptyState title="Not on this client's plan">
        Nutrition logging is not included in their subscription, so there is nothing recorded to
        show.
      </EmptyState>
    );
  }

  if (data.items.length === 0) {
    return <EmptyState title="Nothing logged">No meals recorded in the last month.</EmptyState>;
  }

  return (
    <Card>
      <ul className="fc-history-list fc-history-list--tall">
        {data.items.map((day) => (
          <li key={day.date}>
            <span className="fc-text-xs fc-text-muted">{day.date}</span>
            <span>
              {day.meals} meal{day.meals === 1 ? '' : 's'}
            </span>
            <strong>{day.calories} kcal</strong>
            <span className="fc-text-xs fc-text-muted">
              {Math.round(day.protein_g)}p / {Math.round(day.carbs_g)}c / {Math.round(day.fat_g)}f
            </span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/**
 * Health trends, from what the progress endpoint already returns.
 *
 * 06 asks for weight, body fat, sleep and blood pressure here, gated on
 * entitlement and with the access logged. Only the first two are available:
 * there is no `/trainer/clients/{id}/health` endpoint in the contract, and the
 * access logging that reading somebody's blood pressure ought to carry does not
 * exist yet either. Showing the two series the API does return is honest;
 * inventing an endpoint without the logging requirement would not be.
 */
function HealthTab({ clientId }: { clientId: number }) {
  const { data, isLoading } = useClientProgress(clientId, 'quarter', true);

  if (isLoading || !data) {
    return <Skeleton height={180} />;
  }

  const labels = (data.labels ?? []) as string[];
  const weight = (data.weight ?? []) as Array<number | null>;
  const bodyFat = (data.body_fat ?? []) as Array<number | null>;

  const points = labels
    .map((label, index) => ({ label, weight: weight[index], bodyFat: bodyFat[index] }))
    .filter((point) => point.weight !== null || point.bodyFat !== null);

  if (points.length === 0) {
    return (
      <EmptyState title="Nothing recorded">This client has logged no measurements.</EmptyState>
    );
  }

  return (
    <>
      <Card className="fc-mb-16">
        <ul className="fc-history-list fc-history-list--tall">
          {points.map((point) => (
            <li key={point.label}>
              <span className="fc-text-xs fc-text-muted">{point.label}</span>
              <span>{point.weight !== null ? `${point.weight} kg` : '—'}</span>
              <span className="fc-text-xs fc-text-muted">
                {point.bodyFat !== null ? `${point.bodyFat}% body fat` : ''}
              </span>
            </li>
          ))}
        </ul>
      </Card>

      <p className="fc-text-xs fc-text-muted">
        Sleep, resting heart rate and blood pressure are recorded by the client but are not shown
        here yet — that needs an endpoint with its own access logging.
      </p>
    </>
  );
}

/**
 * The trainer's own thread with this client, inline (06 §2, W3.4 slice 3).
 *
 * Messaging reuses W3.1's API unchanged — a trainer's thread with this client is
 * the same resource the member sees from the other side — and the pane is the
 * same `Conversation` the standalone Messages screen renders.
 *
 * The thread is found by `user_id` rather than fetched by id, because there is
 * no "thread for this client" endpoint and the conversation list is already
 * polled and cached. It is **this trainer's thread only**: `GET /messages/threads`
 * is scoped to the caller, so a co-trainer's conversation with the same client
 * is not in the list to be found — which is the rule 06 states for this tab and
 * the one place in the client record where shared read does not apply.
 */
function MessagesTab({ clientId }: { clientId: number }) {
  const { data, isLoading } = useThreads();

  if (isLoading) {
    return <Skeleton height={280} />;
  }

  const thread = data?.items.find((item) => item.user_id === clientId) ?? null;

  if (!thread) {
    return (
      <EmptyState title="No conversation yet">
        A conversation opens when you accept a client. This one predates that, so there is no thread
        to write in — a message from them will not create one either.
      </EmptyState>
    );
  }

  return <Conversation thread={thread} counterpart="Client" />;
}

/**
 * Private notes (Q15).
 *
 * Not shared-read and not visible to the client. The copy says so, because a
 * trainer who is unsure whether a co-trainer can read this will write something
 * less useful — or nothing.
 */
function NotesTab({ clientId }: { clientId: number }) {
  const { data, isLoading } = useClientNotes(clientId, true);
  const { addNote, deleteNote } = useClientActions(clientId);
  const [draft, setDraft] = useState('');

  return (
    <>
      <p className="fc-text-sm fc-text-muted fc-mb-16">
        Only you can read these. Not the client, and not their other coaches.
      </p>

      <form
        className="fc-msg__composer fc-mb-16"
        onSubmit={(event) => {
          event.preventDefault();

          if (draft.trim() !== '' && !addNote.isPending) {
            addNote.mutate(draft.trim(), { onSuccess: () => setDraft('') });
          }
        }}
      >
        <input
          value={draft}
          placeholder="Add a note…"
          aria-label="New note"
          onChange={(event) => setDraft(event.target.value)}
        />
        <Button variant="primary" type="submit" disabled={draft.trim() === '' || addNote.isPending}>
          Add
        </Button>
      </form>

      {isLoading && <Skeleton height={120} />}

      {!isLoading && (data?.items.length ?? 0) === 0 && (
        <EmptyState title="No notes">Anything you record here stays with you.</EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {(data?.items ?? []).map((note) => (
          <Card key={note.id}>
            <div className="fc-flex fc-flex-c fc-gap-12">
              <div style={{ flex: 1, minWidth: 0 }}>
                <p>{note.body}</p>
                <span className="fc-text-xs fc-text-subtle">{note.created_at.slice(0, 10)}</span>
              </div>
              <button
                type="button"
                className="fc-btn fc-btn--icon fc-btn--danger"
                aria-label="Delete note"
                disabled={deleteNote.isPending}
                onClick={() => deleteNote.mutate(note.id)}
              >
                <Icon name="x" size={14} />
              </button>
            </div>
          </Card>
        ))}
      </div>
    </>
  );
}
