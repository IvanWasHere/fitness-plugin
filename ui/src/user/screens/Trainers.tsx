import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Skeleton,
  Tag,
} from '@shared/components/ui';
import { useDirectory, useMyTrainers, useTrainerRequests } from '../api/queries';
import type { DirectoryTrainer, MyTrainer, TrainerEligibility } from '../api/types';

/**
 * The trainer directory and My Trainers (plans/04-user-app.md, Q4/Q14, W3.5).
 *
 * ## Two panels, one screen
 *
 * 04 puts My Trainers under `/profile`, but the member app has no profile screen
 * yet — it is unbuilt, and inventing one to host half a list would be a bigger
 * change than this package. Coaches you have and coaches you might have are the
 * same subject anyway, and the slot counter that governs both is one number, so
 * they sit here as two panels. When the profile screen lands this can move or be
 * linked to; nothing else depends on where it lives.
 *
 * ## The directory is shown to members who cannot use it
 *
 * Hiding it from somebody with no plan removes the clearest upgrade prompt in
 * the product. The cards render either way and the request button carries the
 * reason — which comes from the server's `eligibility` block rather than being
 * inferred here, so the button and the endpoint that would refuse it can never
 * disagree.
 */
export function Trainers() {
  const [panel, setPanel] = useState<'find' | 'mine'>('find');

  return (
    <>
      <PageHeader title="Trainers" subtitle="Find a coach, or manage the ones you have." />

      <div className="fc-tabs fc-mb-16" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={panel === 'find'}
          className="fc-tab"
          onClick={() => setPanel('find')}
        >
          Find a trainer
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={panel === 'mine'}
          className="fc-tab"
          onClick={() => setPanel('mine')}
        >
          My trainers
        </button>
      </div>

      {panel === 'find' ? <Directory /> : <MyTrainersPanel />}
    </>
  );
}

function Directory() {
  const [draft, setDraft] = useState('');
  const [search, setSearch] = useState('');
  const [specialization, setSpecialization] = useState('');

  // Debounced, like the client list: typing a name should be one query.
  useEffect(() => {
    const timer = setTimeout(() => setSearch(draft), 300);

    return () => clearTimeout(timer);
  }, [draft]);

  const { data, isLoading, error, refetch } = useDirectory(search, specialization);

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  return (
    <>
      {data && <SlotNote eligibility={data.eligibility} />}

      <div className="fc-admin-toolbar fc-mb-16">
        <label className="fc-search">
          <Icon name="list" size={14} />
          <input
            type="search"
            value={draft}
            placeholder="Search by name or specialism…"
            aria-label="Search trainers"
            onChange={(event) => setDraft(event.target.value)}
          />
        </label>

        {/* Derived from trainers who are actually listable, so the filter can
            never offer a value that returns nothing. */}
        <select
          value={specialization}
          aria-label="Filter by specialism"
          onChange={(event) => setSpecialization(event.target.value)}
        >
          <option value="">All specialisms</option>
          {(data?.specializations ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>

      {isLoading && <Skeleton height={260} />}

      {!isLoading && (data?.items.length ?? 0) === 0 && (
        <EmptyState title={search || specialization ? 'No match' : 'No trainers available'}>
          {search || specialization
            ? 'No trainer matches that. Try a different search.'
            : 'Nobody is taking new clients at the moment. Check back soon.'}
        </EmptyState>
      )}

      <div className="fc-grid fc-grid-2 fc-gap-16">
        {(data?.items ?? []).map((trainer) => (
          <TrainerCard
            key={trainer.trainer_id}
            trainer={trainer}
            eligibility={data?.eligibility ?? null}
          />
        ))}
      </div>
    </>
  );
}

function TrainerCard({
  trainer,
  eligibility,
}: {
  trainer: DirectoryTrainer;
  eligibility: TrainerEligibility | null;
}) {
  return (
    <Card>
      <div className="fc-flex fc-flex-c fc-gap-12 fc-mb-12">
        <span className="fc-avatar" aria-hidden="true">
          {(trainer.display_name ?? '?').slice(0, 1).toUpperCase()}
        </span>

        <div className="fc-grow fc-flex fc-flex-column">
          <strong>{trainer.display_name}</strong>
          <span className="fc-text-xs fc-text-muted">
            {trainer.specialization ?? 'General coaching'}
          </span>
        </div>

        {/* A bare average implies a sample size it may not have, so the count
            travels with it. */}
        {trainer.rating !== null && (
          <Tag tone="blue">
            {trainer.rating} ({trainer.rating_count})
          </Tag>
        )}
      </div>

      {trainer.bio && <p className="fc-text-sm fc-text-muted fc-mb-12">{trainer.bio}</p>}

      <div className="fc-flex fc-flex-c fc-gap-8">
        <span className="fc-text-xs fc-text-muted fc-grow">
          {trainer.client_count} client{trainer.client_count === 1 ? '' : 's'}
          {trainer.max_clients !== null && ` of ${trainer.max_clients}`}
        </span>

        {/* The card's "Request" goes to the profile rather than opening a
            dialog here: the optional message belongs with the bio the member is
            deciding on, and a request sent from a card is one sent without
            reading anything. */}
        <RequestControl
          trainer={trainer}
          eligibility={eligibility}
          requestHref={`/trainers/${trainer.trainer_id}`}
        />

        <Link className="fc-btn" to={`/trainers/${trainer.trainer_id}`}>
          View
        </Link>
      </div>
    </Card>
  );
}

/**
 * The three states 04 asks for, plus the two this member is already in.
 *
 * A trainer they have asked, or who already coaches them, gets a label rather
 * than a button that would answer 409.
 */
export function RequestControl({
  trainer,
  eligibility,
  onRequest,
  requestHref,
}: {
  trainer: DirectoryTrainer;
  eligibility: TrainerEligibility | null;
  /** Opens the request form. Used on the profile, where the form lives. */
  onRequest?: () => void;
  /** Where to send the member to fill that form in. Used on a directory card. */
  requestHref?: string;
}) {
  const { withdraw } = useTrainerRequests();

  if (trainer.my_status === 'active') {
    return <Tag tone="green">Your coach</Tag>;
  }

  if (trainer.my_status === 'pending') {
    return (
      <Button disabled={withdraw.isPending} onClick={() => withdraw.mutate(trainer.trainer_id)}>
        {withdraw.isPending ? 'Withdrawing…' : 'Withdraw request'}
      </Button>
    );
  }

  // Disabled, not hidden: 04 is explicit that the reason has to be visible, and
  // `SlotNote` states it once above the list.
  if (eligibility && !eligibility.can_request) {
    return (
      <Button variant="secondary" disabled>
        Request
      </Button>
    );
  }

  if (!trainer.accepting_clients || !trainer.has_capacity) {
    return (
      <Button variant="secondary" disabled>
        Not taking clients
      </Button>
    );
  }

  if (requestHref) {
    return (
      <Link className="fc-btn fc-btn--primary" to={requestHref}>
        Request
      </Link>
    );
  }

  return (
    <Button variant="primary" onClick={onRequest}>
      Request
    </Button>
  );
}

/**
 * Why the request buttons are disabled, said once at the top of the list.
 *
 * Repeating it on every card would be noise; saying it nowhere would leave a row
 * of dead buttons with no explanation.
 *
 * **The `limit_reached` copy does not imply a mistake.** With grandfathering on
 * downgrade (Q16) "2 of 1 slots used" is a normal state somebody reaches by
 * changing plan, not by doing something wrong.
 */
export function SlotNote({ eligibility }: { eligibility: TrainerEligibility }) {
  if (eligibility.can_request) {
    const allowance =
      eligibility.trainers_allowed === null
        ? 'Your plan has no limit on trainers.'
        : `${eligibility.trainers_used} of ${eligibility.trainers_allowed} trainer slots used.`;

    return <p className="fc-text-xs fc-text-muted fc-mb-16">{allowance}</p>;
  }

  if (eligibility.reason === 'subscription_required') {
    return (
      <Card className="fc-mb-16">
        <p className="fc-text-sm fc-mb-8">
          <strong>Choose a plan to work with a trainer.</strong>
        </p>
        <p className="fc-text-xs fc-text-muted fc-mb-12">
          You can browse coaches now and pick one once you are subscribed.
        </p>
        <Link className="fc-btn fc-btn--primary" to="/subscription">
          See plans
        </Link>
      </Card>
    );
  }

  // A plan with **no** trainer slots is a different situation from a full one,
  // and the seeded free tier is exactly that (`max_trainers: 0`). "End a
  // coaching relationship to free a slot" is advice somebody with zero slots
  // cannot act on — they have no relationships and freeing one would not help.
  if (eligibility.trainers_allowed === 0) {
    return (
      <Card className="fc-mb-16">
        <p className="fc-text-sm fc-mb-8">
          <strong>Your plan does not include a trainer.</strong>
        </p>
        <p className="fc-text-xs fc-text-muted fc-mb-12">
          Browse the coaches here, and upgrade when you find someone you want to work with.
        </p>
        <Link className="fc-btn fc-btn--primary" to="/subscription">
          See plans
        </Link>
      </Card>
    );
  }

  return (
    <Card className="fc-mb-16">
      <p className="fc-text-sm fc-mb-8">
        <strong>
          {eligibility.trainers_used} of {eligibility.trainers_allowed} trainer slots used.
        </strong>
      </p>
      <p className="fc-text-xs fc-text-muted fc-mb-12">
        To work with somebody new, upgrade your plan or end a current coaching relationship.
      </p>
      <Link className="fc-btn fc-btn--primary" to="/subscription">
        Upgrade
      </Link>
    </Card>
  );
}

function MyTrainersPanel() {
  const { data, isLoading, error, refetch } = useMyTrainers();
  const { leave, setPrimary } = useTrainerRequests();

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return <Skeleton height={200} />;
  }

  if (data.items.length === 0) {
    return (
      <EmptyState title="No trainers yet">
        Coaches you ask, and coaches who have taken you on, appear here.
      </EmptyState>
    );
  }

  return (
    <>
      <SlotNote eligibility={data.eligibility} />

      <ul className="fc-history-list fc-history-list--tall">
        {data.items.map((entry) => (
          <MyTrainerRow
            key={entry.id}
            entry={entry}
            canSetPrimary={data.items.filter((item) => item.status === 'active').length > 1}
            onLeave={() => leave.mutate(entry.trainer_id)}
            onPrimary={() => setPrimary.mutate(entry.trainer_id)}
          />
        ))}
      </ul>
    </>
  );
}

function MyTrainerRow({
  entry,
  canSetPrimary,
  onLeave,
  onPrimary,
}: {
  entry: MyTrainer;
  canSetPrimary: boolean;
  onLeave: () => void;
  onPrimary: () => void;
}) {
  const pending = entry.status === 'pending';

  return (
    <li>
      <span className="fc-history-list__grow">
        <Link to={`/trainers/${entry.trainer_id}`}>{entry.display_name}</Link>
      </span>

      {entry.is_primary && <Tag tone="green">primary</Tag>}
      {pending && <Tag tone="orange">awaiting reply</Tag>}

      <span className="fc-text-xs fc-text-muted">
        {pending
          ? `asked ${entry.requested_at?.slice(0, 10) ?? ''}`
          : `since ${entry.assigned_date ?? ''}`}
      </span>

      {/* Only offered when there is a choice to make: one coach is already the
          primary one, and a button that cannot change anything is clutter. */}
      {!pending && canSetPrimary && !entry.is_primary && (
        <Button onClick={onPrimary}>Make primary</Button>
      )}

      <Button variant="danger" onClick={onLeave}>
        {pending ? 'Withdraw' : 'End coaching'}
      </Button>
    </li>
  );
}
