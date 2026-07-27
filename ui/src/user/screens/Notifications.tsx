import { useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { toIconName } from '@shared/components/icon-paths';
import { Button, Card, EmptyState, ErrorState, PageHeader, Skeleton } from '@shared/components/ui';
import {
  useActivity,
  useNotificationActions,
  useNotifications,
  usePreferences,
  useUpdatePreferences,
} from '../api/queries';
import type { Notification, NotificationType } from '../api/types';

/**
 * Notifications, the activity feed, and the switches that govern them (W2.4).
 *
 * Three panels on one screen rather than three destinations, because they are
 * the same question asked three ways: what happened, what you did, and what you
 * want to hear about. `plans/04-user-app.md` lists `/notifications` and no
 * `/activity`, so the feed lives here rather than inventing a nav entry the
 * plan does not have.
 *
 * The settings panel is the notification half of `PUT /user/preferences`; the
 * privacy half belongs to the Profile screen, which is not in this package.
 */
type Panel = 'inbox' | 'activity' | 'settings';

export function Notifications() {
  const [panel, setPanel] = useState<Panel>('inbox');

  return (
    <>
      <PageHeader
        title="Notifications"
        actions={
          <div className="fc-layout-toggle" role="group" aria-label="View">
            {(
              [
                ['inbox', 'Inbox'],
                ['activity', 'Activity'],
                ['settings', 'Settings'],
              ] as Array<[Panel, string]>
            ).map(([value, label]) => (
              <button
                key={value}
                type="button"
                className={panel === value ? 'active' : ''}
                aria-pressed={panel === value}
                onClick={() => setPanel(value)}
              >
                {label}
              </button>
            ))}
          </div>
        }
      />

      {panel === 'inbox' && <Inbox />}
      {panel === 'activity' && <ActivityPanel />}
      {panel === 'settings' && <SettingsPanel />}
    </>
  );
}

function Inbox() {
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useNotifications(unreadOnly, page);
  const { markRead, markAllRead, dismiss } = useNotificationActions();

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return <Skeleton height={280} />;
  }

  const pages = Math.max(1, Math.ceil(data.total / data.per_page));

  return (
    <>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-12 fc-flex-wrap fc-mb-16">
        <p className="fc-text-sm fc-text-muted">
          {data.unread_count === 0
            ? 'You are all caught up.'
            : `${data.unread_count} unread notification${data.unread_count === 1 ? '' : 's'}`}
        </p>

        <div className="fc-flex fc-gap-8">
          <Button
            onClick={() => {
              setUnreadOnly((value) => !value);
              setPage(1);
            }}
            aria-pressed={unreadOnly}
          >
            {unreadOnly ? 'Show all' : 'Unread only'}
          </Button>
          <Button
            variant="primary"
            icon="check"
            disabled={data.unread_count === 0 || markAllRead.isPending}
            onClick={() => markAllRead.mutate()}
          >
            Mark all read
          </Button>
        </div>
      </div>

      {data.items.length === 0 ? (
        <EmptyState title={unreadOnly ? 'Nothing unread' : 'No notifications yet'}>
          {unreadOnly
            ? 'Everything here has been read.'
            : 'Finishing a workout, hitting a record or hearing from a trainer will show up here.'}
        </EmptyState>
      ) : (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {data.items.map((notification) => (
            <NotificationRow
              key={notification.id}
              notification={notification}
              onRead={() => markRead.mutate(notification.id)}
              onDismiss={() => dismiss.mutate(notification.id)}
            />
          ))}
        </div>
      )}

      {pages > 1 && (
        <div className="fc-flex fc-flex-c fc-gap-12 fc-mt-16">
          <Button icon="chevronLeft" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="fc-text-sm fc-text-muted">
            Page {page} of {pages}
          </span>
          <Button disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
            Next
          </Button>
        </div>
      )}
    </>
  );
}

/**
 * One notification.
 *
 * The body is a `<button>` because tapping it marks it read — the prototype used
 * a `<div onclick>`, which no keyboard or screen reader can reach. Dismiss is a
 * separate control rather than a swipe, which has no keyboard equivalent at all.
 */
function NotificationRow({
  notification,
  onRead,
  onDismiss,
}: {
  notification: Notification;
  onRead: () => void;
  onDismiss: () => void;
}) {
  const tone = notification.color ?? TYPE_TONES[notification.type] ?? 'var(--accent)';

  return (
    <Card className={notification.is_read ? '' : 'fc-notif--unread'}>
      <div className="fc-flex fc-flex-c fc-gap-12">
        <span
          className="fc-stat__icon"
          style={{ background: `color-mix(in srgb, ${tone} 14%, transparent)`, color: tone }}
        >
          <Icon
            name={toIconName(notification.icon ?? TYPE_ICONS[notification.type] ?? 'bell')}
            size={18}
          />
        </span>

        <button
          type="button"
          className="fc-notif__body"
          onClick={onRead}
          disabled={notification.is_read}
        >
          <span className="fc-font-bold">
            {notification.title}
            {/* The unread state is carried by the left border, which is colour
                alone — so it is also stated in text for anyone who cannot see
                it. Not `.fc-badge-dot`: that is `position: absolute` for a
                positioned ancestor the card does not provide, and in here it
                escaped to the top-right corner of the page. */}
            {!notification.is_read && <span className="fc-visually-hidden"> (unread)</span>}
          </span>
          {notification.body && (
            <span className="fc-text-sm fc-text-muted">{notification.body}</span>
          )}
          <span className="fc-text-xs fc-text-subtle">{relativeTime(notification.created_at)}</span>
        </button>

        <button
          type="button"
          className="fc-btn fc-btn--icon"
          onClick={onDismiss}
          aria-label={`Dismiss ${notification.title}`}
        >
          <Icon name="x" size={14} />
        </button>
      </div>
    </Card>
  );
}

const TYPE_TONES: Partial<Record<NotificationType, string>> = {
  workout: 'var(--accent)',
  achievement: 'var(--warning)',
  message: 'var(--info)',
  subscription: 'var(--purple)',
  progress: 'var(--accent2)',
  support: 'var(--info)',
  system: 'var(--text2)',
};

const TYPE_ICONS: Partial<Record<NotificationType, string>> = {
  workout: 'dumbbell',
  achievement: 'trophy',
  message: 'bell',
  subscription: 'target',
  progress: 'activity',
  support: 'bell',
  system: 'bell',
};

function ActivityPanel() {
  const [types, setTypes] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading, error, refetch } = useActivity(page, types);

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return <Skeleton height={280} />;
  }

  const pages = Math.max(1, Math.ceil(data.total / data.per_page));

  return (
    <>
      {data.available_types.length > 1 && (
        <div className="fc-flex fc-gap-8 fc-flex-wrap fc-mb-16">
          <FilterChip label="All" active={types === ''} onClick={() => setTypes('')} />
          {data.available_types.map((type) => (
            <FilterChip
              key={type}
              label={humanType(type)}
              active={types === type}
              onClick={() => {
                setTypes(type);
                setPage(1);
              }}
            />
          ))}
        </div>
      )}

      {data.items.length === 0 ? (
        <EmptyState title="Nothing here yet">
          Workouts, meals and measurements you log all appear in this feed.
        </EmptyState>
      ) : (
        <Card>
          <ul className="fc-history-list fc-history-list--tall">
            {data.items.map((entry, index) => (
              <li key={`${entry.type}-${entry.occurred_at}-${index}`}>
                <span className="fc-text-xs fc-text-muted">{shortDate(entry.occurred_at)}</span>
                <span>{entry.title}</span>
                {entry.detail && <span className="fc-text-xs fc-text-muted">{entry.detail}</span>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {pages > 1 && (
        <div className="fc-flex fc-flex-c fc-gap-12 fc-mt-16">
          <Button icon="chevronLeft" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="fc-text-sm fc-text-muted">
            Page {page} of {pages}
          </span>
          <Button disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
            Next
          </Button>
        </div>
      )}
    </>
  );
}

function FilterChip({
  label,
  active,
  onClick,
}: {
  label: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    // Not `.fc-tab`: that is the segmented control, which is `flex: 1` and keys
    // its active style off `aria-selected`. Reusing it stretched four chips
    // across the full page width with no highlight on the selected one.
    <button
      type="button"
      className={`fc-chip${active ? ' fc-chip--on' : ''}`}
      aria-pressed={active}
      onClick={onClick}
    >
      {label}
    </button>
  );
}

const PREFERENCE_LABELS: Record<NotificationType, string> = {
  workout: 'Workout reminders and assignments',
  message: 'Messages from your trainer',
  achievement: 'Personal records and milestones',
  progress: 'Progress summaries',
  subscription: 'Billing and subscription',
  support: 'Support ticket replies',
  system: 'Service announcements',
};

/**
 * The switches.
 *
 * Each flips one setting and sends only that one, because the server merges —
 * `fc_users.preferences` is shared with the privacy settings, and a wholesale
 * write would drop them.
 *
 * Turning a category off stops it being *written*, not just displayed. So the
 * copy says so: a member who expects muted notifications to pile up unseen and
 * finds them missing later has been misled by a vaguer wording.
 */
function SettingsPanel() {
  const { data, isLoading, error, refetch } = usePreferences();
  const update = useUpdatePreferences();

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  if (isLoading || !data) {
    return <Skeleton height={280} />;
  }

  return (
    <Card>
      <h3 className="fc-text-lg fc-mb-8">What you hear about</h3>
      <p className="fc-text-sm fc-text-muted fc-mb-16">
        Switching a category off stops those notifications being created at all &mdash; they will
        not be waiting for you later.
      </p>

      <ul className="fc-switch-list">
        {(Object.keys(PREFERENCE_LABELS) as NotificationType[]).map((type) => (
          <li key={type}>
            <label htmlFor={`fc-pref-${type}`}>{PREFERENCE_LABELS[type]}</label>
            <input
              id={`fc-pref-${type}`}
              type="checkbox"
              role="switch"
              checked={data.notifications[type]}
              disabled={update.isPending}
              onChange={(event) => update.mutate({ [type]: event.target.checked })}
            />
          </li>
        ))}
      </ul>

      {update.error && <p className="fc-text-sm fc-text-danger">{update.error.message}</p>}
    </Card>
  );
}

/** "just now", "3h ago", or the date once it stops being useful as a duration. */
function relativeTime(iso: string | null): string {
  if (!iso) {
    return '';
  }

  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) {
    return '';
  }

  const seconds = Math.floor((Date.now() - then) / 1000);

  if (seconds < 60) {
    return 'just now';
  }
  if (seconds < 3600) {
    return `${Math.floor(seconds / 60)}m ago`;
  }
  if (seconds < 86_400) {
    return `${Math.floor(seconds / 3600)}h ago`;
  }
  if (seconds < 604_800) {
    return `${Math.floor(seconds / 86_400)}d ago`;
  }

  return iso.slice(0, 10);
}

function shortDate(iso: string): string {
  return iso.slice(0, 10);
}

/** "workout.completed" → "Workout completed". */
function humanType(type: string): string {
  const words = type.replace(/[._]/g, ' ');

  return words.charAt(0).toUpperCase() + words.slice(1);
}
