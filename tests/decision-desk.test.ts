import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { addressChanges, addressWarnings, advanceFor, draftKey, fromBani, loadDraft, moneyHeader, nextAfter, replacementFields, replacementOperations, saveDraft, toBani, waitingLabel } from "../domain/decisionDesk";
import { mapDecisionAnswer, mapDeskPool, mapEditResult, mapShopRead } from "../services/production/httpServices";
import type { ConfirmareItem } from "../domain/confirmare";
import type { EditProduct } from "../domain/confirmareEdit";
import type { AddressFields, ShopOrderDetail } from "../domain/confirmareShop";

const detail = (patch: { total: string; cod?: boolean; required?: string | null; collected?: string | null; percentage?: number }): ShopOrderDetail => ({
  readAt: "2026-11-26T08:00:00Z",
  order: { id: "1", number: "66124", status: { code: "processing", label: "Processing" }, createdAt: null, modifiedAt: null, storage: "hpos", currency: "RON", pricesIncludeTax: true },
  version: "sha256:" + "a".repeat(64), pricingFingerprint: null,
  contact: { phoneAvailable: true, billing: { name: "", company: "", phone: "", addressLines: [] }, shipping: { name: "", company: "", phone: "", addressLines: [] } },
  lines: [], fees: [], coupons: [], shipping: [],
  totals: { subtotal: "0.00", discount: "0.00", discountTax: "0.00", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "0.00", total: patch.total, refunded: "0.00" },
  payment: { method: "cod", methodTitle: "Ramburs", cod: patch.cod ?? true, paidRecorded: false, financial: { state: "waiting", basis: null },
    advance: { percentage: patch.percentage ?? 30, required: patch.required === undefined ? null : patch.required, collected: patch.collected === undefined ? "0.00" : patch.collected } },
});

test("bani: decimal strings of the shop, never floats", () => {
  assert.equal(toBani("1160.26"), 116026);
  assert.equal(toBani("0.1"), 10);
  assert.equal(toBani("-3.5"), -350);
  assert.equal(toBani("12"), 1200);
  assert.equal(toBani("1,5"), null);
  assert.equal(toBani("1.234"), null);
  assert.equal(fromBani(116026), "1160.26");
  assert.equal(fromBani(5), "0.05");
  assert.equal(fromBani(-350), "-3.50");
  // 0.1 + 0.2 in floats is 0.30000000000000004; in bani it is exact.
  assert.equal(fromBani(toBani("0.10")! + toBani("0.20")!), "0.30");
});

test("the advance asked follows YD SOFT MoneyPosition::compute (half away from zero, on integers)", () => {
  assert.equal(advanceFor(116026, 30), 34808); // 1160.26 -> 348.08 (TH66124's real proforma)
  assert.equal(advanceFor(31382, 30), 9415); // 313.82 -> 94.15 (TH66122)
  assert.equal(advanceFor(17232, 30), 5170); // 172.32 -> 51.70 (OP 60986)
  assert.equal(advanceFor(31272, 30), 9382); // 312.72 -> 93.82 (TH66140)
  assert.equal(advanceFor(100000, 30), 30000);
  assert.equal(advanceFor(5, 30), 2); // 0.015 rounds to 0.02
  assert.equal(advanceFor(-100, 30), 0);
});

test("sticky header: the real ramburs and the ramburs after the advance are never mixed up", () => {
  const unpaid = moneyHeader(detail({ total: "1000.00", required: "300.00" }));
  assert.deepEqual(unpaid && { total: unpaid.total, advance: unpaid.advance, received: unpaid.received, now: unpaid.rambursNow, after: unpaid.rambursAfterAdvance, est: unpaid.advanceEstimated },
    { total: "1000.00", advance: "300.00", received: "0.00", now: "1000.00", after: "700.00", est: false });
  const partial = moneyHeader(detail({ total: "1000.00", required: "300.00", collected: "250.00" }));
  assert.equal(partial?.rambursNow, "750.00", "250 verified: the courier collects 750");
  assert.equal(partial?.rambursAfterAdvance, "700.00");
  const covered = moneyHeader(detail({ total: "1000.00", required: "300.00", collected: "300.00" }));
  assert.equal(covered?.rambursNow, "700.00");
  assert.equal(covered?.rambursAfterAdvance, null, "the advance is covered: no second figure");
  const preview = moneyHeader(detail({ total: "1000.00", required: "300.00" }), "1200.00");
  assert.equal(preview?.total, "1200.00");
  assert.equal(preview?.advance, "360.00");
  assert.equal(preview?.advanceEstimated, true, "a previewed total's advance is marked as an estimate until the shop confirms it");
  const online = moneyHeader(detail({ total: "500.00", cod: false, collected: "500.00" }));
  assert.equal(online?.cod, false);
  assert.equal(online?.advance, null);
  assert.equal(online?.rambursNow, "0.00");
});

test("address: only the changed fields, trimmed; the billing address is never touched by a delivery edit", () => {
  const base: AddressFields = { firstName: "Ana", lastName: "Pop", company: "", address1: "Str. Lungă", streetNo: "9", building: "", entrance: "", floor: "", apartment: "", address2: "", city: "Brașov", state: "BV", postcode: "500000", country: "RO" };
  assert.deepEqual(addressChanges(base, { ...base }), {});
  assert.deepEqual(addressChanges(base, { ...base, apartment: " 14 ", city: "Brașov" }), { apartment: "14" });
  assert.deepEqual(addressChanges(base, { ...base, state: "B", city: "Sector 3" }), { state: "B", city: "Sector 3" });
  assert.deepEqual(addressWarnings(base), []);
  assert.deepEqual(addressWarnings({ ...base, address1: "", state: "", postcode: "12" }), ["street", "county", "postcode"]);
  assert.deepEqual(addressWarnings(null), []);
});

test("replacement: only fields the new product really has (with an allowed value) are carried over", () => {
  const product: EditProduct = {
    id: "77", name: "Draperie Velvet Gri", sku: "DV-GRI", type: "simple", purchasable: true, inStock: true, stock: null, variations: [],
    calculator: { fields: [
      { name: "lungimea", title: "Lățime", type: "number", editable: true, required: true, min: 0.5, max: 10, options: null },
      { name: "rejansa", title: "Rejansă", type: "select", editable: true, required: true, min: null, max: null, options: [{ value: "inele", title: "Inele" }] },
    ] },
  };
  const carried = replacementFields({ lungimea: "3.2", rejansa: "bara", culoare: "alb" }, product);
  assert.deepEqual(carried.fields, { lungimea: "3.2" });
  assert.deepEqual(carried.carried, ["lungimea"]);
  assert.deepEqual(carried.dropped.sort(), ["culoare", "rejansa"]);
  assert.deepEqual(replacementFields({ lungimea: "12" }, product).fields, {}, "a measurement outside the new product's limits is never carried");
  assert.deepEqual(replacementOperations("8001", product, null, 2, carried.fields), [{ op: "remove_line", lineId: "8001" }, { op: "add_line", productId: "77", quantity: 2, fields: { lungimea: "3.2" } }]);
});

test("draft safety: per employee and order, with the version it was made on, expired after three days", () => {
  const memory = new Map<string, string>();
  const storage = { getItem: (k: string) => memory.get(k) ?? null, setItem: (k: string, v: string) => { memory.set(k, v); }, removeItem: (k: string) => { memory.delete(k); } };
  const key = draftKey("emp-1", "trendhome:66124");
  assert.notEqual(key, draftKey("emp-2", "trendhome:66124"));
  saveDraft(storage, key, "sha256:v1", { lines: { "8001": { quantity: 2 } } });
  const loaded = loadDraft<{ lines: Record<string, { quantity: number }> }>(storage, key);
  assert.equal(loaded?.version, "sha256:v1");
  assert.equal(loaded?.draft.lines["8001"].quantity, 2);
  assert.equal(loadDraft(storage, key, Date.now() + 4 * 24 * 3600 * 1000), null, "an old draft is dropped");
  saveDraft(storage, key, "sha256:v1", null);
  assert.equal(loadDraft(storage, key), null);
  assert.equal(loadDraft(null, key), null, "no storage: memory only, never an error");
});

test("desk helpers: waiting time and the next order after a decision", () => {
  assert.deepEqual(waitingLabel(12), { value: 12, unit: "min" });
  assert.deepEqual(waitingLabel(185), { value: 3, unit: "h" });
  assert.deepEqual(waitingLabel(3 * 1440), { value: 3, unit: "d" });
  const item = (id: string, status: ConfirmareItem["status"] = "pending") => ({ globalOrderId: id, status }) as ConfirmareItem;
  assert.equal(nextAfter([item("a"), item("b"), item("c")], "b")?.globalOrderId, "c");
  assert.equal(nextAfter([item("a"), item("b")], "b")?.globalOrderId, "a");
  assert.equal(nextAfter([item("b")], "b"), null);
  assert.equal(nextAfter([item("a", "approved"), item("b")], "b"), null);
});

test("API 2.82.0 mappers: desk pool, decision answer, edit result with automatic shipping and address, shop stock and fields", () => {
  const item = { globalOrderId: "trendhome:66124", orderNumber: "66124", sourceKey: "trendhome", status: "pending", version: 3, workflowCohort: "confirmare", openedAt: "2026-11-25T10:00:00Z", submittedAt: null,
    productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false, payment: { state: "waiting" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true,
    dueDate: null, productionCleared: false, contact: null, assignment: null, decision: null, waiting: { at: "2026-11-25T11:00:00Z", note: "Revine", callbackAt: null }, lineCount: 2,
    display: { total: "1160.26", currency: "RON", warnings: ["out_of_stock"], readAt: "2026-11-25T11:00:00Z", current: true }, customerName: "Popescu Ana" };
  const pool = mapDeskPool({ view: "waiting", items: [item], total: 1, limit: 2000, counts: { new: 4, waiting: 1, confirmed: 2, refused: 0, doneToday: 3 }, sources: ["outletperdele", "trendhome"] });
  assert.equal(pool.counts.doneToday, 3);
  assert.equal(pool.items[0].waiting?.note, "Revine");
  assert.equal(pool.items[0].display?.total, "1160.26");
  assert.equal(pool.items[0].customerName, "Popescu Ana");
  const answer = mapDecisionAnswer({ ...item, status: "approved", operatorDecision: "confirmed", undoUntil: "2026-11-25T11:01:00Z" });
  assert.equal(answer.operatorDecision, "confirmed");
  const totals = { subtotal: "1.00", subtotalTax: "0.00", discount: "0.00", discountTax: "0.00", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "0.00", total: "1.00" };
  const edit = mapEditResult({ version: "sha256:" + "b".repeat(64), applicable: true, blockers: [], before: totals, after: totals, lines: [], shipping: [], financial: { delta: "0.00", impact: "none", cod: true },
    shippingAuto: [{ id: "9", from: "flat_rate", to: "free_shipping:3", reason: "free_shipping_threshold_reached" }], address: { shipping: { changed: ["apartment"], locality: { rule: "exact", suggestion: null } } } });
  assert.equal(edit.shippingAuto?.[0].to, "free_shipping:3");
  assert.deepEqual(edit.address?.shipping?.changed, ["apartment"]);
  const read = mapShopRead({ available: true, role: "assignee", freshness: { readAt: "2026-11-25T11:00:00Z", shopModifiedAt: null, arasyaChangedAt: null, pricingMatches: true, inSync: true }, detail: {
    ...detail({ total: "10.00" }), order: { ...detail({ total: "10.00" }).order }, pricingFingerprint: null,
    contact: { phoneAvailable: true, billing: { name: "A", company: "", phone: "", addressLines: [] }, shipping: { name: "A", company: "", phone: "", addressLines: ["x"], fields: { firstName: "A", city: "Brașov", state: "BV", country: "RO" } } },
    lines: [{ id: "1", line: 1, productId: "5", variationId: "0", name: "P", sku: "S", width: null, height: null, unit: null, details: [], quantity: 1, unitPrice: "10.00", subtotal: "10.00", discount: "0.00", tax: "0.00", total: "10.00", calculator: false, image: null, stock: { status: "outofstock", managed: true, quantity: 0 } }],
  } });
  assert.ok(read.available);
  if (read.available) {
    assert.equal(read.detail.lines[0].stock?.status, "outofstock");
    assert.equal(read.detail.contact.shipping.fields?.apartment, "", "a field the shop did not send reads as empty, never undefined");
    assert.equal(read.detail.contact.billing.fields, null, "an older shop's person has no fields");
  }
});

test("the operator desk keeps the safety rules out of the operator's way but in the code", () => {
  const workbench = readFileSync(new URL("../features/confirmare/OrderWorkbench.tsx", import.meta.url), "utf8");
  assert.ok(workbench.includes("reviewFingerprint: current.review.fingerprint"), "green sends the review the screen showed (a changed total is refused by the server)");
  assert.ok(workbench.includes("CONFIRMARE_REVIEW_STALE"), "a changed total shows the new total and asks again");
  assert.ok(!workbench.includes("customerCall"), "no call record field is sent");
  assert.ok(!workbench.includes("priceVerified"), "no separate price box");
  assert.ok(!/localStorage\.setItem\([^)]*phone/i.test(workbench), "no customer data in browser storage");
  const desk = readFileSync(new URL("../features/confirmare/DecisionDesk.tsx", import.meta.url), "utf8");
  assert.ok(!desk.includes("\"trendhome\"") && !desk.includes("\"outletperdele\""), "no shop name is written into the desk: the shops come from the server and the scopes");
});
