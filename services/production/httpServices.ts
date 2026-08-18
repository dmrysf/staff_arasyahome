import { StaffServiceError } from "../../domain/models";
import type { ActivityService, AuthService, EmployeeService, OrderService, ServiceBundle } from "../contracts";

function configuredRequest(apiBaseUrl: string) {
  return async function request<T>(path: string, init?: RequestInit): Promise<T> {
    if (!apiBaseUrl) throw new StaffServiceError("CONFIGURATION_ERROR");
    if (!navigator.onLine) throw new StaffServiceError("NETWORK_UNAVAILABLE");
    const timeoutSignal = AbortSignal.timeout(12_000);
    const signal = init?.signal ? AbortSignal.any([init.signal, timeoutSignal]) : timeoutSignal;
    let response: Response;
    try {
      response = await fetch(`${apiBaseUrl}${path}`, { credentials: "include", headers: { "Content-Type": "application/json", ...init?.headers }, ...init, signal });
    } catch (error) {
      if (timeoutSignal.aborted) throw new StaffServiceError("REQUEST_TIMEOUT");
      if (!navigator.onLine) throw new StaffServiceError("NETWORK_UNAVAILABLE");
      throw error;
    }
    if (response.status === 401) throw new StaffServiceError("SESSION_EXPIRED");
    if (response.status === 403) throw new StaffServiceError("UNAUTHORIZED_ACTION");
    if (response.status === 409) throw new StaffServiceError("ORDER_CHANGED");
    if (!response.ok) throw new StaffServiceError("SERVER_ERROR");
    return response.json() as Promise<T>;
  };
}

export function createProductionServices(apiBaseUrl: string): ServiceBundle {
  const request = configuredRequest(apiBaseUrl);
  const auth: AuthService = {
    login: (input) => request("/auth/login", { method: "POST", body: JSON.stringify(input) }),
    logout: () => request("/auth/logout", { method: "POST" }),
    getSession: () => request("/auth/session"),
    refreshSession: () => request("/auth/refresh", { method: "POST" }),
  };
  const employee: EmployeeService = { getCurrentEmployee: () => request("/employees/me") };
  const orders: OrderService = {
    resolveQr: (token, options) => request("/orders/resolve-qr", { method: "POST", body: JSON.stringify({ token }), signal: options?.signal }),
    lookup: (code, options) => request(`/orders/lookup?code=${encodeURIComponent(code)}`, { signal: options?.signal }),
    listMine: (options) => request("/orders/mine", { signal: options?.signal }),
    getById: (id, options) => request(`/orders/${encodeURIComponent(id)}`, { signal: options?.signal }),
    confirmStageTransition: (id, input, options) => request(`/orders/${encodeURIComponent(id)}/transition`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: options?.signal }),
  };
  const activity: ActivityService = {
    listMine: (input, options) => request(`/activity/mine?${new URLSearchParams(Object.entries(input).filter(([, value]) => value !== undefined) as string[][])}`, { signal: options?.signal }),
  };
  return { auth, employee, orders, activity, mode: "production" };
}
