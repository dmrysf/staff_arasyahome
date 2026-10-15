import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { ro } from "../i18n/messages/ro";
import { tr } from "../i18n/messages/tr";
import { en } from "../i18n/messages/en";
import { si } from "../i18n/messages/si";
import { scannerExceptionPrompt } from "../domain/faults";
import { StaffServiceError, type StaffOrder } from "../domain/models";
import { actionableTracks, approvedReturnFor, isPieceLabel, pieceLabelFilename, scanNeedsPieceChoice, scopeCounts, selectionSummary, trackStatusLabel } from "../domain/pieces";
import { partialScopeNotice, returnScopeWarning } from "../features/exceptions/ExceptionScreen";
import { mapFaultException, mapOrderPieces, mapPieceActionResult, mapProductionOrder } from "../services/production/httpServices";
import { getErrorPresentation } from "../services/errors";
import { orderActionBlockedCopy } from "../domain/orderActions";

// The business shape of Trendyol order 11709027615 (EX-000001): one line, quantity 2, one defective curtain.
const root = path.resolve(import.meta.dirname, "..");
const itemId = "58dbdce3-30d8-4a7a-857a-997ab44d0cd1";
const pieceA = "5a8e1c10-0000-5000-8000-000000000001";
const pieceB = "5a8e1c10-0000-5000-8000-000000000002";
const trackId = "7f1c2d3e-0000-4000-8000-000000000001";
const exceptionId = "a3bbc08e-95ec-40d4-95f7-a54f107b0f2c";
const noActions = { canAcknowledge: false, statesResponsibility: false, canRequestRereview: false, canReview: false, canRequestReturn: false, canRestore: false, canAskManagersAgain: false, canAddNote: false, canConfirmRestore: false };
const scope = { orderLineCount: 1, orderQuantity: 2, affectedLineCount: 1, affectedQuantity: 1, movingQuantity: 1, unaffectedQuantity: 1, wholeOrderMoves: false, partial: true, piecesRecorded: true, carrier: "track", trackId };
const serverFault = (status: string, actions: Partial<typeof noActions> = {}) => ({
  id: exceptionId, number: "EX-000002", type: "tailoring_defect", status, version: 3,
  order: { id: "trendyol:4243727602", orderNumber: "11709027615", source: "trendyol", sourceName: "Trendyol" },
  reason: { key: "material-defect", label: "Defect material neobservat" }, lineCount: 1, faultMeters: null, unmeasuredLineCount: 1, arrivalNumber: 1, reworkCycle: null, repeatedError: false,
  detector: { displayName: "KADYROVA ZULFIYA" }, responsible: { displayName: "REJEPOV AYMYRAT" }, reportedAt: "2026-10-14T12:29:28Z", acknowledgedAt: null, resolvedAt: null, pendingSince: null,
  detectedStage: { id: "ironing", label: "Călcat" }, confirmedReason: null, supervisor: { displayName: "VASILOAIA IULIANA" }, receiver: { displayName: "REJEPOV AYMYRAT" }, physicalCustodian: null,
  responsibilityPosition: null, attribution: null, returnRequestedAt: "2026-10-14T12:33:08Z", role: null, actions: { ...noActions, ...actions }, orderScope: scope,
  lines: [{ itemId, lineNumber: 1, name: "Draperie de Textura Nisip Turcesc Ivory", code: "12754-21", variant: null, color: "Crem", quantity: 2, meters: null, affectedQuantity: 1 }],
  affectedPieces: [{ unitId: pieceA, itemId, piece: "1/2", index: 1, count: 2, lineNumber: 1, name: "Draperie de Textura Nisip Turcesc Ivory", code: "12754-21", color: "Crem", reworkCycleAtReport: 1, trackId }],
});
const track = (overrides: Record<string, unknown> = {}) => ({
  id: trackId, number: 1, stage: { id: "material-preparation", label: "Tăiere" }, owner: { displayName: "REJEPOV AYMYRAT", isMe: true }, version: 4, reworkCycle: 2, reworked: true,
  unitIds: [pieceA], quantity: 1, openException: null, waitingForOrder: false, verifyStageId: "ironing", resolutionConfirmed: false, requiresDefectResolution: false,
  nextStageId: "workshop-receiving", changedAt: "2026-10-14T13:00:00Z", allowedAction: { id: "complete_stage" }, blockedReason: null, ...overrides,
});
const pieces = (tracks: unknown[] = [track()]) => ({
  total: 2, onOrder: 1, inRework: 1, mixedProgress: tracks.length > 0,
  units: [{ id: pieceA, itemId, index: 1, count: 2, lineNumber: 1, trackId, reworkCycle: 2, reviewRequired: false }, { id: pieceB, itemId, index: 2, count: 2, lineNumber: 1, trackId: null, reworkCycle: 1, reviewRequired: false }],
  tracks,
});
const serverOrder = (extra: Record<string, unknown> = {}) => ({
  id: "trendyol:4243727602", source: "trendyol", orderNumber: "11709027615", productionStageId: "ironing", status: "in_progress", updatedAt: "2026-10-14T13:00:00Z", version: 9, productionVersion: 17,
  products: [{ id: itemId, name: "Draperie de Textura Nisip Turcesc Ivory", quantity: 2, code: "12754-21", color: "Crem", measurements: { width: 250, height: 260, unit: "cm" } }], ...extra,
});

test("the two curtains map to two distinct pieces; the order stage is the stage of the healthy one", () => {
  const order = mapProductionOrder(serverOrder({ pieces: pieces() }));
  assert.equal(order.pieces?.total, 2);
  assert.notEqual(order.pieces?.units[0].id, order.pieces?.units[1].id);
  assert.deepEqual(order.pieces?.units.map((unit) => [unit.index, unit.count, unit.trackId]), [[1, 2, trackId], [2, 2, null]]);
  assert.equal(order.pieces?.mixedProgress, true, "mixed progress is explicit: not every piece is at ironing");
  assert.equal(order.productionStageId, "ironing");
  assert.equal(order.pieces?.tracks[0].stage.id, "material-preparation");
});

test("track actions fail closed: an unknown action is never offered", () => {
  const mapped = mapOrderPieces(pieces([track({ allowedAction: { id: "force_complete" } })]));
  assert.equal(mapped.tracks[0].allowedAction, null);
  assert.throws(() => mapOrderPieces({ total: 2, onOrder: 1, inRework: 1, units: "x", tracks: [] }), (error) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
  const result = mapPieceActionResult({ orderId: "trendyol:4243727602", trackId, trackStatus: "active", stageId: "workshop-receiving", pieces: pieces() });
  assert.equal(result.stageId, "workshop-receiving");
});

test("the approved return is shown to the cutter only from the committed state, with the real name", () => {
  const order = mapProductionOrder(serverOrder({ pieces: pieces() })) as StaffOrder;
  const approved = approvedReturnFor(order);
  assert.equal(approved?.owner?.displayName, "REJEPOV AYMYRAT");
  assert.equal(approved?.quantity, 1);
  assert.equal(approvedReturnFor(mapProductionOrder(serverOrder({ pieces: pieces([track({ owner: { displayName: "Alt", isMe: false } })]) }))), null, "never for someone else's piece");
  assert.equal(approvedReturnFor(mapProductionOrder(serverOrder({ pieces: pieces([track({ reworked: false })]) }))), null, "never for a piece that was not approved for rework");
  assert.equal(trackStatusLabel(approved!), ro["piece.status.reworkCutting"]);
  assert.equal(ro["piece.approved.title"], "Retur aprobat pentru remediere");
  assert.match(ro["piece.approved.quantity"], /^Cantitate de refăcut: \{count\} buc\.$/);
  assert.match(ro["piece.approved.stage"], /^Etapa curentă: \{stage\}$/);
  assert.match(ro["piece.approved.owner"], /^Responsabil: \{name\}$/);
});

test("status words for each step of a piece's return", () => {
  const status = (value: string) => trackStatusLabel(mapOrderPieces(pieces([track({ openException: { id: exceptionId, number: "EX-000002", status: value } })])).tracks[0]);
  assert.equal(status("awaiting_acknowledgment"), "Retur în așteptarea primirii");
  assert.equal(status("awaiting_approval"), "În așteptarea aprobării managerului");
  assert.equal(status("in_supervisor_custody"), ro["piece.status.custody"]);
  assert.equal(trackStatusLabel(mapOrderPieces(pieces([track({ stage: { id: "packing", label: "Ambalare" }, waitingForOrder: true, owner: null, allowedAction: null })])).tracks[0]), ro["piece.status.waitingForOrder"]);
  assert.equal(ro["piece.currentStage"], "Etapa actuală");
  assert.equal(ro["piece.status.reworkCutting"], "Refacere la tăiere");
});

test("a scan of the order QR with pieces apart asks which piece is in the hand (fail closed); a piece label names it", () => {
  const order = mapProductionOrder(serverOrder({ pieces: pieces() }));
  assert.equal(scanNeedsPieceChoice(order), true);
  const label = mapProductionOrder(serverOrder({ pieces: pieces(), scannedPiece: { unitId: pieceA, trackId } }));
  assert.equal(scanNeedsPieceChoice(label), false);
  assert.deepEqual(actionableTracks(label).map((item) => item.id), [trackId]);
  assert.equal(scanNeedsPieceChoice(mapProductionOrder(serverOrder({ pieces: pieces([]) }))), true, "a multi-piece order QR requires explicit carrier choice");
  assert.equal(isPieceLabel("ARASYA:U1:3C57C6YJ5WMOFEOIEO53S6IA7Y"), true);
  assert.equal(isPieceLabel("ARASYA:Q1:3C57C6YJ5WMOFEOIEO53S6IA7Y"), false, "the order QR is not a piece label");
  const source = readFileSync(path.join(root, "features/scanner/ScannerScreen.tsx"), "utf8");
  assert.match(source, /\(!scanNeedsPieceChoice\(state\.order\) \|\| orderChosen === state\.order\.id\) && !state\.order\.scannedPiece\?\.trackId/, "the order action waits for the explicit choice");
});

test("the cutter scanning the label of the returned piece is guided to that exact receipt", () => {
  const fault = mapFaultException(serverFault("awaiting_acknowledgment", { canAcknowledge: true, statesResponsibility: true }));
  const withTrack = mapProductionOrder(serverOrder({
    pieces: pieces([track({ stage: { id: "workshop-receiving", label: "Recepție" }, allowedAction: null, owner: null, openException: { id: fault.id, number: fault.number, status: fault.status, actions: { ...noActions, canAcknowledge: true }, orderScope: scope } })]),
    scannedPiece: { unitId: pieceA, trackId },
  }));
  assert.deepEqual(scannerExceptionPrompt(withTrack), { kind: "receipt", exceptionId, number: "EX-000002", pieces: 1 });
  const other = mapProductionOrder(serverOrder({ pieces: pieces([track({ openException: { id: fault.id, number: fault.number, status: fault.status, actions: noActions } })]), scannedPiece: { unitId: pieceA, trackId } }));
  assert.equal(scannerExceptionPrompt(other)?.kind, "blocked", "an uninvolved employee only learns that the piece is blocked");
  const healthy = mapProductionOrder(serverOrder({ pieces: pieces([track({ openException: { id: fault.id, number: fault.number, status: fault.status, actions: { ...noActions, canAcknowledge: true } } })]), scannedPiece: { unitId: pieceB, trackId: null } }));
  assert.equal(scannerExceptionPrompt(healthy), null, "the label of the healthy piece never offers the receipt of the defective one");
});

test("the request names 1 defective and 1 healthy piece, and states that only the defective one moves", () => {
  const fault = mapFaultException(serverFault("awaiting_approval"));
  assert.equal(fault.affectedPieces?.[0].piece, "1/2");
  assert.equal(fault.lines?.[0].affectedQuantity, 1);
  assert.deepEqual(scopeCounts(fault), { defective: "1 buc. defectă", healthy: "1 buc. conformă" });
  assert.match(partialScopeNotice(fault) ?? "", /^Doar bucățile din această cerere se întorc la tăiere: 1 buc\. defectă\. Restul comenzii \(1 buc\. conformă\)/);
  assert.equal(returnScopeWarning(fault), null, "no whole-order warning for a partial return");
  const whole = mapFaultException({ ...serverFault("awaiting_approval"), orderScope: { ...scope, affectedQuantity: 2, movingQuantity: 2, unaffectedQuantity: 0, wholeOrderMoves: true, partial: false, carrier: "order", trackId: null } });
  assert.equal(returnScopeWarning(whole), null, "every piece named: nothing beyond them moves");
  const legacy = mapFaultException({ ...serverFault("awaiting_approval"), orderScope: { orderLineCount: 1, orderQuantity: 2, affectedLineCount: 1, affectedQuantity: 2, wholeOrderMoves: true } });
  assert.match(returnScopeWarning(legacy) ?? "", /2 bucăți/, "an older line report still warns that both pieces move");
});

test("the defect report selects exact pieces, k of n, never a silent default", () => {
  const units = mapOrderPieces(pieces([])).units;
  assert.deepEqual(selectionSummary(units, new Set()), { selected: 0, total: 2, partial: false });
  assert.deepEqual(selectionSummary(units, new Set([pieceA])), { selected: 1, total: 2, partial: true });
  assert.equal(ro["ret.pieces.count"], "Cantitate defectă: {count} din {total}");
  const panel = readFileSync(path.join(root, "features/exceptions/ReturnToCuttingPanel.tsx"), "utf8");
  assert.match(panel, /useState<Set<string>>\(new Set\(\)\)/, "nothing is preselected");
  assert.match(panel, /units: chosen\.map\(\(piece\) => piece\.id\), trackId: track\?\.id \?\? null/);
  const piecesPanel = readFileSync(path.join(root, "features/orders/PiecesPanel.tsx"), "utf8");
  assert.match(piecesPanel, /disabled=\{submitting \|\| \(confirm\.track\.requiresDefectResolution && !resolved\)/, "the stage of the defect needs the explicit resolution");
  assert.equal(pieceLabelFilename("11709027615", "1/2"), "ARASYA-PIECE-11709027615-1-2.svg");
});

test("every language carries the piece words; packing waits with a clear reason", () => {
  for (const catalog of [ro, tr, en, si]) {
    for (const key of ["piece.label", "piece.approved.title", "piece.status.pendingReceipt", "piece.status.pendingApproval", "piece.status.reworkCutting", "piece.currentStage", "ret.pieces.count", "orderAction.blocked.pieces_in_rework"] as const) {
      assert.ok(typeof catalog[key] === "string" && catalog[key].length > 0, key);
    }
  }
  assert.match(orderActionBlockedCopy.pieces_in_rework, /refacere/);
  for (const code of ["ORDER_PIECES_IN_REWORK", "DEFECT_RESOLUTION_REQUIRED", "UNIT_LABEL_MISMATCH", "TRACK_CHANGED", "PARTIAL_REWORK_DISABLED"] as const) {
    const presentation = getErrorPresentation(new StaffServiceError(code));
    assert.notEqual(presentation.title, getErrorPresentation(new StaffServiceError("SERVER_ERROR")).title, code);
  }
});

test("every PieceGate reason keeps the order readable with a clear blocked reason in every language", () => {
  // A held piece at any stage (or a gate reason at packing) is a normal server state; an unknown reason used to make the
  // whole order unreadable ("Ceva nu a funcționat") although the server committed the hold.
  const gate = readFileSync(path.join(root, "operations-api/src/Production/PieceGate.php"), "utf8");
  const reasons = [...new Set([...gate.matchAll(/return '([a-z_]+)';/g)].map((match) => match[1]))];
  assert.ok(reasons.includes("pieces_held") && reasons.length >= 6, reasons.join(","));
  for (const reason of reasons) {
    assert.equal(mapProductionOrder(serverOrder({ employeeActionBlockedReason: reason })).employeeActionBlockedReason, reason);
    for (const catalog of [ro, tr, en, si]) assert.ok(String((catalog as Record<string, unknown>)[`orderAction.blocked.${reason}`] ?? "").length > 0, reason);
  }
  assert.match(orderActionBlockedCopy.pieces_held, /reținută fizic/);
});
