import type { Employee } from "./models";
import { inTab, sourceLabel, type ConfirmareItem, type ConfirmareLine, type QueueTab } from "./confirmare";
import { canUseFinance } from "./confirmareFinance";
import { canOverseeFinance } from "./financeRequests";
import { advanceStatusKey, proformaStatusKey, type InvoiceRead, type MoneyPosition } from "./confirmareInvoice";
import type { ShopLine, ShopRead } from "./confirmareShop";
import type { MessageKey } from "../i18n";

/**
 * The operator desk (Staff 2.42.0): one desktop workspace for the Confirmare operator — the queue on the left, the
 * selected order in the middle, deep work in a drawer. PRESENTATION ONLY: every number, filter and sort here is derived
 * from what the server already sent (the queue rows, the order, the shop read, the SmartBill read); nothing is stored,
 * nothing new is decided, and every command still goes to the server, which checks it again.
 */

// ---- Queue: counts, search and order ------------------------------------------------------------------------------

/** The desk's summary cards: the queue groups an operator works from (the existing tabs, never a new status). */
export const DESK_METRICS = ["mine", "callback", "correction", "payment"] as const satisfies readonly QueueTab[];
export type DeskMetric = (typeof DESK_METRICS)[number];

export function deskCounts(items: readonly ConfirmareItem[], employeeUuid: string): Record<DeskMetric, number> {
  const counts = { mine: 0, callback: 0, correction: 0, payment: 0 };
  for (const item of items) for (const metric of DESK_METRICS) if (inTab(item, metric, employeeUuid)) counts[metric]++;
  return counts;
}

export const DESK_SORTS = ["priority", "oldest", "newest", "due"] as const;
export type DeskSort = (typeof DESK_SORTS)[number];

const received = (item: ConfirmareItem): string => item.submittedAt ?? item.openedAt;

/**
 * The queue in the chosen order. `priority` keeps the server's order (due date first, then first come); the others sort
 * a copy by the order's own instants. A missing due date sorts last; ties keep the server's order.
 */
export function sortQueue(items: readonly ConfirmareItem[], sort: DeskSort): ConfirmareItem[] {
  if (sort === "priority") return [...items];
  const indexed = items.map((item, index) => ({ item, index }));
  const compare = (a: { item: ConfirmareItem; index: number }, b: { item: ConfirmareItem; index: number }): number => {
    if (sort === "due") {
      const left = a.item.dueDate ?? "9999-12-31";
      const right = b.item.dueDate ?? "9999-12-31";
      if (left !== right) return left < right ? -1 : 1;
      return a.index - b.index;
    }
    const difference = received(a.item).localeCompare(received(b.item));
    return (sort === "oldest" ? difference : -difference) || a.index - b.index;
  };
  return indexed.sort(compare).map((entry) => entry.item);
}

/**
 * The search over the queue rows the employee already holds: order number (with or without "#"), shop name and the
 * assignee's name. The queue never carries customer names or telephone numbers (privacy rule of the queue), so those
 * are searched inside the opened order only.
 */
export function searchQueue(items: readonly ConfirmareItem[], query: string): ConfirmareItem[] {
  const words = query.trim().toLocaleLowerCase("ro").replace(/^#/, "").split(/\s+/).filter(Boolean);
  if (words.length === 0) return [...items];
  return items.filter((item) => {
    const haystack = [item.orderNumber, `#${item.orderNumber}`, sourceLabel(item.sourceKey), item.sourceKey, item.assignment?.displayName ?? ""].join(" ").toLocaleLowerCase("ro");
    return words.every((word) => haystack.includes(word));
  });
}

// ---- Products: Arasya's lines with the shop's view of the same line --------------------------------------------------

const normalizeName = (name: string): string => name.normalize("NFC").toLocaleLowerCase("ro").replace(/\s+/g, " ").trim();

/**
 * Pairs each Arasya line with the shop's line of the same position AND the same name. A pair that does not match exactly
 * gets no shop line (no picture, no price): a wrong picture or price beside a product is worse than none.
 */
export function pairShopLines(lines: readonly ConfirmareLine[], shopLines: readonly ShopLine[] | null): Map<string, ShopLine> {
  const pairs = new Map<string, ShopLine>();
  if (!shopLines) return pairs;
  for (const line of lines) {
    const shop = shopLines.find((candidate) => candidate.line === line.lineNumber);
    if (shop && normalizeName(shop.name) === normalizeName(line.name)) pairs.set(line.lineId, shop);
  }
  return pairs;
}

/** The shop's line details that are not already shown as Arasya's measurements or options (no repeated facts). */
export function extraShopDetails(line: ConfirmareLine, shop: ShopLine | undefined): { label: string; value: string }[] {
  if (!shop) return [];
  const shown = new Set(line.options.map((option) => `${normalizeName(option.label)}=${normalizeName(option.value)}`));
  return shop.details.filter((detail) => !shown.has(`${normalizeName(detail.label)}=${normalizeName(detail.value)}`));
}

// ---- Money at a glance --------------------------------------------------------------------------------------------

export type MoneyGlance = {
  currency: string | null;
  total: string | null;
  method: string | null;
  cod: boolean | null;
  /** The shop's one money calculation (YD SOFT MoneyPosition), only when the shop reported a known position. */
  position: MoneyPosition | null;
  advance: MessageKey | null;
  proforma: { status: MessageKey; document: string | null } | null;
  invoice: { issued: boolean; document: string | null } | null;
};

/**
 * What the operator says about money on the telephone, from the reads already on screen: the shop's order (total,
 * method) and SmartBill's view (the money position, the proforma, the final invoice). Nothing is computed here: an
 * amount the server did not send stays unknown ("—"), and a proforma or a transfer is never shown as money received.
 */
export function moneyGlance(shop: ShopRead | null, invoice: InvoiceRead | null): MoneyGlance {
  const order = shop?.available ? shop.detail : null;
  const view = invoice?.available ? invoice : null;
  const proforma = view?.summary.proforma ?? null;
  const position = proforma?.money && proforma.money.known ? proforma.money : null;
  const document = view?.summary.document ?? null;
  const name = (series: string | null, number: string | null): string | null => `${series ?? ""} ${number ?? ""}`.trim() || null;
  return {
    currency: position?.currency ?? order?.order.currency ?? view?.summary.order.currency ?? null,
    total: position?.total ?? order?.totals.total ?? view?.summary.order.total ?? null,
    method: order ? order.payment.methodTitle || order.payment.method || null : null,
    cod: order ? order.payment.cod : proforma?.advance.cod ?? null,
    position,
    advance: proforma ? advanceStatusKey(proforma) : null,
    proforma: proforma ? { status: proformaStatusKey(proforma), document: proforma.document ? name(proforma.document.series, proforma.document.number) : null } : null,
    invoice: document ? { issued: document.exists && document.type === "invoice", document: document.exists && document.type === "invoice" ? name(document.series, document.number) : null } : null,
  };
}

// ---- Scanner -------------------------------------------------------------------------------------------------------

const DOCUMENT_PERMISSIONS = ["production.documents.generate", "production.documents.reprint", "production.documents.request_revision"];

/**
 * Owner decision 2026-10-30: the office operator's screens show no QR scanner. An employee whose Staff work is only
 * Confirmare or finance (no production stage, no production document permission) works without one; every production
 * role keeps the scanner and the production QR flow exactly as before. Presentation and routing only: the server keeps
 * its own stage checks on every scan.
 */
export function usesProductionScanner(employee: Employee): boolean {
  if (!employee.permissions.includes("orders.scan")) return false;
  const office = (employee.confirmareScopes?.operate.length ?? 0) + (employee.confirmareScopes?.supervise.length ?? 0) > 0 || canUseFinance(employee) || canOverseeFinance(employee);
  if (!office) return true;
  return employee.allowedStageIds.length > 0 || DOCUMENT_PERMISSIONS.some((key) => employee.permissions.includes(key));
}
