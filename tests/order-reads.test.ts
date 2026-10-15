import assert from "node:assert/strict";
import test from "node:test";
import { latestProductionView } from "../domain/orderReads";
import type { StaffOrder } from "../domain/models";
const order = (id: string, productionVersion: number, productionStageId: string) => ({ id, productionVersion, productionStageId }) as StaffOrder;
test("a delayed read cannot rewind a committed production revision", () => {
  const committed = order("synthetic:1", 3, "material-preparation");
  assert.equal(latestProductionView(committed, order("synthetic:1", 2, "waiting")), committed);
});
test("a higher revision can return a physical piece to cutting; stage order is not the clock", () => {
  const recutting = order("synthetic:1", 12, "material-preparation");
  assert.equal(latestProductionView(order("synthetic:1", 11, "ironing"), recutting), recutting);
  assert.equal(latestProductionView(order("synthetic:other", 99, "packing"), recutting), recutting);
});
