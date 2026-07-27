import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { ApiClient } from './api';
import { bootOrDefaults, type BootIdentity, type BootPayload, type SpaName } from './boot';
import {
  SessionContext,
  type Credentials,
  type MessageResponse,
  type Registration,
  type ResetInput,
  type SessionValue,
} from './session-context';

/**
 * Session state for all three SPAs (W1.3).
 *
 * The shell already handed us a fully populated boot payload, so this starts
 * *resolved* — there is no loading state on first paint and no `/auth/me` call
 * on mount. `/auth/me` exists for re-syncing after a change, not for booting.
 *
 * One subtlety worth stating: which SPA bundle is loaded is a **server**
 * decision (D9/D10 — the role resolves the bundle at the URL). So when a sign-in
 * resolves to a different role than the bundle currently running — a trainer
 * signing in through the login panel, which is served by the *user* bundle — the
 * only correct move is a navigation, not a re-render. That is the one place this
 * app reloads itself on purpose.
 */
export function SessionProvider({ spa, children }: { spa: SpaName; children: ReactNode }) {
  const [boot, setBoot] = useState<BootPayload>(() => bootOrDefaults(spa));

  // Built once from the first payload; the CSRF token it carries lives exactly
  // as long as the session, so there is nothing to refresh.
  const api = useMemo(() => new ApiClient(boot), []); // eslint-disable-line react-hooks/exhaustive-deps

  /**
   * Adopt a fresh boot payload, or hand off to the bundle that actually serves
   * this user's role.
   */
  const adopt = useCallback(
    (next: BootPayload) => {
      api.setCsrf(next.csrf);

      if (next.app.spa !== spa) {
        window.location.assign(`${next.app.base}/`);
        return;
      }

      setBoot(next);
    },
    [api, spa],
  );

  const login = useCallback(
    async (credentials: Credentials) => {
      adopt(await api.post<BootPayload>('auth/login', credentials));
    },
    [api, adopt],
  );

  const register = useCallback(
    async (input: Registration) => {
      adopt(await api.post<BootPayload>('auth/register', input));
    },
    [api, adopt],
  );

  const logout = useCallback(async () => {
    const result = await api.post<MessageResponse>('auth/logout');

    // Anonymous visitors are always served the user bundle, so anything else has
    // to navigate rather than re-render into a shell it cannot draw.
    if (spa !== 'user') {
      window.location.assign(`${boot.app.base}/`);
      return;
    }

    api.setCsrf(result.csrf ?? '');
    setBoot((current) => ({
      ...current,
      csrf: result.csrf ?? current.csrf,
      user: null,
      subscriptions: [],
      trainers: [],
      counts: { unread_messages: 0, unread_notifications: 0 },
    }));
  }, [api, boot.app.base, spa]);

  const forgotPassword = useCallback(
    async (userLogin: string) => {
      const result = await api.post<MessageResponse>('auth/password/forgot', {
        user_login: userLogin,
      });
      return result.message ?? '';
    },
    [api],
  );

  const resetPassword = useCallback(
    async (input: ResetInput) => {
      const result = await api.post<MessageResponse>('auth/password/reset', input);
      return result.message ?? '';
    },
    [api],
  );

  const refresh = useCallback(async () => {
    const identity = await api.get<BootIdentity>('auth/me');
    setBoot((current) => ({ ...current, ...identity }));
  }, [api]);

  const value = useMemo<SessionValue>(
    () => ({
      boot,
      api,
      isAuthenticated: boot.user !== null,
      login,
      register,
      logout,
      forgotPassword,
      resetPassword,
      refresh,
    }),
    [boot, api, login, register, logout, forgotPassword, resetPassword, refresh],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}
