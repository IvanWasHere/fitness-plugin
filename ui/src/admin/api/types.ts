/**
 * Wire types for `/admin/*` (plans/02-api-contract.md#admin, W2.5).
 *
 * A row is deliberately loose: the whole point of the generic table is that one
 * component renders every resource, so it cannot know the columns at compile
 * time. The *column spec* is what is typed — see `ResourceConfig` — and that is
 * where a mistake actually costs something.
 */
export type AdminRow = Record<string, unknown> & { id: number };

export interface AdminList<T = AdminRow> {
  items: T[];
  total: number;
  page: number;
  per_page: number;
}

/**
 * What the server says a resource is, from its own registry.
 *
 * `GET /admin/resources` is the server describing itself. The SPA's navigation
 * is built from the local `RESOURCES` configs instead — those have to exist
 * anyway, since they carry the columns and forms, and deriving the nav from a
 * second source would just be two lists to keep in step. The endpoint earns its
 * place in the tests, where it is what catches the two registries drifting.
 */
export interface ResourceMeta {
  name: string;
  creatable: boolean;
  audited: boolean;
  sorts: string[];
  filters: string[];
  writable: Record<string, string>;
  required: string[];
}

export interface AdminSettings {
  general: { name: string; app_base: string };
  email: { from_name: string; from_address: string };
  features: Record<string, boolean>;
  context: { site_url: string; app_url: string; reserved: string[] };
  rewrites_flushed?: boolean;
}

export interface AdminDashboard {
  generated_at: string;
  counts: {
    members: number;
    trainers: number;
    workouts: number;
    foods: number;
    active_subscriptions: number;
    open_tickets: number;
    sessions_this_week: number;
    meals_this_week: number;
  };
  revenue: {
    window_total: number;
    window_payments: number;
    mrr: number;
    currency: string;
    from: string;
    to: string;
  };
  recent_signups: Array<{
    id: number;
    display_name: string | null;
    email: string | null;
    status: string;
    created_at: string;
  }>;
  failed_payments: Array<{
    id: number;
    display_name: string | null;
    amount: number;
    currency: string;
    created_at: string;
  }>;
  inactive_members: number;
}

/**
 * The exercise row shape lives with the editor that owns it — one definition,
 * so the admin form and the trainer builder cannot drift on what a row is.
 */
export type { ExerciseRow as WorkoutExerciseRow } from '@shared/components/ExerciseEditor';
