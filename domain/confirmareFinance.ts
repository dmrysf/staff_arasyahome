import { t, type MessageKey } from "../i18n";
import type { Employee } from "./models";

/**
 * Confirmare finance (API 2.41.0, Phase 5). WooCommerce stays the authority for prices, payments, refunds and balances:
 * Staff shows the shop's own figures, read through the Operations API when the employee asks, and never computes,
 * stores or caches an amount (component memory only). A recorded bank transfer is never money received: another
 * employee verifies it against the bank statement first. No refund is ever sent to a payment provider.
 */
export const FINANCE_CAPABILITIES = ["view", "record", "approve"] as const;
export type FinanceCapability = (typeof FINANCE_CAPABILITIES)[number];
export type FinanceScopes = { view: string[]; record: string[]; approve: string[] };

/** Detail capabilities: whether finance runs for the shop and what the signed-in employee may do (the server checks again). */
export type FinanceCapabilities = { configured: boolean; read: boolean; record: boolean; approve: boolean };

export const FINANCE_ACTIONS = ["submit_transfer", "decide_transfer", "acknowledge_liability", "submit_refund", "decide_refund"] as const;
export type FinanceAction = (typeof FINANCE_ACTIONS)[number];
export const FINANCE_NEXT = ["reconcile_action", "reconcile_source", "source_unknown", "verify_transfer", "collect_additional_payment", "acknowledge_refund", "verify_refund", "refund_after_production", "record_refund", "collect_advance", "wait_online_payment", "none"] as const;
export type FinanceNext = (typeof FINANCE_NEXT)[number];
export const FINANCE_TASKS = ["reconciliation_required", "source_unavailable", "refund_processing", "transfer_verification", "refund_eligible", "refund_liability", "additional_payment", "advance_partial", "advance_waiting", "advance_verified"] as const;
export type FinanceTask = (typeof FINANCE_TASKS)[number];
export const TRANSFER_REJECT_REASONS = ["not_received", "amount_mismatch", "wrong_order", "duplicate", "other"] as const;
export type TransferRejectReason = (typeof TRANSFER_REJECT_REASONS)[number];

export type FinanceEntry = {
  id: string; kind: "transfer_in" | "refund_out" | "liability_ack"; purpose: "advance" | "additional_payment" | "refund" | "liability";
  amount: string; reference: string; valueDate: string | null; state: "submitted" | "verified" | "rejected";
  submittedBy: string; submittedAt: string; decidedBy: string | null; decidedAt: string | null; decisionCode: string | null;
};
export type FinanceSummary = {
  readAt: string; revision: string; currency: string;
  order: { id: string; number: string; status: string; modifiedAt: string | null; method: string; methodTitle: string; cod: boolean };
  totals: { current: string | null; original: string | null; refunded: string | null };
  received: { online: string | null; advance: string | null; transfers: string | null; net: string | null };
  payment: { onlineVerified: boolean; paidRecorded: boolean; evidence: "netopia_ipn" | "gateway" | "none" };
  advance: { percentage: number; required: string | null; collected: string | null; outstanding: string | null; onDelivery: string | null } | null;
  balance: { outstanding: string | null; refundLiability: string; acknowledged: boolean };
  financial: { state: "cleared" | "waiting" | "partial" | "unknown"; basis: string | null; attention: string | null };
  entries: FinanceEntry[];
  capabilities: { record: boolean; refunds: "off" | "manual"; electronicRefund: false };
};
export type FinanceTimelineItem = {
  employeeId: string; action: FinanceAction; decision: "verify" | "reject" | null; reasonCode: string | null; purpose: string | null; entryId: string | null;
  idempotencyKey: string; state: "pending" | "applied" | "rejected" | "unknown"; outcome: string | null; requestedAt: string; completedAt: string | null;
};
export type FinanceView = {
  summary: FinanceSummary;
  production: { stageId: string; handedOver: boolean; refundEligible: boolean };
  allowedActions: FinanceAction[];
  nextAction: FinanceNext;
  actionOpen: boolean;
  timeline: FinanceTimelineItem[];
  employees: Record<string, string>;
  me: string;
};
export type FinanceRead = ({ available: true; role: "finance" | "assignee" | "supervisor" } & FinanceView) | { available: false; reason: string };
export type FinanceOutcome =
  | ({ state: "applied"; idempotencyKey: string; entryId: string } & Partial<FinanceView>)
  | { state: "pending" | "unknown"; idempotencyKey: string; reachable?: boolean };
export type FinanceQueueItem = {
  globalOrderId: string; source: string; orderNumber: string; task: FinanceTask;
  financial: { state: string | null; basis: string | null; attention: string | null }; paymentMethod: string | null; stageId: string; confirmation: string; dueDate: string | null; sourceChangedAt: string | null;
};
export type FinanceQueue = { tasks: Record<FinanceTask, number>; orders: FinanceQueueItem[]; sources: string[] };

export type TransferInput = { expectedRevision: string; purpose: "advance" | "additional_payment"; amount: string; reference: string; valueDate: string };
export type DecisionInput = { expectedRevision: string; entryId: string; decision: "verify" | "reject"; reasonCode?: TransferRejectReason };
export type RefundInput = { expectedRevision: string; amount: string; reference: string; valueDate: string };

export interface ConfirmareFinanceApi {
  financeRead(globalOrderId: string, signal?: AbortSignal): Promise<FinanceRead>;
  financeAct(globalOrderId: string, action: FinanceAction, input: TransferInput | DecisionInput | RefundInput | { expectedRevision: string }, idempotencyKey: string): Promise<FinanceOutcome>;
  financeReconcile(globalOrderId: string, idempotencyKey: string): Promise<FinanceOutcome>;
  financeQueue(task: FinanceTask | null, source: string | null, signal?: AbortSignal): Promise<FinanceQueue>;
}

/** Finance figures are hidden again after this long without a new read (money on a shared screen). */
export const FINANCE_DATA_TTL_MS = 10 * 60 * 1000;

// ---- Access (presentation only: every route checks the scopes again) -------------------------------------------

export function financeScopesOf(employee: Employee): FinanceScopes {
  const scopes = employee.financeScopes;
  const known = (sources: readonly string[] | undefined) => (sources ?? []).filter((source) => source === "trendhome" || source === "outletperdele");
  return { view: known(scopes?.view), record: known(scopes?.record), approve: known(scopes?.approve) };
}

/** The finance tasks exist for Staff identities holding any finance scope (server-granted, per person, never root by default). */
export function canUseFinance(employee: Employee): boolean {
  if (employee.status !== "active" || employee.mustChangePassword || !employee.applications.includes("staff")) return false;
  const scopes = financeScopesOf(employee);
  return scopes.view.length + scopes.record.length + scopes.approve.length > 0;
}

// ---- Words -----------------------------------------------------------------------------------------------------

const money = new Map<string, Intl.NumberFormat>();
/** A shop amount (decimal string) in its currency; the value is the shop's, only its presentation is local. */
export function formatFinanceMoney(amount: string | null | undefined, currency: string): string {
  if (amount == null) return "—";
  const value = Number(amount);
  if (!Number.isFinite(value)) return "—";
  let formatter = money.get(currency);
  if (!formatter) {
    try { formatter = new Intl.NumberFormat("ro-RO", { style: "currency", currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    catch { formatter = new Intl.NumberFormat("ro-RO", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    money.set(currency, formatter);
  }
  return formatter.format(value);
}

/**
 * An amount typed by an employee, as the API accepts it ("1.234,50", "1234,5" and "1234.5" all become "1234.50"), or
 * null. Thousands dots are accepted only together with a decimal comma: "1.500" alone is ambiguous (1,5 or 1500) and is
 * refused, never guessed.
 */
export function normalizeAmount(input: string): string | null {
  const compact = input.trim().replace(/\s+/g, "");
  if (compact === "") return null;
  const european = /^\d{1,3}(\.\d{3})*,\d{1,2}$/.test(compact) || /^\d+,\d{1,2}$/.test(compact);
  const plain = european ? compact.replace(/\./g, "").replace(",", ".") : compact;
  if (!/^(0|[1-9]\d{0,8})(\.\d{1,2})?$/.test(plain) || Number(plain) <= 0) return null;
  return Number(plain).toFixed(2);
}

/** A bank reference as the statement shows it (letters, digits, spaces and . / -), at least three letters or digits together. */
export function validReference(input: string): boolean {
  const value = input.trim();
  return /^[A-Za-z0-9][A-Za-z0-9 ./-]{2,63}$/.test(value) && /[A-Za-z0-9]{3}/.test(value);
}

export const nextLabel = (next: FinanceNext): string => t(`cf.fin.next.${next}` as MessageKey);
export const taskLabel = (task: FinanceTask): string => t(`cf.fin.task.${task}` as MessageKey);
export const attentionLabel = (attention: string): string => (["additional_payment_due", "refund_due", "transfer_pending", "reconciliation_required", "refund_liability", "refund_pending"].includes(attention) ? t(`cf.fin.attention.${attention}` as MessageKey) : attention);
export const entryLabel = (entry: Pick<FinanceEntry, "kind" | "purpose">): string => t(`cf.fin.entry.${entry.kind === "transfer_in" ? entry.purpose : entry.kind}` as MessageKey);
export const entryStateLabel = (state: FinanceEntry["state"]): string => t(`cf.fin.entryState.${state}` as MessageKey);
export const actionLabel = (action: FinanceAction): string => t(`cf.fin.action.${action}` as MessageKey);
export const rejectReasonLabel = (reason: string): string => ((TRANSFER_REJECT_REASONS as readonly string[]).includes(reason) ? t(`cf.fin.reject.${reason}` as MessageKey) : reason);

export const nextTone: Readonly<Record<FinanceNext, "neutral" | "success" | "warning" | "danger" | "info">> = {
  reconcile_action: "danger", reconcile_source: "danger", source_unknown: "danger", verify_transfer: "warning", collect_additional_payment: "warning", acknowledge_refund: "warning",
  verify_refund: "warning", refund_after_production: "info", record_refund: "warning", collect_advance: "warning", wait_online_payment: "info", none: "success",
};

/** The shop refusal behind a finance error, in the employee's words (an unknown code reads as a generic refusal). */
const REFUSALS = ["revision_changed", "duplicate_reference", "amount_exceeds_balance", "amount_exceeds_liability", "transfer_pending", "refund_pending", "same_employee", "entry_not_pending", "purpose_not_allowed", "advance_not_required", "payment_not_verified", "nothing_due", "reference_invalid", "value_date_invalid", "no_liability", "already_acknowledged", "liability_not_acknowledged", "refund_exceeds_order", "refund_disabled", "production_not_complete", "financial_unknown", "finance_record_disabled", "not_received", "order_busy", "finance_open", "order_cancelled"] as const;
export function refusalLabel(reason: string | undefined): string {
  return t(`cf.fin.refusal.${reason && (REFUSALS as readonly string[]).includes(reason) ? reason : "other"}` as MessageKey);
}

/** The Confirmare finance error a response carried (backend code), or null. */
export function financeErrorKey(backendCode: string | undefined): MessageKey | null {
  const known = ["CONFIRMARE_FINANCE_DENIED", "CONFIRMARE_FINANCE_SAME_EMPLOYEE", "CONFIRMARE_FINANCE_DISABLED", "CONFIRMARE_FINANCE_INVALID", "CONFIRMARE_FINANCE_ACTION_PENDING", "CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE", "CONFIRMARE_FINANCE_ACTION_NOT_FOUND", "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND"];
  return backendCode && known.includes(backendCode) ? `cf.fin.error.${backendCode}` as MessageKey : null;
}

/** The submitted entries another employee recorded (what this employee may verify), oldest first. */
export function verifiableEntries(view: FinanceView, kind: "transfer_in" | "refund_out"): FinanceEntry[] {
  return view.summary.entries.filter((entry) => entry.kind === kind && entry.state === "submitted" && entry.submittedBy !== view.me);
}

/** The submitted entries this employee recorded (waiting for another employee). */
export function ownPendingEntries(view: FinanceView): FinanceEntry[] {
  return view.summary.entries.filter((entry) => entry.state === "submitted" && entry.submittedBy === view.me);
}
