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
export type CuttingApi = {
  pool(signal?: AbortSignal): Promise<CuttingPool>;
  targets(signal?: AbortSignal): Promise<{ items: { id: string; name: string }[] }>;
  transfers(signal?: AbortSignal): Promise<{ items: CuttingTransfer[] }>;
  request(orderId: string, input: { expectedVersion: number; targetId: string; reasonKey: string; comment?: string }, key: string): Promise<CuttingTransfer>;
  change(id: string, action: "accept" | "verify", input: { expectedVersion: number; confirmed?: boolean; qrToken?: string; confirmedMultiple?: boolean; ownedCount?: number }, key: string): Promise<CuttingTransfer>;
};
export const transferStatus = { pending: "Așteaptă aprobarea", approved: "Aprobat · așteaptă acceptarea", accepted: "Acceptat · scanează aceeași etichetă", completed: "Transfer finalizat", rejected: "Respins", cancelled: "Anulat de Root" };
