import assert from "node:assert/strict";
import test from "node:test";
import { StaffServiceError } from "../domain/models";
import { routeForSession } from "../features/auth/routeProtection";
import { createDuplicateGuard } from "../features/scanner/duplicateGuard";
import { initialScannerState, scannerReducer } from "../features/scanner/machine";
import { createDemoServices } from "../services/dev/demoServices";
import { getErrorPresentation, mapCameraError } from "../services/errors";

test("protected routes send anonymous employees to login", () => {
  assert.equal(routeForSession("/orders", false), "/login");
  assert.equal(routeForSession("/history", true), "/history");
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
  assert.equal(updated.currentStage.label, "Pregătire Material");
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
