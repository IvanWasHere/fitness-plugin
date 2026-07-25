import { Link, useParams } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import { Card, ErrorState, PageHeader, ProgressBar, Skeleton, Tag } from '@shared/components/ui';
import { useWorkout } from '../api/queries';
import { StartButton } from './Workouts';

/**
 * One workout, with its exercises in the order the trainer arranged them.
 *
 * `video_locked` comes from the server when the member's plan does not include
 * video: the workout is still fully readable and the exercise list is intact,
 * with one line explaining what an upgrade adds. Hiding the whole screen behind
 * a paywall would remove the only thing that makes upgrading make sense.
 */
export function WorkoutDetail() {
  const params = useParams();
  const id = Number(params.id);
  const { data: workout, isLoading, error, refetch } = useWorkout(id);

  if (isLoading) {
    return (
      <div className="fc-flex fc-flex-column fc-gap-16">
        <Skeleton height={40} width="60%" />
        <Skeleton height={120} />
        <Skeleton height={280} />
      </div>
    );
  }

  if (error) {
    return (
      <>
        <BackLink />
        <ErrorState
          message={
            error.code === 'fc_workout_not_assigned'
              ? 'That workout is not in your programme.'
              : error.message
          }
          onRetry={() => void refetch()}
        />
      </>
    );
  }

  if (!workout) {
    return null;
  }

  return (
    <>
      <BackLink />

      <PageHeader
        title={workout.workout_name}
        subtitle={workout.description ?? undefined}
        actions={<StartButton workout={workout} size="md" />}
      />

      <Card className="fc-mb-24">
        <div className="fc-meta">
          <span>
            <Icon name="clock" size={14} /> {workout.estimated_duration_minutes} min
          </span>
          <span>
            <Icon name="dumbbell" size={14} /> {workout.exercise_count} exercises
          </span>
          <span>
            <Icon name="flame" size={14} /> ~{workout.calories_burn_estimate ?? 0} kcal
          </span>
          <span>
            <Icon name="target" size={14} /> {workout.difficulty}
          </span>
        </div>

        {(workout.muscle_groups.length > 0 || workout.equipment.length > 0) && (
          <div className="fc-flex fc-gap-4 fc-flex-wrap fc-mt-16">
            {workout.muscle_groups.map((muscle) => (
              <Tag key={muscle} tone="blue">
                {muscle}
              </Tag>
            ))}
            {workout.equipment.map((item) => (
              <Tag key={item} tone="purple">
                {item}
              </Tag>
            ))}
          </div>
        )}

        {workout.progress_percentage > 0 && (
          <div className="fc-mt-16">
            <ProgressBar value={workout.progress_percentage} />
            <p className="fc-text-xs fc-text-muted fc-mt-4">
              {Math.round(workout.progress_percentage)}% complete · finished{' '}
              {workout.times_completed} time{workout.times_completed === 1 ? '' : 's'}
            </p>
          </div>
        )}

        {workout.video_locked && (
          <p className="fc-text-xs fc-text-muted fc-mt-16">
            <Icon name="play" size={12} /> Exercise videos are part of a paid plan.
          </p>
        )}
      </Card>

      <h2 className="fc-text-lg fc-mb-16">Exercises</h2>

      <ol className="fc-flex fc-flex-column fc-gap-8" style={{ listStyle: 'none' }}>
        {workout.exercises.map((exercise, index) => (
          <li key={exercise.id}>
            <div className="fc-exercise-row">
              <span className="fc-exercise-row__index">{index + 1}</span>

              <div className="fc-exercise-row__info">
                <h4>{exercise.exercise_name}</h4>
                <div className="fc-meta">
                  <span>
                    {exercise.default_sets} ×{' '}
                    {exercise.metric === 'seconds'
                      ? `${exercise.default_reps}s`
                      : `${exercise.default_reps}`}
                  </span>
                  {exercise.default_weight_kg > 0 && <span>{exercise.default_weight_kg} kg</span>}
                  <span>
                    <Icon name="clock" size={12} /> {exercise.default_rest_seconds}s rest
                  </span>
                </div>
                {exercise.notes && (
                  <p className="fc-text-xs fc-text-subtle fc-mt-4">{exercise.notes}</p>
                )}
              </div>
            </div>
          </li>
        ))}
      </ol>
    </>
  );
}

function BackLink() {
  return (
    <Link to="/workouts" className="fc-link fc-flex fc-flex-c fc-gap-4 fc-mb-16">
      <Icon name="chevronLeft" size={14} /> All workouts
    </Link>
  );
}
