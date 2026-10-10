import { useCallback, useEffect, useState } from "react";
import type { StaffServiceError } from "../../domain/models";
import { toServiceError } from "../../services/errors";

export type DashboardData<T> = {
  data: T | null;
  error: StaffServiceError | null;
  busy: boolean;
  updatedAt: Date | null;
  refresh: () => void;
};

/**
 * One read per trigger (mount, `key` change, live revision or a manual refresh); never a polling loop and never an
 * automatic retry. The last good data stays visible while a refresh runs; an error is shown with a manual retry.
 */
export function useDashboardData<T>(load: ((signal: AbortSignal) => Promise<T>) | null, key: string, revision = 0): DashboardData<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const [manual, setManual] = useState(0);
  const [loadedKey, setLoadedKey] = useState(key);
  const trigger = `${key}|${revision}|${manual}`;
  const [settled, setSettled] = useState<string | null>(null);

  if (loadedKey !== key) {
    // A different stage or workspace: never show the previous one's numbers.
    setLoadedKey(key);
    setData(null);
    setError(null);
  }

  useEffect(() => {
    if (!load) return undefined;
    const controller = new AbortController();
    load(controller.signal)
      .then((next) => { if (!controller.signal.aborted) { setData(next); setError(null); setUpdatedAt(new Date()); } })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); })
      .finally(() => { if (!controller.signal.aborted) setSettled(trigger); });
    return () => controller.abort();
  }, [load, trigger]);

  const refresh = useCallback(() => setManual((value) => value + 1), []);
  return { data, error, busy: load !== null && settled !== trigger, updatedAt, refresh };
}
