import type { MessageKey } from "../i18n";
import type { ConfirmareItem } from "./confirmare";
import type { EditField, EditLineSnapshot, EditTotals } from "./confirmareEdit";
import type { ShopDetailEntry, ShopLine, ShopOrderDetail } from "./confirmareShop";
import { fromBani, toBani } from "./decisionDesk";

/**
 * Operator Workspace V2 (Staff 2.58.0, owner brief 2026-11-26): presentation helpers for the operator desk and the order
 * workspace. Nothing here prices, taxes or decides anything: every amount is one the shop (WooCommerce, WC Kalkulator,
 * YD SOFT) stated, and the only arithmetic is adding the shop's own figures in bani to show how its total is made up.
 */

// ---- Desk list -----------------------------------------------------------------------------------------------------

/** The display warnings the API writes into the list copy (ConfirmareSourceAccess::rememberDisplay). */
export const DESK_WARNINGS = ["out_of_stock", "address_incomplete", "phone_missing", "possible_duplicate"] as const;
export type DeskWarning = (typeof DESK_WARNINGS)[number];

/** The message of a list warning; an unknown code reads as a generic, translated "check this order" (never a raw key). */
export function deskWarningKey(code: string): MessageKey {
  return ((DESK_WARNINGS as readonly string[]).includes(code) ? `dd.warn.${code}` : "dd.warn.other") as MessageKey;
}

/** The days the confirmed and refused lists cover (API ConfirmareService::DESK_DECIDED_DAYS; a unit test keeps them equal). */
export const DESK_DECIDED_DAYS = 7;

/**
 * How strongly a waiting time is shown. Display only: it changes no order, no priority and no rule. An order waiting more
 * than a working day is amber; more than three days, red. The server's priority order of the list is kept as it is.
 */
export const WAIT_LONG_MINUTES = 24 * 60;
export const WAIT_CRITICAL_MINUTES = 72 * 60;
export type WaitTone = "normal" | "long" | "critical";
export function waitTone(minutes: number): WaitTone {
  if (minutes >= WAIT_CRITICAL_MINUTES) return "critical";
  if (minutes >= WAIT_LONG_MINUTES) return "long";
  return "normal";
}

/** The rows of a list that match the operator's search: the order number or the customer's name (Arasya's own data). */
export function deskMatches(item: Pick<ConfirmareItem, "orderNumber" | "customerName">, query: string): boolean {
  const needle = query.trim().replace(/^#/, "").toLocaleLowerCase("ro");
  if (needle === "") return true;
  return item.orderNumber.includes(needle) || fold(item.customerName ?? "").includes(fold(needle));
}
const fold = (value: string) => value.normalize("NFD").replace(/[̀-ͯ]/g, "").toLocaleLowerCase("ro");

// ---- Product lines -------------------------------------------------------------------------------------------------

/**
 * A line meta the shop shows on the order but that is a technical value (a plugin's own key such as
 * `trendhome-tools_monopera_cost`): lower case, no space, joined by `_`. Hidden from the operator only; the shop keeps it.
 */
export function isTechnicalLabel(label: string): boolean {
  const value = label.trim();
  return /^_/.test(value) || (/^[a-z0-9]+(?:[-_][a-z0-9]+)+$/.test(value) && value.includes("_"));
}

/** The pieces field of WC Kalkulator (`buc`: "2 buc."): how many curtain panels are sewn, not how many were ordered. */
export const isPiecesLabel = (label: string): boolean => /^buc\.?$/i.test(label.trim());
/** The sewing (tailoring) field of the shop's calculator. */
export const isSewingLabel = (label: string): boolean => /^manop[eo]r/i.test(label.trim());

export type LineFieldKind = "measure" | "pieces" | "sewing" | "option";
/** What a calculator field is for the operator, from the shop's own definition (never from a formula Staff assumes). */
export function fieldKind(field: Pick<EditField, "title" | "type" | "options">): LineFieldKind {
  if (isPiecesLabel(field.title)) return "pieces";
  if (isSewingLabel(field.title)) return "sewing";
  if (field.type === "number" && (!field.options || field.options.length === 0)) return "measure";
  return "option";
}

/**
 * The unit of a numeric field, only when the shop states it for this line: the line's width or height (in its unit) has
 * the field's value. Otherwise null: Staff never assumes metres for every product.
 */
export function measureUnit(line: Pick<ShopLine, "width" | "height" | "unit"> | null, value: string): string | null {
  if (!line || line.unit === null) return null;
  const number = Number(value.replace(",", "."));
  if (!Number.isFinite(number)) return null;
  return [line.width, line.height].some((measure) => measure !== null && Math.abs(measure - number) < 1e-9) ? line.unit : null;
}

/**
 * The line's shop details split for the card: what the operator reads (colour, sewing, pieces; never a technical key) and,
 * when the line is edited in place, without the values its editable fields already show.
 */
export function visibleDetails(details: readonly ShopDetailEntry[], editableTitles: readonly string[] = []): ShopDetailEntry[] {
  const shown = new Set(editableTitles.map((title) => title.trim().toLocaleLowerCase("ro")));
  return details.filter((detail) => !isTechnicalLabel(detail.label) && !shown.has(detail.label.trim().toLocaleLowerCase("ro")));
}

// ---- Money ---------------------------------------------------------------------------------------------------------

const add = (...amounts: (string | null | undefined)[]): number | null => {
  let sum = 0;
  for (const amount of amounts) {
    const bani = toBani(amount ?? "0");
    if (bani === null) return null;
    sum += bani;
  }
  return sum;
};

/**
 * A line's price for the card. With prices entered with VAT (the shop's setting) the customer saw the line with VAT: the
 * shop's line total plus its line tax; otherwise the shop's net line total. The unit price is shown only when it divides
 * exactly (never a rounded figure that would not add up).
 */
export function linePrice(snapshot: Pick<ShopLine, "total" | "tax" | "quantity" | "unitPrice"> | Pick<EditLineSnapshot, "total" | "tax" | "quantity">, pricesIncludeTax: boolean): { total: string | null; unit: string | null; vat: "incl" | "excl" } {
  const total = pricesIncludeTax ? add(snapshot.total, snapshot.tax) : toBani(snapshot.total);
  if (total === null) return { total: null, unit: null, vat: pricesIncludeTax ? "incl" : "excl" };
  const quantity = snapshot.quantity;
  let unit: string | null = null;
  if (Number.isInteger(quantity) && quantity > 1) {
    if (!pricesIncludeTax && "unitPrice" in snapshot && toBani(snapshot.unitPrice) !== null && toBani(snapshot.total) === toBani(snapshot.unitPrice)! * quantity) unit = snapshot.unitPrice;
    else if (total % quantity === 0) unit = fromBani(total / quantity);
  }
  return { total: fromBani(total), unit, vat: pricesIncludeTax ? "incl" : "excl" };
}

export type BreakdownRow = { key: "products" | "discount" | "shipping" | "fees" | "vat" | "rounding"; amount: string; free?: boolean };
export type PriceBreakdown = {
  vat: "incl" | "excl";
  rows: BreakdownRow[];
  total: string;
  /** The VAT inside the total (shown as "of which VAT" when prices carry VAT). */
  tax: string;
  /** Whether the rows add up to the shop's total (a difference of a few bani is the shop's own rounding, shown as such). */
  consistent: boolean;
};

type Figures = { productsNet: string; productsTax: string | null; discount: string; discountTax: string; shipping: string; shippingTax: string; fees: string; feesTax: string | null; tax: string; total: string; hasShipping: boolean };

/** At most this difference (bani) is shown as the shop's rounding; anything larger hides the sum rather than mislead. */
export const ROUNDING_TOLERANCE_BANI = 5;

function breakdownOf(f: Figures, pricesIncludeTax: boolean): PriceBreakdown | null {
  const total = toBani(f.total);
  const tax = toBani(f.tax);
  if (total === null || tax === null) return null;
  const incl = pricesIncludeTax && f.productsTax !== null;
  const products = incl ? add(f.productsNet, f.productsTax) : toBani(f.productsNet);
  const discount = incl ? add(f.discount, f.discountTax) : toBani(f.discount);
  const shipping = incl ? add(f.shipping, f.shippingTax) : toBani(f.shipping);
  const fees = incl && f.feesTax !== null ? add(f.fees, f.feesTax) : toBani(f.fees);
  if (products === null || discount === null || shipping === null || fees === null) return null;
  const rows: BreakdownRow[] = [{ key: "products", amount: fromBani(products) }];
  if (discount !== 0) rows.push({ key: "discount", amount: fromBani(-Math.abs(discount)) });
  if (f.hasShipping) rows.push({ key: "shipping", amount: fromBani(shipping), free: shipping === 0 });
  if (fees !== 0) rows.push({ key: "fees", amount: fromBani(fees) });
  if (!incl) rows.push({ key: "vat", amount: fromBani(tax) });
  const sum = products - Math.abs(discount) + shipping + fees + (incl ? 0 : tax);
  const difference = total - sum;
  const consistent = Math.abs(difference) <= ROUNDING_TOLERANCE_BANI;
  if (consistent && difference !== 0) rows.push({ key: "rounding", amount: fromBani(difference) });
  return { vat: incl ? "incl" : "excl", rows, total: fromBani(total), tax: fromBani(tax), consistent };
}

/** How the saved order's total is made up, from the shop's own order figures. Null when the shop sent no plain amounts. */
export function orderBreakdown(detail: ShopOrderDetail): PriceBreakdown | null {
  const lineTax = add(...detail.lines.map((line) => line.tax));
  const feesTax = add(...detail.fees.map((fee) => fee.tax));
  const discountTax = toBani(detail.totals.discountTax);
  return breakdownOf({
    productsNet: detail.totals.subtotal,
    // The products' VAT before the discount: the lines' VAT (after it) plus the discount's VAT.
    productsTax: lineTax === null || discountTax === null ? null : fromBani(lineTax + discountTax),
    discount: detail.totals.discount, discountTax: detail.totals.discountTax,
    shipping: detail.totals.shipping, shippingTax: detail.totals.shippingTax,
    fees: detail.totals.fees, feesTax: feesTax === null ? null : fromBani(feesTax),
    tax: detail.totals.tax, total: detail.totals.total, hasShipping: detail.shipping.length > 0,
  }, detail.order.pricesIncludeTax);
}

/** The same for an unsaved change, from the shop's preview totals. */
export function previewBreakdown(after: EditTotals, pricesIncludeTax: boolean, hasShipping: boolean): PriceBreakdown | null {
  return breakdownOf({
    productsNet: after.subtotal, productsTax: after.subtotalTax, discount: after.discount, discountTax: after.discountTax,
    shipping: after.shipping, shippingTax: after.shippingTax, fees: after.fees, feesTax: toBani(after.fees) === 0 ? "0.00" : null,
    tax: after.tax, total: after.total, hasShipping,
  }, pricesIncludeTax);
}

// ---- Compact navigation --------------------------------------------------------------------------------------------

const NAV_KEY = "arasya.ows.navWide";
/** Whether the operator chose the wide menu on the order workspace (this browser; the compact menu is the default). */
export function readWideNav(): boolean {
  try { return typeof window !== "undefined" && window.localStorage.getItem(NAV_KEY) === "1"; } catch { return false; }
}
export function saveWideNav(wide: boolean): void {
  try { window.localStorage.setItem(NAV_KEY, wide ? "1" : "0"); } catch { /* memory only */ }
}
