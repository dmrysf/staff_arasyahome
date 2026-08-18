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
  assert.equal(session.employee.name, "Ali Demo");
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

test("preview orders support QR, manual lookup, in-memory transitions, idempotency, and conflicts", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage(), now: () => Date.parse("2026-08-19T08:00:00Z") });
  await services.auth.login({ username: "demo", password: "demo" });
  const order = await services.orders.resolveQr("arasya:61833");
  assert.equal(order.orderNumber, "61833");
  assert.equal((await services.orders.lookup("61829")).source, "outletperdele");
  assert.equal((await services.orders.lookup("B2B-1048")).source, "b2b");

  const updated = await services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "preview-transition-1" });
  assert.equal(updated.currentStage.label, "Croire");
  assert.equal(updated.version, order.version + 1);
  assert.deepEqual(
    await services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "preview-transition-1" }),
    updated,
  );
  await assert.rejects(
    services.orders.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey: "preview-stale" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "ORDER_CHANGED",
  );
  await assert.rejects(
    services.orders.resolveQr("invalid"),
    (error: unknown) => error instanceof StaffServiceError && error.code === "INVALID_QR",
  );
});

test("preview activity and my orders stay behind service contracts", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const orders = await services.orders.listMine();
  assert.deepEqual(new Set(orders.map((order) => order.source)), new Set(["trendhome", "outletperdele", "b2b"]));
  assert.ok(orders.some((order) => order.status === "in_progress"));
  assert.ok(orders.some((order) => order.status === "handed_over"));
  assert.equal((await services.activity.listMine({ range: "today" })).summary.inProgress, 3);
  assert.ok((await services.activity.listMine({ range: "7days" })).items.length > 3);
  assert.ok((await services.activity.listMine({ range: "month" })).items.length > 5);
});

test("Preview listMine returns only orders with a direct employee relationship", async () => {
  const services = createPreviewServices({ storage: new MemorySessionStorage() });
  const mine = await services.orders.listMine();
  const mineIds = new Set(mine.map((order) => order.id));

  assert.deepEqual(mineIds, new Set(["order-61833", "order-61829", "order-b2b-1048"]));
  assert.equal(mine.every((order) => isEmployeeRelevantOrder(order, previewEmployee.employeeUuid)), true);
  assert.equal(mineIds.has("order-62001"), false);
  assert.equal(mineIds.has("order-62002"), false);
  assert.equal(mine.find((order) => order.id === "order-61829")?.employeeRelation?.type, "updated");
});

test("stage and department eligibility alone never make a Preview order visible", () => {
  const unassignedSameStage = previewOrderDatabase.find((order) => order.id === "order-62001");
  const otherEmployeeSameStage = previewOrderDatabase.find((order) => order.id === "order-62002");
  assert.ok(unassignedSameStage);
  assert.ok(otherEmployeeSameStage);
  assert.equal(unassignedSameStage.currentStage.id, "waiting");
  assert.equal(unassignedSameStage.nextStage?.id, previewEmployee.productionStagePermissions[0]);
  assert.equal(isEmployeeRelevantOrder(unassignedSameStage, previewEmployee.employeeUuid), false);
  assert.equal(otherEmployeeSameStage.currentStage.id, previewEmployee.productionStagePermissions[0]);
  assert.equal(isEmployeeRelevantOrder(otherEmployeeSameStage, previewEmployee.employeeUuid), false);
});

test("a scanned Preview order becomes visible after the employee claims it", async () => {
  const now = Date.parse("2026-08-19T09:00:00Z");
  const services = createPreviewServices({ storage: new MemorySessionStorage(), now: () => now });
  const unclaimed = await services.orders.resolveQr("62001");
  assert.equal((await services.orders.listMine()).some((order) => order.id === unclaimed.id), false);

  const claimed = await services.orders.confirmStageTransition(unclaimed.id, { expectedVersion: unclaimed.version, idempotencyKey: "claim-62001" });
  assert.equal(claimed.employeeRelation?.employeeUuid, previewEmployee.employeeUuid);
  assert.equal(claimed.employeeRelation?.type, "claimed");
  assert.equal((await services.orders.listMine()).some((order) => order.id === unclaimed.id), true);
});

test("My Orders views distinguish active, recent, and handed-over involvement", async () => {
  const orders = await createPreviewServices({ storage: new MemorySessionStorage() }).orders.listMine();
  assert.deepEqual(orders.filter((order) => matchesMyOrdersView(order, "in_progress")).map((order) => order.id), ["order-61833"]);
  assert.equal(orders.filter((order) => matchesMyOrdersView(order, "recent")).length, 3);
  assert.deepEqual(orders.filter((order) => matchesMyOrdersView(order, "handed_over")).map((order) => order.id), ["order-61829", "order-b2b-1048"]);
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
