import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, StaffServiceError } from "../../domain/models";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { formatFinanceMoney } from "../../domain/confirmareFinance";
import {
  CLARIFICATION_REASONS, canOverseeFinance, financeOrderPath, financeTaskPath, maskIban, oversightPath, proformaLabel, reasonLabel, requestErrorKey, stateLabel, taskCodeLabel, taskOrderId,
  type ClarificationReason, type DecisionAnswer, type FinanceTask, type Inbox, type InboxItem,
} from "../../domain/financeRequests";
import {
  answerLost, bani, checkEntry, checkPayment, deskRows, deskSourceFilter, deskable, fromBani, nextDeskTask, noteDeskDone, setDeskSourceFilter, takeDeskDone, waitParts,
  type DeskView, type PaymentForm, type PaymentInput,
} from "../../domain/financeDesk";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { SourceFilter, dueDay } from "./ConfirmareParts";

/**
 * The Contabilitate desk (Staff 2.53.0, owner 2026-11-25): a compact table of the employee's own finance tasks and a
 * three-step payment check, then the next oldest task opens by itself. The commands are the finance task commands of
 * Staff 2.41.0 unchanged (start, decide, reconcile); the server checks every one again. Exceptional tasks (second
 * checks, refunds, additional payments, reconciliations) keep their task screen and open only from their row.
 */

const SOURCES: readonly ConfirmareSource[] = ["trendhome", "outletperdele"];
const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;
/** The detail screen of a task the three steps do not handle (Staff 2.41.0 FinanceTaskScreen). */
export const financeTaskDetailPath = (requestId: string): string => `${financeTaskPath(requestId)}/detail`;

function useErrorText() {
  const { t } = useI18n();
  return (caught: unknown): string => {
    const error = toServiceError(caught);
    const key = requestErrorKey(backendCode(error));
    return key ? t(key) : getErrorPresentation(error).message;
  };
}

function rowTarget(item: InboxItem): string {
  if (deskable(item)) return financeTaskPath(item.id);
  // An exceptional money task is worked in the order's money panel (refund, additional payment, reconciliation).
  if (item.kind === "exception" && item.category !== "done") return financeOrderPath(taskOrderId(item));
  return financeTaskDetailPath(item.id);
}

// ---- The desk ---------------------------------------------------------------------------------------------------

export function FinanceDeskScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  const [view, setView] = useState<DeskView>("todo");
  const [source, setSource] = useState<string | null>(deskSourceFilter());
  const [query, setQuery] = useState("");
  const [finished] = useState(() => takeDeskDone());
  const load = useMemo(() => (signal: AbortSignal) => service.financeInbox(signal), [service]);
  const inbox = useAutoRefresh(load, "finance-desk");
  const data = inbox.data;
  const rows = useMemo(() => (data ? deskRows(data, view, source, query) : null), [data, view, source, query]);
  // The waiting time is measured at the list's own read (no clock read while rendering).
  const now = inbox.updatedAt ? new Date(inbox.updatedAt).getTime() : 0;
  const kpis: { id: DeskView | "today"; tone: Tone; value: number | null }[] = [
    { id: "todo", tone: "accent", value: data?.counts.todo ?? null },
    { id: "waiting", tone: "danger", value: data?.counts.waiting ?? null },
    { id: "clarification", tone: "warning", value: data?.counts.clarification ?? null },
    { id: "today", tone: "success", value: data?.counts.today ?? null },
  ];
  const chooseSource = (next: string | null) => { setSource(next); setDeskSourceFilter(next); };

  return (
    <article className="screen-stack fd-desk" data-testid="fr-inbox">
      <header className="fd-head">
        <div><p className="eyebrow">{t("fd.eyebrow")}</p><h1>{t("fd.title")}</h1></div>
        <button type="button" className="button button-ghost fd-refresh" onClick={inbox.refresh} disabled={inbox.refreshing} aria-label={t("common.retry")} data-testid="fr-inbox-refresh"><AppIcon name="refresh" size={18} /></button>
      </header>
      <div className="fd-kpis" role="group" aria-label={t("fd.kpis")} data-testid="fd-kpis">
        {kpis.map((kpi) => {
          const selectable = kpi.id !== "today";
          const content = <><span className="fd-kpi-value" data-testid={`fd-kpi-${kpi.id}`}>{kpi.value ?? "–"}</span><span className="fd-kpi-label">{t(`fd.kpi.${kpi.id}` as MessageKey)}</span></>;
          return selectable
            ? <button key={kpi.id} type="button" className={`fd-kpi tone-${kpi.tone}`} aria-pressed={view === kpi.id} onClick={() => setView(kpi.id as DeskView)} data-testid={`fr-category-${kpi.id}`}>{content}</button>
            : <div key={kpi.id} className={`fd-kpi tone-${kpi.tone}`}>{content}</div>;
        })}
      </div>
      <div className="fd-tools">
        <label className="fd-search"><AppIcon name="search" size={18} /><span className="visually-hidden">{t("fd.search")}</span>
          <input type="search" inputMode="search" autoComplete="off" placeholder={t("fd.search.placeholder")} value={query} onChange={(event) => setQuery(event.target.value)} data-testid="fd-search" /></label>
        <SourceFilter sources={SOURCES} value={source} onChange={chooseSource} />
      </div>
      {finished && <Notice tone="success" role="status" testId="fd-done">{t("fd.next.done", { order: finished })} {t("fd.next.none")}</Notice>}
      {inbox.error && (!data || inbox.stopped) && <Notice tone="danger" role="alert">{errorText(inbox.error)}</Notice>}
      {!data && !inbox.error && <QueueSkeleton />}
      {rows && rows.length === 0 && <p className="fd-empty" data-testid="fd-empty">{t(query.trim() ? "fd.empty.search" : `fd.empty.${view}` as MessageKey)}</p>}
      {rows && rows.length > 0 && <div className="fd-table" role="table" aria-label={t(`fd.kpi.${view === "done" ? "today" : view}` as MessageKey)} data-testid="fd-table">
        <div className="fd-row fd-row-head" role="row">
          {(["order", "shop", "ordered", "age", "proforma", "advance", "state", "action"] as const).map((column) => <span key={column} role="columnheader" className={`fd-c-${column}`}>{t(`fd.col.${column}` as MessageKey)}</span>)}
        </div>
        {rows.map((item) => <DeskRow key={item.id} item={item} now={now} navigate={navigate} />)}
      </div>}
      <div className="fd-foot">
        {view !== "done" && data && <button type="button" className="button button-link" onClick={() => setView("done")} data-testid="fr-category-done">{t("fd.done.link", { n: data.counts.done })}</button>}
        {view === "done" && <button type="button" className="button button-link" onClick={() => setView("todo")}>{t("fd.kpi.todo")}</button>}
        {canOverseeFinance(employee) && <button type="button" className="button button-link" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight">{t("fr.oversight.open")}</button>}
      </div>
      {data && data.items.length >= data.limit && <p className="dashboard-footnote">{t("fd.limit")}</p>}
    </article>
  );
}

function DeskRow({ item, now, navigate }: { item: InboxItem; now: number; navigate: (path: string) => void }) {
  const { t, formatDate } = useI18n();
  const wait = waitParts(item.requestedAt, now);
  const age = wait.unit === "days" ? t("fd.age.days", { d: wait.d, h: wait.h }) : t(`fd.age.${wait.unit}` as MessageKey, { n: wait.n });
  const advance = item.advance;
  const rest = advance && advance.remaining !== null && advance.required !== null && advance.remaining !== advance.required ? advance.remaining : null;
  const state = item.awaitingPayment ? t("fd.state.waiting")
    : item.kind === "exception" ? taskCodeLabel(item.taskCode) || t("fr.kind.exception" as MessageKey)
      : item.kind !== "verify" && item.category !== "done" ? t(`fr.kind.${item.kind}` as MessageKey) : stateLabel(item.state);
  const tone: Tone = item.awaitingPayment || item.state === "not_received" ? "danger" : item.state === "needs_clarification" ? "warning" : item.state === "verified" ? "success" : item.category === "done" ? "neutral" : "accent";
  const check = deskable(item);
  return (
    <div className="fd-row" role="row" data-testid={`fr-card-${item.orderNumber}`}>
      <span role="cell" className="fd-c-order"><strong className="mono">#{item.orderNumber}</strong></span>
      <span role="cell" className="fd-c-shop">{sourceLabel(item.source)}</span>
      <span role="cell" className="fd-c-ordered">{item.orderedAt ? formatDate(item.orderedAt, { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "Europe/Bucharest" }) : "—"}</span>
      <span role="cell" className={`fd-c-age${wait.unit === "days" && wait.d >= 2 && item.category !== "done" ? " is-late" : ""}`}>{age}</span>
      <span role="cell" className="fd-c-proforma mono">{proformaLabel(item.proforma)}</span>
      <span role="cell" className="fd-c-advance" data-testid={`fd-advance-${item.orderNumber}`}>{advance ? <><strong>{formatFinanceMoney(advance.required, advance.currency)}</strong>{rest && <small>{t("fd.advance.rest", { amount: formatFinanceMoney(rest, advance.currency) })}</small>}</> : "—"}</span>
      <span role="cell" className="fd-c-state"><StatusPill tone={tone} testId={`fr-card-state-${item.orderNumber}`}>{state}</StatusPill>
        {item.ledger === "manual" && <small className="fd-tag" data-testid={`fr-card-manual-${item.orderNumber}`}>{t("fr.ledger.manual")}</small>}</span>
      <span role="cell" className="fd-c-action">
        <button type="button" className={`button ${check ? "button-primary" : "button-secondary"} fd-row-check`} onClick={() => navigate(rowTarget(item))} data-testid={`fr-open-${item.orderNumber}`}>{t(check ? "fd.row.check" : "fd.row.open")}<AppIcon name="arrow" size={16} /></button>
      </span>
    </div>
  );
}

// ---- The three-step check ---------------------------------------------------------------------------------------

type Step =
  | { kind: "question" }
  | { kind: "payment"; form: PaymentForm }
  | { kind: "summary"; form: PaymentForm; input: PaymentInput; gap: string | null }
  | { kind: "no" }
  | { kind: "no-summary"; outcome: "not_received" }
  | { kind: "no-summary"; outcome: "needs_clarification"; reason: ClarificationReason };
const EMPTY_FORM: PaymentForm = { amount: "", bankReference: "", valueDate: "", statementReference: "" };

export function FinanceCheckScreen({ requestId, service, navigate }: { requestId: string; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  // The task is read when opened and after every command; never refreshed behind the employee's back (money on screen).
  const load = useMemo(() => (signal: AbortSignal) => service.financeTask(requestId, signal), [service, requestId]);
  const view = useAutoRefresh(load, `finance-check|${requestId}`, 10 * 60 * 1000);
  const task = view.data;
  const [step, setStep] = useState<Step>({ kind: "question" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lost, setLost] = useState(false);
  const [done] = useState(() => takeDeskDone());
  const [starting, setStarting] = useState(false);
  const intent = useRef<{ id: string; key: string } | null>(null);
  const started = useRef<string | null>(null);
  const keyFor = (id: string) => { if (intent.current?.id !== id) intent.current = { id, key: createIdempotencyKey() }; return intent.current.key; };
  const entry = task ? checkEntry(task) : null;

  // "Kontrole başla" happens by itself (audited `checking_started`); it is no payment decision.
  useEffect(() => {
    if (!task || !task.allowed.start || entry !== "check") return;
    const marker = `${task.request.id}|${task.request.version}`;
    if (started.current === marker) return;
    started.current = marker;
    setStarting(true);
    service.startTask(task.request.id, { expectedVersion: task.request.version }, createIdempotencyKey())
      .then(() => view.refresh(), () => undefined)
      .finally(() => setStarting(false));
  }, [task, entry, service, view]);

  // After a lost answer the task is read again: a task no longer open was decided by the first send (no second one).
  useEffect(() => {
    if (lost && task && task.request.category !== "todo") void goNext(task);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lost, task]);

  // A task the three steps do not handle opens its own task screen.
  useEffect(() => { if (entry === "other" && task) navigate(financeTaskDetailPath(task.request.id)); }, [entry, task, navigate]);

  async function goNext(current: FinanceTask) {
    intent.current = null;
    noteDeskDone(current.request.orderNumber);
    try {
      const inbox: Inbox = await service.financeInbox();
      const next = nextDeskTask(inbox, current.request.id, deskSourceFilter());
      navigate(next ? financeTaskPath(next.id) : "/confirmare/finance/requests");
    } catch {
      navigate("/confirmare/finance/requests");
    }
  }

  async function settle(current: FinanceTask, answer: DecisionAnswer) {
    intent.current = null;
    if (answer.receipt.unknown) { setLost(true); view.refresh(); return; }
    await goNext(current);
  }

  async function send(current: FinanceTask, id: string, command: (key: string) => Promise<DecisionAnswer>) {
    setBusy(true); setError(null);
    try {
      await settle(current, await command(keyFor(id)));
    } catch (caught) {
      const failure = toServiceError(caught);
      const code = backendCode(failure);
      if (!code && answerLost(failure.code)) {
        // The answer is unknown: nothing is sent again by itself. The same key is kept, so "Verifică rezultatul" replays
        // the stored answer (the server never records a second payment for one key).
        setLost(true);
      } else {
        setError(errorText(caught));
        if (code === "FINANCE_REQUEST_STALE" || code === "FINANCE_REQUEST_STATE" || code === "FINANCE_REQUEST_PROFORMA_CHANGED") { intent.current = null; setStep({ kind: "question" }); }
      }
      view.refresh();
    } finally {
      setBusy(false);
    }
  }

  if (!task || entry === "other") {
    return <article className="screen-stack fd-check" data-testid="fr-task" aria-busy={!view.error}>
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fd.back")}</button>
      {view.error ? <Notice tone="danger" role="alert">{errorText(view.error)}</Notice> : <QueueSkeleton />}
      {view.error && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("fd.retry")}</button>}
    </article>;
  }

  const request = task.request;
  const live = task.live.available ? task.live : null;
  const currency = live?.currency ?? "RON";
  const remaining = live?.advance?.remaining ?? null;
  const reference = live?.paymentReference ?? null;
  const number = step.kind === "question" || step.kind === "no" ? (step.kind === "no" ? 2 : 1) : step.kind === "payment" ? 2 : 3;
  const tone = step.kind === "no" || step.kind === "no-summary" ? (step.kind === "no-summary" && step.outcome === "needs_clarification" ? "warning" : "danger") : "success";
  const blocked = Boolean(live?.proformaChanged);
  const waiting = busy || starting;

  // A lost answer, or a decision the shop has not confirmed yet: settle it with the stored key, never a new payment.
  if (entry === "reconcile" || lost) {
    const recheck = async () => {
      if (entry === "reconcile") return send(task, `reconcile|${request.version}`, () => service.reconcileTask(request.id));
      if (intent.current) {
        // Same key again: the server answers with the stored result of the first send.
        const pending = intent.current.id;
        if (pending.startsWith("verified|") && step.kind === "summary" && live) return send(task, pending, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "verified", expectedRevision: live.revision, currency, ...step.input }, key));
        if (pending.startsWith("nr|")) return send(task, pending, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "not_received", reasonCode: "not_found" }, key));
        if (pending.startsWith("nc|") && step.kind === "no-summary" && step.outcome === "needs_clarification") return send(task, pending, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "needs_clarification", reasonCode: step.reason }, key));
      }
      setLost(false); view.refresh();
    };
    return (
      <article className="screen-stack fd-check" data-testid="fr-task" data-state={request.state}>
        <CheckHead task={task} navigate={navigate} step={3} />
        <section className="fd-panel tone-warning" data-testid="fd-lost">
          <h2>{t("fd.lost.title")}</h2>
          <p>{t("fd.lost.text")}</p>
          {error && <Notice tone="danger" role="alert" testId="fr-task-error">{error}</Notice>}
          <button type="button" className="button button-primary button-large" disabled={busy} onClick={() => void recheck()} data-testid="fr-reconcile">{busy ? t("fd.sending") : t("fd.lost.check")}<AppIcon name="refresh" size={20} /></button>
        </section>
      </article>
    );
  }

  return (
    <article className="screen-stack fd-check" data-testid="fr-task" data-state={request.state} data-step={step.kind}>
      <CheckHead task={task} navigate={navigate} step={number} />
      {done && <Notice tone="success" role="status" testId="fd-done">{t("fd.next.done", { order: done })}</Notice>}
      <dl className="fd-facts" data-testid="fr-task-money">
        <div><dt>{t("fd.fact.order")}</dt><dd className="mono">#{request.orderNumber}</dd></div>
        <div><dt>{t("fd.fact.channel")}</dt><dd>{sourceLabel(request.source)}</dd></div>
        <div><dt>{t("fd.fact.proforma")}</dt><dd className="mono">{live?.proforma ? proformaLabel({ series: live.proforma.series || null, number: live.proforma.number }) : proformaLabel(request.proforma)}</dd></div>
        <div><dt>{t("fd.fact.reference")}</dt><dd className="mono" data-testid="fr-payment-reference"><strong>{reference ?? "—"}</strong></dd></div>
        <div><dt>{t("fd.fact.bank")}</dt><dd>{live?.bank ? <>{live.bank.bank} · <span className="mono">{maskIban(live.bank.ibanMasked)}</span></> : "—"}</dd></div>
        <div className="fd-amount"><dt>{t("fd.fact.advance")}</dt><dd data-testid="fr-remaining">{formatFinanceMoney(remaining ?? live?.advance?.required ?? null, currency)}</dd></div>
      </dl>
      {live?.advance && bani(live.advance.verified ?? "0") > 0 && <p className="fd-already" data-testid="fd-already">{t("fd.fact.already", { amount: formatFinanceMoney(live.advance.verified, currency), remaining: formatFinanceMoney(remaining, currency) })}</p>}
      {!live && <Notice tone="warning" role="note" testId="fr-live-unavailable">{t("fd.unavailable")}</Notice>}
      {blocked && <Notice tone="danger" role="alert" testId="fr-proforma-changed">{t("fr.task.proformaChanged")}</Notice>}
      {request.ledger === "manual" && <Notice tone="info" role="note" testId="fr-task-manual">{t("fr.task.manualLedger")}</Notice>}
      {live?.settledElsewhere && <Notice tone="info" role="note" testId="fr-settled">{t("fr.task.settledElsewhere")}</Notice>}
      {live?.financial.attention && <Notice tone="warning" role="note" testId="fr-attention">{t("fr.task.attention")}</Notice>}
      {error && <Notice tone="danger" role="alert" testId="fr-task-error">{error}</Notice>}

      {step.kind === "question" && <section className="fd-panel" data-testid="fd-question">
        <h2 className="fd-question">{t("fd.q.received")}</h2>
        <div className="fd-choice">
          <button type="button" className="button fd-yes" disabled={waiting || !live || blocked || !task.allowed.verify} onClick={() => setStep({ kind: "payment", form: EMPTY_FORM })} data-testid="fr-mode-verified"><AppIcon name="check" size={22} />{t("fd.yes")}</button>
          <button type="button" className="button fd-no" disabled={waiting || !(task.allowed.notReceived || task.allowed.clarify)} onClick={() => setStep({ kind: "no" })} data-testid="fr-mode-not-received"><AppIcon name="close" size={22} />{t("fd.no")}</button>
        </div>
        {!live && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("fd.retry")}</button>}
      </section>}

      {step.kind === "payment" && live && <PaymentStep form={step.form} currency={currency} remaining={remaining} reference={reference} busy={busy}
        onBack={() => setStep({ kind: "question" })}
        onReady={(form, input, gap) => setStep({ kind: "summary", form, input, gap })}
        onClarify={(form, reason) => { void form; setStep({ kind: "no-summary", outcome: "needs_clarification", reason }); }} />}

      {step.kind === "summary" && live && <SummaryStep order={request.orderNumber} currency={currency} remaining={remaining} input={step.input} gap={step.gap} busy={busy}
        onBack={() => setStep({ kind: "payment", form: step.form })}
        onConfirm={() => void send(task, `verified|${request.version}|${step.input.amount}|${step.input.reference}|${step.input.valueDate}`,
          (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "verified", expectedRevision: live.revision, currency, ...step.input }, key))} />}

      {step.kind === "no" && <NoStep busy={busy} notReceived={task.allowed.notReceived} clarify={task.allowed.clarify} onBack={() => setStep({ kind: "question" })}
        onNotReceived={() => setStep({ kind: "no-summary", outcome: "not_received" })}
        onClarify={(reason) => setStep({ kind: "no-summary", outcome: "needs_clarification", reason })} />}

      {step.kind === "no-summary" && <section className={`fd-panel tone-${tone}`} data-testid="fr-confirm">
        <h2>{t(step.outcome === "not_received" ? "fd.no.notReceived" : "fd.no.clarify")}</h2>
        <p data-testid="fr-confirm-text">{step.outcome === "not_received" ? t("fd.sum.notReceived", { order: request.orderNumber }) : t("fd.sum.clarify", { order: request.orderNumber, reason: reasonLabel(step.reason) })}</p>
        <div className="fd-actions">
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => setStep({ kind: "no" })} data-testid="fr-confirm-back">{t("fd.stepBack")}</button>
          <button type="button" className={`button fd-confirm ${step.outcome === "not_received" ? "fd-no" : "fd-warn"}`} disabled={busy}
            onClick={() => step.outcome === "not_received"
              ? void send(task, `nr|${request.version}|not_found`, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "not_received", reasonCode: "not_found" }, key))
              : void send(task, `nc|${request.version}|${step.reason}`, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "needs_clarification", reasonCode: step.reason }, key))}
            data-testid="fr-confirm-send">{busy ? t("fd.sending") : t("fd.confirm")}<AppIcon name="arrow" size={20} /></button>
        </div>
      </section>}
    </article>
  );
}

function CheckHead({ task, navigate, step }: { task: FinanceTask; navigate: (path: string) => void; step: number }) {
  const { t } = useI18n();
  return (
    <header className="fd-check-head">
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fd.back")}</button>
      <div className="fd-steps" aria-label={t("fd.step", { n: step })} data-testid="fd-step" data-step={step}>
        <span className="fd-step-label">{t("fd.step", { n: step })}</span>
        <span className="fd-step-bar" aria-hidden="true">{[1, 2, 3].map((n) => <i key={n} className={n <= step ? "is-on" : ""} />)}</span>
      </div>
      <p className="visually-hidden">#{task.request.orderNumber}</p>
    </header>
  );
}

function PaymentStep({ form: initial, currency, remaining, reference, busy, onBack, onReady, onClarify }: {
  form: PaymentForm; currency: string; remaining: string | null; reference: string | null; busy: boolean;
  onBack: () => void; onReady: (form: PaymentForm, input: PaymentInput, gap: string | null) => void; onClarify: (form: PaymentForm, reason: ClarificationReason) => void;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState<PaymentForm>(initial);
  const [problem, setProblem] = useState<{ text: string; clarify: ClarificationReason | null } | null>(null);
  const set = (field: keyof PaymentForm) => (value: string) => { setForm((current) => ({ ...current, [field]: value })); setProblem(null); };
  const preview = checkPayment({ ...form, bankReference: form.bankReference || "XXX", valueDate: form.valueDate || "2000-01-01", statementReference: form.statementReference || reference || "X" }, null, remaining, Number.MAX_SAFE_INTEGER);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const check = checkPayment(form, reference, remaining, Date.now());
    if (check.kind === "invalid") {
      const messages: Record<typeof check.field, MessageKey> = { amount: "fr.form.amountInvalid", bankReference: "fr.form.referenceInvalid", valueDate: "fr.form.dateInvalid", statementReference: "fr.form.paymentReferenceMissing" };
      return setProblem({ text: t(messages[check.field]), clarify: null });
    }
    if (check.kind === "over") return setProblem({ text: t("fd.pay.over", { amount: formatFinanceMoney(check.input.amount, currency), remaining: formatFinanceMoney(remaining, currency) }), clarify: check.clarify });
    if (check.kind === "mismatch") return setProblem({ text: t("fd.pay.mismatch", { reference: reference ?? "—" }), clarify: check.clarify });
    onReady(form, check.input, check.gap);
  };
  return (
    <form className="fd-panel fd-form" onSubmit={submit} noValidate data-testid="fr-verify-form">
      <h2>{t("fd.pay.title")}</h2>
      <div className="fd-due"><span>{t("fd.pay.required")}</span><strong>{formatFinanceMoney(remaining, currency)}</strong></div>
      <label className="field fd-amount-field"><span>{t("fd.pay.amount", { currency })}</span>
        <input inputMode="decimal" autoComplete="off" value={form.amount} onChange={(event) => set("amount")(event.target.value)} data-testid="fr-amount" /></label>
      {preview.kind === "ok" && preview.gap && <Notice tone="info" role="status" testId="fr-partial-notice">{t("fd.pay.partial", { gap: formatFinanceMoney(preview.gap, currency) })}</Notice>}
      <div className="fd-grid">
        <label className="field"><span>{t("fd.pay.bankReference")}</span><input autoComplete="off" maxLength={64} value={form.bankReference} onChange={(event) => set("bankReference")(event.target.value)} data-testid="fr-bank-reference" /></label>
        <label className="field"><span>{t("fd.pay.date")}</span><input type="date" value={form.valueDate} onChange={(event) => set("valueDate")(event.target.value)} data-testid="fr-value-date" /></label>
        <label className="field fd-span"><span>{t("fd.pay.statementReference")}</span><input autoComplete="off" maxLength={64} value={form.statementReference} onChange={(event) => set("statementReference")(event.target.value)} data-testid="fr-statement-reference" /></label>
      </div>
      {problem && <div className={`fd-problem tone-${problem.clarify ? "warning" : "danger"}`} role="alert" data-testid="fr-form-problem">
        <p>{problem.text}</p>
        {problem.clarify && <button type="button" className="button fd-warn" disabled={busy} onClick={() => onClarify(form, problem.clarify as ClarificationReason)} data-testid="fd-to-clarification">{t("fd.pay.toClarification")}</button>}
      </div>}
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={onBack} data-testid="fd-back">{t("fd.stepBack")}</button>
        <button type="submit" className="button button-primary" disabled={busy} data-testid="fr-verify-next">{t("fd.continue")}<AppIcon name="arrow" size={20} /></button>
      </div>
    </form>
  );
}

function SummaryStep({ order, currency, remaining, input, gap, busy, onBack, onConfirm }: {
  order: string; currency: string; remaining: string | null; input: PaymentInput; gap: string | null; busy: boolean; onBack: () => void; onConfirm: () => void;
}) {
  const { t } = useI18n();
  const [statement, setStatement] = useState(false);
  const [problem, setProblem] = useState(false);
  const difference = remaining === null ? null : fromBani(bani(input.amount) - bani(remaining));
  return (
    <section className="fd-panel" data-testid="fr-confirm">
      <h2>{t("fd.sum.title")}</h2>
      <dl className="fd-summary" data-testid="fr-confirm-text">
        <div><dt>{t("fd.fact.order")}</dt><dd className="mono">#{order}</dd></div>
        <div><dt>{t("fd.sum.required")}</dt><dd>{formatFinanceMoney(remaining, currency)}</dd></div>
        <div className="fd-amount"><dt>{t("fd.sum.received")}</dt><dd data-testid="fd-received">{formatFinanceMoney(input.amount, currency)}</dd></div>
        <div><dt>{t("fd.sum.gap")}</dt><dd className={gap ? "is-gap" : ""} data-testid="fd-gap">{difference === null ? "—" : formatFinanceMoney(difference, currency)}</dd></div>
        <div><dt>{t("fd.sum.bankReference")}</dt><dd className="mono">{input.reference}</dd></div>
        <div><dt>{t("fd.sum.date")}</dt><dd>{dueDay(input.valueDate)}</dd></div>
        <div><dt>{t("fd.pay.statementReference")}</dt><dd className="mono">{input.paymentReference}</dd></div>
      </dl>
      {gap && <Notice tone="info" role="note">{t("fd.pay.partial", { gap: formatFinanceMoney(gap, currency) })}</Notice>}
      <label className="check-field fd-statement"><input type="checkbox" checked={statement} onChange={(event) => { setStatement(event.target.checked); setProblem(false); }} data-testid="fr-statement" />
        <span className="cf-check">{t("fd.sum.statement")}<small>{t("fr.form.statementHint")}</small></span></label>
      {problem && <Notice tone="danger" role="alert" testId="fr-form-problem">{t("fd.sum.statementRequired")}</Notice>}
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={onBack} data-testid="fr-confirm-back">{t("fd.stepBack")}</button>
        <button type="button" className="button fd-yes fd-confirm" disabled={busy} onClick={() => (statement ? onConfirm() : setProblem(true))} data-testid="fr-confirm-send">{busy ? t("fd.sending") : t("fd.confirm")}<AppIcon name="arrow" size={20} /></button>
      </div>
    </section>
  );
}

function NoStep({ busy, notReceived, clarify, onBack, onNotReceived, onClarify }: {
  busy: boolean; notReceived: boolean; clarify: boolean; onBack: () => void; onNotReceived: () => void; onClarify: (reason: ClarificationReason) => void;
}) {
  const { t } = useI18n();
  const [reasons, setReasons] = useState(false);
  return (
    <section className="fd-panel" data-testid="fr-reason-form">
      <h2>{t("fd.no.title")}</h2>
      {!reasons && <div className="fd-options">
        <button type="button" className="fd-option tone-danger" disabled={busy || !notReceived} onClick={onNotReceived} data-testid="fd-not-received"><strong>{t("fd.no.notReceived")}</strong><span>{t("fd.no.notReceivedHint")}</span></button>
        <button type="button" className="fd-option tone-warning" disabled={busy || !clarify} onClick={() => setReasons(true)} data-testid="fr-mode-clarify"><strong>{t("fd.no.clarify")}</strong><span>{t("fd.no.clarifyHint")}</span></button>
      </div>}
      {reasons && <fieldset className="fd-reasons"><legend>{t("fd.no.reason")}</legend>
        {CLARIFICATION_REASONS.map((reason) => <button key={reason} type="button" className="fd-option tone-warning" disabled={busy} onClick={() => onClarify(reason)} data-testid={`fr-reason-${reason}`}><strong>{reasonLabel(reason)}</strong></button>)}
      </fieldset>}
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => (reasons ? setReasons(false) : onBack())} data-testid="fd-back">{t("fd.stepBack")}</button>
      </div>
    </section>
  );
}
