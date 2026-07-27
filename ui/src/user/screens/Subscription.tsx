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
import { useBilling, useBillingActions, usePaymentHistory, usePlans } from '../api/queries';
import type { BillingCycle, Plan, Subscription as Sub } from '../api/types';

/**
 * Subscription, plan selector and payment history (W3.2).
 *
 * **A member may hold several subscriptions at once** — one per trainer (Q3) —
 * so this renders a list, not "your plan". The platform tier and each coaching
 * plan sit side by side, and cancelling one says nothing about the others.
 *
 * Cancelling is always at period end: the member keeps what they paid for, the
 * status stays `active`, and the screen says when it ends rather than pretending
 * access has already stopped.
 */
const CYCLE_LABEL: Record<BillingCycle, string> = {
  weekly: 'week',
  monthly: 'month',
  quarterly: 'quarter',
  yearly: 'year',
};

const STATUS_TONE: Record<string, string> = {
  active: 'green',
  trialing: 'blue',
  past_due: 'orange',
  cancelled: 'red',
  expired: 'red',
};

export function Subscription() {
  const { data, isLoading, error, refetch } = useBilling();
  const [choosing, setChoosing] = useState(false);

  if (error) {
    return (
      <>
        <PageHeader title="Subscription" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Subscription" />
        <Skeleton height={220} />
      </>
    );
  }

  const active = data.items.filter((s) => s.status === 'active' || s.status === 'trialing');
  const past = data.items.filter((s) => s.status !== 'active' && s.status !== 'trialing');

  return (
    <>
      <PageHeader
        title="Subscription"
        subtitle={data.has_active ? undefined : 'You are on the free plan'}
        actions={
          <Button variant="primary" icon="plus" onClick={() => setChoosing(true)}>
            {data.has_active ? 'Change plan' : 'Choose a plan'}
          </Button>
        }
      />

      {active.length === 0 ? (
        <EmptyState
          title="No paid plan"
          action={
            <Button variant="primary" onClick={() => setChoosing(true)}>
              See the plans
            </Button>
          }
        >
          Logging workouts and health data is always free. A plan adds nutrition tracking, coaching
          and messaging.
        </EmptyState>
      ) : (
        <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
          {active.map((subscription) => (
            <SubscriptionCard key={subscription.id} subscription={subscription} />
          ))}
        </div>
      )}

      {past.length > 0 && (
        <Card className="fc-mb-24">
          <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Previous plans</h3>
          <ul className="fc-history-list">
            {past.map((subscription) => (
              <li key={subscription.id}>
                <span className="fc-text-xs fc-text-muted">{subscription.end_date ?? '—'}</span>
                <span>{subscription.plan_name}</span>
                <Tag tone={STATUS_TONE[subscription.status] ?? 'blue'}>{subscription.status}</Tag>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <PaymentHistory />

      {choosing && <PlanPicker onClose={() => setChoosing(false)} />}
    </>
  );
}

function SubscriptionCard({ subscription }: { subscription: Sub }) {
  const { cancel, resume } = useBillingActions();
  const [confirming, setConfirming] = useState(false);

  const busy = cancel.isPending || resume.isPending;

  return (
    <Card>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-12 fc-mb-8">
        <h3 className="fc-text-lg">{subscription.plan_name}</h3>
        <Tag tone={STATUS_TONE[subscription.status] ?? 'blue'}>{subscription.status}</Tag>
      </div>

      {subscription.trainer_name && (
        <p className="fc-text-sm fc-text-muted">Coaching with {subscription.trainer_name}</p>
      )}

      <p className="fc-text-sm fc-text-muted fc-mb-16">
        {subscription.price_paid !== null
          ? `${subscription.currency ?? ''} ${subscription.price_paid} per ${
              CYCLE_LABEL[subscription.cycle as BillingCycle] ?? subscription.cycle
            }`
          : 'No charge'}
      </p>

      {/* The status is still `active` here — saying "cancelled" would be wrong,
          and saying nothing would hide that it stops. */}
      {subscription.cancel_at_period_end ? (
        <>
          <p className="fc-admin-notice fc-text-sm">
            Ends on {subscription.end_date}. You keep everything until then.
          </p>
          <Button
            variant="primary"
            block
            disabled={busy}
            onClick={() => resume.mutate(subscription.id)}
          >
            {resume.isPending ? 'Resuming…' : 'Keep my plan'}
          </Button>
        </>
      ) : (
        <>
          <p className="fc-text-xs fc-text-muted fc-mb-8">
            {subscription.auto_renew
              ? `Renews on ${subscription.end_date}`
              : `Ends ${subscription.end_date}`}
          </p>
          <Button block disabled={busy} onClick={() => setConfirming(true)}>
            Cancel plan
          </Button>
        </>
      )}

      {(cancel.error || resume.error) && (
        <p className="fc-text-sm fc-text-danger">{(cancel.error ?? resume.error)?.message}</p>
      )}

      {confirming && (
        <Modal
          title="Cancel this plan?"
          tone="danger"
          confirmLabel={cancel.isPending ? 'Cancelling…' : 'Cancel plan'}
          cancelLabel="Keep it"
          confirmDisabled={cancel.isPending}
          onCancel={() => setConfirming(false)}
          onConfirm={() =>
            cancel.mutate(subscription.id, { onSuccess: () => setConfirming(false) })
          }
        >
          You keep {subscription.plan_name} until {subscription.end_date}, and nothing is charged
          after that. You can undo this any time before then.
        </Modal>
      )}
    </Card>
  );
}

/**
 * The plan selector.
 *
 * Prices come from the server and the checkout call sends only a plan id and a
 * cycle — never an amount. That is not a UI concern so much as the reason the UI
 * has nothing to get wrong.
 */
function PlanPicker({ onClose }: { onClose: () => void }) {
  const { data, isLoading } = usePlans();
  const { checkout } = useBillingActions();
  const [cycle, setCycle] = useState<BillingCycle>('monthly');

  const plans = data?.items ?? [];

  return (
    <Modal
      title="Choose a plan"
      variant="form"
      confirmLabel="Close"
      cancelLabel="Cancel"
      onConfirm={onClose}
      onCancel={onClose}
    >
      <div className="fc-layout-toggle fc-mb-16" role="group" aria-label="Billing cycle">
        {(['monthly', 'quarterly', 'yearly'] as BillingCycle[]).map((option) => (
          <button
            key={option}
            type="button"
            className={cycle === option ? 'active' : ''}
            aria-pressed={cycle === option}
            onClick={() => setCycle(option)}
          >
            {option}
          </button>
        ))}
      </div>

      {isLoading && <Skeleton height={180} />}

      {!isLoading && plans.length === 0 && (
        <p className="fc-text-sm fc-text-muted">No plans are on sale right now.</p>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {plans.map((plan) => (
          <PlanRow
            key={plan.id}
            plan={plan}
            cycle={cycle}
            busy={checkout.isPending}
            onChoose={() => checkout.mutate({ plan_id: plan.id, cycle })}
          />
        ))}
      </div>

      {checkout.error && <p className="fc-text-sm fc-text-danger">{checkout.error.message}</p>}
    </Modal>
  );
}

function PlanRow({
  plan,
  cycle,
  busy,
  onChoose,
}: {
  plan: Plan;
  cycle: BillingCycle;
  busy: boolean;
  onChoose: () => void;
}) {
  const price = plan.prices[cycle];

  return (
    <Card>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-12">
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="fc-font-bold">{plan.plan_name}</div>
          {plan.description && <div className="fc-text-xs fc-text-muted">{plan.description}</div>}
        </div>

        <div className="fc-text-right">
          {/* A plan not sold on this cycle says so, rather than showing a price
              from a different one. */}
          {price === null ? (
            <span className="fc-text-xs fc-text-muted">Not sold {CYCLE_LABEL[cycle]}ly</span>
          ) : (
            <div className="fc-font-bold">
              {plan.currency} {price}
            </div>
          )}
        </div>

        <Button variant="primary" size="sm" disabled={price === null || busy} onClick={onChoose}>
          Choose
        </Button>
      </div>
    </Card>
  );
}

function PaymentHistory() {
  const { data, isLoading } = usePaymentHistory();

  if (isLoading) {
    return <Skeleton height={120} />;
  }

  const items = data?.items ?? [];

  if (items.length === 0) {
    return (
      <Card>
        <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Payments</h3>
        <p className="fc-text-sm fc-text-muted">Nothing charged yet.</p>
      </Card>
    );
  }

  return (
    <Card>
      <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Payments</h3>
      <ul className="fc-history-list">
        {items.map((payment) => (
          <li key={payment.id}>
            <span className="fc-text-xs fc-text-muted">
              {payment.payment_date?.slice(0, 10) ?? '—'}
            </span>
            <span>{payment.plan_name ?? payment.payment_type}</span>
            <Tag tone={payment.status === 'completed' ? 'green' : 'red'}>{payment.status}</Tag>
            <strong>
              {payment.currency} {payment.amount}
            </strong>
          </li>
        ))}
      </ul>

      {/* A failed charge is the one row a member needs to act on. */}
      {items.some((p) => p.status === 'failed') && (
        <p className="fc-text-xs fc-text-muted fc-mt-8">
          A failed payment does not end your plan immediately — we retry, and tell you before
          anything stops.
        </p>
      )}
    </Card>
  );
}
