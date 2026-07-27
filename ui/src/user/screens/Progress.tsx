import { Suspense, lazy, useState, type ReactNode } from 'react';
import { Icon } from '@shared/components/Icon';
import {
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Skeleton,
  StatCard,
} from '@shared/components/ui';
import { useProgress, useProgressRecords } from '../api/queries';
import type { Progress as ProgressData, ProgressRange } from '../api/types';

/**
 * The Progress screen (W2.3).
 *
 * **The range filter is real here.** In the prototype it set state and
 * re-rendered identical data (gap §2, line 993) — four buttons that did nothing.
 * Each button now refetches, and the server answers at a different granularity:
 * days close up, weeks at a quarter, months across a year. The subtitle says
 * which, because a chart of monthly averages labelled like daily readings
 * misrepresents its own points.
 *
 * Chart.js arrives via `lazy()`, so a member who never opens this screen never
 * downloads it — and because the *empty* states live out here rather than
 * behind the boundary, a member with no data yet never downloads it either.
 * All four resolve to the same chunk; the import promise is shared.
 */
const CHARTS = () => import('@shared/components/charts/ProgressCharts');

const ReadingChart = lazy(() => CHARTS().then((m) => ({ default: m.ReadingChart })));
const StrengthChart = lazy(() => CHARTS().then((m) => ({ default: m.StrengthChart })));
const ConsistencyChart = lazy(() => CHARTS().then((m) => ({ default: m.ConsistencyChart })));
const MeasurementsChart = lazy(() => CHARTS().then((m) => ({ default: m.MeasurementsChart })));

const RANGES: Array<{ value: ProgressRange; label: string }> = [
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
  { value: 'quarter', label: '3M' },
  { value: 'year', label: 'Year' },
];

const BUCKET_NOTE: Record<ProgressData['bucket'], string> = {
  day: 'daily',
  week: 'weekly averages',
  month: 'monthly averages',
};

export function Progress() {
  const [range, setRange] = useState<ProgressRange>('month');
  const { data, isLoading, isFetching, error, refetch } = useProgress(range);

  if (error) {
    return (
      <>
        <PageHeader title="Progress" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Progress"
        subtitle={data ? `${data.from} to ${data.to} · ${BUCKET_NOTE[data.bucket]}` : undefined}
        actions={
          <div className="fc-layout-toggle" role="group" aria-label="Range">
            {RANGES.map((option) => (
              <button
                key={option.value}
                type="button"
                className={range === option.value ? 'active' : ''}
                aria-pressed={range === option.value}
                onClick={() => setRange(option.value)}
              >
                {option.label}
              </button>
            ))}
          </div>
        }
      />

      {isLoading || !data ? (
        <div className="fc-grid fc-grid-2 fc-gap-16" aria-busy="true" aria-label="Loading">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} height={260} />
          ))}
        </div>
      ) : (
        // Dimmed rather than unmounted while the next range loads: swapping four
        // charts for four spinners on every filter click reads as the page
        // breaking, not as data arriving.
        <div className={isFetching ? 'fc-refreshing' : undefined}>
          <Suspense fallback={<ChartSkeletons />}>
            <ChartGrid data={data} />
          </Suspense>

          <div className="fc-grid fc-grid-3 fc-gap-16 fc-mb-24">
            <StatCard
              icon="dumbbell"
              value={data.totals.workouts_completed}
              label="Workouts completed"
            />
            <StatCard
              icon="flame"
              value={data.totals.calories_burned.toLocaleString()}
              label="Calories burned"
              tone="var(--accent2)"
            />
            <StatCard
              icon="trophy"
              value={data.totals.personal_records}
              label="Personal records"
              tone="var(--warning)"
            />
          </div>

          <Records />
        </div>
      )}
    </>
  );
}

function ChartGrid({ data }: { data: ProgressData }) {
  const hasWeight = data.weight.some((value) => value !== null);
  const hasWorkouts = data.consistency.some((value) => value > 0);

  return (
    <>
      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        <ChartCard title="Weight history">
          {hasWeight ? (
            <ReadingChart labels={data.labels} values={data.weight} label="Weight" unit="kg" />
          ) : (
            <ChartEmpty>
              Log a weight on the Health screen and it appears here. Two readings make a trend.
            </ChartEmpty>
          )}
        </ChartCard>

        <ChartCard title="Strength improvements">
          {data.strength.length > 0 ? (
            <StrengthChart labels={data.labels} series={data.strength} />
          ) : (
            <ChartEmpty>
              Your three most-trained lifts appear here once you have logged sets with a weight
              against them.
            </ChartEmpty>
          )}
        </ChartCard>
      </div>

      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        <ChartCard title="Workout consistency">
          {hasWorkouts ? (
            <ConsistencyChart labels={data.labels} values={data.consistency} />
          ) : (
            <ChartEmpty>No workouts completed in this range yet.</ChartEmpty>
          )}
        </ChartCard>

        <ChartCard title="Body measurements">
          {/* A radar needs at least three axes to be a shape rather than a line,
              and a single session has nothing to compare against. */}
          {data.measurements.length > 0 ? (
            <MeasurementsChart points={data.measurements} />
          ) : (
            <ChartEmpty>
              Record measurements on the Health screen. Two sets let you see the shape change, not
              just the numbers.
            </ChartEmpty>
          )}
        </ChartCard>
      </div>
    </>
  );
}

function Records() {
  const { data, isLoading } = useProgressRecords();

  if (isLoading) {
    return <Skeleton height={120} />;
  }

  const items = data?.items ?? [];

  if (items.length === 0) {
    return (
      <EmptyState title="No personal records yet">
        Finish a workout and your best lifts are recorded here automatically.
      </EmptyState>
    );
  }

  return (
    <Card>
      <h3 className="fc-text-lg fc-mb-16">Personal records</h3>
      <ul className="fc-history-list">
        {items.map((record) => (
          <li key={`${record.exercise_name}-${record.record_type}`}>
            <span className="fc-text-xs fc-text-muted">
              {record.achieved_at ? record.achieved_at.slice(0, 10) : '—'}
            </span>
            <span>{record.exercise_name}</span>
            <span className="fc-text-xs fc-text-muted">{RECORD_LABELS[record.record_type]}</span>
            <strong>
              {record.value} {record.unit}
            </strong>
          </li>
        ))}
      </ul>
    </Card>
  );
}

const RECORD_LABELS: Record<string, string> = {
  max_weight: 'Heaviest',
  max_reps: 'Most reps',
  max_volume: 'Most volume',
  best_time: 'Best time',
};

function ChartCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card>
      <h3 className="fc-text-sm fc-text-muted fc-uppercase fc-mb-16">{title}</h3>
      {children}
    </Card>
  );
}

/**
 * The empty state a chart gets instead of axes drawn around no data.
 *
 * The prototype had none anywhere, so a new member — one weight reading, zero
 * sessions — met four charts that looked broken rather than four that said what
 * to do next.
 */
function ChartEmpty({ children }: { children: ReactNode }) {
  return (
    <div className="fc-chart-frame fc-chart-frame--empty">
      <Icon name="activity" size={24} />
      <p className="fc-text-sm fc-text-muted">{children}</p>
    </div>
  );
}

function ChartSkeletons() {
  return (
    <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24" aria-busy="true">
      <Skeleton height={260} />
      <Skeleton height={260} />
    </div>
  );
}
