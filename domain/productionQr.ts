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

export const qrAuthorityLabels: Record<QrAuthority, string> = {
  arasya: "Cod QR gestionat de Arasya",
  source: "Cod QR gestionat încă de sursă (YD SOFT)",
};

export const qrAuthorityModeLabels: Record<QrAuthorityMode, string> = {
  legacy: "Autoritatea QR Arasya nu este activată pentru această sursă.",
  observe: "Mod observare: YD SOFT încă tipărește propriul cod; Arasya înregistrează diferențele.",
  enforce: "Arasya este singura autoritate pentru codul QR de producție al acestei surse.",
  internal: "Comandă internă Arasya.",
};

export const qrRevisionStateLabels: Record<QrRevisionState, string> = {
  active: "Activ",
  superseded: "Înlocuit",
  revoked: "Anulat",
  retired: "Retras",
};

export const qrRotationReasonLabels: Record<QrRotationReason, string> = {
  label_lost: "Eticheta s-a pierdut",
  label_damaged: "Eticheta este deteriorată",
  security: "Motiv de securitate",
};

export const qrRotateBlockedCopy: Record<string, string> = {
  qr_source_not_supported: "Codul QR al acestei comenzi se schimbă doar prin revizia documentului de producție.",
  qr_cutover_disabled: "Autoritatea QR Arasya nu este activată pentru această sursă.",
  qr_not_arasya: "Producția comenzii nu este gestionată în Arasya.",
  production_completed: "Producția comenzii este finalizată.",
  order_unavailable: "Comanda nu este disponibilă pentru producție.",
  qr_document_controlled: "Comanda are un document de producție: codul se schimbă doar printr-o revizie aprobată.",
  qr_missing: "Comanda nu are încă un cod QR activ.",
};

/** "ARASYA-QR-63380-R2.svg" — no customer data in file names. */
export function qrLabelFilename(orderNumber: string, revision: number): string {
  return `ARASYA-QR-${orderNumber.replace(/[^A-Za-z0-9-]/g, "")}-R${revision}.svg`;
}
