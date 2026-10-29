import { useMemo, useRef, useState, type FormEvent } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, StaffServiceError } from "../../domain/models";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { financeScopesOf, formatFinanceMoney, normalizeAmount, validReference } from "../../domain/confirmareFinance";
import {
  CLARIFICATION_REASONS, INBOX_CATEGORIES, NOT_RECEIVED_REASONS, OVERSIGHT_GROUPS,
  canOverseeFinance, canUseFinanceInbox, financeLegacyPath, financeOrderPath, financeTaskPath, validOrderNumber, maskIban, oversightPath, policyLabel, proformaLabel, reasonLabel, requestErrorKey, stateLabel, taskCodeLabel, taskOrderId,
  type ClarificationReason, type DecisionAnswer, type InboxCategory, type InboxItem, type NotReceivedReason, type OversightFilters, type OversightGroup, type RequestState, type Transfer,
} from "../../domain/financeRequests";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { SourceFilter, when } from "./ConfirmareParts";
import { ConfirmareFinancePanel } from "./ConfirmareFinancePanel";
import { FinanceRequestCard } from "./FinanceRequestCard";

/**
 * The finance employee's payment checks (Staff 2.41.0, API 2.50.0): only the requests assigned to them, a minimized
 * task screen and an explicit confirmation before every decision. The supervisor's oversight reads, never acts. No
 * screen here shows the customer, the products or the order's history; every route checks the scopes again.
 */

const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;
const stateTone: Readonly<Record<RequestState, Tone>> = {
  requested: "neutral", assigned: "accent", checking: "warning", verified: "success", not_received: "danger", needs_clarification: "warning", exception: "danger", cancelled: "neutral",
  requires_manual_review: "warning", resolved: "neutral",
};
const SOURCES: readonly ConfirmareSource[] = ["trendhome", "outletperdele"];

function useErrorText() {
  const { t } = useI18n();
  return (caught: unknown): string => {
    const error = toServiceError(caught);
    const key = requestErrorKey(backendCode(error));
    return key ? t(key) : getErrorPresentation(error).message;
  };
}

// ---- Verificări de plată ---------------------------------------------------------------------------------------

export function FinanceInboxScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  const [category, setCategory] = useState<InboxCategory>("todo");
  const load = useMemo(() => (signal: AbortSignal) => service.financeInbox(signal), [service]);
  const inbox = useAutoRefresh(load, "finance-inbox");
  const data = inbox.data;
  const items = data ? data.items.filter((item) => item.category === category) : null;

  return (
    <article className="screen-stack confirmare-screen fr-inbox" data-testid="fr-inbox">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("fr.inbox.title")} description={t("fr.inbox.lead")} />
      <AutoRefreshBar onRefresh={inbox.refresh} busy={inbox.busy} refreshing={inbox.refreshing} updatedAt={inbox.updatedAt} stale={data !== null && inbox.error !== null} stopped={inbox.stopped} testId="fr-inbox-refresh" />
      <div className="segmented cf-tabs" role="tablist" aria-label={t("fr.inbox.categories")} data-testid="fr-categories">
        {INBOX_CATEGORIES.map((id) => <button key={id} type="button" role="tab" aria-selected={category === id} onClick={() => setCategory(id)} data-testid={`fr-category-${id}`}>
          {t(`fr.category.${id}` as MessageKey)}{data && <span>{data.counts[id]}</span>}
        </button>)}
      </div>
      {inbox.error && (!data || inbox.stopped) && <p className="order-notice order-notice-muted" role="alert">{errorText(inbox.error)}</p>}
      {!data && !inbox.error && <QueueSkeleton />}
      {items && items.length === 0 && <EmptyPanel icon="check" title={t(`fr.empty.${category}` as MessageKey)}>{t("fr.empty.hint")}</EmptyPanel>}
      {items && items.length > 0 && <ul className="queue-list fr-cards" aria-label={t(`fr.category.${category}` as MessageKey)}>
        {items.map((item) => <InboxCard key={item.id} item={item} navigate={navigate} />)}
      </ul>}
      {data && data.items.length >= data.limit && <p className="dashboard-footnote">{t("fr.inbox.limit")}</p>}
      <div className="dashboard-actions">
        {canOverseeFinance(employee) && <button type="button" className="button button-secondary" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight"><AppIcon name="team" size={20} />{t("fr.oversight.open")}</button>}
      </div>
    </article>
  );
}

function InboxCard({ item, navigate }: { item: InboxItem; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const action = item.category !== "done" && (item.kind === "verify" || item.kind === "second_check" || item.kind === "exception");
  // An exceptional money task is worked in the order's money panel (refund, additional payment, reconciliation).
  const target = item.kind === "exception" && item.category !== "done" ? financeOrderPath(taskOrderId(item)) : financeTaskPath(item.id);
  return (
    <li>
      <article className={`fr-card tone-${item.category === "done" ? "neutral" : "warning"}`} data-testid={`fr-card-${item.orderNumber}`}>
        <div className="queue-row-top">
          <strong className="order-id">#{item.orderNumber}</strong>
          <span className="cf-source">{sourceLabel(item.source)}</span>
          <StatusPill tone={stateTone[item.state]} testId={`fr-card-state-${item.orderNumber}`}>{item.kind === "verify" ? stateLabel(item.state) : t(`fr.kind.${item.kind}` as MessageKey)}</StatusPill>
        </div>
        <dl className="authority-facts fr-facts">
          {item.proforma && <div><dt>{t("fr.fact.proforma")}</dt><dd className="mono">{proformaLabel(item.proforma)}</dd></div>}
          {item.taskCode && <div><dt>{t("fr.fact.task")}</dt><dd>{taskCodeLabel(item.taskCode)}</dd></div>}
          <div><dt>{t("fr.fact.requestedAt")}</dt><dd>{when(item.requestedAt)}</dd></div>
          {item.ledger === "manual" && <div><dt>{t("fr.fact.workflow")}</dt><dd data-testid={`fr-card-manual-${item.orderNumber}`}>{t("fr.ledger.manual")}</dd></div>}
          {item.reasonCode && <div><dt>{t("fr.fact.reason")}</dt><dd>{reasonLabel(item.reasonCode)}</dd></div>}
        </dl>
        <button type="button" className={`button ${action ? "button-primary" : "button-secondary"}`} onClick={() => navigate(target)} data-testid={`fr-open-${item.orderNumber}`}>
          {action ? t(item.kind === "second_check" ? "fr.card.secondCheck" : item.kind === "exception" ? "fr.card.exception" : "fr.card.verify") : t("fr.card.open")}<AppIcon name="arrow" size={18} />
        </button>
      </article>
    </li>
  );
}

// ---- Verificarea încasării -------------------------------------------------------------------------------------

type Pending =
  | { kind: "verified"; input: { amount: string; reference: string; valueDate: string; paymentReference: string; currency: string } }
  | { kind: "not_received"; reason: NotReceivedReason }
  | { kind: "needs_clarification"; reason: ClarificationReason }
  | { kind: "second_verify" }
  | { kind: "second_reject"; reason: NotReceivedReason };

export function FinanceTaskScreen({ requestId, service, navigate }: { requestId: string; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  // The task is read when opened and after every command; never refreshed behind the employee's back (money on screen).
  const load = useMemo(() => (signal: AbortSignal) => service.financeTask(requestId, signal), [service, requestId]);
  const view = useAutoRefresh(load, `finance-task|${requestId}`, 10 * 60 * 1000);
  const task = view.data;
  const [mode, setMode] = useState<"verified" | "not_received" | "needs_clarification" | "second_reject" | null>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [receipt, setReceipt] = useState<DecisionAnswer | null>(null);
  const intent = useRef<{ id: string; key: string } | null>(null);
  const keyFor = (id: string) => { if (intent.current?.id !== id) intent.current = { id, key: createIdempotencyKey() }; return intent.current.key; };

  async function send(id: string, command: (key: string) => Promise<DecisionAnswer>, receiptShown = true) {
    setBusy(true); setError(null);
    try {
      const answer = await command(keyFor(id));
      intent.current = null;
      // Starting the check is no decision: the task stays open with its decisions (read again below), no receipt.
      if (receiptShown) setReceipt(answer);
      setPending(null); setMode(null);
    } catch (caught) {
      setError(errorText(caught));
      const code = backendCode(toServiceError(caught));
      if (code === "FINANCE_REQUEST_STALE" || code === "FINANCE_REQUEST_STATE" || code === "FINANCE_REQUEST_PROFORMA_CHANGED") { intent.current = null; setPending(null); }
    } finally {
      setBusy(false);
      view.refresh();
    }
  }

  if (!task) {
    return <article className="screen-stack confirmare-screen" data-testid="fr-task" aria-busy={!view.error}>
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fr.task.back")}</button>
      {view.error ? <Notice tone="danger" role="alert">{errorText(view.error)}</Notice> : <QueueSkeleton />}
      {view.error && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("common.retry")}</button>}
    </article>;
  }

  const request = task.request;
  const live = task.live;
  const confirm = () => {
    if (!pending) return;
    const base = { expectedVersion: request.version };
    if (pending.kind === "verified" && live.available) void send(`verified|${request.version}`, (key) => service.decideTask(request.id, { ...base, outcome: "verified", expectedRevision: live.revision, ...pending.input }, key));
    if (pending.kind === "not_received") void send(`nr|${request.version}|${pending.reason}`, (key) => service.decideTask(request.id, { ...base, outcome: "not_received", reasonCode: pending.reason }, key));
    if (pending.kind === "needs_clarification") void send(`nc|${request.version}|${pending.reason}`, (key) => service.decideTask(request.id, { ...base, outcome: "needs_clarification", reasonCode: pending.reason }, key));
    if (pending.kind === "second_verify" && live.available) void send(`sv|${request.version}`, (key) => service.secondCheck(request.id, { ...base, expectedRevision: live.revision, decision: "verify" }, key));
    if (pending.kind === "second_reject" && live.available) void send(`sr|${request.version}|${pending.reason}`, (key) => service.secondCheck(request.id, { ...base, expectedRevision: live.revision, decision: "reject", reasonCode: pending.reason }, key));
  };
  const recorded = live.available ? live.transfers.filter((entry) => entry.state === "submitted") : [];

  return (
    <article className="screen-stack confirmare-screen fr-task" data-testid="fr-task" data-state={request.state}>
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fr.task.back")}</button>
      <PageHeader eyebrow={sourceLabel(request.source)} title={t("fr.task.title")} description={t("fr.task.lead", { order: request.orderNumber })} />
      <p className="package-pills"><StatusPill tone={stateTone[request.state]} testId="fr-task-state">{stateLabel(request.state)}</StatusPill><StatusPill icon="lock">{policyLabel(live.available ? live.policy : request.policy)}</StatusPill></p>

      {receipt && <Receipt answer={receipt} onBack={() => navigate("/confirmare/finance/requests")} />}
      {error && <Notice tone="danger" role="alert" testId="fr-task-error">{error}</Notice>}
      {request.ledger === "manual" && <Notice tone="info" role="note" testId="fr-task-manual">{t("fr.task.manualLedger")}</Notice>}
      {request.purpose === "exception" && <Notice tone="warning" role="note" testId="fr-task-exception">{t("fr.task.exception", { task: taskCodeLabel(request.taskCode) })}
        {request.category !== "done" && <button type="button" className="button button-link" onClick={() => navigate(financeOrderPath(taskOrderId(request)))} data-testid="fr-open-panel">{t("fr.card.exception")}</button>}</Notice>}

      <section className="detail-section" aria-labelledby="fr-task-money" data-testid="fr-task-money">
        <h2 id="fr-task-money">{t("fr.task.money")}</h2>
        {!live.available
          ? <Notice tone="warning" role="note" testId="fr-live-unavailable">{t(live.reason === "not_configured" ? "fr.task.notConfigured" : "fr.task.unavailable")}</Notice>
          : <>
            <dl className="authority-facts fr-facts">
              <div><dt>{t("fr.fact.proforma")}</dt><dd className="mono">{live.proforma ? proformaLabel({ series: live.proforma.series || null, number: live.proforma.number }) : proformaLabel(request.proforma)}</dd></div>
              <div><dt>{t("fr.fact.proformaAmount")}</dt><dd>{live.proforma?.amount ? formatFinanceMoney(live.proforma.amount, live.currency) : "—"}</dd></div>
              {live.advance && <div><dt>{t("fr.fact.advanceRequired", { percentage: live.advance.percentage })}</dt><dd>{formatFinanceMoney(live.advance.required, live.currency)}</dd></div>}
              {live.advance && <div><dt>{t("fr.fact.alreadyVerified")}</dt><dd>{formatFinanceMoney(live.advance.verified, live.currency)}</dd></div>}
              {live.advance && <div><dt>{t("fr.fact.remaining")}</dt><dd data-testid="fr-remaining"><strong>{formatFinanceMoney(live.advance.remaining, live.currency)}</strong></dd></div>}
              <div><dt>{t("fr.fact.reference")}</dt><dd className="mono" data-testid="fr-payment-reference"><strong>{live.paymentReference ?? "—"}</strong></dd></div>
              {live.bank && <div><dt>{t("fr.fact.account")}</dt><dd>{live.bank.beneficiary} · {live.bank.bank} · <span className="mono">{maskIban(live.bank.ibanMasked)}</span></dd></div>}
              <div><dt>{t("fr.fact.readAt")}</dt><dd>{when(live.readAt)}</dd></div>
            </dl>
            {live.proformaChanged && <Notice tone="danger" role="alert" testId="fr-proforma-changed">{t("fr.task.proformaChanged")}</Notice>}
            {live.settledElsewhere && <Notice tone="info" role="note" testId="fr-settled">{t("fr.task.settledElsewhere")}</Notice>}
            {live.financial.attention && <Notice tone="warning" role="note" testId="fr-attention">{t("fr.task.attention")}</Notice>}
            {live.transfers.length > 0 && <TransferList transfers={live.transfers} currency={live.currency} />}
          </>}
      </section>

      {!receipt && <section className="detail-section fr-actions" aria-labelledby="fr-task-actions" data-testid="fr-task-actions">
        <h2 id="fr-task-actions">{t("fr.task.actions")}</h2>
        {task.allowed.start && <div className="cfw-actions">
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => void send(`start|${request.version}`, (key) => service.startTask(request.id, { expectedVersion: request.version }, key), false)} data-testid="fr-start">{t("fr.action.start")}</button>
          <small>{t("fr.action.startHint")}</small>
        </div>}
        {!pending && <div className="cfw-actions">
          {task.allowed.verify && <button type="button" className="button button-primary" disabled={busy} onClick={() => setMode("verified")} data-testid="fr-mode-verified">{t("fr.action.verified")}</button>}
          {task.allowed.notReceived && <button type="button" className="button button-secondary" disabled={busy} onClick={() => setMode("not_received")} data-testid="fr-mode-not-received">{t("fr.action.notReceived")}</button>}
          {task.allowed.clarify && <button type="button" className="button button-secondary" disabled={busy} onClick={() => setMode("needs_clarification")} data-testid="fr-mode-clarify">{t("fr.action.clarify")}</button>}
          {task.allowed.secondCheck && <>
            <button type="button" className="button button-primary" disabled={busy} onClick={() => setPending({ kind: "second_verify" })} data-testid="fr-second-verify">{t("fr.action.secondVerify")}</button>
            <button type="button" className="button button-secondary" disabled={busy} onClick={() => setMode("second_reject")} data-testid="fr-mode-second-reject">{t("fr.action.secondReject")}</button>
          </>}
          {task.allowed.reconcile && <button type="button" className="button button-primary" disabled={busy} onClick={() => void send(`reconcile|${request.version}`, () => service.reconcileTask(request.id))} data-testid="fr-reconcile">{t("fr.action.reconcile")}</button>}
        </div>}
        {task.allowed.secondCheck && recorded.length > 0 && <p className="dashboard-lead" data-testid="fr-second-hint">{t("fr.action.secondHint")}</p>}
        {request.kind === "awaiting_second_check" && <Notice tone="info" role="note" testId="fr-awaiting-second">{t("fr.task.awaitingSecond")}</Notice>}
        {!task.allowed.verify && !task.allowed.notReceived && !task.allowed.clarify && !task.allowed.secondCheck && !task.allowed.reconcile && !task.allowed.start
          && <p className="dashboard-lead" data-testid="fr-no-action">{t(request.category === "done" ? "fr.task.closed" : "fr.task.nothingNow")}</p>}

        {mode === "verified" && !pending && live.available && <VerifyForm currency={live.currency} reference={live.paymentReference} remaining={live.advance?.remaining ?? null} single={task.singleVerification} busy={busy}
          onCancel={() => setMode(null)} onReady={(input) => setPending({ kind: "verified", input })} />}
        {(mode === "not_received" || mode === "second_reject") && !pending && <ReasonForm reasons={NOT_RECEIVED_REASONS} busy={busy} onCancel={() => setMode(null)}
          onReady={(reason) => setPending(mode === "second_reject" ? { kind: "second_reject", reason: reason as NotReceivedReason } : { kind: "not_received", reason: reason as NotReceivedReason })} />}
        {mode === "needs_clarification" && !pending && <ReasonForm reasons={CLARIFICATION_REASONS} busy={busy} onCancel={() => setMode(null)} onReady={(reason) => setPending({ kind: "needs_clarification", reason: reason as ClarificationReason })} />}

        {pending && <div className="cf-confirm" role="group" aria-labelledby="fr-confirm-title" data-testid="fr-confirm">
          <p className="eyebrow" id="fr-confirm-title">{t("fr.confirm.title")}</p>
          <p data-testid="fr-confirm-text">{confirmText(pending, live.available ? live.currency : "RON", task.singleVerification, request.ledger === "manual", t, live.available ? live.advance?.remaining ?? null : null)}</p>
          <div className="cfw-actions">
            <button type="button" className="button button-primary" disabled={busy} onClick={confirm} data-testid="fr-confirm-send">{busy ? t("common.sending") : t("fr.confirm.send")}</button>
            <button type="button" className="button button-secondary" disabled={busy} onClick={() => setPending(null)} data-testid="fr-confirm-back">{t("fr.confirm.back")}</button>
          </div>
        </div>}
      </section>}
    </article>
  );
}

/** Whole bani of a decimal amount ("190.00" -> 19000), for comparing the statement with the advance left. */
const bani = (amount: string): number => Math.round(Number(amount) * 100);

function confirmText(pending: Pending, currency: string, single: boolean, manual: boolean, t: ReturnType<typeof useI18n>["t"], remaining: string | null = null): string {
  switch (pending.kind) {
    case "verified": {
      const text = t(manual ? "fr.confirm.verifiedManual" : single ? "fr.confirm.verifiedSingle" : "fr.confirm.verifiedFourEyes", { amount: formatFinanceMoney(pending.input.amount, currency), reference: pending.input.reference, date: pending.input.valueDate });
      // Owner 2026-10-29: a smaller amount is recorded as it is; the operator decides on the difference.
      return remaining !== null && bani(pending.input.amount) < bani(remaining)
        ? `${text} ${t("fr.confirm.partial", { remaining: formatFinanceMoney(remaining, currency), gap: formatFinanceMoney(((bani(remaining) - bani(pending.input.amount)) / 100).toFixed(2), currency) })}` : text;
    }
    case "not_received": return t("fr.confirm.notReceived", { reason: reasonLabel(pending.reason) });
    case "needs_clarification": return t("fr.confirm.clarify", { reason: reasonLabel(pending.reason) });
    case "second_verify": return t("fr.confirm.secondVerify");
    default: return t("fr.confirm.secondReject", { reason: reasonLabel(pending.reason) });
  }
}

function VerifyForm({ currency, reference, remaining, single, busy, onCancel, onReady }: { currency: string; reference: string | null; remaining: string | null; single: boolean; busy: boolean; onCancel: () => void; onReady: (input: { amount: string; reference: string; valueDate: string; paymentReference: string; currency: string }) => void }) {
  const { t } = useI18n();
  const [amount, setAmount] = useState("");
  const [bankReference, setBankReference] = useState("");
  const [valueDate, setValueDate] = useState("");
  const [paymentReference, setPaymentReference] = useState("");
  const [statement, setStatement] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const typed = normalizeAmount(amount);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const normalized = normalizeAmount(amount);
    if (!normalized) return setProblem(t("fr.form.amountInvalid"));
    // More than the advance left is never recorded through a task (the shop refuses it too): a clarification.
    if (remaining !== null && bani(normalized) > bani(remaining)) return setProblem(t("fr.form.overRemaining", { amount: formatFinanceMoney(normalized, currency), remaining: formatFinanceMoney(remaining, currency) }));
    if (!validReference(bankReference)) return setProblem(t("fr.form.referenceInvalid"));
    if (!/^\d{4}-\d{2}-\d{2}$/.test(valueDate)) return setProblem(t("fr.form.dateInvalid"));
    const typed = paymentReference.trim().toUpperCase();
    if (!typed) return setProblem(t("fr.form.paymentReferenceMissing"));
    // The statement must show the proforma's own payment reference; a different one is a clarification, never a guess.
    if (reference && typed !== reference.toUpperCase()) return setProblem(t("fr.form.paymentReferenceMismatch"));
    if (!statement) return setProblem(t("fr.form.statementRequired"));
    setProblem(null);
    onReady({ amount: normalized, reference: bankReference.trim(), valueDate, paymentReference: typed, currency });
  };
  return (
    <form className="cf-form fr-form" onSubmit={submit} data-testid="fr-verify-form" noValidate>
      <p className="dashboard-lead">{t(single ? "fr.form.leadSingle" : "fr.form.leadFourEyes")}</p>
      <label className="field"><span>{t("fr.form.amount", { currency })}</span><input inputMode="decimal" autoComplete="off" value={amount} onChange={(event) => setAmount(event.target.value)} data-testid="fr-amount" /></label>
      {typed && remaining !== null && bani(typed) > 0 && bani(typed) < bani(remaining) && <Notice tone="info" role="status" testId="fr-partial-notice">{t("fr.form.partial", {
        amount: formatFinanceMoney(typed, currency), remaining: formatFinanceMoney(remaining, currency), gap: formatFinanceMoney(((bani(remaining) - bani(typed)) / 100).toFixed(2), currency) })}</Notice>}
      <label className="field"><span>{t("fr.form.bankReference")}</span><input autoComplete="off" maxLength={64} value={bankReference} onChange={(event) => setBankReference(event.target.value)} data-testid="fr-bank-reference" /></label>
      <label className="field"><span>{t("fr.form.valueDate")}</span><input type="date" value={valueDate} onChange={(event) => setValueDate(event.target.value)} data-testid="fr-value-date" /></label>
      <label className="field"><span>{t("fr.form.paymentReference")}</span><input autoComplete="off" maxLength={64} value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} data-testid="fr-statement-reference" /></label>
      <label className="check-field"><input type="checkbox" checked={statement} onChange={(event) => setStatement(event.target.checked)} data-testid="fr-statement" /><span className="cf-check">{t("fr.form.statement")}<small>{t("fr.form.statementHint")}</small></span></label>
      {problem && <Notice tone="danger" role="alert" testId="fr-form-problem">{problem}</Notice>}
      <div className="cfw-actions">
        <button type="submit" className="button button-primary" disabled={busy} data-testid="fr-verify-next">{t("fr.form.next")}</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={onCancel}>{t("common.cancel")}</button>
      </div>
    </form>
  );
}

function ReasonForm({ reasons, busy, onCancel, onReady }: { reasons: readonly string[]; busy: boolean; onCancel: () => void; onReady: (reason: string) => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState("");
  return (
    <form className="cf-form fr-form" onSubmit={(event) => { event.preventDefault(); if (reason) onReady(reason); }} data-testid="fr-reason-form">
      <fieldset className="cf-outcomes">
        <legend>{t("fr.form.reason")}</legend>
        {reasons.map((value) => <label key={value} className="check-field"><input type="radio" name="fr-reason" value={value} checked={reason === value} onChange={() => setReason(value)} data-testid={`fr-reason-${value}`} /><span className="cf-check">{reasonLabel(value)}</span></label>)}
      </fieldset>
      <div className="cfw-actions">
        <button type="submit" className="button button-primary" disabled={busy || !reason} data-testid="fr-reason-next">{t("fr.form.next")}</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={onCancel}>{t("common.cancel")}</button>
      </div>
    </form>
  );
}

function Receipt({ answer, onBack }: { answer: DecisionAnswer; onBack: () => void }) {
  const { t } = useI18n();
  const receipt = answer.receipt;
  const key: MessageKey = receipt.unknown ? "fr.receipt.unknown"
    : receipt.state === "requires_manual_review" ? "fr.receipt.manualReview"
    : receipt.state === "verified" ? (receipt.outcome === "verified_partial" ? "fr.receipt.verifiedPartial" : "fr.receipt.verified")
      : receipt.awaitingSecondCheck ? "fr.receipt.awaitingSecond"
        : receipt.state === "not_received" ? "fr.receipt.notReceived"
          : receipt.state === "needs_clarification" ? "fr.receipt.clarification"
            : receipt.state === "checking" ? "fr.receipt.checking" : "fr.receipt.recorded";
  return (
    <section className="detail-section fr-receipt" aria-labelledby="fr-receipt-title" data-testid="fr-receipt" data-state={receipt.state}>
      <p className="eyebrow" id="fr-receipt-title">{t("fr.receipt.title")}</p>
      <Notice tone={receipt.unknown || receipt.state === "requires_manual_review" ? "warning" : receipt.state === "not_received" ? "danger" : "success"} role="status">{t(key, { order: answer.request.orderNumber })}</Notice>
      <dl className="authority-facts fr-facts">
        <div><dt>{t("fr.fact.state")}</dt><dd>{stateLabel(receipt.state)}</dd></div>
        {receipt.reasonCode && <div><dt>{t("fr.fact.reason")}</dt><dd>{reasonLabel(receipt.reasonCode)}</dd></div>}
        <div><dt>{t("fr.fact.policy")}</dt><dd>{policyLabel(receipt.policy)}</dd></div>
        <div><dt>{t("fr.fact.at")}</dt><dd>{when(receipt.at)}</dd></div>
      </dl>
      <button type="button" className="button button-primary" onClick={onBack} data-testid="fr-receipt-back">{t("fr.receipt.back")}</button>
    </section>
  );
}

function TransferList({ transfers, currency }: { transfers: Transfer[]; currency: string }) {
  const { t } = useI18n();
  return (
    <div className="fr-transfers" data-testid="fr-transfers">
      <h3>{t("fr.transfers.title")}</h3>
      <ul className="activity-list">{transfers.map((entry) => <li key={entry.id} data-testid={`fr-transfer-${entry.state}`}>
        <strong>{formatFinanceMoney(entry.amount, currency)}</strong> · <span className="mono">{entry.reference}</span> · {entry.valueDate ?? "—"}
        <small>{t(`fr.transfers.${entry.state}` as MessageKey, { by: entry.submittedBy.displayName ?? "—", decider: entry.decidedBy?.displayName ?? "—" })}</small>
      </li>)}</ul>
    </div>
  );
}

// ---- Activitatea contabilității (read only) --------------------------------------------------------------------

export function FinanceOversightScreen({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  const [filters, setFilters] = useState<OversightFilters>({});
  const key = JSON.stringify(filters);
  const load = useMemo(() => (signal: AbortSignal) => service.financeOversight(filters, signal).then((value) => ({ ...value, key })), [service, filters, key]);
  const view = useAutoRefresh(load, `finance-oversight|${key}`);
  const data = view.data?.key === key ? view.data : null;
  const set = (patch: OversightFilters) => setFilters((current) => ({ ...current, ...patch }));

  return (
    <article className="screen-stack confirmare-screen fr-oversight" data-testid="fr-oversight">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("fr.oversight.title")} description={t("fr.oversight.lead")} />
      <Notice tone="info" role="note" testId="fr-oversight-readonly">{t("fr.oversight.readOnly")}</Notice>
      <AutoRefreshBar onRefresh={view.refresh} busy={view.busy} refreshing={view.refreshing} updatedAt={view.updatedAt} stale={data !== null && view.error !== null} stopped={view.stopped} testId="fr-oversight-refresh" />
      <SourceFilter sources={SOURCES} value={filters.source ?? null} onChange={(source) => set({ source })} />
      <div className="fr-filters">
        {data && data.employees.length > 1 && <label className="field"><span>{t("fr.oversight.employee")}</span>
          <select value={filters.employee ?? ""} onChange={(event) => set({ employee: event.target.value || null })} data-testid="fr-filter-employee">
            <option value="">{t("fr.oversight.everyone")}</option>
            {data.employees.map((person) => <option key={person.id} value={person.id}>{person.displayName}</option>)}
          </select></label>}
        <label className="field"><span>{t("fr.oversight.from")}</span><input type="date" value={filters.from ?? ""} onChange={(event) => set({ from: event.target.value || null })} data-testid="fr-filter-from" /></label>
        <label className="field"><span>{t("fr.oversight.to")}</span><input type="date" value={filters.to ?? ""} onChange={(event) => set({ to: event.target.value || null })} data-testid="fr-filter-to" /></label>
      </div>
      <div className="segmented cf-tabs cf-task-strip" role="tablist" aria-label={t("fr.oversight.states")} data-testid="fr-oversight-states">
        <button type="button" role="tab" aria-selected={!filters.state} onClick={() => set({ state: null })}>{t("fr.oversight.all")}</button>
        {OVERSIGHT_GROUPS.map((group) => <button key={group} type="button" role="tab" aria-selected={filters.state === group} onClick={() => set({ state: group as OversightGroup })} data-testid={`fr-oversight-state-${group}`}>
          {t(`fr.group.${group}` as MessageKey)}{data && <span>{data.counts[group]}</span>}
        </button>)}
      </div>
      {view.error && (!data || view.stopped) && <p className="order-notice order-notice-muted" role="alert">{errorText(view.error)}</p>}
      {!data && !view.error && <QueueSkeleton />}
      {data && (data.items.length === 0
        ? <EmptyPanel icon="team" title={t("fr.oversight.empty")} />
        : <ul className="queue-list" aria-label={t("fr.oversight.title")}>{data.items.map((item) => <li key={item.id}>
          <button type="button" className={`queue-row cf-row tone-${item.state === "needs_clarification" || item.state === "exception" ? "warning" : "neutral"}`} onClick={() => navigate(oversightPath(item.id))} data-testid={`fr-oversight-row-${item.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top"><strong className="order-id">#{item.orderNumber}</strong><span className="cf-source">{sourceLabel(item.source)}</span></span>
              <small>{proformaLabel(item.proforma)} · {policyLabel(item.policy)}</small>
              <small>{t("fr.oversight.people", { assignee: item.assignee?.displayName ?? "—", recorder: item.recordedBy?.displayName ?? "—", decider: item.decidedBy?.displayName ?? "—" })} · {when(item.updatedAt)}</small>
              {item.checker && <small>{t("fr.oversight.checker", { name: item.checker.displayName })}</small>}
              {item.taskCode && <small>{taskCodeLabel(item.taskCode)}</small>}
            </span>
            <span className="queue-chip">{stateLabel(item.state)}</span><AppIcon name="chevron" size={18} />
          </button>
        </li>)}</ul>)}
    </article>
  );
}

export function FinanceOversightDetailScreen({ requestId, service, navigate }: { requestId: string; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  const load = useMemo(() => (signal: AbortSignal) => service.financeOversightDetail(requestId, signal), [service, requestId]);
  const view = useAutoRefresh(load, `finance-oversight-detail|${requestId}`, 10 * 60 * 1000);
  const data = view.data;
  return (
    <article className="screen-stack confirmare-screen fr-oversight-detail" data-testid="fr-oversight-detail">
      <button className="back-link" type="button" onClick={() => navigate(oversightPath())}><AppIcon name="back" size={20} /> {t("fr.oversight.back")}</button>
      {!data ? (view.error ? <Notice tone="danger" role="alert">{errorText(view.error)}</Notice> : <QueueSkeleton />) : <>
        <PageHeader eyebrow={sourceLabel(data.request.source)} title={t("fr.oversight.detailTitle", { order: data.request.orderNumber })} />
        <Notice tone="info" role="note">{t("fr.oversight.readOnly")}</Notice>
        <dl className="authority-facts fr-facts">
          <div><dt>{t("fr.fact.state")}</dt><dd>{stateLabel(data.request.state)}</dd></div>
          <div><dt>{t("fr.fact.proforma")}</dt><dd className="mono">{proformaLabel(data.request.proforma)}</dd></div>
          <div><dt>{t("fr.fact.policy")}</dt><dd>{policyLabel(data.request.policy)}</dd></div>
          <div><dt>{t("fr.fact.assignee")}</dt><dd>{data.request.assignee?.displayName ?? "—"}</dd></div>
          <div><dt>{t("fr.fact.recordedBy")}</dt><dd>{data.request.recordedBy?.displayName ?? "—"}</dd></div>
          {data.request.checker && <div><dt>{t("fr.fact.checker")}</dt><dd>{data.request.checker.displayName}</dd></div>}
          {data.request.taskCode && <div><dt>{t("fr.fact.task")}</dt><dd>{taskCodeLabel(data.request.taskCode)}</dd></div>}
          {data.request.ledger === "manual" && <div><dt>{t("fr.fact.workflow")}</dt><dd>{t("fr.ledger.manual")}</dd></div>}
          <div><dt>{t("fr.fact.decidedBy")}</dt><dd>{data.request.decidedBy?.displayName ?? "—"}</dd></div>
          {data.request.reasonCode && <div><dt>{t("fr.fact.reason")}</dt><dd>{reasonLabel(data.request.reasonCode)}</dd></div>}
        </dl>
        {data.transfers && data.transfers.length > 0 && <TransferList transfers={data.transfers} currency={data.currency ?? "RON"} />}
        <section className="detail-section" aria-labelledby="fr-history"><h2 id="fr-history">{t("fr.oversight.history")}</h2>
          <ol className="activity-list" data-testid="fr-events">{data.events.map((event) => <li key={event.version}>
            <strong>{t(`fr.event.${event.action}` as MessageKey)}</strong> · {event.actor?.displayName ?? t("fr.event.system")} · {when(event.at)}
            {event.reasonCode && <small>{reasonLabel(event.reasonCode)}</small>}
          </li>)}</ol>
        </section>
      </>}
    </article>
  );
}

// ---- An exceptional finance task of one order (no Confirmare detail: the money panel only) ---------------------

export function FinanceOrderScreen({ globalOrderId, employee, service, navigate }: { globalOrderId: string; employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const source = globalOrderId.split(":", 1)[0] ?? "";
  const scopes = financeScopesOf(employee);
  const finance = { configured: true, read: true, record: scopes.record.includes(source), approve: scopes.approve.includes(source) };
  return (
    <article className="screen-stack confirmare-screen fr-order" data-testid="fr-order">
      <button className="back-link" type="button" onClick={() => navigate(canUseFinanceInbox(employee) ? "/confirmare/finance/requests" : "/confirmare/finance")}><AppIcon name="back" size={20} /> {t("fr.order.back")}</button>
      <PageHeader eyebrow={sourceLabel(source)} title={t("fr.order.title", { order: globalOrderId.slice(source.length + 1) })} description={t("fr.order.lead")} />
      <ConfirmareFinancePanel detail={{ globalOrderId, finance }} service={service} />
    </article>
  );
}

// ---- The advance of an order outside Confirmare (operator) --------------------------------------------------------

/**
 * An order of the old workflow (legacy, before Confirmare) submitted from 22 October 2026: the operator sends its advance
 * to finance from here, by the order's number. The screen shows the request only (never the order's customer, products
 * or prices); finance never records its payment in the shop (manual review in the old workflow).
 */
export function FinanceLegacyScreen({ globalOrderId, service, navigate }: { globalOrderId: string; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [source, number] = [globalOrderId.split(":")[0] ?? "", globalOrderId.split(":").slice(1).join(":")];
  return (
    <article className="screen-stack confirmare-screen fr-legacy-order" data-testid="fr-legacy-order">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader eyebrow={sourceLabel(source)} title={t("fr.legacyOrder.title", { order: number })} description={t("fr.legacyOrder.lead")} />
      <FinanceRequestCard globalOrderId={globalOrderId} service={service} canOperate onSettled={() => undefined} />
    </article>
  );
}

/** The operator opens an order of the old workflow by its number (no list of orders). */
export function LegacyOrderLookup({ sources, navigate }: { sources: readonly ConfirmareSource[]; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [source, setSource] = useState<ConfirmareSource>(sources[0] ?? "trendhome");
  const [number, setNumber] = useState("");
  const [problem, setProblem] = useState(false);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const typed = number.trim();
    if (!validOrderNumber(typed)) return setProblem(true);
    navigate(financeLegacyPath(`${source}:${typed}`));
  };
  return (
    <form className="cf-form fr-legacy-lookup" onSubmit={submit} data-testid="fr-legacy-lookup">
      <p className="eyebrow">{t("fr.legacyOrder.lookupTitle")}</p>
      <small>{t("fr.legacyOrder.lookupLead")}</small>
      {sources.length > 1 && <label className="field"><span>{t("fr.legacyOrder.shop")}</span>
        <select value={source} onChange={(event) => setSource(event.target.value as ConfirmareSource)} data-testid="fr-legacy-source">
          {sources.map((value) => <option key={value} value={value}>{sourceLabel(value)}</option>)}
        </select></label>}
      <label className="field"><span>{t("fr.legacyOrder.number")}</span><input inputMode="numeric" autoComplete="off" maxLength={128} value={number} onChange={(event) => { setNumber(event.target.value); setProblem(false); }} data-testid="fr-legacy-number" /></label>
      {problem && <p className="field-error" role="alert">{t("fr.legacyOrder.invalid")}</p>}
      <button type="submit" className="button button-secondary" data-testid="fr-legacy-open">{t("fr.legacyOrder.open")}</button>
    </form>
  );
}
