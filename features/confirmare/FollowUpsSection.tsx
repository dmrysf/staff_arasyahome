import { useMemo, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel, type ConfirmareApi, type FollowUpItem } from "../../domain/confirmare";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { DashboardSection, EmptyState } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { confirmareOrderPath, when } from "./ConfirmareParts";

/** The reasons a supervisor reads in words; any other code is shown as it is (never hidden). */
const KNOWN_REASONS = new Set([
  "operator_inactive", "money_received", "invoice_exists", "production_started", "finance_hold", "finance_open", "not_held", "invoice_disabled",
  "cancel_not_confirmed", "proforma_replaced", "total_changed", "order_changed", "shop_unreachable", "smartbill_unreachable", "rate_limited", "accept_unknown",
]);

/**
 * API 2.82.0 (owner correction 2026-11-26): what the system could not finish after an operator's decision: money arrived
 * after a refusal, an invoice or production exists, SmartBill did not confirm, the deciding operator's account is off, the
 * attempts ran out. Supervisors only (never the operator's list). "Reia" runs the same follow-up again from where it
 * stopped, with every check of that step, as the supervisor; it is refused while another follow-up of the order is active.
 */
export function FollowUpsSection({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal) => service.followUps ? service.followUps(signal) : Promise.resolve([] as FollowUpItem[]), [service]);
  const view = useAutoRefresh(load, "confirmare-followups");
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: Tone; text: string } | null>(null);
  const keys = useRef(new Map<string, string>());
  const items = view.data ?? [];

  async function retry(item: FollowUpItem) {
    if (!service.retryFollowUp) return;
    const key = keys.current.get(item.id) ?? createIdempotencyKey();
    keys.current.set(item.id, key);
    setBusy(item.id); setMessage(null);
    try {
      await service.retryFollowUp(item.id, key);
      keys.current.delete(item.id);
      setMessage({ tone: "success", text: t("dd.fu.retried", { number: item.orderNumber }) });
    } catch (caught) {
      setMessage({ tone: "danger", text: getErrorPresentation(toServiceError(caught)).message });
    } finally { setBusy(null); view.refresh(); }
  }

  const reason = (code: string | null) => code === null ? "—" : KNOWN_REASONS.has(code) ? t(`dd.fu.reason.${code}` as MessageKey) : code;
  return (
    <DashboardSection labelledBy="dd-followups" eyebrow={t("dd.fu.eyebrow")} title={t("dd.fu.title")}>
      {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"}>{message.text}</Notice>}
      {view.error && <Notice tone="danger" role="alert">{getErrorPresentation(view.error).message}</Notice>}
      {view.data && (items.length === 0
        ? <EmptyState title={t("dd.fu.none")}>{t("dd.fu.hint")}</EmptyState>
        : <ul className="queue-list" data-testid="dd-followups">{items.map((item) => (
          <li key={item.id} className="dd-fu-row" data-testid={`dd-fu-${item.orderNumber}`}>
            <button type="button" className="queue-row tone-warning" onClick={() => navigate(`${confirmareOrderPath(item.globalOrderId)}?detailed=1`)}>
              <span className="queue-row-main">
                <span className="queue-row-top"><strong className="order-id">#{item.orderNumber}</strong><span className="cf-source">{sourceLabel(item.sourceKey)}</span></span>
                <small>{t(item.kind === "confirmed" ? "dd.fu.kind.confirmed" : "dd.fu.kind.refused", { name: item.decidedBy })} · {when(item.updatedAt)}</small>
                <small>{reason(item.reason)}{item.retriedBy ? ` · ${t("dd.fu.retriedBy", { name: item.retriedBy })}` : ""}</small>
              </span>
              <StatusPill tone={item.state === "failed" ? "danger" : "warning"}>{t(item.state === "failed" ? "dd.fu.state.failed" : "dd.fu.state.waiting")}</StatusPill>
            </button>
            {service.retryFollowUp && <button type="button" className="button button-secondary button-compact" disabled={busy !== null} onClick={() => { void retry(item); }} data-testid={`dd-fu-retry-${item.orderNumber}`}>{t("dd.fu.retry")}</button>}
          </li>
        ))}</ul>)}
      <p className="dashboard-footnote">{t("dd.fu.footnote")}</p>
    </DashboardSection>
  );
}
