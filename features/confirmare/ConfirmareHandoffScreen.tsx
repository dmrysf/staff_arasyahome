import { useMemo } from "react";
import { useI18n } from "../../i18n";
import { handoffStateLabel, sourceLabel, type ConfirmareApi, type HandoffItem } from "../../domain/confirmare";
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
 */
export function ConfirmareHandoffScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal) => service.handoffList(null, signal), [service]);
  const queue = useAutoRefresh(load, "confirmare-handoff");
  const items = queue.data;
  const message = queue.error && (!items || queue.stopped) ? getErrorPresentation(queue.error).message : "";

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-handoff-queue">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.handoff.list.title")} description={t("cf.handoff.list.description")} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={items !== null && queue.error !== null} stopped={queue.stopped} testId="cf-handoff-refresh" />
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {!items && !message && <QueueSkeleton />}
      {items && (items.length === 0
        ? <EmptyPanel icon="orders" title={t("cf.handoff.list.empty")}>{t("cf.handoff.lead")}</EmptyPanel>
        : <ul className="queue-list" aria-label={t("cf.handoff.list.title")}>{items.map((item) => <HandoffRow key={item.globalOrderId} item={item} navigate={navigate} />)}</ul>)}
    </article>
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
