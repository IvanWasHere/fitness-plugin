import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  ProgressBar,
  SkeletonCards,
  Tag,
} from '@shared/components/ui';
import { useWorkouts, type WorkoutFilters } from '../api/queries';
import type { WorkoutSummary } from '../api/types';
import { usePlayer } from '../state/player-context';

/**
 * The workouts list, with the prototype's grid ⇄ list toggle preserved.
 *
 * The filters are real: the prototype's week/month/type controls set state and
 * re-rendered identical data. These go to the server as query parameters, and
 * the empty result has its own state rather than a blank rectangle.
 */
type Layout = 'grid' | 'list';

export function Workouts() {
  const [layout, setLayout] = useState<Layout>('grid');
  const [filters, setFilters] = useState<WorkoutFilters>({});
  const { data, isLoading, error, refetch } = useWorkouts(filters);

  const items = data?.items ?? [];

  return (
    <>
      <PageHeader
        title="Workouts"
        subtitle="Everything your trainers have assigned you."
        actions={
          <div className="fc-layout-toggle" role="group" aria-label="Layout">
            <button
              type="button"
              aria-pressed={layout === 'grid'}
              onClick={() => setLayout('grid')}
            >
              <Icon name="grid" title="Grid view" />
            </button>
            <button
              type="button"
              aria-pressed={layout === 'list'}
              onClick={() => setLayout('list')}
            >
              <Icon name="list" title="List view" />
            </button>
          </div>
        }
      />

      <div className="fc-flex fc-gap-8 fc-flex-wrap fc-mb-24">
        <label className="fc-visually-hidden" htmlFor="fc-workout-search">
          Search workouts
        </label>
        <input
          id="fc-workout-search"
          type="search"
          placeholder="Search workouts"
          style={{ maxWidth: 260 }}
          onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value }))}
        />

        <select
          aria-label="Difficulty"
          style={{ maxWidth: 180 }}
          onChange={(event) =>
            setFilters((current) => ({ ...current, difficulty: event.target.value }))
          }
        >
          <option value="">All difficulties</option>
          <option value="beginner">Beginner</option>
          <option value="intermediate">Intermediate</option>
          <option value="advanced">Advanced</option>
        </select>

        <select
          aria-label="Type"
          style={{ maxWidth: 180 }}
          onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value }))}
        >
          <option value="">All types</option>
          <option value="strength">Strength</option>
          <option value="cardio">Cardio</option>
          <option value="hiit">HIIT</option>
          <option value="flexibility">Flexibility</option>
          <option value="recovery">Recovery</option>
        </select>
      </div>

      {isLoading && <SkeletonCards count={6} />}

      {error && <ErrorState message={error.message} onRetry={() => void refetch()} />}

      {!isLoading && !error && items.length === 0 && (
        <EmptyState title="No workouts match">
          Try clearing the filters, or ask your trainer to assign something new.
        </EmptyState>
      )}

      {items.length > 0 && layout === 'grid' && (
        <div className="fc-grid fc-grid-3">
          {items.map((workout) => (
            <WorkoutCard key={workout.id} workout={workout} />
          ))}
        </div>
      )}

      {items.length > 0 && layout === 'list' && (
        <div className="fc-flex fc-flex-column fc-gap-8">
          {items.map((workout) => (
            <WorkoutRow key={workout.id} workout={workout} />
          ))}
        </div>
      )}
    </>
  );
}

function WorkoutCard({ workout }: { workout: WorkoutSummary }) {
  return (
    <Card interactive className="fc-workout-card">
      <div
        className="fc-workout-card__cover"
        style={
          workout.cover_image_url
            ? { backgroundImage: `url(${workout.cover_image_url})` }
            : undefined
        }
      >
        {!workout.cover_image_url && <Icon name="dumbbell" size={40} />}
        <Tag tone={workout.difficulty}>{workout.difficulty}</Tag>
      </div>

      <div className="fc-workout-card__body">
        <h3 className="fc-text-lg">{workout.workout_name}</h3>

        <div className="fc-meta">
          <span>
            <Icon name="clock" size={14} /> {workout.estimated_duration_minutes} min
          </span>
          <span>
            <Icon name="dumbbell" size={14} /> {workout.exercise_count}
          </span>
          <span>
            <Icon name="flame" size={14} /> {workout.calories_burn_estimate ?? 0} kcal
          </span>
        </div>

        {workout.muscle_groups.length > 0 && (
          <div className="fc-flex fc-gap-4 fc-flex-wrap">
            {workout.muscle_groups.slice(0, 3).map((muscle) => (
              <Tag key={muscle} tone="blue">
                {muscle}
              </Tag>
            ))}
          </div>
        )}

        {workout.progress_percentage > 0 && <ProgressBar value={workout.progress_percentage} />}

        <div className="fc-flex fc-gap-8 fc-mt-8" style={{ marginTop: 'auto' }}>
          <Link
            to={`/workouts/${workout.id}`}
            className="fc-btn fc-btn--secondary fc-btn--sm fc-btn--block"
          >
            Details
          </Link>
          <StartButton workout={workout} />
        </div>
      </div>
    </Card>
  );
}

function WorkoutRow({ workout }: { workout: WorkoutSummary }) {
  return (
    <div className="fc-list-row">
      <div className="fc-stat__icon" style={{ background: 'var(--bg2)', color: 'var(--accent)' }}>
        <Icon name="dumbbell" />
      </div>

      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="fc-flex fc-flex-c fc-gap-8">
          <span className="fc-font-bold fc-truncate">{workout.workout_name}</span>
          <Tag tone={workout.difficulty}>{workout.difficulty}</Tag>
        </div>
        <div className="fc-text-xs fc-text-muted fc-mt-4">
          {workout.estimated_duration_minutes} min · {workout.exercise_count} exercises ·{' '}
          {Math.round(workout.progress_percentage)}%
        </div>
      </div>

      <div className="fc-flex fc-gap-8">
        <Link to={`/workouts/${workout.id}`} className="fc-btn fc-btn--secondary fc-btn--sm">
          Details
        </Link>
        <StartButton workout={workout} />
      </div>
    </div>
  );
}

export function StartButton({
  workout,
  size = 'sm',
}: {
  workout: WorkoutSummary;
  size?: 'sm' | 'md' | 'lg';
}) {
  const player = usePlayer();
  const resumable = workout.resumable_session_id !== null;

  return (
    <Button
      variant="primary"
      size={size}
      icon="play"
      disabled={player.starting}
      onClick={() => (resumable ? player.open() : player.start(workout.id))}
    >
      {resumable ? 'Resume' : 'Start'}
    </Button>
  );
}
