import { t, type MessageKey } from "../i18n";
import { StaffServiceError, type Employee } from "./models";
import { financeScopesOf } from "./confirmareFinance";

/**
 * Finance verification requests (Staff 2.41.0, API 2.50.0): the explicit finance task of one order's advance payment.
 *
 *   operator -> request -> assigned finance employee -> verified | not_received | needs_clarification -> operator
 *
 * A finance employee works only the requests assigned to them (Verificări de plată) and never sees the order's customer,
 * products or history. A finance supervisor observes the employees they supervise (Activitatea contabilității) and never
 * acts. Presentation only: the server decides who reaches what and checks every command again.
 */
export const REQUEST_STATES = ["requested", "assigned", "checking", "verified", "not_received", "needs_clarification", "exception", "cancelled"] as const;
export type RequestState = (typeof REQUEST_STATES)[number];
export const NOT_RECEIVED_REASONS = ["not_found", "amount_mismatch", "wrong_reference", "duplicate"] as const;
export type NotReceivedReason = (typeof NOT_RECEIVED_REASONS)[number];
export const CLARIFICATION_REASONS = ["reference_missing", "amount_unclear", "payer_unclear", "other"] as const;
export type ClarificationReason = (typeof CLARIFICATION_REASONS)[number];
export const CANCEL_REASONS = ["customer_withdrew", "already_settled", "requested_by_mistake"] as const;
export type CancelReason = (typeof CANCEL_REASONS)[number];
export const INBOX_CATEGORIES = ["todo", "clarification", "done"] as const;
export type InboxCategory = (typeof INBOX_CATEGORIES)[number];
export const TASK_KINDS = ["verify", "second_check", "awaiting_second_check"] as const;
export type TaskKind = (typeof TASK_KINDS)[number];
export const OUTCOMES = ["verified_full", "verified_partial", "not_received"] as const;
export type RequestOutcome = (typeof OUTCOMES)[number];
export const POLICIES = ["four_eyes", "single"] as const;
export type VerificationPolicy = (typeof POLICIES)[number];
export const OVERSIGHT_GROUPS = ["pending", "checking", "verified", "not_received", "attention"] as const;
export type OversightGroup = (typeof OVERSIGHT_GROUPS)[number];

export type Proforma = { series: string | null; number: string };
export type Person = { id: string; displayName: string };

/** The operator's view of the order's request (step 4); `pollSeconds` null: nothing to wait for. */
export type OrderFinanceRequest = {
  request: {
    id: string; state: RequestState; version: number; outcome: RequestOutcome | null; reasonCode: string | null; awaitingSecondCheck: boolean;
    assignee: string | null; proforma: Proforma; requestedAt: string; updatedAt: string; decidedAt: string | null;
  } | null;
  financial: { state: string; attention: string | null; open: boolean };
  canRequest: boolean; canResubmit: boolean; canCancel: boolean; pollSeconds: number | null;
  /** Only on the answer of a request command: whether this command created it (false: the open one was returned). */
  created?: boolean;
};

/** One card of the finance employee's inbox: the order number and the proforma, never an amount or the customer (the
 * task screen reads the money from the shop when it is opened). */
export type InboxItem = {
  id: string; source: string; orderNumber: string; proforma: Proforma;
  state: RequestState; version: number; category: InboxCategory; kind: TaskKind; assignedToMe: boolean; outcome: RequestOutcome | null; reasonCode: string | null;
  policy: VerificationPolicy | null; requestedAt: string; updatedAt: string; decidedAt: string | null;
};
export type LegacySecondCheck = { globalOrderId: string; source: string; orderNumber: string; changedAt: string | null };
export type Inbox = { items: InboxItem[]; legacySecondChecks: LegacySecondCheck[]; counts: Record<InboxCategory, number>; sources: string[]; limit: number };

export type Transfer = {
  id: string; purpose: string; amount: string; reference: string; valueDate: string | null; method: string; state: "submitted" | "verified" | "rejected";
  decisionCode: string | null; flags: string[]; submittedBy: { id: string; displayName: string | null }; submittedAt: string;
  decidedBy: { id: string; displayName: string | null } | null; decidedAt: string | null;
};
export type TaskLive =
  | {
    available: true; revision: string; readAt: string; currency: string;
    proforma: { series: string; number: string; amount: string | null; currency: string | null } | null; proformaChanged: boolean;
    advance: { percentage: number; required: string | null; verified: string | null; remaining: string | null; accepted: boolean } | null;
    paymentReference: string | null;
    bank: { beneficiary: string; bank: string; ibanMasked: string; bic: string | null } | null;
    financial: { state: string; attention: string | null }; policy: VerificationPolicy; recordEnabled: boolean; transfers: Transfer[]; settledElsewhere: boolean;
  }
  | { available: false; reason: string };
export type TaskAllowed = { start: boolean; verify: boolean; notReceived: boolean; clarify: boolean; secondCheck: boolean; reconcile: boolean };
export type FinanceTask = { request: InboxItem; live: TaskLive; allowed: TaskAllowed; me: string; singleVerification: boolean };

export type Receipt = { state: RequestState; outcome: RequestOutcome | null; reasonCode: string | null; policy: VerificationPolicy | null; awaitingSecondCheck: boolean; unknown: boolean; at: string };
export type DecisionAnswer = { request: InboxItem; receipt: Receipt };

export type VerifiedInput = { expectedVersion: number; outcome: "verified"; expectedRevision: string; amount: string; reference: string; valueDate: string; paymentReference: string; currency: string };
export type DecisionInput =
  | VerifiedInput
  | { expectedVersion: number; outcome: "not_received"; reasonCode: NotReceivedReason }
  | { expectedVersion: number; outcome: "needs_clarification"; reasonCode: ClarificationReason };
export type SecondCheckInput = { expectedVersion: number; expectedRevision: string; decision: "verify" } | { expectedVersion: number; expectedRevision: string; decision: "reject"; reasonCode: NotReceivedReason };

export type OversightItem = {
  id: string; source: string; orderNumber: string; proforma: Proforma;
  state: RequestState; outcome: RequestOutcome | null; reasonCode: string | null; policy: VerificationPolicy | null;
  assignee: Person | null; recordedBy: Person | null; decidedBy: Person | null; requestedAt: string; updatedAt: string; decidedAt: string | null;
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
export type RequestPhase = "none" | "waitingAssignment" | "assigned" | "checking" | "secondCheck" | "verified" | "verifiedPartial" | "notReceived" | "clarification" | "exception" | "cancelled";
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
    default: return "cancelled";
  }
}

// ---- Words -----------------------------------------------------------------------------------------------------

export const proformaLabel = (proforma: Proforma): string => `${proforma.series ? `${proforma.series} ` : ""}${proforma.number}`;
export const stateLabel = (state: RequestState): string => t(`fr.state.${state}` as MessageKey);
export const reasonLabel = (reason: string | null): string => {
  if (!reason) return "";
  return [...NOT_RECEIVED_REASONS, ...CLARIFICATION_REASONS, ...CANCEL_REASONS].includes(reason as never) ? t(`fr.reason.${reason}` as MessageKey) : reason;
};
export const policyLabel = (policy: VerificationPolicy | null): string => (policy ? t(`fr.policy.${policy}` as MessageKey) : "—");

/** The finance request error a response carried (backend code), or null. */
const ERRORS = ["FINANCE_REQUEST_NOT_FOUND", "FINANCE_REQUEST_STATE", "FINANCE_REQUEST_STALE", "FINANCE_REQUEST_INVALID", "FINANCE_REQUEST_NO_PROFORMA", "FINANCE_REQUEST_ATTENTION", "FINANCE_REQUEST_NOTHING_DUE",
  "FINANCE_REQUEST_PROFORMA_CHANGED", "FINANCE_REQUEST_CURRENCY", "FINANCE_REQUEST_NOT_APPROVED", "FINANCE_REQUEST_NOT_COD", "FINANCE_REQUEST_ORDER_CLOSED", "FINANCE_REQUEST_TRANSFER_PENDING", "FINANCE_REQUEST_PAYMENT_REFERENCE", "FINANCE_REQUEST_NOTHING_TO_RECONCILE", "FINANCE_REQUEST_UNAVAILABLE", "FINANCE_OVERSIGHT_DENIED",
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
const proforma = (value: unknown): Proforma => { const raw = obj(value); return { series: optStr(raw.series, 40), number: str(raw.number, 40) }; };
const counts = <K extends string>(value: unknown, keys: readonly K[]): Record<K, number> => { const raw = obj(value); return Object.fromEntries(keys.map((key) => [key, int(raw[key])])) as Record<K, number>; };

export function mapOrderFinanceRequest(value: unknown): OrderFinanceRequest {
  const raw = obj(value);
  const financial = obj(raw.financial);
  const request = raw.request == null ? null : (() => {
    const r = obj(raw.request);
    return {
      id: uuid(r.id), state: oneOf(r.state, REQUEST_STATES), version: int(r.version), outcome: optOneOf(r.outcome, OUTCOMES), reasonCode: code(r.reasonCode),
      awaitingSecondCheck: bool(r.awaitingSecondCheck), assignee: optStr(r.assignee, 200), proforma: proforma(r.proforma),
      requestedAt: instant(r.requestedAt), updatedAt: instant(r.updatedAt), decidedAt: optInstant(r.decidedAt),
    };
  })();
  const poll = raw.pollSeconds == null ? null : int(raw.pollSeconds);
  return {
    request, financial: { state: str(financial.state, 20), attention: code(financial.attention), open: bool(financial.open) },
    canRequest: bool(raw.canRequest), canResubmit: bool(raw.canResubmit), canCancel: bool(raw.canCancel), pollSeconds: poll,
    ...(raw.created === undefined ? {} : { created: bool(raw.created) }),
  };
}

export function mapInboxItem(value: unknown): InboxItem {
  const raw = obj(value);
  return {
    id: uuid(raw.id), source: str(raw.source, 40), orderNumber: str(raw.orderNumber, 64), proforma: proforma(raw.proforma),
    state: oneOf(raw.state, REQUEST_STATES), version: int(raw.version), category: oneOf(raw.category, INBOX_CATEGORIES),
    kind: oneOf(raw.kind, TASK_KINDS), assignedToMe: bool(raw.assignedToMe), outcome: optOneOf(raw.outcome, OUTCOMES), reasonCode: code(raw.reasonCode), policy: optOneOf(raw.policy, POLICIES),
    requestedAt: instant(raw.requestedAt), updatedAt: instant(raw.updatedAt), decidedAt: optInstant(raw.decidedAt),
  };
}

export function mapInbox(value: unknown): Inbox {
  const raw = obj(value);
  return {
    items: list(raw.items, 300, mapInboxItem),
    legacySecondChecks: list(raw.legacySecondChecks, 50, (entry) => { const item = obj(entry); return { globalOrderId: str(item.globalOrderId, 200), source: str(item.source, 40), orderNumber: str(item.orderNumber, 64), changedAt: optInstant(item.changedAt) }; }),
    counts: counts(raw.counts, INBOX_CATEGORIES), sources: list(raw.sources, 10, (entry) => str(entry, 40)), limit: int(raw.limit),
  };
}

function mapTransfer(value: unknown): Transfer {
  const raw = obj(value);
  const who = (entry: unknown) => { const item = obj(entry); return { id: str(item.id, 64), displayName: optStr(item.displayName, 200) }; };
  return {
    id: str(raw.id, 64), purpose: str(raw.purpose, 30), amount: money(raw.amount) ?? fail(), reference: str(raw.reference, 64), valueDate: optStr(raw.valueDate, 10), method: str(raw.method, 30),
    state: oneOf(raw.state, ["submitted", "verified", "rejected"] as const), decisionCode: code(raw.decisionCode), flags: list(raw.flags, 10, (entry) => str(entry, 40)),
    submittedBy: who(raw.submittedBy), submittedAt: instant(raw.submittedAt), decidedBy: raw.decidedBy == null ? null : who(raw.decidedBy), decidedAt: optInstant(raw.decidedAt),
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
    proformaChanged: bool(raw.proformaChanged),
    advance: advance === null ? null : { percentage: int(advance.percentage), required: money(advance.required), verified: money(advance.verified), remaining: money(advance.remaining), accepted: bool(advance.accepted) },
    paymentReference: optStr(raw.paymentReference, 64),
    bank: bank === null ? null : { beneficiary: str(bank.beneficiary, 200), bank: str(bank.bank, 200), ibanMasked: str(bank.ibanMasked, 40), bic: optStr(bank.bic, 20) },
    financial: { state: str(financial.state, 20), attention: code(financial.attention) }, policy: oneOf(raw.policy, POLICIES), recordEnabled: bool(raw.recordEnabled),
    transfers: list(raw.transfers, 50, mapTransfer), settledElsewhere: bool(raw.settledElsewhere),
  };
}

export function mapFinanceTask(value: unknown): FinanceTask {
  const raw = obj(value);
  const allowed = obj(raw.allowed);
  return {
    request: mapInboxItem(raw.request), live: mapLive(raw.live),
    allowed: { start: bool(allowed.start), verify: bool(allowed.verify), notReceived: bool(allowed.notReceived), clarify: bool(allowed.clarify), secondCheck: bool(allowed.secondCheck), reconcile: bool(allowed.reconcile) },
    me: uuid(raw.me), singleVerification: bool(raw.singleVerification),
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
    id: uuid(raw.id), source: str(raw.source, 40), orderNumber: str(raw.orderNumber, 64), proforma: proforma(raw.proforma),
    state: oneOf(raw.state, REQUEST_STATES), outcome: optOneOf(raw.outcome, OUTCOMES), reasonCode: code(raw.reasonCode), policy: optOneOf(raw.policy, POLICIES),
    assignee: person(raw.assignee), recordedBy: person(raw.recordedBy), decidedBy: person(raw.decidedBy), requestedAt: instant(raw.requestedAt), updatedAt: instant(raw.updatedAt), decidedAt: optInstant(raw.decidedAt),
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

const oversightQuery = (filters: OversightFilters): string => {
  const entries = Object.entries(filters).filter((entry): entry is [string, string] => typeof entry[1] === "string" && entry[1] !== "");
  return entries.length > 0 ? `?${new URLSearchParams(entries).toString()}` : "";
};

type Http = { request<T>(path: string, init: RequestInit, map: (value: unknown) => T): Promise<T> };

/** The HTTP side of the requests (no-store: money and task data are never kept in the browser's cache). */
export function financeRequestsHttp(http: Http): FinanceRequestsApi {
  const order = (globalOrderId: string, tail = "") => `/confirmare/orders/${encodeURIComponent(globalOrderId)}/finance-request${tail}`;
  const task = (requestId: string, tail = "") => `/confirmare/finance/requests/${encodeURIComponent(requestId)}${tail}`;
  const post = (key: string, body: unknown): RequestInit => ({ method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(body) });
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
    reconcileTask: (requestId) => http.request(task(requestId, "/reconcile"), { method: "POST", cache: "no-store", body: "{}" }, mapDecisionAnswer),
    financeOversight: (filters, signal) => http.request(`/confirmare/finance/oversight${oversightQuery(filters)}`, { signal, cache: "no-store" }, mapOversight),
    financeOversightDetail: (requestId, signal) => http.request(`/confirmare/finance/oversight/${encodeURIComponent(requestId)}`, { signal, cache: "no-store" }, mapOversightDetail),
  };
}
