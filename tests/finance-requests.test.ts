import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import {
  POLL_LIMIT_MS, canOverseeFinance, canUseFinanceInbox, financeOrderPath, mapDecisionAnswer, mapFinanceTask, mapInbox, mapOrderFinanceRequest, mapOversight, mapOversightDetail,
  maskIban, mapInboxItem, nextPollMs, proformaLabel, requestErrorKey, requestPhase, taskCodeLabel, taskOrderId, type OrderFinanceRequest,
} from "../domain/financeRequests";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { canOpenConfirmareOrder, hasConfirmareWorkspace } from "../domain/confirmare";
import type { Employee } from "../domain/models";
import { CATALOGS, type MessageKey } from "../i18n";

// Finance verification requests in Staff (Staff 2.41.0, API 2.50.0). TEST amounts and invented references only.
const root = path.resolve(import.meta.dirname, "..");
const REQ = "00000000-0000-4000-8000-0000000000a1";
const ME = "00000000-0000-4000-8000-0000000000f1";
const OTHER = "00000000-0000-4000-8000-0000000000f2";

function employee(patch: Partial<Employee>): Employee {
  return {
    employeeUuid: ME, username: "test.fin", displayName: "Test Fin", role: "employee", department: "contabilitate", status: "active", permissions: [],
    applications: ["staff"], mustChangePassword: false, locale: "ro", ...patch,
  } as Employee;
}

const operatorView = (state: string | null, extra: Record<string, unknown> = {}) => ({
  request: state === null ? null : {
    id: REQ, state, version: 2, outcome: null, reasonCode: null, purpose: "advance", ledger: "shop", awaitingSecondCheck: false, assignee: "Test Fin", proforma: { series: "TEST", number: "0001" },
    requestedAt: "2026-10-29T08:00:00Z", updatedAt: "2026-10-29T08:01:00Z", decidedAt: null, ...extra,
  },
  financial: { state: "waiting", attention: null, open: false }, scope: "in_scope", ledger: "shop", canRequest: state === null, canResubmit: state === "needs_clarification", canCancel: state === "assigned",
  pollSeconds: state === "checking" ? 5 : state === "assigned" ? 10 : null,
});

const item = (patch: Record<string, unknown> = {}) => ({
  id: REQ, source: "trendhome", orderNumber: "9001", purpose: "advance", taskCode: null, ledger: "shop", proforma: { series: "TEST", number: "0001" },
  state: "assigned", version: 2, category: "todo", kind: "verify", assignedToMe: true, outcome: null, reasonCode: null, policy: null,
  requestedAt: "2026-10-29T08:00:00Z", updatedAt: "2026-10-29T08:01:00Z", decidedAt: null, ...patch,
});

const live = {
  available: true, revision: `sha256:${"b".repeat(64)}`, readAt: "2026-10-29T08:02:00Z", currency: "RON",
  proforma: { series: "TEST", number: "0001", amount: "300.00", currency: "RON" }, proformaChanged: false, ledger: "shop",
  advance: { percentage: 30, required: "300.00", verified: "0.00", remaining: "300.00", accepted: false }, paymentReference: "TH9001",
  bank: { beneficiary: "TEST SRL", bank: "TEST BANK", ibanMasked: "RO49 •••• •••• 0000", bic: null },
  financial: { state: "waiting", attention: null }, policy: "four_eyes", recordEnabled: true,
  transfers: [{ id: "e1", purpose: "advance", amount: "300.00", reference: "BT TEST 1", valueDate: "2026-10-28", method: "bank_transfer", state: "submitted", decisionCode: null, flags: [],
    submittedBy: { id: OTHER, displayName: "Test Rec" }, submittedAt: "2026-10-28T10:00:00+00:00", decidedBy: null, decidedAt: null }],
  settledElsewhere: false,
};

test("the operator's request view is mapped strictly; an unknown state is refused, never half shown", () => {
  const view = mapOrderFinanceRequest(operatorView("checking"));
  assert.equal(view.request?.state, "checking");
  assert.equal(view.pollSeconds, 5);
  assert.equal(mapOrderFinanceRequest(operatorView(null)).request, null);
  assert.throws(() => mapOrderFinanceRequest(operatorView("approved")), "an unknown state");
  assert.throws(() => mapOrderFinanceRequest({ ...operatorView("assigned"), canRequest: "yes" }), "a non-boolean capability");
  assert.equal(mapOrderFinanceRequest({ ...operatorView(null), created: true }).created, true);
});

test("the operator's screen asks while finance works: 5 s checking, 10 s waiting, never hidden, never after 30 minutes", () => {
  const view = (pollSeconds: number | null) => ({ pollSeconds }) as Pick<OrderFinanceRequest, "pollSeconds">;
  assert.equal(nextPollMs(view(5), false, 0), 5000);
  assert.equal(nextPollMs(view(10), false, 0), 10_000);
  assert.equal(nextPollMs(view(1), false, 0), 5000, "never faster than 5 s");
  assert.equal(nextPollMs(view(600), false, 0), 10_000, "never slower than 10 s while waiting");
  assert.equal(nextPollMs(view(5), true, 0), null, "a hidden tab does not ask");
  assert.equal(nextPollMs(view(null), false, 0), null, "nothing to wait for");
  assert.equal(nextPollMs(view(5), false, POLL_LIMIT_MS), null, "bounded");
  assert.equal(nextPollMs(null, false, 0), null);
});

test("each request state has one operator phase; a recorded four-eyes transfer reads as the second check", () => {
  const phase = (state: string | null, extra: Record<string, unknown> = {}) => requestPhase(mapOrderFinanceRequest(operatorView(state, extra)));
  assert.equal(phase(null), "none");
  assert.equal(phase("requested"), "waitingAssignment");
  assert.equal(phase("assigned"), "assigned");
  assert.equal(phase("checking"), "checking");
  assert.equal(phase("checking", { awaitingSecondCheck: true }), "secondCheck");
  assert.equal(phase("verified", { outcome: "verified_full" }), "verified");
  assert.equal(phase("verified", { outcome: "verified_partial" }), "verifiedPartial");
  assert.equal(phase("not_received", { outcome: "not_received", reasonCode: "not_found" }), "notReceived");
  assert.equal(phase("needs_clarification", { reasonCode: "reference_missing" }), "clarification");
  assert.equal(phase("exception"), "exception");
  assert.equal(phase("cancelled", { reasonCode: "customer_withdrew" }), "cancelled");
  for (const key of ["none", "waitingAssignment", "assigned", "checking", "secondCheck", "verified", "verifiedPartial", "notReceived", "clarification", "exception", "cancelled"]) {
    assert.ok(CATALOGS.ro[`fr.step.${key}` as MessageKey] && CATALOGS.ro[`fr.phase.${key}` as MessageKey], key);
  }
  assert.equal(CATALOGS.ro["fr.step.verifiedBanner"], "Avansul a fost verificat de contabilitate.");
});

test("the inbox, the task, the receipt and the oversight are mapped strictly", () => {
  const inbox = mapInbox({ items: [item(), item({ id: "00000000-0000-4000-8000-0000000000a2", category: "done", state: "verified", outcome: "verified_full", policy: "four_eyes" })],
    counts: { todo: 2, clarification: 0, done: 1 }, sources: ["trendhome", "outletperdele"], limit: 300 });
  assert.equal(inbox.items.length, 2);
  assert.equal(inbox.counts.todo, 2);
  const leaked = mapInbox({ items: [item({ amount: "300.00", currency: "RON", paymentReference: "TH9001" })], counts: { todo: 1, clarification: 0, done: 0 }, sources: [], limit: 300 });
  assert.deepEqual(Object.keys(leaked.items[0] ?? {}).filter((key) => /amount|currency|reference|iban/i.test(key)), [], "an inbox card keeps no money field, even if one is sent");
  assert.throws(() => mapInbox({ items: [item({ category: "archived" })], counts: { todo: 0, clarification: 0, done: 0 }, sources: [], limit: 300 }));
  assert.throws(() => mapInbox({ items: [item({ id: "not-a-uuid" })], counts: { todo: 0, clarification: 0, done: 0 }, sources: [], limit: 300 }));
  const task = mapFinanceTask({ request: item(), live, allowed: { start: true, verify: true, notReceived: true, clarify: true, secondCheck: false, reconcile: false }, me: ME, singleVerification: false });
  assert.equal(task.live.available && task.live.transfers[0]?.state, "submitted");
  assert.equal(mapFinanceTask({ request: item(), live: { available: false, reason: "source_unavailable" }, allowed: { start: false, verify: false, notReceived: true, clarify: true, secondCheck: false, reconcile: false }, me: ME, singleVerification: false }).live.available, false);
  assert.throws(() => mapFinanceTask({ request: item(), live: { ...live, policy: "anyone" }, allowed: task.allowed, me: ME, singleVerification: false }), "an unknown policy");
  const answer = mapDecisionAnswer({ request: item({ state: "checking" }), receipt: { state: "checking", outcome: null, reasonCode: null, policy: "four_eyes", awaitingSecondCheck: true, unknown: false, at: "2026-10-29T08:03:00Z" } });
  assert.equal(answer.receipt.awaitingSecondCheck, true);
  const person = { id: OTHER, displayName: "Test Rec" };
  const over = { ...item(), assignee: person, recordedBy: person, checker: null, decidedBy: null };
  delete (over as Record<string, unknown>).category; delete (over as Record<string, unknown>).kind; delete (over as Record<string, unknown>).assignedToMe; delete (over as Record<string, unknown>).version;
  const oversight = mapOversight({ items: [over], counts: { pending: 1, checking: 0, verified: 0, not_received: 0, attention: 0 }, employees: [person], limit: 300 });
  assert.equal(oversight.items[0]?.recordedBy?.displayName, "Test Rec");
  const detail = mapOversightDetail({ request: over, events: [{ version: 1, action: "requested", actor: person, previousState: null, state: "assigned", outcome: null, reasonCode: null, policy: null, at: "2026-10-29T08:00:00Z" }], transfers: null, currency: "RON" });
  assert.equal(detail.events[0]?.action, "requested");
  assert.throws(() => mapOversightDetail({ request: over, events: [{ version: 1, action: "<b>x</b>", actor: null, previousState: null, state: "assigned", outcome: null, reasonCode: null, policy: null, at: "2026-10-29T08:00:00Z" }], transfers: null }));
});

test("who sees what: payment checks for record/approve/verify, oversight for supervisors, never the Confirmare order", () => {
  const viewer = employee({ financeScopes: { view: ["trendhome"], record: [], approve: [] } });
  const recorder = employee({ financeScopes: { view: ["trendhome"], record: ["trendhome"], approve: [] } });
  const verifier = employee({ financeScopes: { view: [], record: [], approve: [], verify: ["outletperdele"] } });
  const supervisor = employee({ financeScopes: { view: ["trendhome"], record: [], approve: ["trendhome"] }, financeSupervisor: true });
  const onlySupervisor = employee({ financeSupervisor: true });
  assert.equal(canUseFinanceInbox(viewer), false, "a view-only scope has no tasks");
  assert.equal(canUseFinanceInbox(recorder), true);
  assert.equal(canUseFinanceInbox(verifier), true);
  assert.equal(canOverseeFinance(recorder), false);
  assert.equal(canOverseeFinance(supervisor), true);
  assert.equal(canOverseeFinance(employee({ financeSupervisor: true, mustChangePassword: true })), false);
  assert.equal(hasConfirmareWorkspace(onlySupervisor), true, "a supervisor without a scope still has the oversight on Home");
  for (const person of [viewer, recorder, verifier, supervisor]) {
    assert.equal(canOpenConfirmareOrder(person), false);
    assert.equal(canAccessRoute(person, "/confirmare/orders/trendhome%3A9001"), false, "no Confirmare order through a finance scope");
  }
  assert.equal(canAccessRoute(recorder, `/confirmare/finance/requests/${REQ}`), true);
  assert.equal(canAccessRoute(viewer, "/confirmare/finance/requests"), false);
  assert.equal(canAccessRoute(supervisor, "/confirmare/finance/oversight"), true);
  assert.equal(canAccessRoute(recorder, "/confirmare/finance/oversight"), false);
  assert.equal(canAccessRoute(onlySupervisor, `/confirmare/finance/oversight/${REQ}`), true);
  assert.equal(canAccessRoute(onlySupervisor, "/confirmare/finance/requests"), false, "oversight is no task");
});

test("routes carry a request id or an order's global id only", () => {
  assert.deepEqual(parseStaffRoute(`/confirmare/finance/requests/${REQ}`), { kind: "finance-task", pathname: `/confirmare/finance/requests/${REQ}`, requestId: REQ });
  assert.deepEqual(parseStaffRoute(`/confirmare/finance/oversight/${REQ}`), { kind: "finance-oversight-detail", pathname: `/confirmare/finance/oversight/${REQ}`, requestId: REQ });
  assert.equal(parseStaffRoute("/confirmare/finance/requests").kind, "finance-inbox");
  assert.equal(parseStaffRoute("/confirmare/finance/oversight").kind, "finance-oversight");
  assert.equal(parseStaffRoute("/confirmare/finance/requests/123").kind, "invalid");
  assert.deepEqual(parseStaffRoute(financeOrderPath("outletperdele:5001")), { kind: "finance-order", pathname: "/confirmare/finance/orders/outletperdele%3A5001", orderId: "outletperdele:5001" });
  assert.equal(parseStaffRoute("/confirmare/finance/orders/b2b%3A1").kind, "invalid");
});

test("words: masked account, proforma label, known errors", () => {
  assert.equal(maskIban("RO49 AAAA 1B31 0075 9384 0000"), "RO49 •••• •••• 0000");
  assert.ok(!maskIban("RO49AAAA1B31007593840000").includes("1B31"), "the middle of an IBAN is never shown");
  assert.equal(proformaLabel({ series: "TEST", number: "0001" }), "TEST 0001");
  assert.equal(proformaLabel({ series: null, number: "0001" }), "0001");
  assert.equal(requestErrorKey("FINANCE_REQUEST_STATE"), "fr.error.FINANCE_REQUEST_STATE");
  assert.equal(requestErrorKey("SOMETHING_ELSE"), null);
  for (const locale of ["ro", "en", "tr", "si"] as const) {
    assert.ok(CATALOGS[locale]["fr.error.FINANCE_REQUEST_PAYMENT_REFERENCE"], locale);
  }
});

test("the finance screens show no customer, product or order history, and refreshing never acts", () => {
  const screens = readFileSync(path.join(root, "features/confirmare/FinanceRequestScreens.tsx"), "utf8");
  const card = readFileSync(path.join(root, "features/confirmare/FinanceRequestCard.tsx"), "utf8");
  for (const forbidden of ["customer", "phone", "address", "lines", "measurements", "detail(", "sourceOrder"]) assert.ok(!screens.includes(`.${forbidden}`) && !screens.includes(`service.${forbidden}`), forbidden);
  // The loaders read only; every decision goes through a confirmation (fr-confirm) first.
  assert.match(screens, /useMemo\(\(\) => \(signal: AbortSignal\) => service\.financeInbox\(signal\)/);
  assert.match(screens, /useMemo\(\(\) => \(signal: AbortSignal\) => service\.financeTask\(requestId, signal\)/);
  assert.ok(/data-testid="fr-confirm-send"/.test(screens) && /data-testid="fr-step-confirm-send"/.test(card));
  assert.ok(!/setTimeout\([^)]*request(Finance|Task)/.test(card), "the poll never sends a command");
  assert.match(card, /visibilitychange/);
});

test("22 October 2026 scope: the operator's view says whether an order is covered and where its payment is recorded", () => {
  const legacy = mapOrderFinanceRequest({ ...operatorView(null), scope: "before_scope", ledger: "manual", canRequest: false });
  assert.equal(legacy.scope, "before_scope");
  assert.equal(legacy.ledger, "manual");
  assert.throws(() => mapOrderFinanceRequest({ ...operatorView(null), scope: "everything" }), "an unknown scope is refused");
  assert.throws(() => mapOrderFinanceRequest({ ...operatorView(null), ledger: "bank" }), "an unknown ledger is refused");
  const manual = mapOrderFinanceRequest(operatorView("requires_manual_review", { ledger: "manual", outcome: "found_manual" }));
  assert.equal(requestPhase(manual), "manualReview", "a payment found for an order of the old workflow is a manual review, never verified");
  assert.equal(requestPhase(mapOrderFinanceRequest(operatorView("resolved", { purpose: "exception", proforma: null }))), "resolved");
});

test("exception and second-check tasks: no proforma, a task code, the money panel of the order", () => {
  const exception = mapInboxItem(item({ purpose: "exception", taskCode: "additional_payment_due", proforma: null, kind: "exception" }));
  assert.equal(exception.proforma, null);
  assert.equal(proformaLabel(exception.proforma), "—");
  assert.equal(taskOrderId(exception), "trendhome:9001");
  assert.equal(taskCodeLabel("additional_payment_due"), CATALOGS.ro["fr.taskCode.additional_payment_due" as MessageKey]);
  assert.equal(taskCodeLabel("<b>"), "", "an unknown code is never shown as text");
  assert.throws(() => mapInboxItem(item({ purpose: "refund" })), "an unknown purpose is refused");
  assert.equal(mapInboxItem(item({ ledger: "manual" })).ledger, "manual");
  assert.equal(requestErrorKey("FINANCE_REQUEST_OUT_OF_SCOPE"), "fr.error.FINANCE_REQUEST_OUT_OF_SCOPE");
  assert.equal(requestErrorKey("FINANCE_REQUEST_LEDGER_MISMATCH"), "fr.error.FINANCE_REQUEST_LEDGER_MISMATCH");
});

test("no shop-wide finance list: the inbox and the workspace link only the tasks assigned to the employee", () => {
  const screens = readFileSync(path.join(root, "features/confirmare/FinanceRequestScreens.tsx"), "utf8");
  const workspace = readFileSync(path.join(root, "features/confirmare/ConfirmareWorkspace.tsx"), "utf8");
  assert.ok(!screens.includes("legacySecondChecks") && !screens.includes("fr-open-exceptional"), "no list of the shop's pending transfers in the inbox");
  assert.ok(!/inbox && canUseFinance\(employee\) && <button[^>]*\/confirmare\/finance"/.test(workspace), "the finance-only workspace links no shop-wide finance queue");
  const card = readFileSync(path.join(root, "features/confirmare/FinanceRequestCard.tsx"), "utf8");
  assert.match(card, /onlyManual && \(!view \|\| view\.ledger !== "manual" \|\| \(view\.scope !== "in_scope" && !view\.request\)\)\) return null/);
});

test("the advance of an order outside Confirmare opens by its global id only, for Confirmare operators", () => {
  const route = parseStaffRoute("/confirmare/finance/legacy/trendhome%3A64459");
  assert.equal(route.kind, "finance-legacy");
  assert.equal(parseStaffRoute("/confirmare/finance/legacy/b2b%3A1").kind, "invalid");
  assert.equal(parseStaffRoute("/confirmare/finance/legacy/trendhome%3A1%2F2").kind, "invalid");
});

test("owner 2026-10-29: finance records the real smaller amount, never 'not received'; the operator decides on the difference", () => {
  for (const locale of ["ro", "tr", "en", "si"] as const) {
    for (const key of ["fr.form.partial", "fr.form.overRemaining", "fr.confirm.partial"] as const) assert.ok(String(CATALOGS[locale][key] ?? "").includes("{remaining}"), `${locale} ${key}`);
    assert.ok(String(CATALOGS[locale]["fr.step.verifiedPartial"]).includes("{at}"), `${locale} fr.step.verifiedPartial`);
  }
  assert.ok(CATALOGS.ro["fr.form.partial"].includes("nu marcați „Plata nu a fost primită”") && CATALOGS.ro["fr.form.partial"].includes("{gap}"));
  assert.ok(CATALOGS.ro["fr.step.verifiedPartial"].includes("acceptați avansul primit sau solicitați diferența"));
});
