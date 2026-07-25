import { createContext, useContext } from 'react';

export type ToastTone = 'success' | 'info' | 'error';

export interface Toast {
  id: number;
  tone: ToastTone;
  message: string;
}

export interface ToastApi {
  success(message: string): void;
  info(message: string): void;
  error(message: string): void;
}

export const ToastContext = createContext<ToastApi | null>(null);

/**
 * Toasts are advisory, never load-bearing: a component that cannot reach the
 * provider (a test, a screen rendered in isolation) gets no-ops rather than a
 * thrown error, because failing to *announce* something should never be what
 * breaks a screen.
 */
const NOOP: ToastApi = {
  success: () => undefined,
  info: () => undefined,
  error: () => undefined,
};

export function useToast(): ToastApi {
  return useContext(ToastContext) ?? NOOP;
}
