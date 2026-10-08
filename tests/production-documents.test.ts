import { test } from "node:test";
import assert from "node:assert/strict";
import { createProductionServices, mapDocumentSummary, mapOrderDocument, mapProductionOrder } from "../services/production/httpServices";
import { blockedDocumentNotice, canUseDocuments, changeLabel, documentFilename, documentLiveMessage, documentStep, invalidDocumentMessage, type OrderDocument } from "../domain/documents";
import { liveMessage, nextLiveNotice } from "../domain/faults";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError } from "../domain/models";
import { parseStaffRoute } from "../domain/staffRoute";
import { canAccessRoute } from "../domain/permissions";
import { orderActionBlockedCopy } from "../domain/orderActions";

const baseDocument = {
  order: { id: "trendhome:84521", number: "84521", source: "trendhome", stageId: "material-preparation", stageLabel: "Tăiere", ownerName: "Murat", completed: false, unavailable: false },
  status: "stale", version: 3,
  activeRevision: { id: "r1", number: 1, status: "active", qrHint: "abc", generatedAt: "2026-10-06T07:12:00.000Z", generatedBy: "Online", approvedBy: null, prints: 2 },
  latestRevisionNumber: 1,
  request: null,
  revisions: [{ id: "r1", number: 1, status: "active", qrHint: "abc", generatedAt: "2026-10-06T07:12:00.000Z", generatedBy: "Online", prints: 2 }],
};
const operator = ["production.documents.generate", "production.documents.reprint", "production.documents.request_revision"];

test("document views map strictly and keep no customer or QR payload", () => {
  const mapped = mapOrderDocument(baseDocument);
  assert.equal(mapped.status, "stale");
  assert.equal(mapped.activeRevision?.number, 1);
  assert.equal(JSON.stringify(mapped).includes("qrHint"), false);
  assert.throws(() => mapOrderDocument({ ...baseDocument, status: "expired" }), (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
  assert.deepEqual(mapDocumentSummary({ status: "active", version: 1, revisionNumber: 2, request: null }), { status: "active", version: 1, revisionNumber: 2, request: null });
});

test("the requester sees exactly one next step in the Romanian workflow", () => {
  const doc = (overrides: Partial<OrderDocument> & Record<string, unknown>) => mapOrderDocument({ ...baseDocument, ...overrides });
  assert.deepEqual(documentStep(doc({ status: "none", activeRevision: null, latestRevisionNumber: null, revisions: [] }), operator), { kind: "generate-first" });
  assert.deepEqual(documentStep(doc({ status: "active" }), operator), { kind: "print", revision: 1 });
  assert.deepEqual(documentStep(doc({}), operator), { kind: "request", revision: 2 });
  const pending = { id: "q1", status: "pending" as const, version: 1, targetRevision: 2, requestedBy: "Online", requestedAt: "2026-10-06T08:00:00.000Z", comment: null, changes: [{ field: "line.meters", line: 1, before: "8 m", after: "10 m" }], decidedBy: null, decisionComment: null, decidedAt: null, resolutionNote: null };
  assert.deepEqual(documentStep(doc({ request: pending }), operator), { kind: "waiting", target: 2 });
  assert.deepEqual(documentStep(doc({ request: { ...pending, status: "approved" as const, version: 2 } }), operator), { kind: "generate-revision", target: 2, requestId: "q1" });
  assert.deepEqual(documentStep(doc({ request: { ...pending, status: "rejected" as const } }), operator), { kind: "request", revision: 2 });
  assert.deepEqual(documentStep(doc({}), ["orders.scan"]), { kind: "none" });
  assert.deepEqual(documentStep(doc({ order: { ...baseDocument.order, completed: true } }), operator), { kind: "completed" });
  assert.equal(changeLabel({ field: "line.meters", line: 1, before: "8 m", after: "10 m" }), "Linia 1 · Metri (linie)");
});

test("workers see DOCUMENT BLOCAT on a stale document; old QR scans say DOCUMENT INVALID with the active revision", () => {
  const order = mapProductionOrder({ id: "trendhome:84521", source: "trendhome", orderNumber: "84521", productionStageId: "material-preparation", products: [{ id: "i", name: "Draperie", quantity: 1 }],
    status: "in_progress", updatedAt: "2026-10-06T08:00:00.000Z", version: 4, productionVersion: 3, documentStatus: "stale", employeeActionBlockedReason: "document_revision_pending",
    productionDocument: { status: "stale", version: 2, revisionNumber: 1, request: null } });
  assert.equal(order.employeeActionBlockedReason, "document_revision_pending");
  assert.match(orderActionBlockedCopy.document_revision_pending, /Document blocat/);
  assert.deepEqual(blockedDocumentNotice(order.productionDocument), { title: "DOCUMENT BLOCAT", lines: ["Comanda are o revizie în curs.", "Așteaptă aprobarea și documentul nou."] });
  assert.equal(blockedDocumentNotice({ status: "active", version: 1, revisionNumber: 2, request: null }), null);
  const superseded = new StaffServiceError("DOCUMENT_SUPERSEDED", undefined, { activeRevisionNumber: 2 });
  assert.deepEqual(invalidDocumentMessage(superseded), { title: "DOCUMENT INVALID", lines: ["Acest document a fost înlocuit.", "Folosește REVIZIA 2."] });
  assert.equal(getErrorPresentation(superseded).title, "DOCUMENT INVALID");
  assert.equal(getErrorPresentation(new StaffServiceError("DOCUMENT_SUPERSEDED")).message, "Acest document a fost înlocuit. Există o revizie mai nouă a documentului.");
  assert.equal(getErrorPresentation(new StaffServiceError("ORDER_BLOCKED_BY_DOCUMENT")).title, "DOCUMENT BLOCAT");
});

test("the server's DOCUMENT_SUPERSEDED details reach the scanner without other details", async () => {
  const fetchImpl = (async () => new Response(JSON.stringify({ error: { code: "DOCUMENT_SUPERSEDED", message: "x", details: { activeRevisionNumber: 3, revisionNumber: 1, secret: "no" } } }), { status: 410 })) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, workflowCache: { read: () => null, write: () => undefined, clear: () => undefined } as never });
  await assert.rejects(services.orders.resolveQr("ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ"), (error: unknown) => error instanceof StaffServiceError && error.code === "DOCUMENT_SUPERSEDED" && JSON.stringify(error.details) === '{"activeRevisionNumber":3}');
});

test("live document notices are Romanian and actionable; routes and permissions are server-named", () => {
  assert.equal(documentLiveMessage("document.revision_approved", "84521", 2), "Revizia 2 a fost aprobată pentru comanda #84521. Poți genera documentul nou.");
  assert.equal(liveMessage("document.blocked", "84521"), "Comanda #84521: documentul de producție este blocat. Așteaptă documentul nou.");
  assert.equal(liveMessage("document.changed", "84521"), null);
  const approved = { seq: 4, type: "document.revision_approved", orderId: "trendhome:84521", orderNumber: "84521", revisionNumber: 2 };
  assert.equal(nextLiveNotice(approved, { seq: 5, type: "document.changed", orderId: "trendhome:84521", orderNumber: "84521" }), approved);
  assert.deepEqual(parseStaffRoute("/documents/trendhome%3A84521"), { kind: "document-detail", pathname: "/documents/trendhome%3A84521", orderId: "trendhome:84521" });
  const employee = { permissions: ["production.documents.request_revision"] } as never;
  assert.equal(canAccessRoute(employee, "/documents/trendhome%3A84521"), true);
  assert.equal(canAccessRoute({ permissions: ["orders.scan"] } as never, "/documents/trendhome%3A84521"), false);
  assert.equal(canUseDocuments(["orders.scan", "orders.claim"]), false);
  assert.equal(documentFilename("#84521", 2), "ARASYA-84521-R2.pdf");
});

test("revision preview is a plain GET of one revision (nothing recorded) and history keeps approver and revoke reason", async () => {
  const calls: { url: string; method: string }[] = [];
  const fetchImpl = (async (url: string, init?: RequestInit) => {
    calls.push({ url: String(url), method: init?.method ?? "GET" });
    return new Response(new Uint8Array([37, 80, 68, 70]), { status: 200, headers: { "Content-Type": "application/pdf" } });
  }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, workflowCache: { read: () => null, write: () => undefined, clear: () => undefined } as never });
  const blob = await services.documents!.preview("trendhome:84521", 2);
  assert.equal(blob.size, 4);
  assert.deepEqual(calls, [{ url: "https://api.arasyahome.ro/production-documents/orders/trendhome%3A84521/revisions/2/preview", method: "GET" }]);
  const mapped = mapOrderDocument({ ...baseDocument, revisions: [
    { id: "r2", number: 2, status: "active", qrHint: "def", generatedAt: "2026-10-06T09:00:00.000Z", generatedBy: "Trendhome · #7 Operator", approvedBy: "Director Online", prints: 1 },
    { id: "r1", number: 1, status: "revoked", qrHint: "abc", generatedAt: "2026-10-06T07:12:00.000Z", generatedBy: "Online", approvedBy: null, revokeReason: "Test", prints: 2 },
  ] });
  assert.equal(mapped.revisions[0].approvedBy, "Director Online");
  assert.equal(mapped.revisions[1].revokeReason, "Test");
  assert.equal(JSON.stringify(mapped).includes("qrHint"), false);
});

test("a source-issued ticket refusal is explained in Romanian", () => {
  assert.match(getErrorPresentation(new StaffServiceError("DOCUMENT_AUTHORITY_SOURCE")).message, /magazinul sursă/);
});
