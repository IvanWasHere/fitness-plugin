import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useSession } from '@shared/session-context';
import { useToast } from '@shared/toast-context';
import { useWakeLock } from '@shared/hooks/useWakeLock';
import { useActiveSession, useSessionActions } from '../api/queries';
import type { Celebration, SessionExercise } from '../api/types';
import { PlayerContext, type LoggedSetInput, type PlayerValue } from './player-context';
import { SetQueue, type QueueState } from './setQueue';

/**
 * Owns the running workout: cursor, rest timer, the offline set queue, the wake
 * lock, and the celebration payload (plans/04-user-app.md).
 *
 * It lives above the router so the session survives navigation — a user who taps
 * through to another screen mid-workout comes back to the same state — and it is
 * seeded from `GET /sessions/active`, so a reload or a second device rehydrates
 * rather than starting over.
 *
 * **Local completion state is optimistic.** The queue may still be delivering
 * when the user taps the next set, so which sets are "done" is tracked here and
 * seeded from the server, not read back from it after each write. The
 * focus-refetch of `/sessions/active` reconciles.
 */
const EMPTY_QUEUE: QueueState = { pending: 0, syncing: false, offline: false, dropped: 0 };

export function PlayerProvider({ children }: { children: ReactNode }) {
  const { api } = useSession();
  const toast = useToast();
  const { data: session = null } = useActiveSession();
  const { start, transition, cursor, complete, abandon } = useSessionActions();

  const [isOpen, setIsOpen] = useState(false);
  const [exerciseIndex, setExerciseIndex] = useState(0);
  const [setIndex, setSetIndex] = useState(0);
  const [completedSets, setCompletedSets] = useState<Record<string, true>>({});
  const [restEndsAt, setRestEndsAt] = useState<number | null>(null);
  const [celebration, setCelebration] = useState<Celebration | null>(null);
  const [queue, setQueue] = useState<QueueState>(EMPTY_QUEUE);

  /** When the last set finished, so the next one can report its rest honestly. */
  const lastSetAt = useRef<number | null>(null);
  const queueRef = useRef<SetQueue | null>(null);
  const sessionId = session?.id ?? null;

  // --- the set queue, one per session ------------------------------------
  useEffect(() => {
    if (sessionId === null) {
      queueRef.current?.dispose();
      queueRef.current = null;
      setQueue(EMPTY_QUEUE);

      return;
    }

    const instance = new SetQueue(api, sessionId);
    queueRef.current = instance;
    const unsubscribe = instance.subscribe(setQueue);

    // Anything left from a previous visit goes out now.
    void instance.flush();

    const onHide = () => {
      if (document.visibilityState === 'hidden') {
        instance.flushWithBeacon();
      }
    };
    const onOnline = () => void instance.flush();

    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('pagehide', () => instance.flushWithBeacon());
    window.addEventListener('online', onOnline);

    return () => {
      document.removeEventListener('visibilitychange', onHide);
      window.removeEventListener('online', onOnline);
      unsubscribe();
      instance.dispose();
    };
  }, [api, sessionId]);

  // --- rehydrate the cursor and completed sets from the server ------------
  useEffect(() => {
    if (!session) {
      setCompletedSets({});
      return;
    }

    setExerciseIndex(session.current_exercise_index);
    setSetIndex(session.current_set_index);

    setCompletedSets((current) => {
      const seeded: Record<string, true> = { ...current };

      for (const exercise of session.exercises) {
        for (const set of exercise.sets) {
          seeded[`${exercise.exercise_id}:${set.set_index}`] = true;
        }
      }

      return seeded;
    });
  }, [session]);

  // Keep the screen alive only while a workout is actually running.
  useWakeLock(isOpen && session?.status === 'in_progress');

  // Memoised so the callbacks below keep a stable identity between renders.
  const exercises = useMemo(() => session?.exercises ?? [], [session]);
  const exercise: SessionExercise | null = exercises[exerciseIndex] ?? null;

  /**
   * The elapsed-time baseline. `elapsed_seconds` was computed by the server when
   * the response was built, so pairing it with the moment it arrived lets the
   * display tick forward without trusting the device clock's absolute value.
   */
  // Keyed on the session object itself: every mutation returns a freshly
  // rehydrated session, and re-capturing on identity means a pause-then-resume
  // inside the same second still resets the baseline. Keying on the number alone
  // would leave `capturedAt` stale there, and the timer would jump by the
  // length of the pause.
  const elapsedBase = useMemo(
    () => ({ seconds: session?.elapsed_seconds ?? 0, capturedAt: Date.now() }),
    [session],
  );

  const syncCursor = useCallback(
    (nextExercise: number, nextSet: number) => {
      if (sessionId === null) {
        return;
      }
      cursor.mutate({ sessionId, exerciseIndex: nextExercise, setIndex: nextSet });
    },
    [cursor, sessionId],
  );

  const goTo = useCallback(
    (nextExercise: number, nextSet: number) => {
      const bounded = Math.max(0, Math.min(exercises.length - 1, nextExercise));
      setExerciseIndex(bounded);
      setSetIndex(Math.max(0, nextSet));
      setRestEndsAt(null);
      syncCursor(bounded, Math.max(0, nextSet));
    },
    [exercises.length, syncCursor],
  );

  const next = useCallback(() => {
    if (!exercise) {
      return;
    }

    if (setIndex + 1 < exercise.planned_sets) {
      goTo(exerciseIndex, setIndex + 1);
      return;
    }

    goTo(exerciseIndex + 1, 0);
  }, [exercise, exerciseIndex, goTo, setIndex]);

  const previous = useCallback(() => {
    if (setIndex > 0) {
      goTo(exerciseIndex, setIndex - 1);
      return;
    }

    if (exerciseIndex > 0) {
      const target = exercises[exerciseIndex - 1];
      goTo(exerciseIndex - 1, Math.max(0, (target?.planned_sets ?? 1) - 1));
    }
  }, [exerciseIndex, exercises, goTo, setIndex]);

  const logCurrentSet = useCallback(
    (input: LoggedSetInput) => {
      if (!exercise || sessionId === null) {
        return;
      }

      const now = Date.now();
      const restTaken =
        lastSetAt.current === null ? null : Math.round((now - lastSetAt.current) / 1000);
      lastSetAt.current = now;

      // Only what was actually measured goes on the wire. A timed hold has no
      // reps and a bodyweight set has no weight; sending nulls for them is
      // noise the server has to accept and validate for no benefit.
      queueRef.current?.enqueue({
        exercise_id: exercise.exercise_id,
        set_index: setIndex,
        ...(input.reps === undefined || input.reps === null ? {} : { reps: input.reps }),
        ...(input.weight_kg === undefined || input.weight_kg === null
          ? {}
          : { weight_kg: input.weight_kg }),
        ...(input.duration_seconds === undefined || input.duration_seconds === null
          ? {}
          : { duration_seconds: input.duration_seconds }),
        ...(restTaken === null ? {} : { rest_taken_seconds: restTaken }),
      });

      setCompletedSets((current) => ({
        ...current,
        [`${exercise.exercise_id}:${setIndex}`]: true,
      }));

      // Order matters: `next()` moves the cursor, and moving the cursor cancels
      // any rest (skipping ahead by hand should not leave a countdown running).
      // Starting the rest *after* it is therefore the whole difference between a
      // rest timer and no rest timer at all.
      next();

      // Rest starts the moment the set ends, not when the request returns —
      // waiting for the round trip would shorten every rest by the latency.
      const rest = exercise.rest_seconds > 0 ? exercise.rest_seconds : null;
      setRestEndsAt(rest === null ? null : now + rest * 1000);
    },
    [exercise, next, sessionId, setIndex],
  );

  const startWorkout = useCallback(
    (workoutId: number) => {
      start.mutate(workoutId, {
        onSuccess: () => {
          lastSetAt.current = null;
          setCompletedSets({});
          setExerciseIndex(0);
          setSetIndex(0);
          setRestEndsAt(null);
          setIsOpen(true);
        },
        onError: (error) => {
          // A session is already open: take the user to it rather than nagging.
          if (error.code === 'fc_session_already_open') {
            setIsOpen(true);
            toast.info('You already have a workout in progress.');
            return;
          }

          toast.error(error.message);
        },
      });
    },
    [start, toast],
  );

  const finish = useCallback(() => {
    if (sessionId === null) {
      return;
    }

    // Get whatever is queued to the server *before* asking it to total the
    // workout up, or the celebration screen reports numbers missing the last set.
    const flushed = queueRef.current?.flush() ?? Promise.resolve();

    void flushed.finally(() => {
      complete.mutate(sessionId, {
        onSuccess: (payload) => {
          setCelebration(payload);
          setIsOpen(false);
          setRestEndsAt(null);
        },
        onError: (error) => toast.error(error.message),
      });
    });
  }, [complete, sessionId, toast]);

  const stop = useCallback(() => {
    if (sessionId === null) {
      return;
    }

    abandon.mutate(sessionId, {
      onSuccess: () => {
        setIsOpen(false);
        setRestEndsAt(null);
        toast.info('Workout stopped. Your sets were saved.');
      },
      onError: (error) => toast.error(error.message),
    });
  }, [abandon, sessionId, toast]);

  const value = useMemo<PlayerValue>(
    () => ({
      session,
      isOpen: isOpen && session !== null,
      open: () => setIsOpen(true),
      close: () => setIsOpen(false),
      starting: start.isPending,
      start: startWorkout,
      exercise,
      exerciseIndex,
      setIndex,
      goTo,
      next,
      previous,
      completedSets,
      logCurrentSet,
      pause: () => sessionId !== null && transition.mutate({ sessionId, action: 'pause' }),
      resume: () => sessionId !== null && transition.mutate({ sessionId, action: 'resume' }),
      complete: finish,
      abandon: stop,
      busy: complete.isPending || abandon.isPending || transition.isPending,
      celebration,
      dismissCelebration: () => setCelebration(null),
      queue,
      restEndsAt,
      skipRest: () => setRestEndsAt(null),
      elapsedBase,
    }),
    [
      abandon.isPending,
      celebration,
      complete.isPending,
      completedSets,
      elapsedBase,
      exercise,
      exerciseIndex,
      finish,
      goTo,
      isOpen,
      logCurrentSet,
      next,
      previous,
      queue,
      restEndsAt,
      session,
      sessionId,
      setIndex,
      start.isPending,
      startWorkout,
      stop,
      transition,
    ],
  );

  return <PlayerContext.Provider value={value}>{children}</PlayerContext.Provider>;
}
