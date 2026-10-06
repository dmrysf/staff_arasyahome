import { useEffect, useState } from "react";
import type { ActivityPage, Employee } from "../../domain/models";
import type { ActivityService, ExceptionService } from "../../services/contracts";
import { ExceptionsCard } from "../exceptions/ExceptionsCard";
import { AppIcon } from "../../components/icons/AppIcon";
import { hasPermission } from "../../domain/permissions";

type TodaySummary = ActivityPage["summary"];
type MetricsState = { status: "loading" } | { status: "loaded"; summary: TodaySummary } | { status: "unavailable" };

export function loadTodaySummary(service: ActivityService, signal?: AbortSignal): Promise<TodaySummary> {
  return service.listMine({ range: "today" }, { signal }).then((page) => page.summary);
}

export function formatHomeMetric(value: number | undefined): string {
  return typeof value === "number" && Number.isFinite(value) ? String(value) : "—";
}

export function HomeScreen({ employee, activityService, exceptionService, navigate }: { employee: Employee; activityService: ActivityService; exceptionService?: ExceptionService; navigate: (path: string) => void }) {
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
      <section className="greeting"><p>{employee.department}</p><h1>Bună, {employee.displayName.split(" ")[0]}.</h1></section>
      <button className="home-scan" type="button" disabled={!hasPermission(employee, "orders.scan")} onClick={() => navigate("/scan")} aria-label="Scanează o comandă">
        <span className="home-scan-icon" aria-hidden="true"><AppIcon name="scan" size={34} /></span>
        <span className="home-scan-copy"><strong>Scanează comanda</strong><span>Apropie codul QR pentru a începe.</span></span>
        <AppIcon name="arrow" size={24} />
      </button>
      {exceptionService && <ExceptionsCard service={exceptionService} navigate={navigate} />}
      <section className="work-summary" aria-labelledby="today-summary" aria-live="polite"><p id="today-summary">{metrics.status === "unavailable" ? "Activitate indisponibilă" : "Astăzi"}</p><dl><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(inProgress)}</dd><dt>În lucru</dt></div><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(handedOver)}</dd><dt>Predate</dt></div></dl></section>
    </div>
  );
}
