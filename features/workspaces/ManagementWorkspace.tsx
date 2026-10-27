import { useCallback } from "react";
import { formatDate, useI18n } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import type { Employee } from "../../domain/models";
import type { ManagementApi, ProductionOverview } from "../../domain/workspaces";
import { canManageAuthority } from "../../domain/authority";
import { useLive } from "../../app/liveContext";
import { ErrorState } from "../../components/ErrorState";
import { DashboardSection, Metric, MetricGrid, RefreshButton, type MetricValue } from "./DashboardParts";
import { useDashboardData } from "./useDashboardData";
import { useGuide } from "../guide/ContextualHelp";
import { guideChrome, managementTerms } from "../../i18n/messages/guide";

const since = { format: (value: Date): string => formatDate(value, { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) };

/**
 * Read-only production overview for employees with Dashboard access and `production.view` (the server checks both).
 * Stage-level aggregates only: no employee activity and no customer data. Management functions stay in Dashboard.
 */
export function ManagementWorkspace({ employee, api, dashboardUrl, navigate }: { employee: Employee; api: ManagementApi; dashboardUrl: string | null; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const { revision } = useLive();
  // Owner 2026-10-26: the numbers are the new system's; older WordPress-managed shop orders are only counted apart.
  const load = useCallback((signal: AbortSignal): Promise<ProductionOverview> => api.productionOverview(signal, "current"), [api]);
  const overview = useDashboardData(load, "management", revision);
  const data = overview.data;
  const metric = (value: number | undefined): MetricValue => data ? value ?? "unavailable" : overview.error ? "unavailable" : "loading";
  // What each number counts, in the guide language (the server's definitions, ProductionOverviewService).
  const guide = useGuide();
  const hint = (key: keyof typeof managementTerms) => guide ? { hint: managementTerms[key][guide.lang], hintLang: guide.lang } : {};

  return (
    <div className="workspace-body" data-testid="workspace-management">
      <DashboardSection labelledBy="management-summary" eyebrow={t("mgmt.eyebrow")} title={t("mgmt.title")}
        action={<RefreshButton onRefresh={overview.refresh} busy={overview.busy} updatedAt={overview.updatedAt} />}>
        <MetricGrid label={t("mgmt.metrics")}>
          <Metric label={t("mgmt.active")} value={metric(data?.summary.active)} {...hint("active")} />
          <Metric label={t("mgmt.waiting")} value={metric(data?.summary.waiting)} tone="warning" {...hint("waiting")} />
          <Metric label={t("mgmt.inWork")} value={metric(data?.summary.inWork)} tone="action" {...hint("inWork")} />
          <Metric label={t("mgmt.unassigned")} value={metric(data?.summary.unassigned)} {...hint("unassigned")} />
          <Metric label={t("mgmt.completedToday")} value={metric(data?.summary.completedToday)} tone="success" {...hint("completedToday")} />
        </MetricGrid>
        {data?.legacy && data.legacy.active > 0 && <p className="dashboard-footnote" data-testid="mgmt-legacy">{t("mgmt.legacyNote", { count: data.legacy.active, waiting: data.legacy.waiting })}</p>}
        {overview.error && <ErrorState error={overview.error} compact onAction={overview.refresh} />}
        <div className="dashboard-actions">
          {dashboardUrl && <a className="button button-secondary" href={dashboardUrl} target="_blank" rel="noreferrer">{t("mgmt.openDashboard")}</a>}
          {canManageAuthority(employee) && <button type="button" className="button button-secondary" onClick={() => navigate("/authority")}>{t("mgmt.authority")}</button>}
          {guide?.guide && <button type="button" className="button button-ghost" onClick={guide.open} aria-haspopup="dialog" lang={guide.lang} data-testid="mgmt-guide">{guideChrome.metricsHelp[guide.lang]}</button>}
        </div>
      </DashboardSection>

      {data && (
        <DashboardSection labelledBy="management-stages" eyebrow={t("mgmt.stagesEyebrow")} title={t("mgmt.stagesTitle")}>
          <table className="stage-table">
            <thead><tr><th scope="col">{t("mgmt.col.stage")}</th><th scope="col">{t("mgmt.col.active")}</th><th scope="col">{t("mgmt.col.unassigned")}</th><th scope="col">{t("mgmt.col.oldest")}</th></tr></thead>
            <tbody>
              {data.stages.map((stage) => (
                <tr key={stage.id} className={stage.active === 0 ? "is-empty" : undefined}>
                  <th scope="row"><span className="stage-ordinal">{stage.ordinal}</span>{stageName(stage.id, stage.label)}</th>
                  <td>{stage.active}</td>
                  <td>{stage.unassigned}</td>
                  <td>{stage.oldestEnteredAt ? since.format(new Date(stage.oldestEnteredAt)) : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="dashboard-footnote">{t("mgmt.footnote")}</p>
        </DashboardSection>
      )}
    </div>
  );
}
