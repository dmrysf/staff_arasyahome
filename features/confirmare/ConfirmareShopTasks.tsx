import { useMemo, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { opsErrorKey, type AwbQueueItem, type EfacturaState, type EfacturaTask, type HealthMetrics } from "../../domain/confirmareOps";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { AutoRefreshBar, Metric, MetricGrid, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ConfirmareOperationsPanel } from "./ConfirmareOperationsPanel";
import { SourceFilter, when } from "./ConfirmareParts";

const SOURCES: readonly ConfirmareSource[] = ["trendhome", "outletperdele"];
const failure = (error: unknown, t: (key: MessageKey) => string): string => {
  const failed = toServiceError(error);
  const key = opsErrorKey(typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined, failed.details?.editReason);
  return key ? t(key) : getErrorPresentation(failed).message;
};

/**
 * The AWB queue of the shop employee (API 2.46.0, owner decisions K-13, K-14): the approved orders of the employee's shops at
 * stage 12 (Control calitate) or later without an AWB, routed to this employee (an invoice manager sees the shop's). A
 * row opens the AWB panel in place: the one collection amount, the parcels, the label and the address change task.
 */
export function ConfirmareAwbScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  // The open order stays on screen after its AWB is made (it leaves the queue): the employee still prints the label.
  const [open, setOpen] = useState<AwbQueueItem | null>(null);
  const load = useMemo(() => (signal: AbortSignal) => service.awbQueue(null, signal), [service]);
  const queue = useAutoRefresh(load, "confirmare-awb");
  const items = queue.data === null ? null : open !== null && !queue.data.some((item) => item.globalOrderId === open.globalOrderId) ? [open, ...queue.data] : queue.data;
  const message = queue.error && (!items || queue.stopped) ? getErrorPresentation(queue.error).message : "";
  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-awb-queue">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.awbq.title")} description={t("cf.awbq.description")} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={items !== null && queue.error !== null} stopped={queue.stopped} testId="cf-awb-refresh" />
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!items && !message && <QueueSkeleton />}
      {items && (items.length === 0
        ? <EmptyPanel icon="orders" title={t("cf.awbq.empty")}>{t("cf.awbq.emptyHint")}</EmptyPanel>
        : <ul className="queue-list" aria-label={t("cf.awbq.title")}>{items.map((item) => <AwbRow key={item.globalOrderId} item={item} open={open?.globalOrderId === item.globalOrderId} onToggle={() => setOpen(open?.globalOrderId === item.globalOrderId ? null : item)} service={service} onChanged={queue.refresh} />)}</ul>)}
    </article>
  );
}

function AwbRow({ item, open, onToggle, service, onChanged }: { item: AwbQueueItem; open: boolean; onToggle: () => void; service: ConfirmareApi; onChanged: () => void }) {
  const { t } = useI18n();
  return (
    <li>
      <button type="button" className={`queue-row cf-row tone-${item.lastAttempt === "unknown" ? "warning" : "accent"}`} aria-expanded={open} onClick={onToggle} data-testid={`cf-awb-row-${item.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">#{item.orderNumber}</strong><span className="cf-source">{sourceLabel(item.source)}</span></span>
          <small>{t(`cf.awbq.stage.${item.stage === "quality-control" || item.stage === "packing" || item.stage === "delivery" ? item.stage : "other"}` as MessageKey)}{item.lastAttempt ? ` · ${t(item.lastAttempt === "unknown" ? "cf.awbq.lastUnknown" : "cf.awbq.lastRefused")}` : ""}</small>
        </span>
        <AppIcon name="chevron" size={18} />
      </button>
      {open && <ConfirmareOperationsPanel globalOrderId={item.globalOrderId} service={service} mode="awb" onChanged={onChanged} />}
    </li>
  );
}

/**
 * The e-Factura follow-up (API 2.46.0, owner decision K-04): every final SMART invoice must reach ANAF within 5 working
 * days. SmartBill sends it; its API gives no e-Factura answer, so the employee checks SmartBill Cloud (or SPV) and records
 * what they saw. Overdue tasks come first.
 */
export function ConfirmareEfacturaScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [source, setSource] = useState<string | null>(null);
  const [all, setAll] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [result, setResult] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const load = useMemo(() => (signal: AbortSignal) => service.efactura(source, all ? null : "attention", signal).then((queue) => ({ ...queue, source, all })), [service, source, all]);
  const queue = useAutoRefresh(load, `confirmare-efactura|${source ?? "all"}|${all ? "all" : "attention"}`);
  const data = queue.data?.source === source && queue.data.all === all ? queue.data : null;
  const message = queue.error && (!data || queue.stopped) ? getErrorPresentation(queue.error).message : "";

  async function record(task: EfacturaTask, outcome: EfacturaState) {
    setBusy(task.id); setResult(null);
    try {
      await service.recordEfactura(task.id, { outcome, expectedVersion: task.version });
      setResult({ tone: "success", text: t("cf.efactura.recorded", { number: task.orderNumber, state: t(`cf.efactura.state.${outcome}` as MessageKey) }) });
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught, t) });
    } finally {
      setBusy(null);
      queue.refresh();
    }
  }

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-efactura">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.efactura.title")} description={t("cf.efactura.description")} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={data !== null && queue.error !== null} stopped={queue.stopped} testId="cf-efactura-refresh" />
      <SourceFilter sources={SOURCES} value={source} onChange={setSource} />
      <div className="segmented cf-tabs" role="tablist" aria-label={t("cf.efactura.title")}>
        <button type="button" role="tab" aria-selected={!all} onClick={() => setAll(false)}>{t("cf.efactura.attention")}{data && !all && <span>{data.items.length}</span>}</button>
        <button type="button" role="tab" aria-selected={all} onClick={() => setAll(true)}>{t("cf.efactura.all")}</button>
      </div>
      {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} entering>{result.text}</Notice>}
      {data && data.overdue > 0 && <Notice tone="danger" role="note" testId="cf-efactura-overdue">{t("cf.efactura.overdueCount", { count: data.overdue })}</Notice>}
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!data && !message && <QueueSkeleton />}
      {data && (data.items.length === 0
        ? <EmptyPanel icon="document" title={t("cf.efactura.empty")}>{t("cf.efactura.emptyHint")}</EmptyPanel>
        : <ul className="queue-list cf-efactura-list">{data.items.map((task) => <li key={task.id} className={`cf-efactura-task${task.overdue ? " is-overdue" : ""}`} data-testid={`cf-efactura-${task.orderNumber}`}>
          <div className="queue-row-main">
            <span className="queue-row-top"><strong className="order-id">#{task.orderNumber}</strong><span className="cf-source">{sourceLabel(task.source)}</span></span>
            <small>{t("cf.efactura.issued", { name: task.issuer, time: when(task.issuedAt) })} · {t("cf.efactura.due", { time: when(task.dueAt) })}</small>
            <span className="package-pills"><StatusPill tone={task.overdue ? "danger" : task.state === "accepted" || task.state === "resolved" ? "success" : task.state === "rejected" ? "danger" : "neutral"}>{t(`cf.efactura.state.${task.state}` as MessageKey)}{task.overdue ? ` · ${t("cf.efactura.overdue")}` : ""}</StatusPill></span>
          </div>
          {task.next.length > 0 && <div className="cf-confirm-actions">{task.next.map((outcome) => <button key={outcome} type="button" className={`button ${outcome === "rejected" ? "button-danger-outline" : "button-secondary"}`} disabled={busy !== null} onClick={() => { void record(task, outcome); }} data-testid={`cf-efactura-${task.orderNumber}-${outcome}`}>{t(`cf.efactura.mark.${outcome}` as MessageKey)}</button>)}</div>}
        </li>)}</ul>)}
    </article>
  );
}

const HEALTH_FIELDS: readonly { key: string; path: readonly string[]; tone: "neutral" | "action" | "warning" | "success"; alert?: boolean }[] = [
  { key: "newOrdersToday", path: ["newOrdersToday"], tone: "neutral" },
  { key: "pending", path: ["confirmare", "pending"], tone: "action" },
  { key: "unassigned", path: ["confirmare", "unassigned"], tone: "warning", alert: true },
  { key: "neverCalled", path: ["confirmare", "neverCalled"], tone: "warning" },
  { key: "undetermined", path: ["confirmare", "undetermined"], tone: "warning", alert: true },
  { key: "proformaCycleErrors", path: ["proformaCycleErrors"], tone: "warning", alert: true },
  { key: "financeActionsOpen", path: ["financeActionsOpen"], tone: "warning" },
  { key: "invoiceActionsUnknown", path: ["invoiceActionsUnknown"], tone: "warning", alert: true },
  { key: "routedNotInvoiced", path: ["routedNotInvoiced"], tone: "action" },
  { key: "efacturaOpen", path: ["efactura", "open"], tone: "neutral" },
  { key: "efacturaOverdue", path: ["efactura", "overdue"], tone: "warning", alert: true },
  { key: "awbWaiting", path: ["awbWaiting"], tone: "action" },
  { key: "cardRefundsOpen", path: ["cardRefundsOpen"], tone: "warning" },
  { key: "operationsUnknown", path: ["operationsUnknown"], tone: "warning", alert: true },
  { key: "cancellationSyncOpen", path: ["cancellationSyncOpen"], tone: "warning" },
  { key: "productionStopped", path: ["productionStopped"], tone: "warning" },
];

const pick = (metrics: Record<string, unknown>, path: readonly string[]): number | "unavailable" => {
  let value: unknown = metrics;
  for (const part of path) value = value !== null && typeof value === "object" && !Array.isArray(value) ? (value as Record<string, unknown>)[part] : undefined;
  return typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : "unavailable";
};

/**
 * The operating health of both shops after the go-live (API 2.46.0): counts only, never an order, a customer or an
 * amount. A count that needs a person now is marked; "—" means the figure is not available (an older migration level).
 */
export function ConfirmareHealthScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal): Promise<HealthMetrics> => service.health(null, signal), [service]);
  const view = useAutoRefresh(load, "confirmare-health");
  const data = view.data;
  const message = view.error && (!data || view.stopped) ? getErrorPresentation(view.error).message : "";
  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-health">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.health.title")} description={t("cf.health.description")} />
      <AutoRefreshBar onRefresh={view.refresh} busy={view.busy} refreshing={view.refreshing} updatedAt={view.updatedAt} stale={data !== null && view.error !== null} stopped={view.stopped} testId="cf-health-refresh" />
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!data && !message && <QueueSkeleton />}
      {data && Object.entries(data.sources).map(([source, metrics]) => {
        const lastContact = typeof metrics.secondsSinceLastSourceContact === "number" ? Math.round(metrics.secondsSinceLastSourceContact / 60) : null;
        const lastBank = typeof metrics.lastBankCheck === "string" ? metrics.lastBankCheck : null;
        const alerts = HEALTH_FIELDS.filter((field) => field.alert && typeof pick(metrics, field.path) === "number" && (pick(metrics, field.path) as number) > 0);
        return (
          <section key={source} className="detail-section" aria-labelledby={`cf-health-${source}`} data-testid={`cf-health-${source}`}>
            <div className="section-title"><h2 id={`cf-health-${source}`}>{sourceLabel(source)}</h2>{alerts.length === 0 ? <StatusPill tone="success" icon="check">{t("cf.health.calm")}</StatusPill> : <StatusPill tone="warning" icon="clock">{t("cf.health.attention", { count: alerts.length })}</StatusPill>}</div>
            <p className="dashboard-footnote">{lastContact === null ? t("cf.health.noContact") : t("cf.health.lastContact", { minutes: lastContact })} · {lastBank ? t("cf.health.lastBank", { time: when(lastBank) }) : t("cf.health.noBank")}</p>
            {lastContact !== null && lastContact > 60 && <Notice tone="warning" role="note">{t("cf.health.contactStale")}</Notice>}
            <MetricGrid label={sourceLabel(source)}>
              {HEALTH_FIELDS.map((field) => <Metric key={field.key} label={t(`cf.health.metric.${field.key}` as MessageKey)} value={pick(metrics, field.path)} tone={field.tone} testId={`cf-health-${source}-${field.key}`} />)}
            </MetricGrid>
          </section>
        );
      })}
      {data && <p className="dashboard-footnote">{t("cf.health.generated", { time: when(data.generatedAt) })}</p>}
    </article>
  );
}
