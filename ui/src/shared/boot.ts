/**
 * The boot payload the PHP shell injects (D9/D10, plans/02-api-contract.md).
 *
 * The shell renders `<div id="fc-app" data-boot="…">` where the JSON carries
 * everything the SPA needs for its first frame — REST root, nonce, current user,
 * theme tokens, entitlements, and the app base path. The SPA never guesses its
 * own configuration.
 */
export interface BootUser {
  id: number;
  wp_user_id: number;
  display_name: string;
  avatar_url?: string;
  role: 'fc_user' | 'fc_trainer' | 'administrator' | string;
}

export interface BootApp {
  /** Configured front-end slug, e.g. "/fitness". */
  base: string;
  /** react-router basename. */
  basename: string;
  /** Which SPA the backend resolved for this role. */
  spa: 'user' | 'trainer' | 'admin';
}

export interface BootPayload {
  restUrl: string;
  nonce: string;
  user: BootUser | null;
  app: BootApp;
  theme?: Record<string, unknown>;
  entitlements?: Record<string, unknown>;
  brand?: string;
  locale?: string;
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
