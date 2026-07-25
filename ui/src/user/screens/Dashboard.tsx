import { Link } from 'react-router-dom';
import { useSession } from '@shared/session-context';
import { Icon } from '@shared/components/Icon';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  ProgressBar,
  Skeleton,
  StatCard,
} from '@shared/components/ui';
import { formatDuration } from '@shared/hooks/timers';
import { useSessionHistory, useWorkouts } from '../api/queries';
import type { Session, WorkoutSummary } from '../api/types';
import { usePlayer } from '../state/player-context';

/**
 * The dashboard.
 *
 * **Composed client-side for now.** The contract's one-call aggregate
 * (`GET /user/dashboard`, plans/02-api-contract.md) is W1.6; until it lands this
 * screen derives what it can from the two endpoints that exist. Everything below
 * is therefore written as a single `useDashboard()` hook, so W1.6 replaces one
 * function rather than rewriting the screen — and the six-round-trip version the
 * prototype shipped never appears here at all.
 */
function useDashboard() {
  const workouts = useWorkouts();
  const history = useSessionHistory(1);

  const items = workouts.data?.items ?? [];
  const sessions = history.data?.items ?? [];

  const completed = sessions.filter((session) => session.status === 'completed');
  const thisWeek = completed.filter((session) => isWithinDays(session.log_date, 7));

  return {
    isLoading: workouts.isLoading || history.isLoading,
    error: workouts.error ?? history.error,
    refetch: () => {
      void workouts.refetch();
      void history.refetch();
    },
    todaysWorkout: pickTodaysWorkout(items),
    workoutCount: items.length,
    recent: completed.slice(0, 5),
    stats: {
      streakDays: streakFrom(completed),
      weeklyWorkouts: thisWeek.length,
      weeklyMinutes: Math.round(thisWeek.reduce((sum, s) => sum + s.duration_seconds, 0) / 60),
      weeklyCalories: thisWeek.reduce((sum, s) => sum + (s.calories_burned ?? 0), 0),
    },
  };
}

export function Dashboard() {
  const { boot } = useSession();
  const player = usePlayer();
  const { isLoading, error, refetch, todaysWorkout, recent, stats } = useDashboard();

  const firstName = (boot.user?.display_name ?? '').split(' ')[0] || 'there';

  return (
    <>
      <PageHeader title={`Hey, ${firstName}`} subtitle="Here is where you stand today." />

      {error && <ErrorState message={error.message} onRetry={refetch} />}

      <div className="fc-welcome fc-mb-24">
        <h2>
          {stats.streakDays > 0 ? `${stats.streakDays}-day streak` : 'Start your streak today'}
        </h2>
        <p>
          {stats.weeklyWorkouts > 0
            ? `${stats.weeklyWorkouts} workout${stats.weeklyWorkouts === 1 ? '' : 's'} this week · ${stats.weeklyMinutes} minutes`
            : 'No workouts logged this week yet.'}
        </p>
      </div>

      <div className="fc-grid fc-grid-4 fc-mb-24">
        <StatCard icon="flame" value={stats.streakDays} label="Day streak" />
        <StatCard
          icon="dumbbell"
          value={stats.weeklyWorkouts}
          label="This week"
          tone="var(--info)"
        />
        <StatCard icon="clock" value={stats.weeklyMinutes} label="Minutes" tone="var(--purple)" />
        <StatCard
          icon="activity"
          value={stats.weeklyCalories}
          label="Calories"
          tone="var(--accent2)"
        />
      </div>

      <h2 className="fc-text-lg fc-mb-16">Today&rsquo;s workout</h2>

      {isLoading && <Skeleton height={160} />}

      {!isLoading && !todaysWorkout && (
        <EmptyState
          title="Nothing scheduled"
          action={
            <Link to="/workouts" className="fc-btn fc-btn--primary">
              Browse your workouts
            </Link>
          }
        >
          When a trainer assigns you a workout it shows up here.
        </EmptyState>
      )}

      {todaysWorkout && (
        <TodaysWorkout
          workout={todaysWorkout}
          onStart={() => player.start(todaysWorkout.id)}
          starting={player.starting}
        />
      )}

      <h2 className="fc-text-lg fc-mt-24 fc-mb-16">Recent activity</h2>

      {isLoading && <Skeleton height={120} />}

      {!isLoading && recent.length === 0 && (
        <EmptyState title="No workouts yet">Your finished workouts will appear here.</EmptyState>
      )}

      {recent.length > 0 && (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {recent.map((session) => (
            <RecentRow key={session.id} session={session} />
          ))}
        </div>
      )}
    </>
  );
}

function TodaysWorkout({
  workout,
  onStart,
  starting,
}: {
  workout: WorkoutSummary;
  onStart: () => void;
  starting: boolean;
}) {
  const player = usePlayer();
  const resumable = workout.resumable_session_id !== null;

  return (
    <Card interactive>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-16 fc-flex-wrap">
        <div style={{ minWidth: 0 }}>
          <h3 className="fc-text-lg">{workout.workout_name}</h3>
          <div className="fc-meta fc-mt-8">
            <span>
              <Icon name="clock" size={14} /> {workout.estimated_duration_minutes} min
            </span>
            <span>
              <Icon name="dumbbell" size={14} /> {workout.exercise_count} exercises
            </span>
            <span>
              <Icon name="flame" size={14} /> ~{workout.calories_burn_estimate ?? 0} kcal
            </span>
          </div>
        </div>

        <div className="fc-flex fc-gap-8">
          <Link to={`/workouts/${workout.id}`} className="fc-btn fc-btn--secondary">
            Details
          </Link>
          <Button
            variant="primary"
            icon="play"
            disabled={starting}
            onClick={resumable ? player.open : onStart}
          >
            {resumable ? 'Resume' : 'Start'}
          </Button>
        </div>
      </div>

      {workout.progress_percentage > 0 && (
        <div className="fc-mt-16">
          <ProgressBar value={workout.progress_percentage} />
          <p className="fc-text-xs fc-text-muted fc-mt-4">
            {Math.round(workout.progress_percentage)}% complete
          </p>
        </div>
      )}
    </Card>
  );
}

function RecentRow({ session }: { session: Session }) {
  return (
    <div className="fc-list-row">
      <div
        className="fc-stat__icon"
        style={{ background: 'var(--accent-d)', color: 'var(--accent)' }}
      >
        <Icon name="check" />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fc-font-bold fc-truncate">{session.workout_name}</div>
        <div className="fc-text-xs fc-text-muted">
          {session.log_date} · {formatDuration(session.duration_seconds)} ·{' '}
          {session.calories_burned ?? 0} kcal
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ helpers

function pickTodaysWorkout(items: WorkoutSummary[]): WorkoutSummary | null {
  if (items.length === 0) {
    return null;
  }

  const today = new Date().toISOString().slice(0, 10);

  return (
    items.find((item) => item.resumable_session_id !== null) ??
    items.find((item) => item.scheduled_for === today) ??
    items.find((item) => item.status !== 'completed') ??
    items[0]
  );
}

function isWithinDays(date: string | null, days: number): boolean {
  if (!date) {
    return false;
  }

  const then = Date.parse(`${date}T00:00:00Z`);

  return Number.isFinite(then) && Date.now() - then <= days * 86_400_000;
}

/**
 * Consecutive-day streak from completed sessions.
 *
 * Deliberately the same rule as the server's StreakService — distinct days, live
 * if the last one is today or yesterday — because this is a stand-in until
 * `GET /user/dashboard` returns the authoritative number in W1.6, and two
 * different answers on two screens is worse than a temporary one.
 */
function streakFrom(sessions: Session[]): number {
  const days = [...new Set(sessions.map((session) => session.log_date).filter(Boolean))]
    .sort()
    .reverse();

  if (days.length === 0) {
    return 0;
  }

  const today = new Date().toISOString().slice(0, 10);
  const yesterday = new Date(Date.now() - 86_400_000).toISOString().slice(0, 10);

  if (days[0] !== today && days[0] !== yesterday) {
    return 0;
  }

  let streak = 1;
  let cursor = days[0] as string;

  for (const day of days.slice(1)) {
    const expected = new Date(Date.parse(`${cursor}T00:00:00Z`) - 86_400_000)
      .toISOString()
      .slice(0, 10);
    if (day !== expected) {
      break;
    }
    streak += 1;
    cursor = day as string;
  }

  return streak;
}
