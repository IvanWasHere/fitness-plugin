/**
 * Wire types for `/trainer/*` (plans/02-api-contract.md, W3.4).
 *
 * The shape that matters most is `assigned_by`, which rides on every
 * assignment. Q13 gives every coach sight of every other coach's work on a
 * shared client; attribution is what stops that being confusing rather than
 * useful.
 */
export interface AssignedBy {
  account_id: number | null;
  trainer_id: number | null;
  display_name: string | null;
  assigned_at: string | null;
}

export interface ClientSummary {
  user_id: number;
  display_name: string | null;
  avatar_url: string | null;
  email: string | null;
  fitness_goal: string | null;
  weight_kg: number | null;
  target_weight_kg: number | null;
  status: string;
  is_primary: boolean;
  assigned_date: string | null;
  sessions_completed: number;
  last_session_date: string | null;
  /** How many coaches this client has in total, including you (Q3). */
  trainer_count: number;
}

export interface ClientTrainer {
  trainer_id: number;
  display_name: string | null;
  specialization: string | null;
  is_primary: boolean;
  assigned_date: string | null;
}

export interface Assignment {
  id: number;
  workout_id: number;
  workout_name: string | null;
  difficulty: string | null;
  workout_type: string | null;
  scheduled_for: string | null;
  status: string;
  progress_percentage: number;
  times_completed: number;
  assigned_by: AssignedBy;
}

export interface ClientDetail {
  user_id: number;
  display_name: string | null;
  avatar_url: string | null;
  email: string | null;
  date_of_birth: string | null;
  gender: string | null;
  height_cm: number | null;
  weight_kg: number | null;
  target_weight_kg: number | null;
  fitness_level: string | null;
  fitness_goal: string | null;
  activity_level: string | null;
  account_status: string;
  last_login_at: string | null;
  trainers: ClientTrainer[];
  assignments: Assignment[];
}

export interface ClientSession {
  id: number;
  workout_name: string | null;
  log_date: string | null;
  status: string;
  duration_seconds: number;
  calories_burned: number | null;
  completion_percentage: number;
  perceived_exertion: number | null;
  notes: string | null;
}

/** `available: false` when the client's own plan does not include nutrition. */
export interface ClientNutrition {
  items: Array<{
    date: string;
    meals: number;
    calories: number;
    protein_g: number;
    carbs_g: number;
    fat_g: number;
  }>;
  available: boolean;
  from?: string;
  to?: string;
}

export interface TrainerNote {
  id: number;
  body: string;
  pinned: boolean;
  created_at: string;
  updated_at: string;
}

export interface ClientRequest {
  id: number;
  user_id: number;
  display_name: string | null;
  avatar_url: string | null;
  email: string | null;
  fitness_goal: string | null;
  request_message: string | null;
  requested_at: string | null;
}

/** `max: null` means no limit configured, not "cannot take anybody". */
export interface TrainerCapacity {
  used: number;
  max: number | null;
}

export interface TrainerDashboard {
  generated_at: string;
  counts: {
    active_clients: number;
    pending_requests: number;
    workouts_authored: number;
    sessions_this_week: number;
  };
  needs_attention: Array<{
    user_id: number;
    display_name: string | null;
    last_session_date: string | null;
  }>;
}

/** Non-blocking: another coach's week is a thing to know, not to be stopped by. */
export interface AssignmentWarning {
  code: string;
  message: string;
  assignment_id: number;
  scheduled_for: string | null;
  difficulty: string | null;
}

/**
 * `GET /trainer/clients/{id}/progress`.
 *
 * The member's own `/progress` payload verbatim — `TrainerService::progress()`
 * delegates straight to `ProgressService::range()` after the roster check — which
 * is why the trainer draws it with the member app's chart grid rather than a
 * second rendering that would drift.
 */
export interface ClientProgress {
  range: string;
  from: string;
  to: string;
  bucket: 'day' | 'week' | 'month';
  labels: string[];
  dates: string[];
  weight: Array<number | null>;
  body_fat: Array<number | null>;
  strength: Array<{ exercise_name: string; points: Array<number | null>; sets: number }>;
  consistency: number[];
  measurements: Array<Record<string, number | null | string>>;
  totals: {
    workouts_completed: number;
    calories_burned: number;
    total_minutes: number;
    personal_records: number;
  };
}

export interface TrainerWorkout {
  id: number;
  workout_name: string;
  description: string | null;
  workout_type: string | null;
  difficulty: string | null;
  estimated_duration_minutes: number;
  muscle_groups: string[];
  equipment: string[];
  is_active: boolean;
  exercise_count: number | null;
  is_mine: boolean;
  is_platform: boolean;
}
