import { t, type MessageKey } from "../i18n";

/**
 * SmartBill invoicing of Confirmare orders (API 2.43.0). The shop's own SmartBill plugin issues, sends and re-issues the
 * documents with the shop's own settings (document type, series, VAT, currency, e-mail); Staff shows the shop's state,
 * read through the Operations API, and offers only what the server allows this employee now. Nothing is computed,
 * cached or stored in the browser (component memory only). Every action is confirmed once, sent with an Idempotency-Key,
 * and never shown as done before the shop confirmed it; an unknown outcome is checked, never sent again.
 */
export const INVOICE_ACTIONS = ["issue", "send_mail", "reissue", "resolve"] as const;
export type InvoiceAction = (typeof INVOICE_ACTIONS)[number];
export type InvoiceActionKey = "issue" | "sendMail" | "reissue" | "resolve";
export const INVOICE_REASONS = ["order_changed", "customer_data", "wrong_document", "other"] as const;
export type InvoiceReason = (typeof INVOICE_REASONS)[number];
export const INVOICE_ROLES = ["documents", "manager", "finance", "viewer"] as const;
export type InvoiceRole = (typeof INVOICE_ROLES)[number];

/** Detail capabilities: whether invoicing runs for the shop and what the signed-in employee may do (the server checks again). */
export type InvoiceCapabilities = { configured: boolean; read: boolean; issue: boolean; manage: boolean };

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
};
export type InvoiceTimelineItem = {
  employeeId: string; role: string; action: InvoiceAction; reasonCode: string | null; idempotencyKey: string; state: "pending" | "applied" | "rejected" | "unknown";
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
};
export type InvoiceRead = ({ available: true; role: InvoiceRole } & InvoiceView) | { available: false; reason: string };
export type InvoiceOutcome =
  | ({ state: "applied"; idempotencyKey: string; result: string | null } & Partial<InvoiceView>)
  | { state: "pending" | "unknown"; idempotencyKey: string; reachable?: boolean };

export type IssueInput = { expectedRevision: string };
export type MailInput = { expectedRevision: string; confirmResend?: boolean };
export type ReissueInput = { expectedRevision: string; reasonCode: InvoiceReason; reasonNote?: string; previousCancelled?: boolean };
export type ResolveInput = { expectedRevision: string; target: "document" | "mail"; resolution: "not_issued" | "issued" | "sent" | "not_sent"; reasonNote?: string };

export interface ConfirmareInvoiceApi {
  invoiceRead(globalOrderId: string, signal?: AbortSignal): Promise<InvoiceRead>;
  invoiceAct(globalOrderId: string, action: InvoiceAction, input: IssueInput | MailInput | ReissueInput | ResolveInput, idempotencyKey: string): Promise<InvoiceOutcome>;
  invoiceReconcile(globalOrderId: string, idempotencyKey: string): Promise<InvoiceOutcome>;
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
  return ["issue", "reissue", "superseded", "send_mail", "auto_mail", "resolve_document", "resolve_mail"].includes(kind) ? t(`inv.history.${kind}` as MessageKey) : kind;
}

/** The invoice error a response carried (backend code), or null. */
export function invoiceErrorKey(backendCode: string | undefined): MessageKey | null {
  const known = ["CONFIRMARE_INVOICE_DENIED", "CONFIRMARE_INVOICE_SAME_EMPLOYEE", "CONFIRMARE_INVOICE_DISABLED", "CONFIRMARE_INVOICE_INVALID", "CONFIRMARE_INVOICE_ACTION_NOT_FOUND",
    "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND", "RATE_LIMITED"];
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
