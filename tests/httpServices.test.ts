import { test } from "node:test";
import assert from "node:assert/strict";
import { mapProductionOrder, mapOrderPage } from "../services/production/httpServices";
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
    id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z"
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
    id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [], updatedAt: "2026-08-19T00:00:00Z"
  };

  assert.doesNotThrow(() => mapProductionOrder(baseOrder));

  assertServerError(() => mapProductionOrder({ ...baseOrder, version: 0 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: -1 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: 1.5 }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, version: "2" }));
});

test("mapProductionOrder validates products array", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: null }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: {} }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: undefined }));
});

test("mapProductionOrder validates relation type", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, employeeRelation: { employeeUuid: "emp-1", type: "claimed", lastActionAt: "2026-08-19T00:00:00Z" } }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, employeeRelation: { employeeUuid: "emp-1", type: "something-new", lastActionAt: "2026-08-19T00:00:00Z" } }));
});

test("mapProductionOrder validates freshness status", () => {
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, freshness: { status: "fresh", sourceChangedAt: "2026-08-19T00:00:00Z", lastSourceSeenAt: "2026-08-19T00:00:00Z" } }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, freshness: { status: "something-new", sourceChangedAt: "2026-08-19T00:00:00Z", lastSourceSeenAt: "2026-08-19T00:00:00Z" } }));
});

test("mapProductionOrder validates meters", () => {
  const baseItem = { id: "item-1", name: "Item", quantity: 1 };
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z" };

  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: 0 }] }));
  assert.doesNotThrow(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: 2.5 }] }));
  
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: -1 }] }));
  assertServerError(() => mapProductionOrder({ ...baseOrder, products: [{ ...baseItem, meters: Infinity }] }));
});

test("mapProductionOrder validates measurement unit", () => {
  const baseItem = { id: "item-1", name: "Item", quantity: 1 };
  const baseOrder = { id: "ord-1", source: "trendhome", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [baseItem], updatedAt: "2026-08-19T00:00:00Z" };

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
  const baseOrder = { id: "ord-1", source: "some-future-brand", orderNumber: "1", productionStageId: "s1", status: "in_progress", version: 1, products: [], updatedAt: "2026-08-19T00:00:00Z" };
  
  const mapped = mapProductionOrder(baseOrder);
  assert.equal(mapped.source, "unknown");
});
