import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { ApiError } from '@shared/api';
import { useSession } from '@shared/session-context';
import type {
  Assignment,
  AssignmentWarning,
  ClientDetail,
  ClientNutrition,
  ClientProgress,
  ClientRequest,
  ClientSession,
  ClientSummary,
  TrainerCapacity,
  TrainerDashboard,
  TrainerNote,
  TrainerWorkout,
} from './types';

/**
 * Trainer server state (W3.4).
 *
 * Everything is scoped server-side by the roster check, so there is no client id
 * a hook could pass that would widen access — a client not on the roster answers
 * 404 whatever this file does.
 */
export const trainerKeys = {
  dashboard: ['trainer', 'dashboard'] as const,
  clients: (filters: string) => ['trainer', 'clients', filters] as const,
  client: (id: number) => ['trainer', 'client', id] as const,
  sessions: (id: number) => ['trainer', 'client', id, 'sessions'] as const,
  progress: (id: number, range: string) => ['trainer', 'client', id, 'progress', range] as const,
  nutrition: (id: number) => ['trainer', 'client', id, 'nutrition'] as const,
  notes: (id: number) => ['trainer', 'client', id, 'notes'] as const,
  requests: ['trainer', 'requests'] as const,
  workouts: ['trainer', 'workouts'] as const,
};

export function useTrainerDashboard(): UseQueryResult<TrainerDashboard, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.dashboard,
    queryFn: () => api.get<TrainerDashboard>('trainer/dashboard'),
    staleTime: 60_000,
  });
}

export function useClients(
  search = '',
): UseQueryResult<{ items: ClientSummary[]; total: number }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.clients(search),
    queryFn: () =>
      api.get<{ items: ClientSummary[]; total: number }>(
        'trainer/clients',
        search ? { q: search } : undefined,
      ),
  });
}

export function useClient(id: number | null): UseQueryResult<ClientDetail, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.client(id ?? 0),
    queryFn: () => api.get<ClientDetail>(`trainer/clients/${id}`),
    enabled: id !== null && id > 0,
  });
}

/**
 * Tab data is fetched only when its tab is open.
 *
 * Seven tabs eagerly loaded would be seven requests for a screen where a trainer
 * usually looks at one of them.
 */
export function useClientSessions(
  id: number,
  enabled: boolean,
): UseQueryResult<{ items: ClientSession[]; total: number }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.sessions(id),
    queryFn: () =>
      api.get<{ items: ClientSession[]; total: number }>(`trainer/clients/${id}/sessions`),
    enabled,
  });
}

export function useClientProgress(
  id: number,
  range: string,
  enabled: boolean,
): UseQueryResult<ClientProgress, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.progress(id, range),
    queryFn: () => api.get<ClientProgress>(`trainer/clients/${id}/progress`, { range }),
    enabled,
  });
}

export function useClientNutrition(
  id: number,
  enabled: boolean,
): UseQueryResult<ClientNutrition, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.nutrition(id),
    queryFn: () => api.get<ClientNutrition>(`trainer/clients/${id}/nutrition`),
    enabled,
  });
}

export function useClientNotes(
  id: number,
  enabled: boolean,
): UseQueryResult<{ items: TrainerNote[] }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.notes(id),
    queryFn: () => api.get<{ items: TrainerNote[] }>(`trainer/clients/${id}/notes`),
    enabled,
  });
}

export function useTrainerWorkouts(
  enabled = true,
): UseQueryResult<{ items: TrainerWorkout[] }, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.workouts,
    queryFn: () => api.get<{ items: TrainerWorkout[] }>('trainer/workouts'),
    enabled,
    staleTime: 5 * 60_000,
  });
}

/**
 * Writes against one client.
 *
 * `assign` returns warnings alongside the assignment — an overlap with another
 * coach's week is surfaced, never enforced.
 */
export function useClientActions(clientId: number) {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['trainer'] });
  };

  const assign = useMutation<
    { assignment: Assignment; warnings: AssignmentWarning[] },
    ApiError,
    { workout_id: number; scheduled_for?: string }
  >({
    mutationFn: (body) => api.post(`trainer/clients/${clientId}/workouts`, body),
    onSuccess: settle,
  });

  const unassign = useMutation<{ ok: boolean }, ApiError, number>({
    mutationFn: (assignmentId) =>
      api.delete(`trainer/clients/${clientId}/workouts/${assignmentId}`),
    onSuccess: settle,
  });

  const addNote = useMutation<{ id: number }, ApiError, string>({
    mutationFn: (body) => api.post(`trainer/clients/${clientId}/notes`, { body }),
    onSuccess: settle,
  });

  const deleteNote = useMutation<{ ok: boolean }, ApiError, number>({
    mutationFn: (noteId) => api.delete(`trainer/notes/${noteId}`),
    onSuccess: settle,
  });

  return { assign, unassign, addNote, deleteNote };
}

export function useRequests(): UseQueryResult<
  { items: ClientRequest[]; capacity: TrainerCapacity },
  ApiError
> {
  const { api } = useSession();

  return useQuery({
    queryKey: trainerKeys.requests,
    queryFn: () =>
      api.get<{ items: ClientRequest[]; capacity: TrainerCapacity }>('trainer/requests'),
  });
}

export function useRequestActions() {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['trainer'] });
  };

  const accept = useMutation<{ id: number; status: string }, ApiError, number>({
    mutationFn: (id) => api.post(`trainer/requests/${id}/accept`),
    onSuccess: settle,
  });

  const decline = useMutation<
    { id: number; status: string },
    ApiError,
    { id: number; reason?: string }
  >({
    mutationFn: ({ id, reason }) => api.post(`trainer/requests/${id}/decline`, { reason }),
    onSuccess: settle,
  });

  return { accept, decline };
}
