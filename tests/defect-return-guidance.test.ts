import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { ro } from "../i18n/messages/ro";
import { scannerExceptionPrompt } from "../domain/faults";
import type { StaffOrder } from "../domain/models";
import { returnScopeWarning } from "../features/exceptions/ExceptionScreen";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError } from "../domain/models";
import { mapFaultException } from "../services/production/httpServices";

const root = path.resolve(import.meta.dirname, "..");
const noActions = { canAcknowledge: false, statesResponsibility: false, canRequestRereview: false, canReview: false, canRequestReturn: false, canRestore: false, canAskManagersAgain: false, canAddNote: false, canConfirmRestore: false };
const serverFault = (status: string, actions: Partial<typeof noActions> = {}, orderScope?: object) => ({
  id: "a3bbc08e-95ec-40d4-95f7-a54f107b0f2c", number: "EX-000001", type: "tailoring_defect", status, version: 3,
  order: { id: "trendyol:4243727602", orderNumber: "11709027615", source: "trendyol", sourceName: "Trendyol" },
  reason: { key: "material-defect", label: "Defect material neobservat" }, lineCount: 1, faultMeters: null, unmeasuredLineCount: 1, arrivalNumber: 1, reworkCycle: null, repeatedError: false,
  detector: { displayName: "KADYROVA ZULFIYA" }, responsible: { displayName: "REJEPOV AYMYRAT" }, reportedAt: "2026-10-14T12:29:28Z", acknowledgedAt: null, resolvedAt: null, pendingSince: null, attempts: 0,
  detectedStage: { id: "ironing", label: "Călcat" }, confirmedReason: null, supervisor: { displayName: "VASILOAIA IULIANA" }, receiver: { displayName: "REJEPOV AYMYRAT" }, physicalCustodian: null,
  responsibilityPosition: null, attribution: null, supervisorReviewedAt: null, returnRequestedAt: "2026-10-14T12:33:08Z",
  role: null, actions: { ...noActions, ...actions }, ...(orderScope ? { orderScope } : {}),
});
const scanned = (fault: ReturnType<typeof serverFault> | null): Pick<StaffOrder, "productionQuality"> => ({
  productionQuality: { arrivalNumber: 1, reworkCycles: 0, repeatedErrors: false, openException: fault === null ? null : mapFaultException(fault) },
} as unknown as Pick<StaffOrder, "productionQuality">);

test("a scanned order with a return addressed to me guides me to the receipt; nobody else gets an action", () => {
  assert.equal(scannerExceptionPrompt(scanned(null)), null, "no open exception: the scanner behaves as before");
  assert.deepEqual(scannerExceptionPrompt(scanned(serverFault("awaiting_acknowledgment", { canAcknowledge: true, statesResponsibility: true }))), { kind: "receipt", exceptionId: "a3bbc08e-95ec-40d4-95f7-a54f107b0f2c", number: "EX-000001", pieces: null });
  assert.equal(scannerExceptionPrompt(scanned(serverFault("awaiting_restore_receipt", { canConfirmRestore: true })))?.kind, "restoreReceipt");
  assert.equal(scannerExceptionPrompt(scanned(serverFault("awaiting_acknowledgment", { canRestore: true, canAddNote: true })))?.kind, "supervisor");
  // An uninvolved employee (or an older API without actions) only learns that the order is blocked.
  assert.equal(scannerExceptionPrompt(scanned(serverFault("awaiting_acknowledgment")))?.kind, "blocked");
  assert.equal(scannerExceptionPrompt(scanned(serverFault("awaiting_restore_receipt", { canAddNote: true })))?.kind, "blocked");
  assert.match(ro["scanner.exception.receipt"], /returnată pentru remediere\. Confirmă primirea produsului\./);
});

test("the scanner never acknowledges by itself: the prompt only navigates to the request screen", () => {
  const source = readFileSync(path.join(root, "features/scanner/ScannerScreen.tsx"), "utf8");
  const prompt = source.slice(source.indexOf("function ExceptionPrompt"));
  assert.match(prompt, /navigate\(`\/exceptions\//);
  assert.doesNotMatch(prompt, /acknowledge|receiveRestore/);
});

test("the whole order moves on approval: the warning says so for several products and for several pieces", () => {
  const map = (scope: object) => mapFaultException(serverFault("in_supervisor_custody", {}, scope));
  assert.equal(returnScopeWarning(map({ orderLineCount: 1, orderQuantity: 1, affectedLineCount: 1, affectedQuantity: 1, wholeOrderMoves: true })), null, "one product, one piece: nothing extra moves");
  assert.match(returnScopeWarning(map({ orderLineCount: 5, orderQuantity: 7, affectedLineCount: 1, affectedQuantity: 1, wholeOrderMoves: true }))!, /comanda are 5 produse \(7 buc\.\).*1 produs.*întreaga comandă/);
  assert.match(returnScopeWarning(map({ orderLineCount: 1, orderQuantity: 2, affectedLineCount: 1, affectedQuantity: 2, wholeOrderMoves: true }))!, /2 bucăți.*doar o parte/);
  assert.equal(returnScopeWarning(mapFaultException(serverFault("in_supervisor_custody"))), null, "an older API without orderScope shows no claim");
});

test("cancelling a pending return to cutting is a ticked, explicit choice with a Romanian explanation", () => {
  const screen = readFileSync(path.join(root, "features/exceptions/ExceptionScreen.tsx"), "utf8");
  assert.match(screen, /cancelsReturn && !confirmed/);
  assert.match(screen, /cancelReturn: true/);
  assert.match(ro["exc.restore.cancelBody"], /NU mai merge la tăiere/);
  assert.match(ro["exc.waitingCutter"], /Abia după scanare managerul primește cererea de aprobare/);
  assert.equal(getErrorPresentation(new StaffServiceError("RETURN_CANCEL_CONFIRMATION_REQUIRED")).title, ro["error.RETURN_CANCEL_CONFIRMATION_REQUIRED.title"]);
});
