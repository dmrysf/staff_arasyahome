import { useCallback, useEffect, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { StaffServiceError } from "../../domain/models";
import type { ConfirmareApi } from "../../domain/confirmare";
import { CANCEL_REASONS, POLL_LIMIT_MS, nextPollMs, proformaLabel, reasonLabel, requestErrorKey, requestPhase, type CancelReason, type OrderFinanceRequest, type RequestPhase } from "../../domain/financeRequests";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;
const phaseTone: Readonly<Record<RequestPhase, Tone>> = {
  none: "neutral", waitingAssignment: "warning", assigned: "warning", checking: "warning", secondCheck: "warning", verified: "success", verifiedPartial: "warning",
  notReceived: "danger", clarification: "danger", exception: "danger", cancelled: "neutral", manualReview: "warning", resolved: "neutral",
};

/**
 * Step 4 (Plata și avansul), Staff 2.41.0: the operator sends the advance payment to finance and sees the answer. The
 * screen asks the server again while finance works on it (5 s while checking, 10 s while waiting, the server's hint),
 * only while the tab is visible and for at most 30 minutes without a change; it never sends a command by itself.
 * `onlyManual` (an order outside Confirmare, shown on the production order screen): the card appears only for a covered
 * order (submitted from 22 October 2026) of the manual ledger and stays silent otherwise (no Confirmare scope, an older
 * order, a Confirmare order that has its own step 4).
 */
export function FinanceRequestCard({ globalOrderId, service, canOperate, onSettled, onlyManual = false }: { globalOrderId: string; service: ConfirmareApi; canOperate: boolean; onSettled: () => void; onlyManual?: boolean }) {
  const { t } = useI18n();
  const [view, setView] = useState<OrderFinanceRequest | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState<"request" | "resubmit" | "cancel" | null>(null);
  const [cancelReason, setCancelReason] = useState<CancelReason | "">("");
  const [hidden, setHidden] = useState(() => typeof document !== "undefined" && document.visibilityState === "hidden");
  const [stopped, setStopped] = useState(false);
  const changedAt = useRef(0);
  const lastState = useRef<string | null>(null);
  const intent = useRef<{ id: string; key: string } | null>(null);
  const keyFor = (id: string) => { if (intent.current?.id !== id) intent.current = { id, key: createIdempotencyKey() }; return intent.current.key; };

  const accept = useCallback((next: OrderFinanceRequest) => {
    const state = next.request ? `${next.request.state}|${next.request.version}|${next.financial.open}` : "none";
    if (state !== lastState.current) {
      // A finance answer reached the order: the order screen reads its facts again (step 4 may be done now).
      if (lastState.current !== null) onSettled();
      lastState.current = state;
      changedAt.current = Date.now();
      setStopped(false);
    }
    setView(next);
  }, [onSettled]);

  const read = useCallback((signal?: AbortSignal): Promise<void> => service.orderFinanceRequest(globalOrderId, signal).then((next) => { accept(next); setError(null); }, (caught: unknown) => {
    if (signal?.aborted) return;
    const failure = toServiceError(caught);
    const key = requestErrorKey(backendCode(failure));
    setError(key ? t(key) : getErrorPresentation(failure).message);
  }), [accept, globalOrderId, service, t]);

  useEffect(() => {
    const controller = new AbortController();
    service.orderFinanceRequest(globalOrderId, controller.signal).then((next) => { accept(next); setError(null); }, (caught: unknown) => {
      if (controller.signal.aborted) return;
      const failure = toServiceError(caught);
      const key = requestErrorKey(backendCode(failure));
      setError(key ? t(key) : getErrorPresentation(failure).message);
    });
    return () => controller.abort();
  }, [accept, globalOrderId, service, t]);

  useEffect(() => {
    const onVisibility = () => setHidden(document.visibilityState === "hidden");
    document.addEventListener("visibilitychange", onVisibility);
    return () => document.removeEventListener("visibilitychange", onVisibility);
  }, []);

  // Bounded, visibility-aware polling: one timer at a time, from the server's hint; it stops after POLL_LIMIT_MS
  // without a change (the employee can ask again).
  useEffect(() => {
    const delay = stopped ? null : nextPollMs(view, hidden, 0);
    if (delay === null) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      if (Date.now() - changedAt.current >= POLL_LIMIT_MS) setStopped(true);
      else void read(controller.signal);
    }, delay);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [view, hidden, read, stopped]);

  async function send(id: string, command: (key: string) => Promise<OrderFinanceRequest>) {
    setBusy(true); setError(null);
    try {
      accept(await command(keyFor(id)));
      intent.current = null;
      setConfirming(null); setCancelReason("");
    } catch (caught) {
      const failure = toServiceError(caught);
      const key = requestErrorKey(backendCode(failure));
      setError(key ? t(key) : getErrorPresentation(failure).message);
      if (backendCode(failure) === "FINANCE_REQUEST_STALE" || backendCode(failure) === "FINANCE_REQUEST_STATE") { intent.current = null; setConfirming(null); void read(); }
    } finally { setBusy(false); }
  }

  if (onlyManual && (!view || view.ledger !== "manual" || (view.scope !== "in_scope" && !view.request))) return null;
  if (!view) return error ? <Notice tone="warning" role="status" testId="fr-step-error">{error}</Notice> : null;
  const phase = requestPhase(view);
  const request = view.request;

  return (
    <section className="cfw-block fr-step" aria-labelledby="fr-step-title" data-testid="fr-step" data-phase={phase}>
      <h4 id="fr-step-title">{t("fr.step.title")}</h4>
      {request && <p className="package-pills">
        <StatusPill tone={phaseTone[phase]} testId="fr-step-state">{t(`fr.phase.${phase}` as MessageKey)}</StatusPill>
        <StatusPill icon="document">{t("fr.step.proforma", { proforma: proformaLabel(request.proforma) })}</StatusPill>
      </p>}
      <p className="dashboard-lead" data-testid="fr-step-message">{t(`fr.step.${phase}` as MessageKey, { name: request?.assignee ?? "—", reason: reasonLabel(request?.reasonCode ?? null), at: when(request?.decidedAt ?? request?.updatedAt ?? null) })}</p>
      {phase === "verified" && <Notice tone="success" role="status" testId="fr-step-verified">{t("fr.step.verifiedBanner")}</Notice>}
      {view.ledger === "manual" && <Notice tone="info" role="note" testId="fr-step-manual">{t("fr.step.manualLedger")}</Notice>}
      {view.scope !== "in_scope" && <Notice tone="warning" role="note" testId="fr-step-scope">{t(view.scope === "before_scope" ? "fr.step.beforeScope" : "fr.step.undetermined")}</Notice>}
      {stopped && <p className="dashboard-footnote" data-testid="fr-step-stopped">{t("fr.step.stopped")} <button type="button" className="button button-link" onClick={() => { changedAt.current = Date.now(); setStopped(false); void read(); }}>{t("fr.step.askAgain")}</button></p>}
      {error && <Notice tone="danger" role="alert" testId="fr-step-error">{error}</Notice>}

      {canOperate && !confirming && <div className="cfw-actions">
        {view.canRequest && !view.financial.open && (phase === "none" || phase === "notReceived" || phase === "cancelled" || phase === "verifiedPartial") && <button type="button" className="button button-primary" disabled={busy} onClick={() => setConfirming("request")} data-testid="fr-step-request">{t(request ? "fr.step.requestAgain" : "fr.step.request")}</button>}
        {view.canResubmit && <button type="button" className="button button-primary" disabled={busy} onClick={() => setConfirming("resubmit")} data-testid="fr-step-resubmit">{t("fr.step.resubmit")}</button>}
        {view.canCancel && <button type="button" className="button button-secondary" disabled={busy} onClick={() => setConfirming("cancel")} data-testid="fr-step-cancel">{t("fr.step.cancel")}</button>}
      </div>}

      {confirming && <div className="cf-confirm" role="group" aria-labelledby="fr-step-confirm" data-testid="fr-step-confirm">
        <p className="eyebrow" id="fr-step-confirm">{t("fr.confirm.title")}</p>
        <p>{t(`fr.step.confirm.${confirming}` as MessageKey)}</p>
        {confirming === "cancel" && <fieldset className="cf-outcomes"><legend>{t("fr.form.reason")}</legend>
          {CANCEL_REASONS.map((value) => <label key={value} className="check-field"><input type="radio" name="fr-cancel" value={value} checked={cancelReason === value} onChange={() => setCancelReason(value)} data-testid={`fr-cancel-${value}`} /><span className="cf-check">{reasonLabel(value)}</span></label>)}
        </fieldset>}
        <div className="cfw-actions">
          <button type="button" className="button button-primary" disabled={busy || (confirming === "cancel" && !cancelReason)} data-testid="fr-step-confirm-send" onClick={() => {
            if (confirming === "request") void send("request", (key) => service.requestFinance(globalOrderId, key));
            if (confirming === "resubmit" && request) void send(`resubmit|${request.version}`, (key) => service.resubmitFinance(globalOrderId, { expectedVersion: request.version }, key));
            if (confirming === "cancel" && request && cancelReason) void send(`cancel|${request.version}|${cancelReason}`, (key) => service.cancelFinance(globalOrderId, { expectedVersion: request.version, reasonCode: cancelReason }, key));
          }}>{busy ? t("common.sending") : t("fr.confirm.send")}</button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => { setConfirming(null); setCancelReason(""); }}>{t("fr.confirm.back")}</button>
        </div>
      </div>}
    </section>
  );
}
