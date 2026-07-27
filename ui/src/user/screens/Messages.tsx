import { useEffect, useRef, useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { Button, Card, EmptyState, ErrorState, PageHeader, Skeleton } from '@shared/components/ui';
import { useSession } from '@shared/session-context';
import { useMessageActions, useThread, useThreads } from '../api/queries';
import type { Message, MessageQuota, ThreadSummary } from '../api/types';

/**
 * Messages (W3.1).
 *
 * A list-and-pane layout: conversations on the left, the open thread on the
 * right, collapsing to one column on a phone. Both halves poll on a 15 s
 * interval — real-time is WebSockets and assumption 5 defers that past launch.
 *
 * **There is no typing indicator.** The prototype showed one permanently
 * whenever the trainer was "online", tied to nothing at all. Real typing state
 * costs a write per keystroke-burst for cosmetic value, and
 * `plans/02-api-contract.md` recommends dropping it at launch.
 */
export function Messages() {
  const { data, isLoading, error, refetch } = useThreads();
  const [openId, setOpenId] = useState<number | null>(null);

  if (error) {
    return (
      <>
        <PageHeader title="Messages" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Messages" />
        <Skeleton height={320} />
      </>
    );
  }

  if (data.items.length === 0) {
    return (
      <>
        <PageHeader title="Messages" />
        <EmptyState title="No conversations yet">
          When a trainer is assigned to you, your conversation with them appears here.
        </EmptyState>
      </>
    );
  }

  const open = data.items.find((thread) => thread.id === openId) ?? null;

  return (
    <>
      <PageHeader
        title="Messages"
        subtitle={data.unread_total > 0 ? `${data.unread_total} unread` : 'Up to date'}
      />

      <div className={`fc-msg${open ? ' fc-msg--open' : ''}`}>
        <ul className="fc-msg__list">
          {data.items.map((thread) => (
            <li key={thread.id}>
              <button
                type="button"
                className={`fc-msg__thread${thread.id === openId ? ' fc-msg__thread--on' : ''}`}
                onClick={() => setOpenId(thread.id)}
              >
                <span className="fc-avatar fc-avatar--sm" aria-hidden="true">
                  {(thread.counterpart_name ?? '?').slice(0, 1).toUpperCase()}
                </span>

                <span className="fc-msg__thread-body">
                  <span className="fc-font-bold fc-truncate">
                    {thread.counterpart_name ?? 'Trainer'}
                  </span>
                  <span className="fc-text-xs fc-text-muted fc-truncate">
                    {thread.preview ?? 'No messages yet'}
                  </span>
                </span>

                {thread.unread_count > 0 && (
                  <span className="fc-msg__unread" aria-label={`${thread.unread_count} unread`}>
                    {thread.unread_count}
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>

        <div className="fc-msg__pane">
          {open ? (
            <Conversation thread={open} onBack={() => setOpenId(null)} />
          ) : (
            <Card>
              <p className="fc-text-sm fc-text-muted">Pick a conversation to read it.</p>
            </Card>
          )}
        </div>
      </div>
    </>
  );
}

function Conversation({ thread, onBack }: { thread: ThreadSummary; onBack: () => void }) {
  const { boot } = useSession();
  const { data, isLoading } = useThread(thread.id);
  const { send, markRead } = useMessageActions(thread.id);

  const [draft, setDraft] = useState('');
  const endRef = useRef<HTMLDivElement>(null);
  const markedRef = useRef<number | null>(null);

  // Mark read once per thread opened, not on every poll — the poll re-renders
  // every 15 s and a mutation on each would be a write loop.
  useEffect(() => {
    if (thread.unread_count > 0 && markedRef.current !== thread.id) {
      markedRef.current = thread.id;
      markRead.mutate(thread.id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [thread.id, thread.unread_count]);

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end' });
  }, [data?.messages.length]);

  const quota = data?.thread.quota ?? thread.quota;
  const blocked = quota !== null && quota.remaining !== null && quota.remaining <= 0;
  const canSend = draft.trim() !== '' && !send.isPending && !blocked;

  return (
    <Card className="fc-msg__conversation">
      <div className="fc-flex fc-flex-c fc-gap-12 fc-mb-16">
        {/* Only shown on a phone, where the pane replaces the list. */}
        <button type="button" className="fc-btn fc-btn--icon fc-msg__back" onClick={onBack}>
          <Icon name="chevronLeft" size={16} title="Back to conversations" />
        </button>
        <strong>{thread.counterpart_name ?? 'Trainer'}</strong>
      </div>

      <div className="fc-msg__log">
        {isLoading && <Skeleton height={200} />}

        {data?.has_more && (
          <p className="fc-text-xs fc-text-muted fc-text-center">
            Older messages are not loaded yet.
          </p>
        )}

        {data?.messages.map((message) => (
          <Bubble
            key={message.id}
            message={message}
            mine={message.sender_account_id === boot.user?.account_id}
          />
        ))}

        <div ref={endRef} />
      </div>

      <QuotaNote quota={quota} />

      <form
        className="fc-msg__composer"
        onSubmit={(event) => {
          event.preventDefault();

          if (canSend) {
            send.mutate({ message: draft.trim() }, { onSuccess: () => setDraft('') });
          }
        }}
      >
        <input
          value={draft}
          placeholder={blocked ? 'No messages left this week' : 'Write a message…'}
          disabled={blocked}
          aria-label="Message"
          onChange={(event) => setDraft(event.target.value)}
        />
        <Button variant="primary" type="submit" disabled={!canSend}>
          {send.isPending ? 'Sending…' : 'Send'}
        </Button>
      </form>

      {send.error && <p className="fc-text-sm fc-text-danger">{send.error.message}</p>}
    </Card>
  );
}

function Bubble({ message, mine }: { message: Message; mine: boolean }) {
  return (
    <div className={`fc-bubble${mine ? ' fc-bubble--mine' : ''}`}>
      {message.message && <p>{message.message}</p>}

      {message.attachments.map((url) => (
        <img key={url} src={url} alt="" className="fc-bubble__image" />
      ))}

      <time className="fc-text-xs fc-text-subtle">
        {message.created_at ? new Date(message.created_at).toLocaleString() : ''}
      </time>
    </div>
  );
}

/**
 * What is left, in words.
 *
 * Nothing is shown on an unlimited plan — a counter that never moves is noise.
 * `limit === 0` is a different message from "you have used them all": the member
 * never had any, and telling them they have run out would be wrong.
 */
function QuotaNote({ quota }: { quota: MessageQuota | null }) {
  if (!quota || quota.limit === null) {
    return null;
  }

  if (quota.limit === 0) {
    return <p className="fc-text-xs fc-text-muted">Messaging is not included in your plan.</p>;
  }

  const resets = quota.resets_at ? new Date(quota.resets_at).toLocaleDateString() : null;

  return (
    <p className="fc-text-xs fc-text-muted">
      {quota.remaining} of {quota.limit} messages left with this trainer
      {resets ? ` · one frees up ${resets}` : ''}
    </p>
  );
}
