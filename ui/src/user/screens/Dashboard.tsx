import { Link } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import { MiniBars } from '@shared/components/MiniBars';
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
import { useDashboard } from '../api/queries';
import type { ActivityEntry, Dashboard as DashboardPayload, WorkoutSummary } from '../api/types';
import { usePlayer } from '../state/player-context';

/**
 * The dashboard.
 *
 * **One request** (`GET /user/dashboard`, W1.6). The client-side composition
 * this screen shipped with in W1.5 is gone, and with it the second streak
 * implementation that lived here: the number now comes from the server's
 * StreakService, so the celebration screen and this screen can no longer
 * disagree about what day it is. The member's timezone is the server's, too —
 * `payload.date` is their today, not the browser's.
 *
 * Sections whose data lands in Phase 2 (nutrition, water, messages) are already
 * in the payload and already honest about being empty, so each renders its real
 * empty state rather than being commented out and forgotten.
 */
export function Dashboard() {
  const player = usePlayer();
  const { data, isLoading, error, refetch } = useDashboard();

  if (error) {
    return (
      <>
        <PageHeader title="Dashboard" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Dashboard" />
        <div className="fc-grid fc-grid-4 fc-mb-24">
          {[0, 1, 2, 3].map((key) => (
            <Skeleton key={key} height={92} />
          ))}
        </div>
        <Skeleton height={160} />
      </>
    );
  }

  const { greeting, stats, todays_workout: todays, upcoming_workout: upcoming } = data;

  return (
    <>
      <PageHeader title={`Hey, ${greeting.name}`} subtitle="Here is where you stand today." />

      <div className="fc-welcome fc-mb-24">
        <h2>{streakHeadline(greeting.streak_days)}</h2>
        <p>{monthlySummary(data)}</p>
      </div>

      <div className="fc-grid fc-grid-4 fc-mb-24">
        <StatCard icon="flame" value={stats.streak_days} label="Day streak" />
        <StatCard
          icon="activity"
          value={stats.calories_burned_today}
          label="Calories today"
          tone="var(--accent2)"
        />
        <StatCard
          icon="dumbbell"
          value={data.monthly_stats.workouts_completed}
          label={`Last ${data.monthly_stats.window_days} days`}
          tone="var(--info)"
        />
        <StatCard
          icon="clock"
          value={data.monthly_stats.avg_hours_per_week}
          label="Hours / week"
          tone="var(--purple)"
        />
      </div>

      <h2 className="fc-text-lg fc-mb-16">Today&rsquo;s workout</h2>

      {!todays && (
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

      {todays && (
        <TodaysWorkout
          workout={todays}
          onStart={() => player.start(todays.id)}
          starting={player.starting}
        />
      )}

      {upcoming && (
        <div className="fc-mt-16">
          <Card>
            <div className="fc-flex fc-flex-between fc-flex-c fc-gap-16 fc-flex-wrap">
              <div style={{ minWidth: 0 }}>
                <div className="fc-text-xs fc-text-muted">Up next</div>
                <div className="fc-font-bold fc-truncate">{upcoming.workout_name}</div>
                <div className="fc-text-xs fc-text-muted fc-mt-4">
                  {upcoming.scheduled_for ?? 'Unscheduled'}
                </div>
              </div>
              <Link to={`/workouts/${upcoming.id}`} className="fc-btn fc-btn--secondary fc-btn--sm">
                Details
              </Link>
            </div>
          </Card>
        </div>
      )}

      <div className="fc-grid fc-grid-2 fc-mt-24 fc-gap-16">
        <ThisWeek data={data} />
        <Nutrition data={data} />
      </div>

      <h2 className="fc-text-lg fc-mt-24 fc-mb-16">Recent activity</h2>

      {data.recent_activity.length === 0 && (
        <EmptyState title="Nothing yet">
          Your finished workouts and milestones will appear here.
        </EmptyState>
      )}

      {data.recent_activity.length > 0 && (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {data.recent_activity.map((entry) => (
            <ActivityRow
              key={`${entry.type}-${entry.occurred_at}-${entry.subject_id}`}
              entry={entry}
            />
          ))}
        </div>
      )}
    </>
  );
}

function ThisWeek({ data }: { data: DashboardPayload }) {
  const { weekly_chart: chart, monthly_stats: monthly } = data;
  const total = chart.calories.reduce((sum, value) => sum + value, 0);

  return (
    <Card>
      <div className="fc-flex fc-flex-between fc-flex-c fc-mb-16">
        <h3 className="fc-text-lg">Last 7 days</h3>
        <span className="fc-text-xs fc-text-muted">{total} kcal</span>
      </div>

      <MiniBars
        title="Calories burned per day"
        labels={chart.labels}
        values={chart.calories}
        unit="kcal"
      />

      {monthly.consistency_percentage !== null && (
        <div className="fc-mt-16">
          <div className="fc-flex fc-flex-between fc-text-xs fc-text-muted fc-mb-4">
            <span>Plan adherence</span>
            <span>{Math.round(monthly.consistency_percentage)}%</span>
          </div>
          <ProgressBar value={monthly.consistency_percentage} tone="var(--info)" />
        </div>
      )}
    </Card>
  );
}

/**
 * Macros and water. Both are read-only here until W2.1 ships the logging
 * screens — the card says where the numbers will come from rather than
 * pretending the feature is missing.
 */
function Nutrition({ data }: { data: DashboardPayload }) {
  const { nutrition, water } = data;
  const hasGoals = nutrition.calories.goal !== null;
  const logged = nutrition.calories.value > 0 || water.consumed_ml > 0;

  return (
    <Card>
      <h3 className="fc-text-lg fc-mb-16">Today&rsquo;s intake</h3>

      {!logged && !hasGoals && (
        <p className="fc-text-sm fc-text-muted">
          Meal and water logging arrives with the Nutrition screen. Anything a trainer records for
          you shows up here in the meantime.
        </p>
      )}

      {(logged || hasGoals) && (
        <div className="fc-flex fc-flex-column fc-gap-12">
          <MacroRow
            label="Calories"
            value={nutrition.calories.value}
            goal={nutrition.calories.goal}
            unit="kcal"
          />
          <MacroRow
            label="Protein"
            value={nutrition.protein_g.value}
            goal={nutrition.protein_g.goal}
            unit="g"
          />
          <MacroRow
            label="Carbs"
            value={nutrition.carbs_g.value}
            goal={nutrition.carbs_g.goal}
            unit="g"
          />
          <MacroRow
            label="Fat"
            value={nutrition.fat_g.value}
            goal={nutrition.fat_g.goal}
            unit="g"
          />
        </div>
      )}

      <div className="fc-mt-16">
        <div className="fc-flex fc-flex-between fc-text-xs fc-text-muted fc-mb-4">
          <span>
            <Icon name="activity" size={12} /> Water
          </span>
          <span>
            {water.consumed_ml} / {water.goal_ml} ml
          </span>
        </div>
        <ProgressBar
          value={water.goal_ml > 0 ? (water.consumed_ml / water.goal_ml) * 100 : 0}
          tone="var(--info)"
        />
      </div>
    </Card>
  );
}

function MacroRow({
  label,
  value,
  goal,
  unit,
}: {
  label: string;
  value: number;
  goal: number | null;
  unit: string;
}) {
  return (
    <div>
      <div className="fc-flex fc-flex-between fc-text-xs fc-text-muted fc-mb-4">
        <span>{label}</span>
        <span>
          {value}
          {goal === null ? '' : ` / ${goal}`} {unit}
        </span>
      </div>
      {/* No goal, no bar: a progress bar without a target is a decoration. */}
      {goal !== null && goal > 0 && <ProgressBar value={(value / goal) * 100} />}
    </div>
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

function ActivityRow({ entry }: { entry: ActivityEntry }) {
  return (
    <div className="fc-list-row">
      <div
        className="fc-stat__icon"
        style={{ background: 'var(--accent-d)', color: 'var(--accent)' }}
      >
        <Icon name={activityIcon(entry.type)} />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fc-font-bold fc-truncate">{entry.title}</div>
        <div className="fc-text-xs fc-text-muted fc-truncate">
          {entry.detail ? `${entry.detail} · ` : ''}
          {relativeTime(entry.occurred_at)}
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ helpers

function activityIcon(type: string) {
  if (type.startsWith('record')) {
    return 'flame' as const;
  }
  if (type.startsWith('message')) {
    return 'activity' as const;
  }

  return 'check' as const;
}

function streakHeadline(days: number): string {
  return days > 0 ? `${days}-day streak` : 'Start your streak today';
}

function monthlySummary({ monthly_stats: monthly }: DashboardPayload): string {
  if (monthly.workouts_completed === 0) {
    return 'No workouts logged yet — the first one starts the streak.';
  }

  const plural = monthly.workouts_completed === 1 ? '' : 's';

  return `${monthly.workouts_completed} workout${plural} and ${monthly.calories_burned} kcal in the last ${monthly.window_days} days.`;
}

/**
 * "3 hours ago" from an ISO timestamp.
 *
 * `Intl.RelativeTimeFormat` rather than a hand-rolled ladder, so a locale that
 * does not say "3 h ago" gets its own phrasing for free.
 */
function relativeTime(iso: string): string {
  const then = Date.parse(iso);

  if (!Number.isFinite(then)) {
    return '';
  }

  const seconds = Math.round((then - Date.now()) / 1000);
  const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
  ];

  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) {
      return format.format(Math.round(seconds / size), unit);
    }
  }

  return format.format(seconds, 'second');
}
