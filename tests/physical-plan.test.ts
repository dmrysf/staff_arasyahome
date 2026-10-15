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
  status, version: 0, markedBy: null, markedAt: null, ...extra });
const statusBlock = (pieces: unknown[], extra: Record<string, unknown> = {}) => ({ stageId: "bottom-hem", policyVersion: 2, informational: true, total: pieces.length, performed: 0, notPerformed: 0, unmarked: pieces.length, pieces, unconfirmedLines: [], ...extra });
const piecesWith = (assistedCheckpoints: unknown) => ({ total: 2, onOrder: 2, inRework: 0, units: [], tracks: [], allPlansKnown: true, partialRework: false, pieceRouting: true, perdeaAssistedCheckpoints: true, assistedCheckpoints });
test("informational PERDEA status from API 2.34.0 maps per piece; an older API without it still maps", () => {
  const block = statusBlock([assistedPiece("performed", { version: 1, markedBy: { displayName: "Synthetic Hemmer", isMe: true }, markedAt: "2026-10-15T10:00:00.000Z" }), assistedPiece("unmarked", { unitId: "u2", index: 2, piece: "2/2" })], { performed: 1, unmarked: 1 });
  const mapped = mapOrderPieces(piecesWith(block));
  assert.equal(mapped.perdeaAssistedCheckpoints, true);
  assert.deepEqual(mapped.assistedCheckpoints?.pieces.map(piece => piece.status), ["performed", "unmarked"]);
  assert.equal(mapped.assistedCheckpoints?.informational, true);
  const lines = mapOrderPieces(piecesWith(statusBlock([], { unconfirmedLines: [{ itemId: "i", lineNumber: 1, name: "Perdea", code: "P-1" }] })));
  assert.equal(lines.assistedCheckpoints?.unconfirmedLines[0].code, "P-1");
  const older = mapOrderPieces({ total: 1, onOrder: 1, inRework: 0, units: [], tracks: [] });
  assert.equal(older.assistedCheckpoints, null);
  assert.equal(older.perdeaAssistedCheckpoints, false);
});
test("an unknown or incomplete PERDEA status fails closed instead of being shown as done", () => {
  assert.throws(() => mapOrderPieces(piecesWith(statusBlock([assistedPiece("verified")]))));
  assert.throws(() => mapOrderPieces(piecesWith(statusBlock([assistedPiece("performed", { markedBy: { displayName: "x" } })]))));
  assert.throws(() => mapOrderPieces(piecesWith({ ...statusBlock([]), informational: false })));
  const line = { ...evidenceLine(undefined).lines[0], assistedStages: ["bottom-hem", "side-hem"], pieces: [{ id: "u", index: 1, count: 2, trackId: null, assistedCheckpoint: assistedPiece("not_performed", { markedBy: { displayName: "Synthetic Hemmer", isMe: false } }) }] };
  assert.equal(mapManufacturingPlan({ orderId: "source:synthetic", perdeaAssistedCheckpoints: true, lines: [line] }).lines[0].pieces[0].assistedCheckpoint?.status, "not_performed");
  assert.throws(() => mapManufacturingPlan({ orderId: "source:synthetic", lines: [{ ...line, pieces: [{ id: "u", index: 1, count: 2, trackId: null, assistedCheckpoint: assistedPiece("taken_for_work") }] }] }));
});
test("every PERDEA status refusal has a specific Romanian message", () => {
  for (const code of ["ASSISTED_MARK_NOT_ALLOWED", "ASSISTED_CHECKPOINT_CHANGED", "ASSISTED_CHECKPOINT_STATE", "ASSISTED_STAGE_MISMATCH", "ASSISTED_CHECKPOINT_NOT_APPLICABLE"]) {
    const message = physicalError(new StaffServiceError("ORDER_CHANGED", undefined, { backendCode: code }));
    assert.doesNotMatch(message, /Ceva nu a funcționat|Operațiune neconfirmată/, code);
  }
});
