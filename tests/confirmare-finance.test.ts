import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { ConfirmareDetail } from "../domain/confirmare";
import { canOpenConfirmareOrder, canUseConfirmare } from "../domain/confirmare";
import {
  FINANCE_NEXT, FINANCE_TASKS, canUseFinance, financeErrorKey, formatFinanceMoney, normalizeAmount, ownPendingEntries, refusalLabel, validReference, verifiableEntries, type FinanceView,
} from "../domain/confirmareFinance";
import { canAccessRoute } from "../domain/permissions";
import type { Employee } from "../domain/models";
import { mapConfirmareDetail, mapExceptionOutcome, mapFinanceOutcome, mapFinanceQueue, mapFinanceRead, mapTransferExceptionList } from "../services/production/httpServices";
import { TransferExceptionsSection } from "../features/confirmare/ConfirmareTransferExceptions";
import { ConfirmareFinancePanel } from "../features/confirmare/ConfirmareFinancePanel";
import { CATALOGS, type MessageKey } from "../i18n";

// Confirmare finance in Staff (Staff 2.31.0, API 2.41.0, Phase 5). TEST amounts and invented references only.
const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-0000000000f1";
const OTHER = "00000000-0000-4000-8000-0000000000f2";
const summary = {
  readAt: "2026-10-22T10:00:00Z", revision: `sha256:${"a".repeat(64)}`, currency: "RON",
  order: { id: "1234", number: "1234", status: "processing", modifiedAt: "2026-10-22T09:30:00Z", method: "netopiapayments", methodTitle: "Card online", cod: false },
  totals: { current: "650.00", original: "500.00", refunded: "0.00" },
  received: { online: "500.00", advance: null, transfers: "0.00", net: "500.00" },
  payment: { onlineVerified: true, paidRecorded: true, evidence: "netopia_ipn" },
  advance: null,
  balance: { outstanding: "150.00", refundLiability: "0.00", acknowledged: false },
  financial: { state: "waiting", basis: null, attention: "transfer_pending" },
  entries: [
    { id: "00000000-0000-4000-9000-000000000001", kind: "transfer_in", purpose: "additional_payment", amount: "150.00", reference: "OP TEST 01", valueDate: "2026-10-22", state: "submitted", submittedBy: OTHER, submittedAt: "2026-10-22T09:40:00Z", decidedBy: null, decidedAt: null, decisionCode: null },
  ],
  capabilities: { record: true, refunds: "manual", electronicRefund: false },
};
const readOk = {
  available: true, role: "finance", summary, production: { stageId: "waiting", handedOver: false, refundEligible: false },
  allowedActions: ["decide_transfer"], nextAction: "verify_transfer", actionOpen: false,
  timeline: [{ employeeId: OTHER, action: "submit_transfer", decision: null, reasonCode: null, purpose: "additional_payment", entryId: summary.entries[0]!.id, idempotencyKey: "fin-key-0000000001", state: "applied", outcome: "applied", requestedAt: "2026-10-22T09:40:00Z", completedAt: "2026-10-22T09:40:01Z" }],
  employees: { [OTHER]: "Test Recorder" }, me: ME,
};
const employee = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: ME, username: "fin.test", displayName: "Test Finance", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: [], record: [], approve: [] }, ...overrides,
} as Employee);
const detail = (overrides: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", status: "approved", version: 3, workflowCohort: "confirmare",
  openedAt: "2026-10-22T08:00:00Z", submittedAt: "2026-10-22T07:59:00Z", productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false,
  payment: { state: "online" }, financial: { state: "waiting", basis: null, open: false, attention: "transfer_pending" }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: null, decision: null,
  review: { fingerprint: "b".repeat(64), lineIds: ["12341"], pricingAvailable: true, callRecorded: true, contactAvailable: true }, lines: [], contacts: [],
  customer: { visible: false, phoneAvailable: false }, source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: false, missing: ["payment"] }, events: [],
  sourceAccess: { configured: true, read: false, edit: false, changePending: false, openEdits: [] }, invoice: { configured: false, read: false, issue: false, manage: false, proforma: false, route: false }, finance: { configured: true, read: true, record: false, approve: true }, handoff: null, ...overrides,
});

test("the finance summary is mapped strictly; an electronic refund is never accepted", () => {
  const mapped = mapFinanceRead(readOk);
  assert.equal(mapped.available, true);
  if (!mapped.available) return;
  assert.equal(mapped.summary.balance.outstanding, "150.00");
  assert.equal(mapped.summary.entries[0]?.state, "submitted");
  assert.deepEqual(mapFinanceRead({ available: false, reason: "not_configured" }), { available: false, reason: "not_configured" });
  const first = mapFinanceRead({ ...structuredClone(readOk), summary: { ...structuredClone(summary), entries: [] }, timeline: [], employees: {} });
  assert.ok(first.available && Object.keys(first.employees).length === 0, "an order without any finance record yet (no names)");
  const broken = (patch: (copy: typeof readOk) => void) => { const copy = structuredClone(readOk); patch(copy); return () => mapFinanceRead(copy); };
  assert.throws(broken((copy) => { (copy.summary.capabilities as { electronicRefund: unknown }).electronicRefund = true; }), "an electronic refund is never offered");
  assert.throws(broken((copy) => { copy.summary.totals.current = "6.5e2"; }), "a malformed amount");
  assert.throws(broken((copy) => { (copy.summary.financial as { attention: string }).attention = "paid"; }), "an unknown attention");
  assert.throws(broken((copy) => { (copy.allowedActions as string[]) = ["refund_by_card"]; }), "an unknown action");
  assert.throws(broken((copy) => { (copy as { role: string }).role = "root"; }), "an unknown role");
  assert.throws(broken((copy) => { copy.summary.entries[0]!.submittedBy = "admin"; }), "an entry author must be an employee id");
  const extra = mapFinanceRead({ ...structuredClone(readOk), iban: "RO49AAAA1B31007593840000", summary: { ...structuredClone(summary), card: "4111" } });
  assert.ok(!JSON.stringify(extra).includes("RO49") && !JSON.stringify(extra).includes("4111"), "unknown fields are never carried");
  assert.deepEqual(mapFinanceOutcome({ state: "unknown", idempotencyKey: "fin-key-0000000002" }), { state: "unknown", idempotencyKey: "fin-key-0000000002" });
  assert.equal(mapFinanceOutcome({ state: "applied", idempotencyKey: "fin-key-0000000003", entryId: summary.entries[0]!.id }).state, "applied");
  const queue = mapFinanceQueue({ tasks: Object.fromEntries(FINANCE_TASKS.map((task) => [task, 0])), orders: [], sources: ["trendhome"] });
  assert.equal(queue.tasks.transfer_verification, 0);
  assert.throws(() => mapFinanceQueue({ tasks: {}, orders: [], sources: [] }), "every task count is required");
});

test("finance scopes: separate from Confirmare, routes follow them, an older API grants nothing", () => {
  const finance = employee({ financeScopes: { view: [], record: [], approve: ["trendhome"] } });
  assert.equal(canUseFinance(finance), true);
  assert.equal(canUseConfirmare(finance), false, "a finance scope is no Confirmare scope");
  assert.equal(canOpenConfirmareOrder(finance), true);
  assert.equal(canAccessRoute(finance, "/confirmare/finance"), true);
  assert.equal(canAccessRoute(finance, "/confirmare/orders/trendhome%3A1234"), true);
  assert.equal(canAccessRoute(finance, "/confirmare"), false, "the operator queue stays with Confirmare scopes");
  assert.equal(canAccessRoute(finance, "/confirmare/team"), false);
  const operator = employee({ confirmareScopes: { operate: ["trendhome"], supervise: [] } });
  // API 2.44.0: an operator opens the advance groups of their shops (read only; the server shows advance tasks only).
  assert.equal(canAccessRoute(operator, "/confirmare/finance"), true, "an operator sees the advance tasks");
  assert.equal(canUseFinance(operator), false, "an operator still has no finance scope");
  assert.equal(canUseFinance(employee({ financeScopes: { view: ["b2b"], record: [], approve: [] } })), false, "only the two shops");
  assert.equal(canUseFinance(employee({ financeScopes: undefined })), false);
  const raw = { ...detail() } as Record<string, unknown>;
  delete raw.finance;
  assert.deepEqual(mapConfirmareDetail(raw).finance, { configured: false, read: false, record: false, approve: false }, "an API 2.40 detail offers no finance");
});

test("amounts and references are checked before anything is sent; the decider is never the recorder", () => {
  assert.equal(normalizeAmount("150"), "150.00");
  assert.equal(normalizeAmount("150,5"), "150.50");
  assert.equal(normalizeAmount("1.234,56"), "1234.56");
  assert.equal(normalizeAmount("1234.5"), "1234.50");
  for (const bad of ["", "0", "-5", "1e3", "12,345", "abc", "1.2.3", "150.001", "1.500"]) assert.equal(normalizeAmount(bad), null, bad);
  assert.equal(validReference("OP 1234 / 22.10"), true);
  for (const bad of ["x", "--", "<script>", "a".repeat(70)]) assert.equal(validReference(bad), false, bad);
  const mapped = mapFinanceRead(readOk);
  if (!mapped.available) throw new Error("expected a read");
  const view: FinanceView = mapped;
  assert.equal(verifiableEntries(view, "transfer_in").length, 1, "another employee's record may be verified");
  assert.equal(verifiableEntries({ ...view, me: OTHER }, "transfer_in").length, 0, "never one's own record");
  assert.equal(ownPendingEntries({ ...view, me: OTHER }).length, 1);
  assert.match(formatFinanceMoney("650.00", "RON"), /650,00/);
  assert.equal(formatFinanceMoney(null, "RON"), "—");
  assert.ok(financeErrorKey("CONFIRMARE_FINANCE_SAME_EMPLOYEE") && financeErrorKey("CONFIRMARE_FINANCE_REFUND_NOT_ELIGIBLE") && financeErrorKey("NOT_A_CODE") === null);
  assert.equal(refusalLabel("<script>"), refusalLabel(undefined), "an unknown refusal reads as a generic refusal");
});

test("the panel: no amount before an explicit read; honest states without permission or configuration", () => {
  const service = { financeRead: () => Promise.resolve(mapFinanceRead(readOk)) } as never;
  const render = (d: ConfirmareDetail) => renderToStaticMarkup(createElement(ConfirmareFinancePanel, { detail: d, service }));
  const closed = render(detail());
  assert.ok(closed.includes("cf-finance-load") && !closed.includes("650") && !closed.includes("OP TEST"), "no amount until the employee asks");
  assert.ok(render(detail({ finance: { configured: false, read: false, record: false, approve: false } })).includes("cf-finance-off"), "a shop without finance says so");
  const colleague = render(detail({ finance: { configured: true, read: false, record: false, approve: false } }));
  assert.ok(colleague.includes("cf-finance-not-yours") && !colleague.includes("cf-finance-load"), "without a permission nothing can be read");
  const source = readFileSync(path.join(root, "features/confirmare/ConfirmareFinancePanel.tsx"), "utf8");
  assert.ok(!/localStorage|sessionStorage|indexedDB|console\.|history\.(push|replace)State/.test(source), "money never reaches storage, the console or the URL");
  assert.ok(source.includes("cf-finance-confirm") && source.includes("cf.fin.unknown") && !/refund_payment|process_refund|capture/i.test(source), "a confirmation step, an honest unknown state, never a provider refund");
});

test("the finance task list only reads (classes, no amount) and the panel never refreshes by itself", () => {
  const screen = readFileSync(path.join(root, "features/confirmare/ConfirmareFinanceScreen.tsx"), "utf8");
  assert.ok(screen.includes("service.financeQueue(") && !/financeAct|financeReconcile|financeRead/.test(screen), "the refreshing list never acts or reads amounts");
  const panel = readFileSync(path.join(root, "features/confirmare/ConfirmareFinancePanel.tsx"), "utf8");
  assert.ok(!/useAutoRefresh|setInterval/.test(panel), "amounts are read only when the employee asks");
});

test("every finance text exists in every language", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("cf.fin.") || key.startsWith("cf.financial.attention."));
  assert.ok(keys.length > 190, `finance keys: ${keys.length}`);
  for (const locale of ["en", "tr", "si"] as const) for (const key of keys) assert.ok((CATALOGS[locale][key as MessageKey] as string).length > 0, `${locale} ${key}`);
  for (const next of FINANCE_NEXT) assert.ok(keys.includes(`cf.fin.next.${next}`) && keys.includes(`cf.fin.nextDetail.${next}`), next);
  for (const task of FINANCE_TASKS) assert.ok(keys.includes(`cf.fin.task.${task}`) && keys.includes(`cf.fin.taskHint.${task}`), task);
  for (const attention of ["transfer_pending", "reconciliation_required", "refund_liability", "refund_pending"]) assert.ok(keys.includes(`cf.financial.attention.${attention}`), attention);
});

test("YD SOFT 2.16 safety refusals: finance_open and order_cancelled explain why and who acts next", async () => {
  const { confirmareErrorDetails } = await import("../services/production/httpServices");
  const generic = refusalLabel(undefined);
  const open = refusalLabel(confirmareErrorDetails({ reason: "finance_open", revision: `sha256:${"a".repeat(64)}` }).editReason as string);
  const cancelled = refusalLabel(confirmareErrorDetails({ reason: "order_cancelled" }).editReason as string);
  assert.notEqual(open, generic, "finance_open has its own explanation");
  assert.notEqual(cancelled, generic, "order_cancelled has its own explanation");
  assert.match(open, /nedecisă/, "why: an open transfer or refund");
  assert.match(open, /altul decât cel care a înregistrat-o/, "who: a finance colleague other than the recorder decides the open record");
  assert.match(open, /responsabilul financiar/, "otherwise: the finance manager");
  assert.match(cancelled, /anulată în magazin/, "why: the shop cancelled the order");
  assert.match(cancelled, /doar respins/, "what stays possible: rejecting a pending transfer");
  assert.match(cancelled, /responsabilul financiar, care decide/, "who: the finance manager decides money that arrived anyway");
  assert.match(cancelled, /nu înregistrați plata aici/, "what not to do: record the money here");
  assert.doesNotMatch(`${open} ${cancelled}`, /rambursat|plătit cu succes|încasat/, "never reads as a payment or a refund");
  for (const known of ["revision_changed", "transfer_pending", "production_not_complete"]) assert.notEqual(refusalLabel(known), generic, `${known} keeps its explanation`);
});

// ---- Owner decision (API 2.44.0, YD SOFT 2.18.0): the advance is paid by bank transfer -----------------------------------
test("finance finds the order by its payment reference; the queue marks a transfer in verification", () => {
  const cod = structuredClone(readOk) as unknown as Record<string, unknown> & { summary: Record<string, unknown> };
  cod.summary = { ...structuredClone(summary), order: { ...summary.order, cod: true, method: "cod", methodTitle: "Ramburs" }, received: { online: null, advance: "0.00", transfers: "0.00", net: "0.00" },
    advance: { percentage: 30, required: "195.00", collected: "0.00", outstanding: "195.00", onDelivery: "650.00", paymentReference: "TH1234", proforma: { series: "PRF", number: "0007", amount: "195.00", currency: "RON", reference: "TH1234" } } };
  const mapped = mapFinanceRead(cod);
  assert.ok(mapped.available && mapped.summary.advance?.paymentReference === "TH1234" && mapped.summary.advance.proforma?.number === "0007");
  const older = structuredClone(cod);
  (older.summary.advance as Record<string, unknown>).paymentReference = undefined;
  (older.summary.advance as Record<string, unknown>).proforma = undefined;
  const olderMapped = mapFinanceRead(older);
  assert.ok(olderMapped.available && olderMapped.summary.advance?.paymentReference === null && olderMapped.summary.advance.proforma === null, "a shop before 2.18.0: no reference, no proforma");
  const bad = structuredClone(cod);
  (bad.summary.advance as Record<string, unknown>).paymentReference = "TH 1234; DROP";
  assert.throws(() => mapFinanceRead(bad), "a malformed payment reference is refused");
  const row = { globalOrderId: "trendhome:1234", source: "trendhome", orderNumber: "1234", task: "advance_waiting", financial: { state: "waiting", basis: null, attention: "transfer_pending" }, paymentMethod: "cod", stageId: "waiting", confirmation: "approved", dueDate: null, sourceChangedAt: null };
  const tasks = Object.fromEntries(FINANCE_TASKS.map((task) => [task, 0]));
  assert.equal(mapFinanceQueue({ tasks, orders: [{ ...row, verifying: true }], sources: ["trendhome"] }).orders[0]?.verifying, true);
  assert.equal(mapFinanceQueue({ tasks, orders: [row], sources: ["trendhome"] }).orders[0]?.verifying, false, "an older API: not in verification");
});

const exception = (over: Record<string, unknown> = {}) => ({
  id: "00000000-0000-4000-9000-0000000000e1", amount: "250.00", currency: "RON", reference: "OP TEST EXC 1", valueDate: "2026-10-24", reason: "unmatched", state: "open",
  submittedBy: OTHER, submittedAt: "2026-10-24T09:00:00Z", resolvedBy: null, resolvedAt: null, resolution: null, order: null, ...over,
});
const exceptionList = (items: unknown[], capabilities = { record: true, approve: true }) => ({ source: "trendhome", available: true, currency: "RON", exceptions: items, capabilities });

test("unmatched bank transfers: strict answers, four eyes in the screen, never money of an order", () => {
  const list = mapTransferExceptionList(exceptionList([exception(), exception({ id: "00000000-0000-4000-9000-0000000000e2", state: "resolved", resolution: "recorded_on_order", resolvedBy: ME, resolvedAt: "2026-10-24T10:00:00Z", order: { id: "1234", number: "1234" } })]));
  assert.ok(list.available && list.exceptions.length === 2 && list.exceptions[1]?.order?.number === "1234");
  assert.deepEqual(mapTransferExceptionList({ source: "outletperdele", available: false, reason: "not_configured", capabilities: { record: false, approve: false } }),
    { source: "outletperdele", available: false, reason: "not_configured", exceptions: [], capabilities: { record: false, approve: false } });
  for (const bad of [exception({ reason: "lost" }), exception({ state: "resolved" }), exception({ resolution: "returned_to_payer" }), exception({ amount: "250" }), exception({ submittedBy: "admin" })]) {
    assert.throws(() => mapTransferExceptionList(exceptionList([bad])), JSON.stringify(bad));
  }
  assert.deepEqual(mapExceptionOutcome({ state: "unknown", idempotencyKey: "fin-exc-0000000001" }), { state: "unknown", idempotencyKey: "fin-exc-0000000001" });
  assert.equal(mapExceptionOutcome({ state: "applied", entryId: exception().id, ...exceptionList([exception()]) }).state, "applied");
  const section = readFileSync(path.join(root, "features/confirmare/ConfirmareTransferExceptions.tsx"), "utf8");
  assert.ok(!/useAutoRefresh|setInterval|financeAct|localStorage|sessionStorage/.test(section), "read when shown, acts only on an explicit confirmation, keeps nothing");
  const html = renderToStaticMarkup(createElement(TransferExceptionsSection, { employee: employee({ financeScopes: { view: [], record: ["trendhome"], approve: ["trendhome"] } }), service: { transferExceptions: () => new Promise(() => undefined) } as never }));
  assert.ok(html.includes('data-testid="cf-exceptions"') && html.includes(CATALOGS.ro["cf.fin.exc.lead"]), "the section explains it is never an order's money");
  assert.equal(renderToStaticMarkup(createElement(TransferExceptionsSection, { employee: employee(), service: { transferExceptions: () => new Promise(() => undefined) } as never })), "", "without a finance scope: nothing");
  assert.equal(refusalLabel("transfer_not_recorded"), CATALOGS.ro["cf.fin.refusal.transfer_not_recorded"]);
});

// ---- API 2.45.0 (YD SOFT 2.19.0): one money calculation, cash with its document, confirmations, deadline, AWB -----------
test("the master update finance fields are strict: money position, cash method and document, confirmations, deadline, AWB", () => {
  const cashEntry = { id: "00000000-0000-4000-9000-000000000002", kind: "transfer_in", purpose: "advance", amount: "1050.00", reference: "BF 1234", valueDate: "2026-10-24", state: "verified", submittedBy: OTHER,
    submittedAt: "2026-10-24T09:40:00Z", decidedBy: ME, decidedAt: "2026-10-24T09:50:00Z", decisionCode: "verified", method: "cash_office", receipt: "bon_fiscal", collector: "Ion Popescu", flags: ["overpayment"] };
  const cod = {
    ...summary, order: { ...summary.order, method: "cod", methodTitle: "Ramburs", cod: true }, totals: { current: "1000.00", original: null, refunded: "0.00" },
    received: { online: null, advance: "1050.00", transfers: "0.00", net: "1050.00" }, payment: { onlineVerified: false, paidRecorded: false, evidence: "none" },
    advance: { percentage: 30, required: "300.00", collected: "1050.00", outstanding: "0.00", onDelivery: "0.00", paymentReference: "TH1234", proforma: null, accepted: false, proformaCancelled: false },
    balance: { outstanding: null, refundLiability: "50.00", acknowledged: false }, financial: { state: "waiting", basis: null, attention: "refund_due" }, entries: [cashEntry],
    position: { currency: "RON", percentage: 30, known: true, total: "1000.00", advanceRequired: "300.00", received: "1050.00", refunded: "0.00", net: "1050.00", remaining: "0.00", excess: "50.00", advanceGap: "0.00" },
    deadline: { state: "open", firstCollection: "2026-10-24", deadline: "2026-11-15", daysLeft: 2, level: "warning" },
    cod: { state: "mismatch", awb: "2345678", awbCod: "700.00", expected: "0.00", status: "AWB creat" },
  };
  const mapped = mapFinanceRead({ ...readOk, summary: cod, allowedActions: ["acknowledge_liability"], nextAction: "acknowledge_refund" });
  assert.equal(mapped.available, true);
  if (!mapped.available) return;
  assert.equal(mapped.summary.position?.remaining, "0.00");
  assert.equal(mapped.summary.position?.excess, "50.00");
  assert.deepEqual([mapped.summary.entries[0]!.method, mapped.summary.entries[0]!.receipt, mapped.summary.entries[0]!.collector, mapped.summary.entries[0]!.flags], ["cash_office", "bon_fiscal", "Ion Popescu", ["overpayment"]]);
  const html = renderToStaticMarkup(createElement(ConfirmareFinancePanel, { detail: detail(), service: { financeRead: () => new Promise(() => undefined) } as never }));
  assert.ok(html.length > 0);
  for (const bad of [{ position: { ...cod.position, remaining: "-50.00" } }, { position: { ...cod.position, excess: "0.00" } }, { entries: [{ ...cashEntry, method: "cash_drawer" }] }, { entries: [{ ...cashEntry, flags: ["forgiven"] }] },
    { cod: { ...cod.cod, state: "fixed" } }, { deadline: { ...cod.deadline, level: "relaxed" } }]) {
    assert.throws(() => mapFinanceRead({ ...readOk, summary: { ...cod, ...bad } }), Error, JSON.stringify(bad));
  }
  const older = mapFinanceRead(readOk);
  assert.ok(older.available && older.summary.position === null && older.summary.entries[0]!.method === "bank_transfer" && older.summary.entries[0]!.flags.length === 0, "an older shop: a bank transfer, no position invented");
  assert.equal(refusalLabel("cash_document_required"), CATALOGS.ro["cf.fin.refusal.cash_document_required"]);
  assert.equal(refusalLabel("proforma_cancelled"), CATALOGS.ro["cf.fin.refusal.proforma_cancelled"]);
  for (const key of ["cf.fin.method.cash_courier", "cf.fin.receipt.chitanta", "cf.fin.overpaymentCheck", "cf.fin.cancelledProformaCheck"] as MessageKey[]) {
    for (const locale of ["ro", "tr", "en", "si"] as const) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  }
});
