import type { ConfirmareItem, DeskView } from "./confirmare";
import type { EditOperation, EditProduct } from "./confirmareEdit";
import type { AddressField, AddressFields, ShopOrderDetail } from "./confirmareShop";

/**
 * Operation Simplification V1 (Staff 2.57.0, API 2.82.0, owner decision 2026-11-26): the operator desk and the full-page
 * order editor. Everything here is presentation and draft bookkeeping. Prices, VAT, shipping and totals are always the
 * shop's (WooCommerce, WC Kalkulator, YD SOFT); the only arithmetic is the money position of the sticky header, done in
 * bani (integers) with the shop's own advance rule (YD SOFT MoneyPosition::compute), so the screen never shows a float.
 */

// ---- Money (bani) --------------------------------------------------------------------------------------------------

/** A decimal amount string of the shop ("1160.26", "-3.5") as bani; null when it is not a plain decimal. */
export function toBani(amount: string | null | undefined): number | null {
  if (amount == null) return null;
  const match = /^(-)?(\d{1,12})(?:\.(\d{1,2}))?$/.exec(amount.trim());
  if (!match) return null;
  const bani = Number(match[2]) * 100 + Number((match[3] ?? "").padEnd(2, "0"));
  return match[1] ? -bani : bani;
}

/** Whether two decimal amounts of the shop are the same amount ("719" and "719.00"); false when either is not one. */
export function sameAmount(left: string | null | undefined, right: string | null | undefined): boolean {
  const a = toBani(left);
  const b = toBani(right);
  return a !== null && b !== null && a === b;
}

/** Bani as the shop's decimal string ("1160.26"). */
export function fromBani(bani: number): string {
  const sign = bani < 0 ? "-" : "";
  const value = Math.abs(Math.trunc(bani));
  return `${sign}${Math.floor(value / 100)}.${String(value % 100).padStart(2, "0")}`;
}

/**
 * The advance asked for a total (YD SOFT MoneyPosition::compute): ROUND(T x p / 100, 2) half away from zero, on integers.
 * Used only to show the advance of a total the operator is previewing; the shop states the real one after the save.
 */
export function advanceFor(totalBani: number, percentage: number): number {
  const basis = Math.round(percentage * 100);
  return Math.floor((Math.max(0, totalBani) * basis + 5000) / 10000);
}

export type MoneyHeader = {
  currency: string;
  total: string;
  /** The advance asked (the shop's figure for the saved order, an estimate by the shop's rule for a preview). */
  advance: string | null;
  advanceEstimated: boolean;
  /** Verified money received (never a promise, a proforma or a click). */
  received: string;
  /** What the courier collects now: the total minus the verified money. */
  rambursNow: string;
  /** What the courier would collect once the advance asked is paid; null when no advance is asked or it is covered. */
  rambursAfterAdvance: string | null;
  cod: boolean;
};

/**
 * The sticky header's money: the order total, the advance asked, the verified money and the two ramburs figures, which are
 * never mixed up. Without verified money the real ramburs is the whole total; the "after the advance" figure holds only
 * once the advance asked is really paid. `previewTotal`: the total of an unsaved change (the shop computed it).
 */
export function moneyHeader(detail: ShopOrderDetail, previewTotal: string | null = null): MoneyHeader | null {
  const total = toBani(previewTotal ?? detail.totals.total);
  if (total === null) return null;
  const advance = detail.payment.advance;
  const received = Math.max(0, toBani(advance?.collected ?? null) ?? 0);
  const cod = detail.payment.cod;
  let asked: number | null = null;
  let estimated = false;
  if (cod && advance) {
    const shopAsked = toBani(advance.required);
    if (previewTotal === null && shopAsked !== null) asked = shopAsked;
    else { asked = advanceFor(total, advance.percentage); estimated = true; }
  }
  const rambursNow = Math.max(0, total - received);
  const after = asked !== null && received < asked ? Math.max(0, total - asked) : null;
  return {
    currency: detail.order.currency, total: fromBani(total), advance: asked === null ? null : fromBani(asked), advanceEstimated: estimated,
    received: fromBani(received), rambursNow: fromBani(cod ? rambursNow : 0), rambursAfterAdvance: after === null ? null : fromBani(after), cod,
  };
}

// ---- Desk list -----------------------------------------------------------------------------------------------------

/** How long an order has waited, in whole minutes (from its submission, else from its arrival in Confirmare). */
export function waitingMinutes(item: ConfirmareItem, now: number = Date.now()): number {
  const since = Date.parse(item.submittedAt ?? item.openedAt);
  return Number.isFinite(since) ? Math.max(0, Math.floor((now - since) / 60000)) : 0;
}

/** A waiting time as the desk shows it: "12 min", "3 h", "2 z". */
export function waitingLabel(minutes: number): { value: number; unit: "min" | "h" | "d" } {
  if (minutes < 60) return { value: minutes, unit: "min" };
  if (minutes < 48 * 60) return { value: Math.floor(minutes / 60), unit: "h" };
  return { value: Math.floor(minutes / 1440), unit: "d" };
}

/** Whether a waiting order's optional callback time has come (it never locks anything; it only sorts and marks). */
export function callbackDue(item: ConfirmareItem, now: number = Date.now()): boolean {
  const at = item.waiting?.callbackAt ? Date.parse(item.waiting.callbackAt) : NaN;
  return Number.isFinite(at) && at <= now;
}

export const DESK_VIEW_ORDER: readonly DeskView[] = ["new", "waiting", "confirmed", "refused"];

/** The next order to open after a decision: the next row of the same list (the server's priority order), else null. */
export function nextAfter(items: readonly ConfirmareItem[], decidedId: string): ConfirmareItem | null {
  const index = items.findIndex((item) => item.globalOrderId === decidedId);
  const rest = index < 0 ? items : [...items.slice(index + 1), ...items.slice(0, index)];
  return rest.find((item) => item.globalOrderId !== decidedId && item.status === "pending") ?? null;
}

// ---- Address -------------------------------------------------------------------------------------------------------

/**
 * WooCommerce's Romanian county codes (WC()->countries->get_states('RO')), the values the shop stores in `state`.
 * Bucharest is "B"; its sector is the city ("Sector 1".."Sector 6"), as at the checkout and for FAN Courier.
 */
export { RO_COUNTIES } from "../i18n/roCounties";
export const BUCHAREST_SECTORS = ["Sector 1", "Sector 2", "Sector 3", "Sector 4", "Sector 5", "Sector 6"] as const;

/** The changed fields only (trimmed, spaces collapsed); an empty object when nothing changed. */
export function addressChanges(current: AddressFields, edited: AddressFields): Partial<Record<AddressField, string>> {
  const changes: Partial<Record<AddressField, string>> = {};
  for (const key of Object.keys(edited) as AddressField[]) {
    const value = edited[key].replace(/\s+/g, " ").trim();
    if (value !== (current[key] ?? "").trim()) changes[key] = value;
  }
  return changes;
}

/** What the desk flags before the operator calls (never a block): a missing street, city, county or postcode. */
export function addressWarnings(fields: AddressFields | null | undefined): ("street" | "city" | "county" | "postcode")[] {
  if (!fields) return [];
  const warnings: ("street" | "city" | "county" | "postcode")[] = [];
  if (fields.address1.trim() === "") warnings.push("street");
  if (fields.city.trim() === "") warnings.push("city");
  if (fields.country === "RO" && fields.state.trim() === "") warnings.push("county");
  if (fields.country === "RO" && !/^\d{6}$/.test(fields.postcode.trim())) warnings.push("postcode");
  return warnings;
}

// ---- Product replacement -------------------------------------------------------------------------------------------

/**
 * Replacing a line by another product of the same shop (SKU): the old line is removed and the new product added with the
 * values that fit it. Only fields the new product's calculator really has, with an allowed value, are carried over; a
 * quantity is kept. Anything else is left for the operator (never guessed). The shop prices the result.
 */
export function replacementFields(oldFields: Record<string, string>, product: EditProduct): { fields: Record<string, string>; carried: string[]; dropped: string[] } {
  const calculator = product.calculator && "fields" in product.calculator ? product.calculator.fields : [];
  const fields: Record<string, string> = {};
  const carried: string[] = [];
  const dropped: string[] = [];
  for (const [name, value] of Object.entries(oldFields)) {
    const field = calculator.find((candidate) => candidate.name === name && candidate.editable);
    const fits = field !== undefined && (field.options === null || field.options.some((option) => option.value === value))
      && (field.type !== "number" || (Number.isFinite(Number(value)) && (field.min === null || Number(value) >= field.min) && (field.max === null || Number(value) <= field.max)));
    if (fits) { fields[name] = value; carried.push(name); } else dropped.push(name);
  }
  return { fields, carried, dropped };
}

/** The operations of a replacement: the old line removed, the new product added (one edit, priced by the shop). */
export function replacementOperations(lineId: string, product: EditProduct, variationId: string | null, quantity: number, fields: Record<string, string>): EditOperation[] {
  const add: Extract<EditOperation, { op: "add_line" }> = { op: "add_line", productId: product.id, quantity };
  if (variationId) add.variationId = variationId;
  if (Object.keys(fields).length > 0) add.fields = fields;
  return [{ op: "remove_line", lineId }, add];
}

// ---- Draft safety ----------------------------------------------------------------------------------------------------

/**
 * The operator's unsaved changes survive a reload, a lost connection or a yellow decision: kept in this browser only, per
 * employee and order, together with the shop version they were made on. A draft made on another version of the order is
 * never applied silently: the screen offers it, and the shop's preview decides again.
 */
export type StoredDraft<T> = { version: string; savedAt: string; draft: T };
const DRAFT_PREFIX = "arasya.cf.draft.";
const DRAFT_TTL_MS = 3 * 24 * 60 * 60 * 1000;

export function draftKey(employeeUuid: string, globalOrderId: string): string {
  return `${DRAFT_PREFIX}${employeeUuid}.${globalOrderId}`;
}

export function saveDraft<T>(storage: Pick<Storage, "setItem" | "removeItem"> | null, key: string, version: string, draft: T | null): void {
  if (!storage) return;
  try {
    if (draft === null) storage.removeItem(key);
    else storage.setItem(key, JSON.stringify({ version, savedAt: new Date().toISOString(), draft } satisfies StoredDraft<T>));
  } catch { /* storage full or blocked: the draft lives in memory only */ }
}

export function loadDraft<T>(storage: Pick<Storage, "getItem" | "removeItem"> | null, key: string, now: number = Date.now()): StoredDraft<T> | null {
  if (!storage) return null;
  try {
    const raw = storage.getItem(key);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<StoredDraft<T>>;
    if (typeof parsed.version !== "string" || typeof parsed.savedAt !== "string" || parsed.draft === undefined || now - Date.parse(parsed.savedAt) > DRAFT_TTL_MS) {
      storage.removeItem(key);
      return null;
    }
    return parsed as StoredDraft<T>;
  } catch {
    return null;
  }
}

export function browserStorage(): Storage | null {
  try { return typeof window === "undefined" ? null : window.localStorage; } catch { return null; }
}

// ---- The detailed screen (transition) -------------------------------------------------------------------------------

const DETAILED_KEY = "arasya.cf.detailed";
/**
 * Whether this tab shows the earlier detailed Confirmare screen (every panel: proforma, invoice, cycle, finance card) instead
 * of the desk. Opened with `?detailed=1` (the supervisors' link in the order editor) and closed with `?detailed=0`; kept for
 * the tab only (sessionStorage). Nothing is decided by it: every command checks everything on the server again.
 */
export function detailedDesk(search: string = typeof window === "undefined" ? "" : window.location.search): boolean {
  const param = new URLSearchParams(search).get("detailed");
  try {
    const storage = typeof window === "undefined" ? null : window.sessionStorage;
    if (param === "1") storage?.setItem(DETAILED_KEY, "1");
    if (param === "0") storage?.removeItem(DETAILED_KEY);
    return param === "1" || (param !== "0" && storage?.getItem(DETAILED_KEY) === "1");
  } catch {
    return param === "1";
  }
}
