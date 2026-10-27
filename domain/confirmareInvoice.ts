import { t, type MessageKey } from "../i18n";

/**
 * SmartBill invoicing of Confirmare orders (API 2.43.0). The shop's own SmartBill plugin issues, sends and re-issues the
 * documents with the shop's own settings (document type, series, VAT, currency, e-mail); Staff shows the shop's state,
 * read through the Operations API, and offers only what the server allows this employee now. Nothing is computed,
 * cached or stored in the browser (component memory only). Every action is confirmed once, sent with an Idempotency-Key,
 * and never shown as done before the shop confirmed it; an unknown outcome is checked, never sent again.
 *
 * API 2.44.0 (owner workflow): the Confirmare operator issues and sends the ADVANCE PROFORMA of a cash-on-delivery order
 * and, once the money is proven, routes the order to one shop employee; that employee scans the order's production QR and
 * issues the final SmartBill invoice. The operator never gets a final invoice action.
 *
 * API 2.45.0 (owner master update): the operator records the customer's telephone acceptance of the order's total
 * ("Confirmare telefonică a comenzii"), may accept a smaller VERIFIED advance ("Acceptă avansul primit") and cancels an
 * unpaid proforma IN SMARTBILL ("Anulează proforma"). The shop's one money calculation (total, advance asked and
 * received, difference, what is owed), the invoicing deadline and the AWB's collection are shown as the shop states them.
 */
export const INVOICE_ACTIONS = ["issue", "send_mail", "reissue", "resolve"] as const;
export type InvoiceAction = (typeof INVOICE_ACTIONS)[number];
export type InvoiceActionKey = "issue" | "sendMail" | "reissue" | "resolve";
export const INVOICE_REASONS = ["order_changed", "customer_data", "wrong_document", "other"] as const;
export type InvoiceReason = (typeof INVOICE_REASONS)[number];
export const INVOICE_ROLES = ["documents", "manager", "finance", "viewer", "operator"] as const;
export type InvoiceRole = (typeof INVOICE_ROLES)[number];

/** Detail capabilities: whether invoicing runs for the shop and what the signed-in employee may do (the server checks again). */
export type InvoiceCapabilities = { configured: boolean; read: boolean; issue: boolean; manage: boolean; proforma: boolean; route: boolean; payment?: boolean };

/**
 * API 2.47.0 (YD SOFT 2.21.0, owner remediation 2026-10-26): what SmartBill knows about the payment of the final invoice,
 * next to the money Arasya verified. Read-only everywhere: Arasya never records a payment in SmartBill. `association_pending`
 * means the finance employee still has to associate the verified money with this invoice in SmartBill Cloud. `settled` only
 * when Arasya verified the whole total; SmartBill fully paid with money still owed in Arasya is `paid_not_verified`.
 * `checkedAt` is the time of the real SmartBill read; `cached` marks the shop's 30 s copy of that read.
 */
export const PAYMENT_STATES = ["no_invoice", "nothing_to_associate", "matched", "settled", "association_pending", "paid_not_verified", "paid_beyond_verified", "invoice_total_differs", "smartbill_inconsistent", "unknown"] as const;
export type PaymentState = (typeof PAYMENT_STATES)[number];
export type InvoicePayment = {
  state: PaymentState; severity: "ok" | "pending" | "attention" | "unknown"; code: string | null; currency: string; checkedAt: string; cached: boolean;
  invoice: { series: string | null; number: string | null } | null;
  smartbill: { total: string; paid: string; unpaid: string } | null;
  arasya: { total: string; verified: string; remaining: string; excess: string } | null;
  retryAfter: number | null;
};
export type InvoicePaymentRead = { available: true; role: InvoiceRole; payment: InvoicePayment } | { available: false; reason: string };

export const PROFORMA_ACTIONS = ["proforma_issue", "proforma_send_mail", "proforma_check", "proforma_resolve", "proforma_cancel", "total_accept", "advance_accept"] as const;
export type ProformaAction = (typeof PROFORMA_ACTIONS)[number];
export const PROFORMA_KEYS = ["proformaIssue", "proformaSendMail", "proformaCheck", "proformaResolve", "proformaCancel", "totalAccept", "advanceAccept"] as const;
export type ProformaActionKey = (typeof PROFORMA_KEYS)[number];
export const proformaKey = (action: ProformaAction): ProformaActionKey => ({
  proforma_issue: "proformaIssue", proforma_send_mail: "proformaSendMail", proforma_check: "proformaCheck", proforma_resolve: "proformaResolve",
  proforma_cancel: "proformaCancel", total_accept: "totalAccept", advance_accept: "advanceAccept",
} as const)[action];
/** The Staff route segment of each proforma action (`/proforma/{segment}`). */
export const PROFORMA_ROUTE: Record<ProformaAction, string> = {
  proforma_issue: "issue", proforma_send_mail: "send-mail", proforma_check: "check", proforma_resolve: "resolve",
  proforma_cancel: "cancel", total_accept: "accept-total", advance_accept: "accept-advance",
};
export const CANCEL_REASONS = ["order_changed", "customer_request", "wrong_details", "duplicate", "other"] as const;
export type CancelReason = (typeof CANCEL_REASONS)[number];
/** The shop's one money calculation (YD SOFT 2.19.0 MoneyPosition): T, A, P, I, N, K = MAX(0, T − N), F, G. */
export type MoneyPosition = {
  currency: string; percentage: number | null; known: boolean;
  total: string | null; advanceRequired: string | null; received: string | null; refunded: string | null; net: string | null; remaining: string | null; excess: string | null; advanceGap: string | null;
};
export type InvoiceDeadline = { state: "none" | "open" | "invoiced"; firstCollection: string | null; deadline: string | null; daysLeft: number | null; level: "none" | "notice" | "warning" | "critical" | "overdue" | null };
export type CodCheck = { state: "none" | "match" | "mismatch" | "delivered" | "unknown"; awb: string | null; awbCod: string | null; expected: string | null; status: string | null };
export type TotalAcceptance = { state: "accepted" | "required"; at: string | null; by: string | null; previousTotal: string | null; total: string | null; changes: { kind: "added" | "removed" | "changed" | "shipping"; name: string }[]; note: string | null;
  /** Owner business rule 2026-10-25: verified money is above zero but below the new standard advance, so accepting this
   *  total needs the operator's explicit advance decision; null when accepted or unknown. */
  advanceDecisionRequired: boolean | null };
/** The order's shipping against WooCommerce's current configuration (YD SOFT ShippingCheck, owner rule 2026-10-25). */
export type ShippingCheck = { state: "none" | "match" | "mismatch" | "unverifiable"; code: string | null; current: string | null; expected: string | null };
export const ADVANCE_DECISIONS = ["accept_received", "request_difference"] as const;
export type AdvanceDecision = (typeof ADVANCE_DECISIONS)[number];
/** The advance proforma as the shop reports it (YD SOFT 2.18.0); amounts only for the roles that work with them. */
export type ProformaSummary = {
  /** `state` comes from the money evidence only (a transfer verified by two finance employees), never from the proforma. */
  advance: { required: string | null; received: string | null; outstanding: string | null; percentage: number | null; cod: boolean | null; state: "waiting" | "partial" | "paid" | null; verifying: boolean; accepted: boolean };
  /** The shop's bank account printed on the proforma and the order's payment reference (owner decision: bank transfer only). */
  transfer: { iban: string; bank: string; beneficiary: string; reference: string | null; bic: string | null } | null;
  state: "none" | "in_flight" | "issued" | "unknown";
  document: { series: string | null; number: string | null; amount: string | null; currency: string | null; reference: string | null; issuedAt: string | null; issuedBy: string | null; outdated: boolean; link: string | null } | null;
  mail: { state: "none" | "smartbill" | "in_flight" | "sent" | "unknown" | "failed"; at: string | null; by: string | null; count: number };
  invoiced: { invoiced: boolean | null; at: string | null } | null;
  error: string | null;
  history: { kind: string; at: string | null; by: string | null; outcome: string; series: string | null; number: string | null; reason: string | null }[];
  blockers: Record<ProformaActionKey, string[]>;
  /** API 2.45.0 (null or empty from a YD SOFT 2.18.0 shop). */
  money: MoneyPosition | null;
  totalAcceptance: TotalAcceptance | null;
  /** kind `kept`: the money paid before a revision, explicitly accepted with the new total; `accepted`: a later acceptance. */
  advanceAcceptance: { at: string | null; by: string | null; required: string | null; received: string | null; reason: string | null; low: boolean; kind: "accepted" | "kept" } | null;
  lowAdvance: boolean;
  cancel: { state: "in_flight" | "unknown"; at: string | null; by: string | null; reason: string | null } | null;
  cancelled: { series: string | null; number: string | null; amount: string | null; currency: string | null; cancelledAt: string | null; cancelledBy: string | null; reason: string | null }[];
  superseded: { series: string | null; number: string | null; amount: string | null; supersededAt: string | null }[];
  deadline: InvoiceDeadline | null;
  cod: CodCheck | null;
  shipping: ShippingCheck | null;
};
export type ShopAssignment = { employeeId: string; assignedBy: string; role: "operator" | "manager"; assignedAt: string };
export type ShopCandidate = { id: string; name: string };
export type ShopQueueItem = { globalOrderId: string; source: string; orderNumber: string; assignedAt: string; assignedBy: string; handoff: string; invoiced: boolean };

export type InvoiceDocument = {
  exists: boolean; type: "invoice" | "proforma" | null; series: string | null; number: string | null; status: "final" | "draft" | null; conflict: boolean;
  through: "arasya" | "shop" | null; issuedAt: string | null; issuedBy: string | null; total: string | null; currency: string | null; outdated: boolean | null; link: string | null;
};
export type InvoiceHistoryItem = { kind: string; at: string | null; by: string | null; outcome: string; reason: { code: string; note: string } | null; series: string | null; number: string | null };
export type InvoiceSummary = {
  readAt: string; revision: string; mode: "observe" | "enforce";
  smartbill: { available: boolean; reason: string | null; documentType: "invoice" | "proforma"; autoEmail: boolean; cashing: boolean; draft: boolean; mailSupported: boolean; autoIssue: boolean };
  order: { id: string; number: string; status: string; total: string; currency: string; billingEmail: boolean };
  financial: { state: string; basis: string | null; attention: string | null; pending: boolean };
  document: InvoiceDocument;
  operation: { state: "none" | "in_flight" | "issued" | "unknown"; kind: string | null; startedAt: string | null; by: string | null; error: string | null };
  mail: { state: "none" | "smartbill" | "in_flight" | "sent" | "unknown" | "failed"; at: string | null; by: string | null; count: number };
  history: InvoiceHistoryItem[];
  blockers: Record<InvoiceActionKey, string[]>;
  /** Null for a shop that does not report the proforma yet (YD SOFT before 2.18.0). */
  proforma: ProformaSummary | null;
  /** API 2.45.0: ANAF e-Factura is never tracked by the shop (null from an older shop). */
  eFactura: { state: "not_tracked" } | null;
};
export type InvoiceTimelineItem = {
  employeeId: string; role: string; action: InvoiceAction | ProformaAction; reasonCode: string | null; idempotencyKey: string; state: "pending" | "applied" | "rejected" | "unknown";
  outcome: string | null; result: string | null; requestedAt: string; completedAt: string | null;
};
export type InvoiceView = {
  summary: InvoiceSummary;
  handoff: { state: string; blockers: string[] };
  /** Arasya's own refusals per action (the shop's are in summary.blockers). */
  blockers: Record<InvoiceActionKey, string[]>;
  allowedActions: InvoiceActionKey[];
  actionOpen: boolean;
  timeline: InvoiceTimelineItem[];
  employees: Record<string, string>;
  me: string;
  /** API 2.44.0: what this employee may do with the proforma (null: the shop reports no proforma). */
  proforma: { blockers: Record<ProformaActionKey, string[]>; allowedActions: ProformaActionKey[]; role: "operator" | "manager" | "documents" | null } | null;
  assignment: ShopAssignment | null;
  /** Only for the operator: whether the order can be routed to the shop now. */
  routing: { allowed: boolean; blockers: string[] } | null;
  qr: { verified: boolean; validSeconds: number };
};
export type InvoiceRead = ({ available: true; role: InvoiceRole } & InvoiceView) | { available: false; reason: string };
export type InvoiceOutcome =
  | ({ state: "applied"; idempotencyKey: string; result: string | null } & Partial<InvoiceView>)
  | { state: "pending" | "unknown"; idempotencyKey: string; reachable?: boolean };

export type IssueInput = { expectedRevision: string };
export type MailInput = { expectedRevision: string; confirmResend?: boolean };
export type ReissueInput = { expectedRevision: string; reasonCode: InvoiceReason; reasonNote?: string; previousCancelled?: boolean };
export type ResolveInput = { expectedRevision: string; target: "document" | "mail"; resolution: "not_issued" | "issued" | "sent" | "not_sent"; reasonNote?: string };
export type ProformaIssueInput = { expectedRevision: string; replaceOutdated?: boolean };
export type ProformaResolveInput = { expectedRevision: string; target: "document" | "mail" | "cancel"; resolution: "not_issued" | "issued" | "sent" | "not_sent" | "cancelled" | "not_cancelled"; series?: string; number?: string; reasonNote?: string };
export type ProformaCancelInput = { expectedRevision: string; reasonCode: CancelReason; reasonNote?: string; confirmNoPayment: boolean };
export type TotalAcceptInput = { expectedRevision: string; declaration: boolean; note?: string; advanceDecision?: AdvanceDecision; confirmLow?: boolean };
export type AdvanceAcceptInput = { expectedRevision: string; reasonNote?: string; confirmLow?: boolean };

export interface ConfirmareInvoiceApi {
  invoiceRead(globalOrderId: string, signal?: AbortSignal): Promise<InvoiceRead>;
  invoiceAct(globalOrderId: string, action: InvoiceAction, input: IssueInput | MailInput | ReissueInput | ResolveInput, idempotencyKey: string): Promise<InvoiceOutcome>;
  invoiceReconcile(globalOrderId: string, idempotencyKey: string): Promise<InvoiceOutcome>;
  proformaAct(globalOrderId: string, action: ProformaAction, input: ProformaIssueInput | MailInput | IssueInput | ProformaResolveInput | ProformaCancelInput | TotalAcceptInput | AdvanceAcceptInput, idempotencyKey: string): Promise<InvoiceOutcome>;
  shopCandidates(globalOrderId: string, signal?: AbortSignal): Promise<ShopCandidate[]>;
  assignShop(globalOrderId: string, input: { employeeId: string; expectedAssigneeId: string | null }, idempotencyKey: string): Promise<ShopAssignment | null>;
  verifyInvoiceQr(globalOrderId: string, qrToken: string): Promise<{ verified: true; validSeconds: number }>;
  shopQueue(signal?: AbortSignal): Promise<ShopQueueItem[]>;
  /** API 2.47.0: the read-only SmartBill payment reconciliation (absent in an older or simulated service). */
  invoicePayment?(globalOrderId: string, signal?: AbortSignal): Promise<InvoicePaymentRead>;
}

/** Every refusal of a proforma action: Arasya's first, then the shop's (codes). */
export function proformaBlockersOf(view: InvoiceView, key: ProformaActionKey): string[] {
  return [...new Set([...(view.proforma?.blockers[key] ?? []), ...(view.summary.proforma?.blockers[key] ?? [])])];
}

export const actionKey = (action: InvoiceAction): InvoiceActionKey => (action === "send_mail" ? "sendMail" : action);

/** Every refusal of an action: Arasya's first, then the shop's (codes). */
export function blockersOf(view: InvoiceView, key: InvoiceActionKey): string[] {
  return [...new Set([...view.blockers[key], ...view.summary.blockers[key]])];
}

// ---- Words -----------------------------------------------------------------------------------------------------

const BLOCKERS = [
  "invoice_observe", "not_allowed_for_role", "same_employee", "invoice_action_open", "order_cancellation", "order_unavailable", "confirmare_not_approved", "handoff_not_ready",
  "finance_action_open", "smartbill_missing", "smartbill_version_unsupported", "smartbill_not_connected", "smartbill_series_missing", "currency_mismatch", "operation_in_progress",
  "operation_unknown", "document_conflict", "order_closed", "document_missing", "document_exists", "mail_in_progress", "php_incompatible", "billing_email_invalid", "finance_unavailable",
  "payment_not_cleared", "zero_total_unsupported", "finance_attention", "finance_pending", "cashing_mismatch", "nothing_to_resolve", "resend_confirmation_required", "reason_required",
  "reissue_attestation_required", "smartbill_refused", "smartbill_precheck", "mail_refused", "revision_changed", "order_busy", "not_received", "invoice_disabled",
  // API 2.44.0 / YD SOFT 2.18.0
  "not_assigned", "assigned_to_other", "qr_not_verified", "advance_not_verified", "final_document_not_invoice", "proforma_operation_unknown", "proforma_invoiced",
  "proforma_status_unknown", "proforma_series_missing", "advance_not_required", "advance_already_collected", "proforma_exists", "proforma_missing", "proforma_outdated",
  "replace_confirmation_required",
  // API 2.45.0 / YD SOFT 2.19.0
  "total_not_accepted", "total_already_accepted", "declaration_required", "commercial_record_damaged", "financial_unknown", "advance_not_received",
  "advance_already_accepted", "payment_verifying", "low_advance_confirmation_required", "cancel_unknown", "advance_payment_received", "invoice_exists",
  "no_payment_confirmation_required", "proforma_check_unknown", "cancel_refused", "smartbill_company_not_approved", "invoice_series_not_approved", "not_supported",
  "proforma_bank_missing", "proforma_series_not_approved", "payment_reference_invalid",
  // Owner business rules 2026-10-25
  "advance_decision_required", "advance_decision_not_applicable", "shipping_mismatch",
] as const;
/** A blocker or refusal code in the employee's words (an unknown code reads as a generic refusal, never as the code). */
export function invoiceBlockerLabel(code: string | undefined): string {
  return t(`inv.blocker.${code && (BLOCKERS as readonly string[]).includes(code) ? code : "other"}` as MessageKey);
}

export const documentTypeLabel = (type: "invoice" | "proforma" | null | undefined): string => t(type === "proforma" ? "inv.type.proforma" : "inv.type.invoice");
export const mailStateLabel = (state: InvoiceSummary["mail"]["state"]): string => t(`inv.mail.${state}` as MessageKey);
export const operationStateLabel = (state: InvoiceSummary["operation"]["state"]): string => t(`inv.operation.${state}` as MessageKey);
export const reasonLabel = (reason: string): string => ((INVOICE_REASONS as readonly string[]).includes(reason) ? t(`inv.reason.${reason}` as MessageKey) : reason);
export function historyLabel(kind: string): string {
  return ["issue", "reissue", "superseded", "send_mail", "auto_mail", "resolve_document", "resolve_mail", "proforma_issue", "proforma_mail", "check", "proforma_resolve_document", "proforma_resolve_mail",
    "proforma_cancel", "proforma_resolve_cancel"].includes(kind) ? t(`inv.history.${kind}` as MessageKey) : kind;
}
export const cancelReasonLabel = (reason: string | null | undefined): string => (reason && (CANCEL_REASONS as readonly string[]).includes(reason) ? t(`inv.pf.cancelReason.${reason}` as MessageKey) : t("inv.pf.cancelReason.other"));

/**
 * The status of the advance proforma the operator reads first (Romanian UX, master update): the proforma's own state
 * from the shop, never a payment. Order: a cancellation being checked, cancelled, invoiced by SmartBill, unknown,
 * outdated, sent, issued, none.
 */
export function proformaStatusKey(p: ProformaSummary): MessageKey {
  if (p.cancel?.state === "unknown" || p.cancel?.state === "in_flight") return "inv.pf.status.cancelChecking";
  if (p.document === null) return p.cancelled.length > 0 ? "inv.pf.status.cancelled" : (p.state === "unknown" ? "inv.pf.status.unknown" : "inv.pf.status.none");
  if (p.invoiced?.invoiced === true) return "inv.pf.status.invoiced";
  if (p.state === "unknown") return "inv.pf.status.unknown";
  if (p.document.outdated) return "inv.pf.status.outdated";
  return p.mail.state === "sent" ? "inv.pf.status.sent" : "inv.pf.status.issued";
}

/** The advance in the operator's words: waiting, verifying, partial, partial accepted, confirmed. */
export function advanceStatusKey(p: ProformaSummary): MessageKey | null {
  if (p.advance.cod !== true) return null;
  if (p.advance.accepted) return "inv.pf.adv.acceptedPartial";
  if (p.advance.state === "paid") return "inv.pf.adv.confirmed";
  if (p.advance.verifying) return "inv.pf.adv.verifying";
  return p.advance.state === "partial" ? "inv.pf.adv.partial" : "inv.pf.adv.waiting";
}

/** The invoice error a response carried (backend code), or null. */
export function invoiceErrorKey(backendCode: string | undefined): MessageKey | null {
  const known = ["CONFIRMARE_INVOICE_DENIED", "CONFIRMARE_INVOICE_SAME_EMPLOYEE", "CONFIRMARE_INVOICE_DISABLED", "CONFIRMARE_INVOICE_INVALID", "CONFIRMARE_INVOICE_ACTION_NOT_FOUND",
    "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND", "RATE_LIMITED",
    "CONFIRMARE_ASSIGNMENT_CHANGED", "CONFIRMARE_ASSIGNMENT_UNCHANGED", "CONFIRMARE_SHOP_EMPLOYEE_NOT_ELIGIBLE", "CONFIRMARE_ASSIGNMENT_INVALID", "INVOICE_QR_INVALID", "INVOICE_QR_REFUSED"];
  return backendCode && known.includes(backendCode) ? `inv.error.${backendCode}` as MessageKey : null;
}

// ---- One state on every screen ---------------------------------------------------------------------------------

const CHANNEL = "arasya-invoice";
type Listener = (globalOrderId: string) => void;
const listeners = new Set<Listener>();
let channel: BroadcastChannel | null = null;
function openChannel(): BroadcastChannel | null {
  if (channel !== null || typeof BroadcastChannel === "undefined") return channel;
  try {
    channel = new BroadcastChannel(CHANNEL);
    channel.onmessage = (event: MessageEvent) => {
      const id = (event.data as { globalOrderId?: unknown } | null)?.globalOrderId;
      if (typeof id === "string") for (const listener of listeners) listener(id);
    };
  } catch { channel = null; }
  return channel;
}

/**
 * After an action (whatever its outcome), every invoice panel of the order reads the shop again: in this tab and in
 * the employee's other open tabs (the order detail and the production ticket screen). Only the order id travels.
 */
export function announceInvoiceChange(globalOrderId: string): void {
  for (const listener of listeners) listener(globalOrderId);
  try { openChannel()?.postMessage({ globalOrderId }); } catch { /* another tab simply reads on its next refresh */ }
}

export function onInvoiceChange(listener: Listener): () => void {
  listeners.add(listener);
  openChannel();
  return () => { listeners.delete(listener); };
}
