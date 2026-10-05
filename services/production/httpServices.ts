import { StaffServiceError, type ActivityAction, type ActivityEntry, type ActivityPage, type Employee, type ServiceErrorCode } from "../../domain/models";
import { isOrderActionBlockedReason, isOrderActionId, orderActionLabels } from "../../domain/orderActions";
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
  ORDER_UNAVAILABLE: "ORDER_UNAVAILABLE",
  ORDER_ALREADY_CLAIMED: "ORDER_ALREADY_CLAIMED",
  ORDER_AMBIGUOUS: "ORDER_AMBIGUOUS",
  INVALID_LOOKUP_CODE: "INVALID_ORDER_CODE",
  INVALID_STAGE_TRANSITION: "INVALID_STAGE_TRANSITION",
  IDEMPOTENCY_CONFLICT: "IDEMPOTENCY_CONFLICT",
  SOURCE_STAGE_UNKNOWN: "WORKFLOW_UNAVAILABLE",
  INVALID_QR: "INVALID_QR",
  UNKNOWN_QR: "UNKNOWN_QR",
  EXPIRED_QR: "EXPIRED_QR",
  PASSWORD_CHANGE_REQUIRED: "PASSWORD_CHANGE_REQUIRED",
  APPLICATION_ACCESS_DENIED: "APPLICATION_ACCESS_DENIED",
  CURRENT_PASSWORD_INVALID: "CURRENT_PASSWORD_INVALID",
  PASSWORD_POLICY: "PASSWORD_POLICY",
};

function objectValue(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new StaffServiceError("SERVER_ERROR");
  return value as Record<string, unknown>;
}

function stringValue(value: unknown) {
  if (typeof value !== "string" || !value) throw new StaffServiceError("SERVER_ERROR");
  return value;
}

function booleanValue(value: unknown) {
  if (typeof value !== "boolean") throw new StaffServiceError("SERVER_ERROR");
  return value;
}

function timestampValue(value: unknown) {
  const text = stringValue(value);
  if (!Number.isFinite(Date.parse(text))) throw new StaffServiceError("SERVER_ERROR");
  return text;
}

function positiveInteger(value: unknown) {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 1) throw new StaffServiceError("SERVER_ERROR");
  return value;
}

function nonNegativeNumber(value: unknown) {
  if (typeof value !== "number" || !Number.isFinite(value) || value < 0) throw new StaffServiceError("SERVER_ERROR");
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
    applications: stringList(raw.applications),
    mustChangePassword: booleanValue(raw.mustChangePassword),
    locale: "ro",
  };
}

export function mapProductionOrderItem(value: unknown): import("../../domain/models").ProductionItem {
  const raw = objectValue(value);
  const quantity = raw.quantity;
  if (typeof quantity !== "number" || !Number.isFinite(quantity) || !Number.isInteger(quantity) || quantity < 1) {
    throw new StaffServiceError("SERVER_ERROR");
  }
  const item: import("../../domain/models").ProductionItem = {
    id: stringValue(raw.id),
    name: stringValue(raw.name),
    quantity,
  };
  if (raw.code != null) item.code = stringValue(raw.code);
  if (raw.color != null) item.color = stringValue(raw.color);
  if (raw.variant != null) item.variant = stringValue(raw.variant);
  if (raw.meters !== undefined && raw.meters !== null) {
    if (typeof raw.meters !== "number" || !Number.isFinite(raw.meters) || raw.meters < 0) {
      throw new StaffServiceError("SERVER_ERROR");
    }
    item.meters = raw.meters;
  }
  if (raw.measurements !== undefined && raw.measurements !== null) {
    const rawMeas = objectValue(raw.measurements);
    item.measurements = {};
    if (rawMeas.width !== undefined && rawMeas.width !== null) {
      if (typeof rawMeas.width !== "number" || !Number.isFinite(rawMeas.width) || rawMeas.width < 0) throw new StaffServiceError("SERVER_ERROR");
      item.measurements.width = rawMeas.width;
    }
    if (rawMeas.height !== undefined && rawMeas.height !== null) {
      if (typeof rawMeas.height !== "number" || !Number.isFinite(rawMeas.height) || rawMeas.height < 0) throw new StaffServiceError("SERVER_ERROR");
      item.measurements.height = rawMeas.height;
    }
    if (rawMeas.unit !== undefined && rawMeas.unit !== null) {
      if (typeof rawMeas.unit !== "string" || !["mm", "cm", "m"].includes(rawMeas.unit)) throw new StaffServiceError("SERVER_ERROR");
      item.measurements.unit = rawMeas.unit as "mm" | "cm" | "m";
    }
  }
  if (raw.productionContext != null) {
    const context = objectValue(raw.productionContext), kind = stringValue(context.kind);
    if (!['curtain', 'drapery', 'other'].includes(kind)) throw new StaffServiceError('SERVER_ERROR');
    item.productionContext = { kind: kind as 'curtain' | 'drapery' | 'other',
      notes: context.notes === null ? null : stringValue(context.notes), productionNotes: context.productionNotes === null ? null : stringValue(context.productionNotes) };
    if (context.project != null) item.productionContext.project = mapProjectLocation(context.project);
  }
  return item;
}

const optionalString = (value: unknown) => value === null || value === undefined ? null : stringValue(value);
function oneOf<T extends string>(value: unknown, allowed: readonly T[]): T {
  if (!allowed.includes(value as T)) throw new StaffServiceError("SERVER_ERROR");
  return value as T;
}

/** Strict mapping of the frozen project location; anything unexpected fails closed. */
export function mapProjectLocation(value: unknown): import("../../domain/models").ProjectLocation {
  const raw = objectValue(value), project = objectValue(raw.project), zone = objectValue(raw.zone), room = objectValue(raw.room);
  const opening = objectValue(raw.opening), treatment = objectValue(raw.treatment);
  const level = zone.level;
  if (level !== null && level !== undefined && !Number.isInteger(level)) throw new StaffServiceError("SERVER_ERROR");
  return {
    projectCode: stringValue(project.code), projectName: stringValue(project.name),
    zone: { name: stringValue(zone.name), zoneType: oneOf(zone.zoneType, ["floor", "zone"] as const), level: (level as number | null | undefined) ?? null, building: optionalString(zone.building) },
    room: { name: stringValue(room.name) },
    opening: { name: stringValue(opening.name), openingType: stringValue(opening.openingType), width: optionalString(opening.width), height: optionalString(opening.height),
      sillHeight: optionalString(opening.sillHeight), mounting: opening.mounting == null ? null : oneOf(opening.mounting, ["ceiling", "wall", "recess"] as const), railType: optionalString(opening.railType) },
    treatment: { treatmentType: oneOf(treatment.treatmentType, ["sheer", "drapery", "blackout", "rail", "accessory", "other"] as const),
      panelLayout: treatment.panelLayout == null ? null : oneOf(treatment.panelLayout, ["single", "pair", "left", "right"] as const) },
  };
}

export function mapProductionOrder(value: unknown): import("../../domain/models").StaffOrder {
  const raw = objectValue(value);
  const source = stringValue(raw.source);
  const status = stringValue(raw.status);
  
  const mappedSource = ["trendhome", "outletperdele", "trendyol", "b2b", "marketplace"].includes(source)
    ? (source as import("../../domain/models").OrderSource)
    : "unknown";
    
  if (!["in_progress", "handed_over", "unavailable"].includes(status)) {
    throw new StaffServiceError("SERVER_ERROR");
  }
  const mappedStatus = status as "in_progress" | "handed_over" | "unavailable";

  if (!Array.isArray(raw.products)) throw new StaffServiceError("SERVER_ERROR");
  const version = raw.version;
  if (typeof version !== "number" || !Number.isFinite(version) || !Number.isInteger(version) || version < 1) {
    throw new StaffServiceError("SERVER_ERROR");
  }

  const order: import("../../domain/models").StaffOrder = {
    id: stringValue(raw.id),
    source: mappedSource,
    orderNumber: stringValue(raw.orderNumber),
    productionStageId: stringValue(raw.productionStageId),
    products: raw.products.map(mapProductionOrderItem),
    status: mappedStatus,
    updatedAt: timestampValue(raw.updatedAt),
    version,
    productionVersion: positiveInteger(raw.productionVersion),
  };

  if (raw.employeeAllowedAction != null) {
    const action = objectValue(raw.employeeAllowedAction);
    if (!isOrderActionId(action.id)) throw new StaffServiceError("SERVER_ERROR");
    order.employeeAllowedAction = { id: action.id, label: orderActionLabels[action.id] };
  }
  if (raw.employeeActionBlockedReason != null) {
    if (!isOrderActionBlockedReason(raw.employeeActionBlockedReason) || order.employeeAllowedAction) throw new StaffServiceError("SERVER_ERROR");
    order.employeeActionBlockedReason = raw.employeeActionBlockedReason;
  }
  if (raw.productionCompletedAt != null) order.productionCompletedAt = timestampValue(raw.productionCompletedAt);

  if (raw.sourceCommerceStatus != null) {
    const scs = objectValue(raw.sourceCommerceStatus);
    order.sourceCommerceStatus = { code: stringValue(scs.code), label: stringValue(scs.label) };
  }
  if (raw.productionNotes != null) order.productionNotes = stringValue(raw.productionNotes);
  if (raw.productionContext != null) {
    if (source !== 'b2b') throw new StaffServiceError('SERVER_ERROR');
    const company = objectValue(objectValue(raw.productionContext).company);
    order.productionContext = { company: { legalName: stringValue(company.legalName), companyCode: stringValue(company.companyCode),
      countryCode: stringValue(company.countryCode), taxIdentifier: stringValue(company.taxIdentifier) } };
  }
  if (raw.acceptedAt != null) order.acceptedAt = timestampValue(raw.acceptedAt);

  if (raw.employeeRelation != null) {
    const rel = objectValue(raw.employeeRelation);
    const relType = stringValue(rel.type);
    if (!["claimed", "assigned", "updated", "handover_in", "handover_out", "completed"].includes(relType)) {
      throw new StaffServiceError("SERVER_ERROR");
    }
    order.employeeRelation = {
      employeeUuid: stringValue(rel.employeeUuid),
      type: relType as import("../../domain/models").EmployeeOrderRelationType,
      lastActionAt: timestampValue(rel.lastActionAt),
    };
  }

  if (raw.freshness != null) {
    const fresh = objectValue(raw.freshness);
    const freshStatus = stringValue(fresh.status);
    if (!["fresh", "stale", "source_unavailable"].includes(freshStatus)) {
      throw new StaffServiceError("SERVER_ERROR");
    }
    order.freshness = {
      status: freshStatus as "fresh" | "stale" | "source_unavailable",
      sourceChangedAt: timestampValue(fresh.sourceChangedAt),
      lastSourceSeenAt: timestampValue(fresh.lastSourceSeenAt),
    };
  }

  return order;
}

export function mapOrderPage(value: unknown): import("../../domain/models").OrderPage {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  return {
    items: raw.items.map(mapProductionOrder),
    nextCursor: raw.nextCursor != null ? stringValue(raw.nextCursor) : undefined,
  };
}

const activityActions = new Set<ActivityAction>(["claimed", "stage_completed", "production_completed"]);

export function mapActivityEntry(value: unknown): ActivityEntry {
  const raw = objectValue(value);
  const action = raw.action;
  if (typeof action !== "string" || !activityActions.has(action as ActivityAction)) throw new StaffServiceError("SERVER_ERROR");
  const source = stringValue(raw.source);
  const entry: ActivityEntry = {
    id: stringValue(raw.id),
    occurredAt: timestampValue(raw.occurredAt),
    action: action as ActivityAction,
    orderId: stringValue(raw.orderId),
    orderNumber: stringValue(raw.orderNumber),
    source: ["trendhome", "outletperdele", "trendyol", "b2b", "marketplace"].includes(source) ? source as ActivityEntry["source"] : "unknown",
    fromStageId: stringValue(raw.fromStageId),
    fromStageLabelSnapshot: stringValue(raw.fromStageLabelSnapshot),
  };
  if (raw.toStageId != null || raw.toStageLabelSnapshot != null) {
    entry.toStageId = stringValue(raw.toStageId);
    entry.toStageLabelSnapshot = stringValue(raw.toStageLabelSnapshot);
  }
  if (action === "stage_completed" && !entry.toStageId) throw new StaffServiceError("SERVER_ERROR");
  if (raw.meters != null) entry.meters = nonNegativeNumber(raw.meters);
  return entry;
}

export function mapActivityPage(value: unknown): ActivityPage {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  const summary = objectValue(raw.summary);
  const count = (item: unknown) => {
    if (typeof item !== "number" || !Number.isInteger(item) || item < 0) throw new StaffServiceError("SERVER_ERROR");
    return item;
  };
  return {
    items: raw.items.map(mapActivityEntry),
    nextCursor: raw.nextCursor != null ? stringValue(raw.nextCursor) : undefined,
    summary: { processed: count(summary.processed), meters: nonNegativeNumber(summary.meters), handedOver: count(summary.handedOver), inProgress: count(summary.inProgress) },
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
    // Access changed centrally (temporary password, application access removed): the app re-reads the session.
    if (["PASSWORD_CHANGE_REQUIRED", "APPLICATION_ACCESS_DENIED"].includes(error.code) && path !== "/auth/session" && path !== "/auth/password") onSessionExpired(error);
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
    async changePassword(input) {
      const payload = await http.request("/auth/password", { method: "POST", body: JSON.stringify({ currentPassword: input.currentPassword, newPassword: input.newPassword }) }, mapProductionSession);
      http.setCsrf(payload.csrfToken);
      return { employee: payload.employee, expiresAt: payload.expiresAt };
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
    resolveQr: (token, requestOptions) => http.request("/orders/resolve-qr", { method: "POST", body: JSON.stringify({ token }), signal: requestOptions?.signal }, mapProductionOrder),
    lookup: (code, requestOptions) => http.request(`/orders/lookup?code=${encodeURIComponent(code)}`, { signal: requestOptions?.signal }, mapProductionOrder),
    listMine: (requestOptions) => {
      let q = "";
      if (requestOptions?.cursor) q += `?cursor=${encodeURIComponent(requestOptions.cursor)}`;
      if (requestOptions?.limit) q += (q ? "&" : "?") + `limit=${requestOptions.limit}`;
      return http.request(`/orders/mine${q}`, { signal: requestOptions?.signal }, mapOrderPage);
    },
    getById: (id, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}`, { signal: requestOptions?.signal }, mapProductionOrder),
    claim: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/claim`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }, mapProductionOrder),
    confirmStageTransition: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/transition`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }, mapProductionOrder),
  };
  const activity: ActivityService = {
    listMine: (input, requestOptions) => {
      const query = new URLSearchParams({ range: input.range });
      if (input.range === "custom") {
        if (!input.from || !input.to) return Promise.reject(new StaffServiceError("SERVER_ERROR"));
        query.set("from", input.from);
        query.set("to", input.to);
      }
      if (input.cursor) query.set("cursor", input.cursor);
      return http.request(`/activity/mine?${query}`, { signal: requestOptions?.signal }, mapActivityPage);
    },
  };
  const workflow = createProductionWorkflowService({
    get: (etag, signal) => http.send("/production/workflow", { signal, headers: etag ? { "If-None-Match": etag } : undefined }),
    failure: (response) => http.errorFromResponse(response, "/production/workflow"),
  }, options.workflowCache ?? (normalizedApiBaseUrl ? createBrowserWorkflowCache(normalizedApiBaseUrl) : createUnavailableWorkflowCache()));
  return { auth, employee, orders, activity, workflow, mode: "production" };
}
