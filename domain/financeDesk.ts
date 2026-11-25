import { normalizeAmount, validReference } from "./confirmareFinance";
import type { ClarificationReason, FinanceTask, Inbox, InboxItem } from "./financeRequests";

/**
 * The Contabilitate desk (Staff 2.53.0, owner 2026-11-25): Kontrol et -> Karar ver -> Onayla -> Sıradaki sipariş.
 *
 * Presentation only. The desk reuses the finance task commands unchanged (start, decide, reconcile): the server checks
 * the employee's scopes, the shop's revision, the proforma, the payment reference, the currency and the duplicate bank
 * reference again on every command. Nothing here decides money: the list amount is display data (API 2.77.9
 * finance_task_amounts) and the payment check always reads the shop live before anything is confirmed.
 */
export const DESK_VIEWS = ["todo", "waiting", "clarification", "done"] as const;
export type DeskView = (typeof DESK_VIEWS)[number];

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
export function deskRows(inbox: Inbox, view: DeskView, source: string | null, query: string): InboxItem[] {
  const rows = inbox.items.filter((item) => deskView(item) === view && (source === null || item.source === source) && matchesSearch(item, query));
  if (view === "done") return rows.sort((a, b) => time(b.updatedAt) - time(a.updatedAt) || a.id.localeCompare(b.id));
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

export type PaymentForm = { amount: string; bankReference: string; valueDate: string; statementReference: string };
export type PaymentInput = { amount: string; reference: string; valueDate: string; paymentReference: string };
/**
 * Step 2 of the check. `over` and `mismatch` are never recorded through the task (the shop refuses them too): the desk
 * sends them to a clarification with `clarify`. `partial` is recorded as it is (owner 2026-10-29); the existing advance
 * acceptance rules then apply.
 */
export type PaymentCheck =
  | { kind: "invalid"; field: "amount" | "bankReference" | "valueDate" | "statementReference" }
  | { kind: "over"; input: PaymentInput; clarify: ClarificationReason }
  | { kind: "mismatch"; input: PaymentInput; clarify: ClarificationReason }
  | { kind: "ok"; input: PaymentInput; gap: string | null };

/** The value date is a real calendar day, not in the future. */
export function validValueDate(value: string, nowMs: number): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const day = Date.parse(`${value}T00:00:00Z`);
  return Number.isFinite(day) && new Date(day).toISOString().slice(0, 10) === value && day <= nowMs + 36 * 3600 * 1000;
}

export function checkPayment(form: PaymentForm, expectedReference: string | null, remaining: string | null, nowMs: number): PaymentCheck {
  const amount = normalizeAmount(form.amount);
  if (!amount) return { kind: "invalid", field: "amount" };
  if (!validReference(form.bankReference)) return { kind: "invalid", field: "bankReference" };
  if (!validValueDate(form.valueDate, nowMs)) return { kind: "invalid", field: "valueDate" };
  const statement = form.statementReference.trim().toUpperCase();
  if (!statement) return { kind: "invalid", field: "statementReference" };
  const input: PaymentInput = { amount, reference: form.bankReference.trim(), valueDate: form.valueDate, paymentReference: statement };
  // The statement must show the proforma's own payment reference; a different one is a clarification, never a guess.
  if (expectedReference && statement !== expectedReference.toUpperCase()) return { kind: "mismatch", input, clarify: "reference_missing" };
  if (remaining !== null && bani(amount) > bani(remaining)) return { kind: "over", input, clarify: "amount_unclear" };
  const gap = remaining !== null && bani(amount) < bani(remaining) ? fromBani(bani(remaining) - bani(amount)) : null;
  return { kind: "ok", input, gap };
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
