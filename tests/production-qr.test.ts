import { test } from "node:test";
import assert from "node:assert/strict";
import { createProductionServices, mapProductionQrView } from "../services/production/httpServices";
import { qrAuthorityLabels, qrLabelFilename, qrRevisionStateLabels, qrRotateBlockedCopy, qrRotationReasonLabels } from "../domain/productionQr";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError } from "../domain/models";

const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" shape-rendering="crispEdges" role="img" aria-label="Cod QR de producție"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="M4 4h1v1h-1zM5 4h1v1h-1z"/></svg>';
const view = {
  globalOrderId: "trendhome:63380", orderNumber: "63380", source: "trendhome", qrAuthorityMode: "enforce", productionAuthority: "operations", qrAuthority: "arasya",
  active: { revision: 2, issuedAt: "2026-10-08T10:00:00.000Z", hint: "0123456789", documentRevision: null, payload: "ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ", svg },
  history: [
    { revision: 2, state: "active", issuedAt: "2026-10-08T10:00:00.000Z", retiredAt: null, hint: "0123456789", documentRevision: null },
    { revision: 1, state: "superseded", issuedAt: "2026-10-07T19:32:00.000Z", retiredAt: "2026-10-08T10:00:00.000Z", hint: "abcdef0123", documentRevision: null },
  ],
  rotate: { allowed: true, blockedReason: null, reasons: ["label_lost", "label_damaged", "security"] },
};
const serverError = (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR";

test("the QR authority view maps the active revision, its preview and the history", () => {
  const mapped = mapProductionQrView(view);
  assert.equal(mapped.qrAuthority, "arasya");
  assert.equal(mapped.active?.revision, 2);
  assert.equal(mapped.history.map((row) => row.state).join(","), "active,superseded");
  assert.equal(mapProductionQrView({ ...view, active: null, qrAuthority: "source" }).active, null);
});

test("the QR view fails closed on unknown values and on anything but the server's QR drawing", () => {
  assert.throws(() => mapProductionQrView({ ...view, qrAuthority: "yd" }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, qrAuthorityMode: "on" }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, active: { ...view.active, payload: "https://trendhome.ro/wp-admin/post.php?post=1" } }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, active: { ...view.active, svg: svg.replace("<rect", "<script>alert(1)</script><rect") } }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, active: { ...view.active, svg: svg.replace('d="M4', 'onload="x" d="M4') } }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, history: [{ ...view.history[0], state: "deleted" }] }), serverError);
  assert.throws(() => mapProductionQrView({ ...view, rotate: { ...view.rotate, reasons: ["because"] } }), serverError);
});

test("the QR service calls the manager routes with an idempotency key", async () => {
  const calls: { url: string; init: RequestInit }[] = [];
  const fetchImpl = (async (input: string, init: RequestInit = {}) => {
    calls.push({ url: input, init });
    return new Response(JSON.stringify(view), { status: 200, headers: { "content-type": "application/json" } });
  }) as unknown as typeof fetch;
  const services = createProductionServices("https://api.example.test", { fetchImpl, isOnline: () => true });
  await services.productionQr!.inspect("trendhome:63380");
  await services.productionQr!.rotate("trendhome:63380", { expectedQrRevision: 2, reason: "label_lost" }, "idem-qr-0001");
  assert.ok(calls[0].url.endsWith("/orders/trendhome%3A63380/production-qr"));
  assert.ok(calls[1].url.endsWith("/orders/trendhome%3A63380/production-qr/rotate"));
  assert.equal(calls[1].init.method, "POST");
  assert.equal(new Headers(calls[1].init.headers).get("Idempotency-Key"), "idem-qr-0001");
  assert.deepEqual(JSON.parse(String(calls[1].init.body)), { expectedQrRevision: 2, reason: "label_lost" });
});

test("a replaced or revoked QR is refused in strong Romanian words, with the active revision when known", async () => {
  const fetchImpl = (async () => new Response(JSON.stringify({ error: { code: "QR_SUPERSEDED", message: "x", details: { qrRevision: 1, activeQrRevision: 2 } } }), { status: 410 })) as unknown as typeof fetch;
  const services = createProductionServices("https://api.example.test", { fetchImpl, isOnline: () => true });
  const error = await services.orders.resolveQr("ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ").catch((caught: unknown) => caught);
  assert.ok(error instanceof StaffServiceError && error.code === "QR_SUPERSEDED");
  const presentation = getErrorPresentation(error);
  assert.equal(presentation.title, "COD QR ÎNLOCUIT");
  assert.match(presentation.message, /revizia 2/);
  assert.equal(getErrorPresentation(new StaffServiceError("QR_SUPERSEDED")).title, "COD QR ÎNLOCUIT");
  assert.equal(getErrorPresentation(new StaffServiceError("QR_REVOKED")).title, "COD QR ANULAT");
  for (const code of ["QR_CHANGED", "QR_CUTOVER_DISABLED", "QR_NOT_ARASYA", "QR_NOT_SUPPORTED", "QR_DOCUMENT_CONTROLLED"] as const) {
    const presentation = getErrorPresentation(new StaffServiceError(code));
    assert.ok(presentation.title.length > 0 && !/[A-Z_]{6,}/.test(presentation.message), code);
  }
});

test("QR copy is Romanian, carries no internals and label files carry no customer data", () => {
  assert.match(qrAuthorityLabels.arasya, /Arasya/);
  assert.match(qrAuthorityLabels.source, /YD SOFT/);
  assert.equal(qrRevisionStateLabels.superseded, "Înlocuit");
  assert.ok(Object.values(qrRotationReasonLabels).every((text) => !/_/.test(text)));
  assert.ok(Object.values(qrRotateBlockedCopy).every((text) => !/_/.test(text)));
  assert.equal(qrLabelFilename("63380", 2), "ARASYA-QR-63380-R2.svg");
  assert.equal(qrLabelFilename("#63 380/x", 3), "ARASYA-QR-63380x-R3.svg");
});
