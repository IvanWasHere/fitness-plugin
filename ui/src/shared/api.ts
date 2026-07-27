import type { BootPayload } from './boot';

/**
 * The REST client every screen goes through (plans/02-api-contract.md).
 *
 * The SPA is served from the site's own origin (D9), so
 * `credentials: 'same-origin'` carries the plugin's session cookie and
 * `X-FC-CSRF` proves the request is not cross-site.
 *
 * ## The nonce-refresh machinery is gone, and good riddance
 *
 * This class used to carry ~90 lines of retry logic for one problem: a
 * `wp_rest` nonce expires on its own 12–24 hour clock, *independently of the
 * session*. An app left open overnight — a phone in a pocket between gym
 * sessions is exactly this — started getting `403 rest_cookie_invalid_nonce` on
 * every call while still looking signed in, which presented as "the app
 * silently stopped saving". The fix was to refresh the nonce through admin-ajax
 * and retry once.
 *
 * The plugin's CSRF token lives exactly as long as the session it is bound to,
 * so that state cannot occur: if the token is bad, the session is gone, and the
 * honest answer is a 401 and the login panel — which `isAuthError` already
 * drives. No refresh, no retry, no `ajaxUrl`.
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

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export class ApiClient {
  private csrf: string;

  private readonly restUrl: string;

  constructor(boot: Pick<BootPayload, 'restUrl' | 'csrf'>) {
    this.restUrl = boot.restUrl.replace(/\/$/, '');
    this.csrf = boot.csrf;
  }

  /** The current CSRF token — a fresh session response supersedes it. */
  setCsrf(csrf: string): void {
    if (csrf) {
      this.csrf = csrf;
    }
  }

  /** The plain URL for `navigator.sendBeacon`. */
  beaconUrl(path: string): string {
    return `${this.restUrl}/${path.replace(/^\//, '')}`;
  }

  /**
   * A body that authenticates without request headers, for `navigator.sendBeacon`.
   *
   * A beacon cannot set headers, so the token rides in the JSON body, where
   * `WP_REST_Request` parses it into a normal parameter. It deliberately does
   * *not* go in the query string, which is where the old `?_wpnonce=` fallback
   * put it — a query parameter ends up in web-server access logs, in `Referer`
   * headers and in every proxy in between, which is no place for a token.
   */
  beaconBody(body: Record<string, unknown>): Blob {
    return new Blob([JSON.stringify({ ...body, _csrf: this.csrf })], {
      type: 'application/json',
    });
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

  private async send<T>(method: Method, path: string, body?: unknown): Promise<T> {
    const response = await fetch(`${this.restUrl}/${path.replace(/^\//, '')}`, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(this.csrf ? { 'X-FC-CSRF': this.csrf } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await this.parse(response);

    if (response.ok) {
      return payload as T;
    }

    const error = payload as WpErrorBody;

    throw new ApiError(
      typeof error.code === 'string' ? error.code : 'fc_request_failed',
      typeof error.message === 'string' ? error.message : 'Something went wrong.',
      response.status,
      (error.data ?? {}) as Record<string, unknown>,
    );
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
