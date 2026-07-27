import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import { ApiError } from '@shared/api';
import { useSession } from '@shared/session-context';
import type { AdminDashboard, AdminList, AdminRow, AdminSettings } from './types';

/**
 * Admin server state (W2.5).
 *
 * Every list query is keyed on the **whole query object**, so paging, searching,
 * sorting and filtering each cache separately and going back to a previous page
 * is instant. That is only possible because the server does the work: the
 * prototype had one cached blob of every row and re-derived the view on each
 * keystroke.
 */
export interface ListQuery {
  page?: number;
  per_page?: number;
  q?: string;
  sort?: string;
  order?: 'asc' | 'desc';
  [filter: string]: string | number | undefined;
}

export const adminKeys = {
  list: (resource: string, query: ListQuery) => ['admin', resource, query] as const,
  one: (resource: string, id: number) => ['admin', resource, 'one', id] as const,
  dashboard: ['admin', 'dashboard'] as const,
  settings: ['admin', 'settings'] as const,
};

/** Drop empty values so they never reach the query string as `?q=&status=`. */
function clean(query: ListQuery): Record<string, string | number> {
  return Object.fromEntries(
    Object.entries(query).filter(
      ([, value]) => value !== undefined && value !== '' && value !== null,
    ),
  ) as Record<string, string | number>;
}

export function useAdminList(
  resource: string,
  query: ListQuery,
): UseQueryResult<AdminList, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: adminKeys.list(resource, query),
    queryFn: () => api.get<AdminList>(`admin/${resource}`, clean(query)),
    // Keeps the previous page on screen while the next loads, so paging does
    // not blank the table between clicks.
    placeholderData: (previous) => previous,
  });
}

export function useAdminRecord(
  resource: string,
  id: number | null,
): UseQueryResult<AdminRow, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: adminKeys.one(resource, id ?? 0),
    queryFn: () => api.get<AdminRow>(`admin/${resource}/${id}`),
    enabled: id !== null && id > 0,
  });
}

/**
 * Create, update and delete for one resource.
 *
 * All three invalidate every list page of that resource rather than patching one
 * row: a create can land on any page under the current sort, and a rename can
 * move a row out of the filter it was found under. Patching in place would show
 * a row where the server would no longer put it.
 */
export function useAdminActions(resource: string) {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin', resource] });
    void queryClient.invalidateQueries({ queryKey: adminKeys.dashboard });
  };

  const create = useMutation<AdminRow, ApiError, Record<string, unknown>>({
    mutationFn: (payload) => api.post<AdminRow>(`admin/${resource}`, payload),
    onSuccess: settle,
  });

  const update = useMutation<AdminRow, ApiError, { id: number; payload: Record<string, unknown> }>({
    mutationFn: ({ id, payload }) => api.put<AdminRow>(`admin/${resource}/${id}`, payload),
    onSuccess: settle,
  });

  const destroy = useMutation<{ ok: boolean }, ApiError, number>({
    mutationFn: (id) => api.delete<{ ok: boolean }>(`admin/${resource}/${id}`),
    onSuccess: settle,
  });

  return { create, update, destroy };
}

export function useAdminDashboard(): UseQueryResult<AdminDashboard, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: adminKeys.dashboard,
    queryFn: () => api.get<AdminDashboard>('admin/dashboard'),
    staleTime: 60_000,
  });
}

export function useAdminSettings(): UseQueryResult<AdminSettings, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: adminKeys.settings,
    queryFn: () => api.get<AdminSettings>('admin/settings'),
  });
}

export function useUpdateSettings() {
  const { api } = useSession();
  const queryClient = useQueryClient();

  return useMutation<AdminSettings, ApiError, Record<string, unknown>>({
    mutationFn: (payload) => api.put<AdminSettings>('admin/settings', payload),
    onSuccess: (result) => queryClient.setQueryData(adminKeys.settings, result),
  });
}
