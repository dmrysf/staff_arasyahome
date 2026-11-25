import { normalizeAmount, validReference } from "./confirmareFinance";
import type { ClarificationReason, FinanceTask, Inbox, InboxItem, MatchBasis } from "./financeRequests";

/**
 * The Contabilitate desk (Staff 2.53.0, owner 2026-11-25): Kontrol et -> Karar ver -> Onayla -> Sıradaki sipariş.
 *
 * Presentation only. The desk reuses the finance task commands unchanged (start, decide, reconcile): the server checks
 * the employee's scopes, the shop's revision, the proforma, the payment reference, the currency and the duplicate bank
 * reference again on every command. Nothing here decides money: the list amount is display data (API 2.77.9
 * finance_task_amounts) and the payment check always reads the shop live before anything is confirmed.
 */
export const DESK_VIEWS = ["todo", "waiting", "clarification", "done", "today"] as const;
export type DeskView = (typeof DESK_VIEWS)[number];

const BUCHAREST_DAY = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Bucharest", year: "numeric", month: "2-digit", day: "2-digit" });
const BUCHAREST_CLOCK = new Intl.DateTimeFormat("en-GB", { timeZone: "Europe/Bucharest", day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false });
/** Staff 2.56.0: "25/11, 19:40" in Europe/Bucharest (when the shop's copy shown was read). */
export const bucharestClock = (iso: string): string => { const ms = Date.parse(iso); return Number.isFinite(ms) ? BUCHAREST_CLOCK.format(new Date(ms)) : "—"; };
/** The calendar day (YYYY-MM-DD) of an instant in Europe/Bucharest. */
export const bucharestDay = (ms: number): string => BUCHAREST_DAY.format(new Date(ms));

/** Staff 2.55.0: a task this employee decided today (Bucharest), the rows behind "Finalizate azi". */
export function decidedToday(item: InboxItem, nowMs: number): boolean {
  return item.decidedByMe && item.decidedAt !== null && bucharestDay(Date.parse(item.decidedAt)) === bucharestDay(nowMs);
}

/** The view a task belongs to on the desk (a not received advance still open for a payment is "waiting"). */
export function deskView(item: InboxItem): DeskView {
  if (item.awaitingPayment) return "waiting";
  return item.category;
}

/** A task the three-step check handles: an open advance check of this employee. Every other task keeps its task screen. */
export function deskable(item: InboxItem): boolean {
  return item.purpose === "advance" && item.kind === "verify" && item.category === "todo" && item.assignedToMe;
}

const searchable = (value: string): string => value.toUpperCase().replace(/[^A-Z0-9]/g, "");

/** Whether a task matches the search box (order number or proforma, spaces and separators ignored). */
export function matchesSearch(item: InboxItem, query: string): boolean {
  const wanted = searchable(query);
  if (!wanted) return true;
  const proforma = item.proforma ? searchable(`${item.proforma.series ?? ""}${item.proforma.number}`) : "";
  return searchable(item.orderNumber).includes(wanted) || (proforma !== "" && (proforma.includes(wanted) || searchable(item.proforma?.number ?? "").includes(wanted)));
}

const time = (value: string | null): number => (value ? Date.parse(value) : Number.NaN);

/** The rows of one desk view: oldest task first (the most recently closed first in "done"). */
export function deskRows(inbox: Inbox, view: DeskView, source: string | null, query: string, nowMs: number = Date.now()): InboxItem[] {
  const rows = inbox.items.filter((item) => (view === "today" ? deskView(item) !== "todo" && decidedToday(item, nowMs) : deskView(item) === view) && (source === null || item.source === source) && matchesSearch(item, query));
  if (view === "done" || view === "today") return rows.sort((a, b) => time(b.updatedAt) - time(a.updatedAt) || a.id.localeCompare(b.id));
  return rows.sort((a, b) => time(a.requestedAt) - time(b.requestedAt) || a.id.localeCompare(b.id));
}

/** The next task to check after one is done: the oldest open advance check (same shop filter), never the same task. */
export function nextDeskTask(inbox: Inbox, after: string, source: string | null, skip: readonly string[] = []): InboxItem | null {
  return deskRows(inbox, "todo", source, "").find((item) => deskable(item) && item.id !== after && !skip.includes(item.id)) ?? null;
}

/** How long a task has waited, in whole minutes, hours or days and hours. */
export function waitParts(fromIso: string, nowMs: number): { unit: "minutes" | "hours" | "days"; n: number; d: number; h: number } {
  const minutes = Math.max(0, Math.floor((nowMs - time(fromIso)) / 60000));
  if (!Number.isFinite(minutes) || minutes < 60) return { unit: "minutes", n: Number.isFinite(minutes) ? minutes : 0, d: 0, h: 0 };
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return { unit: "hours", n: hours, d: 0, h: 0 };
  return { unit: "days", n: hours, d: Math.floor(hours / 24), h: hours % 24 };
}

/** Whole bani of a decimal amount ("190.00" -> 19000). */
export const bani = (amount: string): number => Math.round(Number(amount) * 100);
export const fromBani = (value: number): string => (value / 100).toFixed(2);

export type PaymentForm = {
  amount: string; bankReference: string; valueDate: string; statementReference: string;
  /** Staff 2.55.0 (owner 2026-11-25): how the transfer is tied to the order when the statement shows no proforma reference. */
  matchBasis: "" | Exclude<MatchBasis, "proforma_reference">; payerName: string; matchNote: string;
};
export type PaymentInput = { amount: string; reference: string; valueDate: string; matchBasis: MatchBasis; paymentReference?: string; payerName?: string; matchNote?: string };
/** The order's money for the check: what the order still costs (cash on delivery now) and the advance still asked. */
export type CheckMoney = { remaining: string | null; advanceRemaining: string | null; total: string | null; received: string | null };
/** What the payment changes: the new cash on delivery, the part of the asked advance still unpaid, any extra over the advance. */
export type PaymentEffect = { ramburs: string | null; advanceGap: string | null; overAdvance: string | null; totalReceived: string | null };
/**
 * Step 2 of the check (Staff 2.55.0, owner 2026-11-25: finance accepts the real money).
 * - Any positive amount up to what the order still costs is recorded as typed and accepted: a smaller advance is no
 *   problem and no operator acceptance follows; a larger one lowers the cash on delivery. Only more than the order
 *   costs (`over`) goes to a clarification: the excess is a finance exception, never recorded here.
 * - The statement need not show the proforma reference: without it the payer is named (`payerName`), and for another
 *   payer or the customer's proof the evidence too. A shop that does not accept a payer match yet (`canMatch` false)
 *   keeps the old rule: a missing or different reference is a clarification (`mismatch`).
 */
export type PaymentCheck =
  | { kind: "invalid"; field: "amount" | "bankReference" | "valueDate" | "matchBasis" | "payerName" | "matchNote" }
  | { kind: "over"; input: PaymentInput; clarify: ClarificationReason; excess: string }
  | { kind: "mismatch"; input: PaymentInput; clarify: ClarificationReason }
  | { kind: "ok"; input: PaymentInput; effect: PaymentEffect };

const PAYER = /^[\p{L}\p{M}0-9][\p{L}\p{M}0-9 .,&'()-]{1,127}$/u;
const clean = (value: string): string => value.trim().replace(/\s+/g, " ");

/** The value date is a real calendar day, not in the future. */
export function validValueDate(value: string, nowMs: number): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const day = Date.parse(`${value}T00:00:00Z`);
  return Number.isFinite(day) && new Date(day).toISOString().slice(0, 10) === value && day <= nowMs + 36 * 3600 * 1000;
}

/** What a typed amount changes (whole bani; never a negative cash on delivery). */
export function paymentEffect(amount: string | null, money: CheckMoney): PaymentEffect {
  const paid = amount === null ? 0 : bani(amount);
  const rest = money.remaining === null ? null : bani(money.remaining);
  const asked = money.advanceRemaining === null ? null : bani(money.advanceRemaining);
  return {
    ramburs: rest === null ? null : fromBani(Math.max(0, rest - paid)),
    advanceGap: asked === null ? null : fromBani(Math.max(0, asked - paid)),
    overAdvance: asked === null || paid <= asked ? null : fromBani(paid - asked),
    totalReceived: money.received === null ? null : fromBani(bani(money.received) + paid),
  };
}

export function checkPayment(form: PaymentForm, expectedReference: string | null, money: CheckMoney, nowMs: number, canMatch = true): PaymentCheck {
  const amount = normalizeAmount(form.amount);
  if (!amount || bani(amount) <= 0) return { kind: "invalid", field: "amount" };
  if (!validReference(form.bankReference)) return { kind: "invalid", field: "bankReference" };
  if (!validValueDate(form.valueDate, nowMs)) return { kind: "invalid", field: "valueDate" };
  const statement = form.statementReference.trim().toUpperCase();
  const byReference = statement !== "" && expectedReference !== null && statement === expectedReference.toUpperCase();
  const payer = clean(form.payerName);
  const note = clean(form.matchNote);
  const base = { amount, reference: form.bankReference.trim(), valueDate: form.valueDate };
  let input: PaymentInput;
  if (byReference) {
    input = { ...base, matchBasis: "proforma_reference", paymentReference: statement, ...(payer && PAYER.test(payer) ? { payerName: payer } : {}) };
  } else if (!canMatch) {
    // An older shop: only the proforma reference ties a transfer to the order.
    return { kind: "mismatch", input: { ...base, matchBasis: "proforma_reference", paymentReference: statement }, clarify: "reference_missing" };
  } else {
    if (form.matchBasis === "") return { kind: "invalid", field: "matchBasis" };
    if (!PAYER.test(payer)) return { kind: "invalid", field: "payerName" };
    if (form.matchBasis !== "customer_name" && (note.length < 3 || note.length > 255)) return { kind: "invalid", field: "matchNote" };
    input = { ...base, matchBasis: form.matchBasis, payerName: payer, ...(note ? { matchNote: note } : {}) };
  }
  if (money.remaining !== null && bani(amount) > bani(money.remaining)) {
    return { kind: "over", input, clarify: "amount_unclear", excess: fromBani(bani(amount) - bani(money.remaining)) };
  }
  return { kind: "ok", input, effect: paymentEffect(amount, money) };
}

/** Staff 2.55.0: the order's money of a task, from the shop's one calculation (older API: the advance only). */
export function checkMoney(task: FinanceTask): CheckMoney {
  const live = task.live.available ? task.live : null;
  return {
    remaining: live?.money?.remaining ?? null,
    advanceRemaining: live?.advance?.remaining ?? null,
    total: live?.money?.total ?? null,
    received: live?.money?.received ?? live?.advance?.verified ?? null,
  };
}

/** Where the check of a task stands when it is opened: the three steps, a lost answer, or another task screen. */
export type CheckEntry = "check" | "reconcile" | "unavailable" | "other";
export function checkEntry(task: FinanceTask): CheckEntry {
  if (task.allowed.reconcile) return "reconcile";
  if (!deskable(task.request) || !(task.allowed.verify || task.allowed.notReceived || task.allowed.clarify)) return "other";
  if (!task.live.available) return "unavailable";
  return "check";
}

/**
 * A command whose answer was lost (network, timeout): the result is unknown and nothing is sent again blindly. The
 * same idempotency key is reused on a retry, so the server replays the stored answer instead of a second payment.
 */
export const answerLost = (code: string): boolean => code === "NETWORK_UNAVAILABLE" || code === "REQUEST_TIMEOUT" || code === "SERVICE_UNAVAILABLE";

/** The order just finished, shown once on the screen that opens next (memory only, never stored). */
let lastDone: string | null = null;
export const noteDeskDone = (orderNumber: string): void => { lastDone = orderNumber; };
export const takeDeskDone = (): string | null => { const value = lastDone; lastDone = null; return value; };

/** The shop filter of the desk, kept while the employee moves from task to task (memory only, never stored). */
let deskSource: string | null = null;
export const deskSourceFilter = (): string | null => deskSource;
export const setDeskSourceFilter = (source: string | null): void => { deskSource = source; };
