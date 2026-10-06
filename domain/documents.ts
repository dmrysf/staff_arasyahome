import { StaffServiceError } from "./models";

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

export type DocumentRevision = { number: number; status: "active" | "superseded" | "revoked"; generatedAt: string; generatedBy: string; prints: number };

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
}

export const DOCUMENT_PERMISSIONS = ["production.documents.generate", "production.documents.reprint", "production.documents.request_revision"] as const;

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

export const documentStatusLabels: Record<DocumentStatus, string> = {
  none: "Document de producție negenerat",
  active: "Document activ",
  stale: "Document blocat · datele de producție s-au schimbat",
  revoked: "Document anulat · așteaptă documentul nou",
};

export const changeFieldLabels: Record<string, string> = {
  orderNumber: "Număr comandă",
  "customer.name": "Client",
  "customer.company": "Companie",
  "customer.contact": "Persoană de contact",
  "customer.address": "Adresă livrare",
  "customer.phone": "Telefon (mascat)",
  notes: "Instrucțiuni comandă",
  "line.added": "Linie nouă",
  "line.removed": "Linie eliminată",
  "line.code": "Cod produs",
  "line.name": "Produs",
  "line.kind": "Tip produs",
  "line.variant": "Variantă",
  "line.color": "Culoare",
  "line.width": "Lățime",
  "line.height": "Înălțime",
  "line.meters": "Metri (linie)",
  "line.quantity": "Cantitate",
  "line.notes": "Note",
  "line.productionNotes": "Note de producție",
  "line.options": "Opțiuni de confecționare",
  "line.location": "Locație proiect",
};

export function changeLabel(change: DocumentChange): string {
  const label = changeFieldLabels[change.field] ?? change.field;
  return change.line === null ? label : `Linia ${change.line} · ${label}`;
}

/** The Staff order notice for workers: the paper in hand must not be used while a revision is open. */
export function blockedDocumentNotice(summary: OrderDocumentSummary | undefined): { title: string; lines: string[] } | null {
  if (!summary || (summary.status !== "stale" && summary.status !== "revoked")) return null;
  return {
    title: "DOCUMENT BLOCAT",
    lines: summary.status === "revoked"
      ? ["Documentul de producție a fost anulat.", "Așteaptă documentul nou înainte de a continua."]
      : ["Comanda are o revizie în curs.", "Așteaptă aprobarea și documentul nou."],
  };
}

/** Romanian text for an old QR scan (details come from the server). */
export function invalidDocumentMessage(error: unknown): { title: string; lines: string[] } | null {
  if (!(error instanceof StaffServiceError)) return null;
  if (error.code === "DOCUMENT_SUPERSEDED") {
    const active = typeof error.details?.activeRevisionNumber === "number" ? error.details.activeRevisionNumber : null;
    return { title: "DOCUMENT INVALID", lines: ["Acest document a fost înlocuit.", active === null ? "Există o revizie mai nouă a documentului." : `Folosește REVIZIA ${active}.`] };
  }
  if (error.code === "DOCUMENT_REVOKED") return { title: "DOCUMENT INVALID", lines: ["Acest document a fost anulat.", "Așteaptă documentul nou."] };
  if (error.code === "ORDER_BLOCKED_BY_DOCUMENT") return { title: "DOCUMENT BLOCAT", lines: ["Comanda are o revizie în curs.", "Așteaptă aprobarea și documentul nou."] };
  return null;
}

const documentLiveMessages: Partial<Record<string, (orderNumber: string, revision: number | undefined) => string>> = {
  "document.revision_approved": (n, r) => `Revizia ${r ?? ""} a fost aprobată pentru comanda #${n}. Poți genera documentul nou.`,
  "document.revision_rejected": (n) => `Cererea de revizie pentru comanda #${n} a fost respinsă. Vezi motivul.`,
  "document.request_superseded": (n) => `Comanda #${n} s-a schimbat din nou. Este necesară o nouă cerere de revizie.`,
  "document.blocked": (n) => `Comanda #${n}: documentul de producție este blocat. Așteaptă documentul nou.`,
  "document.reactivated": (n, r) => `Comanda #${n}: REVIZIA ${r ?? ""} este activă. Poți continua lucrul.`,
  "document.revision_generated": (n, r) => `REVIZIA ${r ?? ""} a comenzii #${n} a fost generată. Tipărește documentul nou.`,
};

export function documentLiveMessage(type: string, orderNumber?: string, revisionNumber?: number): string | null {
  const message = documentLiveMessages[type];
  return message && orderNumber ? message(orderNumber, revisionNumber) : null;
}

/** "ARASYA-84521-R2.pdf" — no customer data in file names. */
export function documentFilename(orderNumber: string, revision: number): string {
  return `ARASYA-${orderNumber.replace(/^#/, "").replace(/[^A-Za-z0-9-]+/g, "-")}-R${revision}.pdf`;
}
