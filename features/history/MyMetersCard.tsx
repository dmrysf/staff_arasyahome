import { useEffect, useState } from "react";
import { t } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import type { MyMeters } from "../../domain/models";
import type { ActivityService } from "../../services/contracts";
import { formatMeters } from "../../domain/productFormat";
import { historyDate } from "./historyDate";

/** The first day of Production Intelligence (owner decision K3); the API refuses earlier dates. */
export const METERS_START = "2026-10-04";

export type MetersQuery = { period: "today" | "month" } | { from: string; to: string };

/**
 * The query of the history range for `/production-intelligence/mine`, clamped to the analysis start; null when the
 * whole range lies before it (nothing is asked, the card explains why).
 */
export function metersQuery(range: "today" | "7days" | "month" | "custom", custom: { from: string; to: string }, now = new Date()): MetersQuery | null {
  if (range === "today" || range === "month") return { period: range };
  const from = range === "7days" ? historyDate(-6, now) : custom.from;
  const to = range === "7days" ? historyDate(0, now) : custom.to;
  if (to < METERS_START) return null;
  return { from: from < METERS_START ? METERS_START : from, to };
}

/** Meters as shown: "12.500" -> "12,5 m" (active locale). */
function meters(value: string): string {
  return formatMeters(Number(value));
}

export type MyMetersState = { status: "loading" } | { status: "ready"; data: MyMeters } | { status: "unavailable" } | { status: "before-start" };

/** Loads the employee's own meters for the history range. The server takes the employee from the session only. */
export function useMyMeters(service: ActivityService, query: MetersQuery | null, enabled: boolean): MyMetersState {
  const key = JSON.stringify(query);
  const [state, setState] = useState<{ key: string; value: MyMetersState }>({ key: "", value: { status: "loading" } });
  useEffect(() => {
    if (!enabled || !service.myMeters || query === null) return undefined;
    const controller = new AbortController();
    service.myMeters(query, { signal: controller.signal }).then(
      (data) => { if (!controller.signal.aborted) setState({ key, value: data ? { status: "ready", data } : { status: "unavailable" } }); },
      () => { if (!controller.signal.aborted) setState({ key, value: { status: "unavailable" } }); },
    );
    return () => controller.abort();
    // `key` stands for `query`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, key, service]);
  if (!enabled || !service.myMeters) return { status: "unavailable" };
  if (query === null) return { status: "before-start" };
  return state.key === key ? state.value : { status: "loading" };
}

/** The meters tile of the summary: one stage shows its meters; several stages are never added together. */
export function metersTile(state: MyMetersState): string {
  if (state.status !== "ready") return "—";
  const rows = state.data.stages;
  if (rows.length === 0) return "0";
  return rows.length === 1 ? meters(rows[0].fabricMeters).replace(" m", "") : "—";
}

export function MyMetersCard({ state }: { state: MyMetersState }) {
  if (state.status === "loading") return null;
  return (
    <section className="my-meters" aria-labelledby="my-meters-title">
      <h2 id="my-meters-title">{t("history.meters.title")}</h2>
      <p className="my-meters-hint">{t("history.meters.hint")}</p>
      {state.status === "unavailable" && <p className="my-meters-note">{t("history.meters.unavailable")}</p>}
      {state.status === "before-start" && <p className="my-meters-note">{t("history.meters.beforeStart")}</p>}
      {state.status === "ready" && (state.data.stages.length === 0
        ? <p className="my-meters-note">{t("history.meters.empty")}</p>
        : <ul className="my-meters-list">
          {state.data.stages.map((row) => (
            <li key={row.stageId} data-testid={`my-meters-${row.stageId}`}>
              <div className="my-meters-head"><strong>{stageName(row.stageId, row.stageId)}</strong><b>{meters(row.fabricMeters)}</b></div>
              <dl>
                <div><dt>{t("history.meters.completions")}</dt><dd>{row.completions}</dd></div>
                {(row.cuts > 0 || row.recuts > 0) && <><div><dt>{t("history.meters.cuts")}</dt><dd>{row.cuts}</dd></div><div><dt>{t("history.meters.recuts")}</dt><dd>{row.recuts}</dd></div></>}
              </dl>
              {row.completionsWithoutMeters > 0 && <p className="my-meters-warning" role="note">{t("history.meters.withoutMeters", { count: row.completionsWithoutMeters })}</p>}
            </li>
          ))}
        </ul>)}
    </section>
  );
}
