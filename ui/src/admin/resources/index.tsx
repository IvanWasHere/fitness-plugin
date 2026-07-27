import { Tag } from '@shared/components/ui';
import type { ResourceConfig } from '../components/CrudTable';
import { SimpleForm, type FieldSpec } from '../components/SimpleForm';
import { WorkoutForm } from '../features/WorkoutForm';

/**
 * One declarative config per resource (plans/05-admin-app.md#structure, W2.5).
 *
 * "A new admin resource should be one file" is the pattern the prototype got
 * right and the reason its eight screens were 400 lines rather than 4 000. The
 * server has the mirror of this in `Services\Admin\ResourceRegistry`; between
 * them, adding a resource is two config entries and no new component.
 */
const num = (value: unknown, suffix = ''): string =>
  value === null || value === undefined || value === '' ? '—' : `${value}${suffix}`;

const date = (value: unknown): string =>
  typeof value === 'string' && value.length >= 10 ? value.slice(0, 10) : '—';

const STATUS_TONE: Record<string, string> = {
  active: 'green',
  inactive: 'orange',
  suspended: 'red',
  pending: 'orange',
  deleted: 'red',
};

function statusTag(value: unknown) {
  const status = String(value ?? '');

  return status ? <Tag tone={STATUS_TONE[status] ?? 'blue'}>{status}</Tag> : <>—</>;
}

// ------------------------------------------------------------------- users

const userFields: FieldSpec[] = [
  { name: 'display_name', label: 'Name', required: true },
  { name: 'phone', label: 'Phone' },
  { name: 'date_of_birth', label: 'Date of birth', type: 'date' },
  {
    name: 'gender',
    label: 'Gender',
    type: 'select',
    options: ['undisclosed', 'female', 'male', 'other'],
  },
  { name: 'height_cm', label: 'Height (cm)', type: 'number', step: '0.1' },
  { name: 'weight_kg', label: 'Weight (kg)', type: 'number', step: '0.1' },
  { name: 'target_weight_kg', label: 'Target weight (kg)', type: 'number', step: '0.1' },
  {
    name: 'fitness_level',
    label: 'Fitness level',
    type: 'select',
    options: ['beginner', 'intermediate', 'advanced'],
  },
  { name: 'fitness_goal', label: 'Goal' },
  {
    name: 'activity_level',
    label: 'Activity level',
    type: 'select',
    options: ['sedentary', 'light', 'moderate', 'active', 'very_active'],
  },
  { name: 'timezone', label: 'Timezone' },
];

export const usersConfig: ResourceConfig = {
  resource: 'users',
  title: 'Members',
  singular: 'member',
  // Creating a member means creating an account — password, email uniqueness,
  // welcome mail. That is an identity operation, not a profile edit, so the
  // server marks this resource non-creatable and the table hides Add.
  creatable: false,
  defaultSort: 'id',
  searchPlaceholder: 'Search by name, login or email…',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'display_name', label: 'Name', sort: 'name' },
    { key: 'email', label: 'Email', sort: 'email' },
    {
      key: 'plan_name',
      label: 'Plan',
      render: (r) => (r.plan_name ? <Tag tone="purple">{String(r.plan_name)}</Tag> : <>—</>),
    },
    { key: 'status', label: 'Status', sort: 'status', render: (r) => statusTag(r.status) },
    {
      key: 'workouts_completed',
      label: 'Workouts',
      align: 'right',
      render: (r) => num(r.workouts_completed),
    },
    {
      key: 'weight_kg',
      label: 'Weight',
      sort: 'weight',
      align: 'right',
      render: (r) => num(r.weight_kg, ' kg'),
    },
    {
      key: 'last_login_at',
      label: 'Last seen',
      sort: 'last_login',
      render: (r) => date(r.last_login_at),
    },
  ],
  filters: [
    {
      name: 'status',
      label: 'Status',
      options: ['active', 'inactive', 'suspended'].map((v) => ({ value: v, label: v })),
    },
  ],
  describe: (row) => String(row.display_name ?? `#${row.id}`),
  form: (props) => <SimpleForm {...props} fields={userFields} />,
};

// ---------------------------------------------------------------- trainers

const trainerFields: FieldSpec[] = [
  { name: 'display_name', label: 'Name', required: true },
  { name: 'specialization', label: 'Specialization' },
  { name: 'phone', label: 'Phone' },
  { name: 'bio', label: 'Bio', type: 'textarea' },
  { name: 'avatar_url', label: 'Avatar URL' },
  // Q2: trainers are traced, not paid. This is shown on the public profile and
  // never used to compute a payout.
  { name: 'hourly_rate', label: 'Hourly rate (display only)', type: 'number', step: '0.01' },
  { name: 'max_clients', label: 'Max clients', type: 'number' },
  { name: 'accepting_clients', label: 'Accepting clients', type: 'checkbox' },
  { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive', 'suspended'] },
];

export const trainersConfig: ResourceConfig = {
  resource: 'trainers',
  title: 'Trainers',
  singular: 'trainer',
  creatable: false,
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'display_name', label: 'Name', sort: 'name' },
    { key: 'email', label: 'Email', sort: 'email' },
    { key: 'specialization', label: 'Specialization' },
    // Derived by the server, not a stored counter the prototype never updated.
    { key: 'client_count', label: 'Clients', align: 'right', render: (r) => num(r.client_count) },
    {
      key: 'rating',
      label: 'Rating',
      sort: 'rating',
      align: 'right',
      // Read-only: computed from client feedback. The prototype offered it as a
      // free number field an admin could simply type into.
      render: (r) => (r.rating ? `${Number(r.rating).toFixed(1)} ★` : '—'),
    },
    { key: 'status', label: 'Status', sort: 'status', render: (r) => statusTag(r.status) },
  ],
  filters: [
    {
      name: 'status',
      label: 'Status',
      options: ['active', 'inactive', 'suspended'].map((v) => ({ value: v, label: v })),
    },
  ],
  describe: (row) => String(row.display_name ?? `#${row.id}`),
  form: (props) => <SimpleForm {...props} fields={trainerFields} />,
};

// ---------------------------------------------------------------- workouts

export const workoutsConfig: ResourceConfig = {
  resource: 'workouts',
  title: 'Workouts',
  singular: 'workout',
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'workout_name', label: 'Name', sort: 'name' },
    {
      key: 'difficulty',
      label: 'Difficulty',
      sort: 'difficulty',
      render: (r) => <Tag tone={String(r.difficulty ?? '')}>{String(r.difficulty ?? '—')}</Tag>,
    },
    { key: 'workout_type', label: 'Type' },
    {
      key: 'estimated_duration_minutes',
      label: 'Duration',
      sort: 'duration',
      align: 'right',
      render: (r) => num(r.estimated_duration_minutes, ' min'),
    },
    {
      key: 'exercise_count',
      label: 'Exercises',
      align: 'right',
      render: (r) => num(r.exercise_count),
    },
    {
      key: 'assigned_count',
      label: 'Assigned',
      align: 'right',
      render: (r) => num(r.assigned_count),
    },
    { key: 'trainer_name', label: 'Trainer' },
    { key: 'is_active', label: 'Active', render: (r) => (r.is_active ? 'Yes' : 'No') },
  ],
  filters: [
    {
      name: 'difficulty',
      label: 'Difficulty',
      options: ['beginner', 'intermediate', 'advanced'].map((v) => ({ value: v, label: v })),
    },
    {
      name: 'type',
      label: 'Type',
      options: ['strength', 'cardio', 'hiit', 'flexibility', 'recovery'].map((v) => ({
        value: v,
        label: v,
      })),
    },
  ],
  describe: (row) => String(row.workout_name ?? `#${row.id}`),
  // The one resource with its own form: the nested exercise editor is the
  // prototype's best screen and needs more than a field list.
  form: (props) => <WorkoutForm {...props} />,
};

// ------------------------------------------------------------------- foods

const foodFields: FieldSpec[] = [
  { name: 'name', label: 'Name', required: true },
  { name: 'brand', label: 'Brand' },
  {
    name: 'category',
    label: 'Category',
    type: 'select',
    options: [
      'protein',
      'grains',
      'vegetables',
      'fruit',
      'dairy',
      'nuts',
      'fats',
      'supplements',
      'beverages',
      'other',
    ],
  },
  { name: 'serving_size', label: 'Serving size' },
  { name: 'serving_grams', label: 'Serving (g)', type: 'number', step: '0.1' },
  { name: 'calories', label: 'Calories', type: 'number' },
  { name: 'protein_g', label: 'Protein (g)', type: 'number', step: '0.1' },
  { name: 'carbs_g', label: 'Carbs (g)', type: 'number', step: '0.1' },
  { name: 'fat_g', label: 'Fat (g)', type: 'number', step: '0.1' },
  { name: 'fiber_g', label: 'Fibre (g)', type: 'number', step: '0.1' },
  { name: 'sugar_g', label: 'Sugar (g)', type: 'number', step: '0.1' },
  { name: 'sodium_mg', label: 'Sodium (mg)', type: 'number', step: '0.1' },
  { name: 'barcode', label: 'Barcode' },
  { name: 'is_verified', label: 'Verified', type: 'checkbox' },
];

export const foodsConfig: ResourceConfig = {
  resource: 'foods',
  title: 'Food database',
  singular: 'food',
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'name', label: 'Name', sort: 'name' },
    { key: 'brand', label: 'Brand' },
    {
      key: 'category',
      label: 'Category',
      sort: 'category',
      render: (r) => <Tag tone="blue">{String(r.category ?? '—')}</Tag>,
    },
    { key: 'calories', label: 'Calories', sort: 'calories', align: 'right' },
    { key: 'protein_g', label: 'Protein', align: 'right', render: (r) => num(r.protein_g, 'g') },
    { key: 'carbs_g', label: 'Carbs', align: 'right', render: (r) => num(r.carbs_g, 'g') },
    { key: 'fat_g', label: 'Fat', align: 'right', render: (r) => num(r.fat_g, 'g') },
    { key: 'is_verified', label: 'Verified', render: (r) => (r.is_verified ? '✓' : '—') },
  ],
  filters: [
    {
      name: 'category',
      label: 'Category',
      options: [
        'protein',
        'grains',
        'vegetables',
        'fruit',
        'dairy',
        'nuts',
        'fats',
        'supplements',
        'beverages',
        'other',
      ].map((v) => ({ value: v, label: v })),
    },
    {
      name: 'source',
      label: 'Source',
      options: ['system', 'user', 'external'].map((v) => ({ value: v, label: v })),
    },
  ],
  describe: (row) => String(row.name ?? `#${row.id}`),
  form: (props) => <SimpleForm {...props} fields={foodFields} />,
};

// ------------------------------------------------------- meals & health (Q10)

/**
 * A banner both audited resources carry.
 *
 * 05 asks for an "inline warning that saving alters the user's charts", and it
 * is the honest thing to show: an administrator editing these is changing
 * somebody else's record of their own body.
 */
function auditNotice() {
  return (
    <p className="fc-admin-notice">
      <span aria-hidden="true">⚠</span> Editing this changes a member&rsquo;s own record. The change
      is logged with your name and the previous value, and the member sees it marked as
      staff-edited.
    </p>
  );
}

const mealFields: FieldSpec[] = [
  {
    name: 'meal_type',
    label: 'Meal',
    type: 'select',
    options: ['breakfast', 'lunch', 'dinner', 'snack', 'other'],
  },
  { name: 'log_date', label: 'Date', type: 'date' },
  { name: 'notes', label: 'Notes', type: 'textarea' },
];

export const mealsConfig: ResourceConfig = {
  resource: 'meals',
  title: 'Meal logs',
  singular: 'meal log',
  creatable: false,
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    // The prototype rendered "User #1" here.
    { key: 'user_name', label: 'Member', sort: 'user' },
    { key: 'meal_type', label: 'Meal' },
    { key: 'log_date', label: 'Date', sort: 'date', render: (r) => date(r.log_date) },
    { key: 'item_count', label: 'Items', align: 'right' },
    { key: 'total_calories', label: 'Calories', sort: 'calories', align: 'right' },
    {
      key: 'edited_by_staff',
      label: 'Source',
      render: (r) => (r.edited_by_staff ? <Tag tone="orange">staff-edited</Tag> : 'member'),
    },
  ],
  filters: [
    {
      name: 'meal_type',
      label: 'Meal',
      options: ['breakfast', 'lunch', 'dinner', 'snack', 'other'].map((v) => ({
        value: v,
        label: v,
      })),
    },
  ],
  describe: (row) => `${row.meal_type} for ${row.user_name} on ${date(row.log_date)}`,
  form: (props) => <SimpleForm {...props} fields={mealFields} notice={auditNotice()} />,
};

const healthFields: FieldSpec[] = [
  { name: 'record_date', label: 'Date', type: 'date' },
  { name: 'weight_kg', label: 'Weight (kg)', type: 'number', step: '0.1' },
  { name: 'body_fat_percentage', label: 'Body fat (%)', type: 'number', step: '0.1' },
  { name: 'muscle_mass_kg', label: 'Muscle mass (kg)', type: 'number', step: '0.1' },
  { name: 'systolic_pressure', label: 'Systolic', type: 'number' },
  { name: 'diastolic_pressure', label: 'Diastolic', type: 'number' },
  { name: 'heart_rate_resting', label: 'Resting HR', type: 'number' },
  { name: 'sleep_hours', label: 'Sleep (hrs)', type: 'number', step: '0.1' },
  { name: 'mood_score', label: 'Mood (1–5)', type: 'number' },
  { name: 'energy_score', label: 'Energy (1–10)', type: 'number' },
  { name: 'stress_score', label: 'Stress (1–5)', type: 'number' },
  { name: 'notes', label: 'Notes', type: 'textarea' },
];

export const healthConfig: ResourceConfig = {
  resource: 'health-entries',
  title: 'Health entries',
  singular: 'health entry',
  creatable: false,
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'user_name', label: 'Member', sort: 'user' },
    { key: 'record_date', label: 'Date', sort: 'date', render: (r) => date(r.record_date) },
    {
      key: 'weight_kg',
      label: 'Weight',
      sort: 'weight',
      align: 'right',
      render: (r) => num(r.weight_kg, ' kg'),
    },
    {
      key: 'body_fat_percentage',
      label: 'Body fat',
      align: 'right',
      render: (r) => num(r.body_fat_percentage, '%'),
    },
    // Derived from height and weight; the form has no input for it.
    { key: 'bmi', label: 'BMI', align: 'right', render: (r) => num(r.bmi) },
    {
      key: 'heart_rate_resting',
      label: 'HR',
      align: 'right',
      render: (r) => num(r.heart_rate_resting),
    },
    {
      key: 'edited_by_staff',
      label: 'Source',
      render: (r) => (r.edited_by_staff ? <Tag tone="orange">staff-edited</Tag> : 'member'),
    },
  ],
  describe: (row) => `${row.user_name}'s entry for ${date(row.record_date)}`,
  form: (props) => <SimpleForm {...props} fields={healthFields} notice={auditNotice()} />,
};

// ------------------------------------------------------------ billing (W3.2)

const planFields: FieldSpec[] = [
  { name: 'plan_name', label: 'Name', required: true },
  { name: 'slug', label: 'Slug' },
  { name: 'description', label: 'Description', type: 'textarea' },
  { name: 'owner_type', label: 'Owner', type: 'select', options: ['platform', 'trainer'] },
  { name: 'currency', label: 'Currency' },
  { name: 'price_weekly', label: 'Weekly', type: 'number', step: '0.01' },
  { name: 'price_monthly', label: 'Monthly', type: 'number', step: '0.01' },
  { name: 'price_quarterly', label: 'Quarterly', type: 'number', step: '0.01' },
  { name: 'price_yearly', label: 'Yearly', type: 'number', step: '0.01' },
  { name: 'max_messages_per_week', label: 'Messages / week', type: 'number' },
  // Q14: what a plan sells when it sells coaching.
  { name: 'max_trainers', label: 'Max trainers', type: 'number' },
  // The feature-flag editor. Raw JSON, validated server-side — invalid JSON is
  // refused rather than stored, because a plan whose features became null would
  // quietly drop every paying member on it to the free tier.
  { name: 'features', label: 'Feature flags (JSON)', type: 'textarea' },
  { name: 'sort_order', label: 'Sort order', type: 'number' },
  { name: 'is_active', label: 'On sale', type: 'checkbox' },
];

export const plansConfig: ResourceConfig = {
  resource: 'plans',
  title: 'Plans',
  singular: 'plan',
  defaultSort: 'order',
  defaultOrder: 'asc',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'plan_name', label: 'Name', sort: 'name' },
    {
      key: 'owner_type',
      label: 'Owner',
      render: (r) => (
        <Tag tone={r.owner_type === 'platform' ? 'purple' : 'blue'}>{String(r.owner_type)}</Tag>
      ),
    },
    { key: 'trainer_name', label: 'Trainer' },
    {
      key: 'price_monthly',
      label: 'Monthly',
      sort: 'price',
      align: 'right',
      render: (r) => num(r.price_monthly),
    },
    { key: 'max_trainers', label: 'Trainers', align: 'right' },
    { key: 'active_subscribers', label: 'Subscribers', align: 'right' },
    { key: 'is_active', label: 'On sale', render: (r) => (r.is_active ? 'Yes' : 'No') },
  ],
  filters: [
    {
      name: 'owner_type',
      label: 'Owner',
      options: ['platform', 'trainer'].map((v) => ({ value: v, label: v })),
    },
  ],
  describe: (row) => String(row.plan_name ?? `#${row.id}`),
  form: (props) => <SimpleForm {...props} fields={planFields} />,
};

/**
 * Read-mostly on purpose: transitions belong to `SubscriptionService`, which
 * knows that cancelling means at-period-end and how a renewal extends a period.
 * Extending a date by hand is the supported override; setting `status` by hand
 * would skip every rule.
 */
export const subscriptionsConfig: ResourceConfig = {
  resource: 'subscriptions',
  title: 'Subscriptions',
  singular: 'subscription',
  creatable: false,
  defaultSort: 'id',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'user_name', label: 'Member', sort: 'user' },
    { key: 'plan_name', label: 'Plan' },
    { key: 'trainer_name', label: 'Trainer' },
    { key: 'status', label: 'Status', sort: 'status', render: (r) => statusTag(r.status) },
    { key: 'subscription_type', label: 'Cycle' },
    { key: 'end_date', label: 'Ends', sort: 'ends', render: (r) => date(r.end_date) },
    {
      key: 'cancel_at_period_end',
      label: 'Ending',
      render: (r) => (r.cancel_at_period_end ? 'Yes' : '—'),
    },
  ],
  filters: [
    {
      name: 'status',
      label: 'Status',
      options: ['active', 'trialing', 'past_due', 'cancelled', 'expired'].map((v) => ({
        value: v,
        label: v,
      })),
    },
  ],
  describe: (row) => `${row.plan_name} for ${row.user_name}`,
  form: (props) => (
    <SimpleForm
      {...props}
      fields={[
        { name: 'end_date', label: 'Ends on', type: 'date' },
        { name: 'auto_renew', label: 'Auto renew', type: 'checkbox' },
        { name: 'cancel_at_period_end', label: 'Cancel at period end', type: 'checkbox' },
      ]}
    />
  ),
};

export const paymentsConfig: ResourceConfig = {
  resource: 'payments',
  title: 'Payments',
  singular: 'payment',
  creatable: false,
  defaultSort: 'date',
  columns: [
    { key: 'id', label: 'ID', sort: 'id' },
    { key: 'user_name', label: 'Member', sort: 'user' },
    { key: 'plan_name', label: 'Plan' },
    {
      key: 'amount',
      label: 'Amount',
      sort: 'amount',
      align: 'right',
      render: (r) => `${r.currency ?? ''} ${num(r.amount)}`,
    },
    {
      key: 'status',
      label: 'Status',
      sort: 'status',
      render: (r) => (
        <Tag tone={r.status === 'completed' ? 'green' : 'red'}>{String(r.status)}</Tag>
      ),
    },
    { key: 'gateway', label: 'Gateway' },
    { key: 'payment_date', label: 'Date', sort: 'date', render: (r) => date(r.payment_date) },
    { key: 'transaction_id', label: 'Reference' },
  ],
  filters: [
    {
      name: 'status',
      label: 'Status',
      options: ['completed', 'failed', 'refunded', 'pending'].map((v) => ({ value: v, label: v })),
    },
  ],
  describe: (row) => `${row.currency} ${row.amount} from ${row.user_name}`,
  form: (props) => (
    <SimpleForm
      {...props}
      fields={[
        {
          name: 'status',
          label: 'Status',
          type: 'select',
          options: ['completed', 'failed', 'refunded', 'pending'],
        },
        { name: 'payment_method', label: 'Method' },
        { name: 'failure_reason', label: 'Note', type: 'textarea' },
      ]}
    />
  ),
};

export const RESOURCES: ResourceConfig[] = [
  usersConfig,
  trainersConfig,
  workoutsConfig,
  foodsConfig,
  mealsConfig,
  healthConfig,
  plansConfig,
  subscriptionsConfig,
  paymentsConfig,
];
