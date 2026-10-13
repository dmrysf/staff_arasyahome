import type { Employee } from "./models";
import { liveLabels, t } from "../i18n";

/**
 * Staff Trendyol workspace. Incoming Trendyol packages are intake work only: they enter production solely through
 * an explicit approval, which creates the canonical order at stage 1 with its Arasya QR and document revision 1.
 * Nothing in this workspace writes to Trendyol; statuses, shipping and invoices stay in the Seller Panel.
 */
export type TrendyolIntakeStatus = "pending" | "released" | "dismissed" | "marketplace_cancelled";
export type TrendyolView = "pending" | "attention" | "released" | "closed" | "ignored";
/** Server-side class of the marketplace status. Only `new` can be released into production. */
export type TrendyolMarketplaceClass = "new" | "payment_pending" | "review" | "fulfilment" | "returned" | "cancelled" | "split";
export type TrendyolBlockedReason = Exclude<TrendyolMarketplaceClass, "new"> | "unknown_status";
export type TrendyolLineKind = "curtain" | "drapery" | "other";

export type TrendyolCapabilities = { view: boolean; prepare: boolean; release: boolean };

export type TrendyolOverview = {
  intake: { status: "inactive" | "active" | "paused"; baselineAt: string | null; lastRunAt: string | null; lastRunOutcome: string | null };
  counts: { pending: number; attention: number; released: number; closed: number; ignored: number };
  capabilities: TrendyolCapabilities;
};

export type TrendyolPackageSummary = {
  packageId: string;
  orderNumber: string;
  intakeStatus: TrendyolIntakeStatus;
  marketplaceStatus: string;
  marketplaceClass: TrendyolMarketplaceClass;
  marketplaceStatusKnown: boolean;
  orderDate: string | null;
  /** Ordered within five minutes after activation: the team may already have handled it manually. */
  orderDateNearActivation: boolean;
  changedAfterRelease: boolean;
  version: number;
  lineCount?: number;
  preparedCount?: number;
  globalOrderId?: string | null;
  stageId?: string | null;
};

export type TrendyolIgnoredPackage = { packageId: string; orderNumber: string; reason: "historical" | "status_not_eligible" | "order_date_missing"; marketplaceStatus: string; orderDate: string | null; firstSeenAt: string | null };

export type TrendyolPreparedLine = { kind: TrendyolLineKind; widthCm: string | null; heightCm: string | null; meters: string | null; notes: string | null; productionTitle: string | null; preparedBy: string | null; preparedAt: string | null };

export type TrendyolLine = {
  lineId: string;
  lineNumber: number;
  /** The original Trendyol title, kept for traceability; the factory prints the Romanian production title. */
  productName: string;
  stockCode: string | null;
  /** The seller's product code (never Trendyol's "merchantSku" placeholder). */
  productCode: string | null;
  barcode: string | null;
  /** The Romanian title approved earlier for this product code, if any. */
  suggestedTitle: string | null;
  suggestedTitleApproval: { by: string | null; at: string | null } | null;
  productSize: string | null;
  productColor: string | null;
  quantity: number;
  sizeSuggestion: { width: string; height: string } | null;
  prepared: TrendyolPreparedLine | null;
  /** What the released production order prints for this line (its frozen item); null before approval. */
  productionItem: { name: string; productCode: string | null } | null;
};

export type TrendyolPackageDetail = TrendyolPackageSummary & {
  delivery: { name: string | null; addressLines: string[]; phoneMasked: string | null } | null;
  lines: TrendyolLine[];
  readiness: { ready: boolean; missingLines: number[]; marketplaceReleasable: boolean; blockedReason: TrendyolBlockedReason | null };
  siblings: { packageId: string; intakeStatus: TrendyolIntakeStatus; marketplaceStatus: string }[];
  production: { globalOrderId: string; stageId: string; stageLabel: string | null; documentStatus: string; operationalStatus: string; releasedBy: string | null; releasedAt: string | null } | null;
  dismissal: { reason: string; by: string | null; at: string | null } | null;
  /** Released packages only: the printed identity is wrong (placeholder code or marketplace title) and whether it can still be corrected. */
  correction: { needed: boolean; allowed: boolean } | null;
  history: { action: string; actor: string | null; at: string | null }[];
  capabilities: TrendyolCapabilities & { prepareNow: boolean; releaseNow: boolean; dismissNow: boolean; reopenNow: boolean; correctNow: boolean };
};

/** The caller's own Trendyol actions (GET /trendyol/activity): today's counts and the latest ten actions. */
export type TrendyolActivity = {
  today: { linesPrepared: number; released: number; dismissed: number; reopened: number };
  recent: { action: "line_prepared" | "released" | "dismissed" | "reopened"; packageId: string | null; orderNumber: string | null; at: string | null }[];
};

export const activityActionLabels: Readonly<Record<TrendyolActivity["recent"][number]["action"], string>> = liveLabels("ty.activity.", ["line_prepared", "released", "dismissed", "reopened"]);

export type TrendyolLineInput = { expectedVersion: number; kind: TrendyolLineKind; productionTitle: string; widthCm?: string; heightCm?: string; meters?: string; notes?: string };

export interface TrendyolApi {
  overview(signal?: AbortSignal): Promise<TrendyolOverview>;
  activity(signal?: AbortSignal): Promise<TrendyolActivity>;
  list(view: Exclude<TrendyolView, "ignored">, signal?: AbortSignal): Promise<TrendyolPackageSummary[]>;
  ignored(signal?: AbortSignal): Promise<TrendyolIgnoredPackage[]>;
  detail(packageId: string, signal?: AbortSignal): Promise<TrendyolPackageDetail>;
  prepareLine(packageId: string, lineId: string, input: TrendyolLineInput, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  dismiss(packageId: string, input: { expectedVersion: number; reason: string }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  reopen(packageId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  release(packageId: string, input: { expectedVersion: number; confirm: true }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  correctIdentity(packageId: string, input: { expectedVersion: number; confirm: true; lines: { lineId: string; productionTitle: string }[] }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
}

export const TRENDYOL_VIEW_PERMISSION = "trendyol.orders.view";

/** Only explicitly authorized Trendyol personnel see the workspace (Staff application access included). */
export function canUseTrendyolWorkspace(employee: Employee): boolean {
  return employee.applications.includes("staff") && employee.permissions.includes(TRENDYOL_VIEW_PERMISSION);
}

/** Trendyol Seller Panel statuses, shown read-only. Arasya never changes them. */
export const marketplaceStatusLabels: Readonly<Record<string, string>> = liveLabels("ty.marketplace.", [
  "Awaiting", "Verified", "Created", "Picking", "Invoiced", "ReadyToShip", "Shipped", "Delivered", "UnDelivered", "Returned",
  "UnDeliveredAndReturned", "Cancelled", "UnSupplied", "UnPacked", "AtCollectionPoint",
]);

export const intakeStatusLabels: Readonly<Record<TrendyolIntakeStatus, string>> = liveLabels("ty.intake.", ["pending", "released", "dismissed", "marketplace_cancelled"]);

export const ignoredReasonLabels: Readonly<Record<TrendyolIgnoredPackage["reason"], string>> = liveLabels("ty.ignored.", ["historical", "status_not_eligible", "order_date_missing"]);

/** Shown on packages ordered within five minutes after activation: a manual-duplication check, not a timezone rule. */
export const nearActivationWarning = (): string => t("ty.nearActivation");

/** Section headings of the attention list, by marketplace class. */
export const marketplaceClassLabels: Readonly<Record<TrendyolMarketplaceClass, string>> = liveLabels("ty.class.", ["new", "payment_pending", "review", "fulfilment", "returned", "cancelled", "split"]);

/** Why a pending package cannot be released yet. The API enforces the same rule. */
export const blockedReasonMessages: Readonly<Record<TrendyolBlockedReason, string>> = liveLabels("ty.blocked.", ["payment_pending", "review", "unknown_status", "fulfilment", "returned", "cancelled", "split"]);

/** The Arasya intake label, naming a split instead of a cancellation. */
export function intakeLabel(summary: Pick<TrendyolPackageSummary, "intakeStatus" | "marketplaceClass">): string {
  return summary.intakeStatus === "marketplace_cancelled" && summary.marketplaceClass === "split" ? marketplaceClassLabels.split : intakeStatusLabels[summary.intakeStatus];
}

/** Client-side fallback only (the server classification wins); unknown statuses are review. */
export function marketplaceClassOf(status: string): TrendyolMarketplaceClass {
  const classes: Record<string, TrendyolMarketplaceClass> = {
    Created: "new", Picking: "new", Invoiced: "new", Awaiting: "payment_pending", Verified: "payment_pending", ReadyToShip: "review",
    Shipped: "fulfilment", Delivered: "fulfilment", AtCollectionPoint: "fulfilment", UnDelivered: "fulfilment",
    Returned: "returned", UnDeliveredAndReturned: "returned", Cancelled: "cancelled", UnSupplied: "cancelled", UnPacked: "split",
  };
  return classes[status] ?? "review";
}

export const lineKindLabels: Readonly<Record<TrendyolLineKind, string>> = liveLabels("ty.kind.", ["curtain", "drapery", "other"]);

export const intakeStateLabels: Readonly<Record<TrendyolOverview["intake"]["status"], string>> = liveLabels("ty.connection.", ["inactive", "active", "paused"]);

/** Exact decimal text the API accepts: up to six digits and three decimals, comma or dot. */
export function normalizeMeasure(value: string): string | null {
  const text = value.trim().replace(",", ".");
  if (!text) return null;
  return /^\d{1,6}(?:\.\d{1,3})?$/.test(text) && Number(text) > 0 ? text : "invalid";
}

export function marketplaceLabel(status: string): string {
  return Object.hasOwn(marketplaceStatusLabels, status) ? marketplaceStatusLabels[status] : status;
}
