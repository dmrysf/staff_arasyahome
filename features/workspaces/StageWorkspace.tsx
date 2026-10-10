import { useCallback, useState } from "react";
import type { Employee, ProductionWorkflow, StaffOrder } from "../../domain/models";
import { stageGuides, stageSources, type StageQueue, type StageSummary, type Workspace, type WorkspaceApi } from "../../domain/workspaces";
import { getStageById } from "../../domain/productionWorkflow";
import { orderActionBlockedCopy } from "../../domain/orderActions";
import { getUsableProductionProducts } from "../../domain/orderValidation";
import { hasPermission } from "../../domain/permissions";
import type { CuttingApi } from "../../domain/cutting";
import { useLive } from "../../app/liveContext";
import { SourceBadge, sourceLabels } from "../../components/SourceBadge";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { CuttingWork } from "../cutting/CuttingWork";
import { DashboardSection, EmptyState, Metric, MetricGrid, RefreshButton, type MetricValue } from "./DashboardParts";
import { useDashboardData } from "./useDashboardData";

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

const queueStateLabels: Record<QueueState, string> = { mine: "La tine", available: "Disponibilă", claimedByOthers: "Preluată de un coleg", blocked: "Blocată" };

function QueueRow({ order, navigate }: { order: StaffOrder; navigate: (path: string) => void }) {
  const state = queueStateOf(order);
  const products = getUsableProductionProducts(order);
  const first = products.at(0);
  const reason = state === "blocked" && order.employeeActionBlockedReason ? orderActionBlockedCopy[order.employeeActionBlockedReason] : null;
  return (
    <li>
      <button type="button" className={`queue-row queue-${state}`} onClick={() => navigate(`/orders/${encodeURIComponent(order.id)}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong>#{order.orderNumber}</strong><SourceBadge source={order.source} /></span>
          <small>{first ? `${first.name}${first.code ? ` · ${first.code}` : ""}${products.length > 1 ? ` +${products.length - 1}` : ""}` : "Produse indisponibile"}</small>
          {reason && <small className="queue-row-reason">{reason}</small>}
        </span>
        <span className="queue-chip">{queueStateLabels[state]}</span>
        <AppIcon name="arrow" size={18} />
      </button>
    </li>
  );
}

/**
 * One production workspace: one or several of the employee's canonical stages. Every number comes from the
 * server-authorized stage queue; opening an order never claims it (the order screen keeps the explicit actions).
 */
export function StageWorkspace({ workspace, employee, workflow, api, cutting, navigate }: { workspace: Workspace; employee: Employee; workflow: ProductionWorkflow; api?: WorkspaceApi; cutting?: CuttingApi; navigate: (path: string) => void }) {
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

  return (
    <div className="workspace-body" data-testid={`workspace-${workspace.id}`}>
      {workspace.stageIds.length > 1 && (
        <div className="stage-tabs" role="tablist" aria-label="Etapele mele">
          {workspace.stageIds.map((id) => (
            <button key={id} type="button" role="tab" aria-selected={id === stageId} onClick={() => setSelected(id)}>
              {getStageById(workflow, id)?.label ?? "Etapă"}
              {totals.has(id) && <span>{totals.get(id)}</span>}
            </button>
          ))}
        </div>
      )}

      <DashboardSection labelledBy={`stage-${stageId}`} eyebrow={stage ? `Etapa ${stage.ordinal} din ${workflow.stages.length}` : "Etapă"} title={stage?.label ?? "Etapă indisponibilă"}
        action={api ? <RefreshButton onRefresh={() => { queue.refresh(); summary.refresh(); }} busy={queue.busy} updatedAt={queue.updatedAt} /> : undefined}>
        {guide && <p className="dashboard-lead">{guide.focus}</p>}
        {scopedSources && <p className="order-notice order-notice-muted" data-testid="stage-source-scope">Lucrezi la această etapă doar pe comenzile din: {scopedSources.map((source) => sourceLabels[source as keyof typeof sourceLabels] ?? source).join(", ")}.</p>}
        <MetricGrid label={`Situația etapei ${stage?.label ?? ""}`}>
          <Metric label="În lucru la mine" value={metric(counts?.mine)} tone="action" partial={partial} testId="metric-mine" />
          <Metric label="Disponibile" value={metric(counts?.available)} partial={partial} testId="metric-available" />
          <Metric label="Blocate" value={metric(counts?.blocked)} tone="warning" partial={partial} testId="metric-blocked" />
          <Metric label="Preluate de colegi" value={metric(counts?.claimedByOthers)} partial={partial} testId="metric-others" />
          <Metric label="Total la etapă" value={metric(counts?.total)} testId="metric-total" />
        </MetricGrid>
        {!api && <p className="order-notice order-notice-muted">Coada etapei nu este disponibilă în acest mod. Comenzile tale rămân în „Comenzi”.</p>}
        {queue.error && <ErrorState error={queue.error} compact onAction={queue.refresh} />}
        {hasPermission(employee, "orders.scan") && <button type="button" className="button button-primary dashboard-primary" onClick={() => navigate("/scan")}><AppIcon name="scan" size={22} /> Scanează comanda</button>}
      </DashboardSection>

      {cuttingTools && cutting && <CuttingWork service={cutting} navigate={navigate} />}

      {api && queue.data && (
        <DashboardSection labelledBy={`queue-${stageId}`} eyebrow={stage?.label} title={cuttingTools ? "La tine și blocate" : "Comenzi la etapă"}>
          {items.length === 0
            ? <EmptyState title={cuttingTools ? "Nu ai comenzi în lucru la tăiere" : "Nu există comenzi la această etapă"}>Lista se actualizează automat când o comandă ajunge aici.</EmptyState>
            : <ul className="queue-list">{items.map((order) => <QueueRow key={order.id} order={order} navigate={navigate} />)}</ul>}
          {!cuttingTools && queue.data.counts.total > queue.data.items.length && <p className="dashboard-footnote">Sunt afișate primele {queue.data.items.length} comenzi: întâi ale tale, apoi cele disponibile, de la cea mai veche.</p>}
        </DashboardSection>
      )}

      {guide && (
        <DashboardSection labelledBy={`guide-${stageId}`} eyebrow="Instrucțiuni" title="De verificat la această etapă">
          <ul className="dashboard-checklist">{guide.checks.map((check) => <li key={check}>{check}</li>)}</ul>
        </DashboardSection>
      )}
    </div>
  );
}
