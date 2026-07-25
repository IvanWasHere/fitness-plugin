import { createContext, useContext } from 'react';
import type { Celebration, Session, SessionExercise } from '../api/types';
import type { QueueState } from './setQueue';

/**
 * The workout player's contract.
 *
 * Named "player" rather than the plan's `SessionProvider`: in this codebase
 * "session" already means the *auth* session (`shared/session.tsx`), and two
 * different SessionProviders in one tree is a bug waiting to be written.
 */
export interface LoggedSetInput {
  reps?: number | null;
  weight_kg?: number | null;
  duration_seconds?: number | null;
}

export interface PlayerValue {
  /** The open session, or null when nothing is running. */
  session: Session | null;
  /** Whether the full-screen player is showing. */
  isOpen: boolean;
  open(): void;
  close(): void;

  starting: boolean;
  start(workoutId: number): void;

  exercise: SessionExercise | null;
  exerciseIndex: number;
  setIndex: number;
  goTo(exerciseIndex: number, setIndex: number): void;
  next(): void;
  previous(): void;

  /** Keyed `${exerciseId}:${setIndex}` — optimistic, seeded from the server. */
  completedSets: Record<string, true>;
  logCurrentSet(input: LoggedSetInput): void;

  pause(): void;
  resume(): void;
  complete(): void;
  abandon(): void;
  busy: boolean;

  celebration: Celebration | null;
  dismissCelebration(): void;

  queue: QueueState;
  /** Epoch ms the current rest ends at, or null when not resting. */
  restEndsAt: number | null;
  skipRest(): void;

  /** Elapsed-time baseline for `useElapsed` — value plus when it was captured. */
  elapsedBase: { seconds: number; capturedAt: number };
}

export const PlayerContext = createContext<PlayerValue | null>(null);

export function usePlayer(): PlayerValue {
  const value = useContext(PlayerContext);
  if (!value) {
    throw new Error('usePlayer must be used inside <PlayerProvider>.');
  }
  return value;
}
