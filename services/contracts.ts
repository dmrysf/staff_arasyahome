import type { ActivityPage, Employee, FaultException, FaultReason, LiveEvent, OrderPage, ProductionWorkflow, QualityCapabilities, StaffOrder, SupervisorView } from "../domain/models";
import type { StaffRuntimeMode } from "../src/runtimeConfig";

export type Session = { employee: Employee; expiresAt: string };

export interface AuthService {
  login(input: { username: string; password: string }): Promise<Session>;
  logout(): Promise<void>;
  getSession(): Promise<Session | null>;
  refreshSession(): Promise<Session>;
  /** Replaces the password (also the forced first-login change); the server issues a fresh session. */
  changePassword(input: { currentPassword: string; newPassword: string }): Promise<Session>;
  onSessionExpired(handler: (error: import("../domain/models").StaffServiceError) => void): () => void;
}

export interface EmployeeService {
  getCurrentEmployee(): Promise<Employee>;
}

export interface ProductionWorkflowService {
  getCurrent(options?: { signal?: AbortSignal }): Promise<ProductionWorkflow>;
}

export interface OrderService {
  resolveQr(token: string, options?: { signal?: AbortSignal }): Promise<StaffOrder>;
  lookup(code: string, options?: { signal?: AbortSignal }): Promise<StaffOrder>;
  /** Returns only orders with a direct relationship to the authenticated employee. Production enforcement belongs to the server. */
  listMine(options?: { cursor?: string; limit?: number; signal?: AbortSignal }): Promise<OrderPage>;
  getById(id: string, options?: { signal?: AbortSignal }): Promise<StaffOrder>;
  /** Claims the order at its current stage. The server decides eligibility. */
  claim(
    orderId: string,
    input: { expectedVersion: number; idempotencyKey: string; qrToken?: string; confirmedMultiple?: boolean; ownedCount?: number },
    options?: { signal?: AbortSignal },
  ): Promise<StaffOrder>;
  /** Completes the current stage; the server alone chooses the next canonical stage. */
  confirmStageTransition(
    orderId: string,
    input: { expectedVersion: number; idempotencyKey: string; defectResolved?: true; pieceOutcomes?: import("../domain/pieces").PieceOutcome[] },
    options?: { signal?: AbortSignal },
  ): Promise<StaffOrder>;
}

export interface ActivityService {
  listMine(input: { range: "today" | "7days" | "month" | "custom"; cursor?: string; from?: string; to?: string }, options?: { signal?: AbortSignal }): Promise<ActivityPage>;
}

/** Cutting fault return requests. Every mutation needs server confirmation; nothing is queued offline. */
export interface ExceptionService {
  listMine(options?: { signal?: AbortSignal }): Promise<FaultException[]>;
  get(id: string, options?: { signal?: AbortSignal }): Promise<FaultException>;
  /**
   * The universal scanner's first question (API 2.34.1): the open requests the scanned code concerns that involve the
   * signed-in employee, with the server-granted actions. Read-only. null: the API predates the lookup.
   */
  scan(token: string, options?: { signal?: AbortSignal }): Promise<FaultException[] | null>;
  reasons(options?: { signal?: AbortSignal }): Promise<FaultReason[]>;
  /** Tailoring intake returns the order to cutting with the exact faulty lines. */
  /** units (API 2.31.0): the exact defective pieces, optionally on a piece track (expectedVersion is then the track's). */
  report(orderId: string, input: { expectedVersion: number; itemIds: string[]; units?: string[]; trackId?: string | null; recoversExceptionId?: string; reasonKey: string; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  /**
   * The addressed cutting employee scans the QR of the same order. In the supervised flow this records
   * physical receipt; only the responsible employee adds "accepted" or "disputed", never as an admission.
   */
  acknowledge(id: string, input: { expectedVersion: number; qrToken: string; comment: string | null; responsibility?: "accepted" | "disputed" | null; idempotencyKey: string }): Promise<FaultException>;
  /** After a rejection: ask for a new decision; the rejection stays in history. */
  rereview(id: string, input: { expectedVersion: number; comment: string; idempotencyKey: string }): Promise<FaultException>;
  capabilities(options?: { signal?: AbortSignal }): Promise<QualityCapabilities>;
  /** Tailoring quality supervisor tabs. */
  supervisorList(view: SupervisorView, options?: { signal?: AbortSignal }): Promise<FaultException[]>;
  /** Available cutting employees when the responsible one cannot receive the product. */
  receivers(id: string, options?: { signal?: AbortSignal }): Promise<{ id: string; displayName: string }[]>;
  /** Supervisor accepts (with the product's QR and the confirmed reason) or dismisses a report. No manager. */
  review(id: string, input: { expectedVersion: number; decision: "accept" | "dismiss"; reasonKey?: string; qrToken?: string; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  /** Supervisor sends the case to the cutting employee; the managers decide only after physical receipt. */
  sendReturn(id: string, input: { expectedVersion: number; receiverEmployeeId?: string | null; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  /** Tailoring employees who can receive the product back when the employee who had the order cannot (supervisor only). */
  restoreRecipients(id: string, options?: { signal?: AbortSignal }): Promise<{ id: string; displayName: string }[]>;
  /** Supervisor hands the product back to tailoring with it in hand (QR). The order stays blocked until the recipient confirms. */
  /** cancelReturn: the supervisor deliberately withdraws a return to cutting the cutting employee has not received yet. */
  restore(id: string, input: { expectedVersion: number; qrToken: string; comment: string; recipientEmployeeId?: string | null; cancelReturn?: true; idempotencyKey: string }): Promise<FaultException>;
  /** The addressed tailoring employee received the product (QR); only this unblocks the order, owned by them. No manager. */
  receiveRestore(id: string, input: { expectedVersion: number; qrToken: string; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  addNote(id: string, input: { expectedVersion: number; comment: string; idempotencyKey: string }): Promise<FaultException>;
}

/** Authenticated live updates (server-sent events). The handler receives each event exactly once. */
export interface LiveService {
  subscribe(handler: (event: LiveEvent) => void, onState?: (state: "connected" | "reconnecting") => void): () => void;
}

export type ServiceBundle = {
  cutting?: import("../domain/cutting").CuttingApi;
  /** Central production documents (generation, revision requests, prints); absent in preview and demo. */
  documents?: import("../domain/documents").DocumentApi;
  /** Manager-only production authority takeover; absent in preview and demo. */
  authority?: import("../domain/authority").AuthorityApi;
  /** Manager-only production QR authority (view, preview, rotation); absent in preview and demo. */
  productionQr?: import("../domain/productionQr").ProductionQrApi;
  /** Trendyol workspace for explicitly authorized Trendyol personnel; absent in preview and demo. */
  trendyol?: import("../domain/trendyol").TrendyolApi;
  /** Work on pieces in rework (API 2.31.0); absent in preview and demo. */
  physical?: import("../domain/physical").StaffPhysicalApi;
  pieces?: import("../domain/pieces").PieceApi;
  /** Read-only stage queues for the department dashboards; absent in preview and demo. */
  workspace?: import("../domain/workspaces").WorkspaceApi;
  /** Read-only management production overview (Dashboard access + production.view); absent in preview and demo. */
  management?: import("../domain/workspaces").ManagementApi;
  /** Confirmare workspace (API 2.39.0) for people holding a Confirmare scope; absent in preview and demo. */
  confirmare?: import("../domain/confirmare").ConfirmareApi;
  auth: AuthService;
  employee: EmployeeService;
  orders: OrderService;
  activity: ActivityService;
  workflow: ProductionWorkflowService;
  exceptions: ExceptionService;
  live: LiveService;
  mode: StaffRuntimeMode;
};
