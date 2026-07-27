import { useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { Button, Card, EmptyState, ErrorState, Skeleton } from '@shared/components/ui';
import { useRequestActions, useRequests } from '../api/queries';
import type { ClientRequest } from '../api/types';

/**
 * The inbound client request queue (Q4, plans/06-trainer-app.md §9, W3.4).
 *
 * Every row is a person waiting for an answer, which is why declining asks for a
 * reason and why the screen says how full the trainer is before they accept —
 * capacity is checked at accept, and finding that out *after* clicking is a
 * worse experience than being told first.
 */
export function Requests() {
  const { data, isLoading, error, refetch } = useRequests();
  const [declining, setDeclining] = useState<ClientRequest | null>(null);
  const [reason, setReason] = useState('');
  const { accept, decline } = useRequestActions();

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Requests</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Requests</h1>
        </header>
        <Skeleton height={200} />
      </>
    );
  }

  const { used, max } = data.capacity;
  const full = max !== null && used >= max;

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Requests</h1>
          <p className="fc-text-sm fc-text-muted">
            {/* `max: null` is "no limit set", not "no room" — a fresh trainer
                with an unconfigured profile must not read as full. */}
            {max === null
              ? `${used} client${used === 1 ? '' : 's'} · no limit set`
              : `${used} of ${max} client slots used`}
          </p>
        </div>
      </header>

      {full && (
        <p className="fc-admin-notice fc-mb-16">
          <span aria-hidden="true">⚠</span> You are at your client limit. Raise it in your profile
          before accepting anybody else.
        </p>
      )}

      {data.items.length === 0 ? (
        <EmptyState title="Nothing waiting">
          When somebody asks you to coach them, their request appears here.
        </EmptyState>
      ) : (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {data.items.map((request) => (
            <Card key={request.id}>
              <div className="fc-flex fc-flex-c fc-gap-12 fc-flex-wrap">
                <span className="fc-avatar fc-avatar--sm" aria-hidden="true">
                  {(request.display_name ?? '?').slice(0, 1).toUpperCase()}
                </span>

                <div style={{ flex: 1, minWidth: '12rem' }}>
                  <div className="fc-font-bold">{request.display_name ?? 'Someone'}</div>
                  <div className="fc-text-xs fc-text-muted">
                    {request.fitness_goal ?? 'No goal set'}
                    {request.requested_at ? ` · asked ${request.requested_at.slice(0, 10)}` : ''}
                  </div>
                  {request.request_message && (
                    <p className="fc-text-sm fc-mt-8">&ldquo;{request.request_message}&rdquo;</p>
                  )}
                </div>

                <div className="fc-flex fc-gap-8">
                  <Button
                    variant="primary"
                    disabled={full || accept.isPending}
                    onClick={() => accept.mutate(request.id)}
                  >
                    Accept
                  </Button>
                  <Button
                    disabled={decline.isPending}
                    onClick={() => {
                      setReason('');
                      setDeclining(request);
                    }}
                  >
                    Decline
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      {accept.error && <p className="fc-text-sm fc-text-danger">{accept.error.message}</p>}

      {declining && (
        <Modal
          title={`Decline ${declining.display_name ?? 'this request'}?`}
          variant="form"
          confirmLabel={decline.isPending ? 'Declining…' : 'Decline'}
          cancelLabel="Keep it"
          tone="danger"
          confirmDisabled={decline.isPending}
          onCancel={() => setDeclining(null)}
          onConfirm={() =>
            decline.mutate(
              { id: declining.id, reason: reason.trim() || undefined },
              { onSuccess: () => setDeclining(null) },
            )
          }
        >
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-decline-reason">
              Reason (optional, sent to them)
            </label>
            <textarea
              id="fc-decline-reason"
              rows={3}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </div>

          <p className="fc-text-xs fc-text-muted">
            Declining frees the slot they were holding, so they can ask somebody else.
          </p>
        </Modal>
      )}
    </>
  );
}
