import type { ActivityPage, Employee, OrderPage, ProductionWorkflow, StaffOrder } from "../domain/models";
import type { StaffRuntimeMode } from "../src/runtimeConfig";

export type Session = { employee: Employee; expiresAt: string };

export interface AuthService {
  login(input: { username: string; password: string }): Promise<Session>;
  logout(): Promise<void>;
  getSession(): Promise<Session | null>;
  refreshSession(): Promise<Session>;
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
    input: { expectedVersion: number; idempotencyKey: string },
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

export type ServiceBundle = {
  auth: AuthService;
  employee: EmployeeService;
  orders: OrderService;
  activity: ActivityService;
  workflow: ProductionWorkflowService;
  mode: StaffRuntimeMode;
};
