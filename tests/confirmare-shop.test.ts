import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { approvalMissing, carryDraft, confirmareErrorKey, type ConfirmareDetail } from "../domain/confirmare";
import { formatShopMoney, shopConfirmsPrices, shopPhones, shopUnavailableLabel, telHref } from "../domain/confirmareShop";
import { mapConfirmareDetail, mapShopRead } from "../services/production/httpServices";
import { ConfirmareShopPanel } from "../features/confirmare/ConfirmareShopPanel";
import { CATALOGS, type MessageKey } from "../i18n";

// Confirmare: the shop's own order in Staff (Staff 2.30.0, API 2.40.0, Phase 4A).
const root = path.resolve(import.meta.dirname, "..");
const FP = "b".repeat(64);
const person = (phone: string) => ({ name: "TEST Client Sursa", company: "", phone, addressLines: ["Str. Exemplu 11", "400000 Cluj-Napoca"] });
const shopDetail = {
  readAt: "2026-10-22T10:00:00Z",
  order: { id: "1234", number: "1234", status: { code: "pending", label: "Pending payment" }, createdAt: "2026-10-22T09:00:00Z", modifiedAt: "2026-10-22T09:30:00Z", storage: "hpos", currency: "RON", pricesIncludeTax: true },
  version: `sha256:${"c".repeat(64)}`,
  pricingFingerprint: `sha256:${"d".repeat(64)}`,
  contact: { phoneAvailable: true, billing: person("0711 222 333"), shipping: person("") },
  lines: [{ id: "12341", line: 1, productId: "501", variationId: "0", name: "Draperie Velvet", sku: "DV-302", width: 3, height: 2.6, unit: "m", details: [{ label: "Manopera", value: "Manopera Rejansa Normal 6cm" }], quantity: 1, unitPrice: "420.00", subtotal: "420.00", discount: "42.00", tax: "60.35", total: "378.00", calculator: true }],
  fees: [],
  coupons: [{ code: "satest10", discount: "42.00", discountTax: "6.71" }],
  shipping: [{ id: "12349", methodId: "flat_rate", instanceId: "1", title: "Livrare prin curier", total: "25.00", tax: "3.99" }],
  totals: { subtotal: "420.00", discount: "42.00", discountTax: "6.71", shipping: "25.00", shippingTax: "3.99", fees: "0.00", tax: "64.34", total: "403.00", refunded: "0.00" },
  payment: { method: "cod", methodTitle: "Ramburs", cod: true, paidRecorded: false, financial: { state: "waiting", basis: null }, advance: { percentage: 30, required: "120.90", collected: null } },
};
const readOk = { available: true, role: "assignee", detail: shopDetail, freshness: { readAt: "2026-10-22T10:00:00Z", shopModifiedAt: "2026-10-22T09:30:00Z", arasyaChangedAt: "2026-10-22T09:30:00Z", pricingMatches: true, inSync: true } };
const detail = (overrides: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", status: "pending", version: 3, workflowCohort: "confirmare",
  openedAt: "2026-10-22T08:00:00Z", submittedAt: "2026-10-22T07:59:00Z", productionStageId: "waiting", commerceStatus: "pending", commerceClosed: false,
  payment: { state: "cod" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: { employeeId: "00000000-0000-4000-8000-000000000001", displayName: "Op", at: "2026-10-22T08:01:00Z", kind: "auto" }, decision: null,
  review: { fingerprint: FP, lineIds: ["12341"], pricingAvailable: true, callRecorded: true, contactAvailable: true }, lines: [], contacts: [],
  customer: { visible: true, name: "TEST", company: null, addressLines: [], phoneMasked: "07** *** ***", phoneAvailable: false },
  source: { commerceStatusLabel: "Pending payment", changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: false, missing: ["approval"] }, events: [],
  sourceAccess: { configured: true, read: true, edit: false, changePending: false, openEdits: [] }, ...overrides,
});

test("the shop read is mapped strictly: known fields only, anything malformed refuses the whole answer", () => {
  const mapped = mapShopRead(readOk);
  assert.equal(mapped.available, true);
  if (!mapped.available) return;
  assert.equal(mapped.detail.contact.billing.phone, "0711 222 333");
  assert.equal(mapped.detail.totals.total, "403.00");
  assert.equal(mapped.detail.lines[0]?.calculator, true);
  assert.deepEqual(mapShopRead({ available: false, reason: "source_unreachable" }), { available: false, reason: "source_unreachable" });
  const broken = (patch: (copy: typeof readOk) => void) => { const copy = structuredClone(readOk); patch(copy); return () => mapShopRead(copy); };
  assert.throws(broken((copy) => { copy.detail.totals.total = "4e2"; }), "a malformed amount");
  assert.throws(broken((copy) => { (copy.detail.lines[0] as { unitPrice: unknown }).unitPrice = 420; }), "an amount must be the shop's decimal string");
  assert.throws(broken((copy) => { (copy.detail.order as { storage: string }).storage = "mysql"; }), "an unknown storage");
  assert.throws(broken((copy) => { (copy as { role: string }).role = "root"; }), "an unknown role");
  assert.throws(broken((copy) => { (copy.detail.payment.financial as { state: string }).state = "paid"; }), "an unknown financial state");
  assert.throws(broken((copy) => { copy.detail.lines = Array.from({ length: 201 }, () => copy.detail.lines[0]!); }), "too many lines");
  const extra = mapShopRead({ ...structuredClone(readOk), secret: "x", detail: { ...structuredClone(shopDetail), adminUrl: "https://shop/wp-admin" } });
  assert.ok(!JSON.stringify(extra).includes("wp-admin") && !JSON.stringify(extra).includes("secret"), "unknown fields are never carried");
});

test("the detail names the shop access; an older API offers none; financial attention is read", () => {
  const raw = { ...detail(), review: { fingerprint: FP, lineIds: ["12341"], pricingAvailable: true, callRecorded: true, contactAvailable: true } } as Record<string, unknown>;
  delete raw.sourceAccess;
  assert.deepEqual(mapConfirmareDetail(raw).sourceAccess, { configured: false, read: false, edit: false, changePending: false, openEdits: [] });
  assert.deepEqual(mapConfirmareDetail({ ...raw, sourceAccess: { configured: true, read: "yes", edit: true } }).sourceAccess, { configured: true, read: false, edit: true, changePending: false, openEdits: [] });
  assert.equal(mapConfirmareDetail({ ...raw, financial: { state: "waiting", basis: null, open: false, attention: "refund_due" } }).financial.attention, "refund_due");
  assert.throws(() => mapConfirmareDetail({ ...raw, financial: { state: "waiting", basis: null, open: false, attention: "paid" } }));
});

test("where the shop can be read, the approval also needs the shop order read with the prices Arasya holds", () => {
  const ready = { confirmedLineIds: ["12341"], priceReviewed: true };
  assert.deepEqual(approvalMissing(detail(), ready, true), ["shopReviewed"]);
  assert.deepEqual(approvalMissing(detail(), { ...ready, shopReviewed: true }, true), []);
  assert.deepEqual(approvalMissing(detail({ sourceAccess: { configured: false, read: false, edit: false, changePending: false, openEdits: [] } }), ready, true), [], "without shop access nothing changes (Phase 3 rule)");
  const ok = mapShopRead(readOk);
  assert.equal(shopConfirmsPrices(ok), true);
  assert.equal(shopConfirmsPrices(mapShopRead({ ...readOk, freshness: { ...readOk.freshness, pricingMatches: false, inSync: false } })), false, "other prices than Arasya holds never count");
  assert.equal(shopConfirmsPrices(mapShopRead({ ...readOk, freshness: { ...readOk.freshness, pricingMatches: null } })), false, "no price evidence never counts");
  assert.equal(shopConfirmsPrices({ available: false, reason: "source_unreachable" }), false);
  assert.equal(shopConfirmsPrices(null), false);
  const carried = carryDraft({ fingerprint: FP, draft: { confirmedLineIds: ["12341"], priceReviewed: true, shopReviewed: true } }, detail({ review: { fingerprint: "e".repeat(64), lineIds: ["12341"], pricingAvailable: true, callRecorded: true, contactAvailable: true } }));
  assert.equal(carried.reset, true);
  assert.notEqual(carried.draft.shopReviewed, true, "a changed order needs a new shop read");
  assert.ok(confirmareErrorKey("CONFIRMARE_SHOP_NOT_REVIEWED") && confirmareErrorKey("CONFIRMARE_SOURCE_ACCESS_DENIED") && confirmareErrorKey("CONFIRMARE_AUDIT_UNAVAILABLE"));
});

test("telephone links are safe and numbers are listed once; amounts are shown in the shop currency", () => {
  assert.equal(telHref("0711 222 333"), "tel:0711222333");
  assert.equal(telHref("+40 (711) 222-333"), "tel:+40711222333");
  assert.equal(telHref("javascript:alert(1)"), null);
  assert.equal(telHref("0711"), null);
  const ok = mapShopRead(readOk);
  if (!ok.available) throw new Error("expected a read");
  assert.deepEqual(shopPhones(ok.detail).map((entry) => entry.kind), ["billing"], "an empty delivery number is not listed");
  const same = { ...ok.detail, contact: { ...ok.detail.contact, shipping: { ...ok.detail.contact.shipping, phone: "0711-222-333" } } };
  assert.equal(shopPhones(same).length, 1, "the same number twice is listed once");
  assert.match(formatShopMoney("403.00", "RON"), /403,00/);
  assert.equal(formatShopMoney(null, "RON"), "—");
  assert.equal(formatShopMoney("abc", "RON"), "—");
  assert.ok(shopUnavailableLabel("source_unreachable").length > 0 && shopUnavailableLabel("<script>") === shopUnavailableLabel("source_error"), "unknown reasons read as a generic failure");
});

test("the panel: nothing customer-related before an explicit read; honest states without access", () => {
  const service = { sourceOrder: () => Promise.resolve(mapShopRead(readOk)) } as never;
  const render = (d: ConfirmareDetail) => renderToStaticMarkup(createElement(ConfirmareShopPanel, { detail: d, service, onChange: () => undefined, onRefresh: () => undefined }));
  const closed = render(detail());
  assert.ok(closed.includes("cf-shop-load") && !closed.includes("0711") && !closed.includes("403"), "no customer data until the operator asks");
  assert.ok(render(detail({ sourceAccess: { configured: false, read: false, edit: false, changePending: false, openEdits: [] } })).includes("cf-shop-off"), "a shop without access says so");
  const colleague = render(detail({ sourceAccess: { configured: true, read: false, edit: false, changePending: false, openEdits: [] } }));
  assert.ok(colleague.includes("cf-shop-not-yours") && !colleague.includes("cf-shop-load"), "a colleague's order offers no read");
});

test("every shop text exists in every language; nothing is stored in the browser", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("cf.shop.") || key.startsWith("cf.financial.attention."));
  assert.ok(keys.length > 40);
  for (const locale of ["en", "tr", "si"] as const) for (const key of keys) assert.ok((CATALOGS[locale][key as MessageKey] as string).length > 0, `${locale} ${key}`);
  const panel = readFileSync(path.join(root, "features/confirmare/ConfirmareShopPanel.tsx"), "utf8");
  const domain = readFileSync(path.join(root, "domain/confirmareShop.ts"), "utf8");
  for (const source of [panel, domain]) assert.ok(!/localStorage|sessionStorage|indexedDB|console\.|history\.(push|replace)State/.test(source), "shop data never reaches storage, the console or the URL");
});
