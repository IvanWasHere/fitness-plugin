import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react';
import { ToastContext, type Toast, type ToastApi, type ToastTone } from './toast-context';

const LIFETIME_MS = 4500;

/**
 * Transient messages.
 *
 * Rendered into an `aria-live` region so a screen reader announces them — the
 * prototype's toasts were silent `<div>`s, which means a blind user got no
 * confirmation that anything happened at all.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextId = useRef(1);

  const push = useCallback((tone: ToastTone, message: string) => {
    const id = nextId.current++;

    setToasts((current) => [...current, { id, tone, message }]);
    window.setTimeout(() => {
      setToasts((current) => current.filter((toast) => toast.id !== id));
    }, LIFETIME_MS);
  }, []);

  const api = useMemo<ToastApi>(
    () => ({
      success: (message) => push('success', message),
      info: (message) => push('info', message),
      error: (message) => push('error', message),
    }),
    [push],
  );

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="fc-toasts" role="status" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} className={`fc-toast fc-toast--${toast.tone}`}>
            {toast.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
