import { t, type MessageKey } from "../i18n";
import type { OpenEdit } from "./confirmareEdit";

/**
 * The shop's own view of one Confirmare order (API 2.40.0, Phase 4A): read from WooCommerce through the Operations API
 * at the moment the operator asks, never stored by Staff (component memory only: no URL, no storage, no cache) and
 * hidden again automatically. Amounts are the shop's decimal strings, shown as they are; Staff computes no price.
 */
export type SourceAccessCapabilities = {
  configured: boolean; read: boolean; edit: boolean;
  /** Phase 4B: a shop change is not settled yet (approval and other changes wait); the open changes the actor may check. */
  changePending: boolean; openEdits: OpenEdit[];
};

export type ShopPerson = { name: string; company: string; phone: string; addressLines: string[] };
export type ShopDetailEntry = { label: string; value: string };
/** API 2.51.0 (YD SOFT 2.24.0): the catalog picture of a line, the variation's own or else the product's; live, display only. */
export type ShopLineImage = { url: string; width: number; height: number; kind: "variation" | "product" };
export type ShopLine = {
  id: string; line: number; productId: string; variationId: string; name: string; sku: string;
  width: number | null; height: number | null; unit: "m" | null; details: ShopDetailEntry[];
  quantity: number; unitPrice: string; subtotal: string; discount: string; tax: string; total: string; calculator: boolean;
  /** Null: no picture, a deleted product, or an older shop. */
  image: ShopLineImage | null;
};
export type ShopTotals = { subtotal: string; discount: string; discountTax: string; shipping: string; shippingTax: string; fees: string; tax: string; total: string; refunded: string };
export type ShopOrderDetail = {
  readAt: string;
  order: { id: string; number: string; status: { code: string; label: string }; createdAt: string | null; modifiedAt: string | null; storage: "hpos" | "posts"; currency: string; pricesIncludeTax: boolean };
  version: string;
  pricingFingerprint: string | null;
  contact: { phoneAvailable: boolean; billing: ShopPerson; shipping: ShopPerson };
  lines: ShopLine[];
  fees: { name: string; total: string; tax: string }[];
  coupons: { code: string; discount: string; discountTax: string }[];
  shipping: { id: string; methodId: string; instanceId: string; title: string; total: string; tax: string }[];
  totals: ShopTotals;
  payment: {
    method: string; methodTitle: string; cod: boolean; paidRecorded: boolean;
    financial: { state: "cleared" | "waiting" | "partial" | "unknown"; basis: string | null };
    advance: { percentage: number; required: string | null; collected: string | null } | null;
  };
};
export type ShopFreshness = { readAt: string; shopModifiedAt: string | null; arasyaChangedAt: string | null; pricingMatches: boolean | null; inSync: boolean };
export type ShopRead =
  | { available: true; role: "assignee" | "supervisor"; detail: ShopOrderDetail; freshness: ShopFreshness }
  | { available: false; reason: string };

/** Shop data is hidden again after this long without a new read (it is customer data on a shared screen). */
export const SHOP_DATA_TTL_MS = 10 * 60 * 1000;

const SHOP_UNAVAILABLE = ["not_configured", "source_unreachable", "source_response_untrusted", "source_response_invalid", "source_response_too_large", "source_access_denied", "source_rate_limited", "source_disabled", "source_order_not_found", "source_order_not_eligible", "source_address_refused", "source_access_not_configured", "source_error"] as const;

/** Why the shop could not be read, in the operator's words (an unknown reason reads as a generic failure). */
export function shopUnavailableLabel(reason: string): string {
  return t(`cf.shop.reason.${(SHOP_UNAVAILABLE as readonly string[]).includes(reason) ? reason : "source_error"}` as MessageKey);
}

const money = new Map<string, Intl.NumberFormat>();
/** A shop amount (decimal string) in its currency; the value is the shop's, only its presentation is local. */
export function formatShopMoney(amount: string | null, currency: string): string {
  if (amount === null) return "—";
  const value = Number(amount);
  if (!Number.isFinite(value)) return "—";
  let formatter = money.get(currency);
  if (!formatter) {
    try { formatter = new Intl.NumberFormat("ro-RO", { style: "currency", currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    catch { formatter = new Intl.NumberFormat("ro-RO", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    money.set(currency, formatter);
  }
  return formatter.format(value);
}

/** A telephone number as a `tel:` target: digits and a leading plus only (a malformed number gets no link). */
export function telHref(phone: string): string | null {
  const digits = phone.replace(/[^0-9+]/g, "");
  return /^\+?[0-9]{9,15}$/.test(digits) ? `tel:${digits}` : null;
}

/** The distinct callable numbers of the order (billing first), never an empty one. */
export function shopPhones(detail: ShopOrderDetail): { phone: string; kind: "billing" | "shipping"; href: string | null }[] {
  const seen = new Set<string>();
  const phones: { phone: string; kind: "billing" | "shipping"; href: string | null }[] = [];
  for (const [kind, person] of [["billing", detail.contact.billing], ["shipping", detail.contact.shipping]] as const) {
    const key = person.phone.replace(/[^0-9]/g, "");
    if (person.phone.trim() === "" || seen.has(key)) continue;
    seen.add(key);
    phones.push({ phone: person.phone, kind, href: telHref(person.phone) });
  }
  return phones;
}

/** Whether the shop view may be used to confirm prices: read from the shop and showing the prices Arasya holds. */
export function shopConfirmsPrices(read: ShopRead | null): boolean {
  return read !== null && read.available && read.freshness.pricingMatches === true;
}
