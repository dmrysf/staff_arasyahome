import { StaffServiceError, type Employee, type ServiceErrorCode } from "../../domain/models";
import type { ActivityService, AuthService, EmployeeService, OrderService, ServiceBundle, Session } from "../contracts";

type FetchLike = typeof fetch;
export type ProductionServicesOptions = {
  fetchImpl?: FetchLike;
  isOnline?: () => boolean;
  requestId?: () => string;
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

  async function errorFromResponse(response: Response) {
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
    return new StaffServiceError(backendErrorCodes[backendCode] ?? fallback);
  }

  async function request<T>(path: string, init: RequestInit = {}, map?: (value: unknown) => T): Promise<T> {
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
    let response: Response;
    try {
      response = await fetchImpl(`${apiBaseUrl}${path}`, { ...init, credentials: "include", headers, signal });
    } catch (error) {
      if (timeoutSignal.aborted || (error instanceof DOMException && error.name === "AbortError")) throw new StaffServiceError("REQUEST_TIMEOUT");
      if (!isOnline()) throw new StaffServiceError("NETWORK_UNAVAILABLE");
      throw new StaffServiceError("SERVICE_UNAVAILABLE");
    }
    if (!response.ok) {
      const error = await errorFromResponse(response);
      if ((error.code === "SESSION_EXPIRED" || error.code === "ACCOUNT_INACTIVE") && path !== "/auth/login" && path !== "/auth/session") {
        csrfToken = "";
        onSessionExpired(error);
      }
      throw error;
    }
    let payload: unknown;
    try { payload = await response.json(); }
    catch { throw new StaffServiceError("SERVER_ERROR"); }
    return map ? map(payload) : payload as T;
  }

  return {
    request,
    setCsrf(token: string) { csrfToken = token; },
    clearCsrf() { csrfToken = ""; },
  };
}

export function createProductionServices(apiBaseUrl: string, options: ProductionServicesOptions = {}): ServiceBundle {
  const sessionExpiredHandlers = new Set<(error: StaffServiceError) => void>();
  const http = createRequest(apiBaseUrl, options, (error) => sessionExpiredHandlers.forEach((handler) => handler(error)));
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
    listMine: (requestOptions) => http.request("/orders/mine", { signal: requestOptions?.signal }),
    getById: (id, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}`, { signal: requestOptions?.signal }),
    confirmStageTransition: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/transition`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }),
  };
  const activity: ActivityService = {
    listMine: (input, requestOptions) => http.request(`/activity/mine?${new URLSearchParams(Object.entries(input).filter(([, value]) => value !== undefined) as string[][])}`, { signal: requestOptions?.signal }),
  };
  return { auth, employee, orders, activity, mode: "production" };
}
