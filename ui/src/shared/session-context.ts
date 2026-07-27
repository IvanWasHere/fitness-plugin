import { createContext, useContext } from 'react';
import type { ApiClient } from './api';
import type { BootPayload } from './boot';

/**
 * The session contract, kept apart from the provider component so the module
 * that ships JSX exports nothing but components (which is what keeps Fast
 * Refresh working during `npm run dev`).
 */
export interface Credentials {
  user_login: string;
  password: string;
  remember?: boolean;
}

export interface Registration {
  email: string;
  password: string;
  display_name?: string;
}

export interface ResetInput {
  key: string;
  login: string;
  password: string;
}

/** The shape of the small `{ ok, message }` replies the auth routes return. */
export interface MessageResponse {
  ok: boolean;
  message?: string;
  csrf?: string;
}

export interface SessionValue {
  boot: BootPayload;
  api: ApiClient;
  isAuthenticated: boolean;
  login(credentials: Credentials): Promise<void>;
  register(input: Registration): Promise<void>;
  logout(): Promise<void>;
  forgotPassword(userLogin: string): Promise<string>;
  resetPassword(input: ResetInput): Promise<string>;
  refresh(): Promise<void>;
}

export const SessionContext = createContext<SessionValue | null>(null);

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) {
    throw new Error('useSession must be used inside <SessionProvider>.');
  }
  return value;
}
