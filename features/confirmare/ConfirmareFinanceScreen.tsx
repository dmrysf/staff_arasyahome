import { useMemo, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { confirmareScopesOf } from "../../domain/confirmare";
import { FINANCE_TASKS, attentionLabel, financeScopesOf, taskLabel, type FinanceQueue, type FinanceQueueItem, type FinanceTask } from "../../domain/confirmareFinance";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { SourceFilter, confirmareOrderPath, dueDay, when } from "./ConfirmareParts";
import { TransferExceptionsSection } from "./ConfirmareTransferExceptions";

/** Tasks that need a person now; the others are informative. */
/** The advance groups (owner workflow): waiting, partial, paid, routed to the shop. */
const ADVANCE: readonly FinanceTask[] = ["advance_waiting", "advance_partial", "advance_verified", "advance_routed"];
const URGENT: readonly FinanceTask[] = ["reconciliation_required", "source_unavailable", "refund_processing", "transfer_verification", "refund_eligible", "refund_liability", "additional_payment"];

/**
 * The finance tasks of the employee's sources (API 2.41.0): which Confirmare orders need a money decision, derived by the
 * server from the money state the shop sends with every order event (classes only; the amounts are read per order, in
 * the order's finance panel). It refreshes every minute while visible and never acts by itself.
 */
export function ConfirmareFinanceScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const scopes = financeScopesOf(employee);
  const operator = confirmareScopesOf(employee);
  // API 2.44.0: a Confirmare operator sees the advance tasks of their shops (read only).
  const financeRole = scopes.view.length + scopes.record.length + scopes.approve.length > 0;
  const sources = (["trendhome", "outletperdele"] as const).filter((source) => scopes.view.includes(source) || scopes.record.includes(source) || scopes.approve.includes(source)
    || operator.operate.includes(source) || operator.supervise.includes(source)) as ConfirmareSource[];
  const [task, setTask] = useState<FinanceTask | null>(null);
  const [source, setSource] = useState<string | null>(null);
  const load = useMemo(() => (signal: AbortSignal): Promise<FinanceQueue & { source: string | null }> => service.financeQueue(null, source, signal).then((queue) => ({ ...queue, source })), [service, source]);
  const queue = useAutoRefresh(load, `confirmare-finance|${source ?? "all"}`);
  const data = queue.data?.source === source ? queue.data : null;
  const rows = data ? data.orders.filter((order) => task === null || order.task === task) : null;
  const message = queue.error && (!data || queue.stopped) ? getErrorPresentation(queue.error).message : "";
  const visible = FINANCE_TASKS.filter((id) => (financeRole || ADVANCE.includes(id)) && ((data?.tasks[id] ?? 0) > 0 || (financeRole && URGENT.includes(id)) || ADVANCE.includes(id)));

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-finance-queue">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.fin.queueTitle")} description={t("cf.fin.queueDescription")} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={data !== null && queue.error !== null} stopped={queue.stopped} testId="cf-finance-refresh" />
      <SourceFilter sources={sources} value={source} onChange={setSource} />
      <div className="segmented cf-tabs" role="tablist" aria-label={t("cf.fin.tasks")}>
        <button type="button" role="tab" aria-selected={task === null} onClick={() => setTask(null)}>{t("cf.fin.allTasks")}{data && <span>{data.orders.length}</span>}</button>
        {visible.map((id) => <button key={id} type="button" role="tab" aria-selected={task === id} onClick={() => setTask(id)} data-testid={`cf-finance-task-${id}`}>{taskLabel(id)}{data && <span>{data.tasks[id]}</span>}</button>)}
      </div>
      {task && <p className="cf-tab-hint">{t(`cf.fin.taskHint.${task}` as MessageKey)}</p>}
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!rows && !message && <QueueSkeleton />}
      {rows && (rows.length === 0
        ? <EmptyPanel icon="orders" title={t("cf.fin.queueEmpty")}>{t("cf.fin.queueEmptyHint")}</EmptyPanel>
        : <ul className="queue-list" aria-label={task ? taskLabel(task) : t("cf.fin.allTasks")}>{rows.map((order) => <FinanceRow key={order.globalOrderId} order={order} navigate={navigate} />)}</ul>)}
      {data && data.orders.length >= 500 && <p className="dashboard-footnote">{t("cf.fin.queueLimit")}</p>}
      {financeRole && <TransferExceptionsSection employee={employee} service={service} />}
    </article>
  );
}

function FinanceRow({ order, navigate }: { order: FinanceQueueItem; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const urgent = URGENT.includes(order.task);
  return (
    <li>
      <button type="button" className={`queue-row cf-row tone-${urgent ? "warning" : "neutral"}`} onClick={() => navigate(confirmareOrderPath(order.globalOrderId))} data-testid={`cf-finance-row-${order.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top">
            <strong className="order-id">#{order.orderNumber}</strong>
            <span className="cf-source">{sourceLabel(order.source)}</span>
          </span>
          <small>{order.dueDate ? t("cf.row.due", { date: dueDay(order.dueDate) }) : t("cf.row.noDue")} · {t("cf.fin.changedAt", { date: when(order.sourceChangedAt) })}</small>
          <small>{[order.financial.state ? t(`cf.financial.${order.financial.state}` as MessageKey) : t("cf.fin.stateMissing"), order.financial.attention ? attentionLabel(order.financial.attention) : null, order.paymentMethod === "cod" ? t("cf.fin.cod") : null].filter(Boolean).join(" · ")}</small>
          {order.verifying && <small data-testid={`cf-finance-verifying-${order.orderNumber}`}><AppIcon name="clock" size={14} /> {t("cf.fin.verifying")}</small>}
        </span>
        <span className="queue-chip">{taskLabel(order.task)}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}
