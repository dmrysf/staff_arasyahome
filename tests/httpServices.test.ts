import { test } from "node:test";
import assert from "node:assert/strict";
import { createProductionServices, mapActivityPage, mapProductionOrder, mapOrderPage } from "../services/production/httpServices";
import { StaffServiceError } from "../domain/models";

function assertServerError(fn: () => void) {
  try {
    fn();
    assert.fail("Expected StaffServiceError");
  } catch (error) {
    assert.ok(error instanceof StaffServiceError, "Error should be StaffServiceError");
    assert.equal(error.code, "SERVER_ERROR");
  }
}

test("mapProductionOrder validates quantity", () => {
  const baseItem = { id: "item-1", name: "Item", quantity: 1 };
  const baseOrder = {
    id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z"
  };

  assert.doesNotThrow(() => mapProductionOrder(baseOrder));

  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: 0 }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: -1 }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: 1.5 }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: "2" }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: NaN }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, quantity: Infinity }] }));
});

test("mapProductionOrder validates version", () => {
  const baseOrder = {
    id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z"
  };

  assert.doesNotThrow(() => mapProductionOrder(baseOrder));

  assertServerError(() => mapProductionOrder({ ...baseOrder, version: 0 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: -1 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: 1.5 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: "2" }));
});

test("mapProductionOrder validates products array", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: null }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: {} }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: undefined }));
});

test("mapProductionOrder validates relation type", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, employeeRelation: { employeeUuid: "emp-1", type: "claimed", lastActionAt: "2026-08-19T00:00:00Z" } }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, employeeRelation: { employeeUuid: "emp-1", type: "something-new", lastActionAt: "2026-08-19T00:00:00Z" } }));
});

test("mapProductionOrder validates freshness status", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, freshness: { status: "fresh", sourceChangedAt: "2026-08-19T00:00:00Z", lastSourceSeenAt: "2026-08-19T00:00:00Z" } }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, freshness: { status: "something-new", sourceChangedAt: "2026-08-19T00:00:00Z", lastSourceSeenAt: "2026-08-19T00:00:00Z" } }));
});

test("mapProductionOrder validates meters", () => {
  const baseItem = { id: "item-1", name: "Item", quantity: 1 };
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z" };

  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: 0 }] }));
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: 2.5 }] }));
  
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: -1 }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: Infinity }] }));
});

test("mapProductionOrder validates measurement unit", () => {
  const baseItem = { id: "item-1", name: "Item", quantity: 1 };
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z" };

  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, measurements: { unit: "mm" } }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, measurements: { unit: "inch" } }] }));
});

test("mapOrderPage validates items array", () => {
  assert.doesNotThrow(() => mapOrderPage({ items: [] }));
  assertServerError(() => mapOrderPage({ items: null }));
  assertServerError(() => mapOrderPage({ items: {} }));
  assertServerError(() => mapOrderPage({ items: "not array" }));
});

test("mapProductionOrder accepts unknown source identity", () => {
  const baseOrder = { id: "ord-1", source: "some-future-brand", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  const mapped = mapProductionOrder(baseOrder);
  assert.equal(mapped.source, "unknown");
});

test("mapProductionOrder requires a production version and strict server actions", () => {
  const baseOrder = { id: "trendhome:1", source: "trendhome", orderNumber: "1", productionStageId: "material-preparation", status: "in_progress", version: 3, productionVersion: 2, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  assertServerError(() => mapProductionOrder({ ...baseOrder, productionVersion: undefined }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, productionVersion: 0 }));
  assert.deepEqual(mapProductionOrder({ ...baseOrder, employeeAllowedAction: { id: "claim" } }).employeeAllowedAction, { id: "claim", label: "Preia comanda" });
  assert.deepEqual(mapProductionOrder({ ...baseOrder, employeeAllowedAction: { id: "complete_stage" } }).employeeAllowedAction, { id: "complete_stage", label: "Finalizează etapa" });
  assert.equal(mapProductionOrder({ ...baseOrder, employeeActionBlockedReason: "claimed_by_other" }).employeeActionBlockedReason, "claimed_by_other");
  assertServerError(() => mapProductionOrder({ ...baseOrder, employeeAllowedAction: { id: "move_to", toStageId: "delivery" } }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, employeeActionBlockedReason: "something-new" }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, employeeAllowedAction: { id: "claim" }, employeeActionBlockedReason: "claimed_by_other" }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, updatedAt: "not a date" }));
  assert.equal(mapProductionOrder({ ...baseOrder, productionCompletedAt: "2026-08-19T10:00:00.000Z" }).productionCompletedAt, "2026-08-19T10:00:00.000Z");
});

test("activity mapping validates actions, label snapshots and summary counters", () => {
  const entry = { id: "e1", occurredAt: "2026-08-19T10:00:00.000Z", action: "stage_completed", orderId: "trendhome:1", orderNumber: "1", source: "trendhome", fromStageId: "material-preparation", fromStageLabelSnapshot: "Pregătire material", toStageId: "workshop-receiving", toStageLabelSnapshot: "Primire atelier", meters: 8.4 };
  const summary = { processed: 2, meters: 8.4, handedOver: 1, inProgress: 1 };
  const page = mapActivityPage({ items: [entry, { ...entry, id: "e2", action: "claimed", toStageId: undefined, toStageLabelSnapshot: undefined }], nextCursor: null, summary });
  assert.equal(page.items.length, 2);
  assert.equal(page.items[1].toStageId, undefined);
  assert.equal(page.nextCursor, undefined);
  assertServerError(() => mapActivityPage({ items: [{ ...entry, action: "moved" }], summary }));
  assertServerError(() => mapActivityPage({ items: [{ ...entry, toStageId: undefined, toStageLabelSnapshot: undefined }], summary }));
  assertServerError(() => mapActivityPage({ items: [], summary: { ...summary, processed: -1 } }));
  assertServerError(() => mapActivityPage({ items: [] }));
});

function jsonResponse(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("every production order method calls an existing Operations route with CSRF and idempotency", async () => {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  const order = { id: "trendhome:61833", source: "trendhome", orderNumber: "61833", productionStageId: "material-preparation", status: "in_progress", version: 2, productionVersion: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  const fetchImpl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    if (url.endsWith("/auth/session")) return jsonResponse(200, { employee: { employeeUuid: "e", displayName: "A", username: "a", department: "D", role: "employee", status: "active", permissions: [], allowedStageIds: [] }, expiresAt: "2026-08-19T10:00:00Z", csrfToken: "csrf-1" });
    if (url.includes("/activity/mine")) return jsonResponse(200, { items: [], nextCursor: null, summary: { processed: 0, meters: 0, handedOver: 0, inProgress: 0 } });
    if (url.includes("/orders/mine")) return jsonResponse(200, { items: [order], nextCursor: null });
    return jsonResponse(200, order);
  }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, requestId: () => "req" });
  await services.auth.getSession();
  await services.orders.resolveQr("ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ");
  await services.orders.lookup("61833");
  await services.orders.listMine({ limit: 10 });
  await services.orders.getById("trendhome:61833");
  await services.orders.claim("trendhome:61833", { expectedVersion: 1, idempotencyKey: "key-claim-0000000001" });
  await services.orders.confirmStageTransition("trendhome:61833", { expectedVersion: 2, idempotencyKey: "key-transition-00001" });
  await services.activity.listMine({ range: "custom", from: "2026-08-01", to: "2026-08-19" });
  const routes = calls.map(({ url, init }) => `${init.method ?? "GET"} ${url.replace("https://api.arasyahome.ro", "")}`);
  assert.deepEqual(routes, [
    "GET /auth/session",
    "POST /orders/resolve-qr",
    "GET /orders/lookup?code=61833",
    "GET /orders/mine?limit=10",
    "GET /orders/trendhome%3A61833",
    "POST /orders/trendhome%3A61833/claim",
    "POST /orders/trendhome%3A61833/transition",
    "GET /activity/mine?range=custom&from=2026-08-01&to=2026-08-19",
  ]);
  for (const call of calls.filter(({ init }) => init.method === "POST")) {
    assert.equal(new Headers(call.init.headers).get("X-CSRF-Token"), "csrf-1");
    assert.equal(call.init.credentials, "include");
  }
  const claim = calls[5];
  assert.equal(new Headers(claim.init.headers).get("Idempotency-Key"), "key-claim-0000000001");
  assert.deepEqual(JSON.parse(String(claim.init.body)), { expectedVersion: 1 });
  assert.deepEqual(JSON.parse(String(calls[1].init.body)), { token: "ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ" });
});

test("typed Operations failures map to deterministic Staff errors", async () => {
  const cases: Array<[number, string, string]> = [
    [409, "ORDER_ALREADY_CLAIMED", "ORDER_ALREADY_CLAIMED"],
    [409, "ORDER_CHANGED", "ORDER_CHANGED"],
    [409, "IDEMPOTENCY_CONFLICT", "IDEMPOTENCY_CONFLICT"],
    [409, "INVALID_STAGE_TRANSITION", "INVALID_STAGE_TRANSITION"],
    [409, "ORDER_AMBIGUOUS", "ORDER_AMBIGUOUS"],
    [409, "ORDER_UNAVAILABLE", "ORDER_UNAVAILABLE"],
    [400, "INVALID_QR", "INVALID_QR"],
    [404, "UNKNOWN_QR", "UNKNOWN_QR"],
    [410, "EXPIRED_QR", "EXPIRED_QR"],
    [400, "INVALID_LOOKUP_CODE", "INVALID_ORDER_CODE"],
    [404, "ORDER_NOT_FOUND", "ORDER_NOT_FOUND"],
    [503, "WORKFLOW_UNAVAILABLE", "WORKFLOW_UNAVAILABLE"],
    [429, "RATE_LIMITED", "RATE_LIMITED"],
    [403, "CSRF_INVALID", "CSRF_INVALID"],
    [500, "INTERNAL_ERROR", "SERVER_ERROR"],
  ];
  for (const [status, backendCode, expected] of cases) {
    const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl: (async () => jsonResponse(status, { error: { code: backendCode, message: "x", requestId: "r" } })) as typeof fetch, isOnline: () => true });
    await assert.rejects(services.orders.lookup("61833"), (error: unknown) => error instanceof StaffServiceError && error.code === expected, `${backendCode} -> ${expected}`);
  }
  const offline = createProductionServices("https://api.arasyahome.ro", { fetchImpl: (async () => { throw new Error("unreachable"); }) as typeof fetch, isOnline: () => false });
  await assert.rejects(offline.orders.claim("trendhome:1", { expectedVersion: 1, idempotencyKey: "key-offline-00000001" }), (error: unknown) => error instanceof StaffServiceError && error.code === "NETWORK_UNAVAILABLE");
  const malformed = createProductionServices("https://api.arasyahome.ro", { fetchImpl: (async () => jsonResponse(200, { id: "x" })) as typeof fetch, isOnline: () => true });
  await assert.rejects(malformed.orders.confirmStageTransition("trendhome:1", { expectedVersion: 1, idempotencyKey: "key-malformed-000001" }), (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
});
