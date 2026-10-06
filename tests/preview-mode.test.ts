import assert from "node:assert/strict";
import test from "node:test";
import { StaffServiceError } from "../domain/models";
import { createServices } from "../services/createServices";
import { createPreviewServices, PREVIEW_SESSION_KEY, type PreviewSessionStorage } from "../services/preview/previewServices";
import { resolveRuntimeConfig } from "../src/runtimeConfig";
import { isEmployeeRelevantOrder, matchesMyOrdersView } from "../domain/orderRelation";
import { previewEmployee, previewOrderDatabase } from "../mocks/previewFixtures";
import { systemConnectionLabels } from "../components/status/YDSoftConnectionStatus";
import { bottomNavigationItems } from "../components/navigation/BottomNavigation";
import { getNextStage, getStageById } from "../domain/productionWorkflow";
import { previewProductionWorkflow } from "../mocks/productionWorkflow";

class MemorySessionStorage implements PreviewSessionStorage {
  private readonly values = new Map<string, string>();
  getItem(key: string) { return this.values.get(key) ?? null; }
  setItem(key: string, value: string) { this.values.set(key, value); }
  removeItem(key: string) { this.values.delete(key); }
}

test("runtime mode selection is explicit and production-only preview fails closed", () => {
  assert.equal(resolveRuntimeConfig({ isDevelopment: true, isProduction: false, demoFlag: "true" }).mode, "demo");
  assert.equal(resolveRuntimeConfig({ isDevelopment: true, isProduction: false, previewFlag: "true" }).mode, "production");
  assert.equal(resolveRuntimeConfig({ isDevelopment: false, isProduction: true, previewFlag: "true" }).mode, "preview");
  assert.equal(resolveRuntimeConfig({ isDevelopment: false, isProduction: true, previewFlag: "false" }).mode, "production");
  assert.equal(resolveRuntimeConfig({ isDevelopment: false, isProduction: true }).mode, "production");
  assert.equal(resolveRuntimeConfig({ isDevelopment: false, isProduction: true, demoFlag: "true" }).mode, "production");
});

test("preview auth accepts only demo credentials and persists only an expiry marker", async () => {
  const storage = new MemorySessionStorage();
  const now = Date.parse("2026-08-19T08:00:00Z");
  const services = createPreviewServices({ storage, now: () => now });

  await assert.rejects(
    services.auth.login({ username: "wrong", password: "demo" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "UNAUTHORIZED_ACTION",
  );
  await assert.rejects(
    services.auth.login({ username: "demo", password: "wrong" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "UNAUTHORIZED_ACTION",
  );

  const session = await services.auth.login({ username: "demo", password: "demo" });
  assert.equal(session.employee.employeeUuid, "EMP-PREVIEW-001");
  assert.equal(session.employee.displayName, "Ali Demo");
  assert.match(storage.getItem(PREVIEW_SESSION_KEY) ?? "", /^\d+$/);
  assert.equal([...Object.keys(session.employee)].includes("password"), false);

  const reconstructed = await createPreviewServices({ storage, now: () => now }).auth.getSession();
  assert.equal(reconstructed?.employee.username, "demo");

  await services.auth.logout();
  assert.equal(storage.getItem(PREVIEW_SESSION_KEY), null);
  assert.equal(await services.auth.getSession(), null);
});

test("expired preview markers are removed and do not reconstruct sessions", async () => {
  const storage = new MemorySessionStorage();
  let now = Date.parse("2026-08-19T08:00:00Z");
  const services = createPreviewServices({ storage, now: () => now });
  await services.auth.login({ username: "demo", password: "demo" });
  now += 8 * 60 * 60 * 1000 + 1;

  assert.equal(await createPreviewServices({ storage, now: () => now }).auth.getSession(), null);
  assert.equal(storage.getItem(PREVIEW_SESSION_KEY), null);
});

test("preview orders support QR, manual lookup, server-equivalent transitions, idempotency, and conflicts", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage(), now: () => Date.parse("2026-08-19T08:00:00Z") });
  await services.auth.login({ username: "demo", password: "demo" });
  const order = await services.orders.resolveQr("arasya:61833");
  assert.equal(order.orderNumber, "61833");
  assert.equal(order.employeeAllowedAction?.id, "complete_stage");
  assert.equal((await services.orders.lookup("61829")).source, "outletperdele");
  assert.equal((await services.orders.lookup("TY-1048")).source, "trendyol");

  const updated = await services.orders.confirmStageTransition(order.id, { expectedVersion: order.productionVersion, idempotencyKey: "preview-transition-1" });
  assert.equal(updated.productionStageId, "workshop-receiving");
  assert.equal(getStageById(await services.workflow.getCurrent(), updated.productionStageId)?.label, "Primire Croitorie");
  assert.equal(updated.productionVersion, order.productionVersion + 1);
  assert.equal(updated.employeeRelation?.type, "handover_out");
  assert.equal(updated.employeeAllowedAction, undefined);
  assert.equal(updated.employeeActionBlockedReason, "stage_not_allowed");
  assert.deepEqual(
    await services.orders.confirmStageTransition(order.id, { expectedVersion: order.productionVersion, idempotencyKey: "preview-transition-1" }),
    updated,
  );
  await assert.rejects(
    services.orders.confirmStageTransition(order.id, { expectedVersion: order.productionVersion + 1, idempotencyKey: "preview-transition-1" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "IDEMPOTENCY_CONFLICT",
  );
  await assert.rejects(
    services.orders.confirmStageTransition(order.id, { expectedVersion: order.productionVersion, idempotencyKey: "preview-stale" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "ORDER_CHANGED",
  );
  await assert.rejects(
    services.orders.resolveQr("invalid"),
    (error: unknown) => error instanceof StaffServiceError && error.code === "INVALID_QR",
  );
  const today = await services.activity.listMine({ range: "today" });
  assert.equal(today.items[0].action, "stage_completed");
  assert.equal(today.items[0].fromStageLabelSnapshot, "Tăiere");
});

test("preview activity and my orders stay behind service contracts", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const { items: orders } = await services.orders.listMine();
  assert.deepEqual(new Set(orders.map((order) => order.source)), new Set(["trendhome", "outletperdele", "trendyol"]));
  assert.ok(orders.some((order) => matchesMyOrdersView(order, "in_progress")));
  assert.ok(orders.some((order) => matchesMyOrdersView(order, "handed_over")));
  assert.equal((await services.activity.listMine({ range: "today" })).summary.inProgress, 1);
  assert.ok((await services.activity.listMine({ range: "7days" })).items.length > 3);
  assert.ok((await services.activity.listMine({ range: "month" })).items.length > 5);
  assert.equal((await services.activity.listMine({ range: "custom", from: "2026-08-18", to: "2026-08-18" })).items.length, 1);
});

test("Preview listMine returns only orders with a direct employee relationship", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const { items: mine } = await services.orders.listMine();
  const mineIds = new Set(mine.map((order) => order.id));

  assert.deepEqual(mineIds, new Set(["order-61833", "order-61829", "order-trendyol-1048"]));
  assert.equal(mine.every((order) => isEmployeeRelevantOrder(order, previewEmployee.employeeUuid)), true);
  assert.equal(mineIds.has("order-62001"), false);
  assert.equal(mineIds.has("order-62002"), false);
  assert.equal(mine.find((order) => order.id === "order-61829")?.employeeRelation?.type, "handover_out");
});

test("stage eligibility allows scanning but never adds an order to My Orders", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const unclaimed = previewOrderDatabase.find((order) => order.id === "order-62001");
  const otherEmployeeSameStage = previewOrderDatabase.find((order) => order.id === "order-62002");
  assert.ok(unclaimed && otherEmployeeSameStage);
  assert.equal(unclaimed.productionStageId, previewEmployee.allowedStageIds[0]);
  assert.equal(otherEmployeeSameStage.productionStageId, previewEmployee.allowedStageIds[0]);
  const other = await services.orders.lookup("62002");
  assert.equal(other.employeeRelation, undefined);
  assert.equal(other.employeeActionBlockedReason, "claimed_by_other");
  await assert.rejects(services.orders.claim(other.id, { expectedVersion: other.productionVersion, idempotencyKey: "claim-other" }), (error: unknown) => error instanceof StaffServiceError && error.code === "ORDER_ALREADY_CLAIMED");
  assert.equal(getNextStage(previewProductionWorkflow, "waiting")?.id, previewEmployee.allowedStageIds[0]);
});

test("a scanned Preview order becomes visible after the employee claims it, then completes N -> N+1", async () => {
  const now = Date.parse("2026-08-19T09:00:00Z");
  const services = createPreviewServices({ storage: new MemorySessionStorage(), now: () => now });
  const unclaimed = await services.orders.resolveQr("62001");
  assert.equal(unclaimed.employeeAllowedAction?.id, "claim");
  assert.equal((await services.orders.listMine()).items.some((order) => order.id === unclaimed.id), false);
  await assert.rejects(services.orders.confirmStageTransition(unclaimed.id, { expectedVersion: unclaimed.productionVersion, idempotencyKey: "too-early" }), (error: unknown) => error instanceof StaffServiceError && error.code === "INVALID_STAGE_TRANSITION");

  const claimed = await services.orders.claim(unclaimed.id, { expectedVersion: unclaimed.productionVersion, idempotencyKey: "claim-62001" });
  assert.equal(claimed.employeeRelation?.employeeUuid, previewEmployee.employeeUuid);
  assert.equal(claimed.employeeRelation?.type, "claimed");
  assert.equal(claimed.productionStageId, unclaimed.productionStageId);
  assert.equal(claimed.employeeAllowedAction?.id, "complete_stage");
  assert.equal((await services.orders.listMine()).items.some((order) => order.id === unclaimed.id), true);
  const completed = await services.orders.confirmStageTransition(claimed.id, { expectedVersion: claimed.productionVersion, idempotencyKey: "complete-62001" });
  assert.equal(completed.productionStageId, getNextStage(previewProductionWorkflow, claimed.productionStageId)?.id);
});

test("My Orders views distinguish active, recent, and handed-over involvement", async () => {
  const { items: orders } = await createPreviewServices({ storage: new MemorySessionStorage() }).orders.listMine();
  assert.deepEqual(orders.filter((order) => matchesMyOrdersView(order, "in_progress")).map((order) => order.id), ["order-61833"]);
  assert.equal(orders.filter((order) => matchesMyOrdersView(order, "recent")).length, 3);
  assert.deepEqual(orders.filter((order) => matchesMyOrdersView(order, "handed_over")).map((order) => order.id).sort(), ["order-61829", "order-trendyol-1048"]);
});

test("Preview listMine paginates correctly via cursor", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const first = await services.orders.listMine({ limit: 1 });
  assert.equal(first.items.length, 1);
  assert.ok(first.nextCursor);

  const second = await services.orders.listMine({
    limit: 1,
    cursor: first.nextCursor
  });
  assert.equal(second.items.length, 1);
  assert.notEqual(first.items[0].id, second.items[0].id);
});

test("connection labels and primary navigation routes remain explicit", () => {
  assert.deepEqual(systemConnectionLabels, {
    connected: "YD Soft conectat",
    checking: "Se verifică...",
    disconnected: "YD Soft deconectat",
  });
  assert.deepEqual(bottomNavigationItems.map((item) => item.path), ["/", "/orders", "/history", "/profile"]);
});

test("strict production service selection never accepts preview credentials or fixtures", async () => {
  const services = createServices({ mode: "production", apiBaseUrl: "" });
  assert.equal(services.mode, "production");
  await assert.rejects(
    services.auth.login({ username: "demo", password: "demo" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "CONFIGURATION_ERROR",
  );
});
