import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { ActivityPage, Employee } from "../domain/models";
import type { ActivityService } from "../services/contracts";
import { activeLocale, CATALOGS, setActiveLocale } from "../i18n";
import { mapActivityPage } from "../services/production/httpServices";
import { describeActivity, historyTarget, HistoryScreen } from "../features/history/HistoryScreen";
import { StaffServiceError } from "../domain/models";

// Staff 2.43.0 (API 2.52.0): the history of an office employee (Confirmare, finance, the shops) lists their own office
// work, not only production. The server decides what is history; Staff only names it and links it where the employee
// may open it.

const person = (over: Partial<Employee> = {}): Employee => ({
  employeeUuid: "00000000-0000-4000-8000-000000000001", displayName: "Operator Test", username: "op.test", department: "Vânzări online", role: "employee", status: "active",
  permissions: ["profile.view_self", "history.view_mine"], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: ["outletperdele"], supervise: [] }, ...over,
});
const office = (action: string, detail?: string) => ({ id: `e-${action}-${detail ?? ""}`, occurredAt: "2026-11-01T17:15:14.111Z", kind: "office", action, ...(detail ? { detail } : {}), orderId: "outletperdele:60420", orderNumber: "60420", source: "outletperdele" });
const summary = { processed: 1, meters: 0, handedOver: 0, inProgress: 0, actions: 3 };
const inRomanian = <T>(run: () => T): T => { const before = activeLocale(); setActiveLocale("ro"); try { return run(); } finally { setActiveLocale(before); } };
const assertServerError = (run: () => unknown) => assert.throws(run, (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");

test("office entries map without a production stage; unknown shapes are refused, unknown details dropped", () => {
  const page = mapActivityPage({ items: [office("confirmare.approved"), office("contact", "confirmed"), office("invoice.proforma_issue")], nextCursor: null, summary });
  assert.deepEqual(page.items.map((i) => [i.kind, i.action, i.detail ?? null, i.fromStageId]), [["office", "confirmare.approved", null, ""], ["office", "contact", "confirmed", ""], ["office", "invoice.proforma_issue", null, ""]]);
  assert.equal(page.summary.actions, 3);
  assert.equal(mapActivityPage({ items: [], summary: { processed: 0, meters: 0, handedOver: 0, inProgress: 0 } }).summary.actions, undefined, "an older API sends no action count");
  assert.equal(mapActivityPage({ items: [office("contact", "Ion Popescu")], summary }).items[0].detail, undefined, "a detail is a code, never free text");
  assertServerError(() => mapActivityPage({ items: [office("payroll.export")], summary }));
  assertServerError(() => mapActivityPage({ items: [office("contact; DROP")], summary }));
  assertServerError(() => mapActivityPage({ items: [], summary: { ...summary, actions: -1 } }));
});

test("every office action the API names has a label in each language; the detail refines it; an unknown one stays neutral", () => {
  const actions = ["confirmare.approved", "confirmare.rejected", "confirmare.reopened", "confirmare.reassigned", "contact", "shop.edit", "shop.order_reactivate", "shop.awb_create", "shop.awb_address_task", "shop.awb_address_done", "shop.card_refund_full", "shop.card_refund_record", "shop.card_refund_verify",
    "invoice.issue", "invoice.send_mail", "invoice.reissue", "invoice.resolve", "invoice.proforma_issue", "invoice.proforma_send_mail", "invoice.proforma_check", "invoice.proforma_resolve", "invoice.proforma_cancel", "invoice.total_accept", "invoice.advance_accept", "invoice.advance_difference",
    "finance.submit_transfer", "finance.decide_transfer", "finance.verify_transfer", "finance.acknowledge_liability", "finance.submit_refund", "finance.decide_refund", "verification.requested", "verification.resubmitted", "verification.cancelled"];
  for (const [locale, catalog] of Object.entries(CATALOGS)) {
    for (const action of actions) assert.ok(Object.hasOwn(catalog, `history.office.${action}`), `${locale} names ${action}`);
  }
  inRomanian(() => {
    const [approved, called, proforma] = mapActivityPage({ items: [office("confirmare.approved"), office("contact", "confirmed"), office("invoice.proforma_issue")], summary }).items;
    assert.equal(describeActivity(approved), "Comandă aprobată");
    assert.equal(describeActivity(called), "Apel: clientul a confirmat");
    assert.equal(describeActivity(proforma), "Proformă emisă");
    const [rejected, decided, future] = mapActivityPage({ items: [office("confirmare.rejected", "order_details_wrong"), office("finance.decide_transfer", "reject"), office("shop.something_new")], summary }).items;
    assert.equal(describeActivity(rejected), "Comandă respinsă", "an unknown reason keeps the action's own label");
    assert.equal(describeActivity(decided), "Transfer bancar respins");
    assert.equal(describeActivity(future), "Altă operațiune");
  });
});

test("a row leads to the Confirmare order for an operator, to nowhere for finance only, and production rows keep their order link", () => {
  const [entry] = mapActivityPage({ items: [office("confirmare.approved")], summary }).items;
  assert.equal(historyTarget(entry, person()), "/confirmare/orders/outletperdele%3A60420");
  assert.equal(historyTarget(entry, person({ confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: ["outletperdele"], record: ["outletperdele"], approve: [], verify: ["outletperdele"] } } as Partial<Employee>)), null);
  const production = { ...entry, kind: undefined, action: "claimed" as const, orderId: "trendhome:61833" };
  assert.equal(historyTarget(production, person({ allowedStageIds: ["material-preparation"] })), "/orders/trendhome%3A61833");
});

test("an office employee's history counts orders and actions; a production employee keeps the four production numbers", () => {
  const page: ActivityPage = mapActivityPage({ items: [office("confirmare.approved"), office("contact", "confirmed")], nextCursor: null, summary: { ...summary, actions: 2 } });
  const service: ActivityService = { listMine: () => Promise.resolve(page) };
  inRomanian(() => {
    // The first render shows the loading state; the layout itself is chosen from the employee, not from the data.
    const officeHtml = renderToStaticMarkup(createElement(HistoryScreen, { service, employee: person(), navigate: () => undefined }));
    assert.match(officeHtml, /Comenzile la care ați lucrat/);
    const productionHtml = renderToStaticMarkup(createElement(HistoryScreen, { service, employee: person({ allowedStageIds: ["material-preparation"] }), navigate: () => undefined }));
    assert.match(productionHtml, /Acțiunile tale confirmate în producție/);
  });
});
