import { Link } from 'react-router-dom';
import { Card, EmptyState, ErrorState, Skeleton, StatCard } from '@shared/components/ui';
import { useTrainerDashboard } from '../api/queries';

/**
 * The trainer dashboard (plans/06-trainer-app.md §1, W3.4).
 *
 * **Needs attention comes first, and that is the design.** 06 puts it plainly:
 * "a list of counts is a report; a list of *clients who need something* is a
 * tool. Build that first." So the clients who have gone quiet lead the screen
 * and the stat row sits under them.
 *
 * **No money appears anywhere** (Q2). Trainers are traced, not paid: revenue is
 * attributed to them in the admin's report and never shown here, because a
 * figure a trainer cannot act on and is not owed invites exactly the wrong
 * conversation.
 */
export function Dashboard() {
  const { data, isLoading, error, refetch } = useTrainerDashboard();

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Dashboard</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Dashboard</h1>
        </header>
        <Skeleton height={200} />
      </>
    );
  }

  return (
    <>
      <header className="fc-admin-head">
        <h1>Dashboard</h1>
      </header>

      <Card className="fc-mb-24">
        <h2 className="fc-text-lg fc-mb-8">Needs attention</h2>
        <p className="fc-text-sm fc-text-muted fc-mb-16">
          Clients with no completed session in the last fortnight.
        </p>

        {data.needs_attention.length === 0 ? (
          <EmptyState title="Everyone is training">
            No client has gone quiet. Nothing here needs you today.
          </EmptyState>
        ) : (
          <ul className="fc-history-list">
            {data.needs_attention.map((client) => (
              <li key={client.user_id}>
                <span className="fc-text-xs fc-text-muted">
                  {client.last_session_date ?? 'never trained'}
                </span>
                {/* Straight into the client, because the next thing a trainer
                    wants is to look at them, not to search for them. */}
                <Link to={`/clients/${client.user_id}`} className="fc-link">
                  {client.display_name ?? `Client #${client.user_id}`}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <div className="fc-grid fc-grid-4 fc-gap-16">
        <StatCard icon="user" value={data.counts.active_clients} label="Active clients" />
        <StatCard
          icon="bell"
          value={data.counts.pending_requests}
          label="Pending requests"
          tone="var(--warning)"
        />
        <StatCard
          icon="activity"
          value={data.counts.sessions_this_week}
          label="Sessions this week"
          tone="var(--info)"
        />
        <StatCard
          icon="dumbbell"
          value={data.counts.workouts_authored}
          label="Workouts you wrote"
          tone="var(--purple)"
        />
      </div>
    </>
  );
}
