import { StaffServiceError } from "../../domain/models";
import { isEmployeeRelevantOrder } from "../../domain/orderRelation";
import { getNextStage } from "../../domain/productionWorkflow";
import { demoActivities, demoEmployee, demoOrders } from "../../mocks/fixtures";
import { previewProductionWorkflow } from "../../mocks/productionWorkflow";
import type { ActivityService, AuthService, EmployeeService, OrderService, ServiceBundle, Session } from "../contracts";

const clone = <T,>(value: T): T => structuredClone(value);

let memorySession: Session | null = null;
let orders = clone(demoOrders);

const auth: AuthService = {
  async login(input) {
    if (!input.username.trim() || !input.password) throw new StaffServiceError("UNAUTHORIZED_ACTION");
    memorySession = { employee: { ...demoEmployee, username: input.username.trim() }, expiresAt: new Date(Date.now() + 3_600_000).toISOString() };
    return clone(memorySession);
  },
  async logout() { memorySession = null; },
  async getSession() { return clone(memorySession); },
  async refreshSession() {
    if (!memorySession) throw new StaffServiceError("SESSION_EXPIRED");
    memorySession.expiresAt = new Date(Date.now() + 3_600_000).toISOString();
    return clone(memorySession);
  },
  onSessionExpired() { return () => undefined; },
};

function resolveDemoCode(rawCode: string) {
  const code = rawCode.trim().toLowerCase();
  if (!code || code === "invalid") throw new StaffServiceError("INVALID_QR");
  if (code === "expired") throw new StaffServiceError("EXPIRED_QR");
  if (code === "cancelled") throw new StaffServiceError("ORDER_UNAVAILABLE");
  if (code === "session-expired") throw new StaffServiceError("SESSION_EXPIRED");
  const matched = orders.find((order) =>
    [order.id, order.orderNumber.toLowerCase(), `arasya:${order.orderNumber.toLowerCase()}`].includes(code),
  );
  if (!matched) throw new StaffServiceError("ORDER_NOT_FOUND");
  return clone(matched);
}

const orderService: OrderService = {
  async resolveQr(token) { return resolveDemoCode(token); },
  async lookup(code) { return resolveDemoCode(code); },
  async listMine() { return clone(orders.filter((order) => isEmployeeRelevantOrder(order, demoEmployee.employeeUuid))); },
  async getById(id) {
    const order = orders.find((item) => item.id === id);
    if (!order) throw new StaffServiceError("ORDER_NOT_FOUND");
    return clone(order);
  },
  async confirmStageTransition(orderId, input) {
    const index = orders.findIndex((item) => item.id === orderId);
    if (index < 0) throw new StaffServiceError("ORDER_NOT_FOUND");
    const current = orders[index];
    if (current.version !== input.expectedVersion) throw new StaffServiceError("ORDER_CHANGED");
    const nextStage = getNextStage(previewProductionWorkflow, current.productionStageId);
    if (!nextStage || !current.employeeAllowedAction) throw new StaffServiceError("UNAUTHORIZED_ACTION");
    const updated = {
      ...current,
      productionStageId: nextStage.id,
      employeeAllowedAction: undefined,
      employeeRelation: {
        employeeUuid: demoEmployee.employeeUuid,
        type: current.employeeAllowedAction.id === "handover" ? "handover_out" as const : "claimed" as const,
        lastActionAt: new Date().toISOString(),
      },
      acceptedAt: current.acceptedAt ?? new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      version: current.version + 1,
    };
    orders[index] = updated;
    return clone(updated);
  },
};

const employee: EmployeeService = {
  async getCurrentEmployee() {
    if (!memorySession) throw new StaffServiceError("SESSION_EXPIRED");
    return clone(memorySession.employee);
  },
};

const activity: ActivityService = {
  async listMine() {
    return {
      items: clone(demoActivities),
      summary: { processed: 12, meters: 37.6, handedOver: 7, inProgress: 6 },
    };
  },
};

export function createDemoServices(): ServiceBundle {
  orders = clone(demoOrders);
  return { auth, employee, orders: orderService, activity, workflow: { async getCurrent() { return previewProductionWorkflow; } }, mode: "demo" };
}
