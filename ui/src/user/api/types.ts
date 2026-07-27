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

// ------------------------------------------------------------- nutrition

export interface Food {
  id: number;
  name: string;
  brand: string | null;
  category: string;
  serving_size: string | null;
  serving_grams: number | null;
  calories: number;
  protein_g: number;
  carbs_g: number;
  fat_g: number;
  fiber_g: number | null;
  sugar_g: number | null;
  sodium_mg: number | null;
  source: string;
  is_verified: boolean;
}

export interface MealItem {
  id: number;
  /** Null for a free-text entry; set when the item came from the food database. */
  food_id: number | null;
  name: string | null;
  quantity: number;
  unit: string | null;
  calories: number;
  protein_g: number;
  carbs_g: number;
  fat_g: number;
}

export type MealType = 'breakfast' | 'lunch' | 'dinner' | 'snack' | 'other';

export interface Meal {
  id: number;
  date: string;
  logged_at: string | null;
  meal_type: MealType;
  calories: number;
  protein_g: number;
  carbs_g: number;
  fat_g: number;
  notes: string | null;
  /** 'admin' when a trainer or administrator logged this for the member (Q10). */
  source: string;
  items: MealItem[];
}

export interface WaterState {
  consumed_ml: number;
  goal_ml: number;
  glass_ml: number;
  glasses: number;
  glasses_goal: number;
}

/** Null means the member has not set this goal — not zero. */
export interface NutritionGoals {
  calories: number | null;
  protein_g: number | null;
  carbs_g: number | null;
  fat_g: number | null;
  water_ml: number | null;
}

/** `GET /nutrition/day` — the whole Nutrition screen in one call. */
export interface NutritionDay {
  date: string;
  meals: Meal[];
  totals: { calories: number; protein_g: number; carbs_g: number; fat_g: number };
  goals: NutritionGoals;
  water: WaterState;
}

/** One item as the client submits it: a food reference, or free text. */
export interface MealItemInput {
  food_id?: number;
  custom_name?: string;
  quantity?: number;
  unit?: string;
  calories?: number;
  protein_g?: number;
  carbs_g?: number;
  fat_g?: number;
}

export interface MealInput {
  meal_type: MealType;
  log_date?: string;
  notes?: string | null;
  items: MealItemInput[];
}

// ---------------------------------------------------------------- health

/** The writable metrics, matching HealthService::FIELDS. */
export type HealthField =
  | 'weight_kg'
  | 'body_fat_percentage'
  | 'muscle_mass_kg'
  | 'systolic_pressure'
  | 'diastolic_pressure'
  | 'heart_rate_resting'
  | 'sleep_hours'
  | 'mood_score'
  | 'energy_score'
  | 'stress_score';

/**
 * Movement since the previous reading.
 *
 * `direction` and nothing else — the server deliberately does not say whether
 * the direction is good, because whether falling weight is progress depends on
 * what the member is training for. The UI draws a neutral arrow.
 */
export interface HealthTrend {
  direction: 'up' | 'down' | 'flat';
  delta: number;
  previous: number;
  previous_date: string | null;
}

/**
 * One card on the Health screen, described by the server.
 *
 * The card list is data, not markup: the prototype hardcoded eight cards in the
 * view, so adding a metric meant editing the screen. Here it is one entry in
 * `HealthService::CARDS`.
 */
export interface HealthCard {
  key: string;
  /** Null for BMI, which is derived and has no input. */
  field: HealthField | null;
  label: string;
  icon: string;
  tone: string;
  unit: string;
  decimals: number;
  /** Null when nothing has been logged, or the last reading is stale. */
  value: number | null;
  display: string | null;
  recorded_on: string | null;
  editable: boolean;
  detail: string | null;
  trend: HealthTrend | null;
  /** Diastolic, on the blood-pressure card only. */
  secondary_value?: number | null;
}

export type MeasurementField =
  | 'chest_cm'
  | 'waist_cm'
  | 'hips_cm'
  | 'arms_cm'
  | 'thighs_cm'
  | 'shoulders_cm'
  | 'neck_cm'
  | 'calves_cm';

/** One stored measurement session. Every field is nullable. */
export type Measurements = {
  id: number;
  date: string;
  notes: string | null;
} & Record<MeasurementField, number | null>;

/**
 * The latest value of each measurement, **per field rather than per session**.
 *
 * Each carries its own date: someone who measures chest monthly and calves
 * twice a year should not see calves vanish the moment they record anything
 * else, nor see a March reading presented as current.
 */
export interface LatestMeasurements {
  last_recorded_on: string;
  fields: Partial<Record<MeasurementField, { value: number; date: string }>>;
}

/** `GET /health/summary` — the whole Health screen in one call. */
export interface HealthSummary {
  date: string;
  height_cm: number | null;
  cards: HealthCard[];
  measurements: LatestMeasurements | null;
  target_weight_kg: number | null;
}

/** One stored day. Every metric is nullable — a day records what was measured. */
export type HealthStat = {
  id: number;
  date: string;
  recorded_at: string | null;
  bmi: number | null;
  notes: string | null;
  /** 'admin' when a trainer or administrator entered this (Q10). */
  source: string;
} & Record<HealthField, number | null>;

export interface HealthStats {
  items: HealthStat[];
  from: string;
  to: string;
}

/**
 * A health write. Only the keys present are sent, because the server treats an
 * absent key as "leave it alone" and an explicit null as "clear it" — the rule
 * that lets a member log weight in the morning and sleep at night without the
 * second entry erasing the first.
 */
export type HealthInput = Partial<Record<HealthField, number | null>> & {
  record_date?: string;
  notes?: string | null;
};

export type MeasurementsInput = Partial<Record<MeasurementField, number | null>> & {
  record_date?: string;
  notes?: string | null;
};

// ---------------------------------------------------------------- progress

export type ProgressRange = 'week' | 'month' | 'quarter' | 'year';

export interface StrengthSeries {
  exercise_name: string;
  /** One entry per label. Null where the lift was not trained in that bucket. */
  points: Array<number | null>;
  sets: number;
}

export type MeasurementPoint = { date: string } & Partial<Record<MeasurementField, number | null>>;

/**
 * `GET /progress?range=` — every series the screen charts, already bucketed.
 *
 * `bucket` says how: the client must not relabel or re-aggregate, because a
 * tooltip reading "12 Jun" over a chart of monthly averages is a lie about what
 * the point represents.
 *
 * The two kinds of empty are different and both are load-bearing. Reading
 * series (`weight`, `body_fat`, `strength[].points`) use **null** for a bucket
 * with no reading — a gap in the line. `consistency` uses **zero**, because a
 * week with no workouts is a fact rather than missing data.
 */
export interface Progress {
  range: ProgressRange;
  from: string;
  to: string;
  bucket: 'day' | 'week' | 'month';
  labels: string[];
  /** ISO date of each bucket's start, so a tooltip never has to guess the year. */
  dates: string[];
  weight: Array<number | null>;
  body_fat: Array<number | null>;
  strength: StrengthSeries[];
  consistency: number[];
  measurements: MeasurementPoint[];
  totals: {
    workouts_completed: number;
    calories_burned: number;
    total_minutes: number;
    personal_records: number;
  };
}

export interface ProgressRecord {
  exercise_name: string;
  record_type: 'max_weight' | 'max_reps' | 'max_volume' | 'best_time';
  value: number;
  unit: string;
  achieved_at: string | null;
}

export interface ExerciseProgression {
  exercise_name: string;
  range: ProgressRange;
  from: string;
  to: string;
  points: Array<{
    date: string;
    top_weight: number | null;
    top_reps: number | null;
    volume_kg: number;
    sets: number;
  }>;
}

// ----------------------------------------------------- notifications & activity

export type NotificationType =
  'workout' | 'message' | 'achievement' | 'subscription' | 'system' | 'progress' | 'support';

export interface Notification {
  id: number;
  type: NotificationType;
  title: string;
  body: string | null;
  icon: string | null;
  color: string | null;
  action_url: string | null;
  meta: Record<string, unknown>;
  is_read: boolean;
  read_at: string | null;
  created_at: string | null;
}

/**
 * `GET /notifications`.
 *
 * `unread_count` is the whole inbox's count, not this page's — it is what the
 * nav badge shows, and every mutation answers with it so the badge never has to
 * make a second request to learn what it should say.
 */
export interface NotificationList {
  items: Notification[];
  total: number;
  page: number;
  per_page: number;
  unread_count: number;
}

/** Every mutation that changes read state returns the resulting count. */
export interface UnreadCount {
  ok: boolean;
  unread_count: number;
  /** Only on read-all. */
  marked?: number;
}

export interface ActivityFeed {
  items: ActivityEntry[];
  total: number;
  page: number;
  per_page: number;
  /** Derived from what the member actually has, so the filter can't return nothing. */
  available_types: string[];
}

/** Null means the member has no opinion, which the server reads as on. */
export type NotificationPreferences = Record<NotificationType, boolean>;

export interface ConsistencyCalendar {
  year: number;
  /** Every day of the year, including the zeros. */
  days: Record<string, number>;
  total: number;
  active_days: number;
  best_day: number;
}

// ---------------------------------------------------------------- messaging

/**
 * The member's send allowance on one thread.
 *
 * `limit: null` means unlimited — never coerce it to 0, which would read as
 * "none allowed" and hide the composer on the best plan the product sells.
 * The allowance is **per thread**: a member coached by two trainers has a
 * separate one for each (Q3).
 */
export interface MessageQuota {
  limit: number | null;
  used: number;
  remaining: number | null;
  resets_at: string | null;
}

export interface ThreadSummary {
  id: number;
  trainer_id: number;
  user_id: number;
  counterpart_name: string | null;
  counterpart_avatar: string | null;
  preview: string | null;
  last_message_at: string | null;
  unread_count: number;
  status: string;
  /** Null when the caller is the trainer — replying spends nobody's plan. */
  quota: MessageQuota | null;
}

export type MessageDirection = 'user_to_trainer' | 'trainer_to_user';

export interface Message {
  id: number;
  thread_id: number;
  sender_account_id: number;
  direction: MessageDirection;
  message: string | null;
  attachments: string[];
  is_read: boolean;
  created_at: string | null;
}

export interface ThreadDetail {
  thread: Omit<ThreadSummary, 'preview' | 'last_message_at'>;
  messages: Message[];
  has_more: boolean;
  /** Pass back as `before` to page further into the history. */
  oldest_id: number | null;
}

/** `GET /messages/poll` — ids only, so an idle poll costs almost nothing. */
export interface MessagePoll {
  messages: Array<{ id: number; thread_id: number }>;
  latest_id: number;
  unread_total: number;
}
