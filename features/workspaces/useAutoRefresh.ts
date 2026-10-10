import { useCallback, useEffect, useRef, useState } from "react";
import { toServiceError } from "../../services/errors";
import { createRefreshScheduler, type RefreshReason, type RefreshSnapshot } from "./refreshScheduler";

/** The Trendyol workspace refresh period. Only the Trendyol dashboard and inbox refresh automatically. */
export const AUTO_REFRESH_MS = 60_000;

export type AutoRefreshData<T> = RefreshSnapshot<T> & { refresh: () => void };

/**
 * Reads on mount and on `key` change, then every `intervalMs` while the tab is visible (see createRefreshScheduler).
 * A new key starts a new scheduler seeded with the last good data, so the loader can keep what still applies.
 * Unmounting (navigation) stops the timer and aborts the read in flight.
 */
export function useAutoRefresh<T>(load: (signal: AbortSignal, previous: T | null, reason: RefreshReason) => Promise<T>, key: string, intervalMs: number = AUTO_REFRESH_MS): AutoRefreshData<T> {
  const [snapshot, setSnapshot] = useState<RefreshSnapshot<T>>({ data: null, error: null, updatedAt: null, busy: true, refreshing: false, stopped: false });
  const latest = useRef<T | null>(null);
  const scheduler = useRef<ReturnType<typeof createRefreshScheduler<T>> | null>(null);

  useEffect(() => {
    const seed = latest.current;
    const current = createRefreshScheduler<T>({
      load: (signal, previous, reason) => load(signal, previous ?? seed, reason),
      toError: toServiceError,
      intervalMs,
      onChange: (next) => {
        if (next.data !== null) latest.current = next.data;
        setSnapshot({ ...next, data: next.data ?? seed, updatedAt: next.updatedAt ?? null });
      },
    });
    scheduler.current = current;
    current.start();
    return () => {
      current.dispose();
      if (scheduler.current === current) scheduler.current = null;
    };
  }, [load, key, intervalMs]);

  const refresh = useCallback(() => scheduler.current?.refresh(), []);
  return { ...snapshot, refresh };
}
