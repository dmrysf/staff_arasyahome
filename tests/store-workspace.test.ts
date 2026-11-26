import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  MAX_PARCELS, ShopQueue, addressFormIssues, awbReadiness, canUseStoreWorkspace, clampParcels, mapAwbHistoryPage, mapAwbPage, mapShopRow, mapStoreOrder, mapStoreSummary,
  opensStoreWorkspace, parcelKey, parseWeight, reprintReasonText, retryable, sectorOf, stageNumber, telHref, toneOf, validRange, type StoreAwbItem,
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
const item = (shop: Record<string, unknown> | null, lastAttempt: string | null = null): StoreAwbItem => ({ globalOrderId: "trendhome:1", orderNumber: "1", source: "trendhome", stage: "quality-control", lastAttempt, shop: shop === null ? null : mapShopRow(shop) });

// 41.x Access and home ----------------------------------------------------------------------------------------------------------
test("the Store Workspace is the home of a shop employee, never of a Confirmare operator or root", () => {
  assert.equal(canUseStoreWorkspace(employee()), true);
  assert.equal(opensStoreWorkspace(employee(), false), true);
  assert.equal(opensStoreWorkspace(employee(), true), false, "an operator keeps the operator desk");
  assert.equal(opensStoreWorkspace(employee({ confirmareScopes: { operate: ["trendhome"], supervise: [] } }), false), false);
  assert.equal(opensStoreWorkspace(employee(), false, "?clasic=1"), false, "the earlier home stays reachable");
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
