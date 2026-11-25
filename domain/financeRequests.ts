import { t, type MessageKey } from "../i18n";
import { StaffServiceError, type Employee } from "./models";
import { financeScopesOf } from "./confirmareFinance";

/**
 * Finance tasks (Staff 2.41.0, API 2.50.0): the explicit finance work of one order, assigned to one finance employee.
 *
 *   operator -> request -> assigned finance employee -> verified | not_received | needs_clarification -> operator
 *
 * Every Trend Home and Outlet Perdele order submitted from 22 October 2026 00:00 (Bucharest) is covered, whatever its
 * workflow. An order outside Confirmare keeps its old workflow: its payment is never recorded in the shop from here (the
 * task ends in "manual review", never as money received). A finance employee works only the tasks assigned to them
 * (Verificări de plată) and never sees the order's customer, products or history; a second check or an exceptional money
 * task names its employee too. A finance supervisor observes the employees they supervise (Activitatea contabilității)
 * and never acts. Presentation only: the server decides who reaches what and checks every command again.
 */
export const REQUEST_STATES = ["requested", "assigned", "checking", "verified", "not_received", "needs_clarification", "exception", "cancelled", "requires_manual_review", "resolved"] as const;
export type RequestState = (typeof REQUEST_STATES)[number];
export const NOT_RECEIVED_REASONS = ["not_found", "amount_mismatch", "wrong_reference", "duplicate"] as const;
export type NotReceivedReason = (typeof NOT_RECEIVED_REASONS)[number];
export const CLARIFICATION_REASONS = ["reference_missing", "amount_unclear", "payer_unclear", "other"] as const;
export type ClarificationReason = (typeof CLARIFICATION_REASONS)[number];
export const CANCEL_REASONS = ["customer_withdrew", "already_settled", "requested_by_mistake"] as const;
export type CancelReason = (typeof CANCEL_REASONS)[number];
export const INBOX_CATEGORIES = ["todo", "clarification", "done"] as const;
export type InboxCategory = (typeof INBOX_CATEGORIES)[number];
export const TASK_KINDS = ["verify", "second_check", "awaiting_second_check", "exception"] as const;
export type TaskKind = (typeof TASK_KINDS)[number];
export const PURPOSES = ["advance", "second_check", "exception"] as const;
export type TaskPurpose = (typeof PURPOSES)[number];
/** Where the order's payment is recorded: the shop's finance record (Confirmare) or the old workflow (manual). */
export const LEDGERS = ["shop", "manual"] as const;
export type Ledger = (typeof LEDGERS)[number];
export const SCOPES = ["in_scope", "before_scope", "undetermined"] as const;
export type TaskScope = (typeof SCOPES)[number];
/** The money state an exception task is about (codes only). */
export const TASK_CODES = ["transfer_pending", "refund_due", "refund_liability", "refund_pending", "additional_payment_due", "reconciliation_required", "card_refund_verify"] as const;
export const OUTCOMES = ["verified_full", "verified_partial", "not_received", "found_manual", "state_cleared"] as const;
export type RequestOutcome = (typeof OUTCOMES)[number];
export const POLICIES = ["four_eyes", "single"] as const;
export type VerificationPolicy = (typeof POLICIES)[number];
export const OVERSIGHT_GROUPS = ["pending", "checking", "verified", "not_received", "attention"] as const;
export type OversightGroup = (typeof OVERSIGHT_GROUPS)[number];

export type Proforma = { series: string | null; number: string };
/** null: a task without a proforma (a second check or an exceptional money task). */
export type TaskProforma = Proforma | null;
export type Person = { id: string; displayName: string };

/** The operator's view of the order's request (step 4); `pollSeconds` null: nothing to wait for. */
export type OrderFinanceRequest = {
  request: {
    id: string; state: RequestState; version: number; outcome: RequestOutcome | null; reasonCode: string | null; purpose: TaskPurpose; ledger: Ledger; awaitingSecondCheck: boolean;
    assignee: string | null; proforma: TaskProforma; requestedAt: string; updatedAt: string; decidedAt: string | null;
  } | null;
  financial: { state: string; attention: string | null; open: boolean };
  /** Whether the order is covered (submitted from 22 October 2026) and where its payment is recorded. */
  scope: TaskScope; ledger: Ledger;
  canRequest: boolean; canResubmit: boolean; canCancel: boolean; pollSeconds: number | null;
  /** Only on the answer of a request command: whether this command created it (false: the open one was returned). */
  created?: boolean;
};

/** One card of the finance employee's inbox: the order number and the proforma, never an amount or the customer (the
 * task screen reads the money from the shop when it is opened). */
export type InboxItem = {
  id: string; source: string; orderNumber: string; purpose: TaskPurpose; taskCode: string | null; ledger: Ledger; proforma: TaskProforma;
  state: RequestState; version: number; category: InboxCategory; kind: TaskKind; assignedToMe: boolean; outcome: RequestOutcome | null; reasonCode: string | null;
  policy: VerificationPolicy | null; requestedAt: string; updatedAt: string; decidedAt: string | null;
  /** API 2.77.9: the advance the shop last showed for the task (null: not read yet); display only, never a decision input. */
  advance: ListedAdvance | null;
  /** API 2.77.9: a not received advance no newer request replaced (Așteaptă plata). */
  awaitingPayment: boolean;
  /** API 2.77.9: when the customer placed the order. */
  orderedAt: string | null;
  /** API 2.80.0: this employee decided the task (Finalizate azi); a payment not found is looked for again at `recheckAt`. */
  decidedByMe: boolean;
  recheckAt: string | null;
};
export type ListedAdvance = { currency: string; required: string | null; remaining: string | null; readAt: string };
/** `waiting` and `today` (API 2.77.9): the not received advances still open for a payment, the tasks decided today. */
export type InboxCounts = Record<InboxCategory, number> & { waiting: number; today: number };
export type Inbox = { items: InboxItem[]; counts: InboxCounts; sources: string[]; limit: number };

/** API 2.80.0 (YD SOFT 2.32.0): how a transfer was tied to the order. */
export const MATCH_BASES = ["proforma_reference", "customer_name", "other_payer", "customer_proof"] as const;
export type MatchBasis = (typeof MATCH_BASES)[number];
export type Transfer = {
  id: string; purpose: string; amount: string; reference: string; valueDate: string | null; method: string; state: "submitted" | "verified" | "rejected" | "corrected";
  decisionCode: string | null; flags: string[]; submittedBy: { id: string; displayName: string | null }; submittedAt: string;
  decidedBy: { id: string; displayName: string | null } | null; decidedAt: string | null;
  /** API 2.80.0: who paid (typed from the bank statement), how it was matched, and the entry a correction replaced. */
  payer: string | null; matchBasis: MatchBasis | null; matchNote: string | null; corrects: string | null;
};
/** API 2.80.0: the shop's one money calculation (total, received, cash on delivery, excess). */
export type TaskMoney = { known: boolean; total: string | null; advanceRequired: string | null; received: string | null; remaining: string | null; excess: string | null };
/** API 2.80.0: where the order stands, from Arasya's records (no amount, no customer data). */
export type TaskProgress = {
  known: boolean; approval: string | null; callConfirmed: boolean; paymentCleared: boolean; productionCleared: boolean; missing: string[];
  operator: { displayName: string } | null; routedTo: { displayName: string } | null; routedAt: string | null; stage: string | null; cancelled: boolean;
};
export type TaskLive =
  | {
    available: true; revision: string; readAt: string; currency: string;
    proforma: { series: string; number: string; amount: string | null; currency: string | null } | null; proformaChanged: boolean; ledger: Ledger;
    advance: { percentage: number; required: string | null; verified: string | null; remaining: string | null; accepted: boolean } | null;
    paymentReference: string | null;
    bank: { beneficiary: string; bank: string; ibanMasked: string; bic: string | null } | null;
    financial: { state: string; attention: string | null }; policy: VerificationPolicy; recordEnabled: boolean; transfers: Transfer[]; settledElsewhere: boolean;
    /** API 2.80.0 (absent from an older API: null / false). */
    money: TaskMoney | null; capabilities: { match: boolean; correction: boolean }; cod: { state: string; awb: boolean } | null;
    /**
     * API 2.81.0: `fromCopy` the task opened from Arasya's copy of the shop's last answer (no shop call); `stale` the shop
     * did not answer now and this copy (read at `readAt`) is shown instead. Every decision still reads the shop.
     */
    fromCopy: boolean; stale: boolean; staleReason: string | null;
  }
  | { available: false; reason: string };
export type TaskAllowed = { start: boolean; verify: boolean; notReceived: boolean; clarify: boolean; secondCheck: boolean; reconcile: boolean; addPayment: boolean; correctPayment: boolean };
export type FinanceTask = { request: InboxItem; live: TaskLive; allowed: TaskAllowed; me: string; singleVerification: boolean; progress: TaskProgress | null };

export type Receipt = { state: RequestState; outcome: RequestOutcome | null; reasonCode: string | null; policy: VerificationPolicy | null; awaitingSecondCheck: boolean; unknown: boolean; at: string };
export type DecisionAnswer = { request: InboxItem; receipt: Receipt };

/** API 2.80.0: without the proforma reference the payer is named (and the evidence for another payer or a customer's proof). */
export type MatchInput = { matchBasis?: MatchBasis; payerName?: string; matchNote?: string; paymentReference?: string };
export type VerifiedInput = { expectedVersion: number; outcome: "verified"; expectedRevision: string; amount: string; reference: string; valueDate: string; currency: string } & MatchInput;
export type PaymentInput = { expectedRevision: string; amount: string; reference: string; valueDate: string; currency: string; bankStatementConfirmed: true } & MatchInput & { matchBasis: MatchBasis };
export type CorrectionInput = Omit<PaymentInput, "paymentReference" | "matchBasis"> & { entryId: string; reasonNote: string; matchBasis?: MatchBasis; payerName?: string; matchNote?: string };
export type FollowUpAnswer = { state: string; idempotencyKey: string; request: InboxItem };
export type FinanceHistory = { items: InboxItem[]; next: string | null };
export type DecisionInput =
  | VerifiedInput
  | { expectedVersion: number; outcome: "not_received"; reasonCode: NotReceivedReason }
  | { expectedVersion: number; outcome: "needs_clarification"; reasonCode: ClarificationReason };
export type SecondCheckInput = { expectedVersion: number; expectedRevision: string; decision: "verify" } | { expectedVersion: number; expectedRevision: string; decision: "reject"; reasonCode: NotReceivedReason };

export type OversightItem = {
  id: string; source: string; orderNumber: string; purpose: TaskPurpose; taskCode: string | null; ledger: Ledger; proforma: TaskProforma;
  state: RequestState; outcome: RequestOutcome | null; reasonCode: string | null; policy: VerificationPolicy | null;
  assignee: Person | null; recordedBy: Person | null; checker: Person | null; decidedBy: Person | null; requestedAt: string; updatedAt: string; decidedAt: string | null;
};
export type Oversight = { items: OversightItem[]; counts: Record<OversightGroup, number>; employees: Person[]; limit: number };
export type OversightFilters = { employee?: string | null; source?: string | null; state?: OversightGroup | null; from?: string | null; to?: string | null };
export type OversightEvent = { version: number; action: string; actor: Person | null; previousState: string | null; state: string; outcome: string | null; reasonCode: string | null; policy: string | null; at: string };
export type OversightDetail = { request: OversightItem; events: OversightEvent[]; transfers: Transfer[] | null; currency: string | null };

export interface FinanceRequestsApi {
  orderFinanceRequest(globalOrderId: string, signal?: AbortSignal): Promise<OrderFinanceRequest>;
  requestFinance(globalOrderId: string, idempotencyKey: string): Promise<OrderFinanceRequest>;
  resubmitFinance(globalOrderId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<OrderFinanceRequest>;
  cancelFinance(globalOrderId: string, input: { expectedVersion: number; reasonCode: CancelReason }, idempotencyKey: string): Promise<OrderFinanceRequest>;
  financeInbox(signal?: AbortSignal): Promise<Inbox>;
  financeTask(requestId: string, signal?: AbortSignal): Promise<FinanceTask>;
  startTask(requestId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<DecisionAnswer>;
  decideTask(requestId: string, input: DecisionInput, idempotencyKey: string): Promise<DecisionAnswer>;
  secondCheck(requestId: string, input: SecondCheckInput, idempotencyKey: string): Promise<DecisionAnswer>;
  reconcileTask(requestId: string): Promise<DecisionAnswer>;
  /** API 2.80.0: a later transfer of a verified task's order, and the correction of one's own transfer. */
  addPayment(requestId: string, input: PaymentInput, idempotencyKey: string): Promise<FollowUpAnswer>;
  correctPayment(requestId: string, input: CorrectionInput, idempotencyKey: string): Promise<FollowUpAnswer>;
  /** API 2.80.0: closed tasks beyond the inbox's latest ones, with search and a cursor. */
  financeHistory(query: { q?: string; before?: string | null; source?: string | null }, signal?: AbortSignal): Promise<FinanceHistory>;
  financeOversight(filters: OversightFilters, signal?: AbortSignal): Promise<Oversight>;
  financeOversightDetail(requestId: string, signal?: AbortSignal): Promise<OversightDetail>;
}

// ---- Access (presentation only: every route checks again) ------------------------------------------------------

const working = (employee: Employee): boolean => employee.status === "active" && !employee.mustChangePassword && employee.applications.includes("staff");

/** The inbox exists for an employee who records, approves or verifies payments (a view-only scope has no tasks). */
export function canUseFinanceInbox(employee: Employee): boolean {
  if (!working(employee)) return false;
  const scopes = financeScopesOf(employee);
  return scopes.record.length + scopes.approve.length + (employee.financeScopes?.verify?.length ?? 0) > 0;
}

/** The oversight screen exists for an employee the root made supervisor of finance employees. */
export function canOverseeFinance(employee: Employee): boolean {
  return working(employee) && employee.financeSupervisor === true;
}

// ---- The operator's wait ---------------------------------------------------------------------------------------

/** The operator's screen stops asking after this long without a change (the employee can always ask again). */
export const POLL_LIMIT_MS = 30 * 60 * 1000;

/**
 * When the operator's screen asks next, or null: the server's hint (5 s while finance checks, 10 s while it waits),
 * clamped to 5–10 s, never while the tab is hidden, never after POLL_LIMIT_MS without a change.
 */
export function nextPollMs(view: Pick<OrderFinanceRequest, "pollSeconds"> | null, hidden: boolean, sinceChangeMs: number): number | null {
  if (!view || view.pollSeconds === null || hidden || sinceChangeMs >= POLL_LIMIT_MS) return null;
  return Math.min(10, Math.max(5, view.pollSeconds)) * 1000;
}

/** What the operator sees of the request in step 4 (one message per state, never an amount). */
export type RequestPhase = "none" | "waitingAssignment" | "assigned" | "checking" | "secondCheck" | "verified" | "verifiedPartial" | "notReceived" | "clarification" | "exception" | "cancelled" | "manualReview" | "resolved";
export function requestPhase(view: OrderFinanceRequest): RequestPhase {
  const request = view.request;
  if (!request) return "none";
  switch (request.state) {
    case "requested": return "waitingAssignment";
    case "assigned": return "assigned";
    case "checking": return request.awaitingSecondCheck ? "secondCheck" : "checking";
    case "verified": return request.outcome === "verified_partial" ? "verifiedPartial" : "verified";
    case "not_received": return "notReceived";
    case "needs_clarification": return "clarification";
    case "exception": return "exception";
    case "requires_manual_review": return "manualReview";
    case "resolved": return "resolved";
    default: return "cancelled";
  }
}

// ---- Words -----------------------------------------------------------------------------------------------------

export const proformaLabel = (proforma: TaskProforma): string => (proforma ? `${proforma.series ? `${proforma.series} ` : ""}${proforma.number}` : "—");
export const taskCodeLabel = (code: string | null): string => (code && (TASK_CODES as readonly string[]).includes(code) ? t(`fr.taskCode.${code}` as MessageKey) : "");
/** The order's global id from a task's shop and number (the money panel of an exception task). */
export const taskOrderId = (item: Pick<InboxItem, "source" | "orderNumber">): string => `${item.source}:${item.orderNumber}`;
export const stateLabel = (state: RequestState): string => t(`fr.state.${state}` as MessageKey);
export const reasonLabel = (reason: string | null): string => {
  if (!reason) return "";
  return [...NOT_RECEIVED_REASONS, ...CLARIFICATION_REASONS, ...CANCEL_REASONS].includes(reason as never) ? t(`fr.reason.${reason}` as MessageKey) : reason;
};
export const policyLabel = (policy: VerificationPolicy | null): string => (policy ? t(`fr.policy.${policy}` as MessageKey) : "—");

/** The finance request error a response carried (backend code), or null. */
const ERRORS = ["FINANCE_REQUEST_NOT_FOUND", "FINANCE_REQUEST_STATE", "FINANCE_REQUEST_STALE", "FINANCE_REQUEST_INVALID", "FINANCE_REQUEST_NO_PROFORMA", "FINANCE_REQUEST_ATTENTION", "FINANCE_REQUEST_NOTHING_DUE",
  "FINANCE_REQUEST_PROFORMA_CHANGED", "FINANCE_REQUEST_CURRENCY", "FINANCE_REQUEST_NOT_APPROVED", "FINANCE_REQUEST_OUT_OF_SCOPE", "FINANCE_REQUEST_SCOPE_UNDETERMINED", "FINANCE_REQUEST_FINANCING", "FINANCE_REQUEST_LEDGER_MISMATCH", "FINANCE_REQUEST_NOT_COD", "FINANCE_REQUEST_ORDER_CLOSED", "FINANCE_REQUEST_TRANSFER_PENDING", "FINANCE_REQUEST_PAYMENT_REFERENCE", "FINANCE_REQUEST_NOTHING_TO_RECONCILE", "FINANCE_REQUEST_UNAVAILABLE", "FINANCE_OVERSIGHT_DENIED",
  "CONFIRMARE_FINANCE_POLICY_FOUR_EYES", "CONFIRMARE_FINANCE_SAME_EMPLOYEE", "CONFIRMARE_FINANCE_DENIED", "CONFIRMARE_FINANCE_DISABLED", "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_FINANCE_REFUSED"] as const;
export function requestErrorKey(backendCode: string | undefined): MessageKey | null {
  return backendCode && (ERRORS as readonly string[]).includes(backendCode) ? `fr.error.${backendCode}` as MessageKey : null;
}

/** A masked IBAN for the screen: country, check digits and the last four characters only. */
export function maskIban(iban: string): string {
  const compact = iban.replace(/\s+/g, "").toUpperCase();
  if (compact.length < 10) return "••••";
  return `${compact.slice(0, 4)} •••• •••• ${compact.slice(-4)}`;
}

export const financeTaskPath = (requestId: string): string => `/confirmare/finance/requests/${requestId}`;
export const oversightPath = (requestId?: string): string => requestId ? `/confirmare/finance/oversight/${requestId}` : "/confirmare/finance/oversight";
export const financeOrderPath = (globalOrderId: string): string => `/confirmare/finance/orders/${encodeURIComponent(globalOrderId)}`;
/** The operator's finance request of an order outside Confirmare (no order data on that screen, the request only). */
export const financeLegacyPath = (globalOrderId: string): string => `/confirmare/finance/legacy/${encodeURIComponent(globalOrderId)}`;
/** An order number as the shop prints it (digits, letters, . _ -). */
export const validOrderNumber = (value: string): boolean => /^[A-Za-z0-9._-]{1,128}$/.test(value);

// ---- Strict mapping (anything unexpected is refused, never half shown) -------------------------------------------

const fail = (): never => { throw new StaffServiceError("SERVER_ERROR"); };
const obj = (value: unknown): Record<string, unknown> => (value !== null && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : fail());
const str = (value: unknown, max = 200): string => (typeof value === "string" && value.length <= max ? value : fail());
const optStr = (value: unknown, max = 200): string | null => (value == null ? null : str(value, max));
const code = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^[a-z_]{2,40}$/.test(value) ? value : fail()));
const int = (value: unknown): number => (typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : fail());
const bool = (value: unknown): boolean => (typeof value === "boolean" ? value : fail());
const instant = (value: unknown): string => (typeof value === "string" && value.length <= 40 && Number.isFinite(Date.parse(value)) ? value : fail());
const optInstant = (value: unknown): string | null => (value == null ? null : instant(value));
const money = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^-?(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/.test(value) ? value : fail()));
const uuid = (value: unknown): string => (typeof value === "string" && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(value) ? value : fail());
const oneOf = <T extends string>(value: unknown, allowed: readonly T[]): T => (typeof value === "string" && (allowed as readonly string[]).includes(value) ? value as T : fail());
const optOneOf = <T extends string>(value: unknown, allowed: readonly T[]): T | null => (value == null ? null : oneOf(value, allowed));
const list = <T>(value: unknown, max: number, map: (entry: unknown) => T): T[] => (Array.isArray(value) && value.length <= max ? value.map(map) : fail());
const person = (value: unknown): Person | null => { if (value == null) return null; const raw = obj(value); return { id: uuid(raw.id), displayName: str(raw.displayName, 200) }; };
const proforma = (value: unknown): TaskProforma => { if (value == null) return null; const raw = obj(value); return { series: optStr(raw.series, 40), number: str(raw.number, 40) }; };
const counts = <K extends string>(value: unknown, keys: readonly K[]): Record<K, number> => { const raw = obj(value); return Object.fromEntries(keys.map((key) => [key, int(raw[key])])) as Record<K, number>; };

export function mapOrderFinanceRequest(value: unknown): OrderFinanceRequest {
  const raw = obj(value);
  const financial = obj(raw.financial);
  const request = raw.request == null ? null : (() => {
    const r = obj(raw.request);
    return {
      id: uuid(r.id), state: oneOf(r.state, REQUEST_STATES), version: int(r.version), outcome: optOneOf(r.outcome, OUTCOMES), reasonCode: code(r.reasonCode),
      purpose: oneOf(r.purpose, PURPOSES), ledger: oneOf(r.ledger, LEDGERS), awaitingSecondCheck: bool(r.awaitingSecondCheck), assignee: optStr(r.assignee, 200), proforma: proforma(r.proforma),
      requestedAt: instant(r.requestedAt), updatedAt: instant(r.updatedAt), decidedAt: optInstant(r.decidedAt),
    };
  })();
  const poll = raw.pollSeconds == null ? null : int(raw.pollSeconds);
  return {
    request, financial: { state: str(financial.state, 20), attention: code(financial.attention), open: bool(financial.open) },
    scope: oneOf(raw.scope, SCOPES), ledger: oneOf(raw.ledger, LEDGERS),
    canRequest: bool(raw.canRequest), canResubmit: bool(raw.canResubmit), canCancel: bool(raw.canCancel), pollSeconds: poll,
    ...(raw.created === undefined ? {} : { created: bool(raw.created) }),
  };
}

export function mapInboxItem(value: unknown): InboxItem {
  const raw = obj(value);
  return {
    id: uuid(raw.id), source: str(raw.source, 40), orderNumber: str(raw.orderNumber, 64), purpose: oneOf(raw.purpose, PURPOSES), taskCode: code(raw.taskCode), ledger: oneOf(raw.ledger, LEDGERS), proforma: proforma(raw.proforma),
    state: oneOf(raw.state, REQUEST_STATES), version: int(raw.version), category: oneOf(raw.category, INBOX_CATEGORIES),
    kind: oneOf(raw.kind, TASK_KINDS), assignedToMe: bool(raw.assignedToMe), outcome: optOneOf(raw.outcome, OUTCOMES), reasonCode: code(raw.reasonCode), policy: optOneOf(raw.policy, POLICIES),
    requestedAt: instant(raw.requestedAt), updatedAt: instant(raw.updatedAt), decidedAt: optInstant(raw.decidedAt),
    advance: listedAdvance(raw.advance), awaitingPayment: raw.awaitingPayment === undefined ? false : bool(raw.awaitingPayment), orderedAt: optInstant(raw.orderedAt),
    decidedByMe: raw.decidedByMe === undefined ? false : bool(raw.decidedByMe), recheckAt: optInstant(raw.recheckAt),
  };
}

function listedAdvance(value: unknown): ListedAdvance | null {
  if (value == null) return null;
  const raw = obj(value);
  return { currency: str(raw.currency, 3), required: money(raw.required), remaining: money(raw.remaining), readAt: instant(raw.readAt) };
}

/**
 * Staff 2.52.5 (owner 2026-11-25): the most items one inbox answer may carry. API FinanceVerificationRequests::inbox lists
 * up to 1000 open tasks (OPEN_LIMIT) and the 100 latest done ones (DONE_LIMIT); a stricter cap here rejected the whole
 * answer, so an employee with more than 300 tasks saw none. Staff 2.53.0 (API 2.77.9): plus up to 300 not received
 * advances still waiting for a payment (WAITING_LIMIT): 1000 + 100 + 300.
 */
export const INBOX_MAX_ITEMS = 1400;

export function mapInbox(value: unknown): Inbox {
  const raw = obj(value);
  return {
    items: list(raw.items, INBOX_MAX_ITEMS, mapInboxItem),
    counts: inboxCounts(raw.counts), sources: list(raw.sources, 10, (entry) => str(entry, 40)), limit: int(raw.limit),
  };
}

function inboxCounts(value: unknown): InboxCounts {
  const raw = obj(value);
  return { ...counts(raw, INBOX_CATEGORIES), waiting: raw.waiting === undefined ? 0 : int(raw.waiting), today: raw.today === undefined ? 0 : int(raw.today) };
}

function mapTransfer(value: unknown): Transfer {
  const raw = obj(value);
  const who = (entry: unknown) => { const item = obj(entry); return { id: str(item.id, 64), displayName: optStr(item.displayName, 200) }; };
  return {
    id: str(raw.id, 64), purpose: str(raw.purpose, 30), amount: money(raw.amount) ?? fail(), reference: str(raw.reference, 64), valueDate: optStr(raw.valueDate, 10), method: str(raw.method, 30),
    state: oneOf(raw.state, ["submitted", "verified", "rejected", "corrected"] as const), decisionCode: code(raw.decisionCode), flags: list(raw.flags, 10, (entry) => str(entry, 40)),
    submittedBy: who(raw.submittedBy), submittedAt: instant(raw.submittedAt), decidedBy: raw.decidedBy == null ? null : who(raw.decidedBy), decidedAt: optInstant(raw.decidedAt),
    payer: optStr(raw.payer, 128), matchBasis: optOneOf(raw.matchBasis, MATCH_BASES), matchNote: optStr(raw.matchNote, 255), corrects: optStr(raw.corrects, 64),
  };
}

function mapLive(value: unknown): TaskLive {
  const raw = obj(value);
  if (raw.available === false) return { available: false, reason: code(raw.reason) ?? fail() };
  if (raw.available !== true) fail();
  const live = raw.proforma == null ? null : obj(raw.proforma);
  const advance = raw.advance == null ? null : obj(raw.advance);
  const bank = raw.bank == null ? null : obj(raw.bank);
  const financial = obj(raw.financial);
  return {
    available: true, revision: str(raw.revision, 80), readAt: instant(raw.readAt), currency: str(raw.currency, 3),
    proforma: live === null ? null : { series: str(live.series, 40), number: str(live.number, 40), amount: money(live.amount), currency: optStr(live.currency, 3) },
    proformaChanged: bool(raw.proformaChanged), ledger: oneOf(raw.ledger, LEDGERS),
    advance: advance === null ? null : { percentage: int(advance.percentage), required: money(advance.required), verified: money(advance.verified), remaining: money(advance.remaining), accepted: bool(advance.accepted) },
    paymentReference: optStr(raw.paymentReference, 64),
    bank: bank === null ? null : { beneficiary: str(bank.beneficiary, 200), bank: str(bank.bank, 200), ibanMasked: str(bank.ibanMasked, 40), bic: optStr(bank.bic, 20) },
    financial: { state: str(financial.state, 20), attention: code(financial.attention) }, policy: oneOf(raw.policy, POLICIES), recordEnabled: bool(raw.recordEnabled),
    transfers: list(raw.transfers, 50, mapTransfer), settledElsewhere: bool(raw.settledElsewhere),
    money: raw.money == null ? null : taskMoney(raw.money),
    capabilities: raw.capabilities == null ? { match: false, correction: false } : { match: bool(obj(raw.capabilities).match), correction: bool(obj(raw.capabilities).correction) },
    cod: raw.cod == null ? null : { state: str(obj(raw.cod).state, 20), awb: bool(obj(raw.cod).awb) },
    fromCopy: raw.source === "cache", stale: raw.stale === true, staleReason: raw.staleReason == null ? null : code(raw.staleReason),
  };
}

function taskMoney(value: unknown): TaskMoney {
  const raw = obj(value);
  return { known: bool(raw.known), total: money(raw.total), advanceRequired: money(raw.advanceRequired), received: money(raw.received), remaining: money(raw.remaining), excess: money(raw.excess) };
}

function taskProgress(value: unknown): TaskProgress | null {
  if (value == null) return null;
  const raw = obj(value);
  if (raw.known === false) return null;
  const name = (entry: unknown) => entry == null ? null : { displayName: str(obj(entry).displayName, 200) };
  return {
    known: true, approval: optStr(raw.approval, 20), callConfirmed: bool(raw.callConfirmed), paymentCleared: bool(raw.paymentCleared), productionCleared: bool(raw.productionCleared),
    missing: list(raw.missing, 10, (entry) => str(entry, 20)), operator: name(raw.operator), routedTo: name(raw.routedTo), routedAt: optInstant(raw.routedAt), stage: optStr(raw.stage, 40), cancelled: bool(raw.cancelled),
  };
}

export function mapFinanceTask(value: unknown): FinanceTask {
  const raw = obj(value);
  const allowed = obj(raw.allowed);
  return {
    request: mapInboxItem(raw.request), live: mapLive(raw.live),
    allowed: { start: bool(allowed.start), verify: bool(allowed.verify), notReceived: bool(allowed.notReceived), clarify: bool(allowed.clarify), secondCheck: bool(allowed.secondCheck), reconcile: bool(allowed.reconcile),
      addPayment: allowed.addPayment === undefined ? false : bool(allowed.addPayment), correctPayment: allowed.correctPayment === undefined ? false : bool(allowed.correctPayment) },
    me: uuid(raw.me), singleVerification: bool(raw.singleVerification), progress: taskProgress(raw.progress),
  };
}

export function mapDecisionAnswer(value: unknown): DecisionAnswer {
  const raw = obj(value);
  const receipt = obj(raw.receipt);
  return {
    request: mapInboxItem(raw.request),
    receipt: {
      state: oneOf(receipt.state, REQUEST_STATES), outcome: optOneOf(receipt.outcome, OUTCOMES), reasonCode: code(receipt.reasonCode), policy: optOneOf(receipt.policy, POLICIES),
      awaitingSecondCheck: bool(receipt.awaitingSecondCheck), unknown: bool(receipt.unknown), at: instant(receipt.at),
    },
  };
}

function mapOversightItem(value: unknown): OversightItem {
  const raw = obj(value);
  return {
    id: uuid(raw.id), source: str(raw.source, 40), orderNumber: str(raw.orderNumber, 64), purpose: oneOf(raw.purpose, PURPOSES), taskCode: code(raw.taskCode), ledger: oneOf(raw.ledger, LEDGERS), proforma: proforma(raw.proforma),
    state: oneOf(raw.state, REQUEST_STATES), outcome: optOneOf(raw.outcome, OUTCOMES), reasonCode: code(raw.reasonCode), policy: optOneOf(raw.policy, POLICIES),
    assignee: person(raw.assignee), recordedBy: person(raw.recordedBy), checker: person(raw.checker), decidedBy: person(raw.decidedBy), requestedAt: instant(raw.requestedAt), updatedAt: instant(raw.updatedAt), decidedAt: optInstant(raw.decidedAt),
  };
}

export function mapOversight(value: unknown): Oversight {
  const raw = obj(value);
  return { items: list(raw.items, 300, mapOversightItem), counts: counts(raw.counts, OVERSIGHT_GROUPS), employees: list(raw.employees, 100, (entry) => person(entry) ?? fail()), limit: int(raw.limit) };
}

export function mapOversightDetail(value: unknown): OversightDetail {
  const raw = obj(value);
  return {
    request: mapOversightItem(raw.request),
    events: list(raw.events, 200, (entry) => {
      const event = obj(entry);
      return { version: int(event.version), action: code(event.action) ?? fail(), actor: person(event.actor), previousState: code(event.previousState), state: code(event.state) ?? fail(), outcome: code(event.outcome), reasonCode: code(event.reasonCode), policy: code(event.policy), at: instant(event.at) };
    }),
    transfers: raw.transfers == null ? null : list(raw.transfers, 50, mapTransfer),
    currency: optStr(raw.currency, 3),
  };
}

export function mapFollowUp(value: unknown): FollowUpAnswer {
  const raw = obj(value);
  return { state: str(raw.state, 20), idempotencyKey: str(raw.idempotencyKey, 120), request: mapInboxItem(raw.request) };
}

/** API 2.80.0: one page of closed tasks (at most 50) and the cursor of the next one. */
export function mapHistory(value: unknown): FinanceHistory {
  const raw = obj(value);
  return { items: list(raw.items, 60, mapInboxItem), next: raw.next == null ? null : str(raw.next, 100) };
}

const historyQuery = (query: { q?: string; before?: string | null; source?: string | null }): string => {
  const entries = Object.entries(query).filter((entry): entry is [string, string] => typeof entry[1] === "string" && entry[1] !== "");
  return entries.length > 0 ? `?${new URLSearchParams(entries).toString()}` : "";
};

const oversightQuery = (filters: OversightFilters): string => {
  const entries = Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === "string" && entry[1] !== "");
  return entries.length > 0 ? `?${new URLSearchParams(entries).toString()}` : "";
};

type Http = { request<T>(path: string, init: RequestInit & { timeoutMs?: number }, map: (value: unknown) => T): Promise<T> };

/**
 * Staff 2.56.0 (P0 2026-11-25): how long a finance command may take before its answer counts as lost. The server reads
 * the shop and then sends the action (each call bounded at 8 s), so 12 s could cut off a payment the shop recorded.
 */
export const FINANCE_COMMAND_TIMEOUT_MS = 30_000;

/** The HTTP side of the requests (no-store: money and task data are never kept in the browser's cache). */
export function financeRequestsHttp(http: Http): FinanceRequestsApi {
  const order = (globalOrderId: string, tail = "") => `/confirmare/orders/${encodeURIComponent(globalOrderId)}/finance-request${tail}`;
  const task = (requestId: string, tail = "") => `/confirmare/finance/requests/${encodeURIComponent(requestId)}${tail}`;
  const post = (key: string, body: unknown): RequestInit & { timeoutMs: number } => ({ method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(body), timeoutMs: FINANCE_COMMAND_TIMEOUT_MS });
  return {
    orderFinanceRequest: (globalOrderId, signal) => http.request(order(globalOrderId), { signal, cache: "no-store" }, mapOrderFinanceRequest),
    requestFinance: (globalOrderId, key) => http.request(order(globalOrderId), post(key, { purpose: "advance" }), mapOrderFinanceRequest),
    resubmitFinance: (globalOrderId, input, key) => http.request(order(globalOrderId, "/resubmit"), post(key, input), mapOrderFinanceRequest),
    cancelFinance: (globalOrderId, input, key) => http.request(order(globalOrderId, "/cancel"), post(key, input), mapOrderFinanceRequest),
    financeInbox: (signal) => http.request("/confirmare/finance/requests", { signal, cache: "no-store" }, mapInbox),
    financeTask: (requestId, signal) => http.request(task(requestId), { signal, cache: "no-store" }, mapFinanceTask),
    startTask: (requestId, input, key) => http.request(task(requestId, "/start"), post(key, input), mapDecisionAnswer),
    decideTask: (requestId, input, key) => http.request(task(requestId, "/decide"), post(key, input), mapDecisionAnswer),
    secondCheck: (requestId, input, key) => http.request(task(requestId, "/second-check"), post(key, input), mapDecisionAnswer),
    reconcileTask: (requestId) => http.request(task(requestId, "/reconcile"), { method: "POST", cache: "no-store", body: "{}", timeoutMs: FINANCE_COMMAND_TIMEOUT_MS }, mapDecisionAnswer),
    addPayment: (requestId, input, key) => http.request(task(requestId, "/add-payment"), post(key, input), mapFollowUp),
    correctPayment: (requestId, input, key) => http.request(task(requestId, "/correct-payment"), post(key, input), mapFollowUp),
    financeHistory: (query, signal) => http.request(`/confirmare/finance/requests/history${historyQuery(query)}`, { signal, cache: "no-store" }, mapHistory),
    financeOversight: (filters, signal) => http.request(`/confirmare/finance/oversight${oversightQuery(filters)}`, { signal, cache: "no-store" }, mapOversight),
    financeOversightDetail: (requestId, signal) => http.request(`/confirmare/finance/oversight/${encodeURIComponent(requestId)}`, { signal, cache: "no-store" }, mapOversightDetail),
  };
}
