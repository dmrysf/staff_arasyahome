import { useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi, ConfirmareDetail } from "../../domain/confirmare";
import { hoursUntil, opsErrorKey, RETRYABLE_CLOSED, type ProformaCycle } from "../../domain/confirmareOps";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

/** The cycle states a person may run now (the worker runs them too); closed cycles only show. */
const RUNNABLE: readonly ProformaCycle["state"][] = ["issue_pending", "mail_pending", "running", "waiting_bank_check", "cancel_pending", "cancel_unknown", "order_cancel_pending"];
const CLOSED_REASONS = ["advance_received", "proforma_expired", "proforma_replaced", "proforma_cancelled", "proforma_missing", "order_closed", "cancel_not_confirmed", "proforma_unsupported", ...RETRYABLE_CLOSED] as const;
const ERRORS = ["internal_error", "invoice_disabled", "shop_unreachable", "issue_unknown", "mail_unknown", "rate_limited"] as const;

const stateTone = (state: ProformaCycle["state"]): Tone =>
  state === "paid" ? "success" : state === "cancelled" || state === "cancel_unknown" ? "danger" : state === "closed" ? "neutral" : state === "waiting_bank_check" || state === "waiting_finance" ? "warning" : "info";

/**
 * The call and advance proforma cycle of an order (API 2.46.0, owner rules 3.3–3.5 and K-05): three unanswered calls, each
 * recorded as its own real call, then the advance proforma is issued unconfirmed and e-mailed by SmartBill; reminders after 24
 * and 72 hours; after 120 hours without the advance, and only once a bank check covers the expiry, the proforma and the
 * order are cancelled. Every step is the server's (a worker runs them); the operator of the order or a supervisor may run
 * the next step now. Times are shown in Bucharest time.
 */
export function ConfirmareCyclePanel({ detail, service, canRun, onChanged }: { detail: ConfirmareDetail; service: ConfirmareApi; canRun: boolean; onChanged: () => void }) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: Tone; text: string } | null>(null);
  const cycle = detail.cycle;
  if (!cycle || (cycle.calls === null && cycle.proforma === null && cycle.history.length === 0)) return null;
  const calls = cycle.calls;
  const proforma = cycle.proforma;
  const left = proforma ? hoursUntil(proforma.expiresAt) : null;
  const previous = cycle.history.filter((entry) => entry.cycle !== proforma?.cycle);
  // R1/R2 (2026-10-26): the latest cycle closed by a definite refusal and nothing runs now: say so, offer to start again.
  const latest = previous.reduce<ProformaCycle | null>((found, entry) => (found === null || entry.cycle > found.cycle ? entry : found), null);
  const failed = !proforma && calls?.complete && latest?.state === "closed" && latest.closedReason !== null && (RETRYABLE_CLOSED as readonly string[]).includes(latest.closedReason) ? latest : null;

  async function run(retry: boolean) {
    setBusy(true); setMessage(null);
    try {
      const after = retry ? await service.retryCycle(detail.globalOrderId) : await service.advanceCycle(detail.globalOrderId);
      setMessage({ tone: "success", text: t(retry ? "cf.cycle.retried" : "cf.cycle.advanced", { state: t(`cf.cycle.state.${after.state}` as MessageKey) }) });
    } catch (caught) {
      const failed = toServiceError(caught);
      const key = opsErrorKey(typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined, undefined);
      setMessage({ tone: "danger", text: key ? t(key) : getErrorPresentation(failed).message });
    } finally {
      setBusy(false);
      onChanged();
    }
  }

  return (
    <section className="detail-section cf-cycle" aria-labelledby="cf-cycle" data-testid="cf-cycle">
      <div className="section-title"><h2 id="cf-cycle">{t("cf.cycle.title")}</h2></div>
      {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"} entering>{message.text}</Notice>}
      {calls && <div className="package-pills">
        <StatusPill tone={calls.complete ? "warning" : "neutral"} icon="clock" testId="cf-cycle-calls">{t("cf.cycle.calls", { count: calls.noAnswer, max: calls.max })}</StatusPill>
      </div>}
      {calls && !calls.complete && calls.noAnswer > 0 && <p className="dashboard-footnote">{t("cf.cycle.callsHint", { left: calls.max - calls.noAnswer })}</p>}
      {calls?.complete && !proforma && !failed && <Notice tone="info" role="note" testId="cf-cycle-complete">{t("cf.cycle.callsComplete")}</Notice>}
      {failed && <div className="cf-ops-block" data-testid="cf-cycle-failed">
        <Notice tone="danger" role="alert" testId="cf-cycle-failed-notice">{t(failed.closedReason!.startsWith("mail_") ? "cf.cycle.failed.mail" : "cf.cycle.failed.issue", { reason: t(`cf.cycle.closed.${failed.closedReason}` as MessageKey) })}</Notice>
        {canRun && <button type="button" className="button button-primary" disabled={busy} onClick={() => { void run(true); }} data-testid="cf-cycle-retry">{busy ? t("common.sending") : t("cf.cycle.retry")}</button>}
      </div>}

      {proforma && <div className="cf-ops-block" data-testid="cf-cycle-proforma">
        <div className="package-pills">
          <StatusPill tone={stateTone(proforma.state)} testId="cf-cycle-state">{t(`cf.cycle.state.${proforma.state}` as MessageKey)}</StatusPill>
          {proforma.origin === "no_answer" && <StatusPill tone="warning">{t("cf.cycle.unconfirmed")}</StatusPill>}
        </div>
        <dl className="authority-facts">
          <div><dt>{t("cf.cycle.sent")}</dt><dd>{when(proforma.sentAt)}</dd></div>
          <div><dt>{t("cf.cycle.reminder1")}</dt><dd>{proforma.reminder1At ? t("cf.cycle.doneAt", { time: when(proforma.reminder1At) }) : t("cf.cycle.dueAt", { time: when(proforma.reminder1DueAt) })}</dd></div>
          <div><dt>{t("cf.cycle.reminder2")}</dt><dd>{proforma.reminder2At ? t("cf.cycle.doneAt", { time: when(proforma.reminder2At) }) : t("cf.cycle.dueAt", { time: when(proforma.reminder2DueAt) })}</dd></div>
          <div><dt>{t("cf.cycle.expires")}</dt><dd data-testid="cf-cycle-expires">{when(proforma.expiresAt)}{left !== null ? ` · ${left >= 0 ? t("cf.cycle.hoursLeft", { hours: left }) : t("cf.cycle.hoursPast", { hours: -left })}` : ""}</dd></div>
        </dl>
        {proforma.state === "waiting_bank_check" && <Notice tone="warning" role="note" testId="cf-cycle-bank">{t("cf.cycle.waitingBank")}</Notice>}
        {proforma.state === "waiting_finance" && <Notice tone="warning" role="note">{t("cf.cycle.waitingFinance")}</Notice>}
        {proforma.lastError && <Notice tone="danger" role="note" testId="cf-cycle-error">{t(((ERRORS as readonly string[]).includes(proforma.lastError) ? `cf.cycle.error.${proforma.lastError}` : "cf.cycle.error.other") as MessageKey)}</Notice>}
        {canRun && RUNNABLE.includes(proforma.state) && <button type="button" className="button button-secondary" disabled={busy} onClick={() => { void run(false); }} data-testid="cf-cycle-advance">{busy ? t("common.sending") : t("cf.cycle.advance")}</button>}
      </div>}

      {previous.length > 0 && <ol className="timeline" data-testid="cf-cycle-history">{previous.map((entry) => <li key={entry.cycle}>
        <strong>{t("cf.cycle.historyEntry", { cycle: entry.cycle, state: t(`cf.cycle.state.${entry.state}` as MessageKey) })}</strong>
        {entry.closedReason && <small>{t(((CLOSED_REASONS as readonly string[]).includes(entry.closedReason) ? `cf.cycle.closed.${entry.closedReason}` : "cf.cycle.closed.other") as MessageKey)}</small>}
        <small>{when(entry.closedAt ?? entry.sentAt)}</small>
      </li>)}</ol>}
    </section>
  );
}
