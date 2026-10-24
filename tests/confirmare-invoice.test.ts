import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { INVOICE_ACTIONS, blockersOf, invoiceBlockerLabel, invoiceErrorKey, type InvoiceView } from "../domain/confirmareInvoice";
import { mapInvoiceOutcome, mapInvoiceRead } from "../services/production/httpServices";
import { ConfirmareInvoicePanel, InvoiceBody } from "../features/confirmare/ConfirmareInvoicePanel";
import { CATALOGS, type MessageKey } from "../i18n";

// SmartBill invoicing in Staff (Staff 2.33.0, API 2.43.0). TEST orders, numbers and links only.
const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-0000000000d1";
const MGR = "00000000-0000-4000-8000-0000000000e1";
const none = { issue: [], sendMail: [], reissue: [], resolve: [] };
const summary = (overrides: Record<string, unknown> = {}) => ({
  readAt: "2026-10-24T10:00:00Z", revision: `sha256:${"c".repeat(64)}`, mode: "enforce",
  smartbill: { available: true, reason: null, documentType: "invoice", autoEmail: false, cashing: false, draft: false, mailSupported: true, autoIssue: false },
  order: { id: "1234", number: "1234", status: "processing", total: "719.00", currency: "RON", billingEmail: true },
  financial: { state: "cleared", basis: "online_payment", attention: null, pending: false },
  document: { exists: false, type: null, series: null, number: null, status: null, conflict: false, through: null, issuedAt: null, issuedBy: null, total: null, currency: null, outdated: null, link: null },
  operation: { state: "none", kind: null, startedAt: null, by: null, error: null },
  mail: { state: "none", at: null, by: null, count: 0 },
  history: [],
  blockers: none,
  ...overrides,
});
const read = (role: string, allowed: string[], over: Record<string, unknown> = {}, blockers: Record<string, string[]> = none) => ({
  available: true, role, summary: summary(over), handoff: { state: "ready", blockers: [] }, blockers, allowedActions: allowed, actionOpen: false, timeline: [], employees: { [ME]: "Test Documents", [MGR]: "Test Manager" }, me: ME,
});
const issued = {
  document: { exists: true, type: "invoice", series: "TST", number: "0007", status: "final", conflict: false, through: "arasya", issuedAt: "2026-10-24T09:00:00Z", issuedBy: ME, total: "719.00", currency: "RON", outdated: false,
    link: "https://cloud.smartbill.ro/core/factura/vizualizare/?serie=TST&numar=0007" },
  operation: { state: "issued", kind: "issue", startedAt: "2026-10-24T09:00:00Z", by: ME, error: null },
  mail: { state: "sent", at: "2026-10-24T09:00:01Z", by: ME, count: 1 },
  history: [{ kind: "auto_mail", at: "2026-10-24T09:00:01Z", by: ME, outcome: "sent", reason: null, series: null, number: null }, { kind: "issue", at: "2026-10-24T09:00:00Z", by: ME, outcome: "issued", reason: null, series: "TST", number: "0007" }],
};
const body = (value: ReturnType<typeof read>, pending: { action: (typeof INVOICE_ACTIONS)[number]; key: string } | null = null) => {
  const mapped = mapInvoiceRead(value);
  if (!mapped.available) throw new Error("expected a read");
  return renderToStaticMarkup(createElement(InvoiceBody, { globalOrderId: "trendhome:1234", view: mapped, role: mapped.role, busy: false, pending, onPrepare: () => undefined, onCancel: () => undefined, onSend: () => undefined, onReconcile: () => undefined }));
};

test("the answers are strict: a foreign link, an unknown code or a malformed state is refused, never half shown", () => {
  assert.equal(mapInvoiceRead(read("documents", ["issue"])).available, true);
  assert.equal(mapInvoiceRead({ available: false, reason: "not_configured" }).available, false);
  for (const link of ["http://cloud.smartbill.ro/x", "https://smartbill.ro.evil.example/x", "https://user:pw@cloud.smartbill.ro/x", "https://cloud.smartbill.ro:8443/x", "javascript:alert(1)"]) {
    assert.throws(() => mapInvoiceRead(read("documents", [], { ...issued, document: { ...issued.document, link } })), Error, link);
  }
  assert.throws(() => mapInvoiceRead(read("documents", ["delete_everything"])), Error, "an unknown action is refused");
  assert.throws(() => mapInvoiceRead(read("documents", [], { mail: { state: "spam", at: null, by: null, count: 0 } })), Error, "an unknown mail state is refused");
  assert.throws(() => mapInvoiceRead({ ...read("root", []) }), Error, "an unknown role is refused");
  assert.deepEqual(mapInvoiceOutcome({ state: "unknown", idempotencyKey: "inv-key-000000000001", reachable: false }), { state: "unknown", idempotencyKey: "inv-key-000000000001", reachable: false });
});

test("the production ticket employee sees the issue button with the shop's document type; nothing else", () => {
  const html = body(read("documents", ["issue"]));
  assert.ok(html.includes('data-testid="cf-invoice-issue"') && html.includes(CATALOGS.ro["inv.action.issueInvoice"]) && !html.includes("cf-invoice-reissue") && !html.includes("cf-invoice-send-mail"), "issue only");
  const proforma = body(read("documents", ["issue"], { smartbill: { ...summary().smartbill, documentType: "proforma" } }));
  assert.ok(proforma.includes(CATALOGS.ro["inv.action.issueProforma"]), "the shop is set to proforma: the button says proforma (no choice in Arasya)");
  assert.ok(html.includes("719,00") && html.includes("#1234") && html.includes("Trend Home"), "the order, the shop and the amount to invoice");
});

test("read-only roles, blockers in words, outdated and unknown documents", () => {
  const viewer = body(read("viewer", [], issued, { ...none, issue: ["not_allowed_for_role"] }));
  assert.ok(viewer.includes("cf-invoice-readonly") && !viewer.includes("cf-invoice-actions") && viewer.includes("0007"), "a viewer sees the document, never an action");
  const finance = body(read("finance", [], { ...issued, document: { ...issued.document, link: null } }));
  assert.ok(!finance.includes("cf-invoice-view-link"), "finance gets no SmartBill link");
  const blocked = body(read("documents", [], {}, { ...none, issue: ["handoff_not_ready"] }));
  assert.ok(blocked.includes(CATALOGS.ro["inv.blocker.handoff_not_ready"]) && !blocked.includes(">handoff_not_ready<"), "a blocker is shown in words, never as a code");
  const outdated = body(read("manager", ["reissue"], { ...issued, document: { ...issued.document, outdated: true } }));
  assert.ok(outdated.includes("cf-invoice-outdated") && outdated.includes(CATALOGS.ro["inv.outdated"]), "an outdated document is marked");
  const unknown = body(read("manager", ["resolve"], { operation: { state: "unknown", kind: "issue", startedAt: "2026-10-24T09:00:00Z", by: ME, error: "transport_error" } }));
  assert.ok(unknown.includes("cf-invoice-unknown") && unknown.includes("cf-invoice-resolve") && !unknown.includes("cf-invoice-issue"), "an unknown outcome: no issue button, a manager resolves");
  const observe = body(read("documents", [], { mode: "observe" }, { ...none, issue: ["invoice_observe"] }));
  assert.ok(observe.includes("cf-invoice-observe") && !observe.includes("cf-invoice-issue"), "observe: nothing to press");
  assert.equal(invoiceBlockerLabel("<script>"), invoiceBlockerLabel(undefined), "an unknown code reads as a generic refusal");
  const mapped = mapInvoiceRead(read("documents", [], {}, { ...none, issue: ["same_employee"] }));
  if (mapped.available) assert.deepEqual(blockersOf(mapped as InvoiceView, "issue"), ["same_employee"]);
});

test("every action is confirmed once, with the order as it is; re-issue needs the statement; resend says it was sent", () => {
  const issue = body(read("documents", ["issue"]), { action: "issue", key: "inv-key-000000000001" });
  assert.ok(issue.includes("cf-invoice-confirm") && issue.includes("cf-invoice-reviewed") && /disabled=""[^>]*data-testid="cf-invoice-send"/.test(issue), "issue: the final order must be confirmed before sending");
  const resend = body(read("documents", ["sendMail"], issued), { action: "send_mail", key: "inv-key-000000000002" });
  assert.ok(resend.includes(CATALOGS.ro["inv.confirm.resend"].replace("{count}", "1")), "a second e-mail says how many were sent");
  const reissue = body(read("manager", ["reissue"], issued), { action: "reissue", key: "inv-key-000000000003" });
  assert.ok(reissue.includes("cf-invoice-previous-cancelled") && reissue.includes("cf-invoice-reason") && /disabled=""[^>]*data-testid="cf-invoice-send"/.test(reissue), "re-issue of an invoice: the statement first");
});

test("the panel keeps nothing, never acts by itself and shows one state on every screen", () => {
  const source = readFileSync(path.join(root, "features/confirmare/ConfirmareInvoicePanel.tsx"), "utf8");
  assert.ok(!/localStorage|sessionStorage|indexedDB|console\.|history\.(push|replace)State|setInterval/.test(source), "no storage, console, URL or polling");
  assert.ok(source.includes("announceInvoiceChange(globalOrderId)") && source.includes("onInvoiceChange("), "after any action every panel of the order reads again");
  assert.ok(source.includes('rel="noopener noreferrer"') && source.includes('target="_blank"'), "the SmartBill link opens apart, without the referrer");
  const domain = readFileSync(path.join(root, "domain/confirmareInvoice.ts"), "utf8");
  assert.ok(/postMessage\(\{ globalOrderId \}\)/.test(domain), "only the order id travels between tabs");
  const screen = readFileSync(path.join(root, "features/confirmare/ConfirmareOrderScreen.tsx"), "utf8");
  const documents = readFileSync(path.join(root, "features/documents/DocumentScreen.tsx"), "utf8");
  assert.ok(screen.includes("<ConfirmareInvoicePanel") && documents.includes("<ConfirmareInvoicePanel"), "the order detail and the production ticket screen show the same panel");
  const html = renderToStaticMarkup(createElement(ConfirmareInvoicePanel, { globalOrderId: "trendhome:1", service: { invoiceRead: () => new Promise(() => undefined) } as never, capabilities: { configured: true, read: false, issue: false, manage: false } }));
  assert.equal(html, "", "an employee without the right sees no SmartBill section");
});

test("every SmartBill text exists in Romanian and Turkish (and the other catalogs)", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("inv.")) as MessageKey[];
  assert.ok(keys.length > 100, `invoice keys: ${keys.length}`);
  for (const locale of ["tr", "en", "si"] as const) for (const key of keys) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  assert.equal(CATALOGS.ro["inv.title"], "Facturare SmartBill");
  assert.equal(CATALOGS.tr["inv.title"], "SmartBill Faturalandırma");
  assert.equal(CATALOGS.ro["inv.action.issueInvoice"], "Emite factura în SmartBill");
  assert.equal(CATALOGS.tr["inv.action.issueInvoice"], "SmartBill'de Fatura Oluştur");
  assert.equal(CATALOGS.tr["inv.action.view"], "SmartBill'de Görüntüle");
  assert.equal(CATALOGS.tr["inv.action.sendMail"], "Faturayı Müşteriye Gönder");
  assert.equal(CATALOGS.tr["inv.action.reissue"], "Belgeyi Yeniden Düzenle");
  assert.ok(invoiceErrorKey("CONFIRMARE_INVOICE_SAME_EMPLOYEE") && invoiceErrorKey("NOT_A_CODE") === null);
});
