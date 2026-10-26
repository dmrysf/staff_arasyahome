import { t, type MessageKey } from "../i18n";
import type { CodCheck, InvoiceDeadline, MoneyPosition } from "./confirmareInvoice";
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
export const FINANCE_TASKS = ["reconciliation_required", "source_unavailable", "refund_processing", "transfer_verification", "refund_eligible", "refund_liability", "additional_payment", "advance_partial", "advance_waiting", "advance_verified", "advance_routed"] as const;
export type FinanceTask = (typeof FINANCE_TASKS)[number];
export const TRANSFER_REJECT_REASONS = ["not_received", "amount_mismatch", "wrong_order", "duplicate", "other"] as const;
export type TransferRejectReason = (typeof TRANSFER_REJECT_REASONS)[number];
/** API 2.45.0: how the money came (cash needs its fiscal receipt or chitanță number and who collected it). */
export const PAYMENT_METHODS = ["bank_transfer", "cash_office", "cash_courier"] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];
export const CASH_RECEIPTS = ["bon_fiscal", "chitanta"] as const;
export type CashReceipt = (typeof CASH_RECEIPTS)[number];

export type FinanceEntry = {
  id: string; kind: "transfer_in" | "refund_out" | "liability_ack"; purpose: "advance" | "additional_payment" | "refund" | "liability";
  amount: string; reference: string; valueDate: string | null; state: "submitted" | "verified" | "rejected";
  submittedBy: string; submittedAt: string; decidedBy: string | null; decidedAt: string | null; decisionCode: string | null;
  method: PaymentMethod; receipt: CashReceipt | null; collector: string | null; flags: ("overpayment" | "cancelled_proforma" | "card_full_refund")[];
};
export type FinanceSummary = {
  readAt: string; revision: string; currency: string;
  order: { id: string; number: string; status: string; modifiedAt: string | null; method: string; methodTitle: string; cod: boolean };
  totals: { current: string | null; original: string | null; refunded: string | null };
  received: { online: string | null; advance: string | null; transfers: string | null; net: string | null };
  payment: { onlineVerified: boolean; paidRecorded: boolean; evidence: "netopia_ipn" | "gateway" | "none" };
  /** YD SOFT 2.18.0: the payment reference the customer writes in the transfer and the advance proforma, to find the order on the statement. */
  advance: {
    percentage: number; required: string | null; collected: string | null; outstanding: string | null; onDelivery: string | null;
    paymentReference: string | null; proforma: { series: string; number: string; amount: string | null; currency: string | null; reference: string | null } | null;
    /** API 2.45.0: the operator accepted a smaller verified advance; the last proforma was cancelled in SmartBill. */
    accepted: boolean; proformaCancelled: boolean;
  } | null;
  balance: { outstanding: string | null; refundLiability: string; acknowledged: boolean };
  financial: { state: "cleared" | "waiting" | "partial" | "unknown"; basis: string | null; attention: string | null };
  entries: FinanceEntry[];
  capabilities: { record: boolean; refunds: "off" | "manual"; electronicRefund: false };
  /** API 2.45.0 (null from an older shop): the one money calculation, the invoicing deadline, the AWB's collection. */
  position: MoneyPosition | null;
  deadline: InvoiceDeadline | null;
  cod: CodCheck | null;
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
  /** A bank transfer recorded by one finance employee waits for the second: not money received yet. */
  verifying: boolean;
};
export type FinanceQueue = { tasks: Record<FinanceTask, number>; orders: FinanceQueueItem[]; sources: string[] };

export type TransferInput = {
  expectedRevision: string; purpose: "advance" | "additional_payment"; amount: string; reference: string; valueDate: string;
  method?: PaymentMethod; receipt?: CashReceipt; collector?: string; overpaymentConfirmed?: true; cancelledProforma?: "apply_to_order";
};
export type DecisionInput = { expectedRevision: string; entryId: string; decision: "verify" | "reject"; reasonCode?: TransferRejectReason };
export type RefundInput = { expectedRevision: string; amount: string; reference: string; valueDate: string };

/**
 * The shop's bank transfers that match no order (API 2.44.0, YD SOFT 2.18.0): kept in the shop's finance record until
 * ANOTHER finance employee closes it. Never money of an order; amounts are read from the shop when shown, never kept.
 */
export const EXCEPTION_REASONS = ["unmatched", "over_payment", "duplicate", "amount_mismatch", "other"] as const;
export type ExceptionReason = (typeof EXCEPTION_REASONS)[number];
export const EXCEPTION_RESOLUTIONS = ["recorded_on_order", "returned_to_payer", "not_customer_payment"] as const;
export type ExceptionResolution = (typeof EXCEPTION_RESOLUTIONS)[number];
export type TransferException = {
  id: string; amount: string; currency: string; reference: string; valueDate: string | null; reason: ExceptionReason; state: "open" | "resolved";
  submittedBy: string; submittedAt: string | null; resolvedBy: string | null; resolvedAt: string | null; resolution: ExceptionResolution | null;
  order: { id: string; number: string | null } | null;
};
export type TransferExceptionList =
  | { source: string; available: true; currency: string | null; exceptions: TransferException[]; capabilities: { record: boolean; approve: boolean } }
  | { source: string; available: false; reason: string; exceptions: []; capabilities: { record: boolean; approve: boolean } };
export type ExceptionRecordInput = { amount: string; currency: string; reference: string; valueDate: string; reason: ExceptionReason };
export type ExceptionResolveInput = { entryId: string; resolution: ExceptionResolution; orderNumber?: string };
export type ExceptionOutcome = ({ state: "applied"; entryId: string } & Extract<TransferExceptionList, { available: true }>) | { state: "unknown"; idempotencyKey: string };

export interface ConfirmareFinanceApi {
  financeRead(globalOrderId: string, signal?: AbortSignal): Promise<FinanceRead>;
  financeAct(globalOrderId: string, action: FinanceAction, input: TransferInput | DecisionInput | RefundInput | { expectedRevision: string }, idempotencyKey: string): Promise<FinanceOutcome>;
  financeReconcile(globalOrderId: string, idempotencyKey: string): Promise<FinanceOutcome>;
  financeQueue(task: FinanceTask | null, source: string | null, signal?: AbortSignal): Promise<FinanceQueue>;
  transferExceptions(source: string | null, signal?: AbortSignal): Promise<TransferExceptionList>;
  transferExceptionAct(source: string, action: "record" | "resolve", input: ExceptionRecordInput | ExceptionResolveInput, idempotencyKey: string): Promise<ExceptionOutcome>;
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
export const exceptionReasonLabel = (reason: ExceptionReason): string => t(`cf.fin.exc.reason.${reason}` as MessageKey);
export const exceptionResolutionLabel = (resolution: ExceptionResolution): string => t(`cf.fin.exc.resolution.${resolution}` as MessageKey);
export const rejectReasonLabel = (reason: string): string => ((TRANSFER_REJECT_REASONS as readonly string[]).includes(reason) ? t(`cf.fin.reject.${reason}` as MessageKey) : reason);

export const nextTone: Readonly<Record<FinanceNext, "neutral" | "success" | "warning" | "danger" | "info">> = {
  reconcile_action: "danger", reconcile_source: "danger", source_unknown: "danger", verify_transfer: "warning", collect_additional_payment: "warning", acknowledge_refund: "warning",
  verify_refund: "warning", refund_after_production: "info", record_refund: "warning", collect_advance: "warning", wait_online_payment: "info", none: "success",
};

/** The shop refusal behind a finance error, in the employee's words (an unknown code reads as a generic refusal). */
const REFUSALS = ["cash_document_required", "proforma_cancelled", "transfer_not_recorded", "revision_changed", "duplicate_reference", "amount_exceeds_balance", "amount_exceeds_liability", "transfer_pending", "refund_pending", "same_employee", "entry_not_pending", "purpose_not_allowed", "advance_not_required", "payment_not_verified", "nothing_due", "reference_invalid", "value_date_invalid", "no_liability", "already_acknowledged", "liability_not_acknowledged", "refund_exceeds_order", "refund_disabled", "production_not_complete", "financial_unknown", "finance_record_disabled", "not_received", "order_busy", "finance_open", "order_cancelled"] as const;
export function refusalLabel(reason: string | undefined): string {
  return t(`cf.fin.refusal.${reason && (REFUSALS as readonly string[]).includes(reason) ? reason : "other"}` as MessageKey);
}

/**
 * Why the shop's register of unmatched transfers is not shown (Staff 2.36.1). `not_configured` is the server's answer
 * while finance is switched off for the shop (it never calls the shop then): that is the expected state before the
 * activation, not a fault. Any other reason is a read that failed now.
 */
export function exceptionUnavailableKey(reason: string): MessageKey {
  return reason === "not_configured" ? "cf.fin.exc.notConfigured" : "cf.fin.exc.unavailable";
}

/** The Confirmare finance error a response carried (backend code), or null. */
export function financeErrorKey(backendCode: string | undefined): MessageKey | null {
  const known = ["CONFIRMARE_FINANCE_DENIED", "CONFIRMARE_FINANCE_SAME_EMPLOYEE", "CONFIRMARE_FINANCE_DISABLED", "CONFIRMARE_FINANCE_INVALID", "CONFIRMARE_FINANCE_ACTION_PENDING", "CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE", "CONFIRMARE_FINANCE_ACTION_NOT_FOUND", "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND"];
  return backendCode && known.includes(backendCode) ? `cf.fin.error.${backendCode}` as MessageKey : null;
}

/**
 * The submitted entries another employee recorded (what this employee may verify), oldest first. A NETOPIA full card
 * refund (YD SOFT 2.20.0) is verified in the operations panel of the order, never through the finance refund decision.
 */
export function verifiableEntries(view: FinanceView, kind: "transfer_in" | "refund_out"): FinanceEntry[] {
  return view.summary.entries.filter((entry) => entry.kind === kind && entry.state === "submitted" && entry.submittedBy !== view.me && !entry.flags.includes("card_full_refund"));
}

/** The NETOPIA full card refunds recorded by another employee: what this finance approver verifies in the operations panel. */
export function cardRefundEntries(view: FinanceView): FinanceEntry[] {
  return view.summary.entries.filter((entry) => entry.kind === "refund_out" && entry.state === "submitted" && entry.submittedBy !== view.me && entry.flags.includes("card_full_refund"));
}

/** The submitted entries this employee recorded (waiting for another employee). */
export function ownPendingEntries(view: FinanceView): FinanceEntry[] {
  return view.summary.entries.filter((entry) => entry.state === "submitted" && entry.submittedBy === view.me);
}
