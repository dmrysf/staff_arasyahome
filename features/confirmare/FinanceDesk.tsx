import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, StaffServiceError } from "../../domain/models";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { formatFinanceMoney, normalizeAmount, validReference } from "../../domain/confirmareFinance";
import {
  CLARIFICATION_REASONS, canOverseeFinance, financeOrderPath, financeTaskPath, maskIban, oversightPath, proformaLabel, reasonLabel, requestErrorKey, stateLabel, taskCodeLabel, taskOrderId,
  type ClarificationReason, type DecisionAnswer, type FinanceTask, type Inbox, type InboxItem, type Transfer,
} from "../../domain/financeRequests";
import {
  answerLost, bani, checkEntry, checkMoney, checkPayment, deskRows, deskSourceFilter, deskable, fromBani, nextDeskTask, noteDeskDone, paymentEffect, setDeskSourceFilter, takeDeskDone, validValueDate, waitParts,
  type CheckMoney, type DeskView, type PaymentEffect, type PaymentForm, type PaymentInput,
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
/** The clock of a submit handler (never read while rendering). */
const clockNow = (): number => Date.now();
const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;
/** The detail screen of a task the three steps do not handle (Staff 2.41.0 FinanceTaskScreen). */
export const financeTaskDetailPath = (requestId: string): string => `${financeTaskPath(requestId)}/detail`;
/** Staff 2.55.0: a closed task, read-only with its payments (Finalizate, Finalizate azi, the history). */
export const financeTaskDonePath = (requestId: string): string => `${financeTaskPath(requestId)}/done`;

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
  // Staff 2.55.0 (owner 2026-11-25): a closed advance task opens read-only with its payments, never the old task screen.
  if (item.category === "done" && item.purpose === "advance") return financeTaskDonePath(item.id);
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
  // The waiting time is measured at the list's own read (no clock read while rendering).
  const now = inbox.updatedAt ? new Date(inbox.updatedAt).getTime() : 0;
  const rows = useMemo(() => (data ? deskRows(data, view, source, query, now) : null), [data, view, source, query, now]);
  const kpis: { id: DeskView; tone: Tone; value: number | null }[] = [
    { id: "todo", tone: "accent", value: data?.counts.todo ?? null },
    { id: "waiting", tone: "danger", value: data?.counts.waiting ?? null },
    { id: "clarification", tone: "warning", value: data?.counts.clarification ?? null },
    { id: "today", tone: "success", value: data?.counts.today ?? null },
  ];
  const chooseSource = (next: string | null) => { setSource(next); setDeskSourceFilter(next); };
  const label = view === "done" ? "fd.done.all" : `fd.kpi.${view}`;

  return (
    <article className="screen-stack fd-desk" data-testid="fr-inbox">
      <header className="fd-head">
        <div><p className="eyebrow">{t("fd.eyebrow")}</p><h1>{t("fd.title")}</h1></div>
        <button type="button" className="button button-ghost fd-refresh" onClick={inbox.refresh} disabled={inbox.refreshing} aria-label={t("common.retry")} data-testid="fr-inbox-refresh"><AppIcon name="refresh" size={18} /></button>
      </header>
      <div className="fd-kpis" role="group" aria-label={t("fd.kpis")} data-testid="fd-kpis">
        {/* Staff 2.55.0: every counter opens its list, "Finalizate azi" too (the tasks this employee decided today). */}
        {kpis.map((kpi) => <button key={kpi.id} type="button" className={`fd-kpi tone-${kpi.tone}`} aria-pressed={view === kpi.id} onClick={() => setView(kpi.id)} data-testid={`fr-category-${kpi.id}`}>
          <span className="fd-kpi-value" data-testid={`fd-kpi-${kpi.id}`}>{kpi.value ?? "–"}</span><span className="fd-kpi-label">{t(`fd.kpi.${kpi.id}` as MessageKey)}</span></button>)}
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
      {rows && rows.length > 0 && <DeskTable rows={rows} now={now} navigate={navigate} label={t(label as MessageKey)} />}
      {view === "done" && data && <DeskHistory service={service} source={source} query={query} now={now} navigate={navigate} shown={rows ?? []} />}
      <div className="fd-foot">
        {view !== "done" && data && <button type="button" className="button button-link" onClick={() => setView("done")} data-testid="fr-category-done">{t("fd.done.link", { n: data.counts.done })}</button>}
        {view !== "todo" && <button type="button" className="button button-link" onClick={() => setView("todo")}>{t("fd.kpi.todo")}</button>}
        {canOverseeFinance(employee) && <button type="button" className="button button-link" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight">{t("fr.oversight.open")}</button>}
      </div>
      {data && data.items.length >= data.limit && <p className="dashboard-footnote">{t("fd.limit")}</p>}
    </article>
  );
}

function DeskTable({ rows, now, navigate, label, testId = "fd-table" }: { rows: InboxItem[]; now: number; navigate: (path: string) => void; label: string; testId?: string }) {
  const { t } = useI18n();
  return (
    <div className="fd-table" role="table" aria-label={label} data-testid={testId}>
      <div className="fd-row fd-row-head" role="row">
        {(["order", "shop", "ordered", "age", "proforma", "advance", "state", "action"] as const).map((column) => <span key={column} role="columnheader" className={`fd-c-${column}`}>{t(`fd.col.${column}` as MessageKey)}</span>)}
      </div>
      {rows.map((item) => <DeskRow key={item.id} item={item} now={now} navigate={navigate} />)}
    </div>
  );
}

/**
 * Staff 2.55.0 (owner 2026-11-25): the closed tasks older than the inbox's latest ones (GET .../history), with the same
 * search (sent to the server) and a "Mai multe" page, so no closed task is out of reach.
 */
function DeskHistory({ service, source, query, now, navigate, shown }: { service: ConfirmareApi; source: string | null; query: string; now: number; navigate: (path: string) => void; shown: InboxItem[] }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  const [pages, setPages] = useState<{ key: string; items: InboxItem[]; next: string | null } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = `${source ?? ""}|${query.trim()}`;
  const loadPage = async (before: string | null) => {
    setBusy(true); setError(null);
    try {
      const page = await service.financeHistory({ q: query.trim(), before, source });
      setPages((current) => ({ key, items: [...(current && current.key === key && before ? current.items : []), ...page.items], next: page.next }));
    } catch (caught) {
      setError(errorText(caught));
    } finally {
      setBusy(false);
    }
  };
  const ownPages = pages && pages.key === key ? pages : null;
  const seen = new Set(shown.map((item) => item.id));
  const more = ownPages ? ownPages.items.filter((item) => !seen.has(item.id)) : [];
  return (
    <section className="fd-history" data-testid="fd-history">
      {ownPages === null && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void loadPage(null)} data-testid="fd-history-open">{busy ? t("fd.sending") : t("fd.history.open")}</button>}
      {ownPages && more.length === 0 && !busy && <p className="fd-empty" data-testid="fd-history-empty">{t("fd.history.empty")}</p>}
      {more.length > 0 && <DeskTable rows={more} now={now} navigate={navigate} label={t("fd.history.title")} testId="fd-history-table" />}
      {ownPages?.next && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void loadPage(ownPages.next)} data-testid="fd-history-more">{busy ? t("fd.sending") : t("fd.history.more")}</button>}
      {error && <Notice tone="danger" role="alert">{error}</Notice>}
    </section>
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
    // Staff 2.55.0: the whole row opens the task (the button stays for the keyboard and screen readers).
    <div className="fd-row is-clickable" role="row" tabIndex={-1} data-testid={`fr-card-${item.orderNumber}`}
      onClick={(event) => { if (!(event.target as HTMLElement).closest("button")) navigate(rowTarget(item)); }}
      onKeyDown={(event) => { if (event.key === "Enter" && event.target === event.currentTarget) navigate(rowTarget(item)); }}>
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
  | { kind: "summary"; form: PaymentForm; input: PaymentInput; effect: PaymentEffect }
  | { kind: "no" }
  | { kind: "no-summary"; outcome: "not_received" }
  | { kind: "no-summary"; outcome: "needs_clarification"; reason: ClarificationReason };
const EMPTY_FORM: PaymentForm = { amount: "", bankReference: "", valueDate: "", statementReference: "", matchBasis: "", payerName: "", matchNote: "" };

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
  const money = checkMoney(task);
  const canMatch = live?.capabilities.match ?? false;
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
        <div className="fd-wide"><dt>{t("fd.fact.bank")}</dt><dd>{live?.bank ? <>{live.bank.bank} · <span className="mono">{maskIban(live.bank.ibanMasked)}</span></> : "—"}</dd></div>
        <div className="fd-full"><dt>{t("fd.fact.total")}</dt><dd data-testid="fd-total">{formatFinanceMoney(money.total, currency)}</dd></div>
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

      {step.kind === "payment" && live && <PaymentStep form={step.form} currency={currency} money={money} reference={reference} canMatch={canMatch} busy={busy}
        onBack={() => setStep({ kind: "question" })}
        onReady={(form, input, effect) => setStep({ kind: "summary", form, input, effect })}
        onClarify={(form, reason) => { void form; setStep({ kind: "no-summary", outcome: "needs_clarification", reason }); }} />}

      {step.kind === "summary" && live && <SummaryStep order={request.orderNumber} currency={currency} money={money} input={step.input} effect={step.effect} busy={busy}
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

/** Staff 2.55.0: how the transfer is tied to the order when the statement shows no proforma reference. */
const MATCH_CHOICES = ["customer_name", "other_payer", "customer_proof"] as const;

function MoneyLine({ label, value, testId, strong = false, tone }: { label: string; value: string; testId: string; strong?: boolean; tone?: "accent" | "success" }) {
  return <div className={`fd-money-line${strong ? " is-strong" : ""}${tone ? ` tone-${tone}` : ""}`}><dt>{label}</dt><dd data-testid={testId}>{value}</dd></div>;
}

/**
 * Step 2 (Staff 2.55.0, owner 2026-11-25): the employee types the real amount; the order value, the advance asked, the
 * total received, the advance difference and the new cash on delivery follow as they type. A smaller advance is shown
 * neutrally (no red, no block): finance accepts the real money, the operator does not approve it again.
 */
function PaymentStep({ form: initial, currency, money, reference, canMatch, busy, onBack, onReady, onClarify }: {
  form: PaymentForm; currency: string; money: CheckMoney; reference: string | null; canMatch: boolean; busy: boolean;
  onBack: () => void; onReady: (form: PaymentForm, input: PaymentInput, effect: PaymentEffect) => void; onClarify: (form: PaymentForm, reason: ClarificationReason) => void;
}) {
  const { t } = useI18n();
  const [form, setForm] = useState<PaymentForm>(initial);
  const [problem, setProblem] = useState<{ text: string; clarify: ClarificationReason | null } | null>(null);
  const set = (field: keyof PaymentForm) => (value: string) => { setForm((current) => ({ ...current, [field]: value })); setProblem(null); };
  const typed = normalizeAmount(form.amount);
  const effect = paymentEffect(typed, money);
  const byReference = reference !== null && form.statementReference.trim().toUpperCase() === reference.toUpperCase();
  const over = typed !== null && money.remaining !== null && bani(typed) > bani(money.remaining);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const check = checkPayment(form, reference, money, Date.now(), canMatch);
    if (check.kind === "invalid") {
      const messages: Record<typeof check.field, MessageKey> = { amount: "fr.form.amountInvalid", bankReference: "fr.form.referenceInvalid", valueDate: "fr.form.dateInvalid",
        matchBasis: "fd.pay.matchRequired", payerName: "fd.pay.payerRequired", matchNote: "fd.pay.noteRequired" };
      return setProblem({ text: t(messages[check.field]), clarify: null });
    }
    if (check.kind === "over") return setProblem({ text: t("fd.pay.overTotal", { amount: formatFinanceMoney(check.input.amount, currency), remaining: formatFinanceMoney(money.remaining, currency), excess: formatFinanceMoney(check.excess, currency) }), clarify: check.clarify });
    if (check.kind === "mismatch") return setProblem({ text: t("fd.pay.mismatch", { reference: reference ?? "—" }), clarify: check.clarify });
    onReady(form, check.input, check.effect);
  };
  return (
    <form className="fd-panel fd-form" onSubmit={submit} noValidate data-testid="fr-verify-form">
      <h2>{t("fd.pay.title")}</h2>
      <dl className="fd-money" data-testid="fd-money">
        <MoneyLine label={t("fd.money.total")} value={formatFinanceMoney(money.total, currency)} testId="fd-money-total" />
        <MoneyLine label={t("fd.money.asked")} value={formatFinanceMoney(money.advanceRemaining, currency)} testId="fd-money-asked" />
        <MoneyLine label={t("fd.money.paid")} value={typed ? formatFinanceMoney(typed, currency) : "—"} testId="fd-money-paid" strong />
        <MoneyLine label={t("fd.money.received")} value={formatFinanceMoney(effect.totalReceived, currency)} testId="fd-money-received" />
        <MoneyLine label={t("fd.money.gap")} value={effect.overAdvance ? `+${formatFinanceMoney(effect.overAdvance, currency)}` : formatFinanceMoney(effect.advanceGap, currency)} testId="fd-money-gap" />
        <MoneyLine label={t("fd.money.ramburs")} value={formatFinanceMoney(effect.ramburs, currency)} testId="fd-money-ramburs" strong tone="accent" />
      </dl>
      <label className="field fd-amount-field"><span>{t("fd.pay.amount", { currency })}</span>
        <input inputMode="decimal" autoComplete="off" value={form.amount} onChange={(event) => set("amount")(event.target.value)} data-testid="fr-amount" /></label>
      {typed && !over && <p className="fd-accept-note" role="status" data-testid="fd-accept-note">{t("fd.pay.acceptNote")}</p>}
      <div className="fd-grid">
        <label className="field"><span>{t("fd.pay.bankReference")}</span><input autoComplete="off" maxLength={64} value={form.bankReference} onChange={(event) => set("bankReference")(event.target.value)} data-testid="fr-bank-reference" /></label>
        <label className="field"><span>{t("fd.pay.date")}</span><input type="date" value={form.valueDate} onChange={(event) => set("valueDate")(event.target.value)} data-testid="fr-value-date" /></label>
        <label className="field fd-span"><span>{t("fd.pay.statementReferenceOptional", { reference: reference ?? "—" })}</span><input autoComplete="off" maxLength={64} value={form.statementReference} onChange={(event) => set("statementReference")(event.target.value)} data-testid="fr-statement-reference" /></label>
      </div>
      {byReference && <p className="fd-match-ok" data-testid="fd-match-reference"><AppIcon name="check" size={16} /> {t("fd.pay.matchReference")}</p>}
      {!byReference && canMatch && <fieldset className="fd-match" data-testid="fd-match">
        <legend>{t("fd.pay.matchTitle")}</legend>
        <div className="fd-match-options">
          {MATCH_CHOICES.map((basis) => <label key={basis} className={`fd-match-option${form.matchBasis === basis ? " is-on" : ""}`}>
            <input type="radio" name="fd-match" value={basis} checked={form.matchBasis === basis} onChange={() => set("matchBasis")(basis)} data-testid={`fd-match-${basis}`} />
            <span>{t(`fd.match.${basis}` as MessageKey)}</span></label>)}
        </div>
        <div className="fd-grid">
          <label className="field"><span>{t("fd.pay.payer")}</span><input autoComplete="off" maxLength={128} value={form.payerName} onChange={(event) => set("payerName")(event.target.value)} data-testid="fd-payer" /></label>
          {form.matchBasis !== "" && form.matchBasis !== "customer_name" && <label className="field"><span>{t("fd.pay.note")}</span><input autoComplete="off" maxLength={255} value={form.matchNote} onChange={(event) => set("matchNote")(event.target.value)} data-testid="fd-match-note" /></label>}
        </div>
        <p className="fd-match-hint">{t("fd.pay.matchHint")}</p>
      </fieldset>}
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

const matchText = (input: { matchBasis?: string | null; payerName?: string | null; payer?: string | null }, t: (key: MessageKey) => string): string =>
  input.matchBasis && input.matchBasis !== "proforma_reference" ? `${t(`fd.match.${input.matchBasis}` as MessageKey)} · ${input.payerName ?? input.payer ?? ""}` : t("fd.match.proforma_reference");

/** Step 3: the last check, one green button. Confirming also accepts the real advance (no second acceptance). */
function SummaryStep({ order, currency, money, input, effect, busy, onBack, onConfirm }: {
  order: string; currency: string; money: CheckMoney; input: PaymentInput; effect: PaymentEffect; busy: boolean; onBack: () => void; onConfirm: () => void;
}) {
  const { t } = useI18n();
  const [statement, setStatement] = useState(false);
  const [problem, setProblem] = useState(false);
  return (
    <section className="fd-panel" data-testid="fr-confirm">
      <h2>{t("fd.sum.title")}</h2>
      <dl className="fd-summary" data-testid="fr-confirm-text">
        <div><dt>{t("fd.fact.order")}</dt><dd className="mono">#{order}</dd></div>
        <div><dt>{t("fd.money.total")}</dt><dd>{formatFinanceMoney(money.total, currency)}</dd></div>
        <div className="fd-amount"><dt>{t("fd.sum.received")}</dt><dd data-testid="fd-received">{formatFinanceMoney(input.amount, currency)}</dd></div>
        <div><dt>{t("fd.money.received")}</dt><dd data-testid="fd-total-received">{formatFinanceMoney(effect.totalReceived, currency)}</dd></div>
        <div className="fd-amount tone-accent"><dt>{t("fd.money.ramburs")}</dt><dd data-testid="fd-ramburs">{formatFinanceMoney(effect.ramburs, currency)}</dd></div>
        <div><dt>{t("fd.money.gap")}</dt><dd data-testid="fd-gap">{effect.overAdvance ? `+${formatFinanceMoney(effect.overAdvance, currency)}` : formatFinanceMoney(effect.advanceGap, currency)}</dd></div>
        <div><dt>{t("fd.sum.bankReference")}</dt><dd className="mono">{input.reference}</dd></div>
        <div><dt>{t("fd.sum.date")}</dt><dd>{dueDay(input.valueDate)}</dd></div>
        <div className="fd-wide"><dt>{t("fd.sum.match")}</dt><dd data-testid="fd-sum-match">{matchText(input, t)}</dd></div>
      </dl>
      <p className="fd-accept-note" data-testid="fd-sum-accept">{t("fd.sum.accept")}</p>
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

// ---- A closed task: read-only, its payments, where the order stands (Staff 2.55.0) ----------------------------------

type Step2 = "money" | "approval" | "payment" | "production" | "routing";
type Milestone = { id: Step2; state: "done" | "waiting" | "problem"; text: string };

/** Where the order stands, from Arasya's records (owner 2026-11-25: who has it, why it waits, what comes next). */
export function milestones(task: FinanceTask, t: (key: MessageKey, values?: Record<string, string | number>) => string): { items: Milestone[]; now: string } {
  const progress = task.progress;
  const live = task.live.available ? task.live : null;
  const paid = progress?.paymentCleared ?? live?.financial.state === "cleared";
  if (!progress) return { items: [{ id: "payment", state: paid ? "done" : "waiting", text: t(paid ? "fd.ms.paid" : "fd.ms.unpaid") }], now: t(paid ? "fd.now.unknown" : "fd.now.finance") };
  const operator = progress.operator?.displayName ?? t("fd.ms.operator");
  const items: Milestone[] = [
    { id: "approval", state: progress.approval === "approved" ? "done" : progress.approval === "rejected" ? "problem" : "waiting", text: t(progress.approval === "approved" ? "fd.ms.approved" : progress.approval === "rejected" ? "fd.ms.rejected" : "fd.ms.approval", { name: operator }) },
    { id: "payment", state: paid ? "done" : "waiting", text: t(paid ? "fd.ms.paid" : "fd.ms.unpaid") },
    { id: "production", state: progress.productionCleared ? "done" : "waiting", text: progress.productionCleared ? t("fd.ms.cleared") : t("fd.ms.missing", { list: progress.missing.map((code) => t(`fd.missing.${code}` as MessageKey)).join(", ") || "—" }) },
    { id: "routing", state: progress.routedTo ? "done" : "waiting", text: progress.routedTo ? t("fd.ms.routed", { name: progress.routedTo.displayName }) : t("fd.ms.notRouted") },
  ];
  if (progress.cancelled) return { items: [...items, { id: "money", state: "problem", text: t("fd.ms.cancelled") }], now: t("fd.now.cancelled") };
  const now = progress.approval !== "approved" ? t("fd.now.operator", { name: operator })
    : !paid ? t("fd.now.finance")
      : !progress.productionCleared ? t("fd.now.blocked", { list: progress.missing.map((code) => t(`fd.missing.${code}` as MessageKey)).join(", ") || "—" })
        : !progress.routedTo ? t("fd.now.routing")
          : t("fd.now.shop", { name: progress.routedTo.displayName });
  return { items, now };
}

export function FinanceDoneScreen({ requestId, service, navigate }: { requestId: string; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t, formatDate } = useI18n();
  const errorText = useErrorText();
  const load = useMemo(() => (signal: AbortSignal) => service.financeTask(requestId, signal), [service, requestId]);
  const view = useAutoRefresh(load, `finance-done|${requestId}`, 10 * 60 * 1000);
  const task = view.data;
  const [form, setForm] = useState<"add" | "correct" | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  if (!task) {
    return <article className="screen-stack fd-check" data-testid="fd-done-task" aria-busy={!view.error}>
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fd.back")}</button>
      {view.error ? <Notice tone="danger" role="alert">{errorText(view.error)}</Notice> : <QueueSkeleton />}
      {view.error && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("fd.retry")}</button>}
    </article>;
  }
  const request = task.request;
  const live = task.live.available ? task.live : null;
  const currency = live?.currency ?? "RON";
  const money = checkMoney(task);
  const plan = milestones(task, t);
  const when = (value: string | null) => value ? formatDate(value, { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" }) : "—";
  const transfers = live?.transfers ?? [];
  const mine = transfers.filter((entry) => entry.state === "verified" && entry.purpose === "advance" && entry.method === "bank_transfer" && entry.submittedBy.id === task.me && entry.flags.includes("single_verification"));
  const tone: Tone = request.state === "verified" ? "success" : request.state === "not_received" ? "danger" : request.state === "needs_clarification" ? "warning" : "neutral";
  const finish = (text: string) => { setForm(null); setSaved(text); view.refresh(); };
  return (
    <article className="screen-stack fd-check fd-done-task" data-testid="fd-done-task" data-state={request.state}>
      <button className="back-link" type="button" onClick={() => navigate("/confirmare/finance/requests")}><AppIcon name="back" size={20} /> {t("fd.back")}</button>
      <header className="fd-done-head">
        <div><p className="eyebrow">{sourceLabel(request.source)}</p><h1 className="mono">#{request.orderNumber}</h1></div>
        <StatusPill tone={tone} testId="fd-done-state">{request.awaitingPayment ? t("fd.state.waiting") : stateLabel(request.state)}</StatusPill>
      </header>
      {saved && <Notice tone="success" role="status" testId="fd-done-saved">{saved}</Notice>}
      {!live && <Notice tone="warning" role="note" testId="fr-live-unavailable">{t("fd.unavailable")}</Notice>}
      <dl className="fd-money fd-money-done" data-testid="fd-done-money">
        <MoneyLine label={t("fd.money.total")} value={formatFinanceMoney(money.total, currency)} testId="fd-done-total" />
        <MoneyLine label={t("fd.money.asked")} value={formatFinanceMoney(live?.advance?.required ?? null, currency)} testId="fd-done-asked" />
        <MoneyLine label={t("fd.money.received")} value={formatFinanceMoney(money.received, currency)} testId="fd-done-received" strong />
        <MoneyLine label={t("fd.money.ramburs")} value={formatFinanceMoney(money.remaining, currency)} testId="fd-done-ramburs" strong tone="accent" />
        {live?.money?.excess && bani(live.money.excess) > 0 && <MoneyLine label={t("fd.money.excess")} value={formatFinanceMoney(live.money.excess, currency)} testId="fd-done-excess" />}
        <MoneyLine label={t("fd.done.decidedAt")} value={when(request.decidedAt)} testId="fd-done-decided" />
        <MoneyLine label={t("fd.fact.proforma")} value={proformaLabel(request.proforma)} testId="fd-done-proforma" />
      </dl>
      {request.recheckAt && <p className="fd-recheck" data-testid="fd-done-recheck">{t("fd.done.recheck", { at: when(request.recheckAt), last: when(request.decidedAt) })}</p>}

      <section className="fd-panel" data-testid="fd-done-transfers">
        <h2>{t("fd.done.transfers")}</h2>
        {transfers.length === 0 && <p className="fd-empty">{t("fd.done.noTransfers")}</p>}
        {transfers.length > 0 && <ul className="fd-transfers">
          {transfers.map((entry) => <li key={entry.id} className={`fd-transfer is-${entry.state}`} data-testid={`fd-transfer-${entry.state}`}>
            <strong>{formatFinanceMoney(entry.amount, currency)}</strong>
            <span className="mono">{entry.reference}</span>
            <span>{entry.valueDate ? dueDay(entry.valueDate) : "—"}</span>
            <span>{entry.matchBasis ? matchText({ matchBasis: entry.matchBasis, payer: entry.payer }, t) : t("fd.match.proforma_reference")}</span>
            <small>{t(`fd.transfer.${entry.state}` as MessageKey)}{entry.corrects ? ` · ${t("fd.transfer.correction")}` : ""} · {entry.submittedBy.displayName ?? "—"} · {when(entry.decidedAt ?? entry.submittedAt)}</small>
          </li>)}
        </ul>}
      </section>

      <section className="fd-panel" data-testid="fd-done-progress">
        <h2>{t("fd.done.progress")}</h2>
        <p className="fd-now" data-testid="fd-done-now"><strong>{t("fd.done.now")}</strong> {plan.now}</p>
        <ol className="fd-milestones">
          {plan.items.map((item) => <li key={item.id} className={`fd-ms is-${item.state}`} data-testid={`fd-ms-${item.id}`}><span className="fd-ms-dot" aria-hidden="true" />{item.text}</li>)}
        </ol>
      </section>

      {(task.allowed.addPayment || task.allowed.correctPayment) && form === null && <div className="fd-actions fd-done-actions">
        {task.allowed.addPayment && <button type="button" className="button button-primary" onClick={() => { setSaved(null); setForm("add"); }} data-testid="fd-add-payment">{t("fd.add.open")}</button>}
        {task.allowed.correctPayment && mine.length > 0 && <button type="button" className="button button-secondary" onClick={() => { setSaved(null); setForm("correct"); }} data-testid="fd-correct-payment">{t("fd.fix.open")}</button>}
      </div>}
      {form === "add" && live && <AddPaymentForm task={task} currency={currency} money={money} service={service} onCancel={() => setForm(null)} onDone={() => finish(t("fd.add.done"))} />}
      {form === "correct" && live && <CorrectPaymentForm task={task} currency={currency} money={money} entries={mine} service={service} onCancel={() => setForm(null)} onDone={() => finish(t("fd.fix.done"))} />}
    </article>
  );
}

/** Sends one follow-up command with one key per intent; a lost answer keeps the key, so "Verifică rezultatul" replays it. */
function useFollowUp(onDone: () => void) {
  const errorText = useErrorText();
  const intent = useRef<{ id: string; key: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lost, setLost] = useState(false);
  const send = async (id: string, command: (key: string) => Promise<{ state: string }>) => {
    if (intent.current?.id !== id) intent.current = { id, key: createIdempotencyKey() };
    setBusy(true); setError(null);
    try {
      const answer = await command(intent.current.key);
      if (answer.state === "applied") { intent.current = null; setLost(false); onDone(); return; }
      setLost(true);
    } catch (caught) {
      const failure = toServiceError(caught);
      if (!backendCode(failure) && answerLost(failure.code)) setLost(true);
      else { setError(errorText(caught)); intent.current = null; }
    } finally {
      setBusy(false);
    }
  };
  return { busy, error, lost, send };
}

function AddPaymentForm({ task, currency, money, service, onCancel, onDone }: { task: FinanceTask; currency: string; money: CheckMoney; service: ConfirmareApi; onCancel: () => void; onDone: () => void }) {
  const { t } = useI18n();
  const live = task.live.available ? task.live : null;
  const reference = live?.paymentReference ?? null;
  const [form, setForm] = useState<PaymentForm>(EMPTY_FORM);
  const [statement, setStatement] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const follow = useFollowUp(onDone);
  const set = (field: keyof PaymentForm) => (value: string) => { setForm((current) => ({ ...current, [field]: value })); setProblem(null); };
  const typed = normalizeAmount(form.amount);
  const effect = paymentEffect(typed, { ...money, advanceRemaining: null });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!live) return;
    const check = checkPayment(form, reference, money, Date.now(), true);
    if (check.kind === "invalid") return setProblem(t(({ amount: "fr.form.amountInvalid", bankReference: "fr.form.referenceInvalid", valueDate: "fr.form.dateInvalid", matchBasis: "fd.pay.matchRequired", payerName: "fd.pay.payerRequired", matchNote: "fd.pay.noteRequired" } as const)[check.field]));
    if (check.kind === "over") return setProblem(t("fd.pay.overTotal", { amount: formatFinanceMoney(check.input.amount, currency), remaining: formatFinanceMoney(money.remaining, currency), excess: formatFinanceMoney(check.excess, currency) }));
    if (check.kind === "mismatch") return setProblem(t("fd.pay.mismatch", { reference: reference ?? "—" }));
    if (!statement) return setProblem(t("fd.sum.statementRequired"));
    const input = check.input;
    void follow.send(`add|${input.amount}|${input.reference}|${input.valueDate}`, (key) => service.addPayment(task.request.id, { expectedRevision: live.revision, currency, bankStatementConfirmed: true, ...input }, key));
  };
  return (
    <form className="fd-panel fd-form" onSubmit={submit} noValidate data-testid="fd-add-form">
      <h2>{t("fd.add.title")}</h2>
      <dl className="fd-money">
        <MoneyLine label={t("fd.money.received")} value={formatFinanceMoney(money.received, currency)} testId="fd-add-received" />
        <MoneyLine label={t("fd.money.ramburs")} value={formatFinanceMoney(effect.ramburs, currency)} testId="fd-add-ramburs" strong tone="accent" />
      </dl>
      <label className="field fd-amount-field"><span>{t("fd.pay.amount", { currency })}</span><input inputMode="decimal" autoComplete="off" value={form.amount} onChange={(event) => set("amount")(event.target.value)} data-testid="fd-add-amount" /></label>
      <div className="fd-grid">
        <label className="field"><span>{t("fd.pay.bankReference")}</span><input autoComplete="off" maxLength={64} value={form.bankReference} onChange={(event) => set("bankReference")(event.target.value)} data-testid="fd-add-reference" /></label>
        <label className="field"><span>{t("fd.pay.date")}</span><input type="date" value={form.valueDate} onChange={(event) => set("valueDate")(event.target.value)} data-testid="fd-add-date" /></label>
        <label className="field fd-span"><span>{t("fd.pay.statementReferenceOptional", { reference: reference ?? "—" })}</span><input autoComplete="off" maxLength={64} value={form.statementReference} onChange={(event) => set("statementReference")(event.target.value)} data-testid="fd-add-statement-reference" /></label>
      </div>
      <fieldset className="fd-match"><legend>{t("fd.pay.matchTitle")}</legend>
        <div className="fd-match-options">{MATCH_CHOICES.map((basis) => <label key={basis} className={`fd-match-option${form.matchBasis === basis ? " is-on" : ""}`}>
          <input type="radio" name="fd-add-match" checked={form.matchBasis === basis} onChange={() => set("matchBasis")(basis)} data-testid={`fd-add-match-${basis}`} /><span>{t(`fd.match.${basis}` as MessageKey)}</span></label>)}</div>
        <div className="fd-grid">
          <label className="field"><span>{t("fd.pay.payer")}</span><input autoComplete="off" maxLength={128} value={form.payerName} onChange={(event) => set("payerName")(event.target.value)} data-testid="fd-add-payer" /></label>
          {form.matchBasis !== "" && form.matchBasis !== "customer_name" && <label className="field"><span>{t("fd.pay.note")}</span><input autoComplete="off" maxLength={255} value={form.matchNote} onChange={(event) => set("matchNote")(event.target.value)} data-testid="fd-add-note" /></label>}
        </div>
      </fieldset>
      <label className="check-field fd-statement"><input type="checkbox" checked={statement} onChange={(event) => { setStatement(event.target.checked); setProblem(null); }} data-testid="fd-add-statement" />
        <span className="cf-check">{t("fd.sum.statement")}<small>{t("fr.form.statementHint")}</small></span></label>
      {(problem || follow.error) && <Notice tone="danger" role="alert" testId="fd-add-problem">{problem ?? follow.error}</Notice>}
      {follow.lost && <Notice tone="warning" role="status" testId="fd-add-lost">{t("fd.lost.text")}</Notice>}
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={follow.busy} onClick={onCancel}>{t("fd.stepBack")}</button>
        <button type="submit" className="button fd-yes" disabled={follow.busy} data-testid="fd-add-send">{follow.busy ? t("fd.sending") : follow.lost ? t("fd.lost.check") : t("fd.add.send")}<AppIcon name="arrow" size={20} /></button>
      </div>
    </form>
  );
}

function CorrectPaymentForm({ task, currency, money, entries, service, onCancel, onDone }: { task: FinanceTask; currency: string; money: CheckMoney; entries: Transfer[]; service: ConfirmareApi; onCancel: () => void; onDone: () => void }) {
  const { t } = useI18n();
  const live = task.live.available ? task.live : null;
  const [entryId, setEntryId] = useState(entries[0]?.id ?? "");
  const old = entries.find((entry) => entry.id === entryId) ?? null;
  const [amount, setAmount] = useState("");
  const [reference, setReference] = useState(old?.reference ?? "");
  const [valueDate, setValueDate] = useState(old?.valueDate ?? "");
  const [reason, setReason] = useState("");
  const [statement, setStatement] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const follow = useFollowUp(onDone);
  const typed = normalizeAmount(amount);
  const ramburs = old && money.remaining !== null && typed ? fromBani(Math.max(0, bani(money.remaining) + bani(old.amount) - bani(typed))) : null;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!live || !old) return;
    if (!typed || bani(typed) <= 0) return setProblem(t("fr.form.amountInvalid"));
    if (!validReference(reference)) return setProblem(t("fr.form.referenceInvalid"));
    if (!validValueDate(valueDate, clockNow())) return setProblem(t("fr.form.dateInvalid"));
    if (reason.trim().length < 3) return setProblem(t("fd.fix.reasonRequired"));
    if (money.remaining !== null && bani(typed) > bani(money.remaining) + bani(old.amount)) return setProblem(t("fd.fix.over"));
    if (!statement) return setProblem(t("fd.sum.statementRequired"));
    void follow.send(`fix|${old.id}|${typed}|${reference.trim()}|${valueDate}`, (key) => service.correctPayment(task.request.id, {
      expectedRevision: live.revision, entryId: old.id, amount: typed, reference: reference.trim(), valueDate, currency, bankStatementConfirmed: true, reasonNote: reason.trim(),
    }, key));
  };
  return (
    <form className="fd-panel fd-form" onSubmit={submit} noValidate data-testid="fd-fix-form">
      <h2>{t("fd.fix.title")}</h2>
      {entries.length > 1 && <label className="field"><span>{t("fd.fix.which")}</span>
        <select value={entryId} onChange={(event) => { setEntryId(event.target.value); setProblem(null); }} data-testid="fd-fix-entry">
          {entries.map((entry) => <option key={entry.id} value={entry.id}>{formatFinanceMoney(entry.amount, currency)} · {entry.reference}</option>)}
        </select></label>}
      {old && <dl className="fd-money" data-testid="fd-fix-before">
        <MoneyLine label={t("fd.fix.before")} value={`${formatFinanceMoney(old.amount, currency)} · ${old.reference} · ${old.valueDate ? dueDay(old.valueDate) : "—"}`} testId="fd-fix-old" />
        <MoneyLine label={t("fd.money.ramburs")} value={formatFinanceMoney(ramburs ?? money.remaining, currency)} testId="fd-fix-ramburs" strong tone="accent" />
      </dl>}
      <div className="fd-grid">
        <label className="field"><span>{t("fd.fix.amount", { currency })}</span><input inputMode="decimal" autoComplete="off" value={amount} onChange={(event) => { setAmount(event.target.value); setProblem(null); }} data-testid="fd-fix-amount" /></label>
        <label className="field"><span>{t("fd.pay.bankReference")}</span><input autoComplete="off" maxLength={64} value={reference} onChange={(event) => { setReference(event.target.value); setProblem(null); }} data-testid="fd-fix-reference" /></label>
        <label className="field"><span>{t("fd.pay.date")}</span><input type="date" value={valueDate} onChange={(event) => { setValueDate(event.target.value); setProblem(null); }} data-testid="fd-fix-date" /></label>
        <label className="field fd-span"><span>{t("fd.fix.reason")}</span><input autoComplete="off" maxLength={255} value={reason} onChange={(event) => { setReason(event.target.value); setProblem(null); }} data-testid="fd-fix-reason" /></label>
      </div>
      <p className="fd-match-hint">{t("fd.fix.hint")}</p>
      <label className="check-field fd-statement"><input type="checkbox" checked={statement} onChange={(event) => { setStatement(event.target.checked); setProblem(null); }} data-testid="fd-fix-statement" />
        <span className="cf-check">{t("fd.sum.statement")}<small>{t("fr.form.statementHint")}</small></span></label>
      {(problem || follow.error) && <Notice tone="danger" role="alert" testId="fd-fix-problem">{problem ?? follow.error}</Notice>}
      {follow.lost && <Notice tone="warning" role="status" testId="fd-fix-lost">{t("fd.lost.text")}</Notice>}
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={follow.busy} onClick={onCancel}>{t("fd.stepBack")}</button>
        <button type="submit" className="button fd-warn" disabled={follow.busy} data-testid="fd-fix-send">{follow.busy ? t("fd.sending") : follow.lost ? t("fd.lost.check") : t("fd.fix.send")}<AppIcon name="arrow" size={20} /></button>
      </div>
    </form>
  );
}
