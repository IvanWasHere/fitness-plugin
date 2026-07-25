import { useEffect, useRef } from 'react';

/**
 * Keep the screen awake while a workout is running
 * (plans/04-user-app.md#workout-player--the-hard-part).
 *
 * A screen that sleeps mid-set is the top complaint about every workout app: the
 * user puts the phone down between reps and comes back to a locked device.
 *
 * Two details the API forces on us:
 *
 *   - **The lock is dropped whenever the page is hidden.** Switching apps or
 *     locking the phone manually releases it, and it is *not* restored on
 *     return — so the lock has to be re-requested on `visibilitychange`.
 *   - **It can be refused.** Low battery, an unsupported browser (Safari before
 *     16.4, Firefox), or an insecure origin all reject the request. That is not
 *     an error worth showing anyone; the workout carries on either way, and the
 *     timers are timestamp-derived precisely so a sleeping screen costs nothing.
 */
interface WakeLockSentinelLike {
  released: boolean;
  release(): Promise<void>;
}

type WakeLockNavigator = Navigator & {
  wakeLock?: { request(type: 'screen'): Promise<WakeLockSentinelLike> };
};

export function useWakeLock(active: boolean): void {
  const sentinel = useRef<WakeLockSentinelLike | null>(null);

  useEffect(() => {
    const api = (navigator as WakeLockNavigator).wakeLock;
    if (!api || !active) {
      return;
    }

    let cancelled = false;

    const acquire = async () => {
      if (
        cancelled ||
        document.visibilityState !== 'visible' ||
        sentinel.current?.released === false
      ) {
        return;
      }

      try {
        sentinel.current = await api.request('screen');
      } catch {
        // Refused (battery saver, unsupported, insecure origin). Nothing to do.
      }
    };

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        void acquire();
      }
    };

    void acquire();
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      cancelled = true;
      document.removeEventListener('visibilitychange', onVisible);
      void sentinel.current?.release().catch(() => undefined);
      sentinel.current = null;
    };
  }, [active]);
}
