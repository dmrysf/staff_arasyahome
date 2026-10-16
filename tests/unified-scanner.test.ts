import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { chooseScanned, hasMyStep, needsScannedCode, nextStepText } from "../domain/faults";
import { StaffServiceError, type FaultException } from "../domain/models";
import { setActiveLocale } from "../i18n";
import { initialScannerState, scannerReducer, type ScannerState } from "../features/scanner/machine";
import { isProductionCode } from "../features/scanner/ScannerScreen";
import { createProductionServices, mapFaultException } from "../services/production/httpServices";

// The universal scanner (Staff 2.26.1, API 2.34.1): one scan entry point, exception first, one scanned code reused for
// the one action it was scanned for, every decision taken by the server.
const root = path.resolve(import.meta.dirname, "..");
const read = (file: string) => readFileSync(path.join(root, file), "utf8");
const none = { canAcknowledge: false, statesResponsibility: false, canRequestRereview: false, canReview: false, canRequestReturn: false, canRestore: false, canConfirmRestore: false, canAskManagersAgain: false, canAddNote: false };
const fault = (id: string, status: string, actions: Partial<typeof none> = {}, extra: Record<string, unknown> = {}): FaultException => mapFaultException({
  id, number: `EX-${id.slice(0, 6)}`, type: "tailoring_defect", status, version: 4,
  order: { id: "trendyol:11709027615", orderNumber: "11709027615", source: "trendyol", sourceName: "Trendyol" },
  reason: { key: "material-defect", label: "Defect material" }, lineCount: 1, faultMeters: null, unmeasuredLineCount: 1, arrivalNumber: 1, reworkCycle: null, repeatedError: false,
  detector: { displayName: "Kadyrova Test" }, responsible: { displayName: "Rejepov Test" }, reportedAt: "2026-10-14T12:29:00.000Z", acknowledgedAt: null, resolvedAt: null, pendingSince: null,
  detectedStage: { id: "ironing", label: "Călcat" }, confirmedReason: null, supervisor: { displayName: "Vasiloaia Test" }, receiver: { displayName: "Rejepov Test" }, physicalCustodian: { displayName: "Vasiloaia Test" },
  responsibilityPosition: null, attribution: null, returnRequestedAt: null, restoreRecipient: { displayName: "Kadyrova Test" }, restoreRequestedAt: null, restoreReceivedAt: null, cancelledByRoot: false,
  role: "recipient", actions: { ...none, ...actions }, ...extra,
});
const scanned: ScannerState = { status: "decoded", token: "ARASYA:Q1:ABCDEFGHJKLMNPQRSTUVWXYZ23" };

test("exception first: the lookup runs before any intake or claim and returns to the decoded code when nothing is open", () => {
  const checking = scannerReducer(scanned, { type: "CHECK_STARTED" });
  assert.deepEqual(checking, { status: "checking", token: scanned.token });
  assert.equal(scannerReducer(checking, { type: "INTAKE_STARTED", attempt: { token: scanned.token, idempotencyKey: "k" } }), checking, "no intake while the exception lookup runs");
  assert.equal(scannerReducer(checking, { type: "RESOLVE_STARTED" }), checking, "no ordinary verification while the exception lookup runs");
  const back = scannerReducer(checking, { type: "EXCEPTIONS_NONE" });
  assert.deepEqual(back, scanned, "nothing open: the same decoded code takes the ordinary path");
  assert.equal(scannerReducer(back, { type: "INTAKE_STARTED", attempt: { token: scanned.token, idempotencyKey: "k" } }).status, "taking");
  assert.equal(scannerReducer(checking, { type: "EXCEPTIONS_FOUND", items: [], chosenId: null }), checking, "an empty answer is not a request panel");
});

test("a found request is shown in the scanner with the scanned code; a code of another request than the opened one is flagged", () => {
  const receipt = fault("a1", "awaiting_restore_receipt", { canConfirmRestore: true, canAddNote: true });
  const panel = scannerReducer({ status: "checking", token: scanned.token }, { type: "EXCEPTIONS_FOUND", items: [receipt], chosenId: "a1" });
  assert.deepEqual(panel, { status: "exception", token: scanned.token, items: [receipt], chosenId: "a1", mismatch: false });
  const mismatch = scannerReducer({ status: "checking", token: scanned.token }, { type: "EXCEPTIONS_FOUND", items: [], chosenId: null, mismatch: true });
  assert.equal(mismatch.status, "exception");
  assert.equal(scannerReducer(panel, { type: "EXCEPTION_CHOSEN", id: "unknown" }), panel, "only a request of this scan can be chosen");
  const done = scannerReducer(panel, { type: "EXCEPTION_COMMITTED", fault: { ...receipt, status: "restored" } });
  assert.equal(done.status, "exception_done");
  assert.equal(scannerReducer(done, { type: "EXCEPTION_COMMITTED", fault: receipt }), done, "a late second answer never replaces the committed result");
  const refreshed = scannerReducer(panel, { type: "EXCEPTIONS_REFRESHED", items: [] });
  assert.deepEqual(refreshed, { ...panel, items: [], chosenId: null }, "a request gone after a conflict is shown as gone, nothing is resubmitted");
});

test("a failed lookup is an error with a retry of the lookup, never a fall-through to the intake", () => {
  const failed = scannerReducer({ status: "checking", token: scanned.token }, { type: "CHECK_FAILED", error: new StaffServiceError("NETWORK_UNAVAILABLE") });
  assert.equal(failed.status, "error");
  assert.equal(failed.status === "error" ? failed.failedCheck : null, scanned.token);
  assert.deepEqual(scannerReducer(failed, { type: "RETRY_CHECK" }), { status: "checking", token: scanned.token });
  const denied = scannerReducer({ status: "checking", token: scanned.token }, { type: "CHECK_FAILED", error: new StaffServiceError("UNAUTHORIZED_ACTION") });
  assert.equal(scannerReducer(denied, { type: "RETRY_CHECK" }), denied, "only a transient failure is retried");
  assert.equal(scannerReducer(initialScannerState, { type: "CHECK_STARTED" }), initialScannerState);
});

test("which request a scan acts on: the opened one, else the single actionable one; several are never guessed", () => {
  const mine = fault("a1", "awaiting_restore_receipt", { canConfirmRestore: true });
  const waiting = fault("b2", "awaiting_approval", {}, { role: "receiver" });
  const alsoMine = fault("c3", "awaiting_acknowledgment", { canAcknowledge: true }, { role: "receiver" });
  assert.equal(chooseScanned([waiting, mine], null), "a1");
  assert.equal(chooseScanned([waiting], null), "b2", "a single request without a step is shown to explain who acts");
  assert.equal(chooseScanned([mine, alsoMine], null), null, "two actionable requests: the employee chooses");
  assert.equal(chooseScanned([mine, alsoMine], "c3"), "c3");
  assert.equal(chooseScanned([waiting, mine], "zz"), "a1", "an opened request this code does not belong to is not chosen");
  assert.equal(hasMyStep(waiting), false);
  assert.equal(hasMyStep(fault("d4", "awaiting_restore_receipt", { canRestore: true }, { role: "supervisor" })), true, "the supervisor's re-addressing is a step");
  assert.equal(needsScannedCode(mine), true);
  assert.equal(needsScannedCode(fault("e5", "in_supervisor_custody", { canRequestReturn: true })), false, "sending to cutting needs no product scan");
});

test("the next step names who acts, in the account language (EX-000001 shape in Turkish)", () => {
  setActiveLocale("tr");
  try {
    assert.equal(nextStepText(fault("a1", "awaiting_restore_receipt")), "Sıradaki işlem: terzihanede geri teslim alma: Kadyrova Test");
    assert.equal(nextStepText(fault("a1", "awaiting_approval")), "Sıradaki işlem: yönetici onayı");
    assert.equal(nextStepText(fault("a1", "awaiting_acknowledgment")), "Sıradaki işlem: kesimde teslim alma: Rejepov Test");
    assert.equal(nextStepText(fault("a1", "restored")), null);
  } finally { setActiveLocale("ro"); }
  assert.equal(nextStepText(fault("a1", "awaiting_restore_receipt")), "Următorul pas: primirea înapoi la croitorie de către Kadyrova Test");
});

test("only a scanned or typed Arasya label code is evidence; an order number stays a lookup", () => {
  assert.equal(isProductionCode("ARASYA:Q1:ABCDEFGHJKLMNPQRSTUVWXYZ23"), true);
  assert.equal(isProductionCode(" arasya:u1:abc "), true);
  assert.equal(isProductionCode("11709027615"), false);
  assert.equal(isProductionCode("https://example.test/ARASYA:Q1:X"), false);
});

test("the lookup is a POST with the code in the body only; an older API (404) means no lookup", async () => {
  const calls: { url: string; method: string; body: string }[] = [];
  let status = 200;
  const fetchImpl = (async (url: string, init: RequestInit) => {
    calls.push({ url, method: String(init.method), body: String(init.body) });
    return new Response(JSON.stringify(status === 200 ? { items: [] } : { error: { code: "NOT_FOUND", message: "x", requestId: "r" } }), { status, headers: { "Content-Type": "application/json" } });
  }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  assert.deepEqual(await services.exceptions.scan("ARASYA:Q1:SECRETCODE"), []);
  assert.equal(calls[0].method, "POST");
  assert.equal(calls[0].url, "https://api.arasyahome.ro/production-exceptions/scan");
  assert.deepEqual(JSON.parse(calls[0].body), { token: "ARASYA:Q1:SECRETCODE" });
  status = 404;
  assert.equal(await services.exceptions.scan("ARASYA:Q1:SECRETCODE"), null);
  status = 429;
  await assert.rejects(services.exceptions.scan("ARASYA:Q1:SECRETCODE"), (error: unknown) => error instanceof StaffServiceError && error.code === "RATE_LIMITED");
});

test("one camera: request screens never capture a code of their own, and the code is never stored", () => {
  for (const file of ["features/exceptions/ExceptionScreen.tsx", "features/exceptions/ExceptionActions.tsx", "features/scanner/ExceptionScan.tsx"]) assert.doesNotMatch(read(file), /QrCapture/, `${file}: no second QR capture`);
  // Inside the scanner a label is read only for a cutting claim of an order that was found by its number (nothing scanned yet).
  assert.equal(read("features/scanner/ScannerScreen.tsx").match(/<QrCapture /g)?.length, 1);
  for (const file of ["features/exceptions/ExceptionScreen.tsx", "features/exceptions/ExceptionActions.tsx", "features/scanner/ScannerScreen.tsx", "features/scanner/ExceptionScan.tsx"]) {
    const source = read(file);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB|console\.|sendBeacon/, `${file}: the scanned code stays in memory`);
  }
  const actions = read("features/exceptions/ExceptionActions.tsx");
  assert.match(actions, /navigate|onOpenScanner/);
  assert.match(read("features/exceptions/ExceptionScreen.tsx"), /navigate\(`\/scan\?exception=\$\{encodeURIComponent\(fault\.id\)\}`\)/, "the request screen opens the one scanner with the request id only");
  assert.doesNotMatch(read("app/StaffApp.tsx"), /searchParams\.get\("(token|qr|code)"\)|get\("token"\)/, "no code is read from a URL");
  // Every QR action sends the scanned code; the server verifies it again inside the action.
  for (const call of ["service.review(", "service.acknowledge(", "service.restore(", "service.receiveRestore("]) assert.ok(actions.includes(call), call);
  assert.match(actions, /inFlightRef\.current\) return/, "a double tap never sends a second command");
});
