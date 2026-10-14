import { useCallback, useState } from "react";
import { liveLabels, useI18n } from "../../i18n";
import type { Employee, ProductionWorkflow, StaffOrder } from "../../domain/models";
import { stageGuides, stageSources, type StageQueue, type StageSummary, type Workspace, type WorkspaceApi } from "../../domain/workspaces";
import { getStageById } from "../../domain/productionWorkflow";
import { orderActionBlockedCopy } from "../../domain/orderActions";
import { hasPermission } from "../../domain/permissions";
import type { CuttingApi } from "../../domain/cutting";
import { useLive } from "../../app/liveContext";
import { SourceBadge, sourceLabels } from "../../components/SourceBadge";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { CuttingWork } from "../cutting/CuttingWork";
import { DashboardSection, EmptyState, Metric, MetricGrid, NextActionCard, NextActionSkeleton, QueueSkeleton, RefreshButton, type MetricValue } from "./DashboardParts";
import { orderFacts, stageNextAction, stageProfile, type StageProfile } from "./stageProfiles";
import { useDashboardData } from "./useDashboardData";
import { pieceName, trackPieces, trackStatusLabel } from "../../domain/pieces";

const CUTTING_STAGE = "material-preparation";

type QueueState = "mine" | "available" | "claimedByOthers" | "blocked";

/** Client copy of the server bucket, derived only from the server-evaluated action of each order. */
export function queueStateOf(order: StaffOrder): QueueState {
  const action = order.employeeAllowedAction?.id;
  if (action === "complete_stage" || action === "complete_production") return "mine";
  if (action === "claim") return "available";
  if (order.employeeActionBlockedReason === "claimed_by_other") return "claimedByOthers";
  return "blocked";
}

const queueStateLabels: Readonly<Record<QueueState, string>> = liveLabels("stageWs.queue.", ["mine", "available", "claimedByOthers", "blocked"]);

function QueueRow({ order, detail, navigate }: { order: StaffOrder; detail: StageProfile["detail"]; navigate: (path: string) => void }) {
  const state = queueStateOf(order);
  const facts = orderFacts(order, detail);
  const reason = state === "blocked" && order.employeeActionBlockedReason ? orderActionBlockedCopy[order.employeeActionBlockedReason] : null;
  return (
    <li>
      <button type="button" className={`queue-row queue-${state}`} onClick={() => navigate(`/orders/${encodeURIComponent(order.id)}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">#{order.orderNumber}</strong><SourceBadge source={order.source} /></span>
          <small>{facts.primary}</small>
          {facts.chips.length > 0 && <span className="fact-chips">{facts.chips.map((chip, index) => <span key={`${chip}-${index}`} className="fact-chip">{chip}</span>)}</span>}
          {reason && <small className="queue-row-reason">{reason}</small>}
        </span>
        <span className="queue-chip">{queueStateLabels[state]}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

/** A piece in rework at this stage (API 2.31.0): its own work item, opened on the order screen; never claimed here. */
function TrackRow({ entry, navigate }: { entry: StageQueue["trackItems"][number]; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const track = entry.order.pieces?.tracks.find((item) => item.id === entry.trackId);
  if (!track || !entry.order.pieces) return null;
  const state = track.owner?.isMe ? "mine" : track.allowedAction?.id === "claim" ? "available" : track.blockedReason === "claimed_by_other" ? "claimedByOthers" : "blocked";
  return (
    <li>
      <button type="button" className={`queue-row queue-${state}`} data-testid="rework-row" onClick={() => navigate(`/orders/${encodeURIComponent(entry.order.id)}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">#{entry.order.orderNumber}</strong><SourceBadge source={entry.order.source} /></span>
          <small>{trackPieces(entry.order.pieces, track).map(pieceName).join(", ")} · {trackStatusLabel(track)}</small>
          {track.owner && <small>{t("piece.owner")}: {track.owner.displayName}</small>}
        </span>
        <span className="queue-chip">{queueStateLabels[state]}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

/**
 * One production workspace: one or several of the employee's canonical stages. Every number comes from the
 * server-authorized stage queue; opening an order never claims it (the order screen keeps the explicit actions).
 */
export function StageWorkspace({ workspace, employee, workflow, api, cutting, navigate }: { workspace: Workspace; employee: Employee; workflow: ProductionWorkflow; api?: WorkspaceApi; cutting?: CuttingApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const { revision } = useLive();
  const [selected, setSelected] = useState(workspace.stageIds[0] ?? "");
  const stageId = workspace.stageIds.includes(selected) ? selected : workspace.stageIds[0] ?? "";
  const stage = getStageById(workflow, stageId);
  const loadQueue = useCallback((signal: AbortSignal): Promise<StageQueue> => api!.stageQueue(stageId, signal), [api, stageId]);
  const loadSummary = useCallback((signal: AbortSignal): Promise<StageSummary> => api!.stageSummary(signal), [api]);
  const queue = useDashboardData(api ? loadQueue : null, `${workspace.id}/${stageId}`, revision);
  const summary = useDashboardData(api && workspace.stageIds.length > 1 ? loadSummary : null, workspace.id, revision);
  const guide = stageGuides[stageId];
  const scopedSources = stageSources(employee, stageId);
  const counts = queue.data?.counts;
  const metric = (value: number | undefined): MetricValue => !api || (queue.error && !queue.data) ? "unavailable" : value ?? "loading";
  const partial = queue.data ? !queue.data.countsComplete : false;
  const cuttingTools = stageId === CUTTING_STAGE && Boolean(cutting) && !employee.isRoot;
  const items = queue.data?.items.filter((order) => !cuttingTools || queueStateOf(order) !== "available") ?? [];
  const totals = new Map(summary.data?.map((entry) => [entry.stageId, entry.total]) ?? []);

  const profile = stageProfile(workspace.id);
  const canScan = hasPermission(employee, "orders.scan");
  const next = api && queue.data ? stageNextAction(queue.data, profile, canScan) : null;

  return (
    <div className="workspace-body split-workspace" data-testid={`workspace-${workspace.id}`}>
      {workspace.stageIds.length > 1 && (
        <div className="stage-tabs" role="tablist" aria-label={t("stageWs.myStages")}>
          {workspace.stageIds.map((id) => (
            <button key={id} type="button" role="tab" aria-selected={id === stageId} onClick={() => setSelected(id)}>
              {getStageById(workflow, id)?.label ?? t("common.stage")}
              {totals.has(id) && <span>{totals.get(id)}</span>}
            </button>
          ))}
        </div>
      )}

      <div className="workspace-main">
        {next ? <NextActionCard action={next} navigate={navigate} id={`next-${stageId}`} testId="stage-next-action"
          secondary={canScan && next.cta?.path !== "/scan" ? <button type="button" className="button button-secondary next-action-cta" onClick={() => navigate("/scan")}><AppIcon name="scan" size={20} /> {t("common.scanOrder")}</button> : undefined} />
          : api && !queue.error ? <NextActionSkeleton /> : null}

        {api && queue.data && (
          <DashboardSection labelledBy={`queue-${stageId}`} eyebrow={stage?.label} title={cuttingTools ? t("stageWs.mineAndBlocked") : t("stageWs.atStage")}>
            {items.length === 0
              ? <EmptyState title={cuttingTools ? t("stageWs.emptyCutting") : t("stageWs.empty")}>{t("stageWs.autoUpdate")}</EmptyState>
              : <ul className="queue-list">{items.map((order) => <QueueRow key={order.id} order={order} detail={profile.detail} navigate={navigate} />)}</ul>}
            {!cuttingTools && queue.data.counts.total > queue.data.items.length && <p className="dashboard-footnote">{t("stageWs.firstShown", { count: queue.data.items.length })}</p>}
          </DashboardSection>
        )}
        {api && queue.data && queue.data.trackItems.length > 0 && (
          <DashboardSection labelledBy={`rework-${stageId}`} eyebrow={stage?.label} title={t("ws.rework.title")}>
            <ul className="queue-list" data-testid="rework-queue">{queue.data.trackItems.map((entry) => <TrackRow key={entry.trackId} entry={entry} navigate={navigate} />)}</ul>
          </DashboardSection>
        )}
        {cuttingTools && cutting && <CuttingWork service={cutting} navigate={navigate} />}
        {api && !queue.data && !queue.error && <DashboardSection labelledBy={`queue-loading-${stageId}`} eyebrow={stage?.label} title={t("stageWs.atStage")}><QueueSkeleton /></DashboardSection>}
      </div>

      <div className="workspace-side">
        <DashboardSection labelledBy={`stage-${stageId}`} eyebrow={stage ? t("stageWs.stageOf", { ordinal: stage.ordinal, total: workflow.stages.length }) : t("common.stage")} title={stage?.label ?? t("common.stageUnavailable")}
          action={api ? <RefreshButton onRefresh={() => { queue.refresh(); summary.refresh(); }} busy={queue.busy} updatedAt={queue.updatedAt} /> : undefined}>
          <span className="stage-icon" aria-hidden="true"><AppIcon name={profile.icon} size={22} /></span>
          {guide && <p className="dashboard-lead">{guide.focus}</p>}
          {scopedSources && <p className="order-notice order-notice-muted" data-testid="stage-source-scope">{t("stageWs.sourceScope", { sources: scopedSources.map((source) => sourceLabels[source as keyof typeof sourceLabels] ?? source).join(", ") })}</p>}
          <MetricGrid label={t("stageWs.metrics", { stage: stage?.label ?? "" })}>
            <Metric label={t("stageWs.metric.mine")} value={metric(counts?.mine)} tone="action" partial={partial} testId="metric-mine" />
            <Metric label={t("stageWs.metric.available")} value={metric(counts?.available)} partial={partial} testId="metric-available" />
            <Metric label={t("stageWs.metric.blocked")} value={metric(counts?.blocked)} tone="warning" partial={partial} testId="metric-blocked" />
            <Metric label={t("stageWs.metric.others")} value={metric(counts?.claimedByOthers)} partial={partial} testId="metric-others" />
            <Metric label={t("stageWs.metric.total")} value={metric(counts?.total)} testId="metric-total" />
          </MetricGrid>
          {!api && <p className="order-notice order-notice-muted">{t("stageWs.noQueue")}</p>}
          {queue.error && <ErrorState error={queue.error} compact onAction={queue.refresh} />}
          {canScan && !next && <button type="button" className="button button-primary dashboard-primary" onClick={() => navigate("/scan")}><AppIcon name="scan" size={22} /> {t("common.scanOrder")}</button>}
        </DashboardSection>

        {guide && (
          <DashboardSection labelledBy={`guide-${stageId}`} eyebrow={t("stageWs.guideEyebrow")} title={t("stageWs.guideTitle")}>
            <ul className="dashboard-checklist">{guide.checks.map((check) => <li key={check}>{check}</li>)}</ul>
          </DashboardSection>
        )}
      </div>
    </div>
  );
}
