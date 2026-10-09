import type { Employee } from "./models";

/**
 * Staff Trendyol workspace. Incoming Trendyol packages are intake work only: they enter production solely through
 * an explicit approval, which creates the canonical order at stage 1 with its Arasya QR and document revision 1.
 * Nothing in this workspace writes to Trendyol; statuses, shipping and invoices stay in the Seller Panel.
 */
export type TrendyolIntakeStatus = "pending" | "released" | "dismissed" | "marketplace_cancelled";
export type TrendyolView = "pending" | "released" | "closed" | "ignored";
export type TrendyolLineKind = "curtain" | "drapery" | "other";

export type TrendyolCapabilities = { view: boolean; prepare: boolean; release: boolean };

export type TrendyolOverview = {
  intake: { status: "inactive" | "active" | "paused"; baselineAt: string | null; lastRunAt: string | null; lastRunOutcome: string | null };
  counts: { pending: number; released: number; closed: number; ignored: number };
  capabilities: TrendyolCapabilities;
};

export type TrendyolPackageSummary = {
  packageId: string;
  orderNumber: string;
  intakeStatus: TrendyolIntakeStatus;
  marketplaceStatus: string;
  orderDate: string | null;
  /** Ordered within three hours after activation: Trendyol's GMT+3 order date may predate activation. */
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
  readiness: { ready: boolean; missingLines: number[]; marketplaceReleasable: boolean };
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
  Awaiting: "Plată în verificare",
  Verified: "Plată verificată",
  Created: "Nouă",
  Picking: "În pregătire",
  Invoiced: "Facturată",
  Shipped: "Expediată",
  Delivered: "Livrată",
  UnDelivered: "Nelivrată",
  Returned: "Returnată",
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

/** Shown on packages dated inside the GMT+3 ambiguity after activation, before anyone approves them. */
export const nearActivationWarning = "Comanda are data în primele 3 ore după activarea conexiunii. Trendyol trimite ora Turciei (GMT+3), deci comanda poate fi plasată înainte de activare. Verifică în Seller Panel și în producție că nu a fost deja preluată manual înainte de aprobare.";

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
