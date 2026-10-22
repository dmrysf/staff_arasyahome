import { formatDate, useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { contactOutcomeLabel, itemStatus, itemStatusLabel, itemStatusTone, sourceLabel, type ConfirmareApi, type ConfirmareItem, type ConfirmareSource } from "../../domain/confirmare";
import { AppIcon } from "../../components/icons/AppIcon";
import { StatusPill } from "../../components/ui/StatusPill";

export const when = (value: string | null): string => value ? formatDate(value, { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) : "—";
/** A source due date (YYYY-MM-DD) is a calendar day: shown as such, never shifted by a time zone. */
export const dueDay = (value: string | null): string => value ? formatDate(`${value}T12:00:00Z`, { dateStyle: "medium", timeZone: "UTC" }) : "—";

export const confirmareOrderPath = (globalOrderId: string): string => `/confirmare/orders/${encodeURIComponent(globalOrderId)}`;

/** The rows the operator works on: the pending orders (server priority order), then approvals still waiting. */
export type ConfirmareQueueData = { items: ConfirmareItem[]; source: string | null };

export function confirmareQueueLoader(service: ConfirmareApi, source: string | null) {
  // Reads only: never claims, approves, cancels or changes a payment on refresh.
  return async (signal: AbortSignal): Promise<ConfirmareQueueData> => {
    const [open, followup] = await Promise.all([service.pool("open", source, signal), service.pool("followup", source, signal)]);
    const seen = new Set(open.items.map((item) => item.globalOrderId));
    return { items: [...open.items, ...followup.items.filter((item) => !seen.has(item.globalOrderId))], source };
  };
}

/** One queue row: order identity and states only (never customer data). One tap opens the order. */
export function ConfirmareRow({ item, employee, navigate }: { item: ConfirmareItem; employee: Employee; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const status = itemStatus(item);
  const mine = item.assignment?.employeeId === employee.employeeUuid;
  const tone = itemStatusTone[status];
  return (
    <li>
      <button type="button" className={`queue-row cf-row tone-${tone}${mine ? " queue-mine" : ""}`} onClick={() => navigate(confirmareOrderPath(item.globalOrderId))} data-testid={`cf-row-${item.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top">
            <strong className="order-id">#{item.orderNumber}</strong>
            <span className="cf-source">{sourceLabel(item.sourceKey)}</span>
          </span>
          <small>{item.dueDate ? t("cf.row.due", { date: dueDay(item.dueDate) }) : t("cf.row.noDue")} · {t("cf.row.received", { date: when(item.submittedAt ?? item.openedAt) })}</small>
          {item.contact && <small>{t("cf.row.lastCall", { outcome: contactOutcomeLabel(item.contact.outcome), date: when(item.contact.at) })}{item.contact.current ? "" : ` · ${t("cf.row.callStale")}`}</small>}
          {item.assignment && !mine && <small>{t("cf.row.assignedTo", { name: item.assignment.displayName })}</small>}
          {!item.financial.open && item.status !== "rejected" && <small className="queue-row-reason">{t("cf.row.paymentMissing")}</small>}
        </span>
        <span className="queue-chip">{itemStatusLabel(status)}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

export function ItemStatusPill({ item, testId }: { item: ConfirmareItem; testId?: string }) {
  const status = itemStatus(item);
  return <StatusPill tone={itemStatusTone[status]} icon={status === "cleared" || status === "confirmed" ? "check" : status === "toCall" ? "phone" : "clock"} testId={testId}>{itemStatusLabel(status)}</StatusPill>;
}

/** Source filter: shown only for someone holding more than one source; it never adds a source the server did not grant. */
export function SourceFilter({ sources, value, onChange }: { sources: readonly ConfirmareSource[]; value: string | null; onChange: (source: string | null) => void }) {
  const { t } = useI18n();
  if (sources.length < 2) return null;
  const choices: (ConfirmareSource | null)[] = [null, ...sources];
  return (
    <div className="segmented cf-source-filter" role="radiogroup" aria-label={t("cf.filter.source")}>
      {choices.map((source) => <button key={source ?? "all"} type="button" role="radio" aria-checked={value === source} onClick={() => { if (value !== source) onChange(source); }}>{source ? sourceLabel(source) : t("cf.filter.allSources")}</button>)}
    </div>
  );
}
