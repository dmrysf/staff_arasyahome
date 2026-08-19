import { StaffServiceError, type Employee, type ServiceErrorCode } from "../../domain/models";
import type { ActivityService, AuthService, EmployeeService, OrderService, ServiceBundle, Session } from "../contracts";
import { createBrowserWorkflowCache, createUnavailableWorkflowCache, normalizeProductionApiBaseUrl, type WorkflowCache } from "./workflowCache";
import { createProductionWorkflowService } from "./workflowService";

type FetchLike = typeof fetch;
export type ProductionServicesOptions = {
  fetchImpl?: FetchLike;
  isOnline?: () => boolean;
  requestId?: () => string;
  workflowCache?: WorkflowCache;
};

const backendErrorCodes: Partial<Record<string, ServiceErrorCode>> = {
  INVALID_CREDENTIALS: "INVALID_CREDENTIALS",
  ACCOUNT_INACTIVE: "ACCOUNT_INACTIVE",
  RATE_LIMITED: "RATE_LIMITED",
  SERVICE_UNAVAILABLE: "SERVICE_UNAVAILABLE",
  NO_SESSION: "NO_SESSION",
  SESSION_EXPIRED: "SESSION_EXPIRED",
  UNAUTHORIZED_ACTION: "UNAUTHORIZED_ACTION",
  CSRF_INVALID: "CSRF_INVALID",
  CONFIGURATION_ERROR: "CONFIGURATION_ERROR",
  WORKFLOW_UNAVAILABLE: "WORKFLOW_UNAVAILABLE",
  ORDER_CHANGED: "ORDER_CHANGED",
  ORDER_NOT_FOUND: "ORDER_NOT_FOUND",
};

function objectValue(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new StaffServiceError("SERVER_ERROR");
  return value as Record<string, unknown>;
}

function stringValue(value: unknown) {
  if (typeof value !== "string" || !value) throw new StaffServiceError("SERVER_ERROR");
  return value;
}

function stringList(value: unknown) {
  if (!Array.isArray(value) || value.some((item) => typeof item !== "string")) throw new StaffServiceError("SERVER_ERROR");
  return value as string[];
}

function employeeStatus(value: unknown): Employee["status"] {
  if (value === "active" || value === "inactive" || value === "suspended") return value;
  throw new StaffServiceError("SERVER_ERROR");
}

export function mapProductionEmployee(value: unknown): Employee {
  const raw = objectValue(value);
  return {
    employeeUuid: stringValue(raw.employeeUuid),
    employeeCode: raw.employeeCode == null ? undefined : stringValue(raw.employeeCode),
    displayName: stringValue(raw.displayName),
    username: stringValue(raw.username),
    department: stringValue(raw.department),
    departmentKey: raw.departmentKey == null ? undefined : stringValue(raw.departmentKey),
    role: stringValue(raw.role),
    status: employeeStatus(raw.status),
    permissions: stringList(raw.permissions),
    allowedStageIds: stringList(raw.allowedStageIds),
    locale: "ro",
  };
}

export function mapProductionOrderItem(value: unknown): import("../../domain/models").ProductionItem {
  const raw = objectValue(value);
  const item: import("../../domain/models").ProductionItem = {
    id: stringValue(raw.id),
    name: stringValue(raw.name),
    quantity: typeof raw.quantity === "number" ? raw.quantity : 0,
  };
  if (raw.code != null) item.code = stringValue(raw.code);
  if (raw.color != null) item.color = stringValue(raw.color);
  if (raw.variant != null) item.variant = stringValue(raw.variant);
  if (raw.meters != null && typeof raw.meters === "number") item.meters = raw.meters;
  if (raw.measurements != null) {
    const rawMeas = objectValue(raw.measurements);
    item.measurements = {};
    if (typeof rawMeas.width === "number") item.measurements.width = rawMeas.width;
    if (typeof rawMeas.height === "number") item.measurements.height = rawMeas.height;
    if (typeof rawMeas.unit === "string" && ["mm", "cm", "m"].includes(rawMeas.unit)) {
      item.measurements.unit = rawMeas.unit as "mm" | "cm" | "m";
    }
  }
  return item;
}

export function mapProductionOrder(value: unknown): import("../../domain/models").StaffOrder {
  const raw = objectValue(value);
  const source = stringValue(raw.source);
  const status = stringValue(raw.status);
  
  const mappedSource = ["trendhome", "outletperdele", "trendyol", "b2b", "marketplace"].includes(source)
    ? (source as import("../../domain/models").OrderSource)
    : "unknown";
    
  const mappedStatus = ["in_progress", "handed_over", "unavailable"].includes(status)
    ? (status as "in_progress" | "handed_over" | "unavailable")
    : "unavailable";

  const order: import("../../domain/models").StaffOrder = {
    id: stringValue(raw.id),
    source: mappedSource,
    orderNumber: stringValue(raw.orderNumber),
    productionStageId: stringValue(raw.productionStageId),
    products: Array.isArray(raw.products) ? raw.products.map(mapProductionOrderItem) : [],
    status: mappedStatus,
    updatedAt: stringValue(raw.updatedAt),
    version: typeof raw.version === "number" ? raw.version : 1,
  };

  if (raw.sourceCommerceStatus != null) {
    const scs = objectValue(raw.sourceCommerceStatus);
    order.sourceCommerceStatus = { code: stringValue(scs.code), label: stringValue(scs.label) };
  }
  if (raw.productionNotes != null) order.productionNotes = stringValue(raw.productionNotes);
  if (raw.acceptedAt != null) order.acceptedAt = stringValue(raw.acceptedAt);

  if (raw.employeeRelation != null) {
    const rel = objectValue(raw.employeeRelation);
    const relType = stringValue(rel.type);
    order.employeeRelation = {
      employeeUuid: stringValue(rel.employeeUuid),
      type: ["claimed", "assigned", "updated", "handover_in", "handover_out", "completed"].includes(relType) 
        ? (relType as import("../../domain/models").EmployeeOrderRelationType) 
        : "updated",
      lastActionAt: stringValue(rel.lastActionAt),
    };
  }

  if (raw.freshness != null) {
    const fresh = objectValue(raw.freshness);
    const freshStatus = stringValue(fresh.status);
    order.freshness = {
      status: ["fresh", "stale", "source_unavailable"].includes(freshStatus) 
        ? (freshStatus as "fresh" | "stale" | "source_unavailable") 
        : "source_unavailable",
      sourceChangedAt: stringValue(fresh.sourceChangedAt),
      lastSourceSeenAt: stringValue(fresh.lastSourceSeenAt),
    };
  }

  return order;
}

export function mapOrderPage(value: unknown): import("../../domain/models").OrderPage {
  const raw = objectValue(value);
  return {
    items: Array.isArray(raw.items) ? raw.items.map(mapProductionOrder) : [],
    nextCursor: raw.nextCursor != null ? stringValue(raw.nextCursor) : undefined,
  };
}

type ProductionAuthPayload = Session & { csrfToken: string };

export function mapProductionSession(value: unknown): ProductionAuthPayload {
  const raw = objectValue(value);
  return {
    employee: mapProductionEmployee(raw.employee),
    expiresAt: stringValue(raw.expiresAt),
    csrfToken: stringValue(raw.csrfToken),
  };
}

function createRequest(apiBaseUrl: string, options: ProductionServicesOptions, onSessionExpired: (error: StaffServiceError) => void) {
  const fetchImpl = options.fetchImpl ?? fetch;
  const isOnline = options.isOnline ?? (() => typeof navigator === "undefined" || navigator.onLine);
  const nextRequestId = options.requestId ?? (() => globalThis.crypto?.randomUUID?.() ?? `staff-${Date.now()}`);
  let csrfToken = "";

  async function errorFromResponse(response: Response, path: string) {
    let backendCode = "";
    try {
      const payload = objectValue(await response.json());
      const error = objectValue(payload.error);
      backendCode = typeof error.code === "string" ? error.code : "";
    } catch { /* HTTP status fallback remains typed below. */ }
    const fallback: ServiceErrorCode = response.status === 401
      ? "SESSION_EXPIRED"
      : response.status === 403
        ? "UNAUTHORIZED_ACTION"
        : response.status === 409
          ? "ORDER_CHANGED"
          : response.status === 429
            ? "RATE_LIMITED"
            : response.status === 503
              ? "SERVICE_UNAVAILABLE"
              : "SERVER_ERROR";
    const error = new StaffServiceError(backendErrorCodes[backendCode] ?? fallback);
    if (["SESSION_EXPIRED", "NO_SESSION", "ACCOUNT_INACTIVE"].includes(error.code) && path !== "/auth/login" && path !== "/auth/session") {
      csrfToken = "";
      onSessionExpired(error);
    }
    return error;
  }

  async function send(path: string, init: RequestInit = {}): Promise<Response> {
    if (!apiBaseUrl) throw new StaffServiceError("CONFIGURATION_ERROR");
    if (!isOnline()) throw new StaffServiceError("NETWORK_UNAVAILABLE");
    const timeoutSignal = AbortSignal.timeout(12_000);
    const signal = init.signal ? AbortSignal.any([init.signal, timeoutSignal]) : timeoutSignal;
    const method = (init.method ?? "GET").toUpperCase();
    const mutating = !["GET", "HEAD", "OPTIONS"].includes(method);
    const headers = new Headers(init.headers);
    headers.set("Accept", "application/json");
    headers.set("X-Request-ID", nextRequestId());
    if (init.body != null) headers.set("Content-Type", "application/json");
    if (mutating && path !== "/auth/login" && csrfToken) headers.set("X-CSRF-Token", csrfToken);
    try {
      return await fetchImpl(`${apiBaseUrl}${path}`, { ...init, credentials: "include", headers, signal });
    } catch (error) {
      if (init.signal?.aborted) throw error;
      if (timeoutSignal.aborted || (error instanceof DOMException && error.name === "AbortError")) throw new StaffServiceError("REQUEST_TIMEOUT");
      if (!isOnline()) throw new StaffServiceError("NETWORK_UNAVAILABLE");
      throw new StaffServiceError("SERVICE_UNAVAILABLE");
    }
  }

  async function request<T>(path: string, init: RequestInit = {}, map?: (value: unknown) => T): Promise<T> {
    const response = await send(path, init);
    if (!response.ok) {
      throw await errorFromResponse(response, path);
    }
    let payload: unknown;
    try { payload = await response.json(); }
    catch { throw new StaffServiceError("SERVER_ERROR"); }
    return map ? map(payload) : payload as T;
  }

  return {
    request,
    send,
    errorFromResponse,
    setCsrf(token: string) { csrfToken = token; },
    clearCsrf() { csrfToken = ""; },
  };
}

export function createProductionServices(apiBaseUrl: string, options: ProductionServicesOptions = {}): ServiceBundle {
  const normalizedApiBaseUrl = normalizeProductionApiBaseUrl(apiBaseUrl) ?? "";
  const sessionExpiredHandlers = new Set<(error: StaffServiceError) => void>();
  const http = createRequest(normalizedApiBaseUrl, options, (error) => sessionExpiredHandlers.forEach((handler) => handler(error)));
  const auth: AuthService = {
    async login(input) {
      const payload = await http.request("/auth/login", { method: "POST", body: JSON.stringify(input) }, mapProductionSession);
      http.setCsrf(payload.csrfToken);
      return { employee: payload.employee, expiresAt: payload.expiresAt };
    },
    async logout() {
      try {
        await http.request<{ ok: boolean }>("/auth/logout", { method: "POST" });
        http.clearCsrf();
      } catch (error) {
        if (error instanceof StaffServiceError && ["SESSION_EXPIRED", "ACCOUNT_INACTIVE"].includes(error.code)) http.clearCsrf();
        throw error;
      }
    },
    async getSession() {
      try {
        const payload = await http.request("/auth/session", {}, mapProductionSession);
        http.setCsrf(payload.csrfToken);
        return { employee: payload.employee, expiresAt: payload.expiresAt };
      } catch (error) {
        if (error instanceof StaffServiceError && error.code === "NO_SESSION") {
          http.clearCsrf();
          return null;
        }
        throw error;
      }
    },
    async refreshSession() {
      const payload = await http.request("/auth/refresh", { method: "POST" }, mapProductionSession);
      http.setCsrf(payload.csrfToken);
      return { employee: payload.employee, expiresAt: payload.expiresAt };
    },
    onSessionExpired(handler) {
      sessionExpiredHandlers.add(handler);
      return () => sessionExpiredHandlers.delete(handler);
    },
  };
  const employee: EmployeeService = { getCurrentEmployee: () => http.request("/employees/me", {}, mapProductionEmployee) };
  const orders: OrderService = {
    resolveQr: (token, requestOptions) => http.request("/orders/resolve-qr", { method: "POST", body: JSON.stringify({ token }), signal: requestOptions?.signal }),
    lookup: (code, requestOptions) => http.request(`/orders/lookup?code=${encodeURIComponent(code)}`, { signal: requestOptions?.signal }),
    listMine: (requestOptions) => {
      let q = "";
      if (requestOptions?.cursor) q += `?cursor=${encodeURIComponent(requestOptions.cursor)}`;
      if (requestOptions?.limit) q += (q ? "&" : "?") + `limit=${requestOptions.limit}`;
      return http.request(`/orders/mine${q}`, { signal: requestOptions?.signal }, mapOrderPage);
    },
    getById: (id, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}`, { signal: requestOptions?.signal }, mapProductionOrder),
    confirmStageTransition: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/transition`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }),
  };
  const activity: ActivityService = {
    listMine: (input, requestOptions) => http.request(`/activity/mine?${new URLSearchParams(Object.entries(input).filter(([, value]) => value !== undefined) as string[][])}`, { signal: requestOptions?.signal }),
  };
  const workflow = createProductionWorkflowService({
    get: (etag, signal) => http.send("/production/workflow", { signal, headers: etag ? { "If-None-Match": etag } : undefined }),
    failure: (response) => http.errorFromResponse(response, "/production/workflow"),
  }, options.workflowCache ?? (normalizedApiBaseUrl ? createBrowserWorkflowCache(normalizedApiBaseUrl) : createUnavailableWorkflowCache()));
  return { auth, employee, orders, activity, workflow, mode: "production" };
}
