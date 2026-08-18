import { useEffect, useState } from "react";
import type { ActivityPage, Employee } from "../../domain/models";
import type { ActivityService } from "../../services/contracts";

type TodaySummary = ActivityPage["summary"];
type MetricsState = { status: "loading" } | { status: "loaded"; summary: TodaySummary } | { status: "unavailable" };

export function loadTodaySummary(service: ActivityService, signal?: AbortSignal): Promise<TodaySummary> {
  return service.listMine({ range: "today" }, { signal }).then((page) => page.summary);
}

export function formatHomeMetric(value: number | undefined): string {
  return typeof value === "number" && Number.isFinite(value) ? String(value) : "—";
}

export function HomeScreen({ employee, activityService, navigate }: { employee: Employee; activityService: ActivityService; navigate: (path: string) => void }) {
  const [metrics, setMetrics] = useState<MetricsState>({ status: "loading" });

  useEffect(() => {
    const controller = new AbortController();
    loadTodaySummary(activityService, controller.signal)
      .then((summary) => setMetrics({ status: "loaded", summary }))
      .catch(() => { if (!controller.signal.aborted) setMetrics({ status: "unavailable" }); });
    return () => controller.abort();
  }, [activityService]);

  const inProgress = metrics.status === "loaded" ? metrics.summary.inProgress : undefined;
  const handedOver = metrics.status === "loaded" ? metrics.summary.handedOver : undefined;

  return (
    <div className="home-layout">
      <section className="greeting"><p className="eyebrow">{employee.department}</p><h1>Bună, {employee.name.split(" ")[0]}</h1><p>Ești gata pentru următoarea comandă.</p></section>
      <button className="home-scan" type="button" onClick={() => navigate("/scan")}>
        <span className="scan-corners" aria-hidden="true"><i /><i /><i /><i /></span>
        <span className="home-scan-copy"><small>Acțiune principală</small><strong>Scanează<br />comanda</strong><span>Scanează codul QR pentru a prelua sau actualiza comanda.</span></span>
        <span className="home-scan-cta">Deschide camera <b aria-hidden="true">→</b></span>
      </button>
      <section className="work-summary" aria-labelledby="today-summary" aria-live="polite"><div><p className="eyebrow" id="today-summary">Astăzi</p><p>{metrics.status === "unavailable" ? "Date indisponibile" : "Rezumatul tău de lucru"}</p></div><dl><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(inProgress)}</dd><dt>În lucru</dt></div><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(handedOver)}</dd><dt>Predate</dt></div></dl></section>
    </div>
  );
}
