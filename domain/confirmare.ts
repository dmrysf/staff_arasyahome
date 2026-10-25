import type { Employee } from "./models";
import { t, type MessageKey } from "../i18n";
import type { ShopRead, SourceAccessCapabilities } from "./confirmareShop";
import type { ConfirmareEditApi } from "./confirmareEdit";
import { canUseFinance, financeScopesOf, type ConfirmareFinanceApi, type FinanceCapabilities } from "./confirmareFinance";
import type { ConfirmareInvoiceApi, InvoiceCapabilities } from "./confirmareInvoice";

/**
 * Staff Confirmare workspace (API 2.39.0, Phase 3; the shop's own order, API 2.40.0, Phase 4A). New Trend Home and
 * Outlet Perdele orders are confirmed with the customer by telephone before production. Everything here is presentation of facts the server already decided:
 * who may see which source (Confirmare scopes), which order goes to whom (server distribution), whether production may
 * start (ConfirmationClearance). Staff never computes an assignment, never infers a call and never changes a payment.
 */
export const CONFIRMARE_SOURCES = ["trendhome", "outletperdele"] as const;
export type ConfirmareSource = (typeof CONFIRMARE_SOURCES)[number];
export type ConfirmareScopes = { operate: string[]; supervise: string[] };
export type ConfirmareStatus = "pending" | "approved" | "rejected" | "cancelled";
export const CONTACT_OUTCOMES = ["confirmed", "no_answer", "callback", "needs_correction"] as const;
export type ContactOutcome = (typeof CONTACT_OUTCOMES)[number];
export type FinancialState = "cleared" | "waiting" | "partial" | "unknown";
/** API 2.40.0: additional_payment_due, refund_due. API 2.41.0 (the shop's finance record): the other four. */
export const FINANCIAL_ATTENTION = ["additional_payment_due", "refund_due", "transfer_pending", "reconciliation_required", "refund_liability", "refund_pending"] as const;
export type FinancialAttention = (typeof FINANCIAL_ATTENTION)[number];
export const CLEARANCE_CONDITIONS = ["approval", "call", "lines", "price", "sync", "payment"] as const;
export type ClearanceCondition = (typeof CLEARANCE_CONDITIONS)[number];
/**
 * API 2.42.0 (Phase 6): the production handoff of a cleared order (server ConfirmareHandoff). Confirmare is not a
 * production stage: the order waits at stage 1 until the cutting QR scan of its first production document.
 */
export const HANDOFF_STATES = ["closed", "blocked", "ready", "print", "awaiting_cutting", "in_production"] as const;
export type HandoffState = (typeof HANDOFF_STATES)[number];
export const HANDOFF_BLOCKERS = [...CLEARANCE_CONDITIONS, "finance_action", "shop_change", "payment_gate", "exception", "document_blocked"] as const;
export type HandoffBlocker = (typeof HANDOFF_BLOCKERS)[number];
export const DOCUMENT_STATES = ["none", "active", "stale", "revoked"] as const;
export type ConfirmareHandoff = {
  state: HandoffState;
  blockers: HandoffBlocker[];
  stageId: string | null;
  document: { status: (typeof DOCUMENT_STATES)[number]; revision: number | null; prints: number } | null;
  /** What the signed-in employee may do here (the document commands check everything again). */
  actions: { generate: boolean; print: boolean };
};
export const REJECT_REASONS = ["customer_unreachable", "customer_cancelled", "order_details_wrong", "duplicate_order", "payment_problem", "other"] as const;
export type RejectReason = (typeof REJECT_REASONS)[number];

export type ConfirmareContactSummary = { outcome: ContactOutcome; at: string; attempts: number; current: boolean };

/** One queue row: order identity and states only, never customer data. */
export type ConfirmareItem = {
  globalOrderId: string;
  orderNumber: string;
  sourceKey: string;
  status: ConfirmareStatus;
  version: number;
  /** `confirmare` once the submission instant is known; anything else is undetermined (supervisors only). */
  workflowCohort: string;
  openedAt: string;
  submittedAt: string | null;
  productionStageId: string;
  commerceStatus: string | null;
  commerceClosed: boolean;
  payment: { state: string };
  /** `attention` (API 2.40.0): the total changed after a payment; a financial decision is needed (never clears). */
  financial: { state: FinancialState; basis: string | null; open: boolean; attention: FinancialAttention | null };
  pricingAvailable: boolean;
  dueDate: string | null;
  productionCleared: boolean;
  contact: ConfirmareContactSummary | null;
  assignment: { employeeId: string; displayName: string; at: string; kind: string } | null;
  decision: { at: string; by: string | null; reasonCode: string | null } | null;
};

export type ConfirmareView = "mine" | "open" | "all" | "followup";
/** One row of the production handoff list (API 2.42.0): the order's identity and next step only. */
export type HandoffStep = "ready" | "print" | "awaiting_cutting";
export type HandoffItem = { globalOrderId: string; orderNumber: string; sourceKey: string; dueDate: string | null; submittedAt: string | null; approvedAt: string | null; step: HandoffStep };
export type ConfirmarePool = { view: ConfirmareView; items: ConfirmareItem[]; limit: number; targetOpenPerOperator: number };

export type ConfirmareLine = {
  lineId: string;
  lineNumber: number;
  name: string;
  productCode: string | null;
  variant: string | null;
  color: string | null;
  width: number | null;
  height: number | null;
  unit: string | null;
  meters: number | null;
  quantity: number;
  options: { label: string; value: string }[];
  notes: string | null;
  confirmed: boolean;
};

export type ConfirmareContact = { attempt: number; outcome: ContactOutcome; by: string | null; at: string; current: boolean };
export type ConfirmareEvent = { action: string; actor: string | null; previousStatus: string | null; status: string; previousAssignee: string | null; assignee: string | null; reasonCode: string | null; version: number; at: string };

/** Only for the assignee and supervisors; never the full telephone number (Arasya does not hold it). */
export type ConfirmareCustomer =
  | { visible: false; phoneAvailable: false }
  | { visible: true; name: string | null; company: string | null; addressLines: string[]; phoneMasked: string | null; phoneAvailable: false };

export type ConfirmareDetail = ConfirmareItem & {
  review: { fingerprint: string; lineIds: string[]; pricingAvailable: boolean; callRecorded: boolean; contactAvailable: boolean };
  lines: ConfirmareLine[];
  contacts: ConfirmareContact[];
  customer: ConfirmareCustomer;
  source: { commerceStatusLabel: string | null; changedAt: string | null; lastSeenAt: string | null; freshness: string | null };
  production: { cleared: boolean; missing: ClearanceCondition[] };
  events: ConfirmareEvent[];
  /** API 2.40.0: whether the shop's own order can be read (and, in Phase 4B, changed) by the signed-in employee. */
  sourceAccess: SourceAccessCapabilities;
  /** API 2.41.0: whether the money of the order can be read, recorded or approved by the signed-in employee. */
  finance: FinanceCapabilities;
  /** API 2.42.0: the production handoff; null from an older API. */
  handoff: ConfirmareHandoff | null;
  /** API 2.43.0: whether the order's SmartBill document can be read, issued or managed by the signed-in employee. */
  invoice: InvoiceCapabilities;
};

export type ConfirmareOperator = { employeeId: string; displayName: string; sources: string[]; canWork: boolean; available: boolean; availableChangedAt: string | null; lastAssignedAt: string | null; open: number; oldestAssignedAt: string | null };
export type ConfirmareTeam = { operators: ConfirmareOperator[]; unassigned: Record<string, number>; undetermined: Record<string, number>; held: string[]; targetOpenPerOperator: number; inactivityMinutes: number };
export type ConfirmareAlert = { globalOrderId: string; orderNumber: string; sourceKey: string; kind: string; raisedAt: string; assignee: { employeeId: string; displayName: string }; assignedAt: string | null; confirmationVersion: number; lastContactAt: string | null };
export type ConfirmareAvailability = { employeeId: string; available: boolean; changedAt: string | null; lastAssignedAt: string | null; open: number; targetOpen: number };

export type ApproveInput = { expectedVersion: number; customerCall: "confirmed"; confirmedLineIds: string[]; priceVerified: true; reviewFingerprint: string };

export interface ConfirmareApi extends ConfirmareEditApi, ConfirmareFinanceApi, ConfirmareInvoiceApi {
  pool(view: ConfirmareView, source: string | null, signal?: AbortSignal): Promise<ConfirmarePool>;
  /** API 2.42.0: the production handoff list (document generate permission; no Confirmare scope needed). */
  handoffList(source: string | null, signal?: AbortSignal): Promise<HandoffItem[]>;
  detail(globalOrderId: string, signal?: AbortSignal): Promise<ConfirmareDetail>;
  next(source: string | null, idempotencyKey: string): Promise<ConfirmareItem | null>;
  claim(globalOrderId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<ConfirmareItem>;
  release(globalOrderId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<ConfirmareItem>;
  reassign(globalOrderId: string, input: { expectedVersion: number; employeeId: string }, idempotencyKey: string): Promise<ConfirmareItem>;
  reopen(globalOrderId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<ConfirmareItem>;
  approve(globalOrderId: string, input: ApproveInput, idempotencyKey: string): Promise<ConfirmareItem>;
  reject(globalOrderId: string, input: { expectedVersion: number; reasonCode: RejectReason }, idempotencyKey: string): Promise<ConfirmareItem>;
  contact(globalOrderId: string, input: { expectedVersion: number; outcome: ContactOutcome; reviewFingerprint: string }, idempotencyKey: string): Promise<{ contact: ConfirmareContact; item: ConfirmareItem }>;
  availability(signal?: AbortSignal): Promise<ConfirmareAvailability>;
  setAvailability(input: { available: boolean; employeeId?: string }, idempotencyKey: string): Promise<ConfirmareAvailability>;
  team(signal?: AbortSignal): Promise<ConfirmareTeam>;
  alerts(signal?: AbortSignal): Promise<{ items: ConfirmareAlert[]; inactivityMinutes: number }>;
  /** API 2.40.0: the shop's own order (customer telephone numbers, prices), read now, audited, never cached. */
  sourceOrder(globalOrderId: string, signal?: AbortSignal): Promise<ShopRead>;
}

// ---- Access (presentation only: every route checks the scopes again) -------------------------------------------

export function confirmareScopesOf(employee: Employee): { operate: ConfirmareSource[]; supervise: ConfirmareSource[] } {
  const scopes = employee.confirmareScopes;
  const known = (sources: readonly string[] | undefined) => (sources ?? []).filter((source): source is ConfirmareSource => (CONFIRMARE_SOURCES as readonly string[]).includes(source));
  return { operate: known(scopes?.operate), supervise: known(scopes?.supervise) };
}

/** The Confirmare workspace exists for Staff identities holding a Confirmare scope (server-granted, per person). */
export function canUseConfirmare(employee: Employee): boolean {
  if (employee.status !== "active" || employee.mustChangePassword || !employee.applications.includes("staff")) return false;
  const scopes = confirmareScopesOf(employee);
  return scopes.operate.length > 0 || scopes.supervise.length > 0;
}

/** Whether the employee may open a Confirmare order at all: a Confirmare scope, or a finance scope (read-only, API 2.41.0). */
export function canOpenConfirmareOrder(employee: Employee): boolean {
  return canUseConfirmare(employee) || canUseFinance(employee);
}

/**
 * The advance queue (API 2.44.0): finance employees see every finance task; a Confirmare operator or supervisor sees the
 * advance tasks of their shops (waiting, partial, paid, routed to the shop). The server applies the same rule.
 */
export function canUseAdvanceQueue(employee: Employee): boolean {
  return canUseFinance(employee) || canUseConfirmare(employee);
}

/** The finance scopes, re-exported for the Confirmare screens. */
export { canUseFinance, financeScopesOf };

/**
 * The production handoff list (API 2.42.0): the permission to generate production documents. No Confirmare scope is
 * needed (a document employee is never part of the Confirmare distribution); the server limits the list to the sources
 * of the employee's document scope.
 */
export function canHandOff(employee: Employee): boolean {
  return employee.status === "active" && !employee.mustChangePassword && employee.applications.includes("staff") && employee.permissions.includes("production.documents.generate");
}

export function canSuperviseConfirmare(employee: Employee): boolean {
  return canUseConfirmare(employee) && confirmareScopesOf(employee).supervise.length > 0;
}

/** The sources the employee may filter by: exactly the server-granted ones, in a fixed order. */
export function confirmareSources(employee: Employee): ConfirmareSource[] {
  const scopes = confirmareScopesOf(employee);
  return CONFIRMARE_SOURCES.filter((source) => scopes.operate.includes(source) || scopes.supervise.includes(source));
}

export function operatesSource(employee: Employee, source: string): boolean {
  return (confirmareScopesOf(employee).operate as readonly string[]).includes(source);
}

export function supervisesSource(employee: Employee, source: string): boolean {
  return (confirmareScopesOf(employee).supervise as readonly string[]).includes(source);
}

// ---- Presentation groups (derived from API facts; no new state) -------------------------------------------------

export const QUEUE_TABS = ["mine", "unassigned", "inProgress", "callback", "correction", "payment", "attention"] as const;
export type QueueTab = (typeof QUEUE_TABS)[number];

const undetermined = (item: ConfirmareItem): boolean => item.workflowCohort !== "confirmare";
const currentContact = (item: ConfirmareItem): ContactOutcome | null => item.contact?.current ? item.contact.outcome : null;

/** Approved, not cleared, financially fine: the shop changed the order after the review (a supervisor reopens it). */
export function needsReverification(item: ConfirmareItem): boolean {
  return item.status === "approved" && !item.productionCleared && item.financial.open;
}

/** Facts a supervisor resolves: unknown submission, closed in the shop, no price evidence, unknown money, a changed approval. */
export function needsAttention(item: ConfirmareItem): boolean {
  if (item.status === "rejected" || item.productionCleared) return false;
  return undetermined(item) || item.commerceClosed || !item.pricingAvailable || item.financial.state === "unknown" || needsReverification(item);
}

/** Whether a row belongs to a tab. Tabs overlap on purpose (an order may wait for payment and for a call). */
export function inTab(item: ConfirmareItem, tab: QueueTab, employeeUuid: string): boolean {
  const pending = item.status === "pending";
  switch (tab) {
    case "mine": return pending && item.assignment?.employeeId === employeeUuid;
    case "unassigned": return pending && item.assignment === null && !undetermined(item);
    case "inProgress": return pending && item.assignment !== null && currentContact(item) === "confirmed";
    case "callback": return pending && (currentContact(item) === "no_answer" || currentContact(item) === "callback");
    case "correction": return pending && currentContact(item) === "needs_correction";
    case "payment": return (pending || (item.status === "approved" && !item.productionCleared)) && !item.financial.open && !undetermined(item);
    case "attention": return needsAttention(item);
  }
}

/** One status word per row, from the facts, in the operator's terms. */
export type ItemStatus = "toCall" | "confirmed" | "noAnswer" | "callback" | "needsCorrection" | "awaitingPayment" | "reverify" | "cleared" | "rejected" | "unassigned" | "undetermined" | "closedInShop";

export function itemStatus(item: ConfirmareItem): ItemStatus {
  if (item.status === "rejected" || item.status === "cancelled") return "rejected";
  if (item.status === "approved") return item.productionCleared ? "cleared" : item.financial.open ? "reverify" : "awaitingPayment";
  if (undetermined(item)) return "undetermined";
  if (item.commerceClosed) return "closedInShop";
  switch (currentContact(item)) {
    case "confirmed": return "confirmed";
    case "no_answer": return "noAnswer";
    case "callback": return "callback";
    case "needs_correction": return "needsCorrection";
    default: return item.assignment === null ? "unassigned" : "toCall";
  }
}

export const itemStatusTone: Readonly<Record<ItemStatus, "neutral" | "accent" | "success" | "warning" | "danger" | "info">> = {
  toCall: "accent", confirmed: "success", noAnswer: "warning", callback: "warning", needsCorrection: "warning", awaitingPayment: "warning",
  reverify: "danger", cleared: "success", rejected: "neutral", unassigned: "neutral", undetermined: "danger", closedInShop: "danger",
};

export const itemStatusLabel = (status: ItemStatus): string => t(`cf.status.${status}` as MessageKey);
export const contactOutcomeLabel = (outcome: ContactOutcome): string => t(`cf.outcome.${outcome}` as MessageKey);
export const rejectReasonLabel = (reason: string): string => (REJECT_REASONS as readonly string[]).includes(reason) ? t(`cf.reject.${reason}` as MessageKey) : reason;
export const conditionLabel = (condition: ClearanceCondition): string => t(`cf.condition.${condition}` as MessageKey);
export const handoffStateLabel = (state: HandoffState): string => t(`cf.handoff.state.${state}` as MessageKey);
export const handoffBlockerLabel = (blocker: HandoffBlocker): string => (CLEARANCE_CONDITIONS as readonly string[]).includes(blocker) ? conditionLabel(blocker as ClearanceCondition) : t(`cf.handoff.blocker.${blocker}` as MessageKey);
export const sourceLabel = (source: string): string => source === "trendhome" ? "Trend Home" : source === "outletperdele" ? "Outlet Perdele" : source;

// ---- The operator's next step ----------------------------------------------------------------------------------

export type ConfirmareNextAction =
  | { kind: "finish" | "call" | "callback" | "correction"; item: ConfirmareItem }
  | { kind: "takeNext" }
  | { kind: "idle" };

/**
 * The single next step for an operator, from the server-ordered queue (due date first, then FIFO). A confirmed call
 * is finished first, then new orders are called, then callbacks; only then is the next unassigned order taken (the
 * server picks which). Corrections wait for the shop and are shown, never chosen as the next call.
 */
export function nextAction(items: readonly ConfirmareItem[], employeeUuid: string, operates: boolean): ConfirmareNextAction {
  const mine = items.filter((item) => inTab(item, "mine", employeeUuid) && !item.commerceClosed);
  const finish = mine.find((item) => currentContact(item) === "confirmed");
  if (finish) return { kind: "finish", item: finish };
  const call = mine.find((item) => currentContact(item) === null);
  if (call) return { kind: "call", item: call };
  const callback = mine.find((item) => currentContact(item) === "no_answer" || currentContact(item) === "callback");
  if (callback) return { kind: "callback", item: callback };
  if (operates && items.some((item) => inTab(item, "unassigned", employeeUuid))) return { kind: "takeNext" };
  const correction = mine.find((item) => currentContact(item) === "needs_correction");
  if (correction) return { kind: "correction", item: correction };
  return { kind: "idle" };
}

// ---- The final review ----------------------------------------------------------------------------------------

/**
 * `shopReviewed` (API 2.40.0): what the shop panel shows now came from the shop with the prices Arasya holds (never
 * carried across refreshes: the panel drops its read when the order changes). The server checks the same on its read
 * audit; it is required only where the shop can be read.
 */
export type ApprovalDraft = { confirmedLineIds: readonly string[]; priceReviewed: boolean; shopReviewed?: boolean };
export type ApprovalRequirement = "pending" | "eligible" | "decider" | "open" | "contact" | "call" | "lines" | "priceEvidence" | "shopSettled" | "shopReviewed" | "priceReviewed";

/**
 * What still prevents the approval, in the order the operator works. Mirrors the server (which decides again on the
 * submitted version and review fingerprint): the approval is sent only when this list is empty. The financial
 * condition is NOT here: it never blocks the approval and always blocks production until the source clears it.
 */
export function approvalMissing(detail: ConfirmareDetail, draft: ApprovalDraft, canDecide: boolean): ApprovalRequirement[] {
  const missing: ApprovalRequirement[] = [];
  if (detail.status !== "pending") missing.push("pending");
  if (undetermined(detail)) missing.push("eligible");
  if (!canDecide) missing.push("decider");
  if (detail.commerceClosed) missing.push("open");
  if (!detail.review.contactAvailable) missing.push("contact");
  if (!detail.review.callRecorded) missing.push("call");
  const wanted = [...detail.review.lineIds].sort();
  const ticked = [...new Set(draft.confirmedLineIds)].filter((id) => wanted.includes(id)).sort();
  if (wanted.length === 0 || ticked.length !== wanted.length) missing.push("lines");
  if (!detail.review.pricingAvailable) missing.push("priceEvidence");
  // Phase 4B: a change made in the shop through Staff is settled and its order update has reached Arasya.
  if (detail.sourceAccess.changePending) missing.push("shopSettled");
  if (detail.sourceAccess.configured && draft.shopReviewed !== true) missing.push("shopReviewed");
  if (!draft.priceReviewed) missing.push("priceReviewed");
  return missing;
}

export const requirementLabel = (requirement: ApprovalRequirement): string => t(`cf.require.${requirement}` as MessageKey);

/** Whether the signed-in employee may decide or record calls on this order (the server checks again). */
export function canDecide(detail: ConfirmareItem, employee: Employee): boolean {
  if (detail.status !== "pending") return false;
  if (supervisesSource(employee, detail.sourceKey)) return true;
  return operatesSource(employee, detail.sourceKey) && detail.assignment?.employeeId === employee.employeeUuid;
}

/**
 * Keeps an in-progress review across a refresh: the ticks stay while the order is the same review; a new review
 * fingerprint (the shop changed the order) drops them, so nothing is confirmed on content the operator did not see.
 */
export function carryDraft(previous: { fingerprint: string; draft: ApprovalDraft } | null, detail: ConfirmareDetail): { draft: ApprovalDraft; reset: boolean } {
  if (!previous || previous.fingerprint !== detail.review.fingerprint) return { draft: { confirmedLineIds: [], priceReviewed: false }, reset: previous !== null && previous.fingerprint !== detail.review.fingerprint };
  return { draft: { confirmedLineIds: previous.draft.confirmedLineIds.filter((id) => detail.review.lineIds.includes(id)), priceReviewed: previous.draft.priceReviewed }, reset: false };
}

/** The Confirmare refusal a response carried (backend code), for the screen's own explanation. */
export function confirmareErrorKey(backendCode: string | undefined): MessageKey | null {
  const known = ["CONFIRMARE_CHANGED", "CONFIRMARE_REVIEW_STALE", "CONFIRMARE_CALL_NOT_RECORDED", "CONFIRMARE_CONTACT_UNAVAILABLE", "CONFIRMARE_CALL_REQUIRED", "CONFIRMARE_PRICE_UNAVAILABLE", "CONFIRMARE_PRICE_NOT_VERIFIED", "CONFIRMARE_LINES_NOT_CONFIRMED", "CONFIRMARE_NOT_PENDING", "CONFIRMARE_NOT_ASSIGNED_TO_YOU", "CONFIRMARE_ASSIGNED_TO_OTHER", "CONFIRMARE_UNDETERMINED", "CONFIRMARE_ORDER_CLOSED", "CONFIRMARE_SCOPE_DENIED", "CONFIRMARE_NOT_FOUND", "CONFIRMARE_SOURCE_SUSPENDED", "CONFIRMARE_OPERATOR_INELIGIBLE", "CONFIRMARE_NOT_REOPENABLE", "CONFIRMARE_SHOP_NOT_REVIEWED", "CONFIRMARE_SOURCE_ACCESS_DENIED", "CONFIRMARE_SOURCE_NOT_ELIGIBLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_SHOP_CHANGE_PENDING", "CONFIRMARE_SOURCE_EDIT_DISABLED", "CONFIRMARE_EDIT_INVALID", "CONFIRMARE_EDIT_REFUSED", "CONFIRMARE_EDIT_NOT_FOUND", "CONFIRMARE_SOURCE_UNAVAILABLE", "ORDER_PRODUCTION_STOPPED"];
  return backendCode && known.includes(backendCode) ? `cf.error.${backendCode}` as MessageKey : null;
}

/** Errors after which the screen reloads the order before anything else (a colleague or the shop changed it). */
export const RELOAD_CODES: readonly string[] = ["CONFIRMARE_CHANGED", "CONFIRMARE_REVIEW_STALE", "CONFIRMARE_NOT_PENDING", "CONFIRMARE_NOT_ASSIGNED_TO_YOU", "CONFIRMARE_ASSIGNED_TO_OTHER", "CONFIRMARE_LINES_NOT_CONFIRMED", "CONFIRMARE_ORDER_CLOSED", "ORDER_PRODUCTION_STOPPED"];
