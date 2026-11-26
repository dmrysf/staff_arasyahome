import type { Employee } from "./models";
import { StaffServiceError } from "./models";
import { translate } from "../i18n/translator";
import { CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE } from "./canonicalProductionWorkflow";
import { mapDeliveryView, type DeliveryView } from "./confirmareOps";
import { blockersOf, type InvoiceRead } from "./confirmareInvoice";

/**
 * Store Workspace V1 (Staff 2.59.0, API 2.83.0, YD SOFT 2.36.0; owner brief 2026-11-26): the shop employee's one place for
 * the production documents, the FAN Courier AWBs and the order search of their own shop (Trend Home, Outlet Perdele).
 *
 * PRESENTATION ONLY. Every rule is the server's: the shop computes the cash on delivery and the AWB blockers, Arasya checks
 * the scopes, the stage and the routing, and every command goes through its existing route with an idempotency key. What
 * is here reads the answers strictly (anything unexpected is refused) and derives labels, tones and counts from them.
 */

export const STORE_SOURCES = ["trendhome", "outletperdele"] as const;
export type StoreSource = (typeof STORE_SOURCES)[number];
/** FAN Courier's limit of parcels on one AWB (YD SOFT Operations::MAX_PARCELS). */
export const MAX_PARCELS = 20;
export const MAX_WEIGHT_KG = 500;
export const PAGE_SIZE = 25;

/** Why a reprint is needed (stored as the Romanian text in the print record, so every reader sees the same words). */
export const REPRINT_REASONS = ["printer_error", "damaged", "lost", "other"] as const;
export type ReprintReason = (typeof REPRINT_REASONS)[number];
// The Romanian catalog is the one source of these words (the print record keeps them in Romanian for every reader).
const reprintText = (reason: ReprintReason): string => translate("ro", `sw.docs.reason.${reason}`);

/** The Romanian reprint reason sent with the print (other: with the employee's short note). Null when not valid yet. */
export function reprintReasonText(reason: ReprintReason | null, note = ""): string | null {
  if (reason === null) return null;
  const clean = note.replace(/\s+/g, " ").trim();
  if (reason === "other") return clean.length >= 3 ? `${reprintText("other")}: ${clean.slice(0, 200)}` : null;
  return reprintText(reason);
}

/** Why an AWB is corrected (API ConfirmareOperations::CANCEL_REASONS). */
export const CANCEL_REASONS = ["wrong_parcels", "wrong_weight", "wrong_address", "wrong_contact", "other"] as const;
export type CancelReason = (typeof CANCEL_REASONS)[number];

export const HISTORY_RANGES = ["today", "yesterday", "7d", "30d", "custom"] as const;
export type HistoryRange = (typeof HISTORY_RANGES)[number];

// ---- Access (presentation only: the server checks every call) ----------------------------------------------------------

const working = (employee: Employee) => employee.status === "active" && !employee.mustChangePassword && employee.applications.includes("staff") && !employee.isRoot;

/** The shops whose AWBs the employee makes (invoice issue or manage scope). */
export function storeAwbSources(employee: Employee): StoreSource[] {
  const scopes = employee.invoiceScopes;
  return STORE_SOURCES.filter((source) => (scopes?.issue ?? []).includes(source) || (scopes?.manage ?? []).includes(source));
}

/** Whether the employee works in the Store Workspace: an active shop employee with an invoice scope of a shop. */
export function canUseStoreWorkspace(employee: Employee): boolean {
  return working(employee) && storeAwbSources(employee).length > 0;
}

/**
 * The Store Workspace is the home of a shop employee who is not a Confirmare operator (an operator keeps the operator desk).
 * Staff 2.60.0 (Store Workspace V1.1): the earlier `?clasic=1` home is gone; the shop employee always works here.
 */
export function opensStoreWorkspace(employee: Employee, operatorDesk: boolean): boolean {
  if (operatorDesk) return false;
  const confirmare = (employee.confirmareScopes?.operate.length ?? 0) + (employee.confirmareScopes?.supervise.length ?? 0) > 0;
  return canUseStoreWorkspace(employee) && !confirmare;
}

/**
 * Staff 2.60.0: where an earlier screen's address takes a Store Workspace employee instead (a direct URL, an old link, the
 * browser's back button, a live notice or a QR scan that opens an order). The order always opens in the store's own order
 * screen, the production handoff list in "Documente de producție", the old AWB list in "AWB și etichete". Null: the address
 * is not an earlier screen (it stays as it is). Only for the Store Workspace employee: every other employee keeps the
 * earlier screens.
 */
export function storeLegacyRedirect(path: string): string | null {
  const pathname = path.split(/[?#]/, 1)[0];
  const order = /^\/(?:documents|orders)\/([^/]+)\/?$/.exec(pathname);
  if (order) {
    let id: string;
    try { id = decodeURIComponent(order[1]); } catch { return "/magazin"; }
    return id.trim() === "" ? "/magazin" : `/magazin/comenzi/${encodeURIComponent(id)}`;
  }
  if (pathname === "/orders" || pathname === "/orders/") return "/magazin/istoric";
  if (pathname === "/confirmare/handoff") return "/magazin/documente";
  if (pathname === "/confirmare/awb") return "/magazin/awb";
  return null;
}

// ---- Server shapes ------------------------------------------------------------------------------------------------------

export interface StoreSummary {
  day: string; zone: string; sources: string[]; can: { documents: boolean; awb: boolean };
  documents: { waiting: number; revision: number; printedToday: number; reprintsToday: number };
  awb: { waiting: number; needsFix: number; createdToday: number; cancelledToday: number; recreatedToday: number; parcelsToday: number; ordersToday: number; tracked: boolean };
}
export interface StoreShopRow {
  available: boolean; name: string; city: string; county: string; cod: string | null;
  awb: { state: "none" | "created"; number: string | null; status: string };
  blockers: string[]; address: { state: "ok" | "warning" | "attention"; issues: string[]; warnings: string[] };
}
export interface StorePage<T> { items: T[]; page: number; pageSize: number; total: number }
export interface StoreDocumentTodo {
  globalOrderId: string; orderNumber: string; source: string; dueDate: string | null; submittedAt: string | null;
  step: "ready" | "print" | "revision"; documentStatus: string; stage: string | null; shop: StoreShopRow | null;
}
export interface StorePrint {
  globalOrderId: string; orderNumber: string; source: string; kind: "print" | "reprint"; printNumber: number; revision: number; reason: string | null; at: string; by: string; documentStatus: string; stage: string;
}
/** An AWB operation still waiting for the shop's answer (API 2.83.0): shown again after a reload, settled, never resent. */
export interface StoreOpenOperation { kind: "awb_create" | "awb_cancel" | "address_update"; state: "pending" | "unknown"; at: string; idempotencyKey: string }
export interface StoreAwbItem { globalOrderId: string; orderNumber: string; source: string; stage: string; lastAttempt: string | null; shop: StoreShopRow | null; open: StoreOpenOperation | null }
export interface StoreAwbHistoryItem {
  globalOrderId: string; orderNumber: string; source: string; awb: string | null; awbFromShop: boolean; parcels: number | null; at: string; by: string; state: "active" | "cancelled";
  /** The order's current AWB (no later AWB, not cancelled): the one "Corectează AWB" acts on. */
  current: boolean; open: StoreOpenOperation | null;
  cancelledAt: string | null; cancelReason: string | null; replacedBy: string | null; replaces: string | null;
}
/** The AWB figures of a period. Parcels of AWBs made before API 2.83.0 are unknown (`parcelsUnknown`), never 0. */
export interface StoreAwbStats { orders: number; awbs: number; parcels: number; parcelsUnknown: number; cancelled: number; renewed: number }
export type StoreAwbHistoryPage = StorePage<StoreAwbHistoryItem> & { tracked: boolean; stats: StoreAwbStats | null };
export interface StoreAddressCheck { issues: string[]; warnings: string[]; awbActive: boolean }
export interface StoreOrderRow { globalOrderId: string; orderNumber: string; source: string; stage: string; stageNumber: number | null; documentStatus: string; awbDone: boolean; submittedAt: string | null }
export interface StoreProduct { line: number; name: string; code: string | null; variant: string | null; color: string | null; width: string | null; height: string | null; unit: string | null; meters: string | null; quantity: number }
export interface StoreAwbOperation { kind: string; state: string; outcome: string | null; at: string; awb: string | null; parcels: number | null; reason: string | null; by: string }
export interface StoreOrder {
  globalOrderId: string; orderNumber: string; source: string; submittedAt: string | null; dueDate: string | null;
  stage: { id: string; number: number | null; total: number; completed: boolean }; cancelled: boolean;
  document: { status: string; version: number; revision: number | null; prints: number; lastPrint: string | null };
  invoiceIssued: boolean; products: StoreProduct[]; awbOperations: StoreAwbOperation[];
  shop: { status: string | null; lines: { name: string; sku: string; quantity: number; image: string | null }[]; money: { currency: string; known: boolean; total: string | null; received: string | null; remaining: string | null }; payment: string | null; delivery: DeliveryView | null } | null;
  canAwb: boolean; canDocuments: boolean;
}
export interface StoreMineItem { kind: string; detail: string; at: string; globalOrderId: string; orderNumber: string; source: string; awb: string | null; parcels: number | null; reason: string | null }

export interface StoreApi {
  summary(signal?: AbortSignal): Promise<StoreSummary>;
  documents(tab: "todo", page: number, signal?: AbortSignal): Promise<StorePage<StoreDocumentTodo>>;
  printed(tab: "today" | "history", page: number, range: { from: string; to: string } | null, signal?: AbortSignal): Promise<StorePage<StorePrint>>;
  awb(page: number, filter: "all" | "problem", signal?: AbortSignal): Promise<StorePage<StoreAwbItem>>;
  awbHistory(range: HistoryRange, page: number, custom: { from: string; to: string } | null, signal?: AbortSignal): Promise<StoreAwbHistoryPage>;
  /** READ ONLY: the shop checks a delivery address the employee prepares (also while the AWB still exists). Nothing is saved. */
  addressCheck(globalOrderId: string, address: AddressForm): Promise<StoreAddressCheck>;
  search(query: string, signal?: AbortSignal): Promise<{ items: StoreOrderRow[]; shopAsked: boolean }>;
  order(globalOrderId: string, signal?: AbortSignal): Promise<StoreOrder>;
  mine(page: number, signal?: AbortSignal): Promise<StorePage<StoreMineItem>>;
}

// ---- Strict readers -----------------------------------------------------------------------------------------------------

const fail = (): never => { throw new StaffServiceError("SERVER_ERROR"); };
const obj = (value: unknown): Record<string, unknown> => (value !== null && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : fail());
const str = (value: unknown, max = 200): string => (typeof value === "string" && value.length <= max ? value : fail());
const optStr = (value: unknown, max = 200): string | null => (value == null ? null : str(value, max));
const code = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^[a-z_]{2,40}$/.test(value) ? value : fail()));
const int = (value: unknown): number => (typeof value === "number" && Number.isInteger(value) && value >= 0 ? value : fail());
const optInt = (value: unknown): number | null => (value == null ? null : int(value));
const bool = (value: unknown): boolean => (typeof value === "boolean" ? value : fail());
const instant = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/.test(value) ? value : fail()));
const money = (value: unknown): string | null => (value == null ? null : (typeof value === "string" && /^-?(0|[1-9][0-9]{0,8})\.[0-9]{2}$/.test(value) ? value : fail()));
const oneOf = <T extends string>(value: unknown, allowed: readonly T[]): T => (typeof value === "string" && (allowed as readonly string[]).includes(value) ? value as T : fail());
const list = <T>(value: unknown, max: number, map: (entry: unknown) => T): T[] => (Array.isArray(value) && value.length <= max ? value.map(map) : fail());
const codes = (value: unknown): string[] => list(value, 40, (entry) => code(entry) ?? fail());
const page = <T>(raw: Record<string, unknown>, map: (entry: unknown) => T): StorePage<T> => ({ items: list(raw.items, 200, map), page: int(raw.page), pageSize: int(raw.pageSize), total: int(raw.total) });

export function mapShopRow(value: unknown): StoreShopRow | null {
  if (value == null) return null;
  const raw = obj(value);
  if (raw.available !== true) return { available: false, name: "", city: "", county: "", cod: null, awb: { state: "none", number: null, status: "" }, blockers: [], address: { state: "attention", issues: [], warnings: [] } };
  const awb = obj(raw.awb);
  const address = obj(raw.address);
  return {
    available: true, name: str(raw.name, 120), city: str(raw.city, 120), county: str(raw.county, 10), cod: money(raw.cod),
    awb: { state: oneOf(awb.state, ["none", "created"] as const), number: optStr(awb.number, 20), status: str(awb.status ?? "", 120) },
    blockers: codes(raw.blockers), address: { state: oneOf(address.state, ["ok", "warning", "attention"] as const), issues: codes(address.issues), warnings: codes(address.warnings) },
  };
}

export function mapStoreSummary(value: unknown): StoreSummary {
  const raw = obj(value);
  const can = obj(raw.can);
  const d = obj(raw.documents);
  const a = obj(raw.awb);
  return {
    day: str(raw.day, 10), zone: str(raw.zone, 40), sources: list(raw.sources, 2, (s) => str(s, 40)), can: { documents: bool(can.documents), awb: bool(can.awb) },
    documents: { waiting: int(d.waiting), revision: int(d.revision), printedToday: int(d.printedToday), reprintsToday: int(d.reprintsToday) },
    awb: { waiting: int(a.waiting), needsFix: int(a.needsFix), createdToday: int(a.createdToday), cancelledToday: int(a.cancelledToday), recreatedToday: int(a.recreatedToday),
      parcelsToday: int(a.parcelsToday), ordersToday: int(a.ordersToday), tracked: bool(a.tracked) },
  };
}

export function mapDocumentTodoPage(value: unknown): StorePage<StoreDocumentTodo> {
  return page(obj(value), (entry) => {
    const r = obj(entry);
    return { globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.sourceKey, 40), dueDate: optStr(r.dueDate, 10), submittedAt: instant(r.submittedAt),
      step: oneOf(r.step, ["ready", "print", "revision"] as const), documentStatus: str(r.documentStatus, 20), stage: optStr(r.stage, 60), shop: mapShopRow(r.shop) };
  });
}

export function mapPrintPage(value: unknown): StorePage<StorePrint> {
  return page(obj(value), (entry) => {
    const r = obj(entry);
    return { globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40), kind: oneOf(r.kind, ["print", "reprint"] as const), printNumber: int(r.printNumber),
      revision: int(r.revision), reason: optStr(r.reason, 600), at: instant(r.at) ?? fail(), by: str(r.by), documentStatus: str(r.documentStatus, 20), stage: str(r.stage, 60) };
  });
}

export function mapAwbPage(value: unknown): StorePage<StoreAwbItem> {
  return page(obj(value), (entry) => {
    const r = obj(entry);
    return { globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40), stage: str(r.stage, 60), lastAttempt: code(r.lastAttempt), shop: mapShopRow(r.shop), open: mapOpen(r.open) };
  });
}

export function mapOpen(value: unknown): StoreOpenOperation | null {
  if (value == null) return null;
  const r = obj(value);
  const key = str(r.idempotencyKey, 100);
  if (!/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/.test(key)) fail();
  return { kind: oneOf(r.kind, ["awb_create", "awb_cancel", "address_update"] as const), state: oneOf(r.state, ["pending", "unknown"] as const), at: instant(r.at) ?? fail(), idempotencyKey: key };
}

export function mapAddressCheck(value: unknown): StoreAddressCheck {
  const r = obj(value);
  const codes = (list: unknown): string[] => (Array.isArray(list) ? list.map((c) => code(c) ?? fail()) : fail());
  return { issues: codes(r.issues), warnings: codes(r.warnings), awbActive: bool(r.awbActive) };
}

export function mapAwbHistoryPage(value: unknown): StoreAwbHistoryPage {
  const raw = obj(value);
  const stats = raw.stats == null ? null : obj(raw.stats);
  return { ...page(raw, (entry) => {
    const r = obj(entry);
    return { globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40), awb: optStr(r.awb, 20), awbFromShop: r.awbFromShop === true, parcels: optInt(r.parcels), at: instant(r.at) ?? fail(),
      by: str(r.by), state: oneOf(r.state, ["active", "cancelled"] as const), current: r.current === true, open: mapOpen(r.open),
      cancelledAt: instant(r.cancelledAt), cancelReason: code(r.cancelReason), replacedBy: optStr(r.replacedBy, 20), replaces: optStr(r.replaces, 20) };
  }), tracked: bool(raw.tracked),
  stats: stats === null ? null : { orders: int(stats.orders), awbs: int(stats.awbs), parcels: int(stats.parcels), parcelsUnknown: int(stats.parcelsUnknown), cancelled: int(stats.cancelled), renewed: int(stats.renewed) } };
}

export function mapOrderRow(value: unknown): StoreOrderRow {
  const r = obj(value);
  return { globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40), stage: str(r.stage, 60), stageNumber: optInt(r.stageNumber), documentStatus: str(r.documentStatus, 20),
    awbDone: bool(r.awbDone), submittedAt: instant(r.submittedAt) };
}

export function mapStoreOrder(value: unknown): StoreOrder {
  const r = obj(value);
  const stage = obj(r.stage);
  const doc = obj(r.document);
  const shop = r.shop == null ? null : obj(r.shop);
  const m = shop === null ? null : obj(shop.money);
  return {
    globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40), submittedAt: instant(r.submittedAt), dueDate: optStr(r.dueDate, 10),
    stage: { id: str(stage.id, 60), number: optInt(stage.number), total: int(stage.total), completed: bool(stage.completed) }, cancelled: bool(r.cancelled),
    document: { status: str(doc.status, 20), version: int(doc.version), revision: optInt(doc.revision), prints: int(doc.prints), lastPrint: instant(doc.lastPrint) },
    invoiceIssued: bool(r.invoiceIssued),
    products: list(r.products, 100, (entry) => {
      const p = obj(entry);
      return { line: int(p.line), name: str(p.name, 255), code: optStr(p.code, 120), variant: optStr(p.variant, 160), color: optStr(p.color, 160), width: optStr(p.width, 20), height: optStr(p.height, 20),
        unit: optStr(p.unit, 20), meters: optStr(p.meters, 20), quantity: int(p.quantity) };
    }),
    awbOperations: list(r.awbOperations, 20, (entry) => {
      const o = obj(entry);
      return { kind: str(o.kind, 20), state: str(o.state, 10), outcome: code(o.outcome), at: instant(o.at) ?? fail(), awb: optStr(o.awb, 20), parcels: optInt(o.parcels), reason: code(o.reason), by: str(o.by) };
    }),
    shop: shop === null || m === null ? null : {
      status: optStr(shop.status, 30),
      lines: list(shop.lines, 60, (entry) => { const l = obj(entry); return { name: str(l.name), sku: str(l.sku, 80), quantity: int(l.quantity), image: optStr(l.image, 420) }; }),
      money: { currency: str(m.currency, 3), known: bool(m.known), total: money(m.total), received: money(m.received), remaining: money(m.remaining) }, payment: optStr(shop.payment, 40),
      delivery: shop.delivery == null ? null : mapDeliveryView(shop.delivery),
    },
    canAwb: bool(r.canAwb), canDocuments: bool(r.canDocuments),
  };
}

export function mapMinePage(value: unknown): StorePage<StoreMineItem> {
  return page(obj(value), (entry) => {
    const r = obj(entry);
    return { kind: str(r.kind, 20), detail: str(r.detail, 20), at: instant(r.at) ?? fail(), globalOrderId: str(r.globalOrderId), orderNumber: str(r.orderNumber, 64), source: str(r.source, 40),
      awb: optStr(r.awb, 20), parcels: optInt(r.parcels), reason: optStr(r.reason, 600) };
  });
}

// ---- Parcels and weight -------------------------------------------------------------------------------------------------

/** The parcel count kept inside FAN Courier's limits (1–20); anything that is not a whole number becomes 1. */
export function clampParcels(value: number): number {
  if (!Number.isFinite(value)) return 1;
  return Math.min(MAX_PARCELS, Math.max(1, Math.trunc(value)));
}

/** The parcel stepper's keyboard: arrows and +/- step, PageUp/PageDown by 5, Home/End to the limits; null = not handled. */
export function parcelKey(current: number, key: string): number | null {
  switch (key) {
    case "ArrowUp": case "ArrowRight": case "+": return clampParcels(current + 1);
    case "ArrowDown": case "ArrowLeft": case "-": return clampParcels(current - 1);
    case "PageUp": return clampParcels(current + 5);
    case "PageDown": return clampParcels(current - 5);
    case "Home": return 1;
    case "End": return MAX_PARCELS;
    default: return null;
  }
}

/** The weight typed by the employee (comma or dot), or null when empty; NaN when not a valid weight. */
export function parseWeight(text: string): number | null {
  const clean = text.trim().replace(",", ".");
  if (clean === "") return null;
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(clean)) return Number.NaN;
  const value = Number(clean);
  return value > 0 && value <= MAX_WEIGHT_KG ? value : Number.NaN;
}

// ---- Readiness and tones ------------------------------------------------------------------------------------------------

/**
 * The three tones of every message (owner brief): green = done or ready; yellow = the employee can fix it here (address,
 * telephone, parcels, a reload); red = the system or finance must act (never the employee's fault, never retried blindly).
 */
export type Tone = "green" | "yellow" | "red";

const YELLOW = new Set(["address_incomplete", "phone_missing", "address_invalid", "awb_refused", "cod_changed", "label_unavailable", "order_busy", "operation_open", "awb_picked_up",
  "cancel_refused", "stage_not_ready", "document_changed", "DOCUMENT_VERSION_CONFLICT"]);

export function toneOf(reason: string): Tone {
  if (reason === "ok" || reason === "created" || reason === "cancelled" || reason === "address_saved") return "green";
  return YELLOW.has(reason) ? "yellow" : "red";
}

export type AwbReadiness =
  | { state: "ready"; cod: string; warnings: string[] }
  | { state: "address"; issues: string[] }
  | { state: "blocked"; reason: string; tone: Tone }
  | { state: "created"; number: string | null }
  | { state: "unknown" };

/**
 * What the AWB row shows: the shop's own reading (blockers, address check, cash on delivery). The address issues come first
 * (the employee fixes them here); then the first blocker; `ready` only with a known cash on delivery.
 */
export function awbReadiness(item: Pick<StoreAwbItem, "shop" | "lastAttempt">): AwbReadiness {
  const shop = item.shop;
  if (!shop || !shop.available) return { state: "unknown" };
  if (shop.awb.state === "created") return { state: "created", number: shop.awb.number };
  if (shop.address.issues.length > 0) return { state: "address", issues: shop.address.issues };
  const blockers = shop.blockers.filter((b) => b !== "address_incomplete" || shop.address.issues.length === 0);
  if (blockers.length > 0) return { state: "blocked", reason: blockers[0], tone: toneOf(blockers[0]) };
  if (shop.cod === null) return { state: "blocked", reason: "finance_unavailable", tone: "red" };
  return { state: "ready", cod: shop.cod, warnings: shop.address.warnings };
}

// ---- Stage indicator ------------------------------------------------------------------------------------------------------

export const STAGE_IDS = CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.map((stage) => stage.id);

/** "12 / 14" for a stage id of the canonical workflow; null for an unknown id (never guessed). */
export function stageNumber(stageId: string): number | null {
  const index = STAGE_IDS.indexOf(stageId as (typeof STAGE_IDS)[number]);
  return index < 0 ? null : index + 1;
}

// ---- Dates (Europe/Bucharest) --------------------------------------------------------------------------------------------

/** YYYY-MM-DD of an instant in Bucharest. */
export function bucharestDay(at: Date): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Bucharest", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(at);
  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}`;
}

/** A custom range is valid: two dates, from ≤ to, at most 92 days (the server checks again). */
export function validRange(from: string, to: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to) || from > to) return false;
  return (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000 <= 91;
}

// ---- The AWB send queue ----------------------------------------------------------------------------------------------------

/**
 * One AWB request at a time per shop (FAN Courier and the shop's per-order lock serialize anyway), so a burst of clicks never
 * floods the shop; the screen stays usable while the queue works. A refusal that only means "busy now" (`order_busy`, a
 * rate limit, a shop that could not be asked so NOTHING was sent) is retried at most twice with a growing pause. An unknown
 * outcome is NEVER retried: the row asks the shop for the stored answer instead (reconciliation).
 */
export type QueueJob<T> = { key: string; shop: string; run: () => Promise<T> };
export type QueueResult<T> = { key: string; ok: true; value: T } | { key: string; ok: false; error: unknown };

const RETRYABLE = new Set(["order_busy", "RATE_LIMITED", "CONFIRMARE_SOURCE_UNAVAILABLE"]);

export function retryable(error: unknown): boolean {
  if (!(error instanceof StaffServiceError)) return false;
  const details = error.details ?? {};
  const reason = typeof details.editReason === "string" ? details.editReason : "";
  const backend = typeof details.backendCode === "string" ? details.backendCode : "";
  return RETRYABLE.has(reason) || backend === "CONFIRMARE_SOURCE_UNAVAILABLE" || error.code === "RATE_LIMITED";
}

export class ShopQueue {
  private readonly chains = new Map<string, Promise<unknown>>();
  private readonly pending = new Set<string>();
  constructor(private readonly sleep: (ms: number) => Promise<void> = (ms) => new Promise((resolve) => setTimeout(resolve, ms)), private readonly pauses: readonly number[] = [2000, 5000]) {}

  /** Whether a job with this key waits or runs (a second click on the same row is ignored). */
  has(key: string): boolean { return this.pending.has(key); }

  enqueue<T>(job: QueueJob<T>): Promise<QueueResult<T>> {
    if (this.pending.has(job.key)) return Promise.resolve({ key: job.key, ok: false, error: new StaffServiceError("IDEMPOTENCY_CONFLICT") });
    this.pending.add(job.key);
    const previous = this.chains.get(job.shop) ?? Promise.resolve();
    const next = previous.catch(() => undefined).then(async (): Promise<QueueResult<T>> => {
      try {
        for (let attempt = 0; ; attempt += 1) {
          try { return { key: job.key, ok: true, value: await job.run() }; }
          catch (error) {
            if (attempt >= this.pauses.length || !retryable(error)) return { key: job.key, ok: false, error };
            await this.sleep(this.pauses[attempt]);
          }
        }
      } finally { this.pending.delete(job.key); }
    });
    this.chains.set(job.shop, next);
    return next;
  }
}

// ---- Address (the shop checks again; this only helps the employee before saving) ------------------------------------------

export const ADDRESS_FIELDS = ["firstName", "lastName", "phone", "county", "city", "street", "streetNo", "building", "entrance", "floor", "apartment", "postcode", "note"] as const;
export type AddressField = (typeof ADDRESS_FIELDS)[number];
export type AddressForm = Record<AddressField, string>;

/** The fields a form must have before "Salvează și verifică" (codes of the shop's AddressCheck). */
export function addressFormIssues(form: AddressForm): string[] {
  const issues: string[] = [];
  if (`${form.firstName}${form.lastName}`.trim() === "") issues.push("name_missing");
  const digits = form.phone.replace(/\D+/g, "");
  if (digits === "") issues.push("phone_missing");
  else if (!/^(?:0040|40)?0?[237]\d{8}$/.test(digits) && !/^00?[1-9]\d{9,13}$/.test(digits)) issues.push("phone_invalid");
  if (form.county.trim() === "") issues.push("county_missing");
  if (form.city.trim() === "") issues.push("locality_missing");
  if (form.street.trim() === "") issues.push("street_missing");
  if (form.postcode.trim() !== "" && !/^\d{6}$/.test(form.postcode.trim())) issues.push("postcode_invalid");
  return issues;
}

/** The locality FAN Courier knows for Bucharest without a sector (FAN Courier accepts it as written). */
export const BUCHAREST_CITY = "Bucuresti";

/** Bucharest: the sector is the locality FAN Courier knows ("Sector 3"); elsewhere the locality stays as typed. */
export function sectorOf(city: string): number | null {
  const match = /^sector\s*([1-6])$/i.exec(city.trim());
  return match ? Number(match[1]) : null;
}

/** A telephone number for the call link (digits and a leading +), or null. */
export function telHref(phone: string): string | null {
  const clean = phone.replace(/[^\d+]/g, "");
  return /^\+?\d{9,15}$/.test(clean) ? `tel:${clean}` : null;
}

// ---- The final invoice after the production document (Staff 2.60.0) ----------------------------------------------------

/**
 * Store Workspace V1.1 (owner 2026-11-26): the shop employee never sees the SmartBill panel. The final invoice that the
 * employee used to issue there by hand is issued by the same server route right after "Pregătește și tipărește" printed the
 * production document (never before it). Every rule stays the server's and the shop's: who may issue (the routed employee of
 * the shop), when (an approved order whose production ticket is active, the money verified, no open finance or invoice
 * action), the existing document and the SmartBill settings. This only reads the server's answer and says what to do:
 * - `exists`: the final invoice is there (nothing is sent);
 * - `open`: an invoice action has no answer yet (never sent again);
 * - `quiet`: not this employee's invoice (another role, not routed to them, observe mode, a proforma shop): nothing is shown;
 * - `blocked`: the server or the shop refuses now (the first reason is shown in words);
 * - `issue`: send `issue` once, with the document revision the server read.
 */
export type FinalInvoiceStep =
  | { kind: "exists"; document: string }
  | { kind: "open" }
  | { kind: "quiet" }
  | { kind: "blocked"; code: string }
  | { kind: "issue"; revision: string };

const QUIET_INVOICE = ["not_allowed_for_role", "not_assigned", "assigned_to_other", "invoice_observe", "invoice_disabled"];

export function finalInvoiceStep(read: InvoiceRead): FinalInvoiceStep {
  if (!read.available) return read.reason === "not_configured" ? { kind: "quiet" } : { kind: "blocked", code: "smartbill_unreachable" };
  const { summary } = read;
  const document = summary.document;
  if (document.exists && document.type !== "proforma") return { kind: "exists", document: `${document.series ?? ""} ${document.number ?? ""}`.trim() };
  if (summary.operation.state === "unknown" || summary.operation.state === "in_flight" || read.actionOpen) return { kind: "open" };
  if (read.role !== "documents" && read.role !== "manager") return { kind: "quiet" };
  if (summary.smartbill.documentType !== "invoice" || summary.mode !== "enforce") return { kind: "quiet" };
  if (read.allowedActions.includes("issue")) return { kind: "issue", revision: summary.revision };
  const blockers = blockersOf(read, "issue");
  if (blockers.length === 0 || blockers.some((code) => QUIET_INVOICE.includes(code))) return { kind: "quiet" };
  return { kind: "blocked", code: blockers[0] };
}
