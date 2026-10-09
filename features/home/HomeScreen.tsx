import { useEffect, useState } from "react";
import type { ActivityPage, Employee } from "../../domain/models";
import type { ActivityService, ExceptionService } from "../../services/contracts";
import { ExceptionsCard } from "../exceptions/ExceptionsCard";
import { AppIcon } from "../../components/icons/AppIcon";
import { hasPermission } from "../../domain/permissions";
import { CuttingWork } from "../cutting/CuttingWork";
import type { CuttingApi } from "../../domain/cutting";
import type { DocumentApi } from "../../domain/documents";
import { DocumentsCard } from "../documents/DocumentsCard";
import { DocumentLookup } from "../documents/DocumentScreen";
import { canManageAuthority, type AuthorityApi } from "../../domain/authority";
import { canUseTrendyolWorkspace, type TrendyolApi } from "../../domain/trendyol";

type TodaySummary = ActivityPage["summary"];
type MetricsState = { status: "loading" } | { status: "loaded"; summary: TodaySummary } | { status: "unavailable" };

export function loadTodaySummary(service: ActivityService, signal?: AbortSignal): Promise<TodaySummary> {
  return service.listMine({ range: "today" }, { signal }).then((page) => page.summary);
}

export function formatHomeMetric(value: number | undefined): string {
  return typeof value === "number" && Number.isFinite(value) ? String(value) : "—";
}

export function HomeScreen({ employee, activityService, exceptionService, cutting, documents, authority, trendyol, navigate }: { employee: Employee; activityService: ActivityService; exceptionService?: ExceptionService; cutting?: CuttingApi; documents?: DocumentApi; authority?: AuthorityApi; trendyol?: TrendyolApi; navigate: (path: string) => void }) {
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
      {documents && (employee.permissions.includes("production.documents.request_revision") || employee.permissions.includes("production.documents.generate")) && <>
        <DocumentsCard service={documents} navigate={navigate} />
        <DocumentLookup service={documents} navigate={navigate} />
      </>}
      {trendyol && canUseTrendyolWorkspace(employee) && <button type="button" className="exception-row needs-action" data-testid="home-trendyol" onClick={() => navigate("/trendyol")}><span><strong>Comenzi Trendyol</strong><small>Verifică produsele, completează măsurile și aprobă intrarea în producție</small></span><span aria-hidden="true">→</span></button>}
      {exceptionService && <ExceptionsCard service={exceptionService} navigate={navigate} />}
      {authority && canManageAuthority(employee) && <button type="button" className="exception-row" onClick={() => navigate("/authority")}><span><strong>Autoritate producție</strong><small>Preia o comandă din YD SOFT în Arasya, cu etapa aleasă explicit</small></span><span aria-hidden="true">→</span></button>}
      {cutting && employee.allowedStageIds.includes("material-preparation") && !employee.isRoot && <CuttingWork service={cutting} navigate={navigate} />}
      <section className="work-summary" aria-labelledby="today-summary" aria-live="polite"><p id="today-summary">{metrics.status === "unavailable" ? "Activitate indisponibilă" : "Astăzi"}</p><dl><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(inProgress)}</dd><dt>În lucru</dt></div><div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{metrics.status === "loading" ? "…" : formatHomeMetric(handedOver)}</dd><dt>Predate</dt></div></dl></section>
    </div>
  );
}
