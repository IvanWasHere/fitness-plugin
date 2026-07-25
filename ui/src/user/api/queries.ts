import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { ApiError } from '@shared/api';
import { useSession } from '@shared/session-context';
import type { Celebration, Collection, Session, WorkoutDetail, WorkoutSummary } from './types';

/**
 * Server state, one hook per endpoint (plans/04-user-app.md#state-ownership).
 *
 * TanStack Query replaces the prototype's `oninit: async () => { this.workouts =
 * await db… }` — which, being an arrow function, wrote onto module scope, so
 * Dashboard, Workouts and WorkoutDetail all shared one `this.workouts`. It only
 * appeared to work because a single screen renders at a time. Every screen owns
 * its query here.
 */

export const queryKeys = {
  workouts: (filters: Record<string, unknown>) => ['workouts', filters] as const,
  workout: (id: number) => ['workout', id] as const,
  activeSession: ['session', 'active'] as const,
  sessionHistory: (page: number) => ['sessions', page] as const,
};

export interface WorkoutFilters {
  q?: string;
  difficulty?: string;
  type?: string;
  status?: string;
  [key: string]: string | undefined;
}

export function useWorkouts(
  filters: WorkoutFilters = {},
): UseQueryResult<Collection<WorkoutSummary>, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.workouts(filters),
    queryFn: () =>
      api.get<Collection<WorkoutSummary>>(
        'workouts',
        Object.fromEntries(
          Object.entries(filters).filter(([, v]) => v !== undefined && v !== ''),
        ) as Record<string, string>,
      ),
  });
}

export function useWorkout(id: number): UseQueryResult<WorkoutDetail, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.workout(id),
    queryFn: () => api.get<WorkoutDetail>(`workouts/${id}`),
    enabled: Number.isFinite(id) && id > 0,
  });
}

/**
 * The open session, or null.
 *
 * `staleTime: 0` and a refetch on window focus on purpose: coming back to the
 * app after locking the phone is exactly when the client's picture of the
 * session is most likely to be out of date.
 */
export function useActiveSession(): UseQueryResult<Session | null, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.activeSession,
    // `?? null` because TanStack treats `undefined` as "no data yet" and throws;
    // a 204 from the server arrives here as null either way.
    queryFn: async () => (await api.get<Session | null>('sessions/active')) ?? null,
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

export function useSessionHistory(page = 1): UseQueryResult<Collection<Session>, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.sessionHistory(page),
    queryFn: () => api.get<Collection<Session>>('sessions', { page }),
  });
}

/**
 * Mutations that change a session. Each one seeds the active-session cache with
 * the response, because every endpoint returns the whole rehydrated session —
 * so the UI never needs a follow-up read to know where it stands.
 */
export function useSessionActions() {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const adopt = (session: Session | null) => {
    queryClient.setQueryData(queryKeys.activeSession, session);
    void queryClient.invalidateQueries({ queryKey: ['workouts'] });
  };

  const start = useMutation<Session, ApiError, number>({
    mutationFn: (workoutId) => api.post<Session>('sessions', { workout_id: workoutId }),
    onSuccess: adopt,
  });

  const transition = useMutation<
    Session,
    ApiError,
    { sessionId: number; action: 'pause' | 'resume' }
  >({
    mutationFn: ({ sessionId, action }) => api.patch<Session>(`sessions/${sessionId}`, { action }),
    onSuccess: adopt,
  });

  const cursor = useMutation<
    Session,
    ApiError,
    { sessionId: number; exerciseIndex: number; setIndex: number }
  >({
    mutationFn: ({ sessionId, exerciseIndex, setIndex }) =>
      api.patch<Session>(`sessions/${sessionId}/cursor`, {
        exercise_index: exerciseIndex,
        set_index: setIndex,
      }),
    onSuccess: adopt,
  });

  const complete = useMutation<Celebration, ApiError, number>({
    mutationFn: (sessionId) => api.post<Celebration>(`sessions/${sessionId}/complete`),
    onSuccess: () => {
      adopt(null);
      void queryClient.invalidateQueries({ queryKey: ['sessions'] });
    },
  });

  const abandon = useMutation<Session, ApiError, number>({
    mutationFn: (sessionId) => api.post<Session>(`sessions/${sessionId}/abandon`),
    onSuccess: () => {
      adopt(null);
      void queryClient.invalidateQueries({ queryKey: ['sessions'] });
    },
  });

  return { start, transition, cursor, complete, abandon };
}
