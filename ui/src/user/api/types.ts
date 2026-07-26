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

// ------------------------------------------------------------ the dashboard

/** A value against its goal. `goal` is null when the member has not set one. */
export interface MacroTarget {
  value: number;
  goal: number | null;
}

export interface WeeklyChart {
  /** Localised weekday initials, oldest first; the last bucket is today. */
  labels: string[];
  /** ISO dates matching `labels`, so a tooltip never has to guess the year. */
  dates: string[];
  calories: number[];
  workouts: number[];
  minutes: number[];
}

export interface MonthlyStats {
  workouts_completed: number;
  calories_burned: number;
  total_minutes: number;
  active_days: number;
  avg_hours_per_week: number;
  /** Adherence to what was scheduled; null when nothing was. */
  consistency_percentage: number | null;
  window_days: number;
}

export interface ActivityEntry {
  type: string;
  title: string;
  detail: string;
  subject_type: string | null;
  subject_id: number | null;
  meta: Record<string, unknown>;
  occurred_at: string;
}

export interface MessagePreview {
  thread_id: number;
  trainer_name: string | null;
  avatar_url: string | null;
  preview: string | null;
  unread_count: number;
  last_message_at: string;
}

/**
 * `GET /user/dashboard` — every section of the screen in one response.
 *
 * Sections whose services arrive in Phase 2 (nutrition, water, messaging) are
 * already present and already honest: they report zeros and nulls rather than
 * placeholder numbers, so the screen can render its real empty states now.
 */
export interface Dashboard {
  generated_at: string;
  /** Today in the member's own timezone — not the browser's. */
  date: string;
  greeting: { name: string; streak_days: number };
  stats: {
    streak_days: number;
    calories_burned_today: number;
    current_weight_kg: number | null;
    goal_progress_percentage: number | null;
  };
  todays_workout: WorkoutSummary | null;
  upcoming_workout: WorkoutSummary | null;
  water: { consumed_ml: number; goal_ml: number; glass_ml: number };
  nutrition: {
    calories: MacroTarget;
    protein_g: MacroTarget;
    carbs_g: MacroTarget;
    fat_g: MacroTarget;
  };
  weekly_chart: WeeklyChart;
  monthly_stats: MonthlyStats;
  recent_activity: ActivityEntry[];
  message_previews: MessagePreview[];
}
