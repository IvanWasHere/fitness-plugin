import { useMutation, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query';
import type { ApiError } from '@shared/api';
import { useSession } from '@shared/session-context';

/**
 * Messaging server state (W3.1, shared in W3.4 slice 3).
 *
 * These hooks live in `shared/` because the endpoints are **account-keyed and
 * symmetric**: `MessageService::threads()` resolves the caller to either an
 * `fc_users` row or an `fc_trainers` row and answers with the counterpart
 * either way. A member sees their trainers, a trainer sees their clients, and
 * neither side has a route the other lacks — so a second set of hooks for the
 * trainer app would be the same requests under different names.
 *
 * The one asymmetry the wire carries is `quota`, which is **null for a
 * trainer**: replying to your own client does not spend anybody's plan.
 */

export interface MessageQuota {
  limit: number | null;
  used: number;
  remaining: number | null;
  resets_at: string | null;
}

export interface ThreadSummary {
  id: number;
  trainer_id: number;
  user_id: number;
  counterpart_name: string | null;
  counterpart_avatar: string | null;
  preview: string | null;
  last_message_at: string | null;
  unread_count: number;
  status: string;
  /** Null when the caller is the trainer — replying spends nobody's plan. */
  quota: MessageQuota | null;
}

export type MessageDirection = 'user_to_trainer' | 'trainer_to_user';

export interface Message {
  id: number;
  thread_id: number;
  sender_account_id: number;
  direction: MessageDirection;
  message: string | null;
  attachments: string[];
  is_read: boolean;
  created_at: string | null;
}

export interface ThreadDetail {
  thread: Omit<ThreadSummary, 'preview' | 'last_message_at'>;
  messages: Message[];
  has_more: boolean;
  /** Pass back as `before` to page further into the history. */
  oldest_id: number | null;
}

/** `GET /messages/poll` — ids only, so an idle poll costs almost nothing. */
export interface MessagePoll {
  messages: Array<{ id: number; thread_id: number }>;
  latest_id: number;
  unread_total: number;
}

export const messageKeys = {
  threads: ['messages', 'threads'] as const,
  thread: (id: number) => ['messages', 'thread', id] as const,
  unread: ['messages', 'unread-count'] as const,
};

/**
 * The conversation list (W3.1).
 *
 * Polled on an interval rather than pushed: real-time is WebSockets and
 * assumption 5 defers that past launch. Fifteen seconds is the contract's
 * figure — frequent enough that a reply feels prompt, rare enough that an idle
 * tab is not a load generator.
 */
export function useThreads(): UseQueryResult<
  { items: ThreadSummary[]; unread_total: number },
  ApiError
> {
  const { api } = useSession();

  return useQuery({
    queryKey: messageKeys.threads,
    queryFn: () => api.get<{ items: ThreadSummary[]; unread_total: number }>('messages/threads'),
    refetchInterval: 15_000,
  });
}

/**
 * One conversation.
 *
 * Also polled, but only while it is open — an unmounted query stops, so the
 * poll follows the screen the reader is actually looking at.
 */
export function useThread(threadId: number | null): UseQueryResult<ThreadDetail, ApiError> {
  const { api } = useSession();

  return useQuery({
    queryKey: messageKeys.thread(threadId ?? 0),
    queryFn: () => api.get<ThreadDetail>(`messages/threads/${threadId}`),
    enabled: threadId !== null && threadId > 0,
    refetchInterval: 15_000,
  });
}

export function useMessageActions(threadId: number | null) {
  const { api } = useSession();
  const queryClient = useQueryClient();

  const settle = () => {
    void queryClient.invalidateQueries({ queryKey: ['messages'] });
    // The member's dashboard carries thread previews and an unread count. This
    // is a no-op in the trainer and admin apps, which have no such key — cheaper
    // than threading an app-specific callback through every caller.
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  };

  const send = useMutation<
    { message: Message; quota: MessageQuota | null },
    ApiError,
    { message: string; attachments?: string[] }
  >({
    mutationFn: (body) => api.post(`messages/threads/${threadId}`, body),
    onSuccess: settle,
  });

  /**
   * Marking read is fire-and-forget from the UI's point of view, but it still
   * settles the caches: the nav badge and the thread list both show a count the
   * reader has just cleared otherwise.
   */
  const markRead = useMutation<{ ok: boolean; unread_total: number }, ApiError, number>({
    mutationFn: (id) => api.post(`messages/threads/${id}/read`),
    onSuccess: settle,
  });

  return { send, markRead };
}

/**
 * The messages nav badge.
 *
 * Polls `GET /messages/unread-count` — a single indexed SUM — rather than
 * mounting the thread list app-wide. The badge only needs a number, and
 * refetching every conversation every fifteen seconds to derive one would be
 * the expensive way to get it. Seeded from the boot payload so it is right on
 * first paint.
 */
export function useUnreadMessages(): number {
  const { api, boot } = useSession();

  const { data } = useQuery({
    queryKey: messageKeys.unread,
    queryFn: () => api.get<{ unread_total: number }>('messages/unread-count'),
    initialData: { unread_total: boot.counts?.unread_messages ?? 0 },
    refetchInterval: 15_000,
  });

  return data?.unread_total ?? 0;
}
