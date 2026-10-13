import { useMemo } from "react";
import { formatDate as formatDateIn, liveRecords, useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { activityActionLabels, intakeLabel, marketplaceClassLabels, marketplaceLabel, type TrendyolApi, type TrendyolOverview, type TrendyolPackageSummary } from "../../domain/trendyol";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { TrendyolLogo } from "../../components/brand/BrandLogo";
import { trendyolDashboardLoader } from "../trendyol/trendyolRefresh";
import { AutoRefreshBar, DashboardSection, EmptyState, Metric, MetricGrid, NextActionCard, NextActionSkeleton, QueueSkeleton, type MetricValue } from "./DashboardParts";
import { trendyolNextAction } from "./trendyolNextAction";
import { useAutoRefresh } from "./useAutoRefresh";

const LIST_LIMIT = 6;
const formatDate = (value: string | null): string => value ? formatDateIn(value, { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) : "—";

/** Lines still missing confirmed production data across the listed pending packages (the API lists up to 200). */
export function linesToComplete(pending: readonly TrendyolPackageSummary[]): number {
  return pending.reduce((sum, item) => sum + Math.max(0, (item.lineCount ?? 0) - (item.preparedCount ?? 0)), 0);
}

const connectionWords = liveRecords("ty.dash.connection.", ["inactive", "active", "paused"], ["label", "detail"]);
const connectionTones = { inactive: "neutral", active: "success", paused: "warning" } as const;
export const connectionCopy: Readonly<Record<TrendyolOverview["intake"]["status"], { readonly label: string; readonly detail: string; readonly tone: "neutral" | "success" | "warning" }>> = Object.freeze({
  inactive: { get label() { return connectionWords.inactive.label; }, get detail() { return connectionWords.inactive.detail; }, tone: connectionTones.inactive },
  active: { get label() { return connectionWords.active.label; }, get detail() { return connectionWords.active.detail; }, tone: connectionTones.active },
  paused: { get label() { return connectionWords.paused.label; }, get detail() { return connectionWords.paused.detail; }, tone: connectionTones.paused },
});

/** Synchronization outcomes: `ok` and `truncated` (continues on the next run) are progress; anything else failed. */
export function lastRunFailed(outcome: string | null): boolean {
  return outcome !== null && outcome !== "ok" && outcome !== "truncated";
}

export function PackageRow({ item, view, navigate }: { item: TrendyolPackageSummary; view: "pending" | "attention" | "released" | "closed"; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const missing = Math.max(0, (item.lineCount ?? 0) - (item.preparedCount ?? 0));
  const status = view === "attention"
    ? (item.marketplaceStatusKnown ? marketplaceClassLabels[item.marketplaceClass] : t("ty.dash.unknownStatus"))
    : view === "pending" && item.lineCount !== undefined
      ? (missing === 0 ? t("ty.dash.readyToApprove") : t("ty.dash.linesPrepared", { prepared: item.preparedCount ?? 0, total: item.lineCount }))
      : intakeLabel(item);
  const progress = view === "pending" && item.lineCount ? (item.preparedCount ?? 0) / item.lineCount : null;
  return (
    <li>
      <button type="button" className={`queue-row ${view === "pending" ? (missing === 0 ? "queue-available" : "queue-mine") : view === "attention" ? "queue-blocked" : "queue-claimedByOthers"}`} onClick={() => navigate(`/trendyol/${item.packageId}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">{t("common.order", { number: item.orderNumber })}</strong></span>
          <small>{t("ty.inbox.packageLine", { id: item.packageId, date: formatDate(item.orderDate), status: marketplaceLabel(item.marketplaceStatus) })}</small>
          {item.changedAfterRelease && <small className="queue-row-reason">{t("ty.dash.changedAfterRelease")}</small>}
          {item.orderDateNearActivation && item.intakeStatus === "pending" && <small className="queue-row-reason">{t("ty.dash.nearActivation")}</small>}
          {progress !== null && <span className="line-progress" aria-hidden="true"><span style={{ transform: `scaleX(${progress})` }} /></span>}
        </span>
        <span className="queue-chip">{status}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

/**
 * Trendyol operations dashboard for explicitly authorized Trendyol personnel. It only reads the Operations API
 * (never Trendyol itself) and reuses the existing package screens for preparation and approval. It refreshes every
 * minute while visible (overview only unless something changed); a failed refresh keeps the last good numbers.
 * Phones: the next step first, then the numbers and the lists. Desktop: work on the left, status on the right.
 */
export function TrendyolDashboard({ employee, service, navigate }: { employee: Employee; service: TrendyolApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => trendyolDashboardLoader(service), [service]);
  const dashboard = useAutoRefresh(load, "trendyol");
  const data = dashboard.data;
  const metric = (value: number | undefined): MetricValue => data ? value ?? "unavailable" : dashboard.error ? "unavailable" : "loading";
  const pendingPartial = data ? data.overview.counts.pending > data.pending.length : false;
  const connection = data ? connectionCopy[data.overview.intake.status] : null;
  const capabilities = data?.overview.capabilities;
  const next = data ? trendyolNextAction(data) : null;
  const loading = !data && !dashboard.error;

  return (
    <div className="workspace-body trendyol-workspace" data-testid="workspace-trendyol">
      <div className="workspace-main">
        {next ? <NextActionCard action={next} navigate={navigate} id="trendyol-next" testId="trendyol-next-action" /> : loading ? <NextActionSkeleton /> : null}

        {loading && <DashboardSection labelledBy="trendyol-pending-loading" eyebrow={t("ty.dash.pendingEyebrow")} title={t("ty.dash.pendingTitle")}><QueueSkeleton /></DashboardSection>}
        {data && (
          <DashboardSection labelledBy="trendyol-pending" eyebrow={t("ty.dash.pendingEyebrow")} title={t("ty.dash.pendingTitle")} action={data.pending.length > LIST_LIMIT ? <button type="button" className="button button-link" onClick={() => navigate("/trendyol")}>{t("ty.dash.viewAll")}</button> : undefined}>
            {data.pending.length === 0
              ? <EmptyState title={t("ty.dash.noPending")}>{data.overview.intake.status === "inactive" ? t("ty.dash.notActivated") : t("ty.dash.appearAutomatically")}</EmptyState>
              : <ul className="queue-list">{data.pending.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="pending" navigate={navigate} />)}</ul>}
            {capabilities && !capabilities.prepare && <p className="dashboard-footnote">{t("ty.dash.viewOnly")}</p>}
            {capabilities && capabilities.prepare && !capabilities.release && <p className="dashboard-footnote">{t("ty.dash.prepareOnly")}</p>}
          </DashboardSection>
        )}

        {data && data.attention.length > 0 && (
          <DashboardSection labelledBy="trendyol-attention" eyebrow={t("ty.dash.attentionEyebrow")} title={t("ty.dash.attentionTitle")}>
            <ul className="queue-list">{data.attention.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="attention" navigate={navigate} />)}</ul>
            <p className="dashboard-footnote">{t("ty.dash.attentionNote")}</p>
          </DashboardSection>
        )}

        {data && data.released.length > 0 && (
          <DashboardSection labelledBy="trendyol-released" eyebrow={t("ty.dash.releasedEyebrow")} title={t("ty.dash.releasedTitle")}>
            <ul className="queue-list">{data.released.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="released" navigate={navigate} />)}</ul>
          </DashboardSection>
        )}
      </div>

      <div className="workspace-side">
        <DashboardSection labelledBy="trendyol-today" eyebrow={t("ty.dash.todayEyebrow")} title={t("ty.dash.todayTitle")}
          action={<AutoRefreshBar onRefresh={dashboard.refresh} busy={dashboard.busy} refreshing={dashboard.refreshing} updatedAt={dashboard.updatedAt} stale={data !== null && dashboard.error !== null} stopped={dashboard.stopped} testId="trendyol-refresh" />}>
          <MetricGrid label={t("ty.dash.metrics")}>
            <Metric label={t("ty.dash.metric.pending")} value={metric(data?.overview.counts.pending)} tone="action" testId="metric-trendyol-pending" />
            <Metric label={t("ty.dash.metric.lines")} value={metric(data ? linesToComplete(data.pending) : undefined)} partial={pendingPartial} testId="metric-trendyol-lines" />
            <Metric label={t("ty.dash.metric.attention")} value={metric(data?.overview.counts.attention)} tone="warning" testId="metric-trendyol-attention" />
            <Metric label={t("ty.dash.metric.released")} value={metric(data?.overview.counts.released)} tone="success" testId="metric-trendyol-released" />
          </MetricGrid>
          {dashboard.error && (!data || dashboard.stopped) && <ErrorState error={dashboard.error} compact onAction={dashboard.refresh} />}
          <div className="dashboard-actions">
            <button type="button" className="button button-secondary" data-testid="home-trendyol" onClick={() => navigate("/trendyol")}><AppIcon name="orders" size={20} />{t("ty.dash.allOrders")}</button>
          </div>
        </DashboardSection>

        {connection && data && (
          <section className={`connection-card tone-${connection.tone}`} aria-labelledby="trendyol-connection" data-testid="trendyol-connection">
            <span className="connection-logo" aria-hidden="true"><TrendyolLogo alt="" /></span>
            <div>
              <p className="eyebrow">{t("ty.dash.connectionEyebrow")}</p>
              <h2 id="trendyol-connection">{connection.label}</h2>
              <p>{connection.detail}</p>
              {data.overview.intake.lastRunAt && <p data-testid="trendyol-last-sync">{t("ty.dash.lastSync", { date: formatDate(data.overview.intake.lastRunAt) })}{lastRunFailed(data.overview.intake.lastRunOutcome) ? t("ty.dash.lastSyncFailed") : ""}</p>}
            </div>
          </section>
        )}

        {data && (
          <DashboardSection labelledBy="trendyol-mine" eyebrow={employee.displayName} title={t("ty.dash.mineTitle")}>
            {data.activity === null
              ? <p className="order-notice order-notice-muted">{t("ty.dash.mineUnavailable")}</p>
              : <>
                <MetricGrid label={t("ty.dash.mineMetrics")}>
                  <Metric label={t("ty.dash.preparedToday")} value={data.activity.today.linesPrepared} />
                  <Metric label={t("ty.dash.approvedToday")} value={data.activity.today.released} />
                </MetricGrid>
                {data.activity.recent.length === 0
                  ? <EmptyState title={t("ty.dash.noActions")} />
                  : <ul className="activity-list">{data.activity.recent.map((event, index) => <li key={`${event.packageId}-${event.at}-${index}`}>
                    <span><strong>{activityActionLabels[event.action]}</strong>{event.orderNumber && <small className="order-id">{t("common.order", { number: event.orderNumber })}</small>}</span>
                    <time dateTime={event.at ?? undefined}>{formatDate(event.at)}</time>
                  </li>)}</ul>}
              </>}
          </DashboardSection>
        )}
      </div>
    </div>
  );
}
