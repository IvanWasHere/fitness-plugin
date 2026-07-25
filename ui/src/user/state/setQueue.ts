import type { ApiClient } from '@shared/api';
import type { SetPayload } from '../api/types';

/**
 * Offline-tolerant set logging (plans/04-user-app.md#workout-player--the-hard-part).
 *
 * Gyms have bad wifi. The player must never block on a set write, and must never
 * lose one: the alternative is a user finishing a workout and finding half of it
 * missing.
 *
 * How it holds together:
 *
 *   - **Optimistic and queued.** `enqueue()` returns immediately; the UI advances
 *     to the next set. Delivery is this queue's problem.
 *   - **Deduplicated by `(exercise, set)`.** That is the same key the server is
 *     idempotent on, so re-editing set 2 while set 2 is still queued replaces the
 *     pending write instead of sending two.
 *   - **Retry with backoff**, capped, and it stops on a 4xx that will never
 *     succeed (a rejected payload retried forever is a spinner that never ends).
 *   - **Persisted to localStorage**, so a crash or an accidental reload mid-set
 *     does not drop what was already lifted.
 *   - **Flushed via `sendBeacon` on pagehide**, because a normal fetch started
 *     during unload is cancelled. Beacons cannot set headers, which is why
 *     `ApiClient.beaconUrl()` puts the nonce in the query string.
 */

const STORAGE_PREFIX = 'fc:setqueue:';
const MAX_BACKOFF_MS = 30_000;

export interface QueueState {
  /** Sets waiting to reach the server. */
  pending: number;
  /** True while a delivery attempt is in flight. */
  syncing: boolean;
  /** Set after a failure; cleared as soon as anything succeeds. */
  offline: boolean;
  /**
   * Sets the server refused outright and the queue gave up on.
   *
   * Surfaced rather than swallowed: dropping a set silently while the badge
   * still reads "Saved" is the worst of both worlds — the user believes their
   * workout was recorded and it was not.
   */
  dropped: number;
}

type Listener = (state: QueueState) => void;

function storageKey(sessionId: number): string {
  return `${STORAGE_PREFIX}${sessionId}`;
}

function keyOf(item: SetPayload): string {
  return `${item.exercise_id}:${item.set_index}`;
}

export class SetQueue {
  private items: SetPayload[] = [];

  private syncing = false;

  private offline = false;

  private dropped = 0;

  private attempt = 0;

  private timer: number | null = null;

  private readonly listeners = new Set<Listener>();

  constructor(
    private readonly api: ApiClient,
    private readonly sessionId: number,
  ) {
    this.items = this.restore();
  }

  subscribe(listener: Listener): () => void {
    this.listeners.add(listener);
    listener(this.state());

    return () => {
      this.listeners.delete(listener);
    };
  }

  state(): QueueState {
    return {
      pending: this.items.length,
      syncing: this.syncing,
      offline: this.offline,
      dropped: this.dropped,
    };
  }

  /** Accept a set. Never rejects, never awaits the network. */
  enqueue(item: SetPayload): void {
    const existing = this.items.findIndex((queued) => keyOf(queued) === keyOf(item));

    if (existing >= 0) {
      this.items[existing] = item;
    } else {
      this.items.push(item);
    }

    this.persist();
    this.emit();
    void this.flush();
  }

  /**
   * Deliver everything, oldest first, one at a time.
   *
   * Sequential rather than parallel on purpose: a phone on 3G with eight
   * concurrent POSTs finishes slower than with one at a time, and order is the
   * order the user actually lifted in.
   */
  async flush(): Promise<void> {
    if (this.syncing || this.items.length === 0) {
      return;
    }

    this.clearTimer();
    this.syncing = true;
    this.emit();

    while (this.items.length > 0) {
      const item = this.items[0];

      try {
        await this.api.post(`sessions/${this.sessionId}/sets`, item);
        this.items.shift();
        this.attempt = 0;
        this.offline = false;
        this.persist();
        this.emit();
      } catch (error) {
        const status = (error as { status?: number }).status ?? 0;

        // 4xx that is not a rate limit will fail identically forever — a bad
        // payload, or a session that has been completed. Drop it rather than
        // retrying until the heat death of the universe, and let the reconciling
        // refetch show the truth.
        if (status >= 400 && status < 500 && status !== 429) {
          this.items.shift();
          this.dropped += 1;
          this.persist();
          this.emit();
          continue;
        }

        this.offline = true;
        this.syncing = false;
        this.emit();
        this.scheduleRetry();

        return;
      }
    }

    this.syncing = false;
    this.emit();
  }

  /**
   * Last-chance delivery as the page goes away. Fire-and-forget by definition —
   * the browser will not tell us whether it worked, and the server is idempotent
   * on the same key, so a beacon that duplicates a later retry is harmless.
   */
  flushWithBeacon(): void {
    if (this.items.length === 0 || typeof navigator.sendBeacon !== 'function') {
      return;
    }

    const url = this.api.beaconUrl(`sessions/${this.sessionId}/sets`);

    for (const item of this.items) {
      navigator.sendBeacon(url, new Blob([JSON.stringify(item)], { type: 'application/json' }));
    }
  }

  /** Called when the session ends; keeps localStorage from accumulating queues. */
  dispose(): void {
    this.clearTimer();
    this.listeners.clear();

    if (this.items.length === 0) {
      window.localStorage.removeItem(storageKey(this.sessionId));
    }
  }

  private scheduleRetry(): void {
    this.attempt += 1;
    const delay = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** (this.attempt - 1));

    this.timer = window.setTimeout(() => {
      this.timer = null;
      void this.flush();
    }, delay);
  }

  private clearTimer(): void {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }
  }

  private persist(): void {
    try {
      if (this.items.length === 0) {
        window.localStorage.removeItem(storageKey(this.sessionId));
        return;
      }

      window.localStorage.setItem(storageKey(this.sessionId), JSON.stringify(this.items));
    } catch {
      // Private browsing, or the quota is full. The in-memory queue still works;
      // only crash-recovery is lost.
    }
  }

  private restore(): SetPayload[] {
    try {
      const raw = window.localStorage.getItem(storageKey(this.sessionId));
      const parsed: unknown = raw ? JSON.parse(raw) : null;

      return Array.isArray(parsed) ? (parsed as SetPayload[]) : [];
    } catch {
      return [];
    }
  }

  private emit(): void {
    const state = this.state();
    for (const listener of this.listeners) {
      listener(state);
    }
  }
}
