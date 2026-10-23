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
import { mapConfirmareDetail, mapFinanceOutcome, mapFinanceQueue, mapFinanceRead } from "../services/production/httpServices";
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
  sourceAccess: { configured: true, read: false, edit: false, changePending: false, openEdits: [] }, finance: { configured: true, read: true, record: false, approve: true }, handoff: null, ...overrides,
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
  assert.equal(canAccessRoute(operator, "/confirmare/finance"), false, "an operator has no finance tasks");
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
