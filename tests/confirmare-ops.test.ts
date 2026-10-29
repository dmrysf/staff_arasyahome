import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { ConfirmareApi, ConfirmareDetail } from "../domain/confirmare";
import { confirmareErrorKey } from "../domain/confirmare";
import { cardRefundEntries, verifiableEntries, type FinanceView } from "../domain/confirmareFinance";
import {
  CYCLE_STATES, EFACTURA_STATES, OPS_BLOCKERS, REACTIVATION_REASONS, blockerKey, canRecordBankCheck, canUseShopTasks, canWatchHealth, hoursUntil, mapBankChecks, mapContactCycle,
  mapEfacturaQueue, mapHealth, mapOperationOutcome, mapOperationsState, mapOrderCycle, opsErrorKey,
} from "../domain/confirmareOps";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import type { Employee } from "../domain/models";
import { createProductionServices, mapConfirmareDetail, mapFinanceRead } from "../services/production/httpServices";
import { StaffServiceError } from "../domain/models";
import { ConfirmareCyclePanel } from "../features/confirmare/ConfirmareCyclePanel";
import { CATALOGS } from "../i18n";

// The dual-store operations in Staff (Staff 2.36.0, API 2.46.0). TEST data only.
const ME = "00000000-0000-4000-8000-0000000000a1";
const OTHER = "00000000-0000-4000-8000-0000000000a2";
const employee = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: ME, username: "ops.test", displayName: "Test Ops", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: [], record: [], approve: [] }, invoiceScopes: { issue: [], manage: [] }, ...overrides,
} as Employee);
const shop = {
  features: [], orderStatus: "cancelled",
  awb: { state: "none", number: null, createdAt: null, delivered: false, cod: "503.30", codCheck: null, blockers: [], address: [], pickup: "not_tracked" },
  cardRefund: { available: true, transactions: 2, requests: [{ state: "manual_required", at: "2026-10-25T10:00:00Z", amount: "719.00", transactions: 2, completedAt: null, mailed: false }] },
};
const operations = { available: true, shop, stage: "quality-control", awbStage: true, allowed: { reactivate: true, awb: true, cardRefund: true, cardRefundRecord: false, cardRefundVerify: false }, reactivations: [] };
const cycleRaw = {
  calls: { noAnswer: 3, max: 3, complete: true, since: null },
  proforma: { cycle: 1, state: "waiting_bank_check", origin: "no_answer", sentAt: "2026-10-20T08:00:00Z", reminder1DueAt: "2026-10-21T08:00:00Z", reminder1At: "2026-10-21T08:05:00Z", reminder2DueAt: "2026-10-23T08:00:00Z",
    reminder2At: "2026-10-23T08:02:00Z", expiresAt: "2026-10-25T08:00:00Z", bankCheck: false, closedReason: null, lastError: null, closedAt: null },
  history: [],
};

test("operations answers are read strictly, PHP's empty maps included", () => {
  const state = mapOperationsState(operations);
  assert.deepEqual(state.shop?.features, {}, "an empty PHP map arrives as [] and reads as no switch");
  assert.equal(state.shop?.awb.cod, "503.30");
  assert.equal(state.shop?.cardRefund.requests[0]?.state, "manual_required");
  assert.throws(() => mapOperationsState({ ...operations, shop: { ...shop, awb: { ...shop.awb, cod: "503.3" } } }), "a malformed amount");
  assert.throws(() => mapOperationsState({ ...operations, allowed: { ...operations.allowed, awb: "yes" } }), "a permission is a boolean");
  assert.throws(() => mapOperationsState({ ...operations, shop: { ...shop, awb: { ...shop.awb, blockers: ["Robert'); DROP"] } } }), "a blocker is a code");
  const pending = mapOperationOutcome({ state: "pending", idempotencyKey: "ops-key-000000000001", reachable: true, shop: "absent" });
  assert.equal(pending.shop, null, "a reconciliation names the shop journal state as a string, not a shop state");
  assert.equal(mapOperationOutcome({ state: "applied", idempotencyKey: "ops-key-000000000002", result: "manual_required", shop }).shop?.cardRefund.transactions, 2);
  assert.throws(() => mapOperationOutcome({ state: "done", idempotencyKey: "ops-key-000000000003" }), "an unknown state");
  const checks = mapBankChecks({ items: [], waiting: [], sources: ["trendhome"], canRecord: [] });
  assert.deepEqual(checks.waiting, {}, "no proforma waiting arrives as []");
  assert.throws(() => mapBankChecks({ items: [], waiting: { b2b: 1 }, sources: [], canRecord: [] }), "only the two shops");
  const queue = mapEfacturaQueue({ items: [{ id: "00000000-0000-4000-9000-000000000001", globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", issuer: "TEST", issuedAt: "2026-10-23T10:00:00Z",
    dueAt: "2026-10-30T21:59:59Z", state: "open", version: 1, recordedAt: null, overdue: false, next: ["sent", "accepted", "rejected"] }], overdue: 0, managed: [] });
  assert.deepEqual(queue.items[0]?.next, ["sent", "accepted", "rejected"]);
  const health = mapHealth({ generatedAt: "2026-10-25T10:00:00Z", sources: { trendhome: { newOrdersToday: 4, confirmare: { pending: 2 } } } });
  assert.equal(Object.keys(health.sources).join(), "trendhome");
  assert.throws(() => mapHealth({ generatedAt: "2026-10-25T10:00:00Z", sources: { other: {} } }), "only the two shops");
});

test("the Confirmare detail carries the call and proforma cycle; an older API has none", () => {
  const cycle = mapOrderCycle(cycleRaw);
  assert.equal(cycle?.calls?.complete, true);
  assert.equal(cycle?.proforma?.state, "waiting_bank_check");
  assert.equal(mapOrderCycle(null), null);
  assert.throws(() => mapOrderCycle({ ...cycleRaw, proforma: { ...cycleRaw.proforma, state: "expired_soon" } }), "an unknown cycle state");
  assert.deepEqual(mapContactCycle({ cycle: 1, state: "running" }), { started: true, state: "running", reason: null });
  assert.deepEqual(mapContactCycle({ state: "not_started", reason: "calls_remaining" }), { started: false, state: "not_started", reason: "calls_remaining" });
  assert.equal(mapContactCycle(undefined), null, "an older API answers without proformaCycle");
  assert.equal(hoursUntil("2026-10-25T12:00:00Z", Date.parse("2026-10-25T10:00:00Z")), 2);
  assert.equal(hoursUntil(null), null);
});

test("the cycle panel shows the call counter, the unconfirmed proforma and the bank check wait", () => {
  const detail = { globalOrderId: "trendhome:1234", cycle: mapOrderCycle(cycleRaw) } as unknown as ConfirmareDetail;
  const html = renderToStaticMarkup(createElement(ConfirmareCyclePanel, { detail, service: {} as ConfirmareApi, canRun: true, onChanged: () => undefined }));
  assert.match(html, /Apeluri fără răspuns: 3\/3/);
  assert.match(html, /Neconfirmată telefonic/);
  assert.match(html, /data-testid="cf-cycle-bank"/);
  assert.match(html, /data-testid="cf-cycle-advance"/);
  const none = renderToStaticMarkup(createElement(ConfirmareCyclePanel, { detail: { ...detail, cycle: null }, service: {} as ConfirmareApi, canRun: true, onChanged: () => undefined }));
  assert.equal(none, "", "an older API shows nothing");
  const readOnly = renderToStaticMarkup(createElement(ConfirmareCyclePanel, { detail, service: {} as ConfirmareApi, canRun: false, onChanged: () => undefined }));
  assert.doesNotMatch(readOnly, /cf-cycle-advance/, "only the operator of the order or a supervisor runs the cycle");
});

test("a NETOPIA full card refund keeps the finance read readable and is verified only in the operations panel", () => {
  const entry = (id: string, by: string, flags: string[]) => ({ id, kind: "refund_out", purpose: "refund", amount: "719.00", reference: "NTP TEST 1", valueDate: "2026-10-25", state: "submitted", submittedBy: by,
    submittedAt: "2026-10-25T10:00:00Z", decidedBy: null, decidedAt: null, decisionCode: null, flags });
  const summary = {
    readAt: "2026-10-25T10:00:00Z", revision: `sha256:${"a".repeat(64)}`, currency: "RON",
    order: { id: "1234", number: "1234", status: "cancelled", modifiedAt: "2026-10-25T09:30:00Z", method: "netopiapayments", methodTitle: "Card online", cod: false },
    totals: { current: "719.00", original: "719.00", refunded: "0.00" }, received: { online: "719.00", advance: null, transfers: "0.00", net: "719.00" },
    payment: { onlineVerified: true, paidRecorded: true, evidence: "netopia_ipn" }, advance: null, balance: { outstanding: "0.00", refundLiability: "0.00", acknowledged: false },
    financial: { state: "cleared", basis: "online_payment", attention: null },
    entries: [entry("00000000-0000-4000-9000-000000000001", OTHER, ["card_full_refund"]), entry("00000000-0000-4000-9000-000000000002", OTHER, []), entry("00000000-0000-4000-9000-000000000003", ME, ["card_full_refund"])],
    capabilities: { record: true, refunds: "manual", electronicRefund: false },
  };
  const read = mapFinanceRead({ available: true, role: "finance", summary, production: { stageId: "waiting", handedOver: false, refundEligible: true }, allowedActions: ["decide_refund"], nextAction: "verify_refund", actionOpen: false, timeline: [], employees: {}, me: ME });
  assert.ok(read.available, "a card refund entry (YD SOFT 2.20.0) does not break the finance read");
  if (!read.available) return;
  const view = read as FinanceView;
  assert.deepEqual(verifiableEntries(view, "refund_out").map((e) => e.id), ["00000000-0000-4000-9000-000000000002"], "the finance refund decision never offers a card refund entry (the shop refuses it)");
  assert.deepEqual(cardRefundEntries(view).map((e) => e.id), ["00000000-0000-4000-9000-000000000001"], "four eyes: never the employee's own record");
  assert.throws(() => mapFinanceRead({ available: true, role: "finance", summary: { ...summary, entries: [entry("00000000-0000-4000-9000-000000000004", OTHER, ["refund_now"])] }, production: { stageId: "waiting", handedOver: false, refundEligible: true }, allowedActions: [], nextAction: "verify_refund", actionOpen: false, timeline: [], employees: {}, me: ME }), "an unknown flag is still refused");
});

test("routes and access: AWB and e-Factura for shop employees, health for the watchers, bank check for finance", () => {
  for (const pathname of ["/confirmare/awb", "/confirmare/efactura", "/confirmare/health"]) assert.equal(parseStaffRoute(pathname).pathname, pathname);
  const shopEmployee = employee({ invoiceScopes: { issue: ["trendhome"], manage: [] } });
  assert.equal(canUseShopTasks(shopEmployee), true);
  assert.equal(canAccessRoute(shopEmployee, "/confirmare/awb"), true);
  assert.equal(canAccessRoute(shopEmployee, "/confirmare/efactura"), true);
  assert.equal(canAccessRoute(shopEmployee, "/confirmare/health"), false, "an invoice issuer does not watch the health");
  assert.equal(canAccessRoute(shopEmployee, "/confirmare"), false, "a shop employee is never in the Confirmare pool");
  const operator = employee({ confirmareScopes: { operate: ["trendhome"], supervise: [] } });
  assert.equal(canAccessRoute(operator, "/confirmare/awb"), false, "an operator makes no AWB");
  assert.equal(canWatchHealth(operator), false);
  assert.equal(canWatchHealth(employee({ confirmareScopes: { operate: [], supervise: ["outletperdele"] } })), true);
  assert.equal(canWatchHealth(employee({ financeScopes: { view: [], record: [], approve: ["trendhome"] } })), true);
  assert.equal(canRecordBankCheck(employee({ financeScopes: { view: ["trendhome"], record: [], approve: [] } })), false, "viewing is not recording");
  assert.equal(canRecordBankCheck(employee({ financeScopes: { view: [], record: ["trendhome"], approve: [] } })), true);
  assert.equal(canUseShopTasks(employee({ invoiceScopes: { issue: ["trendhome"], manage: [] }, mustChangePassword: true })), false, "a first login first changes the password");
});

test("every refusal and every dynamic text exists in all four languages", () => {
  assert.equal(opsErrorKey("CONFIRMARE_OPERATION_REFUSED", "cod_changed"), "cf.ops.blocker.cod_changed");
  assert.equal(opsErrorKey("CONFIRMARE_OPERATION_NOT_ALLOWED", "something_new"), "cf.ops.blocker.other");
  assert.equal(opsErrorKey("CONFIRMARE_CYCLE_BUSY", undefined), "cf.ops.error.CONFIRMARE_CYCLE_BUSY");
  assert.equal(opsErrorKey("SOMETHING_ELSE", undefined), null);
  assert.equal(confirmareErrorKey("CONFIRMARE_CALL_TOO_SOON"), null, "the 10-minute gap between calls is removed (owner decision 2026-10-25)");
  assert.equal(confirmareErrorKey("CONFIRMARE_CALL_CYCLE_COMPLETE"), "cf.error.CONFIRMARE_CALL_CYCLE_COMPLETE");
  const keys = [
    ...OPS_BLOCKERS.map((code) => blockerKey(code)), "cf.ops.blocker.other",
    ...["CONFIRMARE_OPERATION_DENIED", "CONFIRMARE_OPERATION_DISABLED", "CONFIRMARE_OPERATION_NOT_FOUND", "CONFIRMARE_AWB_LABEL_UNAVAILABLE", "CONFIRMARE_CYCLE_NONE", "CONFIRMARE_CYCLE_BUSY", "CONFIRMARE_CYCLE_SCHEMA_MISSING",
      "CONFIRMARE_DENIED", "CONFIRMARE_FINANCE_DENIED", "CONFIRMARE_HEALTH_DENIED", "EFACTURA_TASK_CHANGED", "EFACTURA_TRANSITION_REFUSED", "EFACTURA_TASK_NOT_FOUND", "CONFIRMARE_SOURCE_UNAVAILABLE",
      "CONFIRMARE_AUDIT_UNAVAILABLE", "IDEMPOTENCY_CONFLICT", "CONFIRMARE_NOT_FOUND", "VALIDATION_FAILED"].map((code) => opsErrorKey(code, undefined)),
    ...CYCLE_STATES.map((state) => `cf.cycle.state.${state}`), ...EFACTURA_STATES.map((state) => `cf.efactura.state.${state}`), ...["sent", "accepted", "rejected", "resolved"].map((state) => `cf.efactura.mark.${state}`),
    ...REACTIVATION_REASONS.map((reason) => `cf.ops.reactivate.reason.${reason}`), ...["applied", "completed", "other"].map((state) => `cf.ops.reactivate.state.${state}`),
    ...["manual_required", "completed", "unknown"].map((state) => `cf.ops.refund.state.${state}`), "cf.ops.awb.state.none", "cf.ops.awb.state.unknown", "cf.ops.address.taskEntry", "cf.ops.address.doneEntry",
    ...["quality-control", "packing", "delivery", "other"].map((stage) => `cf.awbq.stage.${stage}`), "cf.fin.flag.card_full_refund",
  ];
  for (const key of keys) {
    assert.ok(key, "every code maps to a key");
    for (const locale of ["ro", "en", "tr", "si"] as const) assert.ok((CATALOGS[locale] as Record<string, unknown>)[key as string], `${locale}: ${key}`);
  }
});

test("an API 2.46.0 detail maps its cycle; an API 2.45.0 detail has none", () => {
  const base = {
    globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", status: "pending", version: 3, workflowCohort: "confirmare", openedAt: "2026-10-22T08:00:00Z", submittedAt: "2026-10-22T07:59:00Z",
    productionStageId: "waiting", commerceStatus: "pending", commerceClosed: false, payment: { state: "cod" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null,
    productionCleared: false, contact: null, assignment: null, decision: null, review: { fingerprint: "b".repeat(64), lineIds: [], pricingAvailable: true, callRecorded: false, contactAvailable: true }, lines: [], contacts: [],
    customer: { visible: false, phoneAvailable: false }, source: { commerceStatusLabel: null, changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: false, missing: ["approval"] }, events: [],
  };
  assert.equal(mapConfirmareDetail(base).cycle, null);
  assert.equal(mapConfirmareDetail({ ...base, cycle: cycleRaw }).cycle?.calls?.noAnswer, 3);
});

test("HTTP: the operations routes, one Idempotency-Key per command, the shop refusal reason, the AWB label as a PDF", async () => {
  const calls: { path: string; init: RequestInit }[] = [];
  const answers: Response[] = [];
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  const fetchImpl = (async (url: string, init: RequestInit) => { calls.push({ path: new URL(url).pathname + new URL(url).search, init }); return answers.shift()!; }) as typeof fetch;
  const api = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true }).confirmare!;
  answers.push(json(200, operations));
  assert.equal((await api.operations("trendhome:1001")).awbStage, true);
  assert.equal(calls.at(-1)!.path, "/confirmare/orders/trendhome%3A1001/operations");
  answers.push(json(200, { state: "applied", idempotencyKey: "ops-key-0123456789ab", result: "created", shop: { ...shop, awb: { ...shop.awb, state: "created", number: "2150900000001" } } }));
  const made = await api.operate("trendhome:1001", "awb", { parcels: 2, expectedCod: "503.30" }, "ops-key-0123456789ab");
  assert.equal(made.shop?.awb.number, "2150900000001");
  assert.equal(calls.at(-1)!.path, "/confirmare/orders/trendhome%3A1001/awb");
  assert.equal(new Headers(calls.at(-1)!.init.headers).get("Idempotency-Key"), "ops-key-0123456789ab");
  assert.deepEqual(JSON.parse(String(calls.at(-1)!.init.body)), { parcels: 2, expectedCod: "503.30" });
  answers.push(json(409, { error: { code: "CONFIRMARE_OPERATION_REFUSED", message: "x", details: { reason: "cod_changed", blockers: ["cod_changed"], shop: { awb: { cod: "999.00" } } } } }));
  const refused = await api.operate("trendhome:1001", "card-refund", {}, "ops-key-0123456789ac").then(() => null, (error: unknown) => error as StaffServiceError);
  assert.ok(refused instanceof StaffServiceError);
  assert.equal(opsErrorKey(refused.details?.backendCode as string, refused.details?.editReason), "cf.ops.blocker.cod_changed");
  assert.equal(JSON.stringify(refused.details).includes("999.00"), false, "the shop state of a refusal is not kept");
  answers.push(json(200, { state: "unknown", idempotencyKey: "ops-key-0123456789ac" }));
  await api.operationsReconcile("trendhome:1001", "ops-key-0123456789ac");
  assert.deepEqual(JSON.parse(String(calls.at(-1)!.init.body)), { idempotencyKey: "ops-key-0123456789ac" });
  answers.push(new Response("%PDF-1.4 TEST", { status: 200, headers: { "Content-Type": "application/pdf" } }));
  const label = await api.awbLabel("trendhome:1001");
  assert.equal(await label.text(), "%PDF-1.4 TEST");
  assert.equal(calls.at(-1)!.path, "/confirmare/orders/trendhome%3A1001/awb/label");
  answers.push(json(200, { check: { source: "outletperdele", coversUntil: "2026-10-25T10:00:00Z", recordedAt: "2026-10-25T10:00:00Z", by: "TEST" } }));
  await api.recordBankCheck("outletperdele", "bank-key-0123456789");
  assert.deepEqual(JSON.parse(String(calls.at(-1)!.init.body)), { source: "outletperdele" }, "the check covers now: the browser never chooses the instant");
  answers.push(json(200, { items: [], overdue: 0, managed: [] }));
  await api.efactura("trendhome", "attention");
  assert.equal(calls.at(-1)!.path, "/confirmare/efactura?source=trendhome&state=attention");
  answers.push(json(200, { proformaCycle: cycleRaw.proforma }));
  assert.equal((await api.advanceCycle("trendhome:1001")).state, "waiting_bank_check");
  assert.equal(calls.at(-1)!.path, "/confirmare/orders/trendhome%3A1001/cycle/advance");
  answers.push(json(200, { proformaCycle: { ...cycleRaw.proforma, cycle: 2, state: "mail_pending", lastError: "rate_limited" } }));
  const retried = await api.retryCycle("trendhome:1001");
  assert.equal(calls.at(-1)!.path, "/confirmare/orders/trendhome%3A1001/cycle/retry");
  assert.deepEqual([retried.cycle, retried.state, retried.lastError], [2, "mail_pending", "rate_limited"], "R1/R3: a started-again cycle and the rate-limit wait are read");
  answers.push(json(200, { contact: { attempt: 3, outcome: "no_answer", by: "Op", at: "2026-10-22T09:00:00Z", current: true }, item: {
    globalOrderId: "trendhome:1001", orderNumber: "1001", sourceKey: "trendhome", status: "pending", version: 4, workflowCohort: "confirmare", openedAt: "2026-10-22T08:00:00Z", submittedAt: null, productionStageId: "waiting",
    commerceStatus: "pending", commerceClosed: false, payment: { state: "cod" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
    contact: null, assignment: null, decision: null }, proformaCycle: { ...cycleRaw.proforma, state: "running" } }));
  const call = await api.contact("trendhome:1001", { expectedVersion: 3, outcome: "no_answer", reviewFingerprint: "b".repeat(64) }, "key-0123456789abcdef");
  assert.deepEqual(call.proformaCycle, { started: true, state: "running", reason: null }, "the third unanswered call says the proforma cycle started");
});

test("owner 2026-10-29: the AWB collecting another amount stops the label until the employee states the FAN Courier correction", () => {
  const awb = { ...shop.awb, state: "created", number: "2150900000001", cod: "493.30", blockers: ["awb_exists", "cod_mismatch"] };
  const mismatch = mapOperationsState({ ...operations, shop: { ...shop, awb: { ...awb, codCheck: { state: "mismatch", awb: "2150900000001", awbCod: "503.30", expected: "493.30", status: null, corrected: false } } } });
  assert.deepEqual(mismatch.shop?.awb.codCheck, { state: "mismatch", awbCod: "503.30", expected: "493.30", corrected: false });
  assert.ok(mismatch.shop?.awb.blockers.includes("cod_mismatch"));
  const fixed = mapOperationsState({ ...operations, shop: { ...shop, awb: { ...awb, blockers: ["awb_exists"], codCheck: { state: "match", awb: "2150900000001", awbCod: "493.30", expected: "493.30", status: null, corrected: true } } } });
  assert.equal(fixed.shop?.awb.codCheck?.corrected, true, "the amount compared is the employee's statement");
  const older = mapOperationsState({ ...operations, shop: { ...shop, awb: { ...awb, blockers: ["awb_exists"], codCheck: { state: "match", awb: "2150900000001", awbCod: "493.30", expected: "493.30", status: null } } } });
  assert.equal(older.shop?.awb.codCheck?.corrected, false, "an older shop states nothing");
  assert.throws(() => mapOperationsState({ ...operations, shop: { ...shop, awb: { ...awb, codCheck: { state: "match", awbCod: "493.30", expected: "493.30", corrected: "da" } } } }), "corrected is a boolean");
  assert.equal(blockerKey("cod_mismatch"), "cf.ops.blocker.cod_mismatch");
  assert.equal(blockerKey("cod_not_mismatched"), "cf.ops.blocker.cod_not_mismatched");
  assert.equal(opsErrorKey("CONFIRMARE_AWB_COD_MISMATCH", undefined), "cf.ops.error.CONFIRMARE_AWB_COD_MISMATCH");
  for (const locale of ["ro", "tr", "en", "si"] as const) {
    for (const key of ["cf.ops.awb.corrected", "cf.ops.awb.labelBlocked", "cf.ops.cod.note", "cf.ops.cod.check", "cf.ops.cod.confirm", "cf.ops.cod.done", "cf.ops.blocker.cod_mismatch", "cf.ops.blocker.cod_not_mismatched", "cf.ops.error.CONFIRMARE_AWB_COD_MISMATCH"] as const) {
      assert.ok(String(CATALOGS[locale][key] ?? "").length > 5, `${locale} ${key}`);
    }
  }
  assert.ok(CATALOGS.ro["cf.ops.cod.check"].includes("{amount}") && CATALOGS.ro["cf.ops.awb.corrected"].includes("nu confirmarea FAN Courier"));
});
