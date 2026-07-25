import { useEffect, useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { Modal } from '@shared/components/Modal';
import { Button, ProgressBar } from '@shared/components/ui';
import { formatDuration, useCountdown, useElapsed } from '@shared/hooks/timers';
import { usePlayer } from '../../state/player-context';

/**
 * The full-screen workout player (plans/04-user-app.md#workout-player--the-hard-part).
 *
 * The one screen with real complexity, and the one the prototype got most wrong:
 * counters instead of timestamps, sets held in memory and never persisted,
 * read-only weights, and a native `confirm()` to stop.
 *
 * All four are fixed here — the timers derive from timestamps, every set goes
 * through the offline queue, weight and reps are editable at the moment of
 * lifting (which is when people actually change them), and stopping asks in a
 * dialog the app owns.
 */
export function Player() {
  const player = usePlayer();
  const { session, exercise } = player;

  if (!player.isOpen || !session || !exercise) {
    return null;
  }

  return <PlayerScreen />;
}

function PlayerScreen() {
  const {
    session,
    exercise,
    exerciseIndex,
    setIndex,
    completedSets,
    logCurrentSet,
    next,
    previous,
    pause,
    resume,
    complete,
    abandon,
    close,
    busy,
    queue,
    restEndsAt,
    skipRest,
    elapsedBase,
  } = usePlayer();

  const running = session?.status === 'in_progress';
  const elapsed = useElapsed(elapsedBase.seconds, elapsedBase.capturedAt, running);
  const restLeft = useCountdown(restEndsAt);

  const [reps, setReps] = useState('');
  const [weight, setWeight] = useState('');
  const [confirmStop, setConfirmStop] = useState(false);

  const isTimed = exercise?.metric === 'seconds';

  // Pre-fill from the last set of this exercise, else the plan. Someone who
  // dropped to 60 kg on set 2 means to stay there on set 3.
  useEffect(() => {
    if (!exercise) {
      return;
    }

    const previousSet = [...exercise.sets].sort((a, b) => b.set_index - a.set_index)[0];

    setReps(String(previousSet?.reps ?? exercise.planned_reps));
    setWeight(String(previousSet?.weight_kg ?? exercise.planned_weight_kg));
  }, [exercise]);

  // The rest countdown must announce itself — a phone on the floor with a silent
  // `0:00` on screen is the prototype's version, and it is useless.
  useEffect(() => {
    if (restLeft === 0) {
      navigator.vibrate?.([120, 60, 120]);
    }
  }, [restLeft]);

  if (!session || !exercise) {
    return null;
  }

  const totalSets = exercise.planned_sets;
  const doneInExercise = Array.from(
    { length: totalSets },
    (_, i) => completedSets[`${exercise.exercise_id}:${i}`],
  );
  const totalPlanned = session.exercises.reduce((sum, item) => sum + item.planned_sets, 0);
  const totalDone = Object.keys(completedSets).length;

  return (
    <section className="fc-player" aria-label="Workout player">
      <header className="fc-player__head">
        <Button
          variant="secondary"
          size="sm"
          icon="chevronLeft"
          onClick={close}
          aria-label="Minimise the player"
        >
          Back
        </Button>

        <div className="fc-text-center">
          <div className="fc-player__timer" aria-live="off">
            {formatDuration(elapsed)}
          </div>
          <div className="fc-text-xs fc-text-muted">
            {session.status === 'paused' ? 'Paused' : session.workout_name}
          </div>
        </div>

        <SyncBadge pending={queue.pending} offline={queue.offline} dropped={queue.dropped} />
      </header>

      <div className="fc-player__body">
        <ProgressBar value={totalPlanned > 0 ? (totalDone / totalPlanned) * 100 : 0} />
        <p className="fc-text-xs fc-text-muted fc-mt-8 fc-text-center">
          {totalDone} of {totalPlanned} sets · exercise {exerciseIndex + 1} of{' '}
          {session.exercises.length}
        </p>

        <div className="fc-player__exercise fc-mt-24">
          <h2>{exercise.exercise_name}</h2>
          <p className="fc-text-muted fc-text-sm fc-mt-4">
            {isTimed
              ? `${exercise.planned_sets} × ${exercise.planned_reps}s`
              : `${exercise.planned_sets} × ${exercise.planned_reps} @ ${exercise.planned_weight_kg} kg`}
          </p>
          {exercise.notes && <p className="fc-text-xs fc-text-subtle fc-mt-8">{exercise.notes}</p>}
        </div>

        {restEndsAt !== null && restLeft !== null && (
          <div className={`fc-rest ${restLeft <= 0 ? 'fc-rest--over' : ''}`} role="status">
            <div className="fc-text-xs">{restLeft > 0 ? 'Rest' : 'Rest over — next set'}</div>
            <div className="fc-rest__value">{formatDuration(Math.abs(restLeft))}</div>
            <button type="button" className="fc-link" onClick={skipRest}>
              {restLeft > 0 ? 'Skip rest' : 'Dismiss'}
            </button>
          </div>
        )}

        <div className="fc-set-grid" role="list" aria-label="Sets">
          {doneInExercise.map((done, index) => (
            <div
              key={index}
              role="listitem"
              className={`fc-set-chip ${done ? 'fc-set-chip--done' : ''} ${
                index === setIndex ? 'fc-set-chip--current' : ''
              }`}
            >
              <div className="fc-font-bold">Set {index + 1}</div>
              <div>{done ? 'Done' : '—'}</div>
            </div>
          ))}
        </div>

        <div className="fc-player__inputs">
          <label className="fc-field">
            <span className="fc-label">{isTimed ? 'Seconds' : 'Reps'}</span>
            <input
              type="number"
              inputMode="numeric"
              min={0}
              value={reps}
              onChange={(event) => setReps(event.target.value)}
            />
          </label>

          {!isTimed && (
            <label className="fc-field">
              <span className="fc-label">Weight (kg)</span>
              <input
                type="number"
                inputMode="decimal"
                min={0}
                step="0.5"
                value={weight}
                onChange={(event) => setWeight(event.target.value)}
              />
            </label>
          )}
        </div>
      </div>

      <footer className="fc-player__controls">
        <div className="fc-ctrl-row">
          <button type="button" className="fc-ctrl-btn" onClick={previous} disabled={busy}>
            <Icon name="chevronLeft" /> Prev
          </button>

          {running ? (
            <button type="button" className="fc-ctrl-btn" onClick={pause} disabled={busy}>
              <Icon name="pause" /> Pause
            </button>
          ) : (
            <button type="button" className="fc-ctrl-btn" onClick={resume} disabled={busy}>
              <Icon name="play" /> Resume
            </button>
          )}

          <button type="button" className="fc-ctrl-btn" onClick={next} disabled={busy}>
            <Icon name="skip" /> Skip
          </button>

          <button
            type="button"
            className="fc-ctrl-btn fc-text-danger"
            onClick={() => setConfirmStop(true)}
            disabled={busy}
          >
            <Icon name="x" /> Stop
          </button>
        </div>

        <Button
          variant="primary"
          size="lg"
          block
          icon="check"
          disabled={!running || busy}
          onClick={() =>
            logCurrentSet(
              isTimed
                ? { duration_seconds: Number(reps) || 0 }
                : { reps: Number(reps) || 0, weight_kg: Number(weight) || 0 },
            )
          }
        >
          Complete set {setIndex + 1}
        </Button>

        <Button variant="secondary" block onClick={complete} disabled={busy}>
          Finish workout
        </Button>
      </footer>

      {confirmStop && (
        <Modal
          title="Stop this workout?"
          confirmLabel="Stop workout"
          cancelLabel="Keep going"
          tone="danger"
          onCancel={() => setConfirmStop(false)}
          onConfirm={() => {
            setConfirmStop(false);
            abandon();
          }}
        >
          The sets you have logged and the time you have spent are kept. The workout will show as
          stopped rather than completed.
        </Modal>
      )}
    </section>
  );
}

/**
 * The queue's state, shown as a quiet badge rather than a blocking spinner —
 * the user should never wait on the network mid-set.
 */
function SyncBadge({
  pending,
  offline,
  dropped,
}: {
  pending: number;
  offline: boolean;
  dropped: number;
}) {
  // A refused set outranks everything else the badge could say.
  if (dropped > 0) {
    return (
      <span className="fc-sync fc-text-danger" role="alert">
        <Icon name="x" size={14} />
        {dropped} not saved
      </span>
    );
  }

  if (pending === 0) {
    return <span className="fc-sync fc-text-subtle">Saved</span>;
  }

  return (
    <span className={`fc-sync ${offline ? 'fc-sync--offline' : ''}`}>
      <Icon name={offline ? 'wifiOff' : 'refresh'} size={14} />
      {offline ? `${pending} to sync` : 'Syncing…'}
    </span>
  );
}
