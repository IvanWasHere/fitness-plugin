/**
 * The little bit of routing the auth panel needs before react-router arrives in
 * W1.5. Kept out of the JSX modules so those export components only.
 */
export type AuthView = 'login' | 'register' | 'forgot' | 'reset';

export interface ResetParams {
  key: string;
  login: string;
}

/** The path segment under the configured base, e.g. "reset" for /fitness/reset. */
function segment(basename: string): string {
  return window.location.pathname.replace(basename, '').replace(/^\/+|\/+$/g, '');
}

/** Which panel to open, from the path the shell was rendered at. */
export function initialView(basename: string): AuthView {
  const path = segment(basename);

  return path === 'reset' || path === 'register' || path === 'forgot'
    ? (path as AuthView)
    : 'login';
}

export function resetParams(): ResetParams {
  const params = new URLSearchParams(window.location.search);

  return { key: params.get('key') ?? '', login: params.get('login') ?? '' };
}

/**
 * Is this page an emailed reset link?
 *
 * Checked *before* the signed-in shell renders: a user who still has a session
 * on this browser and clicked "forgot password" on their phone must land on the
 * reset form, not on a dashboard that ignores why they came.
 */
export function isResetLink(basename: string): boolean {
  const { key, login } = resetParams();

  return segment(basename) === 'reset' && key !== '' && login !== '';
}
