import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import {
  answerLost, checkEntry, checkMoney, checkPayment, decidedToday, deskRows, deskView, matchesSearch, nextDeskTask, noteDeskDone, paymentEffect, takeDeskDone, validValueDate, waitParts,
  type CheckMoney, type PaymentForm,
} from "../domain/financeDesk";
import { normalizeAmount } from "../domain/confirmareFinance";
import { canUseFinanceInbox, mapFinanceTask, mapInbox, type Inbox } from "../domain/financeRequests";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { opensFinanceInbox } from "../domain/operatorDesk";
import type { Employee } from "../domain/models";
import { CATALOGS, type MessageKey } from "../i18n";
import { FinanceDeskScreen, milestones } from "../features/confirmare/FinanceDesk";

// The Contabilitate desk (Staff 2.53.0, owner 2026-11-25). TEST amounts and invented references only.
const root = path.resolve(import.meta.dirname, "..");
const NOW = Date.parse("2026-11-25T12:00:00Z");
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const item = (n: number, patch: Record<string, unknown> = {}) => ({
  id: id(n), source: "trendhome", orderNumber: String(65000 + n), purpose: "advance", taskCode: null, ledger: "shop", proforma: { series: "ARS", number: String(600 + n).padStart(4, "0") },
  state: "assigned", version: 2, category: "todo", kind: "verify", assignedToMe: true, outcome: null, reasonCode: null, policy: "single",
  requestedAt: new Date(NOW - n * 3600_000).toISOString(), updatedAt: new Date(NOW - n * 1800_000).toISOString(), decidedAt: null,
  advance: { currency: "RON", required: "300.00", remaining: "300.00", readAt: "2026-11-25T11:00:00Z" }, awaitingPayment: false, orderedAt: "2026-11-20T08:00:00Z", ...patch,
});
const inbox = (items: Record<string, unknown>[], counts: Record<string, number> = {}): Inbox => mapInbox({ items, counts: { todo: 0, clarification: 0, done: 0, waiting: 0, today: 0, ...counts }, sources: ["outletperdele", "trendhome"], limit: items.length + 1 });
const live = (patch: Record<string, unknown> = {}) => ({
  available: true, revision: `sha256:${"c".repeat(64)}`, readAt: "2026-11-25T11:59:00Z", currency: "RON",
  proforma: { series: "ARS", number: "0601", amount: "1000.00", currency: "RON" }, proformaChanged: false, ledger: "shop",
  advance: { percentage: 30, required: "300.00", verified: "0.00", remaining: "300.00", accepted: false }, paymentReference: "TH65001",
  bank: { beneficiary: "TEST SRL", bank: "TEST BANK", ibanMasked: "RO49 •••• •••• 0000", bic: null }, financial: { state: "waiting", attention: null }, policy: "single", recordEnabled: true,
  transfers: [], settledElsewhere: false, ...patch,
});
const task = (request: Record<string, unknown>, allowed: Record<string, boolean> = {}, liveData: Record<string, unknown> = live()) => mapFinanceTask({
  request, live: liveData, allowed: { start: false, verify: true, notReceived: true, clarify: true, secondCheck: false, reconcile: false, ...allowed }, me: id(900), singleVerification: true,
});
const form = (patch: Record<string, string> = {}) => ({ amount: "300,00", bankReference: "ING 2026 0001", valueDate: "2026-11-24", statementReference: "TH65001", matchBasis: "", payerName: "", matchNote: "", ...patch }) as PaymentForm;
// Owner example 2026-11-25: order 500 RON, advance asked 100 RON.
const money = (patch: Partial<CheckMoney> = {}): CheckMoney => ({ remaining: "500.00", advanceRemaining: "100.00", total: "500.00", received: "0.00", ...patch });

test("the owner's table: every positive real payment is accepted and the cash on delivery follows (500 RON order, 100 RON asked)", () => {
  const rows: [string, string][] = [["100", "400.00"], ["99", "401.00"], ["70", "430.00"], ["30", "470.00"], ["10", "490.00"], ["1", "499.00"], ["150", "350.00"], ["500", "0.00"]];
  for (const [paid, ramburs] of rows) {
    const check = checkPayment(form({ amount: paid }), "TH65001", money(), NOW);
    assert.equal(check.kind, "ok", `${paid} RON is accepted`);
    assert.equal(check.kind === "ok" && check.effect.ramburs, ramburs, `${paid} RON: cash on delivery ${ramburs}`);
    assert.equal(check.kind === "ok" && check.input.amount, normalizeAmount(paid), "the real amount, never the asked one");
  }
  assert.deepEqual(checkPayment(form({ amount: "0" }), "TH65001", money(), NOW), { kind: "invalid", field: "amount" }, "0 RON is never a payment");
  const short = checkPayment(form({ amount: "70" }), "TH65001", money(), NOW);
  assert.equal(short.kind === "ok" && short.effect.advanceGap, "30.00", "the advance difference is shown, not a problem");
  const extra = checkPayment(form({ amount: "300" }), "TH65001", money({ advanceRemaining: "150.00" }), NOW);
  assert.equal(extra.kind === "ok" && extra.effect.overAdvance, "150.00", "more than the advance, less than the total: accepted (cash on delivery 200)");
  assert.equal(extra.kind === "ok" && extra.effect.ramburs, "200.00");
});

test("more than the order costs is the only amount sent to clarification (never a negative cash on delivery)", () => {
  const over = checkPayment(form({ amount: "600" }), "TH65001", money(), NOW);
  assert.equal(over.kind, "over");
  assert.equal(over.kind === "over" && over.excess, "100.00");
  assert.equal(over.kind === "over" && over.clarify, "amount_unclear");
  assert.equal(paymentEffect("600.00", money()).ramburs, "0.00", "never below zero");
});

test("the statement need not show the proforma reference: the payer is named, and the evidence for another payer", () => {
  assert.equal(checkPayment(form(), "TH65001", money(), NOW).kind === "ok" && (checkPayment(form(), "TH65001", money(), NOW) as { input: { matchBasis: string } }).input.matchBasis, "proforma_reference");
  assert.equal((checkPayment(form({ statementReference: "th65001" }), "TH65001", money(), NOW) as { kind: string }).kind, "ok", "case is ignored");
  for (const text of ["AVANS PERDELE", "Comandă perdele", "Transfer", ""]) {
    assert.deepEqual(checkPayment(form({ statementReference: text }), "TH65001", money(), NOW), { kind: "invalid", field: "matchBasis" }, `"${text}" needs the match`);
    const named = checkPayment(form({ statementReference: text, matchBasis: "customer_name", payerName: "Ion Popescu" }), "TH65001", money(), NOW);
    assert.equal(named.kind, "ok", `"${text}" with the customer's name is accepted`);
    assert.equal(named.kind === "ok" && named.input.paymentReference, undefined, "a description that is not the proforma reference is not sent as one");
  }
  assert.deepEqual(checkPayment(form({ statementReference: "", matchBasis: "customer_name", payerName: "" }), "TH65001", money(), NOW), { kind: "invalid", field: "payerName" });
  assert.deepEqual(checkPayment(form({ statementReference: "", matchBasis: "other_payer", payerName: "Maria Ionescu" }), "TH65001", money(), NOW), { kind: "invalid", field: "matchNote" });
  const other = checkPayment(form({ statementReference: "", matchBasis: "other_payer", payerName: "Maria Ionescu", matchNote: "soția clientului" }), "TH65001", money(), NOW);
  assert.deepEqual(other.kind === "ok" && other.input, { amount: "300.00", reference: "ING 2026 0001", valueDate: "2026-11-24", matchBasis: "other_payer", payerName: "Maria Ionescu", matchNote: "soția clientului" });
  const older = checkPayment(form({ statementReference: "TH99999" }), "TH65001", money(), NOW, false);
  assert.equal(older.kind, "mismatch", "a shop before YD SOFT 2.32.0 keeps the reference rule (clarification)");
});

test("every field is required and checked; the expected amount is never prefilled", () => {
  assert.deepEqual(checkPayment(form({ amount: "" }), "TH65001", money(), NOW), { kind: "invalid", field: "amount" });
  assert.deepEqual(checkPayment(form({ bankReference: "x" }), "TH65001", money(), NOW), { kind: "invalid", field: "bankReference" });
  assert.deepEqual(checkPayment(form({ valueDate: "" }), "TH65001", money(), NOW), { kind: "invalid", field: "valueDate" });
  assert.equal(validValueDate("2026-02-30", NOW), false, "not a calendar day");
  assert.equal(validValueDate("2026-12-31", NOW), false, "a payment date in the future");
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  assert.match(source, /const EMPTY_FORM: PaymentForm = \{ amount: "", bankReference: "", valueDate: "", statementReference: "", matchBasis: "", payerName: "", matchNote: "" \};/);
});

test("several transfers: each is recorded on its own and the cash on delivery is the total less everything received (600 / 180 asked: 50 + 70 + 30)", () => {
  let received = 0;
  for (const paid of ["50", "70", "30"]) {
    const check = checkPayment(form({ amount: paid, bankReference: `ING 2026 00${paid}` }), "TH65001", money({ total: "600.00", remaining: (600 - received).toFixed(2), received: received.toFixed(2), advanceRemaining: Math.max(0, 180 - received).toFixed(2) }), NOW);
    assert.equal(check.kind, "ok");
    received += Number(paid);
  }
  assert.equal(paymentEffect(null, money({ total: "600.00", remaining: (600 - received).toFixed(2) })).ramburs, "450.00");
  const opened = task(item(1, { state: "checking" }), {}, live({ advance: { percentage: 30, required: "300.00", verified: "200.00", remaining: "100.00", accepted: false } }));
  assert.equal(opened.live.available && opened.live.advance?.remaining, "100.00");
});

test("not received and clarification: both stay tracked (waiting / clarification), never cancelled", () => {
  const box = inbox([item(1), item(2, { state: "not_received", category: "done", awaitingPayment: true, outcome: "not_received" }), item(3, { state: "needs_clarification", category: "clarification" }),
    item(4, { state: "verified", category: "done", outcome: "verified_full" })], { todo: 1, waiting: 1, clarification: 1, done: 2, today: 3 });
  assert.deepEqual(deskRows(box, "waiting", null, "").map((row) => row.orderNumber), ["65002"]);
  assert.deepEqual(deskRows(box, "clarification", null, "").map((row) => row.orderNumber), ["65003"]);
  assert.deepEqual(deskRows(box, "done", null, "").map((row) => row.orderNumber), ["65004"], "a waiting payment is not hidden among the done ones");
  assert.equal(deskView(box.items[1]), "waiting");
  assert.equal(box.counts.today, 3);
  for (const key of ["fd.sum.notReceived"] as const) for (const locale of ["ro", "tr", "en", "si"] as const) assert.ok(String(CATALOGS[locale][key]).length > 10);
  assert.match(CATALOGS.ro["fd.sum.notReceived"], /nu se anulează/);
});

test("the desk lists the oldest task first, filters by shop and searches by order or proforma", () => {
  const box = inbox([item(1), item(5, { source: "outletperdele" }), item(3), item(9, { kind: "second_check", state: "checking" })]);
  assert.deepEqual(deskRows(box, "todo", null, "").map((row) => row.orderNumber), ["65009", "65005", "65003", "65001"], "oldest request first");
  assert.deepEqual(deskRows(box, "todo", "trendhome", "").map((row) => row.orderNumber), ["65009", "65003", "65001"]);
  assert.deepEqual(deskRows(box, "todo", "outletperdele", "").map((row) => row.orderNumber), ["65005"]);
  assert.deepEqual(deskRows(box, "todo", null, "65003").map((row) => row.orderNumber), ["65003"]);
  assert.deepEqual(deskRows(box, "todo", null, "ars 0605").map((row) => row.orderNumber), ["65005"], "proforma series and number, spaces ignored");
  assert.ok(matchesSearch(box.items[0], "0601"));
  assert.ok(!matchesSearch(box.items[0], "0602"));
});

test("the next order opens by itself: the oldest open advance check of the same shop filter, never the same one", () => {
  const box = inbox([item(1), item(2), item(7, { kind: "second_check", state: "checking" }), item(4, { source: "outletperdele" }), item(6, { purpose: "exception", kind: "exception", taskCode: "refund_due" })]);
  assert.equal(nextDeskTask(box, id(4), null)?.orderNumber, "65002", "the second check and the exception task are skipped (their own screens)");
  assert.equal(nextDeskTask(box, id(2), null)?.orderNumber, "65004");
  assert.equal(nextDeskTask(box, id(2), "trendhome")?.orderNumber, "65001");
  assert.equal(nextDeskTask(inbox([item(1)]), id(1), null), null, "the list opens when nothing is left");
  noteDeskDone("65002");
  assert.equal(takeDeskDone(), "65002");
  assert.equal(takeDeskDone(), null, "shown once");
});

test("more than 300 open tasks: all are on the desk", () => {
  const many = Array.from({ length: 431 }, (_, n) => item(n + 1, { source: n % 3 === 0 ? "outletperdele" : "trendhome" }));
  const box = inbox(many, { todo: 431 });
  assert.equal(deskRows(box, "todo", null, "").length, 431);
  assert.equal(deskRows(box, "todo", "trendhome", "").length + deskRows(box, "todo", "outletperdele", "").length, 431);
});

test("a task the three steps do not handle opens its own screen; a lost answer is settled, never sent twice", () => {
  assert.equal(checkEntry(task(item(1))), "check");
  assert.equal(checkEntry(task(item(1), { reconcile: true })), "reconcile");
  assert.equal(checkEntry(task(item(1), {}, { available: false, reason: "source_unavailable" })), "unavailable");
  assert.equal(checkEntry(task(item(1, { kind: "second_check", state: "checking" }), { verify: false, notReceived: false, clarify: false, secondCheck: true })), "other");
  assert.equal(checkEntry(task(item(1, { purpose: "exception", kind: "exception", taskCode: "refund_due" }))), "other", "refunds and overpayments keep their task");
  assert.equal(checkEntry(task(item(1, { state: "verified", category: "done" }), { verify: false, notReceived: false, clarify: false })), "other");
  assert.ok(answerLost("NETWORK_UNAVAILABLE") && answerLost("REQUEST_TIMEOUT"));
  assert.ok(!answerLost("SERVER_ERROR") && !answerLost("UNAUTHORIZED_ACTION"));
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  // One key per decision: a retry after a lost answer reuses it (the server replays the stored answer).
  assert.match(source, /const keyFor = \(id: string\) => \{ if \(intent\.current\?\.id !== id\) intent\.current = \{ id, key: createIdempotencyKey\(\) \}; return intent\.current\.key; \};/);
  assert.match(source, /if \(!code && answerLost\(failure\.code\)\)/);
  assert.doesNotMatch(source, /setLost\(false\); view\.refresh\(\); return send/);
});

test("duplicate bank reference and other shop refusals are shown as the shop's own error", () => {
  for (const code of ["CONFIRMARE_FINANCE_REFUSED", "FINANCE_REQUEST_PAYMENT_REFERENCE", "FINANCE_REQUEST_STALE", "FINANCE_REQUEST_PROFORMA_CHANGED"]) {
    for (const locale of ["ro", "tr", "en", "si"] as const) assert.ok(String(CATALOGS[locale][`fr.error.${code}` as MessageKey] ?? "").length > 5, `${locale} ${code}`);
  }
  assert.ok(Object.keys(CATALOGS.ro).includes("fr.reason.duplicate"));
});

test("permissions stay as they were: Cristina's scopes open the desk; view-only, staged and operator accounts do not", () => {
  const person = (patch: Partial<Employee>): Employee => ({
    employeeUuid: id(900), username: "test.fin", displayName: "Test Fin", role: "employee", department: "contabilitate", status: "active", permissions: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
    allowedStageIds: [], confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: ["trendhome", "outletperdele"], record: ["trendhome", "outletperdele"], approve: [], verify: ["trendhome", "outletperdele"] }, ...patch,
  } as Employee);
  const cristina = person({});
  assert.ok(canUseFinanceInbox(cristina));
  assert.ok(opensFinanceInbox(cristina, { kind: "confirmare" } as never));
  assert.ok(canAccessRoute(cristina, "/confirmare/finance/requests"));
  assert.ok(canAccessRoute(cristina, `/confirmare/finance/requests/${id(1)}`));
  assert.ok(canAccessRoute(cristina, `/confirmare/finance/requests/${id(1)}/detail`));
  const viewer = person({ financeScopes: { view: ["trendhome"], record: [], approve: [] } });
  assert.ok(!canUseFinanceInbox(viewer), "a view-only scope (the supervisor) has no desk");
  assert.ok(!canAccessRoute(viewer, `/confirmare/finance/requests/${id(1)}`));
  assert.deepEqual(parseStaffRoute(`/confirmare/finance/requests/${id(1)}`), { kind: "finance-task", pathname: `/confirmare/finance/requests/${id(1)}`, requestId: id(1) });
  assert.deepEqual(parseStaffRoute(`/confirmare/finance/requests/${id(1)}/detail`), { kind: "finance-task-detail", pathname: `/confirmare/finance/requests/${id(1)}/detail`, requestId: id(1) });
  // Staff 2.55.0: the finance task calls, plus the history and the two follow-up payments (no second approval, no refund).
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  const calls = [...new Set([...source.matchAll(/service\.([a-zA-Z]+)\(/g)].map((match) => match[1]))].sort();
  // Staff 2.57.0: plus her own search of any order and her own payment check (financeLookup, financeCheckOrder).
  assert.deepEqual(calls, ["addPayment", "correctPayment", "decideTask", "financeCheckOrder", "financeHistory", "financeInbox", "financeLookup", "financeTask", "reconcileTask", "startTask"]);
  assert.ok(canAccessRoute(cristina, `/confirmare/finance/requests/${id(1)}/done`));
  assert.deepEqual(parseStaffRoute(`/confirmare/finance/requests/${id(1)}/done`), { kind: "finance-task-done", pathname: `/confirmare/finance/requests/${id(1)}/done`, requestId: id(1) });
});

test("waiting time reads in minutes, hours or days", () => {
  assert.deepEqual(waitParts(new Date(NOW - 5 * 60_000).toISOString(), NOW), { unit: "minutes", n: 5, d: 0, h: 0 });
  assert.deepEqual(waitParts(new Date(NOW - 5 * 3600_000).toISOString(), NOW), { unit: "hours", n: 5, d: 0, h: 0 });
  assert.deepEqual(waitParts(new Date(NOW - 50 * 3600_000).toISOString(), NOW), { unit: "days", n: 50, d: 2, h: 2 });
});

test("every desk text exists in all Staff languages", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("fd."));
  assert.ok(keys.length > 60);
  for (const locale of ["tr", "en", "si"] as const) for (const key of keys) assert.ok(String(CATALOGS[locale][key as MessageKey] ?? "").length > 0, `${locale} ${key}`);
  assert.equal(CATALOGS.ro["fd.q.received"], "A fost primită plata?");
  assert.equal(CATALOGS.ro["fd.yes"], "DA — Plata a fost primită");
  assert.equal(CATALOGS.ro["fd.no"], "NU — Plata nu a fost primită");
  assert.equal(CATALOGS.ro["fd.confirm"], "Confirmă și continuă");
  assert.equal(CATALOGS.ro["fd.step"], "Etapa {n} din 3");
});

test("the desk renders its four counters and the table header without a shop call per row", () => {
  const service = { financeInbox: () => new Promise(() => undefined) } as never;
  const html = renderToStaticMarkup(createElement(FinanceDeskScreen, { employee: {} as Employee, service, navigate: () => undefined }));
  for (const kpi of ["todo", "waiting", "clarification", "today"]) assert.match(html, new RegExp(`data-testid="fd-kpi-${kpi}"`));
  assert.match(html, /data-testid="fd-search"/);
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  const desk = source.slice(source.indexOf("export function FinanceDeskScreen"), source.indexOf("// ---- The three-step check"));
  assert.doesNotMatch(desk, /financeTask\(|orderFinance|financeSummary/, "the list never reads a task or the shop per row");
});

test("Finalizate azi opens: the tasks this employee decided today (Bucharest), every closed task opens read-only", () => {
  const today = new Date(NOW - 3600_000).toISOString();
  const box = inbox([
    item(1, { state: "verified", category: "done", outcome: "verified_full", decidedAt: today, decidedByMe: true }),
    item(2, { state: "not_received", category: "done", outcome: "not_received", awaitingPayment: true, decidedAt: today, decidedByMe: true, recheckAt: "2026-11-26T06:00:00Z" }),
    item(3, { state: "verified", category: "done", outcome: "verified_full", decidedAt: "2026-11-24T21:30:00Z", decidedByMe: true }),
    item(4, { state: "verified", category: "done", outcome: "verified_full", decidedAt: today, decidedByMe: false }),
    item(5),
  ], { today: 2 });
  assert.deepEqual(deskRows(box, "today", null, "", NOW).map((row) => row.orderNumber).sort(), ["65001", "65002"]);
  assert.ok(decidedToday(box.items[0], NOW));
  assert.ok(!decidedToday(box.items[2], NOW), "23:30 Bucharest yesterday (21:30 UTC) is not today");
  assert.ok(decidedToday(mapInbox({ items: [item(6, { state: "verified", category: "done", decidedAt: "2026-11-24T22:30:00Z", decidedByMe: true })], counts: { todo: 0, clarification: 0, done: 1 }, sources: [], limit: 2 }).items[0], NOW), "00:30 Bucharest today (22:30 UTC) is today");
  assert.equal(box.items[1].recheckAt, "2026-11-26T06:00:00Z");
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  assert.match(source, /if \(item\.category === "done" && item\.purpose === "advance"\) return financeTaskDonePath\(item\.id\);/, "a closed advance task opens its read-only detail");
  assert.doesNotMatch(source, /const selectable = kpi\.id !== "today"/, "the today counter is a button like the others");
  assert.match(source, /onClick=\{\(event\) => \{ if \(!\(event\.target as HTMLElement\)\.closest\("button"\)\) navigate\(rowTarget\(item\)\); \}\}/, "the whole row opens the task");
  const html = renderToStaticMarkup(createElement(FinanceDeskScreen, { employee: {} as Employee, service: { financeInbox: () => new Promise(() => undefined) } as never, navigate: () => undefined }));
  assert.match(html, /<button[^>]*data-testid="fr-category-today"/);
});

test("a closed task: its money, its transfers (a correction keeps the old one), where the order stands", () => {
  const done = mapFinanceTask({
    request: item(1, { state: "verified", category: "done", outcome: "verified_partial", decidedAt: "2026-11-25T10:00:00Z", decidedByMe: true }),
    live: live({
      advance: { percentage: 30, required: "150.00", verified: "70.00", remaining: "80.00", accepted: true }, financial: { state: "cleared", attention: null },
      transfers: [
        { id: id(51), purpose: "advance", amount: "100.00", reference: "ING 4001", valueDate: "2026-11-24", method: "bank_transfer", state: "corrected", decisionCode: "verified", flags: ["single_verification"], submittedBy: { id: id(900), displayName: "Test Fin" }, submittedAt: "2026-11-25T09:00:00Z", decidedBy: { id: id(900), displayName: "Test Fin" }, decidedAt: "2026-11-25T09:00:00Z", payer: "Ion Popescu", matchBasis: "customer_name", matchNote: null, corrects: null },
        { id: id(52), purpose: "advance", amount: "70.00", reference: "ING 4001", valueDate: "2026-11-24", method: "bank_transfer", state: "verified", decisionCode: "corrected", flags: ["single_verification"], submittedBy: { id: id(900), displayName: "Test Fin" }, submittedAt: "2026-11-25T10:00:00Z", decidedBy: { id: id(900), displayName: "Test Fin" }, decidedAt: "2026-11-25T10:00:00Z", payer: "Ion Popescu", matchBasis: "customer_name", matchNote: null, corrects: id(51) },
      ],
      money: { known: true, total: "500.00", advanceRequired: "150.00", received: "70.00", remaining: "430.00", excess: "0.00" }, capabilities: { match: true, correction: true }, cod: { state: "none", awb: false },
    }),
    allowed: { start: false, verify: false, notReceived: false, clarify: false, secondCheck: false, reconcile: false, addPayment: true, correctPayment: true }, me: id(900), singleVerification: true,
    progress: { known: true, approval: "approved", callConfirmed: true, paymentCleared: true, productionCleared: true, missing: [], operator: { displayName: "Test Op" }, routedTo: { displayName: "Test Shop" }, routedAt: "2026-11-25T10:05:00Z", stage: "waiting", cancelled: false },
  });
  assert.equal(done.live.available && done.live.transfers[0].state, "corrected");
  assert.equal(done.live.available && done.live.transfers[1].corrects, id(51));
  assert.deepEqual(checkMoney(done), { remaining: "430.00", advanceRemaining: "80.00", total: "500.00", received: "70.00" }, "70 of a 500 order: cash on delivery 430");
  assert.ok(done.allowed.addPayment && done.allowed.correctPayment);
  const t = (key: MessageKey, values?: Record<string, string | number>) => `${key}${values ? JSON.stringify(values) : ""}`;
  const plan = milestones(done, t);
  assert.deepEqual(plan.items.map((step) => step.state), ["done", "done", "done", "done"], "approved, paid, cleared, routed: all green");
  assert.match(plan.now, /fd\.now\.shop.*Test Shop/, "now with the shop employee");
  const waiting = milestones({ ...done, progress: { ...done.progress!, approval: "pending", productionCleared: false, missing: ["approval", "call"], routedTo: null } }, t);
  assert.deepEqual(waiting.items.map((step) => step.state), ["waiting", "done", "waiting", "waiting"]);
  assert.match(waiting.now, /fd\.now\.operator.*Test Op/, "the order waits for the operator, not for finance (parallel work)");
  const older = mapFinanceTask({ request: item(2), live: live(), allowed: { start: true, verify: true, notReceived: true, clarify: true, secondCheck: false, reconcile: false }, me: id(900), singleVerification: true });
  assert.equal(older.progress, null, "an older API: no progress, no follow-up payments");
  assert.ok(!older.allowed.addPayment && !older.allowed.correctPayment);
  assert.deepEqual(older.live.available && older.live.capabilities, { match: false, correction: false });
});

test("API 2.81.0 / Staff 2.56.0: a task opened from Arasya's copy says so; a stale copy shows its age and enables no money decision", () => {
  const fresh = task(item(1), {}, live()).live;
  assert.ok(fresh.available);
  assert.equal(fresh.fromCopy, false);
  assert.equal(fresh.stale, false);
  assert.equal(fresh.staleReason, null);
  const copy = task(item(1), {}, live({ source: "cache", stale: false })).live;
  assert.ok(copy.available);
  assert.equal(copy.fromCopy, true);
  assert.equal(copy.stale, false);
  const stale = task(item(1), { verify: false }, live({ source: "cache", stale: true, staleReason: "source_unreachable" }));
  assert.ok(stale.live.available);
  assert.equal(stale.live.stale, true);
  assert.equal(stale.live.staleReason, "source_unreachable");
  assert.equal(stale.allowed.verify, false);
  const screen = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  assert.match(screen, /live\?\.stale && <Notice tone="warning" role="note" testId="fr-live-stale">\{t\("fd\.stale", \{ at: bucharestClock\(live\.readAt\) \}\)\}/);
  for (const catalog of Object.values(CATALOGS)) assert.match(String((catalog as unknown as Record<string, unknown>)["fd.stale"]), /\{at\}/);
});

test("Staff 2.56.0: a finance command waits up to 30 s for the API (the shop may be slow); other calls keep 12 s", () => {
  const requests = readFileSync(path.join(root, "domain/financeRequests.ts"), "utf8");
  const http = readFileSync(path.join(root, "services/production/httpServices.ts"), "utf8");
  assert.match(requests, /export const FINANCE_COMMAND_TIMEOUT_MS = 30_000;/);
  assert.match(requests, /timeoutMs: FINANCE_COMMAND_TIMEOUT_MS/);
  assert.match(http, /AbortSignal\.timeout\(timeoutMs \?\? 12_000\)/);
});
