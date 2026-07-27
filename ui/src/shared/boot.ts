/**
 * The boot payload the PHP shell injects (D9/D10, plans/02-api-contract.md).
 *
 * The shell renders `<div id="fc-app" data-boot="…">` where the JSON carries
 * everything the SPA needs for its first frame — REST root, CSRF token, user,
 * theme tokens, entitlements, counts and the app base path. The SPA never
 * guesses its own configuration, and `GET /auth/me` returns the same shape so a
 * refresh is a straight replacement rather than a merge.
 */
export interface BootUser {
  /** fc_users.id — internal, never "the user id" in the API. */
  id: number;
  /** fc_accounts.id — the identity anchor. */
  account_id: number;
  display_name: string;
  email?: string;
  avatar_url?: string;
  role: 'user' | 'trainer' | 'admin' | string;
  timezone?: string;
  onboarded?: boolean;
  /** Present for trainers and admins: fc_trainers.id. */
  trainer_id?: number;
}

export interface BootSubscription {
  plan_name: string;
  plan_slug: string;
  status: string;
  cycle: string;
  renews_at: string | null;
  trainer_id: number | null;
  cancel_at_period_end: boolean;
}

export interface BootTrainer {
  id: number;
  display_name: string | null;
  avatar_url: string | null;
  specialization: string | null;
  is_primary: boolean;
  status: string;
}

export interface BootEntitlements {
  has_active_subscription: boolean;
  trainers_used: number;
  max_trainers: number;
  message_quota_by_trainer: Record<string, number | null>;
  [key: string]: unknown;
}

export interface BootTheme {
  theme_name?: string;
  color_scheme?: string;
  colors?: Record<string, string>;
  typography?: Record<string, string>;
  layout?: Record<string, string>;
  components?: Record<string, Record<string, unknown>>;
}

export type SpaName = 'user' | 'trainer' | 'admin';

export interface BootApp {
  /** Configured front-end slug, e.g. "/fitness". */
  base: string;
  /** react-router basename. */
  basename: string;
  /** Which SPA the backend resolved for this role. */
  spa: SpaName;
}

export interface BootCounts {
  unread_messages: number;
  unread_notifications: number;
}

/** The `GET /auth/me` body. */
export interface BootIdentity {
  user: BootUser | null;
  subscriptions: BootSubscription[];
  trainers: BootTrainer[];
  entitlements: BootEntitlements;
  theme: BootTheme;
  app: BootApp;
  counts: BootCounts;
}

/** The identity plus the transport bits only the shell can supply. */
export interface BootPayload extends BootIdentity {
  restUrl: string;
  csrf: string;
  brand: string;
  locale: string;
  flags: { registration_open: boolean; [key: string]: boolean };
}

const MOUNT_ID = 'fc-app';

/**
 * Read and parse the boot payload from the mount node's `data-boot` attribute.
 * Returns null when absent (e.g. running against the Vite dev server with no
 * WordPress shell), so callers can fall back to sensible dev defaults.
 */
export function readBoot(): BootPayload | null {
  const el = document.getElementById(MOUNT_ID);
  const raw = el?.getAttribute('data-boot');
  if (!raw) {
    return null;
  }
  try {
    return JSON.parse(raw) as BootPayload;
  } catch {
    console.error('[fitnessclub] could not parse data-boot payload');
    return null;
  }
}

/**
 * The boot payload, or a usable stand-in when running bare against the Vite dev
 * server. The stand-in points at the conventional WordPress paths, so `npm run
 * dev` with no WordPress shell still talks to a real backend rather than
 * crashing on a null.
 */
export function bootOrDefaults(spa: SpaName): BootPayload {
  const boot = readBoot();
  if (boot) {
    return boot;
  }

  return {
    restUrl: '/wp-json/fitnessclub/v1/',

    csrf: '',
    brand: 'FitnessClub',
    locale: 'en_US',
    flags: { registration_open: true },
    user: null,
    subscriptions: [],
    trainers: [],
    entitlements: {
      has_active_subscription: false,
      trainers_used: 0,
      max_trainers: 0,
      message_quota_by_trainer: {},
    },
    theme: {},
    app: { base: '/fitness', basename: '/fitness', spa },
    counts: { unread_messages: 0, unread_notifications: 0 },
  };
}

/** The mount node, created if missing (dev server has no WP shell). */
export function mountNode(): HTMLElement {
  let el = document.getElementById(MOUNT_ID);
  if (!el) {
    el = document.createElement('div');
    el.id = MOUNT_ID;
    document.body.appendChild(el);
  }
  return el;
}
