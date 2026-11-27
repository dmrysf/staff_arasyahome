import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, StaffServiceError } from "../../domain/models";
import { sourceLabel, type ConfirmareApi, type ConfirmareSource } from "../../domain/confirmare";
import { formatFinanceMoney, normalizeAmount, validReference } from "../../domain/confirmareFinance";
import {
  CLARIFICATION_REASONS, canOverseeFinance, financeOrderPath, financeTaskPath, maskIban, oversightPath, proformaLabel, reasonLabel, requestErrorKey, stateLabel, taskCodeLabel, taskOrderId,
  type ClarificationReason, type DecisionAnswer, type FinanceSearchItem, type FinanceTask, type Inbox, type InboxItem, type TaskCustomer, type Transfer,
} from "../../domain/financeRequests";
import {
  DEFAULT_TOLERANCE, MAX_TOLERANCE, SEARCH_MAX_LENGTH, TOLERANCES, amountBani, baniText, exactQuery, hasCriteria, inboxCandidate, parseBani, parseSearch, rankCandidates, searchCriteria, serverSearchable,
  type CandidateView, type ParsedSearch, type Ranked, type SearchReason,
} from "../../domain/financeSearch";
import {
  answerLost, bani, bucharestClock, checkEntry, checkMoney, checkPayment, deskRows, deskSearchState, deskSourceFilter, deskable, fromBani, nextDeskTask, noteDeskDone, paymentEffect, setDeskSearchState, setDeskSourceFilter, takeDeskDone, validValueDate, waitParts,
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

/** Staff 2.62.0: the name shown with the order number (the company when the order names one and no person). */
const clientLabel = (customer: TaskCustomer | null | undefined): string | null => customer?.name ?? customer?.company ?? null;

type ReasonText = (reason: SearchReason) => string;
function useReasonText(): ReasonText {
  const { t } = useI18n();
  return (reason) => {
    const kind = reason.kind && reason.kind !== "advance" ? ` (${t(`fd.kind.${reason.kind}` as MessageKey)})` : "";
    if (reason.code === "amountNear") return `${t("fd.why.amountNear")}${kind}`;
    if (reason.code === "amountExact") return `${t("fd.why.amountExact")}${kind}`;
    return t(`fd.why.${reason.code}` as MessageKey);
  };
}

/** The ranking view of an order of "Toate comenzile". */
const searchCandidate = (item: FinanceSearchItem): CandidateView => ({
  orderNumber: item.orderNumber, source: item.sourceKey, name: item.customer?.name ?? null, company: item.customer?.company ?? null, proforma: item.proforma, orderedAt: item.orderedAt,
  advance: amountBani(item.money?.advance), remaining: amountBani(item.money?.remaining), total: amountBani(item.money?.total), cod: amountBani(item.money?.cod), verified: amountBani(item.money?.verified), paid: item.paid,
});

/**
 * Staff 2.62.0 (Finance Smart Search V1): "Toate comenzile". The parsed criteria go to the API 300 ms after the last key
 * (GET /confirmare/finance/search, read only); a newer query aborts the older request and an answer is shown only for the
 * query it was asked for, so a late answer never replaces a newer one. The pool is ranked here like the current list.
 */
function useAllOrders(service: ConfirmareApi, parsed: ParsedSearch, tolerance: number, source: string | null, enabled: boolean) {
  const criteria = useMemo(() => searchCriteria(parsed, tolerance, source), [parsed, tolerance, source]);
  const key = JSON.stringify(criteria);
  const ready = enabled && serverSearchable(parsed);
  const [answer, setAnswer] = useState<{ key: string; items: FinanceSearchItem[]; truncated: boolean } | null>(null);
  const [failure, setFailure] = useState<{ key: string; error: unknown } | null>(null);
  useEffect(() => {
    if (!ready) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      service.financeSearch(criteria, controller.signal).then(
        (result) => { if (!controller.signal.aborted) setAnswer({ key, ...result }); },
        (caught: unknown) => { if (!controller.signal.aborted) setFailure({ key, error: caught }); },
      );
    }, 300);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [ready, key, criteria, service]);
  const current = ready && answer?.key === key ? answer : null;
  const ranked = useMemo(() => (current ? rankCandidates(parsed, current.items, searchCandidate, tolerance) : null), [current, parsed, tolerance]);
  return { ready, ranked, truncated: current?.truncated ?? false, error: ready && failure?.key === key ? failure.error : null, busy: ready && current === null && failure?.key !== key };
}

export function FinanceDeskScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const errorText = useErrorText();
  // Staff 2.62.0: the search, its scope and tolerance, the view and the scroll come back when the employee returns.
  const [saved] = useState(() => deskSearchState());
  const [view, setViewState] = useState<DeskView>(saved.view);
  const [source, setSource] = useState<string | null>(deskSourceFilter());
  const [query, setQueryState] = useState(saved.query);
  const [scope, setScopeState] = useState<"current" | "all">(saved.scope);
  const [tolerance, setToleranceState] = useState(saved.tolerance);
  const [asAmount, setAsAmountState] = useState(saved.asAmount);
  const [finished] = useState(() => takeDeskDone());
  const load = useMemo(() => (signal: AbortSignal) => service.financeInbox(signal), [service]);
  const inbox = useAutoRefresh(load, "finance-desk");
  const data = inbox.data;
  // The waiting time is measured at the list's own read (no clock read while rendering).
  const now = inbox.updatedAt ? new Date(inbox.updatedAt).getTime() : 0;
  const parsed = useMemo(() => parseSearch(query, { numbersAsAmounts: asAmount }), [query, asAmount]);
  const searching = hasCriteria(parsed);
  const baseRows = useMemo(() => (data ? deskRows(data, view, source, "", now) : null), [data, view, source, now]);
  // The current list is searched in the browser (the inbox carries names and amounts): no request per key.
  const ranked = useMemo(() => (baseRows && searching ? rankCandidates(parsed, baseRows, inboxCandidate, tolerance) : null), [baseRows, searching, parsed, tolerance]);
  const rows = ranked ? ranked.map((entry) => entry.item) : baseRows;
  const autoAll = scope === "current" && searching && ranked !== null && ranked.length === 0;
  const all = useAllOrders(service, parsed, tolerance, source, scope === "all" || autoAll);
  const kpis: { id: DeskView; tone: Tone; value: number | null }[] = [
    { id: "todo", tone: "accent", value: data?.counts.todo ?? null },
    { id: "waiting", tone: "danger", value: data?.counts.waiting ?? null },
    { id: "clarification", tone: "warning", value: data?.counts.clarification ?? null },
    { id: "today", tone: "success", value: data?.counts.today ?? null },
  ];
  const chooseSource = (next: string | null) => { setSource(next); setDeskSourceFilter(next); };
  const setView = (next: DeskView) => { setViewState(next); setDeskSearchState({ view: next }); };
  const setQuery = (next: string) => { setQueryState(next); setDeskSearchState({ query: next }); };
  const setScope = (next: "current" | "all") => { setScopeState(next); setDeskSearchState({ scope: next }); };
  const setTolerance = (next: number) => { setToleranceState(next); setDeskSearchState({ tolerance: next }); };
  const setAsAmount = (next: boolean) => { setAsAmountState(next); setDeskSearchState({ asAmount: next }); };
  // Opening a result keeps the scroll position for the way back.
  const go = (path: string) => { setDeskSearchState({ scrollY: window.scrollY }); navigate(path); };
  const restore = useRef(saved.scrollY);
  useEffect(() => {
    if (!data || restore.current <= 0) return;
    const y = restore.current;
    restore.current = 0;
    setDeskSearchState({ scrollY: 0 });
    window.requestAnimationFrame(() => window.scrollTo(0, y));
  }, [data]);
  const label = view === "done" ? "fd.done.all" : `fd.kpi.${view}`;
  const amountSearch = parsed.amounts.length > 0;
  const showAll = scope === "all" || autoAll;
  // Enter opens a result only for an exact order, payment reference or proforma with one result (never one of many).
  const onEnter = () => {
    if (!exactQuery(parsed)) return;
    const local = scope === "current" ? (ranked ?? []).filter((entry) => entry.exact) : [];
    if (local.length === 1 && local[0]) return go(rowTarget(local[0].item));
    const remote = showAll ? (all.ranked ?? []).filter((entry) => entry.exact) : [];
    const task = remote.length === 1 ? remote[0]?.item.task : null;
    if (task && task.mine && task.open) return go(financeTaskPath(task.id));
    if (scope === "current" && local.length === 0) setScope("all");
  };
  // The closed tasks beyond the inbox are searched by an order or proforma number only (the history endpoint).
  const historyQuery = parsed.orders[0] ?? parsed.references[0]?.order ?? parsed.proformas[0]?.number ?? "";

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
          <input type="search" inputMode="search" autoComplete="off" spellCheck={false} maxLength={SEARCH_MAX_LENGTH} placeholder={t("fd.search.placeholder")} value={query}
            onChange={(event) => setQuery(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); onEnter(); } }} data-testid="fd-search" /></label>
        <SourceFilter sources={SOURCES} value={source} onChange={chooseSource} />
      </div>
      {searching && <SearchAssist parsed={parsed} scope={scope} onScope={setScope} tolerance={tolerance} onTolerance={setTolerance} asAmount={asAmount} onAsAmount={setAsAmount} />}
      {finished && <Notice tone="success" role="status" testId="fd-done">{t("fd.next.done", { order: finished })} {t("fd.next.none")}</Notice>}
      {inbox.error && (!data || inbox.stopped) && <Notice tone="danger" role="alert">{errorText(inbox.error)}</Notice>}
      {!data && !inbox.error && <QueueSkeleton />}
      {scope === "current" && rows && rows.length === 0 && !autoAll && <p className="fd-empty" data-testid="fd-empty">{t(`fd.empty.${view}` as MessageKey)}</p>}
      {scope === "current" && rows && rows.length > 0 && <DeskTable rows={rows} ranked={ranked} diff={amountSearch} now={now} navigate={go} label={t(label as MessageKey)} />}
      {showAll && <AllOrders state={all} autoAll={autoAll} diff={amountSearch} service={service} navigate={go} />}
      {view === "done" && data && scope === "current" && (!searching || historyQuery !== "") && <DeskHistory service={service} source={source} query={historyQuery} now={now} navigate={go} shown={rows ?? []} />}
      {searching && <p className="dashboard-footnote" data-testid="fd-research">{t("fd.assist.research")}</p>}
      <div className="fd-foot">
        {view !== "done" && data && <button type="button" className="button button-link" onClick={() => setView("done")} data-testid="fr-category-done">{t("fd.done.link", { n: data.counts.done })}</button>}
        {view !== "todo" && <button type="button" className="button button-link" onClick={() => setView("todo")}>{t("fd.kpi.todo")}</button>}
        {canOverseeFinance(employee) && <button type="button" className="button button-link" onClick={() => go(oversightPath())} data-testid="fr-open-oversight">{t("fr.oversight.open")}</button>}
      </div>
      {data && data.items.length >= data.limit && <p className="dashboard-footnote">{t("fd.limit")}</p>}
    </article>
  );
}

/**
 * Staff 2.62.0: the line under the search box, only while searching: what the query was read as (the total of a sum,
 * the amount, a number read as an order with "search as an amount too", the payment date), the scope, and the amount
 * tolerance only for an amount search. A research filter: it accepts, records and decides nothing.
 */
function SearchAssist({ parsed, scope, onScope, tolerance, onTolerance, asAmount, onAsAmount }: {
  parsed: ParsedSearch; scope: "current" | "all"; onScope: (scope: "current" | "all") => void; tolerance: number; onTolerance: (value: number) => void; asAmount: boolean; onAsAmount: (value: boolean) => void;
}) {
  const { t, formatDate } = useI18n();
  const [custom, setCustom] = useState(() => ((TOLERANCES as readonly number[]).includes(tolerance) ? "" : baniText(tolerance).replace(".", ",")));
  const amount = parsed.amounts[0];
  return (
    <div className="fd-assist" data-testid="fd-assist">
      <div className="fd-scope" role="group" aria-label={t("fd.scope")} data-testid="fd-scope">
        {(["current", "all"] as const).map((value) => <button key={value} type="button" className="fd-chip" aria-pressed={scope === value} onClick={() => onScope(value)} data-testid={`fd-scope-${value}`}>{t(`fd.scope.${value}` as MessageKey)}</button>)}
      </div>
      {parsed.sum && <strong className="fd-assist-total" data-testid="fd-sum">{t("fd.assist.total", { amount: formatFinanceMoney(baniText(parsed.sum.total), "RON") })}</strong>}
      {!parsed.sum && amount !== undefined && <span className="fd-assist-total" data-testid="fd-amount">{t("fd.assist.amount", { amount: formatFinanceMoney(baniText(amount), "RON") })}</span>}
      {parsed.date && <span data-testid="fd-date">{t("fd.assist.date", { date: formatDate(`${parsed.date}T12:00:00Z`, { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "Europe/Bucharest" }) })}</span>}
      {parsed.ambiguous.length > 0 && <span className="fd-assist-order">{t("fd.assist.order", { n: parsed.ambiguous.join(", ") })}
        <button type="button" className="fd-chip" aria-pressed={asAmount} onClick={() => onAsAmount(!asAmount)} data-testid="fd-as-amount">{t("fd.assist.asAmount")}</button></span>}
      {parsed.amounts.length > 0 && <div className="fd-tol" role="group" aria-label={t("fd.tol")} data-testid="fd-tolerance">
        {TOLERANCES.map((value) => <button key={value} type="button" className="fd-chip" aria-pressed={tolerance === value && custom === ""} onClick={() => { setCustom(""); onTolerance(value); }} data-testid={`fd-tol-${value}`}>
          {value === 0 ? t("fd.tol.exact") : t("fd.tol.value", { n: value / 100 })}</button>)}
        <label className={`fd-chip fd-tol-custom${custom !== "" ? " is-on" : ""}`}><span>{t("fd.tol.custom")}</span>
          <input inputMode="decimal" autoComplete="off" maxLength={7} placeholder={t("fd.tol.customHint")} value={custom} data-testid="fd-tol-custom"
            onChange={(event) => { const text = event.target.value; setCustom(text); const value = parseBani(text); if (value !== null) onTolerance(Math.min(MAX_TOLERANCE, value)); else if (text === "") onTolerance(DEFAULT_TOLERANCE); }} /></label>
      </div>}
    </div>
  );
}

/**
 * The reasons under the customer's name, from the ranking (the research priority's explanation, at most two lines). A
 * payment already recorded comes first and alone in red (a second one would be a duplicate). The amount's difference is
 * in its own column; the line says only whether the amount is exact or near, and which amount ("total comandă").
 */
function Reasons({ entry, testId }: { entry: Ranked<unknown>; testId: string }) {
  const text = useReasonText();
  const { t } = useI18n();
  const amountOnly = entry.reasons.some((reason) => reason.code === "amountOnly");
  const shown = entry.reasons.filter((reason) => reason.code !== "recorded" && !(amountOnly && (reason.code === "amountExact" || reason.code === "amountNear")));
  return <small className="fd-why" data-testid={testId}>
    {entry.recorded && <strong className="fd-recorded">{t("fd.why.recorded")}{shown.length > 0 ? " · " : ""}</strong>}
    {shown.map((reason) => text(reason)).join(" · ")}</small>;
}

function DiffCell({ entry, currency, testId }: { entry: Ranked<unknown> | null; currency: string; testId: string }) {
  const { t } = useI18n();
  const amount = entry?.amount ?? null;
  return <span role="cell" className="fd-c-diff" data-testid={testId}>{amount ? <><strong>{formatFinanceMoney(baniText(amount.diff), currency)}</strong>
    {amount.kind !== "advance" && <small>{t(`fd.kind.${amount.kind}` as MessageKey)} {formatFinanceMoney(baniText(amount.value), currency)}</small>}</> : "—"}</span>;
}

const COLUMNS = ["order", "shop", "ordered", "age", "proforma", "advance", "action"] as const;
const COLUMNS_DIFF = ["order", "shop", "ordered", "age", "proforma", "advance", "diff", "action"] as const;
/** "Toate comenzile" has no waiting time (an order may have no task): the same table without that column. */
const COLUMNS_ALL = ["order", "shop", "ordered", "proforma", "advance", "action"] as const;
const COLUMNS_ALL_DIFF = ["order", "shop", "ordered", "proforma", "advance", "diff", "action"] as const;

function DeskTable({ rows, ranked = null, diff = false, now, navigate, label, testId = "fd-table" }: {
  rows: InboxItem[]; ranked?: Ranked<InboxItem>[] | null; diff?: boolean; now: number; navigate: (path: string) => void; label: string; testId?: string;
}) {
  const { t } = useI18n();
  const byId = new Map((ranked ?? []).map((entry) => [entry.item.id, entry]));
  return (
    <div className={`fd-table${diff ? " has-diff" : ""}`} role="table" aria-label={label} data-testid={testId}>
      <div className="fd-row fd-row-head" role="row">
        {(diff ? COLUMNS_DIFF : COLUMNS).map((column) => <span key={column} role="columnheader" className={`fd-c-${column}`}>{t(`fd.col.${column}` as MessageKey)}</span>)}
      </div>
      {rows.map((item) => <DeskRow key={item.id} item={item} entry={byId.get(item.id) ?? null} diff={diff} now={now} navigate={navigate} />)}
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

/**
 * Staff 2.62.0: a task's state is no column any more (owner 2026-11-27: it took room and helped nobody find a customer);
 * only what needs attention stays, as a small note under the name: an exceptional or second-check task, the manual
 * ledger, and the outcome of a closed task.
 */
function rowNote(item: InboxItem, t: (key: MessageKey) => string): string | null {
  if (item.kind === "exception") return taskCodeLabel(item.taskCode) || t("fr.kind.exception" as MessageKey);
  if (item.kind !== "verify" && item.category !== "done") return t(`fr.kind.${item.kind}` as MessageKey);
  if (item.awaitingPayment) return null;
  if (item.category === "done") return stateLabel(item.state);
  return null;
}

function DeskRow({ item, entry, diff, now, navigate }: { item: InboxItem; entry: Ranked<InboxItem> | null; diff: boolean; now: number; navigate: (path: string) => void }) {
  const { t, formatDate } = useI18n();
  const wait = waitParts(item.requestedAt, now);
  const age = wait.unit === "days" ? t("fd.age.days", { d: wait.d, h: wait.h }) : t(`fd.age.${wait.unit}` as MessageKey, { n: wait.n });
  const advance = item.advance;
  const currency = advance?.currency ?? "RON";
  const rest = advance && advance.remaining !== null && advance.required !== null && advance.remaining !== advance.required ? advance.remaining : null;
  const note = rowNote(item, t);
  const client = clientLabel(item.customer);
  const check = deskable(item);
  return (
    // Staff 2.55.0: the whole row opens the task (the button stays for the keyboard and screen readers).
    <div className="fd-row is-clickable" role="row" tabIndex={-1} data-testid={`fr-card-${item.orderNumber}`}
      onClick={(event) => { if (!(event.target as HTMLElement).closest("button")) navigate(rowTarget(item)); }}
      onKeyDown={(event) => { if (event.key === "Enter" && event.target === event.currentTarget) navigate(rowTarget(item)); }}>
      <span role="cell" className="fd-c-order">
        <span className="fd-order-line"><strong className="mono">#{item.orderNumber}</strong>{client && <><span className="fd-dash" aria-hidden="true">—</span><span className="fd-client" title={client} data-testid={`fd-client-${item.orderNumber}`}>{client}</span></>}</span>
        {entry && <Reasons entry={entry} testId={`fd-why-${item.orderNumber}`} />}
        {!entry && note && <small className="fd-why" data-testid={`fr-card-state-${item.orderNumber}`}>{note}</small>}
        {item.ledger === "manual" && <small className="fd-why fd-tag" data-testid={`fr-card-manual-${item.orderNumber}`}>{t("fr.ledger.manual")}</small>}
      </span>
      <span role="cell" className="fd-c-shop">{sourceLabel(item.source)}</span>
      <span role="cell" className="fd-c-ordered">{item.orderedAt ? formatDate(item.orderedAt, { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "Europe/Bucharest" }) : "—"}</span>
      <span role="cell" className={`fd-c-age${wait.unit === "days" && wait.d >= 2 && item.category !== "done" ? " is-late" : ""}`}>{age}</span>
      <span role="cell" className="fd-c-proforma mono">{proformaLabel(item.proforma)}</span>
      <span role="cell" className="fd-c-advance" data-testid={`fd-advance-${item.orderNumber}`}>{advance ? <><strong>{formatFinanceMoney(advance.required, advance.currency)}</strong>{rest && <small>{t("fd.advance.rest", { amount: formatFinanceMoney(rest, advance.currency) })}</small>}</> : "—"}</span>
      {diff && <DiffCell entry={entry} currency={currency} testId={`fd-diff-${item.orderNumber}`} />}
      <span role="cell" className="fd-c-action">
        <button type="button" className={`button ${check ? "button-primary" : "button-secondary"} fd-row-check`} onClick={() => navigate(rowTarget(item))} data-testid={`fr-open-${item.orderNumber}`}>{t(check ? "fd.row.check" : "fd.row.open")}<AppIcon name="arrow" size={16} /></button>
      </span>
    </div>
  );
}

/**
 * Staff 2.62.0: "Toate comenzile", in the same table. "Verifică" is the payment check of Staff 2.57.0 unchanged
 * (POST /confirmare/finance/check on her click: her open task, a free one moved to her, or a new one; a colleague's
 * check in progress is never taken). A closed verified task of hers opens read-only. The search itself changes nothing.
 */
function AllOrders({ state, autoAll, diff, service, navigate }: { state: ReturnType<typeof useAllOrders>; autoAll: boolean; diff: boolean; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t, formatDate } = useI18n();
  const errorText = useErrorText();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const keys = useRef<Record<string, string>>({});
  const check = async (item: FinanceSearchItem) => {
    setError(null); setBusy(item.globalOrderId);
    keys.current[item.globalOrderId] ??= createIdempotencyKey();
    try {
      const answer = await service.financeCheckOrder(item.globalOrderId, keys.current[item.globalOrderId]);
      delete keys.current[item.globalOrderId];
      navigate(financeTaskPath(answer.requestId));
    } catch (caught) { setError(errorText(caught)); } finally { setBusy(null); }
  };
  const ranked = state.ranked;
  return (
    <section className="fd-lookup" aria-labelledby="fd-lookup-title" aria-busy={state.busy} data-testid="fd-lookup">
      <h2 id="fd-lookup-title">{t(autoAll ? "fd.all.auto" : "fd.all.title")}</h2>
      {!state.ready && <p className="fd-empty">{t("fd.all.short")}</p>}
      {state.busy && <p className="fd-muted" role="status">{t("fd.all.searching")}</p>}
      {state.error !== null && <Notice tone="danger" role="alert">{errorText(state.error)}</Notice>}
      {error && <Notice tone="danger" role="alert">{error}</Notice>}
      {ranked && ranked.length === 0 && <p className="fd-empty" data-testid="fd-lookup-none">{t("fd.all.none")}</p>}
      {ranked && ranked.length > 0 && <div className={`fd-table fd-table-all${diff ? " has-diff" : ""}`} role="table" aria-label={t("fd.all.title")}>
        <div className="fd-row fd-row-head" role="row">
          {(diff ? COLUMNS_ALL_DIFF : COLUMNS_ALL).map((column) => <span key={column} role="columnheader" className={`fd-c-${column}`}>{t(`fd.col.${column}` as MessageKey)}</span>)}
        </div>
        {ranked.map((entry) => {
          const item = entry.item;
          const currency = item.money?.currency ?? "RON";
          const client = clientLabel(item.customer);
          const task = item.task;
          const doneMine = task !== null && !task.open && task.mine && task.state === "verified";
          return (
            <div key={item.globalOrderId} className="fd-row" role="row" data-testid={`fd-lookup-${item.orderNumber}`}>
              <span role="cell" className="fd-c-order">
                <span className="fd-order-line"><strong className="mono">#{item.orderNumber}</strong>{client && <><span className="fd-dash" aria-hidden="true">—</span><span className="fd-client" title={client}>{client}</span></>}</span>
                <Reasons entry={entry} testId={`fd-lookup-why-${item.orderNumber}`} />
                {task && <small className="fd-why fd-task-state" data-testid={`fd-lookup-task-${item.orderNumber}`}>{stateLabel(task.state as never)}{!task.mine && task.open ? ` · ${t("fd.search.taskOf", { name: task.assignee ?? "—" })}` : ""}</small>}
              </span>
              <span role="cell" className="fd-c-shop">{sourceLabel(item.sourceKey)}</span>
              <span role="cell" className="fd-c-ordered">{item.orderedAt ? formatDate(item.orderedAt, { day: "2-digit", month: "2-digit", year: "numeric", timeZone: "Europe/Bucharest" }) : "—"}</span>
              <span role="cell" className="fd-c-proforma mono">{proformaLabel(item.proforma)}</span>
              <span role="cell" className="fd-c-advance">{item.money?.advance ? <strong>{formatFinanceMoney(item.money.advance, currency)}</strong> : "—"}</span>
              {diff && <DiffCell entry={entry} currency={currency} testId={`fd-lookup-diff-${item.orderNumber}`} />}
              <span role="cell" className="fd-c-action">
                {doneMine
                  ? <button type="button" className="button button-secondary fd-row-check" onClick={() => navigate(financeTaskDonePath(task.id))} data-testid={`fd-lookup-open-${item.orderNumber}`}>{t("fd.all.open")}<AppIcon name="arrow" size={16} /></button>
                  : <button type="button" className="button button-primary fd-row-check" disabled={busy !== null || !item.cod} onClick={() => { void check(item); }} data-testid={`fd-lookup-check-${item.orderNumber}`}>{t("fd.row.check")}<AppIcon name="arrow" size={16} /></button>}
              </span>
            </div>
          );
        })}
      </div>}
      {state.truncated && <p className="dashboard-footnote" data-testid="fd-lookup-more">{t("fd.all.more")}</p>}
    </section>
  );
}

// ---- The three-step check ---------------------------------------------------------------------------------------

type Step =
  | { kind: "question" }
  | { kind: "payment"; form: PaymentForm }
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
  // Staff 2.57.0: the payment the employee confirmed, kept for a replay with the same key after a lost answer.
  const lastPayment = useRef<PaymentInput | null>(null);
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
  // Staff 2.57.0 (Operation Simplification V1): two steps. 1: "A fost primită plata?" (NU records it at once); 2: the payment.
  const number = step.kind === "question" ? 1 : 2;
  const tone = step.kind === "no" || step.kind === "no-summary" ? (step.kind === "no-summary" && step.outcome === "needs_clarification" ? "warning" : "danger") : "success";
  const blocked = Boolean(live?.proformaChanged);
  const waiting = busy || starting;
  const client = clientLabel(request.customer);
  const proformaText = live?.proforma ? proformaLabel({ series: live.proforma.series || null, number: live.proforma.number }) : proformaLabel(request.proforma);
  const advanceDue = remaining ?? live?.advance?.required ?? null;

  // A lost answer, or a decision the shop has not confirmed yet: settle it with the stored key, never a new payment.
  if (entry === "reconcile" || lost) {
    const recheck = async () => {
      if (entry === "reconcile") return send(task, `reconcile|${request.version}`, () => service.reconcileTask(request.id));
      if (intent.current) {
        // Same key again: the server answers with the stored result of the first send.
        const pending = intent.current.id;
        if (pending.startsWith("verified|") && lastPayment.current && live) { const input = lastPayment.current; return send(task, pending, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "verified", expectedRevision: live.revision, currency, ...input }, key)); }
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
        {/* Staff 2.62.0: the customer's name first, with small copy buttons for the bank screen on another computer. */}
        <div className="fd-full fd-client-fact"><dt>{t("fd.fact.client")}</dt><dd data-testid="fd-task-client">{client ?? "—"}{client && <CopyButton value={client} what={t("fd.fact.client")} testId="fd-copy-client" />}</dd></div>
        <div><dt>{t("fd.fact.order")}</dt><dd className="mono">#{request.orderNumber}<CopyButton value={request.orderNumber} what={t("fd.fact.order")} testId="fd-copy-order" /></dd></div>
        <div><dt>{t("fd.fact.channel")}</dt><dd>{sourceLabel(request.source)}</dd></div>
        <div><dt>{t("fd.fact.proforma")}</dt><dd className="mono">{proformaText}{proformaText !== "—" && <CopyButton value={proformaText} what={t("fd.fact.proforma")} testId="fd-copy-proforma" />}</dd></div>
        <div><dt>{t("fd.fact.reference")}</dt><dd className="mono" data-testid="fr-payment-reference"><strong>{reference ?? "—"}</strong>{reference && <CopyButton value={reference} what={t("fd.fact.reference")} testId="fd-copy-reference" />}</dd></div>
        <div className="fd-wide"><dt>{t("fd.fact.bank")}</dt><dd>{live?.bank ? <>{live.bank.bank} · <span className="mono">{maskIban(live.bank.ibanMasked)}</span></> : "—"}</dd></div>
        <div className="fd-full"><dt>{t("fd.fact.total")}</dt><dd data-testid="fd-total">{formatFinanceMoney(money.total, currency)}</dd></div>
        <div className="fd-amount"><dt>{t("fd.fact.advance")}</dt><dd data-testid="fr-remaining">{formatFinanceMoney(advanceDue, currency)}{advanceDue !== null && <CopyButton value={advanceDue.replace(".", ",")} what={t("fd.fact.advance")} testId="fd-copy-advance" />}</dd></div>
      </dl>
      {live?.advance && bani(live.advance.verified ?? "0") > 0 && <p className="fd-already" data-testid="fd-already">{t("fd.fact.already", { amount: formatFinanceMoney(live.advance.verified, currency), remaining: formatFinanceMoney(remaining, currency) })}</p>}
      {!live && <Notice tone="warning" role="note" testId="fr-live-unavailable">{t("fd.unavailable")}</Notice>}
      {live?.stale && <Notice tone="warning" role="note" testId="fr-live-stale">{t("fd.stale", { at: bucharestClock(live.readAt) })}</Notice>}
      {blocked && <Notice tone="danger" role="alert" testId="fr-proforma-changed">{t("fr.task.proformaChanged")}</Notice>}
      {request.ledger === "manual" && <Notice tone="info" role="note" testId="fr-task-manual">{t("fr.task.manualLedger")}</Notice>}
      {live?.settledElsewhere && <Notice tone="info" role="note" testId="fr-settled">{t("fr.task.settledElsewhere")}</Notice>}
      {live?.financial.attention && <Notice tone="warning" role="note" testId="fr-attention">{t("fr.task.attention")}</Notice>}
      {error && <Notice tone="danger" role="alert" testId="fr-task-error">{error}</Notice>}

      {step.kind === "question" && <section className="fd-panel" data-testid="fd-question">
        <h2 className="fd-question">{t("fd.q.received")}</h2>
        <div className="fd-choice">
          <button type="button" className="button fd-yes" disabled={waiting || !live || blocked || !task.allowed.verify} onClick={() => setStep({ kind: "payment", form: EMPTY_FORM })} data-testid="fr-mode-verified"><AppIcon name="check" size={22} />{t("fd.yes")}</button>
          <button type="button" className="button fd-no" disabled={waiting || !task.allowed.notReceived}
            onClick={() => void send(task, `nr|${request.version}|not_found`, (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "not_received", reasonCode: "not_found" }, key))}
            data-testid="fr-mode-not-received"><AppIcon name="close" size={22} />{t("fd.no")}</button>
        </div>
        <p className="fd-no-hint">{t("fd.no.anytime")}</p>
        {task.allowed.clarify && <button type="button" className="button button-ghost" disabled={waiting} onClick={() => setStep({ kind: "no" })} data-testid="fr-mode-clarify-open">{t("fd.no.clarify")}</button>}
        {!live && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("fd.retry")}</button>}
      </section>}

      {step.kind === "payment" && live && <PaymentStep form={step.form} currency={currency} money={money} reference={reference} canMatch={canMatch} busy={busy}
        onBack={() => setStep({ kind: "question" })}
        onReady={(form, input) => { void form; lastPayment.current = input; void send(task, `verified|${request.version}|${input.amount}|${input.reference}|${input.valueDate}`,
          (key) => service.decideTask(request.id, { expectedVersion: request.version, outcome: "verified", expectedRevision: live.revision, currency, ...input }, key)); }}
        onClarify={(form, reason) => { void form; setStep({ kind: "no-summary", outcome: "needs_clarification", reason }); }} />}

      {step.kind === "no" && <NoStep busy={busy} clarify={task.allowed.clarify} onBack={() => setStep({ kind: "question" })}
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

/** Staff 2.62.0: a small copy button (the name, the order, the proforma, the reference, the advance). Copies, nothing else. */
function CopyButton({ value, what, testId }: { value: string; what: string; testId: string }) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  const copy = () => {
    const done = () => { setCopied(true); window.setTimeout(() => setCopied(false), 1500); };
    try { void navigator.clipboard?.writeText(value).then(done, () => undefined); } catch { /* no clipboard: nothing to do */ }
  };
  return <button type="button" className="fd-copy" onClick={copy} aria-label={t("fd.copy", { what })} title={copied ? t("fd.copied") : t("fd.copy", { what })} data-testid={testId}>
    <AppIcon name={copied ? "check" : "copy"} size={14} /></button>;
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
      <p className="fd-declaration" data-testid="fd-declaration">{t("fd.pay.declaration")}</p>
      <div className="fd-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={onBack} data-testid="fd-back">{t("fd.stepBack")}</button>
        <button type="submit" className="button fd-yes fd-confirm" disabled={busy} data-testid="fr-verify-next">{busy ? t("fd.sending") : t("fd.pay.confirmContinue")}<AppIcon name="arrow" size={20} /></button>
      </div>
    </form>
  );
}

const matchText = (input: { matchBasis?: string | null; payerName?: string | null; payer?: string | null }, t: (key: MessageKey) => string): string =>
  input.matchBasis && input.matchBasis !== "proforma_reference" ? `${t(`fd.match.${input.matchBasis}` as MessageKey)} · ${input.payerName ?? input.payer ?? ""}` : t("fd.match.proforma_reference");

/** Staff 2.57.0: "NU" is recorded at once from step 1; this panel only asks for a clarification when something is unclear. */
function NoStep({ busy, clarify, onBack, onClarify }: {
  busy: boolean; clarify: boolean; onBack: () => void; onClarify: (reason: ClarificationReason) => void;
}) {
  const { t } = useI18n();
  const [reasons, setReasons] = useState(false);
  return (
    <section className="fd-panel" data-testid="fr-reason-form">
      <h2>{t("fd.no.title")}</h2>
      {!reasons && <div className="fd-options">
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
        <div><p className="eyebrow">{sourceLabel(request.source)}</p><h1 className="mono">#{request.orderNumber}</h1>
          {clientLabel(request.customer) && <p className="fd-done-client" data-testid="fd-done-client">{clientLabel(request.customer)}</p>}</div>
        <StatusPill tone={tone} testId="fd-done-state">{request.awaitingPayment ? t("fd.state.waiting") : stateLabel(request.state)}</StatusPill>
      </header>
      {saved && <Notice tone="success" role="status" testId="fd-done-saved">{saved}</Notice>}
      {!live && <Notice tone="warning" role="note" testId="fr-live-unavailable">{t("fd.unavailable")}</Notice>}
      {live?.stale && <Notice tone="warning" role="note" testId="fr-live-stale">{t("fd.stale", { at: bucharestClock(live.readAt) })}</Notice>}
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
