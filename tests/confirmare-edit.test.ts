import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { approvalMissing, confirmareErrorKey, type ConfirmareDetail } from "../domain/confirmare";
import { blockerLabel, draftProblems, editOperations, editReasonLabel, editRefusalOf, emptyEditDraft, normalizeFieldValue, productAddable, type EditCatalog, type EditProduct } from "../domain/confirmareEdit";
import { confirmareErrorDetails, mapEditCatalog, mapEditOutcome, mapEditProduct, mapEditResult } from "../services/production/httpServices";
import { ConfirmareShopEditor } from "../features/confirmare/ConfirmareShopEditor";
import { CATALOGS, type MessageKey } from "../i18n";

// Confirmare: controlled changes of the order in its shop (Staff 2.30.0, API 2.40.0, Phase 4B).
const FP = "b".repeat(64);
const VERSION = `sha256:${"c".repeat(64)}`;
const catalogRaw = {
  version: VERSION, editable: true, reason: null,
  lines: [
    { id: "11", productId: "501", variationId: "0", name: "Draperie Velvet", quantity: 1, editable: true, reason: null, variations: [],
      calculator: { fields: [
        { name: "lungimea", title: "Lungimea (L)", type: "number", editable: true, required: true, min: 0.5, max: 12, value: "3" },
        { name: "manopera", title: "Manopera", type: "select", editable: true, required: true, min: null, max: null, options: [{ value: "13:Normal", title: "Normal" }, { value: "18:Bara", title: "Bara" }], value: "13:Normal" },
        { name: "nota", title: "Notă", type: "text", editable: false, required: false, min: null, max: null, value: "x" },
      ] } },
    { id: "12", productId: "502", variationId: "601", name: "Inele", quantity: 2, editable: true, reason: null, calculator: null,
      variations: [{ id: "601", attributes: [{ label: "Culoare", value: "Alb" }], inStock: true, purchasable: true, stock: 5 }, { id: "602", attributes: [{ label: "Culoare", value: "Negru" }], inStock: true, purchasable: true, stock: 2 }] },
    { id: "13", productId: "503", variationId: "0", name: "Perdea fără măsuri salvate", quantity: 1, editable: false, reason: "calculator_state_unknown", calculator: null, variations: [] },
  ],
  shipping: [{ id: "19", method: "flat_rate:3", title: "Curier" }],
  shippingOptions: [{ method: "flat_rate:3", title: "Curier" }, { method: "free_shipping:4", title: "Gratuit" }],
};
const catalog: EditCatalog = mapEditCatalog(catalogRaw);
const totals = (total: string) => ({ subtotal: "720.00", subtotalTax: "114.95", discount: "30.00", discountTax: "4.79", shipping: "25.00", shippingTax: "3.99", fees: "0.00", tax: "114.16", total });
const snapshot = (subtotal: string, length: string) => ({ name: "Draperie Velvet", productId: "501", variationId: "0", quantity: 1, fields: { lungimea: length, manopera: "13:Normal" }, subtotal, total: subtotal, tax: "67.06" });
const resultRaw = {
  version: VERSION, applicable: true, blockers: [], before: totals("719.00"), after: totals("754.00"),
  lines: [{ lineId: "11", state: "changed", before: snapshot("420.00", "3"), after: snapshot("455.00", "3.5") }, { lineId: "new:1", state: "added", before: null, after: snapshot("10.00", "1") }],
  shipping: [{ id: "19", method: "flat_rate:3", before: "25.00", after: "25.00" }],
  financial: { delta: "35.00", impact: "additional_payment_due", cod: false },
};
const detail = (overrides: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", status: "pending", version: 3, workflowCohort: "confirmare",
  openedAt: "2026-10-22T08:00:00Z", submittedAt: "2026-10-22T07:59:00Z", productionStageId: "waiting", commerceStatus: "pending", commerceClosed: false,
  payment: { state: "cod" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: { employeeId: "00000000-0000-4000-8000-000000000001", displayName: "Op", at: "2026-10-22T08:01:00Z", kind: "auto" }, decision: null,
  review: { fingerprint: FP, lineIds: ["11"], pricingAvailable: true, callRecorded: true, contactAvailable: true }, lines: [], contacts: [],
  customer: { visible: true, name: "TEST", company: null, addressLines: [], phoneMasked: "07** *** ***", phoneAvailable: false },
  source: { commerceStatusLabel: "Pending payment", changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: false, missing: ["approval"] }, events: [],
  sourceAccess: { configured: true, read: true, edit: true, changePending: false, openEdits: [] }, ...overrides,
});

test("only what the operator changed becomes an operation; never a price", () => {
  assert.deepEqual(editOperations(catalog, emptyEditDraft()), [], "nothing changed, nothing sent");
  const draft = emptyEditDraft();
  draft.lines["11"] = { fields: { lungimea: "3.5", manopera: "13:Normal" }, quantity: 1 };
  draft.lines["12"] = { variationId: "602", quantity: 3 };
  draft.shipping["19"] = "free_shipping:4";
  const ops = editOperations(catalog, draft);
  assert.deepEqual(ops, [
    { op: "update_line", lineId: "11", fields: { lungimea: "3.5" } },
    { op: "update_line", lineId: "12", quantity: 3, variationId: "602" },
    { op: "set_shipping", shippingLineId: "19", method: "free_shipping:4" },
  ]);
  assert.ok(!JSON.stringify(ops).match(/price|total|subtotal/i), "no operation carries an amount");
  const removal = emptyEditDraft();
  removal.lines["12"] = { remove: true, quantity: 9 };
  assert.deepEqual(editOperations(catalog, removal), [{ op: "remove_line", lineId: "12" }], "a removed line is only removed");
  const product: EditProduct = mapEditProduct({ id: "700", name: "Inele", sku: "IN-1", type: "simple", purchasable: true, inStock: true, stock: 4, variations: [], calculator: null });
  const adding = emptyEditDraft();
  adding.added.push({ key: "new-1", product, variationId: null, quantity: 2, fields: {} });
  assert.deepEqual(editOperations(catalog, adding), [{ op: "add_line", productId: "700", quantity: 2 }]);
  const locked = emptyEditDraft();
  locked.lines["11"] = { fields: { nota: "changed" } };
  assert.deepEqual(editOperations(catalog, locked), [], "a field the shop marks as not editable is never sent");
});

test("values the shop would refuse are flagged before the shop is asked", () => {
  const draft = emptyEditDraft();
  draft.lines["11"] = { fields: { lungimea: "15" } };
  assert.deepEqual(draftProblems(catalog, draft), ["11:lungimea"], "above the maximum");
  draft.lines["11"] = { fields: { lungimea: "" } };
  assert.deepEqual(draftProblems(catalog, draft), ["11:lungimea"], "a required value is missing");
  draft.lines["11"] = { fields: { manopera: "99:Inventat" } };
  assert.deepEqual(draftProblems(catalog, draft), ["11:manopera"], "an option the shop does not offer");
  draft.lines["11"] = { quantity: 0 };
  assert.deepEqual(draftProblems(catalog, draft), ["11:quantity"], "quantity 1..100");
  const all = emptyEditDraft();
  for (const line of catalog.lines) all.lines[line.id] = { remove: true };
  assert.ok(draftProblems(catalog, all).includes("last_line"), "at least one product stays");
  assert.equal(normalizeFieldValue(catalog.lines[0]!.calculator!.fields[0]!, " 3,5 "), "3.5", "a Romanian decimal comma becomes the shop's dot");
  assert.deepEqual(productAddable(mapEditProduct({ id: "1", name: "X", sku: "X", type: "simple", purchasable: true, inStock: false, stock: 0, variations: [], calculator: null })), { ok: false, reason: "out_of_stock" });
  assert.deepEqual(productAddable(mapEditProduct({ id: "1", name: "X", sku: "X", type: "simple", purchasable: true, inStock: true, stock: null, variations: [], calculator: { unavailable: "calculator_time_dependent" } })), { ok: false, reason: "calculator_time_dependent" });
});

test("the shop's edit answers are mapped strictly; anything malformed refuses the whole answer", () => {
  assert.equal(catalog.lines[0]!.calculator!.fields[1]!.options![1]!.value, "18:Bara");
  assert.equal(catalog.lines[2]!.editable, false);
  const result = mapEditResult(resultRaw);
  assert.equal(result.after.total, "754.00");
  assert.equal(result.lines[1]!.state, "added");
  const broken = (patch: (copy: typeof resultRaw) => void) => { const copy = structuredClone(resultRaw); patch(copy); return () => mapEditResult(copy); };
  assert.throws(broken((copy) => { copy.after.total = "754"; }), "an amount without decimals");
  assert.throws(broken((copy) => { (copy.financial as { impact: string }).impact = "refund_now"; }), "an unknown impact");
  assert.throws(broken((copy) => { (copy.lines[0] as { state: string }).state = "edited"; }), "an unknown line state");
  assert.throws(() => mapEditCatalog({ ...catalogRaw, version: "v2" }), "a malformed version");
  const applied = mapEditOutcome({ state: "applied", idempotencyKey: "k".repeat(20), result: { ...resultRaw, modifiedAt: "2026-10-22T10:00:00Z", financialState: { state: "waiting", basis: null, attention: "additional_payment_due" } } });
  assert.ok(applied.state === "applied" && applied.result?.financialState.attention === "additional_payment_due");
  assert.deepEqual(mapEditOutcome({ state: "unknown", idempotencyKey: "k".repeat(20) }), { state: "unknown", idempotencyKey: "k".repeat(20), reachable: null });
  assert.throws(() => mapEditOutcome({ state: "refunded", idempotencyKey: "k".repeat(20) }), "no other state exists");
});

test("refusals: reason, the shop's version and blockers come through as codes", () => {
  const details = confirmareErrorDetails({ reason: "blocked", version: VERSION, blockers: [{ code: "out_of_stock", productId: "501", available: 0, needed: 3 }] });
  assert.deepEqual(editRefusalOf(details), { reason: "blocked", version: VERSION, blockers: [{ code: "out_of_stock", productId: "501", available: 0, needed: 3 }] });
  assert.equal(editRefusalOf(confirmareErrorDetails({ reason: "<script>" })), null, "a malformed reason is dropped");
  assert.equal(editReasonLabel("<script>"), editReasonLabel(null), "an unknown reason reads as a generic refusal");
  assert.ok(blockerLabel({ code: "out_of_stock", available: 0, needed: 3 }).includes("3"));
  for (const code of ["CONFIRMARE_SHOP_CHANGE_PENDING", "CONFIRMARE_EDIT_REFUSED", "CONFIRMARE_SOURCE_UNAVAILABLE", "CONFIRMARE_SOURCE_EDIT_DISABLED", "CONFIRMARE_EDIT_INVALID", "CONFIRMARE_EDIT_NOT_FOUND"]) {
    assert.ok(confirmareErrorKey(code) !== null, `${code} has its own explanation`);
  }
});

test("an unsettled shop change blocks the approval", () => {
  const settled = detail();
  assert.ok(!approvalMissing(settled, { confirmedLineIds: ["11"], priceReviewed: true, shopReviewed: true }, true).includes("shopSettled"));
  const pending = detail({ sourceAccess: { configured: true, read: true, edit: true, changePending: true, openEdits: [] } });
  assert.ok(approvalMissing(pending, { confirmedLineIds: ["11"], priceReviewed: true, shopReviewed: true }, true).includes("shopSettled"));
});

test("the editor: nothing before an explicit start; honest states for unsettled changes", () => {
  const service = {} as never;
  const render = (d: ConfirmareDetail, currency: string | null = "RON") => renderToStaticMarkup(createElement(ConfirmareShopEditor, { detail: d, service, currency, onApplied: () => undefined }));
  const closed = render(detail());
  assert.ok(closed.includes("cf-edit-start") && !closed.includes("cf-edit-preview"), "the change starts only on request");
  assert.ok(render(detail(), null).includes("disabled"), "the shop order is read first");
  const open = render(detail({ sourceAccess: { configured: true, read: true, edit: true, changePending: true, openEdits: [{ idempotencyKey: "a".repeat(36), state: "unknown", requestedAt: "2026-10-22T10:00:00Z", mine: true }] } }));
  assert.ok(open.includes("cf-edit-open") && open.includes("cf-edit-reconcile") && !open.includes("cf-edit-start"), "an unknown change is checked first; no new change meanwhile");
  const waiting = render(detail({ sourceAccess: { configured: true, read: true, edit: true, changePending: true, openEdits: [] } }));
  assert.ok(waiting.includes("cf-edit-waiting") && !waiting.includes("cf-edit-start"), "an applied change waits for the shop's update");
});

test("every 4B message exists in all four catalogs and differs from Romanian where it should", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("cf.edit.") || key === "cf.require.shopSettled" || key.startsWith("cf.error.CONFIRMARE_EDIT") || key === "cf.error.CONFIRMARE_SHOP_CHANGE_PENDING" || key.startsWith("cf.error.CONFIRMARE_SOURCE_"));
  assert.ok(keys.length >= 120, `all edit messages are in the catalog (${keys.length})`);
  for (const locale of ["en", "tr", "si"] as const) {
    for (const key of keys) {
      const value = CATALOGS[locale][key as MessageKey];
      assert.ok(typeof value === "string" && value.length > 0, `${locale} ${key}`);
    }
  }
  const sinhala = keys.filter((key) => !/[඀-෿]/.test(String(CATALOGS.si[key as MessageKey])));
  assert.deepEqual(sinhala, [], "every Sinhala message is written in Sinhala");
});
