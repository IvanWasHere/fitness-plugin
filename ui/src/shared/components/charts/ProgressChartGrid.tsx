import { Suspense, lazy, type ReactNode } from 'react';
import { Icon } from '@shared/components/Icon';
import { Card, Skeleton } from '@shared/components/ui';

/**
 * The four Progress charts as one grid, shared by the member app and the
 * trainer's view of a client (W2.3, W3.4).
 *
 * Both screens read the **same endpoint** — `TrainerService::progress()` calls
 * straight through to `ProgressService::range()` — so drawing them differently
 * would be two renderings of one payload drifting apart. The only thing that
 * genuinely differs is who the empty states are talking to: "log a weight on the
 * Health screen" is advice a member can act on and a trainer cannot, and copy
 * addressed to the wrong person is worse than no copy. Hence `subject`.
 *
 * Chart.js arrives via `lazy()`, so an app that never opens a progress screen
 * never downloads it — and because the empty states live *outside* the Suspense
 * boundary, a client with no data yet never downloads it either.
 */
const CHARTS = () => import('./ProgressCharts');

const ReadingChart = lazy(() => CHARTS().then((m) => ({ default: m.ReadingChart })));
const StrengthChart = lazy(() => CHARTS().then((m) => ({ default: m.StrengthChart })));
const ConsistencyChart = lazy(() => CHARTS().then((m) => ({ default: m.ConsistencyChart })));
const MeasurementsChart = lazy(() => CHARTS().then((m) => ({ default: m.MeasurementsChart })));

/**
 * The slice of `GET /progress` this grid draws.
 *
 * Declared structurally rather than imported from either app's `types.ts`: a
 * shared component reaching into `user/` would invert the dependency, and both
 * apps' fuller `Progress` types are assignable to this one.
 */
export interface ProgressChartData {
  labels: string[];
  weight: Array<number | null>;
  strength: Array<{ exercise_name: string; points: Array<number | null> }>;
  consistency: number[];
  measurements: Array<Record<string, number | null | string>>;
}

/** `null` addresses the member about their own data; a name addresses a trainer about a client's. */
export type ProgressSubject = string | null;

export function ProgressChartGrid({
  data,
  subject = null,
}: {
  data: ProgressChartData;
  subject?: ProgressSubject;
}) {
  const hasWeight = data.weight.some((value) => value !== null);
  const hasWorkouts = data.consistency.some((value) => value > 0);

  // The client is a person whose pronouns nobody has recorded, so the trainer
  // copy stays in they/them rather than guessing from a display name.
  const who = subject ?? 'You';

  return (
    <>
      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        <ChartCard title="Weight history">
          {hasWeight ? (
            <ReadingChart labels={data.labels} values={data.weight} label="Weight" unit="kg" />
          ) : (
            <ChartEmpty>
              {subject === null
                ? 'Log a weight on the Health screen and it appears here. Two readings make a trend.'
                : `${who} has not logged a weight in this range. Two readings make a trend.`}
            </ChartEmpty>
          )}
        </ChartCard>

        <ChartCard title="Strength improvements">
          {data.strength.length > 0 ? (
            <StrengthChart labels={data.labels} series={data.strength} />
          ) : (
            <ChartEmpty>
              {subject === null
                ? 'Your three most-trained lifts appear here once you have logged sets with a weight against them.'
                : `${who}'s three most-trained lifts appear here once they have logged sets with a weight against them.`}
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
              {subject === null
                ? 'Record measurements on the Health screen. Two sets let you see the shape change, not just the numbers.'
                : `${who} has not recorded measurements in this range. Two sets show the shape change, not just the numbers.`}
            </ChartEmpty>
          )}
        </ChartCard>
      </div>
    </>
  );
}

/** The grid's own fallback, so callers do not each invent a different one. */
export function ProgressChartSkeletons() {
  return (
    <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24" aria-busy="true">
      <Skeleton height={260} />
      <Skeleton height={260} />
    </div>
  );
}

/** The grid wrapped in its Suspense boundary — what callers normally want. */
export function LazyProgressCharts(props: { data: ProgressChartData; subject?: ProgressSubject }) {
  return (
    <Suspense fallback={<ProgressChartSkeletons />}>
      <ProgressChartGrid {...props} />
    </Suspense>
  );
}

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
