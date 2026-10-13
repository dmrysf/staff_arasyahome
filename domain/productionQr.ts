import { liveLabels } from "../i18n";

/** Who issues the production QR identity of an order: Arasya, or still the commerce source (legacy YD SOFT). */
export type QrAuthority = "arasya" | "source";
export type QrAuthorityMode = "legacy" | "observe" | "enforce" | "internal";
export type QrRevisionState = "active" | "superseded" | "revoked" | "retired";
export type QrRotationReason = "label_lost" | "label_damaged" | "security";

export type QrRevision = {
  revision: number;
  state: QrRevisionState;
  issuedAt: string;
  retiredAt: string | null;
  hint: string;
  documentRevision: number | null;
};

export type ActiveQr = {
  revision: number;
  issuedAt: string;
  hint: string;
  documentRevision: number | null;
  /** Printed payload (`ARASYA:Q1:…`): opaque, resolves only for authenticated employees allowed to see the order. */
  payload: string;
  /** Server-rendered black-on-white SVG of the payload. */
  svg: string;
};

export type ProductionQrView = {
  globalOrderId: string;
  orderNumber: string;
  source: string;
  qrAuthorityMode: QrAuthorityMode;
  productionAuthority: "source" | "operations";
  qrAuthority: QrAuthority;
  active: ActiveQr | null;
  history: QrRevision[];
  rotate: { allowed: boolean; blockedReason: string | null; reasons: QrRotationReason[] };
};

/** Manager-only production QR authority (same permission and Dashboard rule as the authority takeover). */
export interface ProductionQrApi {
  inspect(globalOrderId: string, signal?: AbortSignal): Promise<ProductionQrView>;
  rotate(globalOrderId: string, input: { expectedQrRevision: number; reason: QrRotationReason }, idempotencyKey: string): Promise<ProductionQrView>;
}

export const qrAuthorityLabels: Readonly<Record<QrAuthority, string>> = liveLabels("qrp.authority.", ["arasya", "source"]);
export const qrAuthorityModeLabels: Readonly<Record<QrAuthorityMode, string>> = liveLabels("qrp.mode.", ["legacy", "observe", "enforce", "internal"]);
export const qrRevisionStateLabels: Readonly<Record<QrRevisionState, string>> = liveLabels("qrp.state.", ["active", "superseded", "revoked", "retired"]);
export const qrRotationReasonLabels: Readonly<Record<QrRotationReason, string>> = liveLabels("qrp.reason.", ["label_lost", "label_damaged", "security"]);
export const qrRotateBlockedCopy: Readonly<Record<string, string>> = liveLabels("qrp.blocked.", [
  "qr_source_not_supported", "qr_cutover_disabled", "qr_not_arasya", "production_completed", "order_unavailable", "qr_document_controlled", "qr_missing",
]);

/** "ARASYA-QR-63380-R2.svg" — no customer data in file names. */
export function qrLabelFilename(orderNumber: string, revision: number): string {
  return `ARASYA-QR-${orderNumber.replace(/[^A-Za-z0-9-]/g, "")}-R${revision}.svg`;
}
