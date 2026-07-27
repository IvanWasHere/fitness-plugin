import { useState } from 'react';
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
import { useFaq, useTicket, useTicketActions, useTickets } from '../api/queries';
import type { Ticket } from '../api/types';

/**
 * Support: tickets and the FAQ (W3.3).
 *
 * **The FAQ comes from the server**, not from an array in this bundle. The
 * prototype hardcoded five answers into the JavaScript, so correcting a wrong
 * one meant a rebuild and a deploy.
 *
 * A member never sees an internal note, an assignee or a first-response time —
 * the server omits them from a member's payload entirely, so there is nothing
 * here that could render them by accident.
 */
const STATUS_TONE: Record<string, string> = {
  open: 'blue',
  in_progress: 'orange',
  waiting_user: 'orange',
  resolved: 'green',
  closed: 'green',
};

const STATUS_LABEL: Record<string, string> = {
  open: 'Open',
  in_progress: 'Being looked at',
  waiting_user: 'Waiting for you',
  resolved: 'Resolved',
  closed: 'Closed',
};

export function Support() {
  const { data, isLoading, error, refetch } = useTickets();
  const [openId, setOpenId] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  if (error) {
    return (
      <>
        <PageHeader title="Support" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Support"
        actions={
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            New ticket
          </Button>
        }
      />

      <Faq />

      <h2 className="fc-text-lg fc-mb-16">Your tickets</h2>

      {isLoading && <Skeleton height={160} />}

      {!isLoading && (data?.items.length ?? 0) === 0 && (
        <EmptyState title="No tickets">
          If something is wrong or you have a question the FAQ does not answer, open a ticket and we
          will get back to you.
        </EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {(data?.items ?? []).map((ticket) => (
          <Card key={ticket.id}>
            <button type="button" className="fc-ticket-row" onClick={() => setOpenId(ticket.id)}>
              <span className="fc-ticket-row__body">
                <span className="fc-font-bold fc-truncate">{ticket.subject}</span>
                <span className="fc-text-xs fc-text-muted">
                  {ticket.created_at.slice(0, 10)} · {ticket.category}
                  {ticket.reply_count
                    ? ` · ${ticket.reply_count} repl${ticket.reply_count === 1 ? 'y' : 'ies'}`
                    : ''}
                </span>
              </span>
              <Tag tone={STATUS_TONE[ticket.status] ?? 'blue'}>
                {STATUS_LABEL[ticket.status] ?? ticket.status}
              </Tag>
            </button>
          </Card>
        ))}
      </div>

      {creating && <NewTicket onClose={() => setCreating(false)} />}
      {openId !== null && <TicketDetail id={openId} onClose={() => setOpenId(null)} />}
    </>
  );
}

function Faq() {
  const { data, isLoading } = useFaq();
  const [open, setOpen] = useState<number | null>(null);

  if (isLoading || !data || data.items.length === 0) {
    return null;
  }

  return (
    <Card className="fc-mb-24">
      <h2 className="fc-text-lg fc-mb-16">Common questions</h2>

      <ul className="fc-faq">
        {data.items.map((entry, index) => (
          <li key={entry.q}>
            <button
              type="button"
              aria-expanded={open === index}
              onClick={() => setOpen(open === index ? null : index)}
            >
              {entry.q}
            </button>
            {open === index && <p className="fc-text-sm fc-text-muted">{entry.a}</p>}
          </li>
        ))}
      </ul>
    </Card>
  );
}

function NewTicket({ onClose }: { onClose: () => void }) {
  const { create } = useTicketActions();
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [category, setCategory] = useState('general');

  const valid = subject.trim() !== '' && message.trim() !== '';

  return (
    <Modal
      title="New ticket"
      variant="form"
      confirmLabel={create.isPending ? 'Sending…' : 'Send'}
      confirmDisabled={!valid || create.isPending}
      onCancel={onClose}
      onConfirm={() =>
        create.mutate(
          { subject: subject.trim(), message: message.trim(), category },
          { onSuccess: onClose },
        )
      }
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-ticket-subject">
          Subject
        </label>
        <input
          id="fc-ticket-subject"
          value={subject}
          onChange={(event) => setSubject(event.target.value)}
        />
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-ticket-category">
          Category
        </label>
        <select
          id="fc-ticket-category"
          value={category}
          onChange={(event) => setCategory(event.target.value)}
        >
          {['general', 'billing', 'technical', 'training', 'other'].map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-ticket-message">
          What is happening?
        </label>
        <textarea
          id="fc-ticket-message"
          rows={5}
          value={message}
          onChange={(event) => setMessage(event.target.value)}
        />
      </div>

      {/* Priority is deliberately not offered. It is staff's triage decision,
          and a picker here would mean every ticket arrives urgent. */}
      {create.error && <p className="fc-text-sm fc-text-danger">{create.error.message}</p>}
    </Modal>
  );
}

function TicketDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const { data, isLoading } = useTicket(id);
  const { reply, setStatus } = useTicketActions();
  const [draft, setDraft] = useState('');

  const ticket = data as Ticket | undefined;
  const isClosed = ticket?.status === 'closed';

  return (
    <Modal
      title={ticket?.subject ?? 'Ticket'}
      variant="form"
      cancelLabel="Close"
      confirmLabel={isClosed ? 'Reopen' : 'Mark as done'}
      onCancel={onClose}
      onConfirm={() =>
        setStatus.mutate({ id, status: isClosed ? 'open' : 'closed' }, { onSuccess: onClose })
      }
    >
      {isLoading || !ticket ? (
        <Skeleton height={180} />
      ) : (
        <>
          <div className="fc-flex fc-flex-c fc-gap-8 fc-mb-16">
            <Tag tone={STATUS_TONE[ticket.status] ?? 'blue'}>
              {STATUS_LABEL[ticket.status] ?? ticket.status}
            </Tag>
            <span className="fc-text-xs fc-text-muted">{ticket.category}</span>
          </div>

          <div className="fc-ticket-thread">
            <div className="fc-bubble">
              <p>{ticket.message}</p>
              <time className="fc-text-xs fc-text-subtle">
                {new Date(ticket.created_at).toLocaleString()}
              </time>
            </div>

            {(ticket.replies ?? []).map((entry) => (
              <div
                key={entry.id}
                className={`fc-bubble${entry.author_role === 'user' ? ' fc-bubble--mine' : ''}`}
              >
                <span className="fc-text-xs fc-text-muted">
                  {entry.author_role === 'user' ? 'You' : (entry.author_name ?? 'Support')}
                </span>
                <p>{entry.message}</p>
                <time className="fc-text-xs fc-text-subtle">
                  {new Date(entry.created_at).toLocaleString()}
                </time>
              </div>
            ))}
          </div>

          {isClosed ? (
            <p className="fc-text-sm fc-text-muted">
              This ticket is closed. Reopen it if the problem is back.
            </p>
          ) : (
            <form
              className="fc-msg__composer fc-mt-16"
              onSubmit={(event) => {
                event.preventDefault();

                if (draft.trim() !== '' && !reply.isPending) {
                  reply.mutate({ id, message: draft.trim() }, { onSuccess: () => setDraft('') });
                }
              }}
            >
              <input
                value={draft}
                placeholder="Add a reply…"
                aria-label="Reply"
                onChange={(event) => setDraft(event.target.value)}
              />
              <Button
                variant="primary"
                type="submit"
                disabled={draft.trim() === '' || reply.isPending}
              >
                Send
              </Button>
            </form>
          )}

          {reply.error && <p className="fc-text-sm fc-text-danger">{reply.error.message}</p>}
        </>
      )}
    </Modal>
  );
}
