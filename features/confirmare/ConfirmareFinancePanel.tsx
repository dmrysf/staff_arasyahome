import { useEffect, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi, ConfirmareDetail } from "../../domain/confirmare";
import {
  FINANCE_DATA_TTL_MS, TRANSFER_REJECT_REASONS, actionLabel, attentionLabel, entryLabel, entryStateLabel, financeErrorKey, formatFinanceMoney, nextLabel, nextTone,
  normalizeAmount, ownPendingEntries, refusalLabel, rejectReasonLabel, validReference, verifiableEntries,
  type DecisionInput, type FinanceAction, type FinanceEntry, type FinanceRead, type FinanceView, type RefundInput, type TransferInput, type TransferRejectReason,
} from "../../domain/confirmareFinance";
import { shopUnavailableLabel } from "../../domain/confirmareShop";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

type Result = { tone: "success" | "warning" | "danger"; text: string; key?: string } | null;
type Pending = { action: FinanceAction; input: TransferInput | DecisionInput | RefundInput | { expectedRevision: string }; summary: string; key: string };

/**
 * The money of a Confirmare order (API 2.41.0, Phase 5), read from the shop when the employee asks: the current and the
 * original total, what was really received (a verified online payment, the cash advance, verified bank transfers, less
 * refunds), the COD advance, an additional payment, a refund liability and every finance record entry. WooCommerce stays
 * the authority: Staff computes no amount and keeps nothing (component memory only, hidden after FINANCE_DATA_TTL_MS).
 *
 * Actions exist only when the server offers them to this employee: a recorded transfer is never money received (another
 * employee verifies it against the bank statement), a refund only after the final manufacturing stage, never through a
 * payment provider. Every action is shown as a plain summary and confirmed once before it is sent; a lost answer is
 * checked with the shop (never sent twice), and nothing is shown as done before the shop confirmed it.
 */
export function ConfirmareFinancePanel({ detail, service }: { detail: ConfirmareDetail; service: ConfirmareApi }) {
  const { t } = useI18n();
  const [read, setRead] = useState<FinanceRead | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expired, setExpired] = useState(false);
  const [result, setResult] = useState<Result>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const [readFor, setReadFor] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const request = useRef<AbortController | null>(null);

  // The figures belong to one order and one permission: anything else drops them (adjusted while rendering).
  const context = `${detail.globalOrderId}|${detail.finance.read ? "read" : "none"}|${detail.finance.record ? "r" : ""}${detail.finance.approve ? "a" : ""}`;
  if (read !== null && readFor !== context) {
    setRead(null); setReadFor(null); setPending(null);
  }

  useEffect(() => () => {
    request.current?.abort();
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const failure = (caught: unknown): string => {
    const failed = toServiceError(caught);
    const code = typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined;
    if (code === "CONFIRMARE_FINANCE_REFUSED") return t("cf.fin.refused", { reason: refusalLabel(typeof failed.details?.editReason === "string" ? failed.details.editReason : undefined) });
    const key = financeErrorKey(code);
    return key ? t(key) : getErrorPresentation(failed).message;
  };

  async function load() {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setBusy(true); setError(null); setExpired(false);
    try {
      const value = await service.financeRead(detail.globalOrderId, controller.signal);
      if (controller.signal.aborted) return;
      setRead(value); setReadFor(context);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => { setRead(null); setReadFor(null); setPending(null); setExpired(true); }, FINANCE_DATA_TTL_MS);
    } catch (caught) {
      if (controller.signal.aborted) return;
      // A refused read never leaves older figures on screen.
      setRead(null); setReadFor(null); setPending(null);
      setError(failure(caught));
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  }

  async function send(action: Pending) {
    setBusy(true); setResult(null);
    try {
      const outcome = await service.financeAct(detail.globalOrderId, action.action, action.input, action.key);
      setPending(null);
      if (outcome.state === "applied") {
        setResult({ tone: "success", text: t("cf.fin.applied", { action: actionLabel(action.action) }) });
        await load();
      } else {
        // The answer was lost: the shop may or may not have recorded it. Check, never send again.
        setResult({ tone: "warning", text: t("cf.fin.unknown"), key: outcome.idempotencyKey });
      }
    } catch (caught) {
      const text = failure(caught);
      setPending(null);
      setResult({ tone: "danger", text });
      // The shop's figures changed (another employee, the order): read them again before anything else.
      if (/revision_changed|entry_not_pending/.test(JSON.stringify(toServiceError(caught).details ?? {}))) await load();
    } finally {
      setBusy(false);
    }
  }

  async function reconcile(key: string) {
    setBusy(true); setResult(null);
    try {
      const outcome = await service.financeReconcile(detail.globalOrderId, key);
      if (outcome.state === "applied") {
        setResult({ tone: "success", text: t("cf.fin.reconciled") });
        await load();
      } else {
        setResult({ tone: "warning", text: outcome.reachable === false ? t("cf.fin.stillUnreachable") : t("cf.fin.stillUnknown"), key });
      }
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
      await load();
    } finally {
      setBusy(false);
    }
  }

  const hide = () => {
    if (timer.current) clearTimeout(timer.current);
    setRead(null); setReadFor(null); setPending(null); setExpired(false);
  };

  return (
    <section className="detail-section cf-finance" aria-labelledby="cf-finance-title" data-testid="cf-finance">
      <div className="section-title"><h2 id="cf-finance-title">{t("cf.fin.title")}</h2>{read?.available && <StatusPill tone={nextTone[read.nextAction]} icon={read.nextAction === "none" ? "check" : "alert"}>{nextLabel(read.nextAction)}</StatusPill>}</div>
      {!detail.finance.configured
        ? <Notice tone="info" role="note" testId="cf-finance-off">{t("cf.fin.notConfigured")}</Notice>
        : !detail.finance.read
          ? <p className="order-notice order-notice-muted" data-testid="cf-finance-not-yours">{t("cf.fin.notYours")}</p>
          : <>
            <p className="dashboard-lead">{t("cf.fin.lead")}</p>
            <div className="cf-shop-actions">
              <button type="button" className={read ? "button button-secondary" : "button button-primary"} disabled={busy} onClick={() => { void load(); }} data-testid="cf-finance-load">
                <AppIcon name={read ? "refresh" : "eye"} size={18} /> {busy ? t("cf.fin.loading") : read ? t("cf.fin.refresh") : t("cf.fin.show")}
              </button>
              {read && <button type="button" className="button button-secondary" onClick={hide} data-testid="cf-finance-hide">{t("cf.fin.hide")}</button>}
            </div>
            {busy && <p className="visually-hidden" aria-live="polite">{t("cf.fin.loading")}</p>}
            {expired && <Notice tone="info" role="status" testId="cf-finance-expired">{t("cf.fin.expired")}</Notice>}
            {error && <Notice tone="danger" role="alert" testId="cf-finance-error">{error}</Notice>}
            {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} testId="cf-finance-result">
              {result.text}
              {result.key && <> <button type="button" className="button button-secondary" disabled={busy} onClick={() => { void reconcile(result.key!); }} data-testid="cf-finance-reconcile-result">{t("cf.fin.check")}</button></>}
            </Notice>}
            {read && !read.available && <Notice tone="warning" role="alert" testId="cf-finance-unavailable">{t("cf.fin.unavailable", { reason: shopUnavailableLabel(read.reason) })}</Notice>}
            {read?.available && <FinanceBody view={read} role={read.role} busy={busy} pending={pending} onPrepare={setPending} onConfirm={(value) => { void send(value); }} onCancel={() => setPending(null)} onReconcile={(key) => { void reconcile(key); }} />}
          </>}
    </section>
  );
}

function FinanceBody({ view, role, busy, pending, onPrepare, onConfirm, onCancel, onReconcile }: {
  view: FinanceView; role: "finance" | "assignee" | "supervisor"; busy: boolean; pending: Pending | null;
  onPrepare: (value: Pending) => void; onConfirm: (value: Pending) => void; onCancel: () => void; onReconcile: (key: string) => void;
}) {
  const { t } = useI18n();
  const { summary } = view;
  const money = (amount: string | null | undefined) => formatFinanceMoney(amount, summary.currency);
  const name = (id: string | null) => (id === null ? "—" : view.employees[id] ?? t("cf.fin.otherEmployee"));
  const allowed = (action: FinanceAction) => view.allowedActions.includes(action);
  const changed = summary.totals.original !== null && summary.totals.original !== summary.totals.current;
  const positive = (amount: string | null) => amount !== null && Number(amount) > 0;
  const openActions = view.timeline.filter((item) => item.state === "pending" || item.state === "unknown");

  return (
    <div className="cf-finance-view" data-testid="cf-finance-view">
      <Notice tone={nextTone[view.nextAction] === "success" ? "success" : nextTone[view.nextAction] === "danger" ? "danger" : nextTone[view.nextAction] === "warning" ? "warning" : "info"} role="status" testId="cf-finance-next">
        <strong>{nextLabel(view.nextAction)}</strong> {t(`cf.fin.nextDetail.${view.nextAction}` as MessageKey)}
      </Notice>
      <p className="dashboard-footnote">{[t("cf.fin.readAt", { time: when(summary.readAt) }), t("cf.fin.shopModified", { time: when(summary.order.modifiedAt) }), role !== "finance" ? t("cf.fin.readOnly") : null].filter(Boolean).join(" · ")}</p>

      <div className="cf-shop-block">
        <h3>{t("cf.fin.amountsTitle")}</h3>
        <dl className="cf-shop-totals" data-testid="cf-finance-amounts">
          <div className="cf-shop-grand"><dt>{t("cf.fin.currentTotal")}</dt><dd data-testid="cf-finance-current">{money(summary.totals.current)}</dd></div>
          {changed && <div><dt>{t("cf.fin.originalTotal")}</dt><dd data-testid="cf-finance-original">{money(summary.totals.original)}</dd></div>}
          {!summary.order.cod && <div><dt>{t("cf.fin.onlinePaid")}</dt><dd>{summary.payment.onlineVerified ? money(summary.received.online) : t("cf.fin.notVerified")}</dd></div>}
          {summary.order.cod && <div><dt>{t("cf.fin.advanceCollected")}</dt><dd>{money(summary.received.advance)}</dd></div>}
          {positive(summary.received.transfers) && <div><dt>{t("cf.fin.transfersVerified")}</dt><dd>{money(summary.received.transfers)}</dd></div>}
          {positive(summary.totals.refunded) && <div><dt>{t("cf.fin.refunded")}</dt><dd>−{money(summary.totals.refunded)}</dd></div>}
          <div><dt>{t("cf.fin.received")}</dt><dd data-testid="cf-finance-received"><strong>{money(summary.received.net)}</strong></dd></div>
          {summary.advance && <>
            <div><dt>{t("cf.fin.advanceRequired", { percentage: summary.advance.percentage })}</dt><dd>{money(summary.advance.required)}</dd></div>
            <div><dt>{t("cf.fin.advanceOutstanding")}</dt><dd data-testid="cf-finance-advance-outstanding">{money(summary.advance.outstanding)}</dd></div>
            <div><dt>{t("cf.fin.onDelivery")}</dt><dd>{money(summary.advance.onDelivery)}</dd></div>
          </>}
          {summary.balance.outstanding !== null && <div><dt>{t("cf.fin.outstanding")}</dt><dd data-testid="cf-finance-outstanding">{money(summary.balance.outstanding)}</dd></div>}
          {positive(summary.balance.refundLiability) && <div><dt>{t("cf.fin.refundLiability")}</dt><dd data-testid="cf-finance-liability">{money(summary.balance.refundLiability)} · {t(summary.balance.acknowledged ? "cf.fin.acknowledged" : "cf.fin.notAcknowledged")}</dd></div>}
        </dl>
        <p className="cf-line-facts"><strong>{summary.order.methodTitle || summary.order.method || "—"}</strong> · {t(`cf.fin.evidence.${summary.payment.evidence}` as MessageKey)}</p>
        <p className="cf-line-facts" data-testid="cf-finance-state">{t(`cf.financial.${summary.financial.state}` as MessageKey)}{summary.financial.attention ? ` · ${attentionLabel(summary.financial.attention)}` : ""}</p>
        {view.production.refundEligible ? null : positive(summary.balance.refundLiability) && <p className="dashboard-footnote">{t("cf.fin.refundAfterProduction")}</p>}
        <p className="dashboard-footnote">{t("cf.fin.noElectronicRefund")}</p>
      </div>

      {openActions.length > 0 && <div className="cf-shop-block" data-testid="cf-finance-open">
        <h3>{t("cf.fin.openTitle")}</h3>
        <ul className="cf-finance-list">
          {openActions.map((item) => <li key={item.idempotencyKey}>
            <span>{actionLabel(item.action)} · {name(item.employeeId)} · {when(item.requestedAt)}</span>
            {role === "finance" && <button type="button" className="button button-secondary" disabled={busy} onClick={() => onReconcile(item.idempotencyKey)} data-testid="cf-finance-reconcile">{t("cf.fin.check")}</button>}
          </li>)}
        </ul>
      </div>}

      {pending
        ? <ConfirmStep pending={pending} busy={busy} onConfirm={onConfirm} onCancel={onCancel} />
        : <FinanceActions view={view} busy={busy} allowed={allowed} money={money} name={name} onPrepare={onPrepare} />}

      {ownPendingEntries(view).length > 0 && <Notice tone="info" role="note" testId="cf-finance-own-pending">{t("cf.fin.ownPending")}</Notice>}

      <div className="cf-shop-block">
        <h3>{t("cf.fin.timelineTitle")}</h3>
        {summary.entries.length === 0
          ? <p className="dashboard-footnote">{t("cf.fin.noEntries")}</p>
          : <ul className="cf-finance-list" data-testid="cf-finance-entries">
            {summary.entries.map((entry) => <li key={entry.id} data-testid={`cf-finance-entry-${entry.state}`}>
              <span><strong>{entryLabel(entry)}</strong> · {money(entry.amount)}{entry.reference ? ` · ${t("cf.fin.reference")}: ${entry.reference}` : ""}{entry.valueDate ? ` · ${entry.valueDate}` : ""}</span>
              <span className="dashboard-footnote">{entryStateLabel(entry.state)} · {t("cf.fin.by", { name: name(entry.submittedBy), time: when(entry.submittedAt) })}{entry.decidedBy && entry.kind !== "liability_ack" ? ` · ${t("cf.fin.decidedBy", { name: name(entry.decidedBy), time: when(entry.decidedAt) })}` : ""}{entry.state === "rejected" && entry.decisionCode ? ` · ${rejectReasonLabel(entry.decisionCode)}` : ""}</span>
            </li>)}
          </ul>}
      </div>
    </div>
  );
}

function FinanceActions({ view, busy, allowed, money, name, onPrepare }: {
  view: FinanceView; busy: boolean; allowed: (action: FinanceAction) => boolean; money: (amount: string | null) => string; name: (id: string | null) => string; onPrepare: (value: Pending) => void;
}) {
  const { t } = useI18n();
  const { summary } = view;
  const revision = summary.revision;
  const [amount, setAmount] = useState("");
  const [reference, setReference] = useState("");
  const [valueDate, setValueDate] = useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState<TransferRejectReason>("not_received");
  const [problem, setProblem] = useState<string | null>(null);
  const transfer = allowed("submit_transfer");
  const refund = allowed("submit_refund");
  if (view.allowedActions.length === 0) return null;

  const evidence = (kind: "transfer" | "refund") => {
    const normalized = normalizeAmount(amount);
    if (normalized === null) { setProblem(t("cf.fin.invalidAmount")); return; }
    if (!validReference(reference)) { setProblem(t("cf.fin.invalidReference")); return; }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(valueDate)) { setProblem(t("cf.fin.invalidDate")); return; }
    setProblem(null);
    if (kind === "transfer") {
      const purpose = summary.order.cod ? "advance" : "additional_payment";
      onPrepare({ action: "submit_transfer", input: { expectedRevision: revision, purpose, amount: normalized, reference: reference.trim(), valueDate }, key: createIdempotencyKey(),
        summary: t("cf.fin.confirmTransfer", { amount: money(normalized), reference: reference.trim(), date: valueDate, purpose: t(purpose === "advance" ? "cf.fin.entry.advance" : "cf.fin.entry.additional_payment") }) });
    } else {
      onPrepare({ action: "submit_refund", input: { expectedRevision: revision, amount: normalized, reference: reference.trim(), valueDate }, key: createIdempotencyKey(),
        summary: t("cf.fin.confirmRefund", { amount: money(normalized), reference: reference.trim(), date: valueDate }) });
    }
  };
  const decide = (entry: FinanceEntry, decision: "verify" | "reject") => {
    const action: FinanceAction = entry.kind === "refund_out" ? "decide_refund" : "decide_transfer";
    const input: DecisionInput = decision === "verify" ? { expectedRevision: revision, entryId: entry.id, decision } : { expectedRevision: revision, entryId: entry.id, decision, reasonCode: reason };
    onPrepare({ action, input, key: createIdempotencyKey(), summary: t(decision === "verify" ? (entry.kind === "refund_out" ? "cf.fin.confirmVerifyRefund" : "cf.fin.confirmVerify") : "cf.fin.confirmReject", {
      amount: money(entry.amount), reference: entry.reference, name: name(entry.submittedBy), reason: rejectReasonLabel(reason),
    }) });
  };

  return (
    <div className="cf-shop-block" data-testid="cf-finance-actions">
      <h3>{t("cf.fin.actionsTitle")}</h3>
      {(["transfer_in", "refund_out"] as const).flatMap((kind) => (allowed(kind === "transfer_in" ? "decide_transfer" : "decide_refund") ? verifiableEntries(view, kind) : [])).map((entry) => (
        <div key={entry.id} className="cf-finance-decision" data-testid="cf-finance-decide">
          <p><strong>{entryLabel(entry)}</strong> · {money(entry.amount)} · {t("cf.fin.reference")}: {entry.reference} · {entry.valueDate}</p>
          <p className="dashboard-footnote">{t("cf.fin.recordedBy", { name: name(entry.submittedBy), time: when(entry.submittedAt) })}</p>
          <p className="dashboard-footnote">{t(entry.kind === "refund_out" ? "cf.fin.verifyRefundHint" : "cf.fin.verifyHint")}</p>
          <div className="cf-shop-actions">
            <button type="button" className="button button-primary" disabled={busy} onClick={() => decide(entry, "verify")} data-testid="cf-finance-verify">{t("cf.fin.verify")}</button>
            <label className="field-inline">{t("cf.fin.rejectReason")}
              <select value={reason} onChange={(event) => setReason(event.target.value as TransferRejectReason)} data-testid="cf-finance-reject-reason">
                {TRANSFER_REJECT_REASONS.map((code) => <option key={code} value={code}>{rejectReasonLabel(code)}</option>)}
              </select>
            </label>
            <button type="button" className="button button-secondary" disabled={busy} onClick={() => decide(entry, "reject")} data-testid="cf-finance-reject">{t("cf.fin.reject")}</button>
          </div>
        </div>
      ))}
      {allowed("acknowledge_liability") && <div className="cf-finance-decision">
        <p>{t("cf.fin.acknowledgeHint", { amount: money(summary.balance.refundLiability) })}</p>
        <button type="button" className="button button-primary" disabled={busy} data-testid="cf-finance-acknowledge"
          onClick={() => onPrepare({ action: "acknowledge_liability", input: { expectedRevision: revision }, key: createIdempotencyKey(), summary: t("cf.fin.confirmAcknowledge", { amount: money(summary.balance.refundLiability) }) })}>{t("cf.fin.acknowledge")}</button>
      </div>}
      {(transfer || refund) && <form className="cf-finance-form" onSubmit={(event) => { event.preventDefault(); evidence(transfer ? "transfer" : "refund"); }} data-testid={transfer ? "cf-finance-transfer-form" : "cf-finance-refund-form"}>
        <p>{t(transfer ? (summary.order.cod ? "cf.fin.transferAdvanceHint" : "cf.fin.transferHint") : "cf.fin.refundHint")}</p>
        <label>{t("cf.fin.amount")}<input inputMode="decimal" autoComplete="off" value={amount} onChange={(event) => setAmount(event.target.value)} data-testid="cf-finance-amount" /></label>
        <label>{t("cf.fin.reference")}<input autoComplete="off" maxLength={64} value={reference} onChange={(event) => setReference(event.target.value)} data-testid="cf-finance-reference" /></label>
        <label>{t("cf.fin.valueDate")}<input type="date" value={valueDate} onChange={(event) => setValueDate(event.target.value)} data-testid="cf-finance-date" /></label>
        {problem && <Notice tone="danger" role="alert" testId="cf-finance-problem">{problem}</Notice>}
        <button type="submit" className="button button-primary" disabled={busy} data-testid="cf-finance-prepare">{t(transfer ? "cf.fin.recordTransfer" : "cf.fin.recordRefund")}</button>
      </form>}
    </div>
  );
}

function ConfirmStep({ pending, busy, onConfirm, onCancel }: { pending: Pending; busy: boolean; onConfirm: (value: Pending) => void; onCancel: () => void }) {
  const { t } = useI18n();
  return (
    <div className="cf-shop-block cf-finance-confirm" role="group" aria-labelledby="cf-finance-confirm-title" data-testid="cf-finance-confirm">
      <h3 id="cf-finance-confirm-title">{t("cf.fin.confirmTitle")}</h3>
      <p>{pending.summary}</p>
      <div className="cf-shop-actions">
        <button type="button" className="button button-primary" disabled={busy} onClick={() => onConfirm(pending)} data-testid="cf-finance-send">{busy ? t("common.processing") : t("cf.fin.send")}</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={onCancel} data-testid="cf-finance-cancel">{t("cf.fin.cancel")}</button>
      </div>
    </div>
  );
}
