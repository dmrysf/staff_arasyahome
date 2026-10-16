import assert from "node:assert/strict";
import { ro } from "../i18n/messages/ro";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { canReportDefect, faultStatusLabel, formatDecimalMeters, formatFaultMeters, isSupervised, liveMessage, needsMyAction, supervisorViews } from "../domain/faults";
import { StaffServiceError, type QualityCapabilities, type StaffOrder } from "../domain/models";
import { parseStaffRoute } from "../domain/staffRoute";
import { canReturnToCutting } from "../features/exceptions/ReturnToCuttingPanel";
import { getErrorPresentation } from "../services/errors";
import { mapFaultException, mapQualityCapabilities } from "../services/production/httpServices";

const root = path.resolve(import.meta.dirname, "..");
const active: QualityCapabilities = { tailoringSupervisor: false, supervisedSources: [], supervisedFlowActive: true };
const inactive: QualityCapabilities = { tailoringSupervisor: false, supervisedSources: [], supervisedFlowActive: false };
const order = (stage: string, source = "trendhome", extra: Partial<StaffOrder> = {}): StaffOrder => ({
  id: `${source}:9001`, orderNumber: "9001", source, productionStageId: stage, productionVersion: 7, products: [],
  employeeAllowedAction: { id: "complete_stage", label: "Finalizează" }, ...extra,
} as unknown as StaffOrder);
const worker = ["orders.report_fault", "orders.claim", "orders.advance_stage"];
const serverFault = {
  id: "8f866d73-a99c-4f6c-85de-e88f0a629072", number: "EX-000042", type: "tailoring_defect", status: "awaiting_supervisor_review", version: 1,
  order: { id: "trendhome:9001", orderNumber: "9001", source: "trendhome", sourceName: "Trend Home" },
  reason: { key: "cut-meterage", label: "Metraj tăiat greșit" }, lineCount: 2, faultMeters: "15.000", unmeasuredLineCount: 0, arrivalNumber: 1, reworkCycle: null, repeatedError: false,
  detector: { displayName: "Ana Croitorie" }, responsible: { displayName: "Ioana Tăiere" }, reportedAt: "2026-10-12T08:00:00.000Z", acknowledgedAt: null, resolvedAt: null, pendingSince: null, attempts: 0,
  detectedStage: { id: "side-hem", label: "Tiv lateral" }, confirmedReason: null, supervisor: null, receiver: null, physicalCustodian: null,
  responsibilityPosition: null, attribution: null, supervisorReviewedAt: null, returnRequestedAt: null,
  role: "supervisor", actions: { canAcknowledge: false, statesResponsibility: false, canRequestRereview: false, canReview: true, canRequestReturn: false, canRestore: false, canAskManagersAgain: false, canAddNote: true },
};

test("„Raportează o problemă” appears only for the owner at stages 3-12 of the three sources, with the supervised flow active", () => {
  for (const stage of ["workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing", "quality-control"]) {
    assert.equal(canReportDefect(order(stage), worker, active), true, stage);
  }
  for (const source of ["trendyol", "outletperdele"]) assert.equal(canReportDefect(order("side-hem", source), worker, active), true, source);
  assert.equal(canReportDefect(order("side-hem", "b2b"), worker, active), false, "B2B keeps the intake-only return");
  assert.equal(canReportDefect(order("side-hem"), worker, inactive), false, "no supervisor appointed: not offered");
  assert.equal(canReportDefect(order("side-hem"), worker, null), false, "capabilities unknown: not offered");
  for (const stage of ["waiting", "material-preparation", "packing", "delivery"]) assert.equal(canReportDefect(order(stage), worker, active), false, stage);
  assert.equal(canReportDefect(order("side-hem", "trendhome", { employeeAllowedAction: { id: "claim", label: "Preia" } } as Partial<StaffOrder>), worker, active), false, "not the owner");
  assert.equal(canReportDefect(order("side-hem", "trendhome", { productionQuality: { arrivalNumber: 1, reworkCycles: 0, repeatedErrors: false, openException: mapFaultException(serverFault) } }), worker, active), false, "one open case per order");
  assert.equal(canReportDefect(order("side-hem"), ["orders.claim"], active), false, "needs the report permission");
  // The original return stays the only option where the supervised flow does not apply.
  assert.equal(isSupervised(order("workshop-receiving"), active), true);
  assert.equal(isSupervised(order("workshop-receiving", "b2b"), active), false);
  assert.equal(canReturnToCutting(order("workshop-receiving", "b2b"), worker), true);
});

test("supervised cases are mapped with every actor separately; an older API grants no new action", () => {
  const fault = mapFaultException(serverFault);
  assert.equal(fault.type, "tailoring_defect");
  assert.deepEqual(fault.detectedStage, { id: "side-hem", label: "Tiv lateral" });
  assert.equal(fault.role, "supervisor");
  assert.equal(fault.actions.canReview, true);
  assert.equal(needsMyAction(fault), true);
  const legacy = mapFaultException({ ...serverFault, type: undefined, status: "awaiting_acknowledgment", actions: { canAcknowledge: true, canRequestRereview: false }, role: "responsible", detectedStage: undefined });
  assert.equal(legacy.type, "cutting_fault");
  assert.equal(legacy.actions.canReview || legacy.actions.canRestore || legacy.actions.statesResponsibility, false);
  assert.deepEqual(legacy.detectedStage, { id: "workshop-receiving", label: null });
  const received = mapFaultException({ ...serverFault, status: "awaiting_approval", receiver: { displayName: "Radu Tăiere" }, physicalCustodian: { displayName: "Radu Tăiere" }, responsibilityPosition: "not_stated", role: "receiver", actions: {} });
  assert.equal(received.receiver?.displayName, "Radu Tăiere");
  assert.equal(received.responsible.displayName, "Ioana Tăiere");
  assert.equal(received.responsibilityPosition, "not_stated");
  assert.throws(() => mapFaultException({ ...serverFault, status: "invented" }));
});

test("receipt wording never implies admitting a fault; statuses and tabs are Romanian", () => {
  assert.equal(faultStatusLabel({ type: "tailoring_defect", status: "awaiting_acknowledgment" }), "Așteaptă primirea la tăiere");
  assert.equal(faultStatusLabel({ type: "cutting_fault", status: "awaiting_acknowledgment" }), "Așteaptă confirmarea de la tăiere");
  assert.equal(faultStatusLabel({ type: "tailoring_defect", status: "in_supervisor_custody" }), "În grija șefului de croitorie");
  assert.equal(faultStatusLabel({ type: "tailoring_defect", status: "return_rejected" }), "Returnarea la tăiere a fost respinsă");
  assert.deepEqual(supervisorViews.map((view) => view.label), ["De verificat", "În grija mea", "De returnat la tăiere", "Finalizate"]);
  assert.equal(liveMessage("exception.review_required", "9001"), "Problemă nouă raportată la comanda #9001. Verific-o în „Comenzi cu probleme”.");
  assert.match(liveMessage("exception.acknowledgment_required", "9001") ?? "", /Verifică produsul și scanează eticheta/);
  assert.doesNotMatch(liveMessage("exception.acknowledgment_required", "9001") ?? "", /Confirmă eroarea/);
  assert.equal(getErrorPresentation(new StaffServiceError("SUPERVISOR_REQUIRED")).title, "Doar șeful de croitorie");
  const screen = readFileSync(path.join(root, "features/exceptions/ExceptionScreen.tsx"), "utf8");
  assert.match(screen, /t\("exc\.reportedToSupervisor"\)/);
  const actions = readFileSync(path.join(root, "features/exceptions/ExceptionActions.tsx"), "utf8");
  assert.match(actions, /t\("exc\.receipt\.body"\)/);
  assert.match(ro["exc.reportedToSupervisor"], /Problema a fost raportată șefului de croitorie\./);
  assert.match(ro["exc.receipt.body"], /Nu înseamnă că recunoști o greșeală/);
  assert.doesNotMatch(screen, /window\.location|localStorage/);
});

test("capabilities accept only canonical sources; the supervisor route parses", () => {
  assert.deepEqual(mapQualityCapabilities({ tailoringSupervisor: true, supervisedSources: ["trendhome", "evil", 3], supervisedFlowActive: true }), { tailoringSupervisor: true, supervisedSources: ["trendhome"], supervisedFlowActive: true, partialRework: false });
  assert.deepEqual(mapQualityCapabilities({}), { tailoringSupervisor: false, supervisedSources: [], supervisedFlowActive: false, partialRework: false });
  assert.equal(mapQualityCapabilities({ partialRework: true }).partialRework, true);
  assert.equal(parseStaffRoute("/problems").kind, "problems");
});

test("the two-party handover back to tailoring: only the addressed worker confirms, and the screen says the order stays blocked", () => {
  const pending = mapFaultException({ ...serverFault, status: "awaiting_restore_receipt", role: "detector", physicalCustodian: { displayName: "VASILOAIA IULIANA" },
    restoreRecipient: { displayName: "Ana Croitorie" }, restoreRequestedAt: "2026-10-12T09:00:00.000Z", restoreReceivedAt: null, cancelledByRoot: false,
    actions: { canConfirmRestore: true, canAddNote: true } });
  assert.equal(pending.status, "awaiting_restore_receipt");
  assert.equal(pending.actions.canConfirmRestore, true);
  assert.equal(needsMyAction(pending), true, "the worker must act: receive and scan");
  assert.equal(pending.restoreRecipient?.displayName, "Ana Croitorie");
  assert.equal(faultStatusLabel(pending), "Așteaptă primirea înapoi la croitorie");
  const older = mapFaultException({ ...serverFault, status: "awaiting_restore_receipt", actions: {} });
  assert.equal(older.actions.canConfirmRestore, false, "an absent flag never offers the receipt");
  assert.equal(mapFaultException({ ...serverFault, role: "recipient" }).role, "recipient");
  assert.match(liveMessage("exception.restore_receipt_required", "9001") ?? "", /Primește produsul, scanează eticheta și confirmă primirea/);
  const screen = readFileSync(path.join(root, "features/exceptions/ExceptionScreen.tsx"), "utf8");
  assert.match(screen, /t\("exc\.restorePending"/);
  assert.match(ro["exc.restorePending"], /Comanda rămâne blocată până când/);
  assert.match(readFileSync(path.join(root, "features/exceptions/ExceptionActions.tsx"), "utf8"), /service\.receiveRestore\(/);
  assert.equal(getErrorPresentation(new StaffServiceError("RESTORE_RECIPIENT_UNAVAILABLE")).title, "Angajatul nu poate primi comanda");
});

test("unknown meterage is never shown or parsed as a measured zero", () => {
  const unknown = mapFaultException({ ...serverFault, lineCount: 1, faultMeters: null, unmeasuredLineCount: 1, lines: [{ itemId: "i1", lineNumber: 1, name: "Voal", code: null, variant: null, color: null, quantity: 1, meters: null }] });
  assert.equal(unknown.faultMeters, null);
  assert.equal(unknown.lines?.[0]?.meters, null);
  assert.equal(formatFaultMeters(unknown), "1 produs fără metraj");
  assert.equal(formatFaultMeters({ faultMeters: "4.500", unmeasuredLineCount: 2 }), "4,5 m + 2 produse fără metraj");
  assert.equal(formatFaultMeters({ faultMeters: "15.000", unmeasuredLineCount: 0 }), "15 m");
  assert.equal(formatDecimalMeters(null), "fără metraj");
  assert.equal(mapFaultException({ ...serverFault, unmeasuredLineCount: undefined }).unmeasuredLineCount, 0, "an older API (no field) means every line was measured");
  assert.throws(() => mapFaultException({ ...serverFault, unmeasuredLineCount: -1 }));
  assert.throws(() => mapFaultException({ ...serverFault, faultMeters: "abc" }));
});
