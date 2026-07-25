import { useEffect, useReducer, useRef } from 'react';

/**
 * Timestamp-derived timers (plans/04-user-app.md#timers).
 *
 * The prototype ran all three of its timers as `setInterval` counters that
 * incremented a number. Two things break that, and both happen on every phone:
 * intervals drift, and browsers throttle background tabs to ≥1 s — often far
 * worse once the screen locks. Leave the player, come back, and the counter is
 * minutes behind the actual workout.
 *
 * Here an interval only ever triggers a **re-render**; every displayed value is
 * derived from timestamps at render time. A tab that was frozen for ten minutes
 * renders the correct number on its first frame back.
 */

/** Run `callback` every `delayMs`, or not at all when `delayMs` is null. */
export function useInterval(callback: () => void, delayMs: number | null): void {
  const saved = useRef(callback);
  saved.current = callback;

  useEffect(() => {
    if (delayMs === null) {
      return;
    }

    const id = window.setInterval(() => saved.current(), delayMs);

    return () => window.clearInterval(id);
  }, [delayMs]);
}

/** Force a re-render on a cadence, without owning any state of its own. */
function useTick(active: boolean, everyMs = 1000): void {
  const [, tick] = useReducer((n: number) => n + 1, 0);
  useInterval(tick, active ? everyMs : null);
}

/**
 * Elapsed active seconds for a running workout.
 *
 * Measured against **the client clock's own elapsed time since the response was
 * received**, not against a server timestamp parsed into local time. That makes
 * it immune to clock skew: a phone whose clock is ten minutes fast still shows
 * the right duration, because only the delta since `baseCapturedAtMs` is local.
 * The server remains the authority — it recomputes duration on pause and
 * complete, and this display is replaced by that value.
 *
 * @param baseSeconds      `elapsed_seconds` from the last server response.
 * @param baseCapturedAtMs `Date.now()` when that response arrived.
 * @param running          Whether the session is currently in progress.
 */
export function useElapsed(
  baseSeconds: number,
  baseCapturedAtMs: number,
  running: boolean,
): number {
  useTick(running);

  if (!running) {
    return baseSeconds;
  }

  return baseSeconds + Math.max(0, Math.floor((Date.now() - baseCapturedAtMs) / 1000));
}

/**
 * Seconds remaining until `endsAtMs`, negative once it has passed.
 *
 * Rest is a client-side concept — the elapsed rest is stamped and sent with the
 * next set — so a locally-set target timestamp is exactly right here, with no
 * skew to worry about.
 */
export function useCountdown(endsAtMs: number | null): number | null {
  useTick(endsAtMs !== null);

  if (endsAtMs === null) {
    return null;
  }

  return Math.round((endsAtMs - Date.now()) / 1000);
}

/** `1 505` → `25:05`; `3 725` → `1:02:05`. */
export function formatDuration(totalSeconds: number): string {
  const seconds = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const rest = seconds % 60;

  const mm = hours > 0 ? String(minutes).padStart(2, '0') : String(minutes);

  return `${hours > 0 ? `${hours}:` : ''}${mm}:${String(rest).padStart(2, '0')}`;
}
