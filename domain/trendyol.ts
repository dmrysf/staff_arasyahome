import type { Employee } from "./models";

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

export type TrendyolPreparedLine = { kind: TrendyolLineKind; widthCm: string | null; heightCm: string | null; meters: string | null; notes: string | null; preparedBy: string | null; preparedAt: string | null };

export type TrendyolLine = {
  lineId: string;
  lineNumber: number;
  productName: string;
  stockCode: string | null;
  barcode: string | null;
  productSize: string | null;
  productColor: string | null;
  quantity: number;
  sizeSuggestion: { width: string; height: string } | null;
  prepared: TrendyolPreparedLine | null;
};

export type TrendyolPackageDetail = TrendyolPackageSummary & {
  delivery: { name: string | null; addressLines: string[]; phoneMasked: string | null } | null;
  lines: TrendyolLine[];
  readiness: { ready: boolean; missingLines: number[]; marketplaceReleasable: boolean; blockedReason: TrendyolBlockedReason | null };
  siblings: { packageId: string; intakeStatus: TrendyolIntakeStatus; marketplaceStatus: string }[];
  production: { globalOrderId: string; stageId: string; stageLabel: string | null; documentStatus: string; operationalStatus: string; releasedBy: string | null; releasedAt: string | null } | null;
  dismissal: { reason: string; by: string | null; at: string | null } | null;
  history: { action: string; actor: string | null; at: string | null }[];
  capabilities: TrendyolCapabilities & { prepareNow: boolean; releaseNow: boolean; dismissNow: boolean; reopenNow: boolean };
};

export type TrendyolLineInput = { expectedVersion: number; kind: TrendyolLineKind; widthCm?: string; heightCm?: string; meters?: string; notes?: string };

export interface TrendyolApi {
  overview(signal?: AbortSignal): Promise<TrendyolOverview>;
  list(view: Exclude<TrendyolView, "ignored">, signal?: AbortSignal): Promise<TrendyolPackageSummary[]>;
  ignored(signal?: AbortSignal): Promise<TrendyolIgnoredPackage[]>;
  detail(packageId: string, signal?: AbortSignal): Promise<TrendyolPackageDetail>;
  prepareLine(packageId: string, lineId: string, input: TrendyolLineInput, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  dismiss(packageId: string, input: { expectedVersion: number; reason: string }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  reopen(packageId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
  release(packageId: string, input: { expectedVersion: number; confirm: true }, idempotencyKey: string): Promise<TrendyolPackageDetail>;
}

export const TRENDYOL_VIEW_PERMISSION = "trendyol.orders.view";

/** Only explicitly authorized Trendyol personnel see the workspace (Staff application access included). */
export function canUseTrendyolWorkspace(employee: Employee): boolean {
  return employee.applications.includes("staff") && employee.permissions.includes(TRENDYOL_VIEW_PERMISSION);
}

/** Trendyol Seller Panel statuses, shown read-only. Arasya never changes them. */
export const marketplaceStatusLabels: Record<string, string> = {
  Awaiting: "În așteptarea confirmării plății",
  Verified: "Plată verificată",
  Created: "Nouă",
  Picking: "În pregătire",
  Invoiced: "Facturată",
  ReadyToShip: "Pregătită pentru curier",
  Shipped: "Expediată",
  Delivered: "Livrată",
  UnDelivered: "Nelivrată",
  Returned: "Returnată",
  UnDeliveredAndReturned: "Nelivrată și returnată",
  Cancelled: "Anulată",
  UnSupplied: "Nefurnizată",
  UnPacked: "Pachet împărțit",
  AtCollectionPoint: "La punctul de ridicare",
};

export const intakeStatusLabels: Record<TrendyolIntakeStatus, string> = {
  pending: "De pregătit",
  released: "Trimisă în producție",
  dismissed: "Scoasă din lucru",
  marketplace_cancelled: "Anulată în Trendyol",
};

export const ignoredReasonLabels: Record<TrendyolIgnoredPackage["reason"], string> = {
  historical: "Comandă anterioară activării (istorică)",
  status_not_eligible: "Nu era o comandă nouă (expediată, livrată, anulată…)",
  order_date_missing: "Fără dată de comandă",
};

/** Shown on packages ordered within five minutes after activation: a manual-duplication check, not a timezone rule. */
export const nearActivationWarning = "Comanda a fost plasată în primele 5 minute după activarea conexiunii. Verifică să nu fi fost deja preluată manual în producție înainte de aprobare.";

/** Section headings of the attention list, by marketplace class. */
export const marketplaceClassLabels: Record<TrendyolMarketplaceClass, string> = {
  new: "Gata de pregătire",
  payment_pending: "În așteptarea confirmării plății",
  review: "Necesită verificare",
  fulfilment: "Excepție de livrare",
  returned: "Returnată",
  cancelled: "Anulată în Trendyol",
  split: "Pachet împărțit în Trendyol",
};

/** Why a pending package cannot be released yet. The API enforces the same rule. */
export const blockedReasonMessages: Record<TrendyolBlockedReason, string> = {
  payment_pending: "În așteptarea confirmării plății. Comanda poate intra în producție numai după ce Trendyol confirmă plata.",
  review: "Necesită verificare: Trendyol arată comanda ca pregătită pentru curier. Nu poate fi trimisă în producție din Arasya în acest status. Verifică în Seller Panel.",
  unknown_status: "Status necunoscut — verificare necesară. Comanda nu poate fi trimisă în producție până când Trendyol o trece într-un status cunoscut.",
  fulfilment: "Comanda este deja expediată sau în livrare în Trendyol (excepție de livrare). Nu poate intra în producție.",
  returned: "Comanda a fost returnată în Trendyol (retur, nu anulare). Nu poate intra în producție.",
  cancelled: "Comanda a fost anulată în Trendyol. Nu poate intra în producție.",
  split: "Pachetul a fost împărțit în Trendyol. Pachetele noi apar separat.",
};

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

export const lineKindLabels: Record<TrendyolLineKind, string> = { curtain: "Perdea", drapery: "Draperie", other: "Alt produs (fără măsuri)" };

export const intakeStateLabels: Record<TrendyolOverview["intake"]["status"], string> = {
  inactive: "Conexiunea Trendyol nu este activată. Nu se citesc comenzi.",
  active: "Conexiune activă (numai citire).",
  paused: "Conexiune oprită temporar.",
};

/** Exact decimal text the API accepts: up to six digits and three decimals, comma or dot. */
export function normalizeMeasure(value: string): string | null {
  const text = value.trim().replace(",", ".");
  if (!text) return null;
  return /^\d{1,6}(?:\.\d{1,3})?$/.test(text) && Number(text) > 0 ? text : "invalid";
}

export function marketplaceLabel(status: string): string {
  return marketplaceStatusLabels[status] ?? status;
}
