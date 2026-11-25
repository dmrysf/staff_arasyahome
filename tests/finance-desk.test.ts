import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import {
  answerLost, checkEntry, checkPayment, deskRows, deskView, matchesSearch, nextDeskTask, noteDeskDone, takeDeskDone, validValueDate, waitParts,
} from "../domain/financeDesk";
import { canUseFinanceInbox, mapFinanceTask, mapInbox, type Inbox } from "../domain/financeRequests";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { opensFinanceInbox } from "../domain/operatorDesk";
import type { Employee } from "../domain/models";
import { CATALOGS, type MessageKey } from "../i18n";
import { FinanceDeskScreen } from "../features/confirmare/FinanceDesk";

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
const form = (patch: Record<string, string> = {}) => ({ amount: "300,00", bankReference: "ING 2026 0001", valueDate: "2026-11-24", statementReference: "TH65001", ...patch });

test("full advance: recorded as typed, no gap, the proforma's reference", () => {
  const check = checkPayment(form(), "TH65001", "300.00", NOW);
  assert.deepEqual(check, { kind: "ok", input: { amount: "300.00", reference: "ING 2026 0001", valueDate: "2026-11-24", paymentReference: "TH65001" }, gap: null });
});

test("partial advance: the real amount is recorded and the difference is named (the acceptance rules apply after)", () => {
  const check = checkPayment(form({ amount: "299.80" }), "TH65001", "300.00", NOW);
  assert.equal(check.kind, "ok");
  assert.equal(check.kind === "ok" && check.input.amount, "299.80", "the expected amount is never taken as the payment");
  assert.equal(check.kind === "ok" && check.gap, "0.20");
});

test("overpayment and a reference mismatch go to clarification, never recorded", () => {
  const over = checkPayment(form({ amount: "350" }), "TH65001", "300.00", NOW);
  assert.equal(over.kind, "over");
  assert.equal(over.kind === "over" && over.clarify, "amount_unclear");
  const mismatch = checkPayment(form({ statementReference: "TH99999" }), "TH65001", "300.00", NOW);
  assert.equal(mismatch.kind, "mismatch");
  assert.equal(mismatch.kind === "mismatch" && mismatch.clarify, "reference_missing");
  assert.equal(checkPayment(form({ statementReference: "th65001" }), "TH65001", "300.00", NOW).kind, "ok", "case is ignored");
});

test("every field is required and checked; the expected amount is never prefilled", () => {
  assert.deepEqual(checkPayment(form({ amount: "" }), "TH65001", "300.00", NOW), { kind: "invalid", field: "amount" });
  assert.deepEqual(checkPayment(form({ amount: "0" }), "TH65001", "300.00", NOW), { kind: "invalid", field: "amount" });
  assert.deepEqual(checkPayment(form({ bankReference: "x" }), "TH65001", "300.00", NOW), { kind: "invalid", field: "bankReference" });
  assert.deepEqual(checkPayment(form({ valueDate: "" }), "TH65001", "300.00", NOW), { kind: "invalid", field: "valueDate" });
  assert.deepEqual(checkPayment(form({ statementReference: " " }), "TH65001", "300.00", NOW), { kind: "invalid", field: "statementReference" });
  assert.equal(validValueDate("2026-02-30", NOW), false, "not a calendar day");
  assert.equal(validValueDate("2026-12-31", NOW), false, "a payment date in the future");
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  assert.match(source, /const EMPTY_FORM: PaymentForm = \{ amount: "", bankReference: "", valueDate: "", statementReference: "" \};/);
});

test("several transfers: the second one is checked against the advance still due", () => {
  const second = checkPayment(form({ amount: "100" }), "TH65001", "100.00", NOW);
  assert.equal(second.kind === "ok" && second.gap, null);
  assert.equal(checkPayment(form({ amount: "150" }), "TH65001", "100.00", NOW).kind, "over");
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
  // No new command: the desk uses the Staff 2.41.0 finance task calls only (no second approval, no refund).
  const source = readFileSync(path.join(root, "features/confirmare/FinanceDesk.tsx"), "utf8");
  const calls = [...new Set([...source.matchAll(/service\.([a-zA-Z]+)\(/g)].map((match) => match[1]))].sort();
  assert.deepEqual(calls, ["decideTask", "financeInbox", "financeTask", "reconcileTask", "startTask"]);
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
