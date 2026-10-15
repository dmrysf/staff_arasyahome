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
const evidenceLine = (routeEvidence: unknown) => ({ orderId: "source:synthetic", lines: [{ itemId: "i", name: "n", sourcePlanHash: "a".repeat(64), planKnown: true, typeVerified: true, staleAfterSourceChange: false,
  commercialQuantity: 1, lineMeters: null, lineMeasurements: { width: null, height: null, unit: null }, route: [], notApplicableStages: [], proposal: { evidence: [] },
  plan: { revision: 1, typeLocked: false, productType: "draperii", pieceCount: 2, materialTotal: null, pieces: [] }, pieces: [{ id: "u", index: 1, count: 2, trackId: null, routeEvidence }] }] });
test("historical route evidence from API 2.33.0 is validated; an older API without it still maps", () => {
  const evidence = { reworkCycle: 1, ledgerStartStageId: "ironing", preLedgerStages: ["material-preparation"], reconciledStages: [], missingStages: ["material-preparation"], uncertainStages: [],
    candidates: [{ stageId: "material-preparation", activityEventId: "e", employee: "Synthetic Cutter", at: "2026-10-14T10:00:00.000Z" }] };
  assert.deepEqual(mapManufacturingPlan(evidenceLine(evidence)).lines[0].pieces[0].routeEvidence, evidence);
  assert.equal(mapManufacturingPlan(evidenceLine(undefined)).lines[0].pieces[0].routeEvidence, undefined);
  assert.throws(() => mapManufacturingPlan(evidenceLine({ ...evidence, missingStages: "material-preparation" })));
  assert.throws(() => mapManufacturingPlan(evidenceLine({ ...evidence, candidates: [{ stageId: "material-preparation" }] })));
});
test("every historical evidence refusal has a specific Romanian message", () => {
  for (const code of ["RECONCILIATION_STAGE_INVALID", "RECONCILIATION_EVIDENCE_INVALID", "RECONCILIATION_STAGE_RECORDED", "RECONCILIATION_UNAVAILABLE", "RECONCILIATION_NOT_ALLOWED"]) {
    const message = physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: code }));
    assert.doesNotMatch(message, /Ceva nu a funcționat|Operațiune neconfirmată/, code);
  }
  assert.match(physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: "RECONCILIATION_STAGE_RECORDED" })), /dovezi verificate/);
});
