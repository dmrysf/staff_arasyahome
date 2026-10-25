import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { INVOICE_ACTIONS, PROFORMA_ROUTE, advanceStatusKey, blockersOf, invoiceBlockerLabel, invoiceErrorKey, proformaStatusKey, type InvoiceView } from "../domain/confirmareInvoice";
import { mapInvoiceDeadline, mapInvoiceOutcome, mapInvoiceRead, mapMoneyPosition } from "../services/production/httpServices";
import { ConfirmareInvoicePanel, InvoiceBody } from "../features/confirmare/ConfirmareInvoicePanel";
import { ProformaSection } from "../features/confirmare/ConfirmareProformaSections";
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
  const html = renderToStaticMarkup(createElement(ConfirmareInvoicePanel, { globalOrderId: "trendhome:1", service: { invoiceRead: () => new Promise(() => undefined) } as never, capabilities: { configured: true, read: false, issue: false, manage: false, proforma: false, route: false } }));
  assert.equal(html, "", "an employee without the right sees no SmartBill section");
});

test("every SmartBill text exists in Romanian and Turkish (and the other catalogs)", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("inv.")) as MessageKey[];
  assert.ok(keys.length > 100, `invoice keys: ${keys.length}`);
  for (const locale of ["tr", "en", "si"] as const) for (const key of keys) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  assert.equal(CATALOGS.ro["inv.title"], "Facturare SmartBill");
  assert.equal(CATALOGS.tr["inv.title"], "SmartBill Faturalandırma");
  assert.equal(CATALOGS.ro["inv.action.issueInvoice"], "Emite factura SmartBill");
  assert.equal(CATALOGS.tr["inv.action.issueInvoice"], "SmartBill'de Fatura Oluştur");
  assert.equal(CATALOGS.tr["inv.action.view"], "SmartBill'de Görüntüle");
  assert.equal(CATALOGS.tr["inv.action.sendMail"], "Faturayı Müşteriye Gönder");
  assert.equal(CATALOGS.tr["inv.action.reissue"], "Belgeyi Yeniden Düzenle");
  assert.ok(invoiceErrorKey("CONFIRMARE_INVOICE_SAME_EMPLOYEE") && invoiceErrorKey("NOT_A_CODE") === null);
});

// ---- API 2.44.0 / Staff 2.34.0: the operator's advance proforma, the routing, the invoice QR scan ----------------------
const OP = "00000000-0000-4000-8000-0000000000a1";
const pfNone = { proformaIssue: [], proformaSendMail: [], proformaCheck: [], proformaResolve: [] };
const proformaShop = (over: Record<string, unknown> = {}) => ({
  advance: { required: "215.70", received: "0.00", outstanding: "215.70", percentage: 30.0, cod: true }, state: "none", document: null,
  mail: { state: "none", at: null, by: null, count: 0 }, invoiced: null, error: null, history: [], blockers: pfNone, ...over,
});
const operatorRead = (shop: Record<string, unknown>, allowed: string[], extra: Record<string, unknown> = {}) => ({
  ...read("operator", [], { financial: { state: "waiting", basis: null, attention: null, pending: false }, proforma: shop }), me: OP,
  proforma: { blockers: pfNone, allowedActions: allowed, role: "operator" }, assignment: null, routing: { allowed: false, blockers: ["advance_not_verified"] }, qr: { verified: false, validSeconds: 1800 }, ...extra,
});
const panelHtml = (value: Record<string, unknown>) => {
  const mapped = mapInvoiceRead(value);
  if (!mapped.available) throw new Error("expected a read");
  return mapped;
};

test("the proforma answers are strict: amounts, link, actions and roles are checked; an older API reads as no proforma", () => {
  const ok = panelHtml(operatorRead(proformaShop(), ["proformaIssue"]));
  assert.equal(ok.summary.proforma?.advance.outstanding, "215.70");
  assert.deepEqual(ok.proforma?.allowedActions, ["proformaIssue"]);
  assert.equal(ok.qr.verified, false);
  const older = mapInvoiceRead(read("documents", ["issue"]));
  assert.ok(older.available && older.summary.proforma === null && older.proforma === null && older.qr.verified === false, "API 2.43.0: no proforma, nothing scanned");
  for (const bad of [{ advance: { ...proformaShop().advance, outstanding: "215.7" } }, { advance: { ...proformaShop().advance, percentage: 130 } }, { state: "paid" }]) {
    assert.throws(() => mapInvoiceRead(operatorRead(proformaShop(bad), [])), Error, JSON.stringify(bad));
  }
  const foreign = { series: "PRF", number: "0001", amount: "215.70", currency: "RON", issuedAt: null, issuedBy: null, outdated: false, link: "https://smartbill.ro.evil.example/p" };
  assert.throws(() => mapInvoiceRead(operatorRead(proformaShop({ document: foreign }), [])), Error, "a foreign proforma link is refused");
  assert.throws(() => mapInvoiceRead(operatorRead(proformaShop(), ["issue_final_invoice"])), Error, "an unknown proforma action is refused");
  assert.throws(() => mapInvoiceRead({ ...operatorRead(proformaShop(), []), routing: { allowed: "yes", blockers: [] } }), Error, "a malformed routing state is refused");
});

test("the operator sees the advance proforma and the routing, never a final invoice action", () => {
  const html = renderToStaticMarkup(createElement(ConfirmareInvoicePanel, { globalOrderId: "trendhome:1234", service: { invoiceRead: () => new Promise(() => undefined) } as never, capabilities: { configured: true, read: true, issue: false, manage: false, proforma: true, route: true } }));
  assert.ok(html.includes('data-testid="cf-invoice"'), "the panel renders for the operator");
  const view = panelHtml(operatorRead(proformaShop(), ["proformaIssue"]));
  const body = renderToStaticMarkup(createElement(InvoiceBody, { globalOrderId: "trendhome:1234", view, role: view.role, busy: false, pending: null, onPrepare: () => undefined, onCancel: () => undefined, onSend: () => undefined, onReconcile: () => undefined }));
  assert.ok(!body.includes("cf-invoice-issue") && !body.includes("cf-invoice-actions") && body.includes('data-testid="cf-invoice-readonly"'), "no final invoice action for the operator");
});

test("the proforma, routing and QR words exist in every catalog; the owner's group names", () => {
  for (const key of ["inv.pf.action.issue", "inv.pf.action.mail", "inv.pf.view", "inv.route.action", "inv.qr.title", "inv.blocker.qr_not_verified", "inv.blocker.not_assigned", "inv.blocker.proforma_invoiced"] as MessageKey[]) {
    for (const locale of ["ro", "tr", "en", "si"] as const) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  }
  assert.equal(CATALOGS.ro["inv.pf.action.issue"], "Emite proforma de avans");
  assert.equal(CATALOGS.ro["inv.pf.action.mail"], "Trimite proforma");
  assert.equal(CATALOGS.ro["inv.pf.view"], "Vezi proforma");
  assert.equal(CATALOGS.ro["inv.route.action"], "Direcționează către magazin");
  assert.equal(CATALOGS.ro["cf.fin.task.advance_waiting"], "În așteptarea avansului");
  assert.equal(CATALOGS.ro["cf.fin.task.advance_partial"], "Avans parțial");
  assert.equal(CATALOGS.ro["cf.fin.task.advance_verified"], "Avans plătit");
  assert.equal(CATALOGS.ro["cf.fin.task.advance_routed"], "Direcționate către magazin");
  assert.ok(invoiceErrorKey("INVOICE_QR_REFUSED") && invoiceErrorKey("CONFIRMARE_ASSIGNMENT_CHANGED"));
  assert.equal(invoiceBlockerLabel("assigned_to_other"), CATALOGS.ro["inv.blocker.assigned_to_other"]);
});

test("the final invoice blockers name the routing and the QR scan in the employee's words", () => {
  const view = panelHtml({ ...read("documents", [], {}, { ...none, issue: ["not_assigned", "qr_not_verified"] }), proforma: null, assignment: null, routing: null, qr: { verified: false, validSeconds: 1800 } });
  assert.deepEqual(blockersOf(view, "issue"), ["not_assigned", "qr_not_verified"]);
  const html = renderToStaticMarkup(createElement(InvoiceBody, { globalOrderId: "trendhome:1234", view, role: view.role, busy: false, pending: null, onPrepare: () => undefined, onCancel: () => undefined, onSend: () => undefined, onReconcile: () => undefined }));
  assert.ok(html.includes(CATALOGS.ro["inv.blocker.not_assigned"]) && html.includes(CATALOGS.ro["inv.blocker.qr_not_verified"]) && !html.includes("cf-invoice-issue"));
});

// ---- Owner decision (YD SOFT 2.18.0): the advance is paid by bank transfer to the account printed on the proforma ------
const transferShop = (advance: Record<string, unknown> = {}, over: Record<string, unknown> = {}) => proformaShop({
  advance: { ...proformaShop().advance, state: "waiting", verifying: false, ...advance },
  transfer: { iban: "RO49AAAA1B31007593840000", bank: "Banca TEST", beneficiary: "TEST Trend Home SRL", reference: "TH1234" },
  state: "issued", document: { series: "PRF", number: "0007", amount: "215.70", currency: "RON", reference: "TH1234", issuedAt: "2026-10-24T09:00:00Z", issuedBy: OP, outdated: false, link: null },
  ...over,
});
const proformaHtml = (view: ReturnType<typeof panelHtml>) => renderToStaticMarkup(createElement(ProformaSection, { globalOrderId: "trendhome:1234", view, service: {} as never, busy: false, run: () => undefined }));

test("the proforma shows the shop's bank transfer details and the payment reference; the operator never confirms a payment", () => {
  const view = panelHtml(operatorRead(transferShop(), ["proformaSendMail"]));
  assert.deepEqual(view.summary.proforma?.transfer, { iban: "RO49AAAA1B31007593840000", bank: "Banca TEST", beneficiary: "TEST Trend Home SRL", reference: "TH1234", bic: null });
  assert.equal(view.summary.proforma?.document?.reference, "TH1234");
  const html = proformaHtml(view);
  assert.ok(html.includes("RO49 AAAA 1B31 0075 9384 0000") && html.includes("TH1234") && html.includes("Banca TEST") && html.includes(CATALOGS.ro["inv.pf.transfer.note"]), "IBAN in groups of four, the reference, the bank and the note");
  assert.ok(!html.includes("cf-proforma-paid") && !/confirm(a|ă)? plata|mark.*paid/i.test(html), "nothing to confirm a payment with");
  const verifying = proformaHtml(panelHtml(operatorRead(transferShop({ verifying: true }), [])));
  assert.ok(verifying.includes('data-testid="cf-proforma-verifying"') && !verifying.includes("cf-proforma-paid"), "a transfer in verification is not paid");
  const partial = proformaHtml(panelHtml(operatorRead(transferShop({ state: "partial", received: "100.00", outstanding: "115.70" }), [])));
  assert.ok(partial.includes('data-testid="cf-proforma-partial"') && partial.includes("115,70"), "partial: the rest to pay");
  const paid = proformaHtml(panelHtml(operatorRead(transferShop({ state: "paid", received: "216.70", outstanding: "0.00" }), [])));
  assert.ok(paid.includes('data-testid="cf-proforma-paid"') && !paid.includes("cf-proforma-transfer"), "paid (verified by finance): shown by itself, no transfer details any more");
  const invoiced = proformaHtml(panelHtml(operatorRead(transferShop({}, { invoiced: { invoiced: true, at: "2026-10-24T10:00:00Z" } }), [])));
  assert.ok(invoiced.includes("cf-proforma-invoiced") && !invoiced.includes("cf-proforma-paid"), "a proforma invoiced in SmartBill is never shown as paid");
  for (const bad of [{ transfer: { iban: "RO49 AAAA", bank: "B", beneficiary: "C", reference: "TH1234" } }, { transfer: { iban: "RO49AAAA1B31007593840000", bank: "B", beneficiary: "C", reference: "th-1234" } }, { advance: { ...transferShop().advance, state: "settled" } }]) {
    assert.throws(() => mapInvoiceRead(operatorRead({ ...transferShop(), ...bad }, [])), Error, JSON.stringify(bad));
  }
  const older = panelHtml(operatorRead(proformaShop(), []));
  assert.ok(older.summary.proforma?.transfer === null && older.summary.proforma?.advance.state === null && older.summary.proforma?.advance.verifying === false, "a shop before the bank transfer details: none shown");
  for (const key of ["inv.pf.transfer.title", "inv.pf.transfer.reference", "inv.pf.verifying", "inv.blocker.proforma_bank_missing", "inv.blocker.proforma_series_not_approved"] as MessageKey[]) {
    for (const locale of ["ro", "tr", "en", "si"] as const) assert.ok(String(CATALOGS[locale][key]).trim().length > 0, `${locale}: ${key}`);
  }
});

// ---- API 2.45.0 (owner master update): the money card, telephone acceptance, smaller advance, cancellation in SmartBill ----
const masterShop = (over: Record<string, unknown> = {}, advance: Record<string, unknown> = {}) => transferShop({ state: "partial", received: "250.00", outstanding: "50.00", required: "300.00", ...advance }, {
  money: { currency: "RON", percentage: 30, known: true, total: "1000.00", advanceRequired: "300.00", received: "250.00", refunded: "0.00", net: "250.00", remaining: "750.00", excess: "0.00", advanceGap: "50.00" },
  totalAcceptance: { state: "accepted", at: "2026-10-24T08:00:00Z", by: OP, previousTotal: "1000.00", total: "1000.00", changes: [], note: null, advanceDecisionRequired: null },
  advanceAcceptance: null, lowAdvance: false, cancel: null, cancelled: [], superseded: [],
  deadline: { state: "open", firstCollection: "2026-10-24", deadline: "2026-11-15", daysLeft: 22, level: "none" },
  cod: { state: "none", awb: null, awbCod: null, expected: null, status: null },
  blockers: { ...pfNone, proformaCancel: [], totalAccept: ["total_already_accepted"], advanceAccept: [] },
  ...over,
});
const masterRead = (shop: Record<string, unknown>, allowed: string[]) => ({ ...operatorRead(shop, allowed), proforma: { blockers: { ...pfNone, proformaCancel: [], totalAccept: [], advanceAccept: [] }, allowedActions: allowed, role: "operator" } });

test("the operator reads the order's money first: total, advance asked and received, difference, what is owed, statuses, deadline", () => {
  const view = panelHtml(masterRead(masterShop(), ["advanceAccept", "proformaCancel"]));
  const html = proformaHtml(view);
  for (const [id, text] of [["cf-money-total", "1.000,00"], ["cf-money-required", "300,00"], ["cf-money-received", "250,00"], ["cf-money-gap", "50,00"], ["cf-money-remaining", "750,00"]] as const) {
    assert.ok(new RegExp(`data-testid="${id}">[^<]*${text.replace(".", "\\.")}`).test(html), `${id} shows ${text}`);
  }
  assert.ok(html.includes(CATALOGS.ro["inv.pf.adv.partial"]) && html.includes(CATALOGS.ro["inv.pf.invoice.none"]) && html.includes("2026-11-15"), "advance status, invoice status and the deadline");
  assert.ok(html.includes('data-testid="cf-proforma-accept-advance"') && html.includes('data-testid="cf-proforma-annul"') && !html.includes("cf-invoice-issue"), "accept the advance and cancel the proforma are offered; never a final invoice");
  assert.equal(CATALOGS.ro["inv.pf.action.acceptAdvance"], "Acceptă avansul primit");
  assert.equal(CATALOGS.ro["inv.pf.action.cancel"], "Anulează proforma");
  assert.equal(CATALOGS.ro["inv.pf.total.title"], "Confirmare telefonică a comenzii");
  assert.equal(CATALOGS.ro["inv.pf.confirm.lowAdvance"], "Avansul primit este semnificativ mai mic decât cel solicitat. Confirmați acceptarea?");
  assert.equal(CATALOGS.ro["inv.pf.status.cancelChecking"], "Anulare în curs de verificare");
  assert.ok(CATALOGS.ro["inv.pf.confirm.advance"].includes("Dacă acceptați suma primită, comanda poate continua"));
});

test("owner business rule: after a revision the money paid counts only with the operator's explicit decision; a wrong shipping amount blocks", () => {
  const decide = proformaHtml(panelHtml(masterRead(masterShop({ totalAcceptance: { state: "required", at: null, by: null, previousTotal: "1000.00", total: "1100.00", changes: [{ kind: "changed", name: "Draperie" }], note: null, advanceDecisionRequired: true } }), ["totalAccept"])));
  assert.ok(decide.includes('data-testid="cf-proforma-total-decision"') && decide.includes("250,00") && decide.includes("300,00") && decide.includes("50,00") && !decide.includes("rămâne valabil"),
    "the operator is told the amount paid, the new advance and the gap, and that an explicit choice is required (never kept by default)");
  const none = proformaHtml(panelHtml(masterRead(masterShop({ totalAcceptance: { state: "required", at: null, by: null, previousTotal: "1000.00", total: "1100.00", changes: [], note: null, advanceDecisionRequired: false } }), ["totalAccept"])));
  assert.ok(!none.includes('data-testid="cf-proforma-total-decision"'), "no verified money below the new advance: no decision to make");
  const kept = panelHtml(masterRead(masterShop({ advanceAcceptance: { at: "2026-10-25T09:00:00Z", by: OP, required: "330.00", received: "300.00", reason: "Acceptat la telefon.", low: false, kind: "kept" } }, { accepted: true }), []));
  assert.ok(proformaHtml(kept).includes('data-testid="cf-proforma-advance-kept"') && proformaHtml(kept).includes(CATALOGS.ro["inv.pf.kept.line"].slice(0, 40)) && !proformaHtml(kept).includes('data-testid="cf-proforma-accepted-partial"'),
    "the money accepted with the new total is named as the operator's explicit acceptance");
  const mismatch = panelHtml(masterRead(masterShop({ shipping: { state: "mismatch", code: "shipping_cost_mismatch", current: "25.00", expected: "35.00" } }), []));
  assert.ok(proformaHtml(mismatch).includes('data-testid="cf-proforma-shipping-mismatch"') && proformaHtml(mismatch).includes("35,00") && mismatch.summary.proforma?.shipping?.code === "shipping_cost_mismatch", "a shipping amount the configuration does not give: a blocking alert with both amounts");
  const unverifiable = proformaHtml(panelHtml(masterRead(masterShop({ shipping: { state: "unverifiable", code: "shipping_method_unsupported", current: "19.99", expected: null } }), [])));
  assert.ok(unverifiable.includes('data-testid="cf-proforma-shipping-unverifiable"') && !unverifiable.includes("cf-proforma-shipping-mismatch"), "a method the rules cannot recompute: shown, not refused");
  const match = proformaHtml(panelHtml(masterRead(masterShop({ shipping: { state: "match", code: null, current: "25.00", expected: "25.00" } }), [])));
  assert.ok(!match.includes("cf-proforma-shipping-"), "a matching shipping: nothing shown");
  for (const bad of [{ shipping: { state: "mismatch", code: null, current: "25.00", expected: "35.00" } }, { shipping: { state: "fine", code: null, current: null, expected: null } }, { shipping: { state: "match", code: "cheaper", current: null, expected: null } }]) {
    assert.throws(() => mapInvoiceRead(masterRead(masterShop(bad), [])), Error, JSON.stringify(bad));
  }
  assert.equal(invoiceBlockerLabel("shipping_mismatch"), CATALOGS.ro["inv.blocker.shipping_mismatch"]);
  assert.equal(invoiceBlockerLabel("advance_decision_required"), CATALOGS.ro["inv.blocker.advance_decision_required"]);
  for (const locale of ["ro", "tr", "en", "si"] as const) {
    for (const key of ["inv.pf.total.decision", "inv.pf.decision.title", "inv.pf.decision.accept", "inv.pf.decision.difference", "inv.pf.decision.low", "inv.pf.kept.line", "inv.pf.shipping.mismatch", "inv.pf.shipping.unverifiable",
      "inv.blocker.advance_decision_required", "inv.blocker.advance_decision_not_applicable", "inv.blocker.shipping_mismatch"] as const) assert.ok((CATALOGS[locale][key] ?? "").length > 10, `${locale} ${key}`);
  }
  assert.ok(CATALOGS.ro["inv.pf.efactura"].includes("Facturi emise") && !/trimis[ăa] în SPV de Arasya/.test(CATALOGS.ro["inv.pf.efactura"]), "e-Factura: where to check it in SmartBill; Arasya never claims a transmission");
});

test("the master update states: accepted partial advance, total to accept, cancellation being verified, AWB collecting the old amount, overdue deadline", () => {
  const accepted = panelHtml(masterRead(masterShop({ advanceAcceptance: { at: "2026-10-24T09:00:00Z", by: OP, required: "300.00", received: "250.00", reason: null, low: false, kind: "accepted" } }, { accepted: true }), []));
  assert.equal(advanceStatusKey(accepted.summary.proforma!), "inv.pf.adv.acceptedPartial");
  assert.ok(proformaHtml(accepted).includes('data-testid="cf-proforma-accepted-partial"'));
  const total = proformaHtml(panelHtml(masterRead(masterShop({ totalAcceptance: { state: "required", at: null, by: null, previousTotal: "1000.00", total: "1200.00", changes: [{ kind: "changed", name: "Draperie" }], note: null, advanceDecisionRequired: false } }), ["totalAccept"])));
  assert.ok(!total.includes('data-testid="cf-proforma-total-decision"'), "no money paid before: no advance decision");
  assert.ok(total.includes('data-testid="cf-proforma-total-required"') && total.includes("1.200,00") && total.includes("Draperie") && total.includes('data-testid="cf-proforma-accept-total"'), "the new total, the change and the telephone confirmation");
  const cancelling = panelHtml(masterRead(masterShop({ cancel: { state: "unknown", at: "2026-10-24T10:00:00Z", by: OP, reason: "customer_request" } }), []));
  assert.equal(proformaStatusKey(cancelling.summary.proforma!), "inv.pf.status.cancelChecking");
  assert.ok(proformaHtml(cancelling).includes('data-testid="cf-proforma-cancel-unknown"'), "an unanswered cancellation is never shown as cancelled");
  const cancelled = panelHtml(masterRead(masterShop({ document: null, state: "none", cancelled: [{ series: "PRF", number: "0007", amount: "300.00", currency: "RON", cancelledAt: "2026-10-24T10:00:00Z", cancelledBy: OP, reason: "order_changed" }] }), []));
  assert.equal(proformaStatusKey(cancelled.summary.proforma!), "inv.pf.status.cancelled");
  assert.ok(proformaHtml(cancelled).includes('data-testid="cf-proforma-cancelled"') && proformaHtml(cancelled).includes(CATALOGS.ro["inv.pf.cancelReason.order_changed"]));
  const awb = proformaHtml(panelHtml(masterRead(masterShop({ cod: { state: "mismatch", awb: "2345678", awbCod: "750.00", expected: "700.00", status: "AWB creat" } }), [])));
  assert.ok(awb.includes('data-testid="cf-proforma-cod-mismatch"') && awb.includes("2345678") && awb.includes("700,00"), "the AWB collecting the old amount is shown with what is owed");
  const overdue = proformaHtml(panelHtml(masterRead(masterShop({ deadline: { state: "open", firstCollection: "2026-09-02", deadline: "2026-10-15", daysLeft: -9, level: "overdue" } }), [])));
  assert.ok(overdue.includes('data-testid="cf-proforma-deadline"') && overdue.includes(CATALOGS.ro["inv.pf.money.deadline"]) && overdue.includes("2026-10-15"), "an overdue invoicing deadline is a finance exception, shown");
  const legacy = panelHtml(operatorRead(transferShop(), []));
  assert.deepEqual(legacy.proforma?.blockers.proformaCancel, ["not_supported"]);
  assert.ok(legacy.summary.proforma?.money === null && legacy.summary.proforma?.cancelled.length === 0, "an older shop: no money card, nothing invented");
  assert.equal(PROFORMA_ROUTE.total_accept, "accept-total");
  assert.equal(PROFORMA_ROUTE.advance_accept, "accept-advance");
  assert.equal(PROFORMA_ROUTE.proforma_cancel, "cancel");
});

test("a money calculation that does not add up, a deadline or a cancellation that is not one, are refused", () => {
  const good = { currency: "RON", percentage: 30, known: true, total: "1000.00", advanceRequired: "300.00", received: "250.00", refunded: "0.00", net: "250.00", remaining: "750.00", excess: "0.00", advanceGap: "50.00" };
  assert.equal(mapMoneyPosition(good).remaining, "750.00");
  for (const bad of [{ remaining: "700.00" }, { excess: "10.00" }, { net: "240.00" }, { advanceGap: "0.00" }, { remaining: "-1.00" }, { percentage: 150 }]) {
    assert.throws(() => mapMoneyPosition({ ...good, ...bad }), Error, JSON.stringify(bad));
  }
  assert.throws(() => mapInvoiceDeadline({ state: "open", firstCollection: "2026-10-24", deadline: "15.11.2026", daysLeft: 3, level: "warning" }));
  assert.throws(() => mapInvoiceDeadline({ state: "open", firstCollection: "2026-10-24", deadline: "2026-11-15", daysLeft: 3, level: "relaxed" }));
  for (const bad of [{ cancel: { state: "maybe", at: null, by: null, reason: null } }, { cancelled: [{ series: "P", number: "1", amount: "1.00", currency: "RON", cancelledAt: null, cancelledBy: null, reason: "whim" }] }]) {
    assert.throws(() => mapInvoiceRead(masterRead(masterShop(bad), [])), Error, JSON.stringify(bad));
  }
  const sent = masterRead(masterShop(), []) as { summary: Record<string, unknown> };
  assert.throws(() => mapInvoiceRead({ ...sent, summary: { ...sent.summary, eFactura: { state: "sent" } } }), Error, "e-Factura is never shown as sent");
  assert.deepEqual((mapInvoiceRead({ ...sent, summary: { ...sent.summary, eFactura: { state: "not_tracked" } } }) as InvoiceView).summary.eFactura, { state: "not_tracked" });
});
