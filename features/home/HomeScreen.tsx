import { useEffect, useMemo, useState } from "react";
import type { ActivityPage, Employee, ProductionWorkflow } from "../../domain/models";
import type { ActivityService, ServiceBundle } from "../../services/contracts";
import { ExceptionsCard } from "../exceptions/ExceptionsCard";
import { DocumentsCard } from "../documents/DocumentsCard";
import { DocumentLookup } from "../documents/DocumentScreen";
import { hasPermission } from "../../domain/permissions";
import { canManageAuthority } from "../../domain/authority";
import { firstName, readWorkspacePreference, resolveWorkspaces, saveWorkspacePreference, selectPrimaryWorkspace, type Workspace } from "../../domain/workspaces";
import { AppIcon } from "../../components/icons/AppIcon";
import { WorkspaceSwitcher } from "../workspaces/DashboardParts";
import { StageWorkspace } from "../workspaces/StageWorkspace";
import { TrendyolDashboard } from "../workspaces/TrendyolDashboard";
import { ManagementWorkspace } from "../workspaces/ManagementWorkspace";

type TodaySummary = ActivityPage["summary"];
type MetricsState = { status: "loading" } | { status: "loaded"; summary: TodaySummary } | { status: "unavailable" };

export function loadTodaySummary(service: ActivityService, signal?: AbortSignal): Promise<TodaySummary> {
  return service.listMine({ range: "today" }, { signal }).then((page) => page.summary);
}

export function formatHomeMetric(value: number | undefined): string {
  return typeof value === "number" && Number.isFinite(value) ? String(value) : "—";
}

/** The employee's own stage activity today (claims in progress, handovers). */
function TodayWork({ service }: { service: ActivityService }) {
  const [metrics, setMetrics] = useState<MetricsState>({ status: "loading" });
  useEffect(() => {
    const controller = new AbortController();
    loadTodaySummary(service, controller.signal)
      .then((summary) => setMetrics({ status: "loaded", summary }))
      .catch(() => { if (!controller.signal.aborted) setMetrics({ status: "unavailable" }); });
    return () => controller.abort();
  }, [service]);
  const value = (pick: (summary: TodaySummary) => number) => metrics.status === "loading" ? "…" : metrics.status === "loaded" ? formatHomeMetric(pick(metrics.summary)) : "—";
  return (
    <section className="work-summary" aria-labelledby="today-summary" aria-live="polite">
      <p id="today-summary">{metrics.status === "unavailable" ? "Activitate indisponibilă" : "Astăzi · activitatea ta"}</p>
      <dl>
        <div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{value((summary) => summary.inProgress)}</dd><dt>În lucru</dt></div>
        <div><dd className={metrics.status === "loaded" ? undefined : "metric-unavailable"}>{value((summary) => summary.handedOver)}</dd><dt>Predate</dt></div>
      </dl>
    </section>
  );
}

/**
 * Department-aware home. It resolves the workspaces the employee is already authorized for (central IAM data from
 * the session) and opens one deterministically; a saved choice is honoured only while still authorized. Each
 * workspace reads its own server-authorized data and links to the existing screens for every action.
 */
export function HomeScreen({ employee, services, workflow, dashboardUrl, navigate }: { employee: Employee; services: ServiceBundle; workflow: ProductionWorkflow; dashboardUrl: string | null; navigate: (path: string) => void }) {
  const workspaces = useMemo(() => resolveWorkspaces(employee, { trendyol: Boolean(services.trendyol), documents: Boolean(services.documents), management: Boolean(services.management) }), [employee, services]);
  const [chosen, setChosen] = useState<string | null>(() => readWorkspacePreference(employee.employeeUuid));
  const current = selectPrimaryWorkspace(workspaces, employee, chosen);

  const select = (workspace: Workspace) => {
    setChosen(workspace.id);
    saveWorkspacePreference(employee.employeeUuid, workspace.id);
  };

  if (!current) return null;
  const scanner = hasPermission(employee, "orders.scan");
  return (
    <div className="dashboard-layout">
      <header className="dashboard-header">
        <div className="greeting">
          <p>{employee.department ? `Departament: ${employee.department}` : "Departament neatribuit"}</p>
          <h1>Bună, {firstName(employee.displayName)}!</h1>
        </div>
        <p className="workspace-title" data-testid="workspace-title"><span>Panou operațional</span><strong>{current.label}</strong></p>
      </header>
      <WorkspaceSwitcher workspaces={workspaces} current={current} onSelect={select} />

      {current.kind === "trendyol" && services.trendyol && <TrendyolDashboard employee={employee} service={services.trendyol} navigate={navigate} />}

      {current.kind === "production" && <>
        <StageWorkspace key={current.id} workspace={current} employee={employee} workflow={workflow} api={services.workspace} cutting={services.cutting} navigate={navigate} />
        <ExceptionsCard service={services.exceptions} navigate={navigate} />
        <TodayWork service={services.activity} />
      </>}

      {current.kind === "documents" && services.documents && <div className="workspace-body" data-testid="workspace-documente">
        <DocumentsCard service={services.documents} navigate={navigate} />
        <DocumentLookup service={services.documents} navigate={navigate} />
      </div>}

      {current.kind === "management" && services.management && <ManagementWorkspace employee={employee} api={services.management} dashboardUrl={dashboardUrl} navigate={navigate} />}

      {current.kind === "general" && <div className="workspace-body" data-testid="workspace-general">
        <section className="dashboard-section" aria-labelledby="general-title">
          <p className="eyebrow">Panoul meu</p>
          <h2 id="general-title">Nu ai încă un spațiu de lucru operațional</h2>
          <p className="dashboard-lead">Contul tău nu are alocată nicio etapă de producție sau alt departament operațional. Managerul îți poate atribui accesul din Dashboard.</p>
          {scanner && <button type="button" className="button button-primary dashboard-primary" onClick={() => navigate("/scan")}><AppIcon name="scan" size={22} /> Scanează comanda</button>}
        </section>
        <ExceptionsCard service={services.exceptions} navigate={navigate} />
        <TodayWork service={services.activity} />
      </div>}

      {current.kind !== "management" && services.authority && canManageAuthority(employee) && (
        <button type="button" className="exception-row" onClick={() => navigate("/authority")}><span><strong>Autoritate producție</strong><small>Preia o comandă din YD SOFT în Arasya, cu etapa aleasă explicit</small></span><span aria-hidden="true">→</span></button>
      )}
    </div>
  );
}
