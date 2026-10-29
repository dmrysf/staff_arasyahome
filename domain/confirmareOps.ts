import type { Employee } from "./models";
import type { MessageKey } from "../i18n";
import { StaffServiceError } from "./models";
import { financeScopesOf } from "./confirmareFinance";

/**
 * The dual-store operating workflow in Staff (API 2.46.0, owner master decisions 2026-10-25): the call cycle and the
 * advance proforma timer of an order, the bank checks, the reactivation of a cancelled order, the FAN Courier AWB from
 * stage 12, the NETOPIA full card refund, the e-Factura follow-up and the operating health counts. Every rule is the
 * server's; what is shown here is presentation only, and every answer is read strictly (anything unexpected is refused).
 */

/**
 * R1/R2 (2026-10-26): why a closed proforma cycle may be started again (API ConfirmareCycles::RETRYABLE_CLOSED). Each is a
 * definite refusal; the `mail_*` ones mean the proforma exists and only its e-mail is sent again.
 */
export const RETRYABLE_CLOSED = ["smartbill_refused", "smartbill_precheck", "smartbill_rate_limited", "mail_refused", "mail_rate_limited",
  "proforma_bank_missing", "proforma_series_missing", "proforma_series_not_approved", "smartbill_company_not_approved", "billing_email_invalid"] as const;

export const CYCLE_STATES = ["issue_pending", "mail_pending", "running", "waiting_bank_check", "waiting_finance", "cancel_pending", "cancel_unknown", "order_cancel_pending", "paid", "cancelled", "closed"] as const;
export type CycleState = (typeof CYCLE_STATES)[number];
export const EFACTURA_STATES = ["open", "sent", "accepted", "rejected", "resolved"] as const;
export type EfacturaState = (typeof EFACTURA_STATES)[number];
export const REACTIVATION_REASONS = ["customer_returned", "customer_paid_late"] as const;
export type ReactivationReason = (typeof REACTIVATION_REASONS)[number];
/** Production stages from which the AWB may be made: 12 Control calitate, 13 Împachetare, 14 Livrare. */
export const AWB_STAGES = ["quality-control", "packing", "delivery"] as const;

export interface CallCycle { noAnswer: number; max: number; complete: boolean; since: string | null }
export interface ProformaCycle {
  cycle: number; state: CycleState; origin: "no_answer" | "operator"; sentAt: string | null;
  reminder1DueAt: string | null; reminder1At: string | null; reminder2DueAt: string | null; reminder2At: string | null; expiresAt: string | null;
  bankCheck: boolean; closedReason: string | null; lastError: string | null; closedAt: string | null;
}
export interface OrderCycle { calls: CallCycle | null; proforma: ProformaCycle | null; history: ProformaCycle[] }
export interface CycleAttentionItem extends ProformaCycle { orderNumber: string; globalOrderId: string }
export interface BankCheck { source: string; coversUntil: string; recordedAt: string; by: string | null }
export interface BankChecks { items: BankCheck[]; waiting: Record<string, number>; sources: string[]; canRecord: string[] }
export interface ShopAwb {
  state: "none" | "created" | "unknown"; number: string | null; createdAt: string | null; delivered: boolean; cod: string | null;
  codCheck: { state: string; awbCod: string | null; expected: string | null; corrected: boolean } | null; blockers: string[]; address: { kind: "task" | "done"; at: string | null }[]; pickup: "not_tracked";
}
export interface ShopCardRefund { available: boolean; transactions: number; requests: { state: "manual_required" | "completed" | "unknown"; at: string | null; amount: string | null; transactions: number; verified: number; completedAt: string | null; mailed: boolean }[] }
export interface ShopOperations { features: { reactivation?: string; awb?: string; cardRefunds?: string }; orderStatus: string | null; awb: ShopAwb; cardRefund: ShopCardRefund }
export interface Reactivation { sequence: number; kind: "ordinary" | "paid_exception"; reason: ReactivationReason; state: string; by: string; at: string; completedAt: string | null }
export interface OperationsState {
  available: boolean; shop: ShopOperations | null; stage: string; awbStage: boolean;
  allowed: { reactivate: boolean; awb: boolean; cardRefund: boolean; cardRefundRecord: boolean; cardRefundVerify: boolean };
  reactivations: Reactivation[];
}
export interface OperationOutcome { state: "applied" | "unknown" | "pending"; idempotencyKey: string; result: string | null; shop: ShopOperations | null; entryId: string | null }
export interface AwbQueueItem { globalOrderId: string; orderNumber: string; source: string; stage: string; lastAttempt: string | null }
export interface EfacturaTask { id: string; globalOrderId: string; orderNumber: string; source: string; issuer: string; issuedAt: string; dueAt: string; state: EfacturaState; version: number; recordedAt: string | null; overdue: boolean; next: EfacturaState[] }
export interface EfacturaQueue { items: EfacturaTask[]; overdue: number; managed: string[] }
export type HealthSource = Record<string, unknown>;
export interface HealthMetrics { generatedAt: string; sources: Record<string, HealthSource> }

/** What a recorded "no answer" did to the proforma cycle (API 2.46.0): started (the cycle state) or why not. */
export interface ContactCycle { started: boolean; state: string | null; reason: string | null }

export type OperationPath = "reactivate" | "awb" | "awb/address" | "awb/cod-corrected" | "card-refund" | "card-refund/record" | "card-refund/verify";

export interface ConfirmareOpsApi {
  cycleAttention(source: string | null, signal?: AbortSignal): Promise<CycleAttentionItem[]>;
  bankChecks(source: string | null, signal?: AbortSignal): Promise<BankChecks>;
  recordBankCheck(source: string, idempotencyKey: string): Promise<BankCheck>;
  advanceCycle(globalOrderId: string): Promise<ProformaCycle>;
  /** R1/R2 (2026-10-26): start the advance proforma again after a definite refusal; the server reads the shop's proforma first. */
  retryCycle(globalOrderId: string): Promise<ProformaCycle>;
  operations(globalOrderId: string, signal?: AbortSignal): Promise<OperationsState>;
  operate(globalOrderId: string, path: OperationPath, input: Record<string, unknown>, idempotencyKey: string): Promise<OperationOutcome>;
  operationsReconcile(globalOrderId: string, idempotencyKey: string): Promise<OperationOutcome>;
  awbLabel(globalOrderId: string): Promise<Blob>;
  awbQueue(source: string | null, signal?: AbortSignal): Promise<AwbQueueItem[]>;
  efactura(source: string | null, state: string | null, signal?: AbortSignal): Promise<EfacturaQueue>;
  recordEfactura(taskId: string, input: { outcome: EfacturaState; expectedVersion: number }): Promise<EfacturaTask>;
  health(source: string | null, signal?: AbortSignal): Promise<HealthMetrics>;
}

// ---- Access (presentation only) --------------------------------------------------------------------------------

const working = (employee: Employee) => employee.status === "active" && !employee.mustChangePassword && employee.applications.includes("staff");

/** The shop employees who make the AWB and follow the e-Factura: an invoice scope of a shop (issue or manage). */
export function canUseShopTasks(employee: Employee): boolean {
  const scopes = employee.invoiceScopes;
  return working(employee) && ((scopes?.issue.length ?? 0) > 0 || (scopes?.manage.length ?? 0) > 0);
}

/** The bank check: finance employees who record or approve. */
export function canRecordBankCheck(employee: Employee): boolean {
  const scopes = financeScopesOf(employee);
  return working(employee) && (scopes.record.length > 0 || scopes.approve.length > 0);
}

/** The operating health: Confirmare supervisors, invoice managers and finance approvers. */
export function canWatchHealth(employee: Employee): boolean {
  return working(employee) && ((employee.confirmareScopes?.supervise.length ?? 0) > 0 || (employee.invoiceScopes?.manage.length ?? 0) > 0 || financeScopesOf(employee).approve.length > 0);
}

/** Hours left until an instant (negative: passed), for the timer line. */
export function hoursUntil(iso: string | null, now: number = Date.now()): number | null {
  if (!iso) return null;
  const at = Date.parse(iso);
  return Number.isNaN(at) ? null : Math.round((at - now) / 3_600_000);
}

// ---- Strict answers ---------------------------------------------------------------------------------------------

const fail = (): never => { throw new StaffServiceError("SERVER_ERROR"); };
const obj = (value: unknown): Record<string, unknown> => (value !== null && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : fail());
const str = (value: unknown, max = 200): string => (typeof value === "string" && value.length <= max ? value : fail());
const optStr = (value: unknown, max = 200): string | null => (value == null ? null : str(value, max));
const code = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^[a-z_]{2,40}$/.test(value) ? value : fail()));
const int = (value: unknown): number => (typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : fail());
const bool = (value: unknown): boolean => (typeof value === "boolean" ? value : fail());
const instant = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/.test(value) ? value : fail()));
const money = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/.test(value) ? value : fail()));
const oneOf = <T extends string>(value: unknown, allowed: readonly T[]): T => (typeof value === "string" && (allowed as readonly string[]).includes(value) ? value as T : fail());
/** A PHP map that is empty arrives as `[]`: read it as an empty object. */
const record = (value: unknown): Record<string, unknown> => (Array.isArray(value) && value.length === 0 ? {} : obj(value));
const list = <T>(value: unknown, max: number, map: (entry: unknown) => T): T[] => (Array.isArray(value) && value.length <= max ? value.map(map) : fail());

export function mapProformaCycle(value: unknown): ProformaCycle {
  const raw = obj(value);
  return {
    cycle: int(raw.cycle), state: oneOf(raw.state, CYCLE_STATES), origin: oneOf(raw.origin, ["no_answer", "operator"] as const), sentAt: instant(raw.sentAt),
    reminder1DueAt: instant(raw.reminder1DueAt), reminder1At: instant(raw.reminder1At), reminder2DueAt: instant(raw.reminder2DueAt), reminder2At: instant(raw.reminder2At),
    expiresAt: instant(raw.expiresAt), bankCheck: bool(raw.bankCheck), closedReason: code(raw.closedReason), lastError: code(raw.lastError), closedAt: instant(raw.closedAt),
  };
}

/** The `cycle` of the Confirmare detail; an older API (before 2.46.0) has none. */
export function mapOrderCycle(value: unknown): OrderCycle | null {
  if (value == null) return null;
  const raw = obj(value);
  const calls = raw.calls == null ? null : obj(raw.calls);
  return {
    calls: calls === null ? null : { noAnswer: int(calls.noAnswer), max: int(calls.max), complete: bool(calls.complete), since: instant(calls.since) },
    proforma: raw.proforma == null ? null : mapProformaCycle(raw.proforma),
    history: raw.history == null ? [] : list(raw.history, 20, mapProformaCycle),
  };
}

export function mapCycleAttention(value: unknown): CycleAttentionItem[] {
  return list(obj(value).items, 300, (entry) => { const raw = obj(entry); return { ...mapProformaCycle(raw), orderNumber: str(raw.orderNumber, 64), globalOrderId: str(raw.globalOrderId, 200) }; });
}

export function mapBankCheck(value: unknown): BankCheck {
  const raw = obj(value);
  return { source: oneOf(raw.source, ["trendhome", "outletperdele"] as const), coversUntil: instant(raw.coversUntil) ?? fail(), recordedAt: instant(raw.recordedAt) ?? fail(), by: optStr(raw.by) };
}

export function mapBankChecks(value: unknown): BankChecks {
  const raw = obj(value);
  const waiting = record(raw.waiting ?? {});
  return {
    items: list(raw.items, 50, mapBankCheck),
    waiting: Object.fromEntries(Object.entries(waiting).map(([key, count]) => [oneOf(key, ["trendhome", "outletperdele"] as const), int(count)])),
    sources: list(raw.sources, 2, (source) => oneOf(source, ["trendhome", "outletperdele"] as const)),
    canRecord: list(raw.canRecord, 2, (source) => oneOf(source, ["trendhome", "outletperdele"] as const)),
  };
}

export function mapShopOperations(value: unknown): ShopOperations {
  const raw = obj(value);
  const awb = obj(raw.awb);
  const refund = obj(raw.cardRefund);
  const check = awb.codCheck == null ? null : obj(awb.codCheck);
  const features = record(raw.features ?? {});
  return {
    features: Object.fromEntries((["reactivation", "awb", "cardRefunds"] as const).filter((name) => features[name] != null).map((name) => [name, str(features[name], 10)])),
    orderStatus: optStr(raw.orderStatus, 30),
    awb: {
      state: oneOf(awb.state, ["none", "created", "unknown"] as const), number: optStr(awb.number, 20), createdAt: instant(awb.createdAt), delivered: bool(awb.delivered), cod: money(awb.cod),
      codCheck: check === null ? null : { state: str(check.state, 20), awbCod: money(check.awbCod), expected: money(check.expected), corrected: check.corrected == null ? false : bool(check.corrected) },
      blockers: list(awb.blockers, 30, (entry) => code(entry) ?? fail()), address: list(awb.address, 10, (entry) => { const a = obj(entry); return { kind: oneOf(a.kind, ["task", "done"] as const), at: instant(a.at) }; }),
      pickup: "not_tracked",
    },
    cardRefund: {
      available: bool(refund.available), transactions: int(refund.transactions),
      requests: list(refund.requests, 10, (entry) => { const r = obj(entry); return { state: oneOf(r.state, ["manual_required", "completed", "unknown"] as const), at: instant(r.at), amount: money(r.amount), transactions: int(r.transactions), verified: r.verified == null ? 0 : int(r.verified), completedAt: instant(r.completedAt), mailed: bool(r.mailed) }; }),
    },
  };
}

export function mapOperationsState(value: unknown): OperationsState {
  const raw = obj(value);
  const allowed = obj(raw.allowed);
  return {
    available: bool(raw.available), shop: raw.shop == null ? null : mapShopOperations(raw.shop), stage: str(raw.stage, 100), awbStage: bool(raw.awbStage),
    allowed: { reactivate: bool(allowed.reactivate), awb: bool(allowed.awb), cardRefund: bool(allowed.cardRefund), cardRefundRecord: bool(allowed.cardRefundRecord), cardRefundVerify: bool(allowed.cardRefundVerify) },
    reactivations: list(raw.reactivations, 20, (entry) => {
      const r = obj(entry);
      return { sequence: int(r.sequence), kind: oneOf(r.kind, ["ordinary", "paid_exception"] as const), reason: oneOf(r.reason, REACTIVATION_REASONS), state: str(r.state, 20), by: str(r.by), at: instant(r.at) ?? fail(), completedAt: instant(r.completedAt) };
    }),
  };
}

export function mapOperationOutcome(value: unknown): OperationOutcome {
  const raw = obj(value);
  return { state: oneOf(raw.state, ["applied", "unknown", "pending"] as const), idempotencyKey: str(raw.idempotencyKey, 100), result: code(raw.result), // A reconciliation still waiting names the shop's journal state as a string: no shop state to show.
    shop: raw.shop !== null && typeof raw.shop === "object" ? mapShopOperations(raw.shop) : null, entryId: optStr(raw.entryId, 40) };
}

export function mapAwbQueue(value: unknown): AwbQueueItem[] {
  return list(obj(value).items, 300, (entry) => { const raw = obj(entry); return { globalOrderId: str(raw.globalOrderId), orderNumber: str(raw.orderNumber, 64), source: str(raw.source, 40), stage: str(raw.stage, 100), lastAttempt: code(raw.lastAttempt) }; });
}

export function mapEfacturaTask(value: unknown): EfacturaTask {
  const raw = obj(value);
  return {
    id: str(raw.id, 36), globalOrderId: str(raw.globalOrderId), orderNumber: str(raw.orderNumber, 64), source: str(raw.source, 40), issuer: str(raw.issuer), issuedAt: instant(raw.issuedAt) ?? fail(),
    dueAt: instant(raw.dueAt) ?? fail(), state: oneOf(raw.state, EFACTURA_STATES), version: int(raw.version), recordedAt: instant(raw.recordedAt), overdue: bool(raw.overdue),
    next: list(raw.next, 5, (entry) => oneOf(entry, EFACTURA_STATES)),
  };
}

export function mapEfacturaQueue(value: unknown): EfacturaQueue {
  const raw = obj(value);
  return { items: list(raw.items, 300, mapEfacturaTask), overdue: int(raw.overdue), managed: list(raw.managed, 2, (source) => str(source, 40)) };
}

export function mapHealth(value: unknown): HealthMetrics {
  const raw = obj(value);
  const sources = obj(raw.sources);
  return { generatedAt: instant(raw.generatedAt) ?? fail(), sources: Object.fromEntries(Object.entries(sources).map(([key, metrics]) => [oneOf(key, ["trendhome", "outletperdele"] as const), obj(metrics)])) };
}

/** The `proformaCycle` of a recorded call; anything unexpected reads as "not started" (the order screen reloads anyway). */
export function mapContactCycle(value: unknown): ContactCycle | null {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return null;
  const raw = value as Record<string, unknown>;
  const text = (entry: unknown) => (typeof entry === "string" && /^[a-z_]{2,40}$/.test(entry) ? entry : null);
  return { started: typeof raw.cycle === "number", state: text(raw.state), reason: text(raw.reason) };
}

// ---- Refusals -----------------------------------------------------------------------------------------------------

/** The blocker codes with their own message (Arasya's and the shop's); any other code is shown by its generic text. */
export const OPS_BLOCKERS = [
  "stage_not_ready", "confirmare_not_approved", "order_cancellation", "operation_open", "production_started", "order_not_cancelled", "payment_required", "finance_scope_required",
  "finance_action_open", "order_unavailable", "reactivation_disabled", "awb_disabled", "card_refund_disabled", "order_refunded", "order_closed", "invoice_missing", "payment_verifying",
  "finance_attention", "address_incomplete", "phone_missing", "shipping_mismatch", "awb_exists", "awb_unavailable", "cod_changed", "cod_mismatch", "cod_not_mismatched", "awb_refused", "awb_missing", "label_unavailable",
  "address_task_missing", "address_task_open", "not_card_payment", "card_payment_unverified", "nothing_to_refund", "refund_request_missing", "refund_pending", "finance_open",
  "amount_exceeds_received", "duplicate_reference", "entry_not_found", "entry_not_pending", "same_employee", "refund_record_failed", "not_received", "order_busy", "finance_unavailable",
] as const;

export const blockerKey = (code: string): MessageKey => ((OPS_BLOCKERS as readonly string[]).includes(code) ? `cf.ops.blocker.${code}` : "cf.ops.blocker.other") as MessageKey;

const OPS_ERRORS = [
  "CONFIRMARE_OPERATION_DENIED", "CONFIRMARE_OPERATION_DISABLED", "CONFIRMARE_OPERATION_NOT_FOUND", "CONFIRMARE_AWB_LABEL_UNAVAILABLE", "CONFIRMARE_AWB_COD_MISMATCH", "CONFIRMARE_CYCLE_NONE", "CONFIRMARE_CYCLE_BUSY", "CONFIRMARE_CYCLE_RETRY_REFUSED",
  "CONFIRMARE_CYCLE_SCHEMA_MISSING", "CONFIRMARE_DENIED", "CONFIRMARE_FINANCE_DENIED", "CONFIRMARE_HEALTH_DENIED", "EFACTURA_TASK_CHANGED", "EFACTURA_TRANSITION_REFUSED", "EFACTURA_TASK_NOT_FOUND",
  "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND", "VALIDATION_FAILED",
] as const;

/**
 * The message of a refused operation: a blocker (NOT_ALLOWED from Arasya, REFUSED from the shop) names its reason;
 * the other known codes have their own text; null lets the caller show the generic error.
 */
export function opsErrorKey(backendCode: string | undefined, reason: unknown): MessageKey | null {
  if ((backendCode === "CONFIRMARE_OPERATION_NOT_ALLOWED" || backendCode === "CONFIRMARE_OPERATION_REFUSED") && typeof reason === "string") return blockerKey(reason);
  return backendCode && (OPS_ERRORS as readonly string[]).includes(backendCode) ? `cf.ops.error.${backendCode}` as MessageKey : null;
}
