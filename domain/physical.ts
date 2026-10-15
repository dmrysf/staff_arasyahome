import { physicalText as pt } from "../i18n/physical";
/** Physical facts from API 2.32.0. Commercial quantity never establishes physical count. */
export type ProductType = "perdea" | "draperii" | "other" | "unknown";
export type PlanPiece = { index: number; width: string | null; height: string | null; unit: string | null; fabricMeters?: string | null; fabric?: string | null };
export type PlanLine = { itemId: string; sourcePlanHash: string; lineNumber: number; name: string; code: string | null; color: string | null;
  commercialQuantity: number; lineMeasurements: { width: string | null; height: string | null; unit: string | null }; lineMeters: string | null;
  planKnown: boolean; staleAfterSourceChange: boolean; typeVerified: boolean; route: string[]; notApplicableStages: string[];
  proposal: { evidence: { kind: string; value: string }[] }; pieces: { id: string; index: number; count: number; trackId: string | null }[];
  plan: { revision: number; productType: ProductType; typeLocked: boolean; pieceCount: number | null; materialTotal: string | null; pieces: PlanPiece[]; note: string | null } };
export type ManufacturingPlan = { orderId: string; canConfirm: boolean; allPlansKnown: boolean; partialRework: boolean; pieceRouting: boolean; lines: PlanLine[] };
export type PlanConfirmation = { expectedRevision: number; expectedSourceHash: string; productType: ProductType; pieceCount: number;
  materialTotalMeters: string | null; pieces: PlanPiece[]; note: string };
export type PhysicalHistory = { unitId: string; status: string; reworkCycle: number; productionStageId: string | null;
  replaces: { unitId: string; disposition: string } | null; replacedBy: string | null;
  custody: { version: number; custodian: { displayName: string } | null; location: { id: string; label: string } | null; hold: string | null };
  labels: { payload: string; status: string }[];
  stageLedger: { reworkCycle: number; stageId: string; outcome: string; employee: string | null; at: string }[];
  material: { id: string; kind: string; meters: string | null; stockEffect?: string }[] };
export type PhysicalApi = {
  plan(orderId: string, signal?: AbortSignal): Promise<ManufacturingPlan>;
  confirmPlan(orderId: string, lineId: string, input: PlanConfirmation, key: string): Promise<ManufacturingPlan>;
  history(unitId: string, signal?: AbortSignal): Promise<PhysicalHistory>;
};
export type CustodyInput = { action: "take" | "locate" | "hold" | "release"; expectedVersion: number; qrToken: string; locationId?: string; holdReason?: string };
export type StaffPhysicalApi = PhysicalApi & {
  locations(signal?: AbortSignal): Promise<{ locations: { id: string; label: string }[] }>;
  custody(unitId: string, input: CustodyInput, key: string): Promise<unknown>;
  reunite(input: { unitIds: string[]; confirmed: true; locationId?: string }, key: string): Promise<unknown>;
  material(unitId: string, input: { kind: string; meters: string; note?: string; correctsRecordId?: string }, key: string): Promise<unknown>;
};
/** No floating point division, no inferred measurements. Empty remains unknown. */
export function planDecimal(value: string): string | null {
  if (!value.trim()) return null;
  const clean = value.trim().replace(",", ".");
  if (!/^\d{1,9}(?:\.\d{1,3})?$/.test(clean)) throw new Error(pt("physical.introdu_o_masura_pozitiva_cu_cel_mult"));
  const [whole, fraction = ""] = clean.split(".");
  return `${whole}.${fraction.padEnd(3, "0")}`;
}
/** Corrections can reverse a measured quantity; plans and consumption still require unsigned values. */
export function materialDecimal(value: string, correction: boolean): string | null {
  const negative = correction && value.trim().startsWith("-");
  const amount = planDecimal(negative ? value.trim().slice(1) : value);
  return amount === null ? null : `${negative ? "-" : ""}${amount}`;
}
/** Keep a pending command immutable across an ambiguous response. Changing its body needs a new key. */
export const isAmbiguous = (error: unknown): boolean => {
  if (typeof error === "object" && error !== null) {
    const status = "status" in error ? Number(error.status) : "details" in error ? Number((error.details as { httpStatus?: number } | undefined)?.httpStatus) : NaN;
    if (Number.isFinite(status) && status > 0 && status < 500) return false;
  }
  const code = typeof error === "object" && error !== null && "code" in error ? String(error.code) : "";
  return ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(code);
};
export function physicalError(error: unknown): string {
  const details = typeof error === "object" && error !== null && "details" in error ? error.details as { backendCode?: string } : null;
  const code = details?.backendCode ?? (typeof error === "object" && error !== null && "code" in error ? String(error.code) : "");
  if (code === "MATERIAL_KIND_MISMATCH") return pt("physical.error.materialKind");
  if (["MATERIAL_ALREADY_RECORDED", "IDEMPOTENCY_CONFLICT"].includes(code)) return pt("physical.error.duplicate");
  if (code === "CUTTING_NOT_AUTHORIZED") return pt("physical.error.cuttingApproval");
  if (["EXACT_PIECES_REQUIRED", "PLAN_CONFIRMATION_REQUIRED"].includes(code)) return pt("physical.error.planRequired");
  if (["PIECE_OUTCOME_REQUIRED", "DEFECT_RESOLUTION_REQUIRED"].includes(code)) return pt("physical.error.resultRequired");
  if (["SOURCE_PLAN_CHANGED", "PLAN_CHANGED", "SOURCE_PLAN_HASH_REQUIRED"].includes(code)) return pt("physical.error.sourceChanged");
  if (["PLAN_PIECE_IN_USE", "PLAN_TYPE_LOCKED"].includes(code)) return pt("physical.planul_folosit_nu_poate_fi_schimbat_astfel");
  if (["ORDER_PIECES_HELD", "ORDER_PIECES_INCOMPLETE", "ORDER_ROUTE_INCOMPLETE", "ORDER_PIECES_IN_REWORK", "ORDER_PIECES_NOT_READY"].includes(code)) return pt("physical.ambalarea_asteapta_toate_bucatile_etapele_aplicabile_si");
  if (["UNIT_RETIRED", "EXPIRED_QR"].includes(code)) return pt("physical.bucata_inlocuita_foloseste_eticheta_noua");
  if (isAmbiguous(error)) return pt("physical.raspuns_neconfirmat_reincarca_starea_salvata_sau_reincearca");
  if (["UNAUTHORIZED_ACTION", "PLAN_CONFIRMATION_NOT_ALLOWED", "APPLICATION_ACCESS_DENIED"].includes(code)) return pt("physical.contul_actual_nu_este_autorizat_pentru_aceasta");
  if (error instanceof Error && !code) return error.message;
  return pt("physical.operatiune_neconfirmata_reincarca_datele_si_verifica_selectia");
}

const record = (value: unknown): Record<string, unknown> => {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw invalidPhysicalResponse();
  return value as Record<string, unknown>;
};
const invalidPhysicalResponse = () => Object.assign(new Error(pt("physical.raspunsul_serverului_nu_poate_fi_verificat_reincarca")), { code: "SERVER_ERROR" });
const text = (v: unknown) => { if (typeof v !== "string") throw invalidPhysicalResponse(); return v; };
const number = (v: unknown, min = 0) => { if (typeof v !== "number" || !Number.isInteger(v) || v < min) throw invalidPhysicalResponse(); return v; };
const nullableDecimal = (v: unknown) => { if (v !== null && (typeof v !== "string" || !/^\d{1,9}\.\d{3}$/.test(v))) throw invalidPhysicalResponse(); };
/** Validate the facts used by confirmations. Missing capability fields fail closed. */
export function mapManufacturingPlan(value: unknown): ManufacturingPlan {
  const raw = record(value); text(raw.orderId);
  if (!Array.isArray(raw.lines)) throw invalidPhysicalResponse();
  for (const value of raw.lines) {
    const line = record(value); text(line.itemId); text(line.name); text(line.sourcePlanHash);
    if (!/^[a-f0-9]{64}$/.test(String(line.sourcePlanHash))) throw invalidPhysicalResponse();
    for (const field of ["planKnown", "typeVerified", "staleAfterSourceChange"]) if (typeof line[field] !== "boolean") throw invalidPhysicalResponse();
    number(line.commercialQuantity, 1); const plan = record(line.plan); number(plan.revision); if (typeof plan.typeLocked !== "boolean") throw invalidPhysicalResponse();
    if (!["perdea", "draperii", "unknown", "other"].includes(text(plan.productType))) throw invalidPhysicalResponse();
    if (plan.pieceCount !== null && number(plan.pieceCount, 1) > 200) throw invalidPhysicalResponse();
    nullableDecimal(plan.materialTotal); nullableDecimal(line.lineMeters);
    const measure = record(line.lineMeasurements); nullableDecimal(measure.width); nullableDecimal(measure.height);
    if (![line.route, line.notApplicableStages, line.pieces, plan.pieces].every(Array.isArray)) throw invalidPhysicalResponse();
    (line.route as unknown[]).forEach(text); (line.notApplicableStages as unknown[]).forEach(text);
    const proposal = record(line.proposal); if (!Array.isArray(proposal.evidence)) throw invalidPhysicalResponse();
    proposal.evidence.forEach(v => text(record(v).value));
    (line.pieces as unknown[]).forEach(v => { const p = record(v); text(p.id); number(p.index, 1); number(p.count, 1); });
    (plan.pieces as unknown[]).forEach(v => { const p = record(v); number(p.index, 1); nullableDecimal(p.width); nullableDecimal(p.height); nullableDecimal(p.fabric ?? p.fabricMeters ?? null); });
  }
  return { ...(raw as unknown as ManufacturingPlan), canConfirm: raw.canConfirm === true, allPlansKnown: raw.allPlansKnown === true, partialRework: raw.partialRework === true, pieceRouting: raw.pieceRouting === true };
}
export function mapPhysicalHistory(value: unknown): PhysicalHistory {
  const raw = record(value); text(raw.unitId); text(raw.status); number(raw.reworkCycle, 1);
  const custody = record(raw.custody); number(custody.version);
  if (!Array.isArray(raw.labels) || !Array.isArray(raw.stageLedger) || !Array.isArray(raw.material)) throw invalidPhysicalResponse();
  raw.stageLedger.forEach(v => { const row = record(v); text(row.stageId); text(row.outcome); number(row.reworkCycle, 1); });
  raw.material.forEach(v => { const row = record(v); text(row.id); text(row.kind); if (row.meters !== null && (typeof row.meters !== "string" || !/^-?\d{1,9}\.\d{3}$/.test(row.meters))) throw invalidPhysicalResponse(); });
  return raw as unknown as PhysicalHistory;
}
