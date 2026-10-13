import { StaffServiceError } from "./models";
import { liveLabels, t, type MessageKey } from "../i18n";

/** Central production document state of an order (the server is the only authority). */
export type DocumentStatus = "none" | "active" | "stale" | "revoked";
export type RevisionRequestStatus = "pending" | "approved" | "rejected" | "superseded" | "generated" | "cancelled";

/** Compact state on every Staff order view: no customer data. */
export type OrderDocumentSummary = {
  status: DocumentStatus;
  version: number;
  revisionNumber: number | null;
  request: { id: string; status: RevisionRequestStatus; targetRevision: number; version: number } | null;
};

export type DocumentChange = { field: string; line: number | null; before: string | null; after: string | null };

export type RevisionRequest = {
  id: string;
  status: RevisionRequestStatus;
  version: number;
  targetRevision: number;
  requestedBy: string;
  requestedAt: string;
  comment: string | null;
  changes: DocumentChange[];
  decidedBy: string | null;
  decisionComment: string | null;
  decidedAt: string | null;
  resolutionNote: string | null;
};

export type DocumentRevision = {
  number: number;
  status: "active" | "superseded" | "revoked";
  generatedAt: string;
  generatedBy: string;
  prints: number;
  approvedBy: string | null;
  revokeReason: string | null;
};

/** The requester's document view of one order. */
export type OrderDocument = {
  order: { id: string; number: string; completed: boolean; unavailable: boolean };
  status: DocumentStatus;
  version: number;
  activeRevision: DocumentRevision | null;
  latestRevisionNumber: number | null;
  request: RevisionRequest | null;
  revisions: DocumentRevision[];
};

export type DocumentAttention = {
  orderId: string;
  orderNumber: string;
  documentStatus: DocumentStatus;
  revisionNumber: number | null;
  request: { id: string; status: RevisionRequestStatus; targetRevision: number; requestedBy: string } | null;
};

export type DocumentLookupItem = { orderId: string; orderNumber: string; source: string; documentStatus: DocumentStatus; revisionNumber: number | null };

export interface DocumentApi {
  lookup(number: string, signal?: AbortSignal): Promise<DocumentLookupItem[]>;
  view(orderId: string, signal?: AbortSignal): Promise<OrderDocument>;
  attention(signal?: AbortSignal): Promise<DocumentAttention[]>;
  generate(orderId: string, input: { expectedDocumentVersion: number; requestId?: string }, key: string): Promise<OrderDocument>;
  requestRevision(orderId: string, input: { expectedDocumentVersion: number; comment: string | null }, key: string): Promise<OrderDocument>;
  /** Records a print (or reprint) of the active revision and returns the PDF. */
  print(orderId: string, input: { revisionNumber: number; reason: string | null }, key: string): Promise<Blob>;
  /** Screen preview of one revision: no QR code, nothing is recorded, not usable in production. */
  preview(orderId: string, revisionNumber: number): Promise<Blob>;
}

export const DOCUMENT_PERMISSIONS = ["production.documents.generate", "production.documents.reprint", "production.documents.request_revision"] as const;

export const revisionStatusLabels: Readonly<Record<DocumentRevision["status"], string>> = liveLabels("doc.revisionStatus.", ["active", "superseded", "revoked"]);

export function canUseDocuments(permissions: readonly string[]): boolean {
  return DOCUMENT_PERMISSIONS.some((permission) => permissions.includes(permission));
}

/** The one next step the requester can take, in the order of the Romanian workflow. */
export type DocumentStep =
  | { kind: "generate-first" }
  | { kind: "print"; revision: number }
  | { kind: "request"; revision: number }
  | { kind: "waiting"; target: number }
  | { kind: "generate-revision"; target: number; requestId: string }
  | { kind: "completed" }
  | { kind: "none" };

export function documentStep(document: OrderDocument, permissions: readonly string[]): DocumentStep {
  const can = (permission: string) => permissions.includes(permission);
  if (document.order.completed) return { kind: "completed" };
  const open = document.request && (document.request.status === "pending" || document.request.status === "approved") ? document.request : null;
  if (document.status === "none") return can("production.documents.generate") ? { kind: "generate-first" } : { kind: "none" };
  if (document.status === "active" && document.activeRevision) {
    return can("production.documents.generate") || can("production.documents.reprint") ? { kind: "print", revision: document.activeRevision.number } : { kind: "none" };
  }
  if (open?.status === "approved") return can("production.documents.generate") ? { kind: "generate-revision", target: open.targetRevision, requestId: open.id } : { kind: "waiting", target: open.targetRevision };
  if (open?.status === "pending") return { kind: "waiting", target: open.targetRevision };
  return can("production.documents.request_revision") ? { kind: "request", revision: (document.latestRevisionNumber ?? 0) + 1 } : { kind: "none" };
}

export const documentStatusLabels: Readonly<Record<DocumentStatus, string>> = liveLabels("doc.status.", ["none", "active", "stale", "revoked"]);

export const changeFieldLabels: Readonly<Record<string, string>> = liveLabels("doc.change.", [
  "orderNumber", "customer.name", "customer.company", "customer.contact", "customer.address", "customer.phone", "notes", "line.added",
  "line.removed", "line.code", "line.name", "line.kind", "line.variant", "line.color", "line.width", "line.height", "line.meters",
  "line.quantity", "line.notes", "line.productionNotes", "line.options", "line.location",
]);

export function changeLabel(change: DocumentChange): string {
  const label = Object.hasOwn(changeFieldLabels, change.field) ? changeFieldLabels[change.field] : change.field;
  return change.line === null ? label : t("doc.line", { line: change.line, label });
}

/** The Staff order notice for workers: the paper in hand must not be used while a revision is open. */
export function blockedDocumentNotice(summary: OrderDocumentSummary | undefined): { title: string; lines: string[] } | null {
  if (!summary || (summary.status !== "stale" && summary.status !== "revoked")) return null;
  return {
    title: t("doc.blocked.title"),
    lines: summary.status === "revoked"
      ? [t("doc.blocked.revoked1"), t("doc.blocked.revoked2")]
      : [t("doc.blocked.pending1"), t("doc.blocked.pending2")],
  };
}

/** Worker text for an old QR scan, in the active language (details come from the server). */
export function invalidDocumentMessage(error: unknown): { title: string; lines: string[] } | null {
  if (!(error instanceof StaffServiceError)) return null;
  if (error.code === "DOCUMENT_SUPERSEDED") {
    const active = typeof error.details?.activeRevisionNumber === "number" ? error.details.activeRevisionNumber : null;
    return { title: t("doc.invalid.title"), lines: [t("doc.invalid.superseded"), active === null ? t("doc.invalid.newer") : t("doc.invalid.useRevision", { number: active })] };
  }
  if (error.code === "DOCUMENT_REVOKED") return { title: t("doc.invalid.title"), lines: [t("doc.invalid.revoked"), t("doc.invalid.waitNew")] };
  if (error.code === "AVANS_NEVALIDAT") return { title: t("doc.payment.title"), lines: [t("doc.payment.line1"), t("doc.payment.line2")] };
  if (error.code === "ORDER_BLOCKED_BY_DOCUMENT") return { title: t("doc.blocked.title"), lines: [t("doc.blocked.pending1"), t("doc.blocked.pending2")] };
  return null;
}

const DOCUMENT_LIVE_TYPES = new Set(["document.revision_approved", "document.revision_rejected", "document.request_superseded", "document.blocked", "document.reactivated", "document.revision_generated"]);

export function documentLiveMessage(type: string, orderNumber?: string, revisionNumber?: number): string | null {
  return DOCUMENT_LIVE_TYPES.has(type) && orderNumber ? t(`live.${type}` as MessageKey, { number: orderNumber, revision: revisionNumber ?? "" }) : null;
}

/** "ARASYA-84521-R2.pdf" — no customer data in file names. */
export function documentFilename(orderNumber: string, revision: number): string {
  return `ARASYA-${orderNumber.replace(/^#/, "").replace(/[^A-Za-z0-9-]+/g, "-")}-R${revision}.pdf`;
}
