/**
 * Wire types for the workout domain, mirroring the PHP presenters
 * (plans/02-api-contract.md). Hand-written for W1.4/W1.5; the plan's generated
 * types come with the OpenAPI document, and these are the shapes that document
 * will describe.
 */

export interface Collection<T> {
  items: T[];
  total: number;
  page: number;
  per_page: number;
}

export interface WorkoutSummary {
  id: number;
  workout_name: string;
  slug: string | null;
  description: string | null;
  workout_type: string;
  difficulty: string;
  estimated_duration_minutes: number;
  calories_burn_estimate: number | null;
  muscle_groups: string[];
  equipment: string[];
  cover_image_url: string | null;
  video_url: string | null;
  exercise_count: number;
  progress_percentage: number;
  times_completed: number;
  status: string | null;
  scheduled_for: string | null;
  last_session_id: number | null;
  /** An open session for this workout, if the user left one running. */
  resumable_session_id: number | null;
}

export interface WorkoutExercise {
  id: number;
  workout_id: number;
  exercise_name: string;
  exercise_type: string;
  muscle_groups: string[];
  instructions: string | null;
  notes: string | null;
  video_url: string | null;
  thumbnail_url: string | null;
  default_sets: number;
  default_reps: number;
  default_weight_kg: number;
  default_rest_seconds: number;
  /** reps | seconds | distance — decides which control the player shows. */
  metric: string;
  order_index: number;
}

export interface WorkoutDetail extends WorkoutSummary {
  exercises: WorkoutExercise[];
  video_locked?: boolean;
}

export interface SessionSet {
  set_index: number;
  reps: number | null;
  weight_kg: number | null;
  duration_seconds: number | null;
  rest_taken_seconds: number | null;
  rpe: number | null;
  completed_at: string | null;
}

export interface SessionExercise {
  exercise_id: number;
  exercise_name: string;
  exercise_type: string;
  metric: string;
  order_index: number;
  planned_sets: number;
  planned_reps: number;
  planned_weight_kg: number;
  rest_seconds: number;
  video_url: string | null;
  thumbnail_url: string | null;
  notes: string | null;
  was_skipped: boolean;
  total_volume_kg: number;
  sets: SessionSet[];
}

export type SessionStatus = 'in_progress' | 'paused' | 'completed' | 'abandoned';

export interface Session {
  id: number;
  workout_id: number;
  workout_name: string | null;
  workout_type: string | null;
  status: SessionStatus;
  log_date: string | null;
  started_at: string | null;
  ended_at: string | null;
  last_resumed_at: string | null;
  /** Stored active time — the server's authoritative figure. */
  duration_seconds: number;
  /** duration_seconds plus the leg currently running, at response time. */
  elapsed_seconds: number;
  paused_seconds: number;
  current_exercise_index: number;
  current_set_index: number;
  completion_percentage: number;
  calories_burned: number | null;
  perceived_exertion: number | null;
  difficulty_rating: number | null;
  notes: string | null;
  total_volume_kg: number | null;
  exercises: SessionExercise[];
}

export interface PersonalRecord {
  exercise_name: string;
  record_type: 'max_weight' | 'max_reps' | 'max_volume' | 'best_time';
  value: number;
  unit: string;
  previous_value: number | null;
  is_new: boolean;
}

export interface Celebration {
  session_id: number;
  workout_name: string;
  duration_seconds: number;
  calories_burned: number;
  exercises_completed: number;
  total_exercises: number;
  completion_percentage: number;
  total_volume_kg: number;
  personal_records: PersonalRecord[];
  streak_days: number;
  streak_extended: boolean;
}

export interface SetPayload {
  exercise_id: number;
  set_index: number;
  reps?: number | null;
  weight_kg?: number | null;
  duration_seconds?: number | null;
  rest_taken_seconds?: number | null;
  rpe?: number | null;
}
