import type { ActivityPage, Employee, FaultException, FaultReason, LiveEvent, OrderPage, ProductionWorkflow, StaffOrder } from "../domain/models";
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
    input: { expectedVersion: number; idempotencyKey: string },
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
  reasons(options?: { signal?: AbortSignal }): Promise<FaultReason[]>;
  /** Tailoring intake returns the order to cutting with the exact faulty lines. */
  report(orderId: string, input: { expectedVersion: number; itemIds: string[]; reasonKey: string; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  /** The responsible cutting employee confirms and scans the QR of the same order. */
  acknowledge(id: string, input: { expectedVersion: number; qrToken: string; comment: string | null; idempotencyKey: string }): Promise<FaultException>;
  /** After a rejection: ask for a new decision; the rejection stays in history. */
  rereview(id: string, input: { expectedVersion: number; comment: string; idempotencyKey: string }): Promise<FaultException>;
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
  /** Read-only stage queues for the department dashboards; absent in preview and demo. */
  workspace?: import("../domain/workspaces").WorkspaceApi;
  /** Read-only management production overview (Dashboard access + production.view); absent in preview and demo. */
  management?: import("../domain/workspaces").ManagementApi;
  auth: AuthService;
  employee: EmployeeService;
  orders: OrderService;
  activity: ActivityService;
  workflow: ProductionWorkflowService;
  exceptions: ExceptionService;
  live: LiveService;
  mode: StaffRuntimeMode;
};
