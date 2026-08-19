import assert from "node:assert/strict";
import test from "node:test";
import { StaffServiceError } from "../domain/models";
import type { StaffOrder } from "../domain/models";
import { getUsableProductionProducts, requireProductionProducts } from "../domain/orderValidation";
import { routeForSession } from "../features/auth/routeProtection";
import { formatHomeMetric, loadTodaySummary } from "../features/home/HomeScreen";
import { createDuplicateGuard } from "../features/scanner/duplicateGuard";
import { initialScannerState, scannerReducer } from "../features/scanner/machine";
import { selectQrDecoder, type QrDecoder } from "../features/scanner/qrDecoder";
import { createDemoServices } from "../services/dev/demoServices";
import { getErrorPresentation, mapCameraError } from "../services/errors";
import { resolveRuntimeConfig } from "../src/runtimeConfig";
import { getStageById } from "../domain/productionWorkflow";

test("protected routes send anonymous employees to login", () => {
  assert.equal(routeForSession("/orders", false), "/login");
  assert.equal(routeForSession("/history", true), "/history");
  assert.equal(routeForSession("/orders/123", true), "/orders/123");
  assert.equal(routeForSession("/login", true), "/");
});

test("scanner state machine accepts only valid transitions", () => {
  const requesting = scannerReducer(initialScannerState, { type: "REQUEST_CAMERA" });
  const scanning = scannerReducer(requesting, { type: "CAMERA_READY", torchSupported: true });
  const decoded = scannerReducer(scanning, { type: "CODE_DETECTED", token: "arasya:61833" });
  const resolving = scannerReducer(decoded, { type: "RESOLVE_STARTED" });
  assert.deepEqual([requesting.status, scanning.status, decoded.status, resolving.status], ["requesting_permission", "scanning", "decoded", "resolving"]);
  assert.equal(scannerReducer(resolving, { type: "SUBMIT", idempotencyKey: "not-allowed" }).status, "resolving");
});

test("duplicate scan guard locks one code until reset", () => {
  const guard = createDuplicateGuard(1500);
  assert.equal(guard.accept("61833", 100), true);
  assert.equal(guard.accept("61833", 200), false);
  assert.equal(guard.accept("61834", 300), false);
  guard.reset();
  assert.equal(guard.accept("61834", 350), true);
});

test("confirmation and submission cannot be skipped or repeated", async () => {
  const services = createDemoServices();
  await services.auth.login({ username: "test", password: "test" });
  const order = await services.orders.resolveQr("arasya:61833");
  const review = scannerReducer(scannerReducer(scannerReducer({ status: "scanning", torchSupported: false, torchOn: false }, { type: "CODE_DETECTED", token: "arasya:61833" }), { type: "RESOLVE_STARTED" }), { type: "ORDER_RESOLVED", order });
  assert.equal(scannerReducer(review, { type: "SUBMIT", idempotencyKey: "early" }).status, "review");
  const confirming = scannerReducer(review, { type: "OPEN_CONFIRMATION" });
  const submitting = scannerReducer(confirming, { type: "SUBMIT", idempotencyKey: "request-1" });
  const repeated = scannerReducer(submitting, { type: "SUBMIT", idempotencyKey: "request-2" });
  assert.equal(submitting.status, "submitting");
  assert.deepEqual(repeated, submitting);
});

test("successful demo QR flow waits for confirmed mutation", async () => {
  const services = createDemoServices();
  await services.auth.login({ username: "test", password: "test" });
  const order = await services.orders.resolveQr("arasya:61833");
  const updated = await services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "request-success" });
  const workflow = await services.workflow.getCurrent();
  assert.equal(getStageById(workflow, updated.productionStageId)?.label, "Pregătire material");
  assert.equal(updated.version, order.version + 1);
});

test("invalid QR and expired session remain explicit failures", async () => {
  const services = createDemoServices();
  await assert.rejects(services.orders.resolveQr("invalid"), (error: unknown) => error instanceof StaffServiceError && error.code === "INVALID_QR");
  await assert.rejects(services.orders.resolveQr("session-expired"), (error: unknown) => error instanceof StaffServiceError && error.code === "SESSION_EXPIRED");
});

test("camera denial maps to a Romanian recovery state", () => {
  const denied = mapCameraError({ name: "NotAllowedError" });
  const presentation = getErrorPresentation(denied);
  assert.equal(denied.code, "CAMERA_PERMISSION_DENIED");
  assert.match(presentation.title, /cameră/i);
  assert.match(presentation.action, /încearcă/i);
});

test("concurrent versions reject stale confirmations", async () => {
  const services = createDemoServices();
  const order = await services.orders.resolveQr("61833");
  await services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "first" });
  await assert.rejects(
    services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "stale" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "ORDER_CHANGED",
  );
});

test("home summary comes from ActivityService and unavailable values remain neutral", async () => {
  const services = createDemoServices();
  const summary = await loadTodaySummary(services.activity);
  assert.equal(summary.inProgress, 6);
  assert.equal(summary.handedOver, 7);
  assert.equal(formatHomeMetric(undefined), "—");
  assert.equal(formatHomeMetric(Number.NaN), "—");
});

test("orders without usable production products produce a controlled error", () => {
  const emptyOrder = { products: [] } as unknown as StaffOrder;
  assert.equal(getUsableProductionProducts(emptyOrder).length, 0);
  assert.throws(
    () => requireProductionProducts(emptyOrder),
    (error: unknown) => error instanceof StaffServiceError && error.code === "ORDER_PRODUCTS_UNAVAILABLE",
  );
  assert.match(getErrorPresentation(new StaffServiceError("ORDER_PRODUCTS_UNAVAILABLE")).message, /nu conține produse/i);
});

test("malformed products are ignored while valid production data remains usable", () => {
  const services = createDemoServices();
  return services.orders.getById("order-61833").then((order) => {
    const mixed = { ...order, products: [{ name: "incomplet" }, ...order.products] } as unknown as StaffOrder;
    const normalized = requireProductionProducts(mixed);
    assert.equal(normalized.products.length, 1);
    assert.equal(normalized.products.at(0)?.code, "DV-302");
  });
});

test("QR decoder selection prefers native and lazy-loads fallback only when needed", async () => {
  let fallbackLoads = 0;
  const native: QrDecoder = { kind: "native", isSupported: () => true, detect: async () => "native-code" };
  const fallback: QrDecoder = { kind: "fallback", isSupported: () => true, detect: async () => "fallback-code" };

  assert.equal((await selectQrDecoder({ nativeDecoder: native, fallbackLoader: async () => { fallbackLoads += 1; return fallback; } }))?.kind, "native");
  assert.equal(fallbackLoads, 0);

  const unsupportedNative: QrDecoder = { kind: "native", isSupported: () => false, detect: async () => null };
  assert.equal((await selectQrDecoder({ nativeDecoder: unsupportedNative, fallbackLoader: async () => { fallbackLoads += 1; return fallback; } }))?.kind, "fallback");
  assert.equal(fallbackLoads, 1);
});

test("manual lookup remains available when no automatic QR decoder exists", async () => {
  const decoder = await selectQrDecoder({ nativeDecoder: null, fallbackLoader: async () => null });
  assert.equal(decoder, null);
  const error = new StaffServiceError("AUTOMATIC_SCAN_UNAVAILABLE");
  assert.match(getErrorPresentation(error).action, /introdu codul/i);
});

test("production can never enable the demo adapter from the environment flag", () => {
  assert.equal(resolveRuntimeConfig({ isDevelopment: false, isProduction: true, demoFlag: "true", apiBaseUrl: "" }).mode, "production");
  assert.equal(resolveRuntimeConfig({ isDevelopment: true, isProduction: false, demoFlag: "true", apiBaseUrl: "" }).mode, "demo");
});
