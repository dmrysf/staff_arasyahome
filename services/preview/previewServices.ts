import { StaffServiceError } from "../../domain/models";
import type { ActivityEntry, ActivityPage, StaffOrder } from "../../domain/models";
import { isEmployeeRelevantOrder } from "../../domain/orderRelation";
import { getNextStage, getStageById } from "../../domain/productionWorkflow";
import { previewActivityPages, previewEmployee, previewOrderDatabase } from "../../mocks/previewFixtures";
import { previewProductionWorkflow } from "../../mocks/productionWorkflow";
import type { AuthService, EmployeeService, OrderService, ServiceBundle, Session } from "../contracts";

export const PREVIEW_SESSION_KEY = "arasya_staff_preview_session";
const PREVIEW_SESSION_DURATION_MS = 8 * 60 * 60 * 1000;

export type PreviewSessionStorage = Pick<Storage, "getItem" | "setItem" | "removeItem">;
export type PreviewServicesOptions = {
  storage?: PreviewSessionStorage;
  now?: () => number;
};

const clone = <T,>(value: T): T => structuredClone(value);

function browserSessionStorage(): PreviewSessionStorage | undefined {
  try { return globalThis.sessionStorage; }
  catch { return undefined; }
}

export function createPreviewServices(options: PreviewServicesOptions = {}): ServiceBundle {
  const storage = options.storage ?? browserSessionStorage();
  const now = options.now ?? Date.now;
  let memorySession: Session | null = null;
  let orders = clone(previewOrderDatabase);
  let activityPages = clone(previewActivityPages);
  const idempotentTransitions = new Map<string, { orderId: string; result: StaffOrder }>();

  function removeSessionMarker() {
    try { storage?.removeItem(PREVIEW_SESSION_KEY); }
    catch { /* Preview continues in memory if browser storage is unavailable. */ }
  }

  function persistExpiry(expiresAt: number) {
    try { storage?.setItem(PREVIEW_SESSION_KEY, String(expiresAt)); }
    catch { /* Preview continues in memory if browser storage is unavailable. */ }
  }

  function resetOperationalState() {
    orders = clone(previewOrderDatabase);
    activityPages = clone(previewActivityPages);
    idempotentTransitions.clear();
  }

  function createSession(expiresAt: number): Session {
    return { employee: clone(previewEmployee), expiresAt: new Date(expiresAt).toISOString() };
  }

  function clearPreviewSession() {
    memorySession = null;
    removeSessionMarker();
  }

  function readPersistedSession(): Session | null {
    let rawExpiry: string | null = null;
    try { rawExpiry = storage?.getItem(PREVIEW_SESSION_KEY) ?? null; }
    catch { return null; }
    if (!rawExpiry) return null;
    const expiry = Number(rawExpiry);
    if (!Number.isFinite(expiry) || expiry <= now()) {
      clearPreviewSession();
      return null;
    }
    memorySession = createSession(expiry);
    return clone(memorySession);
  }

  const auth: AuthService = {
    async login(input) {
      if (input.username !== "demo" || input.password !== "demo") throw new StaffServiceError("UNAUTHORIZED_ACTION");
      const expiry = now() + PREVIEW_SESSION_DURATION_MS;
      memorySession = createSession(expiry);
      persistExpiry(expiry);
      return clone(memorySession);
    },
    async logout() {
      clearPreviewSession();
      resetOperationalState();
    },
    async getSession() {
      if (!memorySession) return readPersistedSession();
      if (new Date(memorySession.expiresAt).getTime() <= now()) {
        clearPreviewSession();
        return null;
      }
      return clone(memorySession);
    },
    async refreshSession() {
      const current = await auth.getSession();
      if (!current) throw new StaffServiceError("SESSION_EXPIRED");
      const expiry = now() + PREVIEW_SESSION_DURATION_MS;
      memorySession = createSession(expiry);
      persistExpiry(expiry);
      return clone(memorySession);
    },
    onSessionExpired() { return () => undefined; },
  };

  function resolvePreviewCode(rawCode: string) {
    const code = rawCode.trim().toLowerCase();
    if (!code || code === "invalid") throw new StaffServiceError("INVALID_QR");
    if (code === "expired") throw new StaffServiceError("EXPIRED_QR");
    if (code === "cancelled") throw new StaffServiceError("ORDER_UNAVAILABLE");
    if (code === "session-expired") {
      clearPreviewSession();
      throw new StaffServiceError("SESSION_EXPIRED");
    }
    const matched = orders.find((order) =>
      [order.id, order.orderNumber.toLowerCase(), `arasya:${order.orderNumber.toLowerCase()}`].includes(code),
    );
    if (!matched) throw new StaffServiceError("ORDER_NOT_FOUND");
    return clone(matched);
  }

  function recordTransition(previous: StaffOrder, updated: StaffOrder) {
    const fromStage = getStageById(previewProductionWorkflow, previous.productionStageId);
    const toStage = getStageById(previewProductionWorkflow, updated.productionStageId);
    if (!fromStage || !toStage) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
    const entry: ActivityEntry = {
      id: `preview-transition-${updated.id}-${updated.version}`,
      occurredAt: updated.updatedAt,
      orderId: updated.id,
      orderNumber: updated.orderNumber,
      source: updated.source,
      fromStageId: fromStage.id,
      fromStageLabelSnapshot: fromStage.label,
      toStageId: toStage.id,
      toStageLabelSnapshot: toStage.label,
      meters: updated.products.reduce((total, item) => total + (item.meters ?? 0), 0),
    };
    for (const range of ["today", "7days", "month", "custom"] as const) {
      const page = activityPages[range];
      const handedOver = updated.status === "handed_over" && previous.status !== "handed_over";
      page.items.unshift(entry);
      page.summary.processed += 1;
      page.summary.meters += entry.meters ?? 0;
      if (handedOver) {
        page.summary.handedOver += 1;
        page.summary.inProgress = Math.max(0, page.summary.inProgress - 1);
      }
    }
  }

  const orderService: OrderService = {
    async resolveQr(token) { return resolvePreviewCode(token); },
    async lookup(code) { return resolvePreviewCode(code); },
    async listMine(options) { 
      const filtered = orders.filter((order) => isEmployeeRelevantOrder(order, previewEmployee.employeeUuid));
      const limit = options?.limit ?? 50;
      let start = 0;
      if (options?.cursor) {
        try {
          start = parseInt(atob(options.cursor), 10);
          if (isNaN(start)) throw new Error();
        } catch {
          throw new StaffServiceError("SERVER_ERROR");
        }
      }
      const items = clone(filtered.slice(start, start + limit));
      const nextCursor = start + limit < filtered.length ? btoa(String(start + limit)) : undefined;
      return { items, nextCursor };
    },
    async getById(id) {
      const order = orders.find((item) => item.id === id);
      if (!order) throw new StaffServiceError("ORDER_NOT_FOUND");
      return clone(order);
    },
    async confirmStageTransition(orderId, input) {
      const priorResult = idempotentTransitions.get(input.idempotencyKey);
      if (priorResult) {
        if (priorResult.orderId !== orderId) throw new StaffServiceError("UNAUTHORIZED_ACTION");
        return clone(priorResult.result);
      }
      const index = orders.findIndex((item) => item.id === orderId);
      if (index < 0) throw new StaffServiceError("ORDER_NOT_FOUND");
      const current = orders[index];
      if (current.version !== input.expectedVersion) throw new StaffServiceError("ORDER_CHANGED");
      const nextStage = getNextStage(previewProductionWorkflow, current.productionStageId);
      if (!nextStage || !current.employeeAllowedAction) throw new StaffServiceError("UNAUTHORIZED_ACTION");
      const updated: StaffOrder = {
        ...current,
        productionStageId: nextStage.id,
        employeeAllowedAction: undefined,
        employeeRelation: {
          employeeUuid: previewEmployee.employeeUuid,
          type: current.employeeAllowedAction.id === "handover" ? "handover_out" : "claimed",
          lastActionAt: new Date(now()).toISOString(),
        },
        acceptedAt: current.acceptedAt ?? new Date(now()).toISOString(),
        updatedAt: new Date(now()).toISOString(),
        status: current.employeeAllowedAction.id === "handover" ? "handed_over" : current.status,
        version: current.version + 1,
      };
      orders[index] = updated;
      idempotentTransitions.set(input.idempotencyKey, { orderId, result: clone(updated) });
      recordTransition(current, updated);
      return clone(updated);
    },
  };

  const employee: EmployeeService = {
    async getCurrentEmployee() {
      const session = await auth.getSession();
      if (!session) throw new StaffServiceError("SESSION_EXPIRED");
      return clone(session.employee);
    },
  };

  return {
    auth,
    employee,
    orders: orderService,
    activity: { async listMine(input): Promise<ActivityPage> { return clone(activityPages[input.range]); } },
    workflow: { async getCurrent() { return previewProductionWorkflow; } },
    mode: "preview",
  };
}
