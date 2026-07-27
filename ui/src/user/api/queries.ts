import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { ApiError } from '@shared/api';
import { useSession } from '@shared/session-context';
import type {
  Celebration,
  Collection,
  ConsistencyCalendar,
  Dashboard,
  ExerciseProgression,
  Food,
  HealthInput,
  HealthStat,
  HealthStats,
  HealthSummary,
  Meal,
  MealInput,
  Measurements,
  MeasurementsInput,
  NutritionDay,
  NutritionGoals,
  Progress,
  ProgressRange,
  ProgressRecord,
  Session,
  WaterState,
  WorkoutDetail,
  WorkoutSummary,
} from './types';

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
  dashboard: ['dashboard'] as const,
  workouts: (filters: Record<string, unknown>) => ['workouts', filters] as const,
  workout: (id: number) => ['workout', id] as const,
  activeSession: ['session', 'active'] as const,
  sessionHistory: (page: number) => ['sessions', page] as const,
  nutritionDay: (date?: string) => ['nutrition', 'day', date ?? 'today'] as const,
  foods: (query: string) => ['nutrition', 'foods', query] as const,
  healthSummary: ['health', 'summary'] as const,
  healthStats: (metrics?: string) => ['health', 'stats', metrics ?? 'all'] as const,
  measurements: ['health', 'measurements'] as const,
  progress: (range: string) => ['progress', range] as const,
  progressRecords: ['progress', 'records'] as const,
  consistency: (year?: number) => ['progress', 'consistency', year ?? 'current'] as const,
};

/**
 * The dashboard aggregate — one request for the whole screen.
 *
 * `staleTime` matches the server's 60-second cache: refetching sooner can only
 * return the same cached bytes, so the shorter default would spend a request to
 * learn nothing. After a write the server *drops* its cache and this query is
 * invalidated, so freshness comes from invalidation, not from polling.
 */
export function useDashboard(): UseQueryResult<Dashboard, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.dashboard,
    queryFn: () => api.get<Dashboard>('user/dashboard'),
    staleTime: 60_000,
  });
}

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
    // Starting, finishing or stopping a workout all move a number on the
    // dashboard — and the server has already dropped its own cache for the same
    // event, so this refetch reads fresh figures rather than the ones the
    // member just invalidated.
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
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

// ------------------------------------------------------------- nutrition

/**
 * The Nutrition screen, one request per day (W2.1).
 *
 * Keyed on the date so paging back through the diary caches each day rather
 * than refetching the same one. `date` is the *member's* day, decided
 * server-side — the client never sends its own "today", because a browser at
 * 00:30 in a different timezone would ask for a day the server does not think
 * it is.
 */
export function useNutritionDay(date?: string): UseQueryResult<NutritionDay, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.nutritionDay(date),
    queryFn: () => api.get<NutritionDay>('nutrition/day', date ? { date } : undefined),
  });
}

/**
 * Food typeahead. Idle until two characters, which is where the server switches
 * from the FULLTEXT index to a prefix LIKE.
 */
export function useFoodSearch(term: string): UseQueryResult<{ items: Food[] }, ApiError> {
  const { api } = useSession();
  const query = term.trim();

  return useQuery({
    queryKey: queryKeys.foods(query),
    queryFn: () => api.get<{ items: Food[] }>('foods', query === '' ? {} : { q: query }),
    enabled: query.length === 0 || query.length >= 2,
    // A typeahead that refetches on every focus change flickers; the food
    // database does not move minute to minute.
    staleTime: 5 * 60_000,
  });
}

/**
 * Nutrition writes. Every one of them invalidates the day *and* the dashboard —
 * the server has already dropped its own cached aggregate for the same event,
 * so the refetch reads fresh figures rather than the ones just invalidated.
 */
export function useNutritionActions(date?: string) {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['nutrition'] });
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
  };

  const logMeal = useMutation<Meal, ApiError, MealInput>({
    mutationFn: (meal) =>
      api.post<Meal>('nutrition/logs', { ...meal, log_date: meal.log_date ?? date }),
    onSuccess: settle,
  });

  const updateMeal = useMutation<Meal, ApiError, { id: number; meal: Partial<MealInput> }>({
    mutationFn: ({ id, meal }) => api.put<Meal>(`nutrition/logs/${id}`, meal),
    onSuccess: settle,
  });

  const deleteMeal = useMutation<{ ok: boolean }, ApiError, number>({
    mutationFn: (id) => api.delete<{ ok: boolean }>(`nutrition/logs/${id}`),
    onSuccess: settle,
  });

  /**
   * `delta_ml` for the +/- buttons, `total_ml` for tapping the n-th dot
   * directly. The server accepts either; sending a total for a direct tap is
   * what keeps a double tap from racing itself.
   */
  const setWater = useMutation<WaterState, ApiError, { delta_ml?: number; total_ml?: number }>({
    mutationFn: (body) => api.post<WaterState>('nutrition/water', { ...body, date }),
    onSuccess: settle,
  });

  const setGoals = useMutation<NutritionGoals, ApiError, Partial<NutritionGoals>>({
    mutationFn: (goals) => api.put<NutritionGoals>('nutrition/goals', { ...goals, date }),
    onSuccess: settle,
  });

  return { logMeal, updateMeal, deleteMeal, setWater, setGoals };
}

// ---------------------------------------------------------------- health

/**
 * The Health screen, one request (W2.2).
 *
 * The eight cards, BMI and the latest measurements together — the prototype
 * read two stores and then indexed `d.weight[d.weight.length - 1]` on seven
 * arrays, which throws for anyone who has logged nothing.
 */
export function useHealthSummary(): UseQueryResult<HealthSummary, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.healthSummary,
    queryFn: () => api.get<HealthSummary>('health/summary'),
  });
}

/**
 * The history behind a card.
 *
 * `enabled` gates it on the modal actually being open: the detail modal is the
 * only thing that wants history, and fetching ninety days of readings for eight
 * cards nobody has tapped is eight requests for nothing.
 */
export function useHealthStats(
  metrics?: string,
  enabled = true,
): UseQueryResult<HealthStats, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.healthStats(metrics),
    queryFn: () => api.get<HealthStats>('health/stats', metrics ? { metrics } : undefined),
    enabled,
  });
}

export function useMeasurements(
  enabled = true,
): UseQueryResult<{ items: Measurements[]; from: string; to: string }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.measurements,
    queryFn: () =>
      api.get<{ items: Measurements[]; from: string; to: string }>('health/measurements'),
    enabled,
  });
}

/**
 * Health writes.
 *
 * Every one invalidates the dashboard as well as the health queries: weight
 * feeds `stats.current_weight_kg` and the goal-progress figure, and the server
 * has already dropped its own cached aggregate for the same event.
 */
export function useHealthActions() {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['health'] });
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboard });
  };

  // POST, not PUT, and it upserts on the date — a second entry the same day
  // edits the day's record rather than adding a second one.
  const saveStat = useMutation<HealthStat, ApiError, HealthInput>({
    mutationFn: (entry) => api.post<HealthStat>('health/stats', entry),
    onSuccess: settle,
  });

  const updateStat = useMutation<HealthStat, ApiError, { id: number; entry: HealthInput }>({
    mutationFn: ({ id, entry }) => api.put<HealthStat>(`health/stats/${id}`, entry),
    onSuccess: settle,
  });

  const deleteStat = useMutation<{ ok: boolean }, ApiError, number>({
    mutationFn: (id) => api.delete<{ ok: boolean }>(`health/stats/${id}`),
    onSuccess: settle,
  });

  const saveMeasurements = useMutation<Measurements, ApiError, MeasurementsInput>({
    mutationFn: (entry) => api.post<Measurements>('health/measurements', entry),
    onSuccess: settle,
  });

  return { saveStat, updateStat, deleteStat, saveMeasurements };
}

// ---------------------------------------------------------------- progress

/**
 * The Progress screen for one range (W2.3).
 *
 * Keyed on the range, so flipping between week and year caches each rather than
 * refetching the one just left. `placeholderData` keeps the previous range's
 * charts on screen while the new one loads — the alternative is four charts
 * unmounting and remounting on every click of the filter, which reads as the
 * page breaking rather than as data arriving.
 */
export function useProgress(range: ProgressRange): UseQueryResult<Progress, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.progress(range),
    queryFn: () => api.get<Progress>('progress', { range }),
    placeholderData: (previous) => previous,
    staleTime: 60_000,
  });
}

export function useProgressRecords(): UseQueryResult<{ items: ProgressRecord[] }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.progressRecords,
    queryFn: () => api.get<{ items: ProgressRecord[] }>('progress/records'),
  });
}

/**
 * One lift's session-by-session history — the detail behind a line on the
 * strength chart. Idle until a lift is actually chosen.
 */
export function useExerciseProgression(
  exerciseName: string | null,
  range: ProgressRange = 'quarter',
): UseQueryResult<ExerciseProgression, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: ['progress', 'exercise', exerciseName, range],
    queryFn: () =>
      api.get<ExerciseProgression>(`progress/exercises/${encodeURIComponent(exerciseName ?? '')}`, {
        range,
      }),
    enabled: exerciseName !== null && exerciseName !== '',
  });
}

export function useConsistencyCalendar(
  year?: number,
  enabled = true,
): UseQueryResult<ConsistencyCalendar, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: queryKeys.consistency(year),
    queryFn: () =>
      api.get<ConsistencyCalendar>('progress/consistency', year ? { year } : undefined),
    enabled,
  });
}
