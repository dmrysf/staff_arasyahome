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
  for (const code of ["PLAN_BLOCKED_BY_EXCEPTION", "RECONCILIATION_STAGE_INVALID", "RECONCILIATION_EVIDENCE_INVALID", "RECONCILIATION_STAGE_RECORDED", "RECONCILIATION_UNAVAILABLE", "RECONCILIATION_NOT_ALLOWED"]) {
    const message = physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: code }));
    assert.doesNotMatch(message, /Ceva nu a funcționat|Operațiune neconfirmată/, code);
  }
  assert.match(physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: "RECONCILIATION_STAGE_RECORDED" })), /dovezi verificate/);
});
const assistedPiece = (status: string, extra: Record<string, unknown> = {}) => ({ unitId: "u1", itemId: "i", lineNumber: 1, name: "Perdea", code: "P-1", index: 1, count: 2, piece: "1/2", reworkCycle: 1, stageId: "bottom-hem",
  status, version: 0, helper: null, verifiedBy: null, takenAt: null, returnedAt: null, verifiedAt: null, ...extra });
const piecesWith = (assistedCheckpoints: unknown) => ({ total: 2, onOrder: 2, inRework: 0, units: [], tracks: [], allPlansKnown: true, partialRework: false, pieceRouting: true, perdeaAssistedCheckpoints: true, assistedCheckpoints });
test("assisted PERDEA checks from API 2.34.0 map per piece; an older API without them still maps", () => {
  const block = { stageId: "bottom-hem", policyVersion: 2, planRequired: false, required: 2, verified: 1, pieces: [assistedPiece("verified", { version: 3, verifiedBy: { displayName: "Synthetic Hemmer", isMe: true } }), assistedPiece("awaiting_work", { unitId: "u2", index: 2, piece: "2/2" })] };
  const mapped = mapOrderPieces(piecesWith(block));
  assert.equal(mapped.perdeaAssistedCheckpoints, true);
  assert.deepEqual(mapped.assistedCheckpoints?.pieces.map(piece => piece.status), ["verified", "awaiting_work"]);
  assert.equal(mapped.assistedCheckpoints?.verified, 1);
  const older = mapOrderPieces({ total: 1, onOrder: 1, inRework: 0, units: [], tracks: [] });
  assert.equal(older.assistedCheckpoints, null);
  assert.equal(older.perdeaAssistedCheckpoints, false);
});
test("an unknown or incomplete assisted check fails closed instead of being shown as verified", () => {
  const block = (piece: unknown) => ({ stageId: "side-hem", policyVersion: 2, planRequired: false, required: 1, verified: 0, pieces: [piece] });
  assert.throws(() => mapOrderPieces(piecesWith(block(assistedPiece("done")))));
  assert.throws(() => mapOrderPieces(piecesWith(block(assistedPiece("verified", { verifiedBy: { displayName: "x" } })))));
  assert.throws(() => mapOrderPieces(piecesWith({ stageId: "side-hem", policyVersion: 2, required: 1, verified: 0, pieces: [] })));
  const line = { ...evidenceLine(undefined).lines[0], assistedStages: ["bottom-hem", "side-hem"], pieces: [{ id: "u", index: 1, count: 2, trackId: null, assistedCheckpoint: assistedPiece("taken_for_work", { helper: { displayName: "Synthetic Helper", isMe: false } }) }] };
  assert.equal(mapManufacturingPlan({ orderId: "source:synthetic", perdeaAssistedCheckpoints: true, lines: [line] }).lines[0].pieces[0].assistedCheckpoint?.status, "taken_for_work");
  assert.throws(() => mapManufacturingPlan({ orderId: "source:synthetic", lines: [{ ...line, pieces: [{ id: "u", index: 1, count: 2, trackId: null, assistedCheckpoint: assistedPiece("maybe") }] }] }));
});
test("every assisted check refusal has a specific Romanian message", () => {
  for (const code of ["ASSISTED_VERIFICATION_REQUIRED", "ASSISTED_WORK_IN_PROGRESS", "ASSISTED_SELF_VERIFICATION", "ASSISTED_VERIFY_NOT_ALLOWED", "ASSISTED_CHECKPOINT_CHANGED", "ASSISTED_CHECKPOINT_STATE", "ASSISTED_STAGE_MISMATCH", "ASSISTED_HELPER_MISMATCH", "ASSISTED_CHECKPOINT_NOT_APPLICABLE"]) {
    const message = physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: code }));
    assert.doesNotMatch(message, /Ceva nu a funcționat|Operațiune neconfirmată/, code);
  }
  assert.equal(physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: "ASSISTED_VERIFICATION_REQUIRED" })), "Lucrarea auxiliară pentru această perdea nu a fost confirmată.");
});
