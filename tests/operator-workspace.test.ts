import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  DESK_DECIDED_DAYS, deskMatches, deskWarningKey, fieldKind, isTechnicalLabel, linePrice, measureUnit, orderBreakdown, previewBreakdown, visibleDetails, waitTone,
} from "../domain/operatorWorkspace";
import { CATALOGS, SUPPORTED_LOCALES } from "../i18n";
import type { ShopLine, ShopOrderDetail } from "../domain/confirmareShop";

// TH 65794 as the shop holds it (read-only, 2026-11-26): prices entered with VAT, one line 277.69 net + 58.31 VAT, flat
// shipping 15.97 net + 3.35 VAT, no coupon, total 355.32 (VAT 61.67). Product data only; no customer data.
const line65794: ShopLine = {
  id: "1", line: 1, productId: "1", variationId: "2", name: "Draperie Diamond Blackout 90% Alb - Alb", sku: "DDB-ALB", width: 6, height: 2.5, unit: "m",
  details: [
    { label: "Culoare", value: "Alb" }, { label: "Lungimea (L)", value: "6" }, { label: "Inaltime (H)", value: "2.50" },
    { label: "Manopera", value: "Manopera Rejansa Bara (Țeavă)" }, { label: "buc", value: "2 buc." }, { label: "trendhome-tools_monopera_cost", value: "8" },
  ],
  quantity: 1, unitPrice: "277.69", subtotal: "277.69", discount: "0.00", tax: "58.31", total: "277.69", calculator: true, image: null,
};
const order65794 = (patch: Partial<ShopOrderDetail> = {}): ShopOrderDetail => ({
  readAt: "2026-11-26T09:00:00Z",
  order: { id: "65794", number: "65794", status: { code: "processing", label: "Processing" }, createdAt: null, modifiedAt: null, storage: "hpos", currency: "RON", pricesIncludeTax: true },
  version: "v", pricingFingerprint: null,
  contact: { phoneAvailable: true, billing: { name: "", company: "", phone: "", addressLines: [] }, shipping: { name: "", company: "", phone: "", addressLines: [] } },
  lines: [line65794], fees: [], coupons: [],
  shipping: [{ id: "9", methodId: "flat_rate", instanceId: "1", title: "Cost de expediere", total: "15.97", tax: "3.35" }],
  totals: { subtotal: "277.69", discount: "0.00", discountTax: "0.00", shipping: "15.97", shippingTax: "3.35", fees: "0.00", tax: "61.67", total: "355.32", refunded: "0.00" },
  payment: { method: "cod", methodTitle: "Ramburs", cod: true, paidRecorded: false, financial: { state: "waiting", basis: null }, advance: { percentage: 30, required: "106.60", collected: null } },
  ...patch,
});

test("TH 65794: the total is products with VAT plus shipping with VAT; the 1-ban rounding never appears", () => {
  const breakdown = orderBreakdown(order65794());
  assert.ok(breakdown);
  assert.equal(breakdown.vat, "incl");
  assert.deepEqual(breakdown.rows, [{ key: "products", amount: "336.00" }, { key: "shipping", amount: "19.32", free: false }]);
  assert.equal(breakdown.total, "355.32");
  assert.equal(breakdown.tax, "61.67");
  assert.equal(breakdown.consistent, true);
});

test("net prices: VAT is its own row and is added once; a coupon is subtracted once", () => {
  const detail = order65794({
    order: { ...order65794().order, pricesIncludeTax: false },
    lines: [{ ...line65794, subtotal: "100.00", discount: "10.00", total: "90.00", tax: "18.90" }],
    coupons: [{ code: "x10", discount: "10.00", discountTax: "2.10" }],
    shipping: [{ id: "9", methodId: "flat_rate", instanceId: "1", title: "Curier", total: "15.97", tax: "3.35" }],
    totals: { subtotal: "100.00", discount: "10.00", discountTax: "2.10", shipping: "15.97", shippingTax: "3.35", fees: "0.00", tax: "22.25", total: "128.22", refunded: "0.00" },
  });
  const breakdown = orderBreakdown(detail);
  assert.ok(breakdown);
  assert.equal(breakdown.vat, "excl");
  assert.deepEqual(breakdown.rows.map((row) => [row.key, row.amount]), [["products", "100.00"], ["discount", "-10.00"], ["shipping", "15.97"], ["vat", "22.25"]]);
  assert.equal(breakdown.consistent, true);
});

test("prices with VAT and a coupon: the coupon with its VAT is subtracted once from the products with their VAT", () => {
  const detail = order65794({
    lines: [{ ...line65794, subtotal: "100.00", discount: "10.00", total: "90.00", tax: "18.90" }],
    shipping: [],
    totals: { subtotal: "100.00", discount: "10.00", discountTax: "2.10", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "18.90", total: "108.90", refunded: "0.00" },
  });
  const breakdown = orderBreakdown(detail)!;
  assert.deepEqual(breakdown.rows.map((row) => [row.key, row.amount]), [["products", "121.00"], ["discount", "-12.10"]]);
  assert.equal(breakdown.consistent, true);
});

test("free shipping is a row of its own; a large gap never shows a made-up sum", () => {
  const free = orderBreakdown(order65794({
    shipping: [{ id: "9", methodId: "free_shipping", instanceId: "2", title: "Livrare gratuită", total: "0.00", tax: "0.00" }],
    totals: { subtotal: "277.69", discount: "0.00", discountTax: "0.00", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "58.31", total: "336.00", refunded: "0.00" },
  }))!;
  assert.deepEqual(free.rows.find((row) => row.key === "shipping"), { key: "shipping", amount: "0.00", free: true });
  const broken = orderBreakdown(order65794({ totals: { ...order65794().totals, total: "999.00" } }))!;
  assert.equal(broken.consistent, false);
  assert.equal(broken.rows.some((row) => row.key === "rounding"), false);
  assert.equal(orderBreakdown(order65794({ totals: { ...order65794().totals, total: "abc" } })), null);
});

test("a preview's breakdown uses the shop's preview totals only", () => {
  const preview = previewBreakdown({ subtotal: "300.00", subtotalTax: "63.00", discount: "0.00", discountTax: "0.00", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "63.00", total: "363.00" }, true, true)!;
  assert.deepEqual(preview.rows, [{ key: "products", amount: "363.00" }, { key: "shipping", amount: "0.00", free: true }]);
  assert.equal(preview.consistent, true);
});

test("a line's price: with VAT when the shop's prices carry VAT; a unit price only when it divides exactly", () => {
  assert.deepEqual(linePrice(line65794, true), { total: "336.00", unit: null, vat: "incl" });
  assert.deepEqual(linePrice({ total: "300.00", tax: "63.00", quantity: 2, unitPrice: "150.00" }, true), { total: "363.00", unit: "181.50", vat: "incl" });
  assert.deepEqual(linePrice({ total: "100.00", tax: "21.01", quantity: 3, unitPrice: "33.33" }, true), { total: "121.01", unit: null, vat: "incl" });
  assert.deepEqual(linePrice({ total: "300.00", tax: "63.00", quantity: 2, unitPrice: "150.00" }, false), { total: "300.00", unit: "150.00", vat: "excl" });
});

test("technical metadata is hidden from the operator; colour, sewing and pieces stay", () => {
  assert.equal(isTechnicalLabel("trendhome-tools_monopera_cost"), true);
  assert.equal(isTechnicalLabel("_wck_fields"), true);
  assert.equal(isTechnicalLabel("Manopera"), false);
  assert.equal(isTechnicalLabel("buc"), false);
  assert.equal(isTechnicalLabel("Lungimea (L)"), false);
  assert.equal(isTechnicalLabel("pa-culoare"), false);
  const shown = visibleDetails(line65794.details).map((detail) => detail.label);
  assert.deepEqual(shown, ["Culoare", "Lungimea (L)", "Inaltime (H)", "Manopera", "buc"]);
  // Edited in place: the values its fields already show are not repeated.
  assert.deepEqual(visibleDetails(line65794.details, ["Lungimea (L)", "Manopera"]).map((detail) => detail.label), ["Culoare", "Inaltime (H)", "buc"]);
});

test("field kinds come from the shop's field definitions; the unit only when the line states it", () => {
  assert.equal(fieldKind({ title: "buc", type: "select", options: [{ value: "2", title: "2 buc." }] }), "pieces");
  assert.equal(fieldKind({ title: "Manopera", type: "select", options: [] }), "sewing");
  assert.equal(fieldKind({ title: "Lungimea (L)", type: "number", options: null }), "measure");
  assert.equal(fieldKind({ title: "Culoare", type: "select", options: [{ value: "a", title: "Alb" }] }), "option");
  assert.equal(measureUnit(line65794, "6"), "m");
  assert.equal(measureUnit(line65794, "2,50"), "m");
  assert.equal(measureUnit(line65794, "7"), null);
  assert.equal(measureUnit({ width: 6, height: 2.5, unit: null }, "6"), null);
});

test("desk warnings never show a raw key; the waiting time only changes its weight", () => {
  assert.equal(deskWarningKey("possible_duplicate"), "dd.warn.possible_duplicate");
  assert.equal(deskWarningKey("something_new"), "dd.warn.other");
  for (const locale of SUPPORTED_LOCALES) {
    for (const key of ["dd.warn.possible_duplicate", "dd.warn.out_of_stock", "dd.warn.address_incomplete", "dd.warn.phone_missing", "dd.warn.other"]) {
      const value = (CATALOGS[locale] as unknown as Record<string, string>)[key];
      assert.ok(typeof value === "string" && value !== "" && !value.startsWith("dd."), `${locale} ${key}`);
    }
  }
  assert.equal((CATALOGS.ro as unknown as Record<string, string>)["dd.warn.possible_duplicate"], "Posibilă comandă dublă");
  assert.equal((CATALOGS.tr as unknown as Record<string, string>)["dd.warn.possible_duplicate"], "Olası mükerrer sipariş");
  assert.equal((CATALOGS.en as unknown as Record<string, string>)["dd.warn.possible_duplicate"], "Possible duplicate order");
  assert.equal(waitTone(30), "normal");
  assert.equal(waitTone(24 * 60), "long");
  assert.equal(waitTone(72 * 60), "critical");
});

test("search: order number (with or without #) or customer name, accents ignored", () => {
  const item = { orderNumber: "65794", customerName: "Ioana Ștefănescu" };
  assert.equal(deskMatches(item, "#6579"), true);
  assert.equal(deskMatches(item, "stefan"), true);
  assert.equal(deskMatches(item, "maria"), false);
  assert.equal(deskMatches(item, " "), true);
});

test("the confirmed and refused lists cover the API's own period", () => {
  const api = readFileSync(new URL("../operations-api/src/Confirmare/ConfirmareService.php", import.meta.url), "utf8");
  assert.match(api, new RegExp(`DESK_DECIDED_DAYS = ${DESK_DECIDED_DAYS};`));
});
