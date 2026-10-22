import { t, type MessageKey } from "../i18n";

/**
 * Controlled changes of a pending Confirmare order in its shop (API 2.40.0, Phase 4B). Staff only collects what the
 * operator changes (measurements, options, quantity, variation, a product of the same shop by SKU, a removed line, the
 * shipping method) and shows what the SHOP computed: WooCommerce prices, coupons, VAT, shipping and totals. Staff never
 * computes a price. Nothing of an edit is kept after the screen closes (component memory only).
 */
export type EditField = {
  name: string; title: string; type: string; editable: boolean; required: boolean;
  min: number | null; max: number | null; options: { value: string; title: string }[] | null; value?: string | null;
};
export type EditVariation = { id: string; attributes: { label: string; value: string }[]; inStock: boolean; purchasable: boolean; stock: number | null };
export type EditCatalogLine = {
  id: string; productId: string; variationId: string; name: string; quantity: number;
  calculator: { fields: EditField[] } | null; variations: EditVariation[]; editable: boolean; reason: string | null;
};
export type EditCatalog = {
  version: string; editable: boolean; reason: string | null; lines: EditCatalogLine[];
  shipping: { id: string; method: string; title: string }[]; shippingOptions: { method: string; title: string }[];
};
export type EditProduct = {
  id: string; name: string; sku: string; type: "simple" | "variable"; purchasable: boolean; inStock: boolean; stock: number | null;
  variations: EditVariation[]; calculator: { fields: EditField[] } | { unavailable: string } | null;
};
export type EditOperation =
  | { op: "update_line"; lineId: string; quantity?: number; variationId?: string; fields?: Record<string, string> }
  | { op: "add_line"; productId: string; variationId?: string; quantity: number; fields?: Record<string, string> }
  | { op: "remove_line"; lineId: string }
  | { op: "set_shipping"; shippingLineId: string; method: string };
export type EditTotals = { subtotal: string; subtotalTax: string; discount: string; discountTax: string; shipping: string; shippingTax: string; fees: string; tax: string; total: string };
export type EditLineSnapshot = { name: string; productId: string; variationId: string; quantity: number; fields: Record<string, string> | null; subtotal: string; total: string; tax: string };
export type EditLineDiff = { lineId: string; state: "added" | "removed" | "changed" | "unchanged"; before: EditLineSnapshot | null; after: EditLineSnapshot | null };
export type EditBlocker = { code: string; productId?: string; method?: string; coupon?: string; available?: number; needed?: number };
export const EDIT_IMPACTS = ["none", "additional_payment_due", "refund_due", "advance_due"] as const;
export type EditImpact = typeof EDIT_IMPACTS[number];
export type EditResult = {
  version: string; applicable: boolean; blockers: EditBlocker[]; before: EditTotals; after: EditTotals; lines: EditLineDiff[];
  shipping: { id: string; method: string; before: string; after: string }[]; financial: { delta: string; impact: EditImpact; cod: boolean };
};
export type EditPreview = EditResult & { previewToken: string };
export type EditApplied = EditResult & { modifiedAt: string; financialState: { state: string; basis: string | null; attention: "additional_payment_due" | "refund_due" | null } };
export type EditOutcome =
  | { state: "applied"; idempotencyKey: string; result: EditApplied | null }
  | { state: "pending" | "unknown"; idempotencyKey: string; reachable: boolean | null };
export type OpenEdit = { idempotencyKey: string; state: "pending" | "unknown"; requestedAt: string; mine: boolean };
export type EditRefusal = { reason: string; version: string | null; blockers: EditBlocker[] };

export interface ConfirmareEditApi {
  sourceCatalog(globalOrderId: string, signal?: AbortSignal): Promise<EditCatalog>;
  sourceProduct(globalOrderId: string, sku: string): Promise<EditProduct>;
  sourcePreview(globalOrderId: string, input: { expectedVersion: string; operations: EditOperation[] }): Promise<EditPreview>;
  sourceApply(globalOrderId: string, input: { expectedVersion: string; operations: EditOperation[]; previewToken: string }, idempotencyKey: string): Promise<EditOutcome>;
  sourceReconcile(globalOrderId: string, idempotencyKey: string): Promise<EditOutcome>;
}

/** What the operator changed (only differences from the shop's catalog become operations). */
export type LineDraft = { quantity?: number; variationId?: string; fields?: Record<string, string>; remove?: boolean };
export type AddedDraft = { key: string; product: EditProduct; variationId: string | null; quantity: number; fields: Record<string, string> };
export type EditDraft = { lines: Record<string, LineDraft>; added: AddedDraft[]; shipping: Record<string, string> };

export const emptyEditDraft = (): EditDraft => ({ lines: {}, added: [], shipping: {} });
export const MAX_EDIT_QUANTITY = 100;

/** The operations for the shop: one per changed line, an added product, a changed shipping method. */
export function editOperations(catalog: EditCatalog, draft: EditDraft): EditOperation[] {
  const ops: EditOperation[] = [];
  for (const line of catalog.lines) {
    const change = draft.lines[line.id];
    if (!change) continue;
    if (change.remove) {
      ops.push({ op: "remove_line", lineId: line.id });
      continue;
    }
    const op: Extract<EditOperation, { op: "update_line" }> = { op: "update_line", lineId: line.id };
    if (change.quantity !== undefined && change.quantity !== line.quantity) op.quantity = change.quantity;
    if (change.variationId !== undefined && change.variationId !== line.variationId) op.variationId = change.variationId;
    const fields: Record<string, string> = {};
    for (const field of line.calculator?.fields ?? []) {
      const value = change.fields?.[field.name];
      if (value !== undefined && field.editable && value !== (field.value ?? "")) fields[field.name] = value;
    }
    if (Object.keys(fields).length > 0) op.fields = fields;
    if (op.quantity !== undefined || op.variationId !== undefined || op.fields !== undefined) ops.push(op);
  }
  for (const added of draft.added) {
    const op: Extract<EditOperation, { op: "add_line" }> = { op: "add_line", productId: added.product.id, quantity: added.quantity };
    if (added.variationId) op.variationId = added.variationId;
    if (Object.keys(added.fields).length > 0) op.fields = { ...added.fields };
    ops.push(op);
  }
  for (const line of catalog.shipping) {
    const method = draft.shipping[line.id];
    if (method !== undefined && method !== line.method) ops.push({ op: "set_shipping", shippingLineId: line.id, method });
  }
  return ops;
}

/** Field values the shop would refuse anyway (empty required value, a number outside its limits), keyed `line:field`. */
export function draftProblems(catalog: EditCatalog, draft: EditDraft): string[] {
  const problems: string[] = [];
  const check = (key: string, field: EditField, value: string | undefined) => {
    if (!field.editable) return;
    const current = value ?? field.value ?? "";
    if (current.trim() === "") {
      if (field.required) problems.push(key);
      return;
    }
    if (field.type === "number") {
      const number = Number(current.replace(",", "."));
      if (!Number.isFinite(number) || (field.min !== null && number < field.min) || (field.max !== null && number > field.max)) problems.push(key);
    } else if (field.options && !field.options.some((option) => option.value === current)) {
      problems.push(key);
    }
  };
  for (const line of catalog.lines) {
    const change = draft.lines[line.id];
    if (!change || change.remove) continue;
    if (change.quantity !== undefined && (!Number.isInteger(change.quantity) || change.quantity < 1 || change.quantity > MAX_EDIT_QUANTITY)) problems.push(`${line.id}:quantity`);
    for (const field of line.calculator?.fields ?? []) check(`${line.id}:${field.name}`, field, change.fields?.[field.name]);
  }
  for (const added of draft.added) {
    if (!Number.isInteger(added.quantity) || added.quantity < 1 || added.quantity > MAX_EDIT_QUANTITY) problems.push(`${added.key}:quantity`);
    if (added.product.type === "variable" && !added.variationId) problems.push(`${added.key}:variation`);
    const calculator = added.product.calculator;
    if (calculator && "fields" in calculator) for (const field of calculator.fields) check(`${added.key}:${field.name}`, field, added.fields[field.name]);
  }
  if (catalog.lines.every((line) => draft.lines[line.id]?.remove) && draft.added.length === 0) problems.push("last_line");
  return problems;
}

/** A number typed with a comma (Romanian keyboards) as the shop expects it: a dot decimal. */
export function normalizeFieldValue(field: EditField, value: string): string {
  return field.type === "number" ? value.trim().replace(",", ".") : value;
}

/** Whether the product added by SKU can be priced and sold by the shop now. */
export function productAddable(product: EditProduct): { ok: true } | { ok: false; reason: string } {
  if (!product.purchasable) return { ok: false, reason: "product_unavailable" };
  if (!product.inStock && product.type === "simple") return { ok: false, reason: "out_of_stock" };
  if (product.calculator && "unavailable" in product.calculator) return { ok: false, reason: product.calculator.unavailable };
  return { ok: true };
}

const REFUSAL_REASONS = [
  "edit_disabled", "order_status_not_editable", "refund_exists", "production_started", "shipping_started", "shipping_state_unknown", "financial_unknown",
  "invalid_operations", "invalid_quantity", "invalid_fields", "line_not_found", "last_line", "variation_invalid", "product_invalid", "product_not_found",
  "field_not_editable", "calculator_unavailable", "calculator_version_unsupported", "calculator_time_dependent", "calculator_unsupported_field",
  "calculator_invalid_input", "calculator_error", "calculator_state_unknown", "version_changed", "preview_stale", "blocked", "order_busy",
  "idempotency_conflict", "no_change", "not_received", "access_disabled", "rate_limited", "order_not_found", "order_not_eligible", "product_unavailable", "out_of_stock",
] as const;

/** Why the shop refused (or cannot be asked), in the operator's words; an unknown reason reads as a generic refusal. */
export function editReasonLabel(reason: string | null): string {
  return t(`cf.edit.reason.${reason !== null && (REFUSAL_REASONS as readonly string[]).includes(reason) ? reason : "other"}` as MessageKey);
}

const BLOCKERS = ["out_of_stock", "product_unavailable", "coupon_conditions", "shipping_method_unsupported", "shipping_rate_unavailable", "free_shipping_threshold_lost", "free_shipping_threshold_reached", "shipping_line_not_found"] as const;

export function blockerLabel(blocker: EditBlocker): string {
  const known = (BLOCKERS as readonly string[]).includes(blocker.code);
  return t(`cf.edit.blocker.${known ? blocker.code : "other"}` as MessageKey, {
    coupon: blocker.coupon ?? "", available: blocker.available ?? 0, needed: blocker.needed ?? 0, method: blocker.method ?? "", code: blocker.code,
  });
}

/** The refusal an edit request carried (API error details), or null. */
export function editRefusalOf(details: Record<string, unknown> | undefined): EditRefusal | null {
  if (!details || typeof details.editReason !== "string") return null;
  return { reason: details.editReason, version: typeof details.editVersion === "string" ? details.editVersion : null, blockers: Array.isArray(details.editBlockers) ? details.editBlockers as EditBlocker[] : [] };
}
