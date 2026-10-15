import assert from "node:assert/strict";
import test from "node:test";
import { isAmbiguous, mapManufacturingPlan, physicalError, planDecimal, materialDecimal } from "../domain/physical";
import { mapOrderPieces } from "../services/production/httpServices";
import { StaffServiceError } from "../domain/models";

test("unknown physical total is accepted without converting commercial quantity to pieces", () => {
  const state = mapOrderPieces({ total: null, onOrder: 0, inRework: 0, units: [], tracks: [], allPlansKnown: false, partialRework: false, pieceRouting: false });
  assert.equal(state.total, null);
  assert.equal(state.partialRework, false);
  assert.equal(state.requiresPieceOutcome, false);
});
test("material values stay unknown or exact; no equal split is computed", () => {
  assert.equal(planDecimal(""), null);
  assert.equal(planDecimal("5"), "5.000");
  assert.equal(planDecimal("3,001"), "3.001");
  assert.throws(() => planDecimal("2.5001"));
  assert.throws(() => planDecimal("-2"));
  assert.equal(materialDecimal("-1,200", true), "-1.200");
  assert.throws(() => materialDecimal("-1.200", false));
});
test("omitted plan capabilities deny confirmation and activation; malformed plan facts are rejected", () => {
  const unknown = mapManufacturingPlan({ orderId: "source:synthetic", lines: [] });
  assert.equal(unknown.canConfirm, false);
  assert.equal(unknown.partialRework, false);
  assert.throws(() => mapManufacturingPlan({ orderId: "source:synthetic", lines: [{ itemId: "x", name: "x", sourcePlanHash: "changed" }] }));
});
test("ambiguous network result allows only committed recovery; terminal packing rejection is understandable", () => {
  assert.equal(isAmbiguous(new StaffServiceError("REQUEST_TIMEOUT")), true);
  assert.equal(isAmbiguous(new StaffServiceError("ORDER_CHANGED")), false);
  assert.match(physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: "ORDER_ROUTE_INCOMPLETE" })), /toate bucățile/);
});
