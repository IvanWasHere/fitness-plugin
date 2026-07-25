import type { BootPayload } from './boot';

/**
 * The REST client every screen goes through (plans/02-api-contract.md).
 *
 * Cookie + nonce: the SPA is served from the site's own origin (D9), so
 * `credentials: 'same-origin'` carries the WordPress session and `X-WP-Nonce`
 * proves the request is not cross-site.
 *
 * ## The nonce-expiry retry, which is the whole reason this class exists
 *
 * A `wp_rest` nonce lasts 12–24 hours. An app left open overnight — a phone in a
 * pocket between gym sessions is exactly this — starts getting
 * `403 rest_cookie_invalid_nonce` on every call while still looking signed in.
 * Left unhandled it presents as "the app silently stopped saving".
 *
 * So a 403 with that code is not an error here: refresh the nonce (see
 * `NonceProvider` for why that goes through admin-ajax and not REST), retry the
 * call exactly once, and only surface a re-login prompt if the refresh itself
 * says the session is gone. Once per request, never a loop.
 */

/** WordPress's `WP_Error` serialisation — one error shape for every failure. */
interface WpErrorBody {
  code?: string;
  message?: string;
  data?: { status?: number; [key: string]: unknown };
}

export class ApiError extends Error {
  constructor(
    /** Stable, namespaced `fc_*` code. Branch on this, never on `message`. */
    readonly code: string,
    message: string,
    readonly status: number,
    readonly data: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The session is gone or was never there — the caller should show the login panel. */
  get isAuthError(): boolean {
    return this.status === 401 || this.code === 'fc_not_authenticated';
  }

  /** 429: `data.retry_after` says how many seconds to wait. */
  get retryAfter(): number | null {
    const value = this.data.retry_after;
    return typeof value === 'number' ? value : null;
  }
}

/** Codes WordPress uses for "your nonce is stale", as opposed to "you may not". */
const STALE_NONCE_CODES = new Set(['rest_cookie_invalid_nonce', 'rest_nonce_invalid']);

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export class ApiClient {
  private nonce: string;

  private readonly restUrl: string;

  private readonly ajaxUrl: string;

  /** In-flight refresh, shared so a burst of 403s triggers one refresh, not six. */
  private refreshing: Promise<boolean> | null = null;

  constructor(boot: Pick<BootPayload, 'restUrl' | 'ajaxUrl' | 'nonce'>) {
    this.restUrl = boot.restUrl.replace(/\/$/, '');
    this.ajaxUrl = boot.ajaxUrl;
    this.nonce = boot.nonce;
  }

  /** The current nonce — a fresh session response supersedes it. */
  setNonce(nonce: string): void {
    if (nonce) {
      this.nonce = nonce;
    }
  }

  /**
   * A URL that authenticates without request headers, for `navigator.sendBeacon`.
   *
   * A beacon cannot carry `X-WP-Nonce` — the API allows no custom headers — but
   * WordPress also accepts the nonce as a `_wpnonce` parameter, which is how the
   * player flushes queued sets during `pagehide`, when there is no time left for
   * a normal request.
   */
  beaconUrl(path: string): string {
    return `${this.restUrl}/${path.replace(/^\//, '')}?_wpnonce=${encodeURIComponent(this.nonce)}`;
  }

  get<T>(path: string, params?: Record<string, string | number | boolean>): Promise<T> {
    const query = params
      ? `?${new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)])).toString()}`
      : '';
    return this.send<T>('GET', `${path}${query}`);
  }

  post<T>(path: string, body?: unknown): Promise<T> {
    return this.send<T>('POST', path, body);
  }

  put<T>(path: string, body?: unknown): Promise<T> {
    return this.send<T>('PUT', path, body);
  }

  patch<T>(path: string, body?: unknown): Promise<T> {
    return this.send<T>('PATCH', path, body);
  }

  delete<T>(path: string): Promise<T> {
    return this.send<T>('DELETE', path);
  }

  private async send<T>(method: Method, path: string, body?: unknown, isRetry = false): Promise<T> {
    const response = await fetch(`${this.restUrl}/${path.replace(/^\//, '')}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(this.nonce ? { 'X-WP-Nonce': this.nonce } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await this.parse(response);

    if (response.ok) {
      return payload as T;
    }

    const error = payload as WpErrorBody;
    const code = typeof error.code === 'string' ? error.code : 'fc_request_failed';

    if (!isRetry && response.status === 403 && STALE_NONCE_CODES.has(code)) {
      if (await this.refreshNonce()) {
        return this.send<T>(method, path, body, true);
      }
    }

    throw new ApiError(
      code,
      typeof error.message === 'string' ? error.message : 'Something went wrong.',
      response.status,
      (error.data ?? {}) as Record<string, unknown>,
    );
  }

  /**
   * Ask admin-ajax for a nonce bound to whatever session the cookie still names.
   * Returns false when there is no session left to refresh for.
   */
  private refreshNonce(): Promise<boolean> {
    this.refreshing ??= this.doRefresh().finally(() => {
      this.refreshing = null;
    });

    return this.refreshing;
  }

  private async doRefresh(): Promise<boolean> {
    try {
      const response = await fetch(`${this.ajaxUrl}?action=fc_nonce`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        return false;
      }

      const json = (await response.json()) as {
        success?: boolean;
        data?: { nonce?: string; logged_in?: boolean };
      };

      const nonce = json?.data?.nonce;
      if (json?.success !== true || typeof nonce !== 'string' || nonce === '') {
        return false;
      }

      this.nonce = nonce;
      return true;
    } catch {
      // Offline, or the site is down. The caller surfaces the original error.
      return false;
    }
  }

  /**
   * Parse a body that should be JSON but might be an HTML error page — a fatal
   * error or a security plugin's block page both arrive as text/html, and
   * `response.json()` would throw a SyntaxError that says nothing useful.
   */
  private async parse(response: Response): Promise<unknown> {
    // `null`, not `{}`. An empty body means "no content", and an empty object is
    // *truthy* — return one and every caller that checks `if (result)` before
    // reading a field gets a crash three components later instead of a null.
    if (response.status === 204) {
      return null;
    }

    const text = await response.text();
    if (text === '') {
      return null;
    }

    try {
      return JSON.parse(text) as unknown;
    } catch {
      throw new ApiError(
        'fc_bad_response',
        'The server sent a response the app could not read.',
        response.status,
      );
    }
  }
}
