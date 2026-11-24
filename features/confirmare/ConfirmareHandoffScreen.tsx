import { useMemo, useState } from "react";
import { useI18n } from "../../i18n";
import { handoffStateLabel, sourceLabel, type ConfirmareApi, type HandoffItem } from "../../domain/confirmare";
import type { CustomerCheckOutcome, ShopQueueItem } from "../../domain/confirmareInvoice";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { dueDay, when } from "./ConfirmareParts";

/**
 * The production handoff list (API 2.42.0): cleared Confirmare orders at stage 1 of the shops in the employee's document
 * scope, with their next step (generate revision 1, print it, or wait for the cutting scan). It needs the document
 * generate permission, never a Confirmare scope, so a document employee never joins the customer confirmation pool. A
 * row opens the central production document of the order. The list reads every minute while visible and never acts.
 *
 * API 2.44.0: on top, the orders the operator routed to THIS employee (paid advance or card payment): the employee opens
 * the production ticket, scans its QR and issues the final SmartBill invoice there.
 */
export function ConfirmareHandoffScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal) => service.handoffList(null, signal), [service]);
  const queue = useAutoRefresh(load, "confirmare-handoff");
  const items = queue.data;
  const message = queue.error && (!items || queue.stopped) ? getErrorPresentation(queue.error).message : "";
  const loadMine = useMemo(() => (signal: AbortSignal) => service.shopQueue(signal), [service]);
  const mine = useAutoRefresh(loadMine, "confirmare-shop-queue");

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-handoff-queue">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.handoff.list.title")} description={t("cf.handoff.list.description")} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={items !== null && queue.error !== null} stopped={queue.stopped} testId="cf-handoff-refresh" />
      {service.customerCheck && mine.data && mine.data.some((item) => item.customerCheck === "required") && (
        <CustomerChecks items={mine.data.filter((item) => item.customerCheck === "required")} service={service} onDone={mine.refresh} navigate={navigate} />
      )}
      <section className="detail-section" aria-labelledby="cf-shop-mine" data-testid="cf-shop-queue">
        <div className="section-title"><h2 id="cf-shop-mine">{t("cf.handoff.mine.title")}</h2></div>
        {mine.data === null
          ? (mine.error ? <p className="order-notice order-notice-muted" role="alert">{getErrorPresentation(mine.error).message}</p> : <QueueSkeleton />)
          : mine.data.length === 0
            ? <p className="dashboard-footnote">{t("cf.handoff.mine.empty")}</p>
            : <ul className="queue-list" aria-label={t("cf.handoff.mine.title")}>{mine.data.map((item) => <MineRow key={item.globalOrderId} item={item} navigate={navigate} />)}</ul>}
      </section>
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!items && !message && <QueueSkeleton />}
      {items && (items.length === 0
        ? <EmptyPanel icon="orders" title={t("cf.handoff.list.empty")}>{t("cf.handoff.lead")}</EmptyPanel>
        : <ul className="queue-list" aria-label={t("cf.handoff.list.title")}>{items.map((item) => <HandoffRow key={item.globalOrderId} item={item} navigate={navigate} />)}</ul>)}
    </article>
  );
}

/**
 * Staff 2.52.4 (API 2.77.6): orders routed to me by a manager approval, without the operator's call. I call the customer,
 * check the products, measurements and price in the shop order, then record the answer. Production opens only after
 * "confirmed"; "needs correction" sends the order back to the operators.
 */
export function CustomerChecks({ items, service, onDone, navigate }: { items: ShopQueueItem[]; service: ConfirmareApi; onDone: () => void; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ order: string; text: string; tone: "positive" | "warning" } | null>(null);
  const answer = async (item: ShopQueueItem, outcome: CustomerCheckOutcome) => {
    if (!service.customerCheck || busy) return;
    setBusy(item.globalOrderId);
    try {
      const result = await service.customerCheck(item.globalOrderId, outcome, createIdempotencyKey());
      setMessage({ order: item.orderNumber, tone: result.productionCleared ? "positive" : "warning", text: t(`cf.check.result.${outcome}`) });
      onDone();
    } catch (error) {
      setMessage({ order: item.orderNumber, tone: "warning", text: getErrorPresentation(error).message });
    } finally {
      setBusy(null);
    }
  };
  return (
    <section className="detail-section" aria-labelledby="cf-check-title" data-testid="cf-customer-checks">
      <div className="section-title"><h2 id="cf-check-title">{t("cf.check.title")}</h2></div>
      <p className="dashboard-footnote">{t("cf.check.lead")}</p>
      {message && <p className={`order-notice order-notice-${message.tone === "positive" ? "success" : "muted"}`} role="status">#{message.order}: {message.text}</p>}
      <ul className="queue-list" aria-label={t("cf.check.title")}>
        {items.map((item) => (
          <li key={item.globalOrderId} className="queue-row cf-row tone-warning" data-testid={`cf-check-row-${item.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top">
                <button type="button" className="link-button" onClick={() => navigate(`/documents/${encodeURIComponent(item.globalOrderId)}`)}><strong className="order-id">#{item.orderNumber}</strong></button>
                <span className="cf-source">{sourceLabel(item.source)}</span>
              </span>
              <small>{t("cf.check.by", { name: item.assignedBy || "—", time: when(item.assignedAt) })}</small>
            </span>
            <span className="cf-check-actions">
              <button type="button" className="button button-primary" disabled={busy !== null} onClick={() => void answer(item, "confirmed")} data-testid={`cf-check-confirmed-${item.orderNumber}`}>{t("cf.check.confirmed")}</button>
              <button type="button" className="button" disabled={busy !== null} onClick={() => void answer(item, "no_answer")} data-testid={`cf-check-no-answer-${item.orderNumber}`}>{t("cf.check.no_answer")}</button>
              <button type="button" className="button" disabled={busy !== null} onClick={() => void answer(item, "needs_correction")} data-testid={`cf-check-correction-${item.orderNumber}`}>{t("cf.check.needs_correction")}</button>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

function MineRow({ item, navigate }: { item: ShopQueueItem; navigate: (path: string) => void }) {
  const { t } = useI18n();
  return (
    <li>
      <button type="button" className={`queue-row cf-row tone-${item.invoiced ? "neutral" : "accent"}`} onClick={() => navigate(`/documents/${encodeURIComponent(item.globalOrderId)}`)} data-testid={`cf-shop-row-${item.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top">
            <strong className="order-id">#{item.orderNumber}</strong>
            <span className="cf-source">{sourceLabel(item.source)}</span>
          </span>
          <small>{t("cf.handoff.mine.by", { name: item.assignedBy || "—", time: when(item.assignedAt) })}</small>
        </span>
        <span className="queue-chip">{item.customerCheck === "required" ? t("cf.check.chip") : item.invoiced ? t("cf.handoff.mine.invoiced") : t("cf.handoff.mine.pending")}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

function HandoffRow({ item, navigate }: { item: HandoffItem; navigate: (path: string) => void }) {
  const { t } = useI18n();
  return (
    <li>
      <button type="button" className={`queue-row cf-row tone-${item.step === "awaiting_cutting" ? "warning" : "accent"}`} onClick={() => navigate(`/documents/${encodeURIComponent(item.globalOrderId)}`)} data-testid={`cf-handoff-row-${item.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top">
            <strong className="order-id">#{item.orderNumber}</strong>
            <span className="cf-source">{sourceLabel(item.sourceKey)}</span>
          </span>
          <small>{item.dueDate ? t("cf.row.due", { date: dueDay(item.dueDate) }) : t("cf.row.noDue")} · {t("cf.row.received", { date: when(item.submittedAt ?? item.approvedAt) })}</small>
        </span>
        <span className="queue-chip">{handoffStateLabel(item.step)}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}
