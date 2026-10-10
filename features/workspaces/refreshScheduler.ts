import type { StaffServiceError } from "../../domain/models";

/** Why a read runs: the first read (or a new key), the employee's button, the timer, or the tab becoming visible. */
export type RefreshReason = "initial" | "manual" | "interval" | "visible";

export type RefreshSnapshot<T> = {
  data: T | null;
  /** The last read's error; the last good data stays in `data`. */
  error: StaffServiceError | null;
  /** When the last read succeeded (epoch ms): the Staff refresh time, not a Trendyol synchronization. */
  updatedAt: number | null;
  /** A read the employee waits for (first read or the button). */
  busy: boolean;
  /** A background read (timer or tab visible again) while the last good data stays on screen. */
  refreshing: boolean;
  /** Automatic refresh stopped after a session or access refusal; only the button reads again. */
  stopped: boolean;
};

export type RefreshVisibility = { isVisible: () => boolean; subscribe: (listener: () => void) => () => void };

export type RefreshSchedulerOptions<T> = {
  /** Reads the data. `previous` is the last good data, so a background read can reuse unchanged parts. */
  load: (signal: AbortSignal, previous: T | null, reason: RefreshReason) => Promise<T>;
  toError: (caught: unknown) => StaffServiceError;
  onChange: (snapshot: RefreshSnapshot<T>) => void;
  /** Automatic refresh period; without it the scheduler reads only on start and on the button. */
  intervalMs?: number;
  visibility?: RefreshVisibility;
  now?: () => number;
};

/** Session or access refusals: polling again cannot succeed and must not keep calling the API. */
const terminalCodes = new Set(["SESSION_EXPIRED", "NO_SESSION", "ACCOUNT_INACTIVE", "PASSWORD_CHANGE_REQUIRED", "APPLICATION_ACCESS_DENIED", "UNAUTHORIZED_ACTION"]);
/** After failures the next automatic read waits 2×, 4× … the interval, at most this many intervals. */
const MAX_BACKOFF_INTERVALS = 5;

export const browserVisibility: RefreshVisibility = {
  isVisible: () => typeof document === "undefined" || document.visibilityState === "visible",
  subscribe: (listener) => {
    if (typeof document === "undefined") return () => undefined;
    document.addEventListener("visibilitychange", listener);
    return () => document.removeEventListener("visibilitychange", listener);
  },
};

/**
 * Reads now, then once per interval while the page is visible. One timer and at most one read at a time: the
 * next timer starts only after a read settles, a newer read aborts the older one, and an aborted or superseded
 * read never changes the snapshot. A hidden tab reads nothing; when it becomes visible again it reads at once
 * if the data is older than the interval. Failures keep the last good data and back off; a session or access
 * refusal stops automatic reads. Reads only: the loader must never change server data.
 */
export function createRefreshScheduler<T>(options: RefreshSchedulerOptions<T>) {
  const now = options.now ?? Date.now;
  const visibility = options.visibility ?? browserVisibility;
  let snapshot: RefreshSnapshot<T> = { data: null, error: null, updatedAt: null, busy: false, refreshing: false, stopped: false };
  let controller: AbortController | null = null;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let settledAt = 0;
  let failures = 0;
  let disposed = false;
  let unsubscribe: (() => void) | null = null;

  const emit = (next: Partial<RefreshSnapshot<T>>) => { snapshot = { ...snapshot, ...next }; options.onChange(snapshot); };
  const clearTimer = () => { if (timer !== null) { clearTimeout(timer); timer = null; } };
  const delay = (): number => (options.intervalMs ?? 0) * (failures === 0 ? 1 : Math.min(2 ** failures, MAX_BACKOFF_INTERVALS));

  function schedule() {
    clearTimer();
    if (disposed || !options.intervalMs || snapshot.stopped || controller !== null || !visibility.isVisible()) return;
    timer = setTimeout(() => { timer = null; run("interval"); }, Math.max(0, settledAt + delay() - now()));
  }

  function run(reason: RefreshReason) {
    if (disposed) return;
    clearTimer();
    controller?.abort();
    const current = new AbortController();
    controller = current;
    const foreground = reason === "initial" || reason === "manual" || snapshot.data === null;
    emit({ busy: foreground, refreshing: !foreground, ...(reason === "manual" ? { stopped: false } : {}) });
    options.load(current.signal, snapshot.data, reason).then(
      (data) => {
        if (current.signal.aborted || disposed) return;
        failures = 0;
        controller = null;
        settledAt = now();
        emit({ data, error: null, updatedAt: settledAt, busy: false, refreshing: false });
        schedule();
      },
      (caught: unknown) => {
        if (current.signal.aborted || disposed) return;
        const error = options.toError(caught);
        failures += 1;
        controller = null;
        settledAt = now();
        emit({ error, busy: false, refreshing: false, stopped: terminalCodes.has(error.code) });
        schedule();
      },
    );
  }

  function onVisibility() {
    if (disposed) return;
    if (!visibility.isVisible()) { clearTimer(); return; }
    if (controller !== null || snapshot.stopped || !options.intervalMs) return;
    if (now() - settledAt >= delay()) run("visible");
    else schedule();
  }

  return {
    start() {
      if (disposed) return;
      if (options.intervalMs && unsubscribe === null) unsubscribe = visibility.subscribe(onVisibility);
      run("initial");
    },
    /** The employee's button: reads now, even after a failure or a stop. */
    refresh() { run("manual"); },
    dispose() {
      disposed = true;
      clearTimer();
      controller?.abort();
      controller = null;
      unsubscribe?.();
      unsubscribe = null;
    },
    snapshot: () => snapshot,
  };
}
