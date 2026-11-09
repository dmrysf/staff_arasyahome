import type { Employee } from "./models";

/**
 * Trendyol AWB print station (Staff 2.47.0, API 2.65.0).
 *
 * A Trendyol order at canonical stage 13 (Împachetare) appears automatically; the employee prints the original Trendyol
 * shipping label and may print it again at any time. The station never moves a stage and never changes an order.
 *
 * "Printed before" means a print was requested before from a station browser: a browser cannot prove that a printer
 * produced the label, so the interface never claims a physical print.
 */
export const AWB_VIEW = "trendyol.awb.view";
export const AWB_PRINT = "trendyol.awb.print";

export type AwbOrder = {
  /** Operational order UUID (the API identity of the station). */
  orderId: string;
  globalOrderId: string;
  orderNumber: string;
  packageId: string;
  stage: { id: string; ordinal: number | null };
  /** At stage 13, open, not stopped: part of the active queue. */
  inQueue: boolean;
  productionCompleted: boolean;
  productionStopped: boolean;
  enteredStageAt: string | null;
  printed: { count: number; lastAt: string | null };
};

export type AwbQueue = { generatedAt: string; total: number; items: AwbOrder[]; nextCursor: string | null; canPrint: boolean };

export type AwbLabel = { blob: Blob; retrievalId: string; parcel: number; parcelCount: number };

export type AwbPrintRecord = { print: { id: string; kind: "initial" | "reprint"; requestedAt: string }; printed: { count: number; lastAt: string | null } };

export type AwbHistoryEvent = { id: string; type: "label_retrieved" | "label_failed" | "print_requested"; kind: "initial" | "reprint" | null; outcome: string; parcel: { number: number; count: number } | null; at: string; by: string };

export interface AwbApi {
  queue(input: { search?: string; cursor?: string | null; limit?: number }, signal?: AbortSignal): Promise<AwbQueue>;
  resolve(code: string, signal?: AbortSignal): Promise<{ items: AwbOrder[]; canPrint: boolean }>;
  label(orderId: string, parcel: number, signal?: AbortSignal): Promise<AwbLabel>;
  recordPrint(orderId: string, input: { attemptId: string; retrievalId: string }): Promise<AwbPrintRecord>;
  history(orderId: string, signal?: AbortSignal): Promise<{ order: AwbOrder; events: AwbHistoryEvent[] }>;
}

export function canUseAwbStation(employee: Employee): boolean {
  return employee.status === "active" && !employee.mustChangePassword && employee.applications.includes("staff") && employee.permissions.includes(AWB_VIEW);
}

export function canPrintAwb(employee: Employee): boolean {
  return canUseAwbStation(employee) && employee.permissions.includes(AWB_PRINT);
}

/**
 * A dedicated station identity: AWB access and nothing else to work on in Staff (no stage, no Trendyol intake, no
 * Confirmare, no documents, no management). Such an account only ever sees the station screen. Presentation only:
 * the server authorizes every request on its own.
 */
export function isAwbStationOnly(employee: Employee, otherWorkspaces: number): boolean {
  return canUseAwbStation(employee) && employee.allowedStageIds.length === 0 && otherWorkspaces === 0;
}

/** API error codes the station explains in its own words (RO/TR); anything else is a generic failure. */
export const AWB_ERROR_CODES = [
  "AWB_LABEL_NOT_READY", "AWB_LABEL_NOT_AVAILABLE", "AWB_LABEL_EXPIRED", "AWB_TRENDYOL_AUTH", "AWB_TRENDYOL_RATE_LIMITED", "AWB_TRENDYOL_UNAVAILABLE",
  "AWB_TRENDYOL_INVALID", "AWB_LABEL_INVALID", "AWB_LABEL_FORMAT", "AWB_PACKAGE_UNAVAILABLE", "AWB_PACKAGE_MISMATCH", "AWB_PACKAGE_CLOSED", "AWB_ORDER_STOPPED",
  "AWB_LABELS_UNAVAILABLE", "AWB_ORDER_NOT_FOUND", "AWB_QR_INACTIVE", "AWB_CODE_INVALID", "AWB_PARCEL_NOT_FOUND", "AWB_RETRIEVAL_UNKNOWN",
] as const;
export type AwbErrorCode = (typeof AWB_ERROR_CODES)[number];

export function awbErrorCode(backendCode: unknown): AwbErrorCode | null {
  return typeof backendCode === "string" && (AWB_ERROR_CODES as readonly string[]).includes(backendCode) ? backendCode as AwbErrorCode : null;
}

/**
 * What the search field received: a scanner types the Arasya QR payload and Enter. Whitespace and a leading '#' are
 * ignored; nothing else is rewritten (the server validates every code).
 */
export function normalizeScan(value: string): string {
  return value.replace(/[\r\n\t]/g, "").trim().slice(0, 128);
}

export function isArasyaQr(value: string): boolean {
  return /^ARASYA:Q1:[A-Z2-7]{26}$/i.test(value.trim());
}

/** A queue row that matches a typed number exactly (order number or package id), without asking the server. */
export function findLocal(items: readonly AwbOrder[], code: string): AwbOrder | null {
  const value = code.replace(/^#/, "").trim();
  if (!value) return null;
  return items.find((item) => item.orderNumber === value || item.packageId === value || item.globalOrderId === value) ?? null;
}

/** A new attempt id per print request (idempotent replay on the server). */
export function newAttemptId(): string {
  return globalThis.crypto?.randomUUID?.() ?? "00000000-0000-4000-8000-000000000000".replace(/0/g, () => Math.floor(Math.random() * 16).toString(16));
}
