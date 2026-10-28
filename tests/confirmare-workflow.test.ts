import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement, createRef, type ReactElement, type ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { ConfirmareContact, ConfirmareDetail } from "../domain/confirmare";
import type { InvoiceRead } from "../domain/confirmareInvoice";
import type { ShopRead } from "../domain/confirmareShop";
import { invoiceFact, resolveWorkflow, shopFact, STEP_SUBTASKS, WORKFLOW_STEPS, type ConfirmareWorkflow, type InvoiceFact, type ShopFact, type WorkflowInput } from "../domain/confirmareWorkflow";
import { ProductReview, StepCard, StepProgress, SubtaskList, TaskBrief, VerificationDetails } from "../features/confirmare/ConfirmareGuided";
import { CATALOGS, I18nProvider, SUPPORTED_LOCALES, type Locale, type MessageKey } from "../i18n";

// Staff 2.40.0: the guided Confirmare order. The five steps are a presentation of the server's facts: these tests build
// the facts of real situations and check which step is open, which task it asks for, who must act and that nothing is
// shown as done before the server (or the operator's own unsent review) proves it.

const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-000000000001";
const OTHER = "00000000-0000-4000-8000-000000000002";
const FP = "a".repeat(64);
const line = (n: number) => ({ lineId: `L${n}`, lineNumber: n, name: `DRAPERIE TEST ${n}`, productCode: `P${n}`, variant: null, color: "Alb", width: 2, height: 2.6, unit: "m", meters: null, quantity: 1, options: [], notes: null, confirmed: false });
const contact = (outcome: ConfirmareContact["outcome"], current = true, attempt = 1): ConfirmareContact => ({ attempt, outcome, by: "Operator Test", at: `2026-10-28T09:0${attempt}:00Z`, current });

/** A pending, assigned COD order of three products, nothing done yet. */
const detail = (over: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  globalOrderId: "trendhome:2001", orderNumber: "2001", sourceKey: "trendhome", status: "pending", version: 4, workflowCohort: "confirmare",
  openedAt: "2026-10-28T08:00:00Z", submittedAt: "2026-10-28T07:59:00Z", productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false,
  payment: { state: "waiting" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: { employeeId: ME, displayName: "Operator Test", at: "2026-10-28T08:01:00Z", kind: "auto" }, decision: null,
  review: { fingerprint: FP, lineIds: ["L1", "L2", "L3"], pricingAvailable: true, callRecorded: false, contactAvailable: true },
  lines: [line(1), line(2), line(3)], contacts: [],
  customer: { visible: true, name: "TEST Client", company: null, addressLines: ["Str. Test 1"], phoneMasked: "07** *** ***", phoneAvailable: false },
  source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: null, freshness: "fresh" },
  production: { cleared: false, missing: ["approval", "call", "lines", "payment"] }, events: [],
  sourceAccess: { configured: true, read: true, edit: true, changePending: false, openEdits: [] },
  finance: { configured: true, read: true, record: false, approve: false }, handoff: null,
  invoice: { configured: true, read: true, issue: false, manage: false, proforma: true, route: true }, cycle: null,
  ...over,
});
const allLines = { confirmedLineIds: ["L1", "L2", "L3"], priceReviewed: true };
const none = { confirmedLineIds: [], priceReviewed: false };
const resolve = (d: ConfirmareDetail, over: Partial<WorkflowInput> = {}): ConfirmareWorkflow => resolveWorkflow({ detail: d, employeeId: ME, operates: true, supervises: false, draft: none, shop: "none", invoice: { kind: "unknown" }, ...over });
const states = (w: ConfirmareWorkflow) => Object.fromEntries(w.steps.map((step) => [step.id, step.state]));
const sub = (w: ConfirmareWorkflow, id: string) => w.steps.flatMap((step) => step.subtasks).find((entry) => entry.id === id)?.state;
const invoiceRead = (over: Partial<Extract<InvoiceFact, { kind: "read" }>> = {}): InvoiceFact => ({ kind: "read", proforma: "none", advance: "waiting", verifying: false, canIssueProforma: true, routing: { allowed: false, assigned: false }, assigned: over.routing?.assigned ?? false, ...over });
/** The confirmed, approved order (the call and the lines are server facts now). */
const approved = (over: Partial<ConfirmareDetail> = {}) => detail({
  status: "approved", review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")], lines: [1, 2, 3].map((n) => ({ ...line(n), confirmed: true })),
  production: { cleared: false, missing: ["payment"] }, ...over,
});
const paid = { payment: { state: "online" }, financial: { state: "cleared" as const, basis: "online_payment", open: true, attention: null } };

test("five steps, always in the same order, each with its own subtasks", () => {
  assert.deepEqual([...WORKFLOW_STEPS], ["take", "contact", "final", "payment", "handoff"]);
  const w = resolve(detail());
  assert.deepEqual(w.steps.map((step) => step.id), [...WORKFLOW_STEPS]);
  for (const step of w.steps) assert.deepEqual(step.subtasks.map((entry) => entry.id), [...STEP_SUBTASKS[step.id]], step.id);
  assert.equal(w.steps.filter((step) => step.state === "active" || step.state === "waiting" || step.state === "attention").length, 1, "one step needs attention at a time");
});

test("scenario 01: a verified online payment, from the call to the handoff", () => {
  const order = detail(paid);
  let w = resolve(order);
  assert.equal(w.active, "contact");
  assert.equal(w.task, "customer", "first the customer's telephone (read from the shop on request)");
  assert.equal(states(w).payment, "done", "the verified online payment is a fact already, shown as done");
  assert.ok(w.background.includes("paymentCleared"));
  w = resolve(order, { shop: "match" });
  assert.equal(w.task, "call");
  assert.equal(sub(w, "customer"), "done");
  const called = detail({ ...paid, review: { ...order.review, callRecorded: true }, contacts: [contact("confirmed")] });
  w = resolve(called, { shop: "match" });
  assert.equal(w.task, "products");
  assert.equal(w.lineIndex, 0);
  w = resolve(called, { shop: "match", draft: { confirmedLineIds: ["L1", "L2", "L3"], priceReviewed: false } });
  assert.equal(w.active, "final");
  assert.equal(w.task, "price");
  w = resolve(called, { shop: "match", draft: allLines });
  assert.equal(w.task, "approve");
  assert.equal(w.canAct, true);
  assert.deepEqual(w.missing, []);
  w = resolve(approved({ ...paid, production: { cleared: true, missing: [] }, productionCleared: true }), { invoice: invoiceRead({ proforma: "na", advance: null, routing: { allowed: true, assigned: false } }) });
  assert.deepEqual(states(w), { take: "done", contact: "done", final: "done", payment: "done", handoff: "active" });
  assert.equal(w.task, "routing");
  w = resolve(approved({ ...paid, production: { cleared: true, missing: [] }, productionCleared: true }), { invoice: invoiceRead({ proforma: "na", advance: null, routing: { allowed: false, assigned: true } }) });
  assert.equal(w.task, "handedOver");
  assert.equal(states(w).handoff, "done");
  assert.equal(w.canAct, false);
});

test("scenario 02: COD — approval is not payment; the proforma, the finance wait, then the handoff", () => {
  let w = resolve(approved());
  assert.equal(w.active, "payment", "an approved COD order waits for its money");
  assert.equal(states(w).final, "done");
  assert.notEqual(states(w).payment, "done", "the approval never marks the payment");
  assert.equal(w.task, "proforma", "before the SmartBill read, the operator's own proforma work");
  w = resolve(approved(), { invoice: invoiceRead() });
  assert.equal(w.task, "proforma");
  assert.equal(w.owner, "you");
  w = resolve(approved(), { invoice: invoiceRead({ proforma: "issued" }) });
  assert.equal(w.task, "financeWaiting", "an issued proforma is never money; the customer pays, accounting verifies");
  assert.equal(w.canAct, false);
  w = resolve(approved(), { invoice: invoiceRead({ proforma: "issued", verifying: true }) });
  assert.equal(w.task, "financeWaiting");
  assert.equal(w.owner, "finance");
  assert.equal(states(w).payment, "waiting");
  const cleared = approved({ financial: { state: "cleared", basis: "advance_collected", open: true, attention: null }, productionCleared: true, production: { cleared: true, missing: [] } });
  w = resolve(cleared, { invoice: invoiceRead({ proforma: "issued", advance: "paid", routing: { allowed: true, assigned: false } }) });
  assert.equal(states(w).payment, "done");
  assert.equal(w.steps.find((step) => step.id === "payment")!.summary, "cfw.summary.payment.advance_collected");
  assert.equal(w.task, "routing");
});

test("step 5: 'Comanda a fost predată' only on a server record of the handover; released is not handed over", () => {
  // Owner review 2026-10-29: approved, money proven, released for production, no SmartBill read or route right for this
  // employee, no verified downstream assignment, the production handoff still `ready`.
  const ready = (invoice: ConfirmareDetail["invoice"]) => approved({
    ...paid, productionCleared: true, production: { cleared: true, missing: [] }, invoice,
    handoff: { state: "ready", blockers: [], stageId: "cutting", document: null, actions: { generate: false, print: false } },
  });
  const noRead = { configured: true, read: false, issue: false, manage: false, proforma: false, route: false };
  for (const [name, d, fact] of [
    ["no SmartBill read or route right", ready(noRead), invoiceFact(ready(noRead), null)],
    ["no SmartBill for the shop", ready({ ...noRead, configured: false }), invoiceFact(ready({ ...noRead, configured: false }), null)],
    ["read without route, nobody routed", ready({ ...noRead, read: true }), invoiceRead({ proforma: "na", advance: null, routing: null })],
  ] as const) {
    const w = resolve(d, { invoice: fact, operates: true });
    assert.equal(w.task, "awaitingHandoff", name);
    assert.notEqual(w.task, "handedOver", `${name}: no false "handed over"`);
    assert.equal(states(w).handoff, "waiting", `${name}: step 5 is not done`);
    assert.equal(w.steps.find((step) => step.id === "handoff")!.summary, null);
    assert.notEqual(sub(w, "production"), "done");
    assert.equal(w.canAct, false);
  }
  assert.ok(!CATALOGS.ro["cfw.task.awaitingHandoff.title"].includes("predată"), "the title never claims a handover");
  // A routing the server recorded proves the handover, whoever reads it (even without the route right).
  const routed = resolve(ready({ ...noRead, read: true }), { invoice: invoiceRead({ proforma: "na", advance: null, routing: null, assigned: true }) });
  assert.equal(routed.task, "handedOver");
  assert.equal(states(routed).handoff, "done");
  // The cutting scan is the other proof: in production, said as such (never "handed over").
  const cutting = approved({ ...paid, productionCleared: true, production: { cleared: true, missing: [] }, invoice: noRead, handoff: { state: "in_production", blockers: [], stageId: "cutting", document: null, actions: { generate: false, print: false } } });
  const w = resolve(cutting, { invoice: invoiceFact(cutting, null) });
  assert.equal(w.task, "inProduction");
  assert.equal(w.steps.find((step) => step.id === "handoff")!.summary, "cfw.summary.inProduction");
  // Owner review of PR #102: in production wins over a recorded routing (the strongest proof comes first).
  const both = approved({ ...paid, productionCleared: true, production: { cleared: true, missing: [] }, invoice: { ...noRead, read: true, route: true }, handoff: { state: "in_production", blockers: [], stageId: "cutting", document: null, actions: { generate: false, print: false } } });
  for (const fact of [invoiceRead({ proforma: "na", advance: null, routing: { allowed: false, assigned: true } }), invoiceRead({ proforma: "na", advance: null, routing: null, assigned: true })]) {
    const inProduction = resolve(both, { invoice: fact });
    assert.equal(inProduction.task, "inProduction", "in production, even when a routing is also recorded");
    assert.equal(states(inProduction).handoff, "done");
    assert.equal(inProduction.steps.find((step) => step.id === "handoff")!.summary, "cfw.summary.inProduction");
    assert.equal(sub(inProduction, "routing"), "done", "the recorded routing still shows as done");
    assert.equal(sub(inProduction, "production"), "done");
  }
  // A routable order already in production is never sent back to the routing task; unknown reads change nothing.
  assert.equal(resolve(both, { invoice: invoiceRead({ proforma: "na", advance: null, routing: { allowed: true, assigned: false } }) }).task, "inProduction");
  assert.equal(resolve(both, { invoice: { kind: "unknown" } }).task, "inProduction");
  // Before production a recorded routing is the handover; its production subtask still waits.
  const routedReady = resolve(ready({ ...noRead, read: true, route: true }), { invoice: invoiceRead({ proforma: "na", advance: null, routing: { allowed: false, assigned: true } }) });
  assert.equal(routedReady.task, "handedOver");
  assert.equal(sub(routedReady, "production"), "waiting");
  // An unanswered SmartBill read never completes the step either.
  assert.equal(resolve(ready({ ...noRead, read: true }), { invoice: { kind: "unavailable" } }).task, "awaitingHandoff");
});

test("scenario 03: three products are reviewed one by one; approval needs every current line", () => {
  const called = detail({ ...paid, review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] });
  for (const [ticked, index] of [[[], 0], [["L1"], 1], [["L1", "L3"], 1]] as const) {
    const w = resolve(called, { shop: "match", draft: { confirmedLineIds: [...ticked], priceReviewed: true } });
    assert.equal(w.task, "products", `${ticked.join(",")}`);
    assert.equal(w.lineIndex, index);
    assert.ok(w.missing.includes("lines"), "the approval stays blocked");
  }
  const stale = resolve(called, { shop: "match", draft: { confirmedLineIds: ["L1", "L2", "OLD"], priceReviewed: true } });
  assert.equal(stale.task, "products", "a tick of a line that no longer exists never counts");
});

test("scenario 04: a measurement correction — editor, waiting for the shop, then a fresh confirmation", () => {
  const asked = detail({ contacts: [contact("needs_correction")] });
  let w = resolve(asked, { shop: "match" });
  assert.equal(w.active, "contact");
  assert.equal(w.task, "correction");
  assert.equal(w.canAct, true);
  w = resolve(detail({ contacts: [contact("needs_correction")], sourceAccess: { ...asked.sourceAccess, edit: false } }));
  assert.equal(w.task, "correctionWaiting", "without the edit right the correction waits for a supervisor");
  assert.equal(w.canAct, false);
  w = resolve(detail({ contacts: [contact("needs_correction")], sourceAccess: { ...asked.sourceAccess, changePending: true } }), { shop: "match", draft: allLines });
  assert.equal(w.task, "shopUpdate", "a saved edit waits for the shop's order update");
  assert.equal(w.owner, "shop");
  assert.equal(states(w).contact, "waiting");
  assert.ok(w.missing.includes("shopSettled"));
  w = resolve(detail({ sourceAccess: { ...asked.sourceAccess, openEdits: [{ idempotencyKey: "k", state: "unknown", requestedAt: "2026-10-28T09:00:00Z", mine: true }] } }));
  assert.equal(w.task, "editUnknown", "an unknown edit result is checked, never sent again");
  assert.equal(states(w).contact, "attention");
  // The shop update arrived: a new fingerprint, the old calls are no longer current, the call is asked again.
  const updated = detail({ review: { ...detail().review, fingerprint: "b".repeat(64), callRecorded: false }, contacts: [contact("confirmed", false, 2), contact("needs_correction", false, 1)] });
  w = resolve(updated, { shop: "match" });
  assert.equal(w.task, "callAgain");
  assert.equal(sub(w, "call"), "current");
});

test("scenario 05: no answer — no approval, no cancellation; the call cycle and the parallel proforma stay visible", () => {
  const cycle = { calls: { noAnswer: 1, max: 3, complete: false, since: null }, proforma: null, history: [] };
  let w = resolve(detail({ contacts: [contact("no_answer")], cycle }));
  assert.equal(w.task, "callAgain");
  assert.equal(w.active, "contact");
  assert.ok(w.background.includes("callCycle"));
  assert.notEqual(w.task, "rejected");
  const proforma = { cycle: 1, state: "running" as const, origin: "no_answer" as const, sentAt: "2026-10-28T10:00:00Z", reminder1DueAt: null, reminder1At: null, reminder2DueAt: null, reminder2At: null, expiresAt: null, bankCheck: false, closedReason: null, lastError: null, closedAt: null };
  w = resolve(detail({ contacts: [contact("no_answer", true, 3)], cycle: { calls: { noAnswer: 3, max: 3, complete: true, since: null }, proforma, history: [] } }));
  assert.equal(w.active, "contact", "the telephone review is not done because a proforma runs");
  assert.ok(w.background.includes("proformaCycle"), "scenario 15: the proforma cycle shows beside the call");
  assert.equal(states(w).payment, "locked");
  assert.equal(sub(w, "call"), "current");
});

test("scenario 06: a rejection is not a cancellation in the shop", () => {
  const w = resolve(detail({ status: "rejected", decision: { at: "2026-10-28T12:00:00Z", by: "Operator Test", reasonCode: "customer_cancelled" } }));
  assert.equal(w.task, "rejected");
  assert.equal(states(w).payment, "skipped");
  assert.equal(w.canAct, false, "an operator cannot reopen");
  assert.ok(!/anulat/.test(CATALOGS.ro["cfw.task.rejected.title"]), "the title never says cancelled");
  assert.match(CATALOGS.ro["cfw.task.rejected.lead"], /nu anulează comanda în magazin/);
  const sup = resolve(detail({ status: "rejected" }), { supervises: true, operates: false });
  assert.equal(sup.canAct, true, "a supervisor may reopen");
  const closed = resolve(detail({ commerceClosed: true }));
  assert.equal(closed.task, "closedInShop");
  assert.equal(closed.active, "take");
});

test("scenario 07/08: a partial advance and a bank transfer being verified are decisions of others", () => {
  let w = resolve(approved({ payment: { state: "partial" }, financial: { state: "partial", basis: null, open: false, attention: null } }), { invoice: invoiceRead({ proforma: "issued", advance: "partial" }) });
  assert.equal(w.task, "partial");
  assert.notEqual(states(w).payment, "done", "an underpayment is never accepted by itself");
  w = resolve(approved({ financial: { state: "waiting", basis: null, open: false, attention: "transfer_pending" } }));
  assert.equal(w.task, "financeWaiting");
  assert.equal(w.owner, "finance");
  assert.equal(w.canAct, false, "an operator is never offered the bank verification");
  w = resolve(approved({ financial: { state: "waiting", basis: null, open: false, attention: "transfer_pending" }, finance: { configured: true, read: true, record: false, approve: true } }), { operates: false });
  assert.equal(w.canAct, true, "a finance approver has the action");
  w = resolve(approved({ payment: { state: "online" } }));
  assert.equal(w.task, "onlineWaiting", "choosing online payment is not a payment");
  w = resolve(approved({ financial: { state: "unknown", basis: null, open: false, attention: null } }));
  assert.equal(w.task, "financeAttention");
  assert.equal(states(w).payment, "attention");
  w = resolve(approved({ financial: { state: "waiting", basis: null, open: false, attention: "reconciliation_required" } }));
  assert.equal(w.task, "financeAttention");
});

test("scenario 09/14: the shop changed the order — the review reopens, an approval waits for a supervisor", () => {
  const called = detail({ ...paid, review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] });
  let w = resolve(called, { shop: "differs", draft: allLines });
  assert.equal(w.task, "pricesDiffer");
  assert.equal(states(w).final, "attention");
  assert.ok(w.missing.includes("shopReviewed"));
  w = resolve(approved({ ...paid, production: { cleared: false, missing: ["sync"] } }));
  assert.equal(w.task, "reverify");
  assert.equal(w.canAct, false);
  assert.equal(resolve(approved({ ...paid, production: { cleared: false, missing: ["sync"] } }), { supervises: true }).canAct, true);
  w = resolve(detail({ review: { ...detail().review, callRecorded: true, pricingAvailable: false }, contacts: [contact("confirmed")] }), { draft: allLines, shop: "match" });
  assert.equal(w.task, "priceEvidence");
  assert.equal(w.owner, "supervisor");
});

test("scenario 10: a reassignment takes the order away; a colleague only watches", () => {
  const w = resolve(detail({ assignment: { employeeId: OTHER, displayName: "Coleg Test", at: "2026-10-28T09:00:00Z", kind: "manual" } }), { draft: allLines, shop: "match" });
  assert.equal(w.active, "take");
  assert.equal(w.task, "colleague");
  assert.equal(w.canAct, false);
  assert.equal(states(w).contact, "locked", "no call or review form for someone else's order");
  const finance = resolve(detail(), { operates: false, supervises: false });
  assert.equal(finance.task, "observe");
  assert.equal(finance.canAct, false);
  const unassigned = resolve(detail({ assignment: null }));
  assert.equal(unassigned.task, "claim");
  assert.equal(unassigned.canAct, true);
  assert.equal(resolve(detail({ assignment: null }), { operates: false, supervises: false }).task, "unassigned");
  const sup = resolve(detail({ assignment: { employeeId: OTHER, displayName: "Coleg Test", at: "2026-10-28T09:00:00Z", kind: "manual" } }), { operates: false, supervises: true });
  assert.equal(sup.active, "contact", "a supervisor may work any order of the source");
  assert.equal(sup.steps[0].summary, "cfw.summary.take.other");
});

test("scenario 11: unknown external results are never done", () => {
  let w = resolve(approved(), { invoice: invoiceRead({ proforma: "unknown" }) });
  assert.equal(w.task, "proformaUnknown");
  assert.equal(states(w).payment, "attention");
  w = resolve(approved(), { invoice: invoiceRead({ proforma: "in_flight" }) });
  assert.equal(w.task, "proformaUnknown");
});

test("scenario 12/13: a reload resumes from the server's facts; an approved order never reopens the call", () => {
  const called = detail({ ...paid, review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] });
  const reloaded = resolve(called);
  assert.equal(reloaded.task, "products", "after a reload: the recorded call stays done, unsent ticks are not pretended");
  assert.equal(sub(reloaded, "call"), "done");
  assert.equal(sub(reloaded, "products"), "current");
  const w = resolve(approved({ ...paid }), { invoice: invoiceRead({ proforma: "na", advance: null }) });
  assert.notEqual(w.active, "contact");
  assert.equal(states(w).contact, "done");
  assert.equal(states(w).final, "done");
});

test("scenario 16: legacy and undetermined orders are never forced into the workflow", () => {
  const w = resolve(detail({ workflowCohort: "legacy" }), { draft: allLines, shop: "match" });
  assert.equal(w.task, "undetermined");
  assert.equal(w.active, "take");
  assert.deepEqual(w.steps.slice(1).map((step) => step.state), ["locked", "locked", "locked", "locked"]);
  assert.equal(w.canAct, false);
});

test("missing telephone, missing shop and unreadable shop each say what is needed", () => {
  assert.equal(resolve(detail({ review: { ...detail().review, contactAvailable: false } })).task, "noContact");
  const offline = detail({ sourceAccess: { configured: false, read: false, edit: false, changePending: false, openEdits: [] } });
  assert.equal(resolve(offline, { shop: "off" }).task, "call", "without the shop the operator still calls (the phone is in the shop's own admin)");
  assert.equal(sub(resolve(offline, { shop: "off" }), "customer"), "skipped");
  const called = detail({ review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] });
  assert.equal(resolve(called, { shop: "unavailable", draft: allLines }).task, "shopUnavailable");
  assert.equal(resolve(called, { shop: "none", draft: allLines }).task, "price", "the shop is read again in the final review");
  const noShop = detail({ review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")], sourceAccess: offline.sourceAccess });
  assert.equal(resolve(noShop, { shop: "off", draft: allLines }).task, "approve");
});

test("no false completion: nothing is done before its fact exists", () => {
  for (const d of [detail(), detail({ contacts: [contact("no_answer")] }), detail({ contacts: [contact("callback")] })]) {
    const w = resolve(d, { shop: "match", draft: allLines });
    assert.notEqual(states(w).contact, "done");
    assert.notEqual(states(w).final, "done");
    assert.notEqual(states(w).handoff, "done");
    assert.notEqual(sub(w, "call"), "done");
  }
  // A pending order never shows the approval or the handoff as done, whatever the operator ticked.
  const w = resolve(detail({ ...paid, review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] }), { shop: "match", draft: allLines });
  assert.notEqual(sub(w, "approval"), "done");
  assert.equal(states(w).handoff, "locked");
});

test("the shop and SmartBill reads map strictly; nothing unread counts", () => {
  const d = detail();
  const read = (matches: boolean | null): ShopRead => ({ available: true, role: "assignee", freshness: { readAt: "x", shopModifiedAt: null, arasyaChangedAt: null, pricingMatches: matches, inSync: true }, detail: {} as never });
  const cases: [ShopRead | null, ShopFact][] = [[null, "none"], [{ available: false, reason: "source_unreachable" }, "unavailable"], [read(true), "match"], [read(false), "differs"], [read(null), "differs"]];
  for (const [value, fact] of cases) assert.equal(shopFact(d, value), fact);
  assert.equal(shopFact(detail({ sourceAccess: { ...d.sourceAccess, configured: false } }), read(true)), "off");
  assert.equal(shopFact(detail({ sourceAccess: { ...d.sourceAccess, read: false } }), read(true)), "denied");
  assert.deepEqual(invoiceFact(detail({ invoice: { ...d.invoice, read: false } }), null), { kind: "none" });
  assert.deepEqual(invoiceFact(d, null), { kind: "unknown" });
  const view = { available: true, summary: { proforma: { advance: { cod: true, state: "waiting", verifying: true }, state: "issued" } }, proforma: { allowedActions: [] }, routing: null, assignment: null } as unknown as InvoiceRead;
  assert.deepEqual(invoiceFact(d, view), { kind: "read", proforma: "issued", advance: "waiting", verifying: true, canIssueProforma: false, assigned: false, routing: null });
  assert.deepEqual(invoiceFact(d, { available: false, reason: "source_unreachable" }), { kind: "unavailable" }, "an unanswered read is not a fact");
  assert.deepEqual(invoiceFact(d, { available: false, reason: "not_configured" }), { kind: "none" });
  const cleared = approved({ ...paid, production: { cleared: true, missing: [] }, productionCleared: true });
  assert.equal(resolve(cleared, { invoice: { kind: "unavailable" } }).task, "routingWaiting", "the handoff never completes on an unanswered SmartBill read");
  assert.notEqual(resolve(cleared, { invoice: { kind: "unknown" } }).task, "handedOver", "nor before it is read");
});

// ---- the guided components (presentation only) ---------------------------------------------------------------
const Provider = I18nProvider as (props: { locale: Locale; children?: ReactNode }) => ReactElement;
const render = (node: ReactElement, locale: Locale = "ro") => renderToStaticMarkup(createElement(Provider, { locale }, node));

test("the progress names every state in words, in every language", () => {
  const w = resolve(approved());
  for (const locale of SUPPORTED_LOCALES) {
    const html = render(createElement(StepProgress, { workflow: w }), locale);
    for (const step of WORKFLOW_STEPS) assert.ok(html.includes(CATALOGS[locale][`cfw.step.${step}` as MessageKey] as string), `${locale} ${step}`);
    assert.ok(html.includes(CATALOGS[locale]["cfw.state.done"]), locale);
    assert.match(html, /aria-current="step"/);
  }
  const html = render(createElement(StepProgress, { workflow: w }));
  assert.ok(html.includes("Pasul 4 din 5"));
  assert.ok(html.includes("Plata și avansul"));
});

test("only the active step shows its task; done steps are summaries; later steps are closed", () => {
  const w = resolve(approved());
  const cards = w.steps.map((step, index) => render(createElement(StepCard, { step, number: index + 1, active: step.id === w.active }, createElement("p", { "data-testid": "body" }, "BODY"))));
  assert.equal(cards.filter((html) => html.includes("BODY")).length, 1);
  assert.ok(cards[2].includes("Comanda a fost aprobată (aceasta nu confirmă plata)."), "the final step's summary says the approval is not a payment");
  assert.ok(cards[4].includes("Încă indisponibil"));
  const brief = render(createElement(TaskBrief, { workflow: w, ownerName: null, headingRef: createRef<HTMLHeadingElement>() }));
  assert.ok(brief.includes("Proforma avansului") && brief.includes("Cine acționează") && brief.includes("Aveți o acțiune de făcut acum."));
  const waiting = render(createElement(TaskBrief, { workflow: resolve(approved({ financial: { state: "waiting", basis: null, open: false, attention: "transfer_pending" } })), ownerName: null, headingRef: createRef<HTMLHeadingElement>() }));
  assert.ok(waiting.includes("În așteptarea verificării plății") && waiting.includes("Contabilitatea") && waiting.includes("Nu aveți nimic de făcut acum."));
});

test("subtasks: a reached one can be opened again, a future one cannot", () => {
  const called = detail({ ...paid, review: { ...detail().review, callRecorded: true }, contacts: [contact("confirmed")] });
  const w = resolve(called, { shop: "match" });
  const contactStep = w.steps.find((step) => step.id === "contact")!;
  const html = render(createElement(SubtaskList, { step: contactStep, shown: "products", onPick: () => undefined }));
  assert.match(html, /data-testid="cfw-sub-call"[^>]*data-state="done"[^]*?<button/);
  assert.doesNotMatch(html, /data-testid="cfw-sub-correction"/, "a correction nobody asked for is not listed");
  const locked = render(createElement(SubtaskList, { step: w.steps.find((step) => step.id === "final")!, shown: null, onPick: () => undefined }));
  assert.doesNotMatch(locked, /<button/, "nothing of a future step can be opened");
});

test("the product review shows one product at a time and never approves the order", () => {
  const d = detail();
  const html = render(createElement(ProductReview, { detail: d, checked: ["L1"], index: 1, onIndex: () => undefined, onCheck: () => undefined, onUncheck: () => undefined, onCorrect: () => undefined, disabled: false }));
  assert.ok(html.includes("Produsul 2 din 3"));
  assert.ok(html.includes("DRAPERIE TEST 2") && !html.includes("DRAPERIE TEST 1") && !html.includes("DRAPERIE TEST 3"));
  assert.ok(html.includes("1 din 3 confirmate"));
  assert.ok(html.includes("Verificat cu clientul, continuă") && html.includes("Clientul vrea o modificare"));
  assert.doesNotMatch(html, /cf-approve/);
  const details = render(createElement(VerificationDetails, { missing: ["lines", "priceReviewed"], items: ["call", "lines", "priceReviewed"] }));
  assert.ok(details.includes("Mai lipsește:") && details.includes("<details"), "the full checklist waits behind a disclosure");
});

test("moving between steps never sends anything: no command in an effect, no automatic financial action", () => {
  const files = ["ConfirmareOrderScreen.tsx", "ConfirmareGuided.tsx", "ConfirmareShopPanel.tsx", "ConfirmareInvoicePanel.tsx"].map((file) => [file, readFileSync(path.join(root, "features/confirmare", file), "utf8")] as const);
  const commands = /\.(contact|approve|reject|claim|release|reassign|reopen|next|sourceApply|sourcePreview|financeAct|invoiceAct|proformaAct|assignShop|advanceCycle|retryCycle|operate)\(/;
  for (const [file, source] of files) {
    for (const effect of source.matchAll(/useEffect\(\(\) => \{?([^]*?)\}?, \[/g)) assert.doesNotMatch(effect[1], commands, `${file}: no command runs from an effect`);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/, `${file}: nothing is stored in the browser`);
  }
  const guided = files.find(([file]) => file === "ConfirmareGuided.tsx")![1];
  assert.doesNotMatch(guided, /service\./, "the guided components never reach the API");
  const domain = readFileSync(path.join(root, "domain/confirmareWorkflow.ts"), "utf8");
  assert.doesNotMatch(domain, /fetch\(|service|localStorage|Date\.now|Math\.random/, "the resolver is pure");
  const screen = files[0][1];
  assert.match(screen, /onSubmit=\{\(event\) => \{[^]*?if \(!outcome \|\| !callHappened\) return;[^]*?service\.contact\(/, "a call is recorded only by the explicit form");
  assert.match(screen, /disabled=\{busy !== null \|\| missing\.length > 0\}/, "the approval stays disabled until every condition is met");
  assert.match(screen, /setConfirmApprove\(true\)[^]*?data-testid="cf-approve-confirm"/, "the approval asks once more before it is sent");
});

test("every guided text exists in the four languages", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("cfw.")) as MessageKey[];
  assert.ok(keys.length > 120, `guided keys: ${keys.length}`);
  for (const locale of SUPPORTED_LOCALES) for (const key of keys) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  const tasks = ["claim", "colleague", "unassigned", "undetermined", "closedInShop", "rejected", "observe", "customer", "call", "callAgain", "noContact", "correction", "correctionWaiting", "shopUpdate", "editUnknown", "products", "price", "pricesDiffer", "shopUnavailable", "priceEvidence", "approve", "proforma", "proformaUnknown", "financeWaiting", "partial", "onlineWaiting", "financeAttention", "reverify", "routing", "routingWaiting", "handedOver", "awaitingHandoff", "inProduction"];
  for (const task of tasks) for (const part of ["title", "lead"]) assert.ok(keys.includes(`cfw.task.${task}.${part}` as MessageKey), `${task}.${part}`);
  assert.equal(CATALOGS.ro["cfw.step.take"], "Preluarea comenzii");
  assert.equal(CATALOGS.ro["cfw.step.contact"], "Verificarea cu clientul");
  assert.equal(CATALOGS.ro["cfw.step.final"], "Verificarea finală");
  assert.equal(CATALOGS.ro["cfw.step.payment"], "Plata și avansul");
  assert.equal(CATALOGS.ro["cfw.step.handoff"], "Predarea comenzii");
});
