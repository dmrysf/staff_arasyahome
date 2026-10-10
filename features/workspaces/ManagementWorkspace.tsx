import { useCallback } from "react";
import type { Employee } from "../../domain/models";
import type { ManagementApi, ProductionOverview } from "../../domain/workspaces";
import { canManageAuthority } from "../../domain/authority";
import { useLive } from "../../app/liveContext";
import { ErrorState } from "../../components/ErrorState";
import { DashboardSection, Metric, MetricGrid, RefreshButton, type MetricValue } from "./DashboardParts";
import { useDashboardData } from "./useDashboardData";

const since = new Intl.DateTimeFormat("ro-RO", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" });

/**
 * Read-only production overview for employees with Dashboard access and `production.view` (the server checks both).
 * Stage-level aggregates only: no employee activity and no customer data. Management functions stay in Dashboard.
 */
export function ManagementWorkspace({ employee, api, dashboardUrl, navigate }: { employee: Employee; api: ManagementApi; dashboardUrl: string | null; navigate: (path: string) => void }) {
  const { revision } = useLive();
  const load = useCallback((signal: AbortSignal): Promise<ProductionOverview> => api.productionOverview(signal), [api]);
  const overview = useDashboardData(load, "management", revision);
  const data = overview.data;
  const metric = (value: number | undefined): MetricValue => data ? value ?? "unavailable" : overview.error ? "unavailable" : "loading";

  return (
    <div className="workspace-body" data-testid="workspace-management">
      <DashboardSection labelledBy="management-summary" eyebrow="Producție · numai citire" title="Privire de ansamblu"
        action={<RefreshButton onRefresh={overview.refresh} busy={overview.busy} updatedAt={overview.updatedAt} />}>
        <MetricGrid label="Situația producției">
          <Metric label="Comenzi active" value={metric(data?.summary.active)} />
          <Metric label="În așteptare" value={metric(data?.summary.waiting)} tone="warning" />
          <Metric label="În lucru" value={metric(data?.summary.inWork)} tone="action" />
          <Metric label="Nepreluate" value={metric(data?.summary.unassigned)} />
          <Metric label="Finalizate azi" value={metric(data?.summary.completedToday)} tone="success" />
        </MetricGrid>
        {overview.error && <ErrorState error={overview.error} compact onAction={overview.refresh} />}
        <div className="dashboard-actions">
          {dashboardUrl && <a className="button button-secondary" href={dashboardUrl} target="_blank" rel="noreferrer">Deschide Dashboard</a>}
          {canManageAuthority(employee) && <button type="button" className="button button-secondary" onClick={() => navigate("/authority")}>Autoritate producție</button>}
        </div>
      </DashboardSection>

      {data && (
        <DashboardSection labelledBy="management-stages" eyebrow="Fluxul de producție · 14 etape" title="Comenzi pe etape">
          <table className="stage-table">
            <thead><tr><th scope="col">Etapă</th><th scope="col">Active</th><th scope="col">Nepreluate</th><th scope="col">Cea mai veche</th></tr></thead>
            <tbody>
              {data.stages.map((stage) => (
                <tr key={stage.id} className={stage.active === 0 ? "is-empty" : undefined}>
                  <th scope="row"><span className="stage-ordinal">{stage.ordinal}</span>{stage.label}</th>
                  <td>{stage.active}</td>
                  <td>{stage.unassigned}</td>
                  <td>{stage.oldestEnteredAt ? since.format(new Date(stage.oldestEnteredAt)) : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="dashboard-footnote">Detaliile pe comenzi, angajați și excepții rămân în aplicația Dashboard.</p>
        </DashboardSection>
      )}
    </div>
  );
}
