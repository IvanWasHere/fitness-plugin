import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import { Card, EmptyState, ErrorState, Skeleton, Tag } from '@shared/components/ui';
import { useClients } from '../api/queries';

/**
 * The client list (plans/06-trainer-app.md §2, W3.4).
 *
 * Two things earn their place in a row: **when they last trained**, because that
 * is what a trainer scans for, and **whether anybody else is coaching them**,
 * because a shared client is a different programming conversation (Q3) and the
 * trainer should know before they open the record rather than after.
 */
export function Clients() {
  const [draft, setDraft] = useState('');
  const [search, setSearch] = useState('');

  // Debounced: typing a five-letter name should be one query, not five.
  useEffect(() => {
    const timer = setTimeout(() => setSearch(draft), 300);

    return () => clearTimeout(timer);
  }, [draft]);

  const { data, isLoading, error, refetch } = useClients(search);

  if (error) {
    return (
      <>
        <header className="fc-admin-head">
          <h1>Clients</h1>
        </header>
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>Clients</h1>
          <p className="fc-text-sm fc-text-muted">
            {isLoading ? 'Loading…' : `${data?.total ?? 0} on your roster`}
          </p>
        </div>
      </header>

      <div className="fc-admin-toolbar">
        <label className="fc-search">
          <Icon name="list" size={14} />
          <input
            type="search"
            value={draft}
            placeholder="Search by name or email…"
            aria-label="Search clients"
            onChange={(event) => setDraft(event.target.value)}
          />
        </label>
      </div>

      {isLoading && <Skeleton height={240} />}

      {!isLoading && (data?.items.length ?? 0) === 0 && (
        <EmptyState title={search ? 'No match' : 'No clients yet'}>
          {search
            ? 'Nothing on your roster matches that.'
            : 'When you accept a client request, they appear here.'}
        </EmptyState>
      )}

      <div className="fc-flex fc-flex-column fc-gap-8">
        {(data?.items ?? []).map((client) => (
          <Card key={client.user_id}>
            <Link to={`/clients/${client.user_id}`} className="fc-client-row">
              <span className="fc-avatar fc-avatar--sm" aria-hidden="true">
                {(client.display_name ?? '?').slice(0, 1).toUpperCase()}
              </span>

              <span className="fc-client-row__body">
                <span className="fc-font-bold fc-truncate">
                  {client.display_name ?? `Client #${client.user_id}`}
                  {client.is_primary && <Tag tone="green">primary</Tag>}
                </span>
                <span className="fc-text-xs fc-text-muted fc-truncate">
                  {client.fitness_goal ?? 'No goal set'} · {client.sessions_completed} sessions
                </span>
              </span>

              {/* Q3 surfaced before the trainer opens the record: a client with
                  two coaches needs a different conversation. */}
              {client.trainer_count > 1 && (
                <Tag tone="purple">
                  +{client.trainer_count - 1} coach{client.trainer_count > 2 ? 'es' : ''}
                </Tag>
              )}

              <span className="fc-text-xs fc-text-muted">
                {client.last_session_date ? `last ${client.last_session_date}` : 'never trained'}
              </span>

              <Icon name="chevronRight" size={14} />
            </Link>
          </Card>
        ))}
      </div>
    </>
  );
}
