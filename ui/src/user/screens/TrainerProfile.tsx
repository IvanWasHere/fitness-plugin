import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Modal } from '@shared/components/Modal';
import { Card, ErrorState, PageHeader, Skeleton, Tag } from '@shared/components/ui';
import { useTrainerProfile, useTrainerRequests } from '../api/queries';
import { RequestControl, SlotNote } from './Trainers';

/**
 * A trainer's public profile and the request form (04, Q4, W3.5).
 *
 * Everything on this page comes from `TrainerDirectoryService::present()` — the
 * one public projection in the API — so there is nothing here that could show a
 * contact detail even by accident.
 *
 * The **message is optional and the form says so**. A required message turns a
 * one-tap action into a writing task, and a coach reading fifty blank-but-
 * mandatory notes learns nothing from any of them.
 */
export function TrainerProfile() {
  const { id } = useParams();
  const trainerId = Number(id);

  const { data, isLoading, error, refetch } = useTrainerProfile(trainerId);
  const { request } = useTrainerRequests();

  const [asking, setAsking] = useState(false);
  const [message, setMessage] = useState('');

  if (error) {
    return (
      <>
        <PageHeader title="Trainer" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Trainer" />
        <Skeleton height={320} />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={data.display_name ?? 'Trainer'}
        subtitle={data.specialization ?? 'General coaching'}
      />

      <p className="fc-text-xs fc-mb-16">
        <Link to="/trainers">← All trainers</Link>
      </p>

      <Card className="fc-mb-16">
        <div className="fc-flex fc-flex-c fc-gap-12 fc-mb-16">
          <span className="fc-avatar" aria-hidden="true">
            {(data.display_name ?? '?').slice(0, 1).toUpperCase()}
          </span>

          <div className="fc-grow fc-flex fc-flex-column">
            <strong>{data.display_name}</strong>
            <span className="fc-text-xs fc-text-muted">
              {data.client_count} client{data.client_count === 1 ? '' : 's'}
              {data.max_clients !== null && ` of ${data.max_clients}`}
            </span>
          </div>

          {data.rating !== null && (
            <Tag tone="blue">
              {data.rating} from {data.rating_count} client{data.rating_count === 1 ? '' : 's'}
            </Tag>
          )}
        </div>

        {data.bio ? (
          <p className="fc-text-sm">{data.bio}</p>
        ) : (
          <p className="fc-text-sm fc-text-muted">This coach has not written a bio yet.</p>
        )}

        {/* Display-only (Q2): the platform bills subscriptions, and nothing in
            the app multiplies by this. Shown because it is on the public
            profile and a member deciding between coaches will want it. */}
        {data.hourly_rate !== null && (
          <p className="fc-text-xs fc-text-muted fc-mt-12">
            Listed rate: {data.currency} {data.hourly_rate.toFixed(2)} per hour
          </p>
        )}
      </Card>

      <SlotNote eligibility={data.eligibility} />

      {!data.accepting_clients && (
        <Card className="fc-mb-16">
          <p className="fc-text-sm fc-text-muted">
            This coach is not taking new clients right now.
          </p>
        </Card>
      )}

      <div className="fc-flex fc-gap-8">
        <RequestControl
          trainer={data}
          eligibility={data.eligibility}
          onRequest={
            data.accepting_clients && data.has_capacity
              ? () => {
                  setMessage('');
                  setAsking(true);
                }
              : undefined
          }
        />
      </div>

      {asking && (
        <Modal
          title={`Ask ${data.display_name} to coach you`}
          variant="form"
          confirmLabel={request.isPending ? 'Sending…' : 'Send request'}
          confirmDisabled={request.isPending}
          onCancel={() => setAsking(false)}
          onConfirm={() =>
            request.mutate(
              { trainerId, message: message.trim() || undefined },
              { onSuccess: () => setAsking(false) },
            )
          }
        >
          <div className="fc-field">
            <label className="fc-label" htmlFor="fc-req-message">
              Anything you want them to know? (optional)
            </label>
            <textarea
              id="fc-req-message"
              rows={4}
              maxLength={500}
              value={message}
              placeholder="What you are training for, and anything they should know."
              onChange={(event) => setMessage(event.target.value)}
            />
            <span className="fc-text-xs fc-text-muted">{message.length}/500</span>
          </div>

          {request.error && <p className="fc-text-sm fc-text-danger">{request.error.message}</p>}
        </Modal>
      )}
    </>
  );
}
