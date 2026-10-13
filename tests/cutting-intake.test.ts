import assert from "node:assert/strict";
import { ro } from "../i18n/messages/ro";
import { readFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { canUseCuttingIntake, cuttingIntakeCopy } from "../domain/cutting";
import type { Employee, StaffOrder } from "../domain/models";
import { StaffServiceError } from "../domain/models";
import { initialScannerState, scannerReducer, type ScannerState } from "../features/scanner/machine";
import { createProductionServices } from "../services/production/httpServices";
import { getErrorPresentation } from "../services/errors";

const root = path.resolve(import.meta.dirname, "..");
const cutter: Employee = {
  employeeUuid: "c", displayName: "Ioana Tăiere", username: "ioana", department: "Tăiere", departmentKey: "taiere", role: "employee", status: "active",
  permissions: ["orders.scan", "orders.view_mine", "orders.claim", "orders.advance_stage"], allowedStageIds: ["material-preparation"], applications: ["staff"], mustChangePassword: false, locale: "ro",
};
const order = { id: "trendhome:74001", orderNumber: "74001", source: "trendhome", productionStageId: "material-preparation", productionVersion: 3, products: [], employeeAllowedAction: { id: "complete_stage", label: "Finalizează" } } as unknown as StaffOrder;
const serverOrder = {
  id: "trendhome:74001", source: "trendhome", orderNumber: "74001", productionStageId: "material-preparation", status: "in_progress", version: 3, productionVersion: 3, updatedAt: "2026-10-12T08:00:00.000Z",
  products: [{ id: "p", name: "Draperie Velvet", quantity: 1, measurements: { width: 300, height: 260, unit: "cm" }, meters: 8.4 }], employeeAllowedAction: { id: "complete_stage" },
};

test("only an eligible cutter sends a scan to the cutting intake; the server decides again", () => {
  assert.equal(canUseCuttingIntake(cutter), true);
  assert.equal(canUseCuttingIntake({ ...cutter, departmentKey: "pregatire-material" }), true);
  for (const [label, employee] of Object.entries({
    waitingOnly: { ...cutter, allowedStageIds: ["waiting"] },
    tailor: { ...cutter, departmentKey: "croitorie" },
    root: { ...cutter, isRoot: true },
    temporaryPassword: { ...cutter, mustChangePassword: true },
    inactive: { ...cutter, status: "inactive" as const },
    noScan: { ...cutter, permissions: ["orders.view_mine", "orders.claim", "orders.advance_stage"] },
    noAdvance: { ...cutter, permissions: ["orders.scan", "orders.claim"] },
    dashboardOnly: { ...cutter, applications: ["dashboard"] },
  })) assert.equal(canUseCuttingIntake(employee), false, label);
});

test("one scan, one in-flight intake: success only from the committed answer, never a second scan", () => {
  const decoded = scannerReducer({ status: "scanning", torchSupported: false, torchOn: false }, { type: "CODE_DETECTED", token: "ARASYA:Q1:LABEL" });
  const attempt = { token: "ARASYA:Q1:LABEL", idempotencyKey: "intake-key-0000000001" };
  const taking = scannerReducer(decoded, { type: "INTAKE_STARTED", attempt });
  assert.equal(taking.status, "taking");
  assert.deepEqual(scannerReducer(taking, { type: "CODE_DETECTED", token: "ARASYA:Q1:LABEL" }), taking, "frames decoded meanwhile are ignored");
  assert.deepEqual(scannerReducer(taking, { type: "INTAKE_STARTED", attempt }), taking, "the same request cannot start twice");
  assert.deepEqual(scannerReducer(taking, { type: "SUBMIT", idempotencyKey: "other" }), taking);
  assert.equal(scannerReducer(decoded, { type: "INTAKE_SUCCEEDED", order, outcome: "started" }).status, "decoded", "no success without a request");
  const success = scannerReducer(taking, { type: "INTAKE_SUCCEEDED", order, outcome: "started" });
  assert.deepEqual(success, { status: "success", order, action: "claim", intake: "started" });
  assert.equal(cuttingIntakeCopy.started.title, "Comandă preluată cu succes");
  assert.equal(cuttingIntakeCopy.started.text, "Comanda a fost atribuită contului tău. Etapa de tăiere a început.");
});

test("the multiple-job warning reuses the scanned label and a confirmation is a new intent", () => {
  const taking: ScannerState = { status: "taking", attempt: { token: "ARASYA:Q1:LABEL", idempotencyKey: "intake-key-0000000001" } };
  const confirm = scannerReducer(taking, { type: "INTAKE_CONFIRM_REQUIRED", ownedCount: 2 });
  assert.deepEqual(confirm, { status: "take_confirm", token: "ARASYA:Q1:LABEL", ownedCount: 2, changed: false });
  assert.equal(scannerReducer(confirm, { type: "INTAKE_STARTED", attempt: { token: "ARASYA:Q1:OTHER", idempotencyKey: "intake-key-0000000002" } }).status, "take_confirm", "another label never rides on this confirmation");
  const confirmed = scannerReducer(confirm, { type: "INTAKE_STARTED", attempt: { token: "ARASYA:Q1:LABEL", idempotencyKey: "intake-key-0000000002", confirmedMultiple: true, ownedCount: 2 } });
  assert.equal(confirmed.status, "taking");
  const changed = scannerReducer(confirmed, { type: "INTAKE_CONFIRM_REQUIRED", ownedCount: 3 });
  assert.deepEqual(changed, { status: "take_confirm", token: "ARASYA:Q1:LABEL", ownedCount: 3, changed: true }, "a changed count is shown and asked again");
  const source = readFileSync(path.join(root, "features/scanner/ScannerScreen.tsx"), "utf8");
  const dialog = source.slice(source.indexOf('state.status === "take_confirm" &&'), source.indexOf('data-testid="cutting-intake-success"'));
  assert.doesNotMatch(dialog, /QrCapture/, "the confirmation never asks for a second scan");
  // The words live in the language catalogs (Romanian reference shown here); the dialog uses that message.
  assert.match(dialog, /t\("scanner\.reuseLabel"\)/);
  assert.match(ro["scanner.reuseLabel"], /Nu este nevoie să o scanezi din nou/);
});

test("another stage falls back to the ordinary scan; a transient failure retries the same request only", () => {
  const taking: ScannerState = { status: "taking", attempt: { token: "ARASYA:Q1:LABEL", idempotencyKey: "intake-key-0000000001" } };
  assert.deepEqual(scannerReducer(taking, { type: "INTAKE_NOT_APPLICABLE" }), { status: "resolving", token: "ARASYA:Q1:LABEL" });
  const offline = scannerReducer(taking, { type: "INTAKE_FAILED", error: new StaffServiceError("NETWORK_UNAVAILABLE") });
  assert.equal(offline.status, "error");
  assert.deepEqual(scannerReducer(offline, { type: "RETRY_INTAKE" }), taking, "same key and intent: the server replays a committed intake");
  const refused = scannerReducer(taking, { type: "INTAKE_FAILED", error: new StaffServiceError("ORDER_ALREADY_CLAIMED") });
  assert.deepEqual(scannerReducer(refused, { type: "RETRY_INTAKE" }), refused, "a refusal is never retried automatically");
  assert.equal(refused.status === "error" && refused.recovery, "reload");
  assert.equal(scannerReducer(refused, { type: "RESET" }).status, initialScannerState.status, "the scanner stays usable");
  assert.equal(getErrorPresentation(new StaffServiceError("ORDER_ALREADY_CLAIMED")).message, "Un coleg lucrează deja la această comandă.");
});

test("the intake client sends the label once with its key, maps the committed order and keeps the workload count", async () => {
  const calls: { path: string; init: RequestInit }[] = [];
  const answers = [
    new Response(JSON.stringify({ outcome: "started", order: serverOrder }), { status: 200, headers: { "Content-Type": "application/json" } }),
    new Response(JSON.stringify({ error: { code: "CUTTING_MULTIPLE_CONFIRMATION_REQUIRED", message: "x", details: { ownedCount: 2 } } }), { status: 409, headers: { "Content-Type": "application/json" } }),
    new Response(JSON.stringify({ error: { code: "CUTTING_INTAKE_NOT_APPLICABLE", message: "x" } }), { status: 409, headers: { "Content-Type": "application/json" } }),
    new Response(JSON.stringify({ error: { code: "CUTTING_INTAKE_NOT_ALLOWED", message: "x" } }), { status: 403, headers: { "Content-Type": "application/json" } }),
    new Response(JSON.stringify({ outcome: "claimed_twice", order: serverOrder }), { status: 200, headers: { "Content-Type": "application/json" } }),
  ];
  const fetchImpl = (async (url: string, init: RequestInit) => { calls.push({ path: new URL(url).pathname, init }); return answers.shift()!; }) as typeof fetch;
  const api = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true }).cutting!;
  const result = await api.intake({ qrToken: "ARASYA:Q1:LABEL" }, "stable-intake-key-0001");
  assert.equal(calls[0].path, "/cutting/intake");
  assert.equal(calls[0].init.method, "POST");
  assert.equal(calls[0].init.credentials, "include");
  assert.equal(new Headers(calls[0].init.headers).get("Idempotency-Key"), "stable-intake-key-0001");
  assert.deepEqual(JSON.parse(calls[0].init.body as string), { qrToken: "ARASYA:Q1:LABEL" });
  assert.equal(result.outcome, "started");
  assert.equal(result.order.productionStageId, "material-preparation");
  await assert.rejects(api.intake({ qrToken: "ARASYA:Q1:LABEL", confirmedMultiple: true, ownedCount: 1 }, "stable-intake-key-0002"), (error: StaffServiceError) => error.code === "CONFIRMATION_REQUIRED" && error.details?.ownedCount === 2);
  assert.deepEqual(JSON.parse(calls[1].init.body as string), { qrToken: "ARASYA:Q1:LABEL", confirmedMultiple: true, ownedCount: 1 });
  await assert.rejects(api.intake({ qrToken: "ARASYA:Q1:LABEL" }, "stable-intake-key-0003"), (error: StaffServiceError) => error.code === "CUTTING_INTAKE_NOT_APPLICABLE");
  await assert.rejects(api.intake({ qrToken: "ARASYA:Q1:LABEL" }, "stable-intake-key-0004"), (error: StaffServiceError) => error.code === "CUTTING_INTAKE_NOT_APPLICABLE", "not a cutter: the ordinary scan applies");
  await assert.rejects(api.intake({ qrToken: "ARASYA:Q1:LABEL" }, "stable-intake-key-0005"), (error: StaffServiceError) => error.code === "SERVER_ERROR", "an unknown outcome is never shown as success");
});

test("the scanner wires the intake for eligible cutters only and shows the employee's own name", () => {
  const source = readFileSync(path.join(root, "features/scanner/ScannerScreen.tsx"), "utf8");
  assert.match(source, /intakeEnabled = Boolean\(cutting && employee && canUseCuttingIntake\(employee\)\)/);
  assert.match(source, /if \(!manual && intakeEnabled\)/, "manual codes keep the ordinary lookup");
  assert.match(source, /employee\.displayName/);
  assert.match(readFileSync(path.join(root, "app/StaffApp.tsx"), "utf8"), /<ScannerScreen [^>]*employee=\{session\.employee\}/);
});
