import { liveLabels, liveRecords, tKnown } from "../i18n";
export type CuttingTransfer = {
  id: string; status: "pending" | "approved" | "accepted" | "completed" | "rejected" | "cancelled"; version: number;
  order: { id: string; orderNumber: string; source: string; productionVersion: number };
  from: { id: string; name: string }; to: { id: string; name: string };
  reason: { key: string; label: string; comment: string | null };
  requestedAt: string; decidedAt: string | null; acceptedAt: string | null; qrVerifiedAt: string | null; resolvedAt: string | null;
  decisionComment: string | null; decidedBy: string | null;
  actions: { canDecide: boolean; canCancel: boolean; canAccept: boolean; canVerify: boolean };
};
export type CuttingPool = { total: number; ownedCount: number; items: { id: string; orderNumber: string; source: string; productionVersion: number }[] };
/** Server result of one QR cutting intake: received from stage 1, claimed in cutting, or already the employee's own job. */
export type CuttingIntakeOutcome = "started" | "claimed" | "already_mine";
export type CuttingIntakeResult = { outcome: CuttingIntakeOutcome; order: import("./models").StaffOrder };
export type CuttingIntakeInput = { qrToken: string; confirmedMultiple?: boolean; ownedCount?: number };
export type CuttingApi = {
  pool(signal?: AbortSignal): Promise<CuttingPool>;
  /** One scan receives the order into cutting (POST /cutting/intake); the server decides and commits atomically. */
  intake(input: CuttingIntakeInput, key: string, signal?: AbortSignal): Promise<CuttingIntakeResult>;
  targets(signal?: AbortSignal): Promise<{ items: { id: string; name: string }[] }>;
  transfers(signal?: AbortSignal): Promise<{ items: CuttingTransfer[] }>;
  request(orderId: string, input: { expectedVersion: number; targetId: string; reasonKey: string; comment?: string }, key: string): Promise<CuttingTransfer>;
  change(id: string, action: "accept" | "verify", input: { expectedVersion: number; confirmed?: boolean; qrToken?: string; confirmedMultiple?: boolean; ownedCount?: number }, key: string): Promise<CuttingTransfer>;
};
export const transferStatus: Readonly<Record<CuttingTransfer["status"], string>> = liveLabels("transferStatus.", ["pending", "approved", "accepted", "completed", "rejected", "cancelled"]);
/** Transfer reasons by key; an unknown key shows the server's own label. */
export const transferReasonLabel = (key: string, fallback: string): string => tKnown("transferReason", key, fallback);

/**
 * Presentation mirror of the server's cutting eligibility (CuttingLifecycle::eligible plus orders.scan): only such
 * employees send a scanned QR to the cutting intake first. It never grants anything; the server decides again.
 */
export function canUseCuttingIntake(employee: import("./models").Employee): boolean {
  return employee.status === "active" && !employee.isRoot && !employee.mustChangePassword && employee.applications.includes("staff")
    && ["pregatire-material", "taiere"].includes(employee.departmentKey ?? "") && employee.allowedStageIds.includes("material-preparation")
    && ["orders.scan", "orders.claim", "orders.advance_stage"].every((permission) => employee.permissions.includes(permission));
}

const intakeCopy = liveRecords("cutting.intake.", ["started", "already_mine"], ["eyebrow", "title", "text"]);
/** `started` and `claimed` read the same to the employee: the order is now theirs and cutting has begun. */
export const cuttingIntakeCopy: Readonly<Record<CuttingIntakeOutcome, Readonly<{ eyebrow: string; title: string; text: string }>>> = Object.freeze({
  started: intakeCopy.started,
  claimed: intakeCopy.started,
  already_mine: intakeCopy.already_mine,
});
