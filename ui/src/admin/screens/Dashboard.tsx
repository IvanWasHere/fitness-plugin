import { Card, ErrorState, Skeleton, StatCard } from '@shared/components/ui';
import { useAdminDashboard } from '../api/queries';

/**
 * The admin dashboard (W2.5).
 *
 * **One request.** The prototype fired ten unawaited `db.count()` calls, each
 * calling `m.redraw()`, so mounting it rendered ten times and the cards filled
 * in a random order (05, "fixes carried from the prototype").
 *
 * The lists matter more than the counts. "12 new members" is a number an
 * administrator can do nothing with; the eight names, and the failed payments
 * beside them, are the actual work.
 */
export function Dashboard() {
  const { data, isLoading, error, refetch } = useAdminDashboard();

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Dashboard</h1>
        </header>
        <div className="fc-grid fc-grid-4 fc-gap-16">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} height={92} />
          ))}
        </div>
      </>
    );
  }

  const money = (amount: number) =>
    `${data.revenue.currency === 'USD' ? '$' : ''}${amount.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Dashboard</h1>
          <p className="fc-text-sm fc-text-muted">
            Revenue window {data.revenue.from} to {data.revenue.to}
          </p>
        </div>
      </header>

      <div className="fc-grid fc-grid-4 fc-gap-16 fc-mb-24">
        <StatCard icon="user" value={data.counts.members} label="Members" />
        <StatCard
          icon="trophy"
          value={data.counts.trainers}
          label="Trainers"
          tone="var(--purple)"
        />
        <StatCard
          icon="dumbbell"
          value={data.counts.workouts}
          label="Workouts"
          tone="var(--info)"
        />
        <StatCard icon="flame" value={data.counts.foods} label="Foods" tone="var(--accent2)" />
        <StatCard
          icon="target"
          value={data.counts.active_subscriptions}
          label="Active subscriptions"
        />
        <StatCard
          icon="activity"
          value={data.counts.sessions_this_week}
          label="Sessions this week"
          tone="var(--info)"
        />
        <StatCard
          icon="bell"
          value={data.counts.open_tickets}
          label="Open tickets"
          tone="var(--warning)"
        />
        {/* The one count that is a problem rather than a total. */}
        <StatCard
          icon="clock"
          value={data.inactive_members}
          label="Inactive 30+ days"
          tone="var(--danger)"
        />
      </div>

      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        <Card>
          <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Revenue</h3>
          <div className="fc-flex fc-gap-32 fc-flex-wrap">
            <div>
              <div className="fc-stat__value">{money(data.revenue.mrr)}</div>
              {/* Yearly plans are normalised to a monthly figure — quoting a
                  year's price as this month's recurring revenue is how a
                  dashboard flatters itself. */}
              <div className="fc-stat__label">MRR (normalised)</div>
            </div>
            <div>
              <div className="fc-stat__value">{money(data.revenue.window_total)}</div>
              <div className="fc-stat__label">
                Collected · {data.revenue.window_payments} payment
                {data.revenue.window_payments === 1 ? '' : 's'}
              </div>
            </div>
          </div>
        </Card>

        <Card>
          <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Failed payments</h3>
          {data.failed_payments.length === 0 ? (
            <p className="fc-text-sm fc-text-muted">None. Nothing to chase.</p>
          ) : (
            <ul className="fc-history-list">
              {data.failed_payments.map((payment) => (
                <li key={payment.id}>
                  <span className="fc-text-xs fc-text-muted">
                    {payment.created_at.slice(0, 10)}
                  </span>
                  <span>{payment.display_name ?? 'Unknown member'}</span>
                  <strong>{money(payment.amount)}</strong>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card>
        <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">Recent signups</h3>
        {data.recent_signups.length === 0 ? (
          <p className="fc-text-sm fc-text-muted">No members yet.</p>
        ) : (
          <ul className="fc-history-list">
            {data.recent_signups.map((member) => (
              <li key={member.id}>
                <span className="fc-text-xs fc-text-muted">{member.created_at.slice(0, 10)}</span>
                <span>{member.display_name ?? '—'}</span>
                <span className="fc-text-xs fc-text-muted">{member.email}</span>
                <span className="fc-text-xs fc-text-muted">{member.status}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  );
}
