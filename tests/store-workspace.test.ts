import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  MAX_PARCELS, ShopQueue, addressFormIssues, awbReadiness, canUseStoreWorkspace, clampParcels, mapAddressCheck, mapAwbHistoryPage, mapOpen, mapAwbPage, mapShopRow, mapStoreOrder, mapStoreSummary,
  finalInvoiceStep, opensStoreWorkspace, parcelKey, storeLegacyRedirect, parseWeight, reprintReasonText, retryable, sectorOf, stageNumber, telHref, toneOf, validRange, type StoreAwbItem,
} from "../domain/storeWorkspace";
import { mapOperationsState, mapShopOperations } from "../domain/confirmareOps";
import { parseStaffRoute } from "../domain/staffRoute";
import { canAccessRoute } from "../domain/permissions";
import { CATALOGS, SUPPORTED_LOCALES } from "../i18n";
import { StaffServiceError, type Employee } from "../domain/models";
import { confirmareErrorDetails } from "../services/production/httpServices";

const employee = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: "00000000-0000-4000-8000-0000000000d1", username: "shop.test", displayName: "Shop Test", status: "active", permissions: ["production.documents.generate"], allowedStageIds: [],
  applications: ["staff"], mustChangePassword: false, locale: "ro", confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: [], record: [], approve: [] },
  invoiceScopes: { issue: ["trendhome"], manage: [] }, ...overrides,
} as Employee);
const row = (patch: Record<string, unknown> = {}) => ({ available: true, name: "TEST Client", city: "Brașov", county: "BV", cod: "350.00", awb: { state: "none", number: null, status: "" }, blockers: [],
  address: { state: "ok", issues: [], warnings: [] }, ...patch });
const item = (shop: Record<string, unknown> | null, lastAttempt: string | null = null): StoreAwbItem => ({ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", stage: "quality-control", lastAttempt, shop: shop === null ? null : mapShopRow(shop), open: null });

// 41.x Access and home ----------------------------------------------------------------------------------------------------------
test("the Store Workspace is the home of a shop employee, never of a Confirmare operator or root", () => {
  assert.equal(canUseStoreWorkspace(employee()), true);
  assert.equal(opensStoreWorkspace(employee(), false), true);
  assert.equal(opensStoreWorkspace(employee(), true), false, "an operator keeps the operator desk");
  assert.equal(opensStoreWorkspace(employee({ confirmareScopes: { operate: ["trendhome"], supervise: [] } }), false), false);
  assert.equal(canUseStoreWorkspace(employee({ invoiceScopes: { issue: [], manage: [] } })), false);
  assert.equal(canUseStoreWorkspace(employee({ isRoot: true })), false);
  assert.equal(canUseStoreWorkspace(employee({ mustChangePassword: true })), false);
  assert.equal(canUseStoreWorkspace(employee({ invoiceScopes: { issue: ["trendyol"], manage: [] } })), false, "only the two shops");
});

test("store routes parse strictly and are guarded by the shop scope", () => {
  assert.deepEqual(parseStaffRoute("/magazin"), { kind: "store-home", pathname: "/magazin" });
  assert.equal(parseStaffRoute("/magazin/awb").kind, "store-awb");
  assert.equal(parseStaffRoute("/magazin/documente").kind, "store-documents");
  assert.equal(parseStaffRoute("/magazin/cauta").kind, "store-search");
  assert.equal(parseStaffRoute("/magazin/istoric").kind, "store-mine");
  assert.deepEqual(parseStaffRoute("/magazin/comenzi/trendhome%3A65794"), { kind: "store-order", pathname: "/magazin/comenzi/trendhome%3A65794", orderId: "trendhome:65794" });
  assert.equal(parseStaffRoute("/magazin/comenzi/trendyol%3A1").kind, "invalid", "another channel's order never opens here");
  assert.equal(parseStaffRoute("/magazin/comenzi/..%2F..").kind, "invalid");
  assert.equal(canAccessRoute(employee(), "/magazin/awb"), true);
  assert.equal(canAccessRoute(employee({ invoiceScopes: { issue: [], manage: [] } }), "/magazin/awb"), false);
});

// 41.x Parcels and weight ---------------------------------------------------------------------------------------------------------
test("the parcel stepper never leaves FAN Courier's 1–20 and answers the keyboard", () => {
  assert.equal(clampParcels(0), 1);
  assert.equal(clampParcels(-3), 1);
  assert.equal(clampParcels(21), MAX_PARCELS);
  assert.equal(clampParcels(Number.NaN), 1);
  assert.equal(clampParcels(2.7), 2);
  assert.equal(parcelKey(1, "ArrowDown"), 1);
  assert.equal(parcelKey(1, "ArrowUp"), 2);
  assert.equal(parcelKey(19, "+"), 20);
  assert.equal(parcelKey(20, "+"), 20);
  assert.equal(parcelKey(3, "PageUp"), 8);
  assert.equal(parcelKey(3, "PageDown"), 1);
  assert.equal(parcelKey(7, "Home"), 1);
  assert.equal(parcelKey(7, "End"), 20);
  assert.equal(parcelKey(7, "a"), null);
});

test("the weight is optional, comma or dot, between 0.01 and 500 kg", () => {
  assert.equal(parseWeight(""), null);
  assert.equal(parseWeight("2,5"), 2.5);
  assert.equal(parseWeight("12.25"), 12.25);
  assert.ok(Number.isNaN(parseWeight("0")));
  assert.ok(Number.isNaN(parseWeight("501")));
  assert.ok(Number.isNaN(parseWeight("2.555")));
  assert.ok(Number.isNaN(parseWeight("abc")));
});

// 41.x Readiness and tones ----------------------------------------------------------------------------------------------------
test("the AWB row shows the shop's real reason: address first, then the first blocker; ready only with a known COD", () => {
  assert.deepEqual(awbReadiness(item(row())), { state: "ready", cod: "350.00", warnings: [] });
  assert.deepEqual(awbReadiness(item(row({ address: { state: "attention", issues: ["locality_unknown"], warnings: [] }, blockers: ["address_incomplete"] }))), { state: "address", issues: ["locality_unknown"] });
  assert.deepEqual(awbReadiness(item(row({ blockers: ["invoice_missing", "payment_verifying"] }))), { state: "blocked", reason: "invoice_missing", tone: "red" });
  assert.deepEqual(awbReadiness(item(row({ blockers: ["phone_missing"] }))), { state: "blocked", reason: "phone_missing", tone: "yellow" });
  assert.deepEqual(awbReadiness(item(row({ cod: null }))), { state: "blocked", reason: "finance_unavailable", tone: "red" });
  assert.deepEqual(awbReadiness(item(row({ awb: { state: "created", number: "2150900000001", status: "" } }))), { state: "created", number: "2150900000001" });
  assert.deepEqual(awbReadiness(item(null)), { state: "unknown" }, "a shop that cannot be read: nothing is guessed");
  assert.deepEqual(awbReadiness(item({ available: false })), { state: "unknown" });
  assert.deepEqual(awbReadiness(item(row({ address: { state: "warning", issues: [], warnings: ["locality_unverified"] } }))), { state: "ready", cod: "350.00", warnings: ["locality_unverified"] });
});

test("three tones: green done, yellow the employee fixes, red system or finance", () => {
  assert.equal(toneOf("created"), "green");
  assert.equal(toneOf("address_invalid"), "yellow");
  assert.equal(toneOf("awb_refused"), "yellow");
  assert.equal(toneOf("cod_changed"), "yellow");
  assert.equal(toneOf("invoice_missing"), "red");
  assert.equal(toneOf("payment_verifying"), "red");
  assert.equal(toneOf("cancel_uncertain"), "red");
  assert.equal(toneOf("something_new"), "red", "an unknown code is never the employee's to fix");
});

// 41.x Documents -------------------------------------------------------------------------------------------------------------
test("a reprint always names a short reason, stored in Romanian", () => {
  assert.equal(reprintReasonText(null), null);
  assert.equal(reprintReasonText("printer_error"), "Eroare imprimantă");
  assert.equal(reprintReasonText("damaged"), "Document deteriorat");
  assert.equal(reprintReasonText("lost"), "Document pierdut");
  assert.equal(reprintReasonText("other", "ab"), null, "other needs at least 3 characters");
  assert.equal(reprintReasonText("other", "  pată  de cafea "), "Alt motiv: pată de cafea");
});

// 41.x Stage indicator --------------------------------------------------------------------------------------------------------
test("the stage indicator reads the 14 canonical stages, never guesses", () => {
  assert.equal(stageNumber("waiting"), 1);
  assert.equal(stageNumber("quality-control"), 12);
  assert.equal(stageNumber("delivery"), 14);
  assert.equal(stageNumber("unknown-stage"), null);
});

// 41.x History range ----------------------------------------------------------------------------------------------------------
test("a custom history range is two dates, from ≤ to, at most 92 days", () => {
  assert.equal(validRange("2026-11-01", "2026-11-26"), true);
  assert.equal(validRange("2026-11-26", "2026-11-01"), false);
  assert.equal(validRange("2026-01-01", "2026-12-31"), false);
  assert.equal(validRange("2026-11-1", "2026-11-26"), false);
});

// 41.x The controlled AWB queue -------------------------------------------------------------------------------------------------
test("the AWB queue runs one request per shop at a time, retries only 'busy' twice, never an unknown outcome", async () => {
  const pauses: number[] = [];
  const queue = new ShopQueue(async (ms) => { pauses.push(ms); }, [10, 20]);
  const order: string[] = [];
  let release!: () => void;
  const gate = new Promise<void>((resolve) => { release = resolve; });
  const first = queue.enqueue({ key: "a", shop: "trendhome", run: async () => { order.push("a:start"); await gate; order.push("a:end"); return 1; } });
  const second = queue.enqueue({ key: "b", shop: "trendhome", run: async () => { order.push("b"); return 2; } });
  const other = queue.enqueue({ key: "c", shop: "outletperdele", run: async () => { order.push("c"); return 3; } });
  assert.equal(queue.has("a"), true);
  assert.equal((await queue.enqueue({ key: "a", shop: "trendhome", run: async () => 9 })).ok, false, "a second click on the same row is ignored");
  await other;
  release();
  await Promise.all([first, second]);
  assert.deepEqual(order.filter((step) => step !== "c"), ["a:start", "a:end", "b"], "same shop: strictly one after another");
  assert.ok(order.indexOf("c") < order.indexOf("a:end"), "another shop is not held up");
  let tries = 0;
  const busy = new StaffServiceError("SERVICE_UNAVAILABLE", undefined, { editReason: "order_busy" });
  const retried = await queue.enqueue({ key: "d", shop: "trendhome", run: async () => { tries++; if (tries < 3) throw busy; return "ok"; } });
  assert.deepEqual(retried, { key: "d", ok: true, value: "ok" });
  assert.deepEqual(pauses, [10, 20]);
  let hard = 0;
  const refused = await queue.enqueue({ key: "e", shop: "trendhome", run: async () => { hard++; throw new StaffServiceError("SERVER_ERROR", undefined, { editReason: "awb_refused" }); } });
  assert.equal(refused.ok, false);
  assert.equal(hard, 1, "a definite refusal is never retried");
  assert.equal(retryable(new StaffServiceError("RATE_LIMITED")), true);
  assert.equal(retryable(new StaffServiceError("SERVER_ERROR", undefined, { backendCode: "CONFIRMARE_SOURCE_UNAVAILABLE" })), true, "the shop could not be asked: nothing was sent");
  assert.equal(retryable(new Error("x")), false);
});

// 41.x Address form -------------------------------------------------------------------------------------------------------------
test("the address form names the real missing fields before saving; Bucharest sector is the locality", () => {
  const form = { firstName: "TEST", lastName: "Client", phone: "0722 111 222", county: "BV", city: "Brașov", street: "Str. Livrării", streetNo: "12", building: "", entrance: "", floor: "", apartment: "", postcode: "500124", note: "" };
  assert.deepEqual(addressFormIssues(form), []);
  assert.deepEqual(addressFormIssues({ ...form, city: "", phone: "12" }), ["phone_invalid", "locality_missing"]);
  assert.deepEqual(addressFormIssues({ ...form, firstName: "", lastName: "", county: "", street: "", postcode: "12" }), ["name_missing", "county_missing", "street_missing", "postcode_invalid"]);
  assert.equal(sectorOf("Sector 3"), 3);
  assert.equal(sectorOf("București"), null);
  assert.equal(telHref("0722 111 222"), "tel:0722111222");
  assert.equal(telHref("+40 722 111 222"), "tel:+40722111222");
  assert.equal(telHref("12"), null);
});

// 41.x Strict reading ---------------------------------------------------------------------------------------------------------
test("store answers are read strictly; the COD is never invented", () => {
  const summary = mapStoreSummary({ day: "2026-11-26", zone: "Europe/Bucharest", sources: ["trendhome"], can: { documents: true, awb: true },
    documents: { waiting: 3, revision: 1, printedToday: 5, reprintsToday: 2 }, awb: { waiting: 4, needsFix: 1, createdToday: 6, cancelledToday: 1, recreatedToday: 1, parcelsToday: 9, ordersToday: 5, tracked: true } });
  assert.equal(summary.awb.parcelsToday, 9);
  assert.throws(() => mapStoreSummary({ ...summary, awb: { ...summary.awb, waiting: -1 } }));
  assert.throws(() => mapShopRow(row({ cod: "350" })), "a malformed amount is refused");
  assert.throws(() => mapShopRow(row({ blockers: ["<script>"] })));
  const page = mapAwbPage({ items: [{ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", stage: "packing", lastAttempt: "rejected", shop: null }], page: 1, pageSize: 25, total: 1 });
  assert.equal(page.items[0].shop, null);
  const history = mapAwbHistoryPage({ items: [{ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", awb: "2150900000001", parcels: 1, at: "2026-11-26T10:00:00Z", by: "Shop Test", state: "cancelled",
    cancelledAt: "2026-11-26T10:05:00Z", cancelReason: "wrong_parcels", replacedBy: "2150900000002", replaces: null }], page: 1, pageSize: 25, total: 1, tracked: true });
  assert.equal(history.items[0].replacedBy, "2150900000002");
  const order = mapStoreOrder({ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", submittedAt: null, dueDate: null, stage: { id: "quality-control", number: 12, total: 14, completed: false }, cancelled: false,
    document: { status: "active", version: 3, revision: 1, prints: 2, lastPrint: null }, invoiceIssued: true, products: [], awbOperations: [], shop: null, canAwb: true, canDocuments: true });
  assert.equal(order.stage.number, 12);
});

test("an operations state of API 2.83.0 (correction, delivery, new kinds) is read; an older one still is", () => {
  const shop = { features: { awb: "enforce", awbCorrection: "enforce", addressEdit: "off" }, orderStatus: "processing",
    awb: { state: "none", number: null, createdAt: null, delivered: false, cod: "350.00", codCheck: null, blockers: [], address: [], pickup: "not_tracked", statusId: null, correction: { state: "cancelled", awb: "2150900000001", at: "2026-11-26T10:05:00Z", reason: "wrong_parcels" } },
    delivery: { address: { target: "shipping", firstName: "TEST", lastName: "Client", company: "", phone: "0722111222", county: "BV", city: "Brașov", street: "Str. Livrării", streetNo: "12", building: "", entrance: "", floor: "", apartment: "", postcode: "", note: "" },
      check: { state: "ok", issues: [], warnings: [], fan: { county: "BV", locality: "Brasov" } }, editable: true, lastEdit: null },
    cardRefund: { available: false, transactions: 0, requests: [] } };
  const mapped = mapShopOperations(shop);
  assert.equal(mapped.awb.correction?.state, "cancelled");
  assert.equal(mapped.delivery?.address.city, "Brașov");
  assert.equal(mapped.features.awbCorrection, "enforce");
  const state = mapOperationsState({ available: true, shop, stage: "quality-control", awbStage: true, allowed: { reactivate: false, awb: true, cardRefund: false, cardRefundRecord: false, cardRefundVerify: false, awbCorrect: true, addressEdit: true }, reactivations: [],
    open: [{ kind: "awb_cancel", state: "unknown", at: "2026-11-26T10:05:00Z", mine: true, idempotencyKey: "store-cancel-0000000001" }, { kind: "address_update", state: "pending", at: "2026-11-26T10:06:00Z", mine: true, idempotencyKey: null }] });
  assert.deepEqual(state.open.map((o) => o.path), ["awb/cancel", "delivery-address"], "an open cancellation or address change never breaks the screen");
  const old = mapShopOperations({ ...shop, awb: { ...shop.awb, statusId: undefined, correction: undefined }, delivery: undefined });
  assert.equal(old.awb.correction, undefined);
  assert.equal(old.delivery, undefined);
});

test("FAN Courier's own refusal text and the address codes reach the screen; anything else is dropped", () => {
  const details = confirmareErrorDetails({ reason: "address_invalid", message: "Localitatea nu există", issues: ["locality_unknown", "<b>"], warnings: ["sector_missing"] });
  assert.equal(details.editReason, "address_invalid");
  assert.equal(details.shopMessage, "Localitatea nu există");
  assert.equal(details.addressIssues, undefined, "a list with a bad code is dropped whole");
  assert.deepEqual(details.addressWarnings, ["sector_missing"]);
  assert.equal(confirmareErrorDetails({ message: "x".repeat(201) }).shopMessage, undefined);
});

// 41.x Languages ----------------------------------------------------------------------------------------------------------------
test("every Store Workspace text exists in every language (no raw key on screen)", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("sw."));
  assert.ok(keys.length > 200);
  for (const locale of SUPPORTED_LOCALES) for (const key of keys) assert.ok((CATALOGS[locale] as Record<string, unknown>)[key] !== undefined, `${locale} ${key}`);
  const source = ["features/store/StoreAwb.tsx", "features/store/StoreDocuments.tsx", "features/store/StoreHome.tsx", "features/store/StoreOrders.tsx", "features/store/StoreAddress.tsx", "features/store/StoreParts.tsx"]
    .map((file) => readFileSync(new URL(`../${file}`, import.meta.url), "utf8")).join("\n");
  for (const match of source.matchAll(/t\("(sw\.[a-zA-Z0-9_.]+)"/g)) assert.ok(keys.includes(match[1]), `missing ${match[1]}`);
  for (const code of ["invoice_missing", "payment_verifying", "address_invalid", "awb_picked_up", "pickup_unknown", "cancel_uncertain", "cancel_refused", "not_cancelled"]) assert.ok(keys.includes(`sw.reason.${code}`), code);
});

// Owner acceptance 2026-11-26 -------------------------------------------------------------------------------------------------
test("an open AWB operation comes back after a reload with its key; a malformed one is refused", () => {
  const open = { kind: "awb_cancel", state: "unknown", at: "2026-11-26T10:00:00Z", idempotencyKey: "sw-cancel-0123456789abcdef" };
  const page = mapAwbPage({ items: [{ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", stage: "packing", lastAttempt: "unknown", shop: null, open: { ...open, kind: "awb_create" } }], page: 1, pageSize: 25, total: 1 });
  assert.deepEqual(page.items[0].open, { ...open, kind: "awb_create" });
  assert.equal(mapOpen(null), null);
  assert.throws(() => mapOpen({ ...open, idempotencyKey: "short" }), "a key outside the pattern is refused");
  assert.throws(() => mapOpen({ ...open, kind: "awb_label" }), "only the AWB operations");
});

test("history figures: parcels of older AWBs are unknown, never 0; the current AWB and its open correction are read", () => {
  const history = mapAwbHistoryPage({ items: [{ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", awb: "2150900000002", awbFromShop: true, parcels: null, at: "2026-11-26T10:00:00Z", by: "Shop Test",
    state: "active", current: true, open: { kind: "awb_cancel", state: "unknown", at: "2026-11-26T10:05:00Z", idempotencyKey: "sw-cancel-0123456789abcdef" }, cancelledAt: null, cancelReason: null, replacedBy: null, replaces: null }],
    page: 1, pageSize: 25, total: 1, tracked: true, stats: { orders: 4, awbs: 5, parcels: 7, parcelsUnknown: 1, cancelled: 1, renewed: 1 } });
  assert.deepEqual(history.stats, { orders: 4, awbs: 5, parcels: 7, parcelsUnknown: 1, cancelled: 1, renewed: 1 });
  assert.equal(history.items[0].parcels, null);
  assert.equal(history.items[0].current, true);
  assert.equal(history.items[0].awbFromShop, true);
  assert.equal(history.items[0].open?.kind, "awb_cancel");
  assert.throws(() => mapAwbHistoryPage({ items: [], page: 1, pageSize: 25, total: 0, tracked: true, stats: { orders: 1, awbs: 1, parcels: -1, parcelsUnknown: 0, cancelled: 0, renewed: 0 } }), "a negative figure is refused");
  assert.equal(mapAwbHistoryPage({ items: [], page: 1, pageSize: 25, total: 0, tracked: false }).stats, null);
});

test("a prepared address check is read strictly (the shop's codes only)", () => {
  assert.deepEqual(mapAddressCheck({ issues: ["locality_missing"], warnings: [], awbActive: true }), { issues: ["locality_missing"], warnings: [], awbActive: true });
  assert.throws(() => mapAddressCheck({ issues: ["<b>"], warnings: [], awbActive: true }));
  assert.throws(() => mapAddressCheck({ issues: [], warnings: [] }));
});

// V1.1 (Staff 2.60.0): no earlier screen for the shop employee, the final invoice after the document --------------------------
test("an earlier screen's address opens its Store Workspace screen (direct URL, old link, back button, notice, scanner)", () => {
  assert.equal(storeLegacyRedirect("/documents/trendhome%3A66489"), "/magazin/comenzi/trendhome%3A66489");
  assert.equal(storeLegacyRedirect("/orders/trendhome%3A66489"), "/magazin/comenzi/trendhome%3A66489");
  assert.equal(storeLegacyRedirect("/orders/trendhome:66489/"), "/magazin/comenzi/trendhome%3A66489");
  assert.equal(storeLegacyRedirect("/documents/trendhome%3A1?tab=x#y"), "/magazin/comenzi/trendhome%3A1");
  assert.equal(storeLegacyRedirect("/documents/%E0%A4%A"), "/magazin", "a broken address opens the store home, never an error");
  assert.equal(storeLegacyRedirect("/orders"), "/magazin/istoric");
  assert.equal(storeLegacyRedirect("/confirmare/handoff"), "/magazin/documente");
  assert.equal(storeLegacyRedirect("/confirmare/awb"), "/magazin/awb");
  for (const kept of ["/", "/magazin", "/magazin/comenzi/trendhome%3A1", "/magazin/awb", "/profile", "/history", "/scan", "/login", "/documents/a/b"]) {
    assert.equal(storeLegacyRedirect(kept), null, kept);
  }
});

test("the Store Workspace screens never open the earlier document screen, the SmartBill panel or the old customer list", () => {
  for (const file of ["StoreDocuments", "StoreOrders", "StoreAwb", "StoreHome", "StoreParts", "StorePrepare", "StoreChecks", "StoreAddress"]) {
    const source = readFileSync(new URL(`../features/store/${file}.tsx`, import.meta.url), "utf8");
    assert.doesNotMatch(source, /["'`]\/documents\//, `${file}: no link to /documents/`);
    assert.doesNotMatch(source, /ConfirmareInvoicePanel|CustomerChecks|DocumentPanel|DocumentScreen|ConfirmareHandoff/, `${file}: no earlier component`);
  }
  const app = readFileSync(new URL("../app/StaffApp.tsx", import.meta.url), "utf8");
  assert.match(app, /storeLegacyRedirect\(route\)/, "the redirect runs before the route is parsed");
  assert.doesNotMatch(app, /clasic/, "no way back to the earlier home");
});

const invoiceRead = (patch: { document?: Record<string, unknown>; operation?: string; allowed?: string[]; blockers?: string[]; shopBlockers?: string[]; role?: string; documentType?: string; mode?: string; actionOpen?: boolean } = {}) => ({
  available: true, role: patch.role ?? "documents", allowedActions: patch.allowed ?? ["issue"], actionOpen: patch.actionOpen ?? false,
  blockers: { issue: patch.blockers ?? [], sendMail: [], reissue: [], resolve: [], delete: [] },
  summary: {
    revision: "rev-1", mode: patch.mode ?? "enforce", smartbill: { documentType: patch.documentType ?? "invoice" },
    document: { exists: false, type: null, series: null, number: null, ...patch.document },
    operation: { state: patch.operation ?? "none" },
    blockers: { issue: patch.shopBlockers ?? [], sendMail: [], reissue: [], resolve: [], delete: [] },
  },
}) as unknown as Parameters<typeof finalInvoiceStep>[0];

test("the final invoice after the print: issued once when the server allows it, never twice, quiet when it is not this employee's", () => {
  assert.deepEqual(finalInvoiceStep(invoiceRead()), { kind: "issue", revision: "rev-1" });
  assert.deepEqual(finalInvoiceStep(invoiceRead({ document: { exists: true, type: "invoice", series: "SMART", number: "10500" } })), { kind: "exists", document: "SMART 10500" });
  assert.deepEqual(finalInvoiceStep(invoiceRead({ document: { exists: true, type: "proforma", series: "ARS", number: "0900" } })), { kind: "issue", revision: "rev-1" }, "a paid proforma is not the final invoice");
  assert.deepEqual(finalInvoiceStep(invoiceRead({ operation: "unknown" })), { kind: "open" }, "an unknown outcome is never sent again");
  assert.deepEqual(finalInvoiceStep(invoiceRead({ operation: "in_flight" })), { kind: "open" });
  assert.deepEqual(finalInvoiceStep(invoiceRead({ actionOpen: true })), { kind: "open" });
  assert.deepEqual(finalInvoiceStep(invoiceRead({ allowed: [], shopBlockers: ["payment_not_cleared"] })), { kind: "blocked", code: "payment_not_cleared" });
  assert.deepEqual(finalInvoiceStep(invoiceRead({ allowed: [], blockers: ["handoff_not_ready"], shopBlockers: ["smartbill_refused"] })), { kind: "blocked", code: "handoff_not_ready" }, "Arasya's reason first");
  for (const quiet of ["not_allowed_for_role", "not_assigned", "assigned_to_other", "invoice_observe", "invoice_disabled"]) {
    assert.deepEqual(finalInvoiceStep(invoiceRead({ allowed: [], blockers: [quiet] })), { kind: "quiet" }, quiet);
  }
  assert.deepEqual(finalInvoiceStep(invoiceRead({ role: "viewer" })), { kind: "quiet" }, "a reader never issues");
  assert.deepEqual(finalInvoiceStep(invoiceRead({ documentType: "proforma" })), { kind: "quiet" }, "a proforma shop: not the shop employee's work");
  assert.deepEqual(finalInvoiceStep(invoiceRead({ mode: "observe" })), { kind: "quiet" });
  assert.deepEqual(finalInvoiceStep({ available: false, reason: "not_configured" }), { kind: "quiet" });
  assert.deepEqual(finalInvoiceStep({ available: false, reason: "unreachable" }), { kind: "blocked", code: "smartbill_unreachable" });
});

test("V1.1 words exist in every Staff language", () => {
  const keys = ["sw.inv.issued", "sw.inv.issuedPlain", "sw.inv.exists", "sw.inv.open", "sw.inv.unknown", "sw.inv.notNow", "sw.docs.completed", "sw.docs.revisionWaiting", "sw.docs.revisionRequested",
    "sw.docs.changedRetry", "sw.docs.tab.checks", "sw.check.title", "sw.check.lead", "sw.check.hint", "sw.check.empty", "sw.check.why", "sw.check.reason", "sw.check.answer"];
  for (const locale of SUPPORTED_LOCALES) for (const key of keys) assert.ok((CATALOGS[locale] as unknown as Record<string, unknown>)[key], `${locale} ${key}`);
});
