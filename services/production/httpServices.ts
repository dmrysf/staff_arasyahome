import { StaffServiceError, type ActivityAction, type ActivityEntry, type ActivityPage, type Employee, type FaultDecision, type FaultException, type FaultReason, type OrderQuality, type ServiceErrorCode } from "../../domain/models";
import { isOrderActionBlockedReason, isOrderActionId, orderActionLabels } from "../../domain/orderActions";
import type { ActivityService, AuthService, EmployeeService, ExceptionService, LiveService, OrderService, ServiceBundle, Session } from "../contracts";
import { startLiveClient } from "./liveClient";
import type { DocumentApi, DocumentAttention, DocumentRevision, OrderDocument, OrderDocumentSummary } from "../../domain/documents";
import type { TrendyolActivity, TrendyolApi, TrendyolCapabilities, TrendyolIgnoredPackage, TrendyolIntakeStatus, TrendyolLine, TrendyolLineKind, TrendyolOverview, TrendyolPackageDetail, TrendyolPackageSummary } from "../../domain/trendyol";
import { marketplaceClassOf, marketplaceStatusLabels } from "../../domain/trendyol";
import type { ManagementApi, ProductionOverview, StageQueue, StageSummary, WorkspaceApi } from "../../domain/workspaces";
import { isProductionAuthority, type AuthorityApi, type AuthorityChange, type OrderAuthorityView, type ProductionAuthorityMode } from "../../domain/authority";
import type { ActiveQr, ProductionQrApi, ProductionQrView, QrAuthority, QrAuthorityMode, QrRevision, QrRevisionState, QrRotationReason } from "../../domain/productionQr";
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
  PACKAGE_NOT_FOUND: "TRENDYOL_PACKAGE_NOT_FOUND",
  LINE_NOT_FOUND: "TRENDYOL_PACKAGE_NOT_FOUND",
  PACKAGE_CHANGED: "TRENDYOL_PACKAGE_CHANGED",
  PACKAGE_NOT_PENDING: "TRENDYOL_PACKAGE_NOT_PENDING",
  PACKAGE_ALREADY_RELEASED: "TRENDYOL_PACKAGE_NOT_PENDING",
  PACKAGE_NOT_DISMISSED: "TRENDYOL_PACKAGE_NOT_PENDING",
  PACKAGE_NOT_PREPARED: "TRENDYOL_PACKAGE_NOT_PREPARED",
  MEASUREMENTS_REQUIRED: "TRENDYOL_PACKAGE_NOT_PREPARED",
  MARKETPLACE_STATUS_NOT_RELEASABLE: "TRENDYOL_STATUS_NOT_RELEASABLE",
  MARKETPLACE_LINES_CHANGED: "TRENDYOL_LINES_CHANGED",
  STAGE_NOT_ALLOWED: "UNAUTHORIZED_ACTION",
  TRENDYOL_PACKAGE_UNAVAILABLE: "TRENDYOL_PACKAGE_UNAVAILABLE",
  TRENDYOL_VERIFICATION_FAILED: "TRENDYOL_VERIFICATION_FAILED",
  TRENDYOL_VERIFICATION_UNAVAILABLE: "TRENDYOL_VERIFICATION_FAILED",
  INVALID_MEASUREMENT: "TRENDYOL_INPUT_INVALID",
  INVALID_KIND: "TRENDYOL_INPUT_INVALID",
  INVALID_TEXT: "TRENDYOL_INPUT_INVALID",
  REASON_REQUIRED: "TRENDYOL_INPUT_INVALID",
  PRODUCTION_AUTHORITY_SOURCE: "PRODUCTION_AUTHORITY_SOURCE",
  AUTHORITY_CUTOVER_DISABLED: "AUTHORITY_CUTOVER_DISABLED",
  AUTHORITY_NOT_SUPPORTED: "AUTHORITY_NOT_SUPPORTED",
  AUTHORITY_ALREADY_OPERATIONS: "AUTHORITY_ALREADY_OPERATIONS",
  AUTHORITY_NOT_OPERATIONS: "AUTHORITY_NOT_OPERATIONS",
  AUTHORITY_RELEASE_NOT_ALLOWED: "AUTHORITY_RELEASE_NOT_ALLOWED",
  INVALID_STAGE: "INVALID_STAGE",
  WORKFLOW_MISMATCH: "WORKFLOW_MISMATCH",
  PRODUCTION_COMPLETED: "PRODUCTION_COMPLETED",
  QR_SUPERSEDED: "QR_SUPERSEDED",
  QR_REVOKED: "QR_REVOKED",
  QR_CHANGED: "QR_CHANGED",
  QR_CUTOVER_DISABLED: "QR_CUTOVER_DISABLED",
  QR_NOT_ARASYA: "QR_NOT_ARASYA",
  QR_NOT_SUPPORTED: "QR_NOT_SUPPORTED",
  QR_DOCUMENT_CONTROLLED: "QR_DOCUMENT_CONTROLLED",
  CUTTING_QR_REQUIRED: "QR_REQUIRED",
  CUTTING_TRANSFER_PENDING: "ORDER_BLOCKED_BY_EXCEPTION",
  CUTTING_MULTIPLE_CONFIRMATION_REQUIRED: "CONFIRMATION_REQUIRED",
  CUTTING_OWNED_COUNT_CHANGED: "ORDER_CHANGED",
  TRANSFER_CHANGED: "ORDER_CHANGED",
  TRANSFER_ALREADY_RESOLVED: "EXCEPTION_ALREADY_RESOLVED",
  TRANSFER_STATE_INVALID: "EXCEPTION_STATE_INVALID",
  INELIGIBLE_TRANSFER_TARGET: "UNAUTHORIZED_ACTION",
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
  ORDER_BLOCKED_BY_EXCEPTION: "ORDER_BLOCKED_BY_EXCEPTION",
  EXCEPTION_ALREADY_OPEN: "EXCEPTION_ALREADY_OPEN",
  EXCEPTION_STAGE_INVALID: "EXCEPTION_STAGE_INVALID",
  FAULT_REPORT_NOT_ALLOWED: "FAULT_REPORT_NOT_ALLOWED",
  SELF_FAULT_REPORT_DENIED: "SELF_FAULT_REPORT_DENIED",
  RESPONSIBLE_EMPLOYEE_UNKNOWN: "RESPONSIBLE_EMPLOYEE_UNKNOWN",
  FAULT_LINES_INVALID: "FAULT_LINES_INVALID",
  FAULT_LINE_WITHOUT_METERS: "FAULT_LINE_WITHOUT_METERS",
  REASON_INVALID: "REASON_INVALID",
  COMMENT_REQUIRED: "COMMENT_REQUIRED",
  CONFIRMATION_REQUIRED: "CONFIRMATION_REQUIRED",
  QR_REQUIRED: "QR_REQUIRED",
  QR_ORDER_MISMATCH: "QR_ORDER_MISMATCH",
  EXCEPTION_NOT_FOUND: "EXCEPTION_NOT_FOUND",
  EXCEPTION_NOT_ASSIGNED: "EXCEPTION_NOT_ASSIGNED",
  EXCEPTION_CHANGED: "EXCEPTION_CHANGED",
  EXCEPTION_STATE_INVALID: "EXCEPTION_STATE_INVALID",
  EXCEPTION_ALREADY_RESOLVED: "EXCEPTION_ALREADY_RESOLVED",
  ORDER_BLOCKED_BY_DOCUMENT: "ORDER_BLOCKED_BY_DOCUMENT",
  DOCUMENT_SUPERSEDED: "DOCUMENT_SUPERSEDED",
  DOCUMENT_REVOKED: "DOCUMENT_REVOKED",
  DOCUMENT_NOT_GENERATED: "DOCUMENT_NOT_GENERATED",
  DOCUMENT_ALREADY_ACTIVE: "DOCUMENT_ALREADY_ACTIVE",
  DOCUMENT_NOT_STALE: "DOCUMENT_NOT_STALE",
  DOCUMENT_REQUEST_OPEN: "DOCUMENT_REQUEST_OPEN",
  DOCUMENT_APPROVAL_REQUIRED: "DOCUMENT_APPROVAL_REQUIRED",
  DOCUMENT_CONTENT_CHANGED: "DOCUMENT_CONTENT_CHANGED",
  DOCUMENT_CHANGED: "DOCUMENT_CHANGED",
  DOCUMENT_ORDER_COMPLETED: "DOCUMENT_ORDER_COMPLETED",
  DOCUMENT_REVISION_NOT_ACTIVE: "DOCUMENT_REVISION_NOT_ACTIVE",
  DOCUMENT_AUTHORITY_SOURCE: "DOCUMENT_AUTHORITY_SOURCE",
  DOCUMENT_NOT_FOUND: "DOCUMENT_NOT_FOUND",
  DOCUMENT_REQUEST_RESOLVED: "DOCUMENT_CHANGED",
  DOCUMENT_REQUEST_CHANGED: "DOCUMENT_CHANGED",
  DOCUMENT_NOT_ACTIVE: "DOCUMENT_CHANGED",
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

/** Optional (older APIs omit it); a present value must be an object of string lists. */
function mapStageSourceScopes(value: unknown): Record<string, string[]> {
  if (value == null) return {};
  const raw = objectValue(value);
  return Object.fromEntries(Object.entries(raw).map(([stageId, sources]) => [stageId, stringList(sources)]));
}

export function mapProductionEmployee(value: unknown): Employee {
  const raw = objectValue(value);
  return {
    isRoot: raw.isRoot === true,
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
    stageSourceScopes: mapStageSourceScopes(raw.stageSourceScopes),
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
  if (Array.isArray(raw.options)) {
    item.options = raw.options.map((option) => { const o = objectValue(option); return { label: stringValue(o.label), value: stringValue(o.value) }; });
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

const documentStatuses = ["none", "active", "stale", "revoked"] as const;
const requestStatuses = ["pending", "approved", "rejected", "superseded", "generated", "cancelled"] as const;
const count = (value: unknown): number => { if (typeof value !== "number" || !Number.isInteger(value) || value < 0) throw new StaffServiceError("SERVER_ERROR"); return value; };

export function mapDocumentSummary(value: unknown): OrderDocumentSummary {
  const raw = objectValue(value);
  const request = raw.request == null ? null : objectValue(raw.request);
  return {
    status: oneOf(raw.status, documentStatuses),
    version: count(raw.version),
    revisionNumber: raw.revisionNumber == null ? null : positiveInteger(raw.revisionNumber),
    request: request === null ? null : { id: stringValue(request.id), status: oneOf(request.status, requestStatuses), targetRevision: positiveInteger(request.targetRevision), version: positiveInteger(request.version) },
  };
}

function mapRevision(value: unknown): DocumentRevision {
  const raw = objectValue(value);
  return { number: positiveInteger(raw.number), status: oneOf(raw.status, ["active", "superseded", "revoked"] as const), generatedAt: timestampValue(raw.generatedAt), generatedBy: stringValue(raw.generatedBy), prints: count(raw.prints),
    approvedBy: optionalString(raw.approvedBy), revokeReason: optionalString(raw.revokeReason) };
}

export function mapOrderDocument(value: unknown): OrderDocument {
  const raw = objectValue(value);
  const order = objectValue(raw.order);
  const request = raw.request == null ? null : objectValue(raw.request);
  if (!Array.isArray(raw.revisions)) throw new StaffServiceError("SERVER_ERROR");
  return {
    order: { id: stringValue(order.id), number: stringValue(order.number), completed: booleanValue(order.completed), unavailable: booleanValue(order.unavailable) },
    status: oneOf(raw.status, documentStatuses),
    version: count(raw.version),
    activeRevision: raw.activeRevision == null ? null : mapRevision(raw.activeRevision),
    latestRevisionNumber: raw.latestRevisionNumber == null ? null : positiveInteger(raw.latestRevisionNumber),
    request: request === null ? null : {
      id: stringValue(request.id), status: oneOf(request.status, requestStatuses), version: positiveInteger(request.version), targetRevision: positiveInteger(request.targetRevision),
      requestedBy: stringValue(request.requestedBy), requestedAt: timestampValue(request.requestedAt), comment: optionalString(request.comment),
      changes: Array.isArray(request.changes) ? request.changes.map((change) => { const c = objectValue(change); return { field: stringValue(c.field), line: c.line == null ? null : positiveInteger(c.line), before: optionalString(c.before), after: optionalString(c.after) }; }) : [],
      decidedBy: optionalString(request.decidedBy), decisionComment: optionalString(request.decisionComment), decidedAt: request.decidedAt == null ? null : timestampValue(request.decidedAt), resolutionNote: optionalString(request.resolutionNote),
    },
    revisions: raw.revisions.map(mapRevision),
  };
}

export function mapDocumentAttention(value: unknown): DocumentAttention {
  const raw = objectValue(value);
  const request = raw.request == null ? null : objectValue(raw.request);
  return {
    orderId: stringValue(raw.orderId), orderNumber: stringValue(raw.orderNumber), documentStatus: oneOf(raw.documentStatus, documentStatuses),
    revisionNumber: raw.revisionNumber == null ? null : positiveInteger(raw.revisionNumber),
    request: request === null ? null : { id: stringValue(request.id), status: oneOf(request.status, requestStatuses), targetRevision: positiveInteger(request.targetRevision), requestedBy: stringValue(request.requestedBy) },
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
  if (raw.productionAuthority != null) {
    if (!isProductionAuthority(raw.productionAuthority)) throw new StaffServiceError("SERVER_ERROR");
    order.productionAuthority = raw.productionAuthority;
  }

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
  if (raw.productionQuality != null) order.productionQuality = mapOrderQuality(raw.productionQuality);
  if (raw.documentStatus != null) order.documentStatus = oneOf(raw.documentStatus, ["none", "active", "stale", "revoked"] as const);
  if (raw.productionDocument != null) order.productionDocument = mapDocumentSummary(raw.productionDocument);

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

const decimalString = (value: unknown) => {
  if (typeof value !== "string" || !/^\d{1,9}\.\d{3}$/.test(value)) throw new StaffServiceError("SERVER_ERROR");
  return value;
};
const nullableText = (value: unknown) => value === null || value === undefined ? null : stringValue(value);
const nullableTimestamp = (value: unknown) => value === null || value === undefined ? null : timestampValue(value);
const exceptionStatuses = ["awaiting_acknowledgment", "awaiting_approval", "approved", "rejected", "cancelled"] as const;

function mapDecision(value: unknown): FaultDecision {
  const raw = objectValue(value);
  return {
    attempt: positiveInteger(raw.attempt),
    status: oneOf(raw.status, ["pending", "approved", "rejected", "cancelled"] as const),
    openedReason: oneOf(raw.openedReason, ["acknowledged", "rereview"] as const),
    openedBy: stringValue(raw.openedBy),
    openedComment: nullableText(raw.openedComment),
    openedAt: timestampValue(raw.openedAt),
    decidedAt: nullableTimestamp(raw.decidedAt),
    decidedBy: nullableText(raw.decidedBy),
    comment: nullableText(raw.comment),
  };
}

/** Strict mapping of a cutting fault request; meters stay exact decimal strings. */
export function mapFaultException(value: unknown): FaultException {
  const raw = objectValue(value);
  const order = objectValue(raw.order), reason = objectValue(raw.reason), actions = raw.actions == null ? null : objectValue(raw.actions);
  const source = stringValue(order.source);
  const mapped: FaultException = {
    id: stringValue(raw.id),
    number: stringValue(raw.number),
    status: oneOf(raw.status, exceptionStatuses),
    version: positiveInteger(raw.version),
    order: { id: stringValue(order.id), orderNumber: stringValue(order.orderNumber), sourceName: stringValue(order.sourceName),
      source: ["trendhome", "outletperdele", "trendyol", "b2b", "marketplace"].includes(source) ? source as FaultException["order"]["source"] : "unknown" },
    reason: { key: stringValue(reason.key), label: stringValue(reason.label) },
    lineCount: positiveInteger(raw.lineCount),
    faultMeters: decimalString(raw.faultMeters),
    arrivalNumber: positiveInteger(raw.arrivalNumber),
    reworkCycle: raw.reworkCycle == null ? null : positiveInteger(raw.reworkCycle),
    repeatedError: booleanValue(raw.repeatedError),
    detector: { displayName: stringValue(objectValue(raw.detector).displayName) },
    responsible: { displayName: stringValue(objectValue(raw.responsible).displayName) },
    reportedAt: timestampValue(raw.reportedAt),
    acknowledgedAt: nullableTimestamp(raw.acknowledgedAt),
    resolvedAt: nullableTimestamp(raw.resolvedAt),
    pendingSince: nullableTimestamp(raw.pendingSince),
    role: raw.role == null ? null : oneOf(raw.role, ["responsible", "detector"] as const),
    actions: { canAcknowledge: actions ? booleanValue(actions.canAcknowledge) : false, canRequestRereview: actions ? booleanValue(actions.canRequestRereview) : false },
  };
  if ("detectorComment" in raw) mapped.detectorComment = nullableText(raw.detectorComment);
  if ("acknowledgmentComment" in raw) mapped.acknowledgmentComment = nullableText(raw.acknowledgmentComment);
  if (raw.lines != null) {
    if (!Array.isArray(raw.lines)) throw new StaffServiceError("SERVER_ERROR");
    mapped.lines = raw.lines.map((item) => {
      const line = objectValue(item);
      return { itemId: stringValue(line.itemId), lineNumber: positiveInteger(line.lineNumber), name: stringValue(line.name), code: nullableText(line.code),
        variant: nullableText(line.variant), color: nullableText(line.color), quantity: positiveInteger(line.quantity), meters: decimalString(line.meters) };
    });
  }
  if (raw.decisions != null) {
    if (!Array.isArray(raw.decisions)) throw new StaffServiceError("SERVER_ERROR");
    mapped.decisions = raw.decisions.map(mapDecision);
  }
  return mapped;
}

export function mapOrderQuality(value: unknown): OrderQuality {
  const raw = objectValue(value);
  const count = (item: unknown) => { if (typeof item !== "number" || !Number.isInteger(item) || item < 0) throw new StaffServiceError("SERVER_ERROR"); return item; };
  return { arrivalNumber: count(raw.arrivalNumber), reworkCycles: count(raw.reworkCycles), repeatedErrors: booleanValue(raw.repeatedErrors),
    openException: raw.openException == null ? null : mapFaultException(raw.openException) };
}

function mapFaultList(value: unknown): FaultException[] {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  return raw.items.map(mapFaultException);
}

function mapReasons(value: unknown): FaultReason[] {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  return raw.items.map((item) => { const reason = objectValue(item); return { key: stringValue(reason.key), label: stringValue(reason.label), requiresComment: booleanValue(reason.requiresComment) }; });
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
    let details: Record<string, unknown> | undefined;
    try {
      const payload = objectValue(await response.json());
      const error = objectValue(payload.error);
      backendCode = typeof error.code === "string" ? error.code : "";
      // Only the active revision number is kept from details (an old QR scan tells which revision to use).
      if (error.details && typeof error.details === "object" && typeof (error.details as Record<string, unknown>).activeRevisionNumber === "number") details = { activeRevisionNumber: (error.details as Record<string, unknown>).activeRevisionNumber };
      if (error.details && typeof error.details === "object" && typeof (error.details as Record<string, unknown>).activeQrRevision === "number") details = { activeQrRevision: (error.details as Record<string, unknown>).activeQrRevision };
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
    const error = new StaffServiceError(backendErrorCodes[backendCode] ?? fallback, undefined, details);
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
    claim: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/claim`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion, ...(input.qrToken ? { qrToken: input.qrToken } : {}), ...(input.confirmedMultiple !== undefined ? { confirmedMultiple: input.confirmedMultiple } : {}), ...(input.ownedCount !== undefined ? { ownedCount: input.ownedCount } : {}) }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }, mapProductionOrder),
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
  const exceptions: ExceptionService = {
    listMine: (requestOptions) => http.request("/production-exceptions/mine", { signal: requestOptions?.signal }, mapFaultList),
    get: (id, requestOptions) => http.request(`/production-exceptions/${encodeURIComponent(id)}`, { signal: requestOptions?.signal }, mapFaultException),
    reasons: (requestOptions) => http.request("/production-exceptions/reasons", { signal: requestOptions?.signal }, mapReasons),
    report: (orderId, input) => http.request(`/orders/${encodeURIComponent(orderId)}/fault-reports`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, itemIds: input.itemIds, reasonKey: input.reasonKey, ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    acknowledge: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/acknowledge`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, confirmed: true, qrToken: input.qrToken, ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    rereview: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/rereview`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, comment: input.comment }) }, mapFaultException),
  };
  const live: LiveService = {
    subscribe(handler, onState) {
      return startLiveClient({
        fetchStream: (path, signal) => http.send(path, { signal }),
        onEvent: handler,
        onState,
        onDenied: (response) => { void http.errorFromResponse(response, "/live/events"); },
      });
    },
  };
  const documents: DocumentApi = {
    lookup: (number, signal) => http.request(`/production-documents/lookup?number=${encodeURIComponent(number)}`, { signal }, (value) => {
      const raw = objectValue(value);
      if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
      return raw.items.map((item) => { const row = objectValue(item); return { orderId: stringValue(row.orderId), orderNumber: stringValue(row.orderNumber), source: stringValue(row.source),
        documentStatus: oneOf(row.documentStatus, documentStatuses), revisionNumber: row.revisionNumber == null ? null : positiveInteger(row.revisionNumber) }; });
    }),
    view: (orderId, signal) => http.request(`/production-documents/orders/${encodeURIComponent(orderId)}`, { signal }, mapOrderDocument),
    attention: (signal) => http.request("/production-documents/attention", { signal }, (value) => {
      const raw = objectValue(value);
      if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
      return raw.items.map(mapDocumentAttention);
    }),
    generate: (orderId, input, key) => http.request(`/production-documents/orders/${encodeURIComponent(orderId)}/generate`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapOrderDocument),
    requestRevision: (orderId, input, key) => http.request(`/production-documents/orders/${encodeURIComponent(orderId)}/revision-requests`, { method: "POST", headers: { "Idempotency-Key": key },
      body: JSON.stringify({ expectedDocumentVersion: input.expectedDocumentVersion, ...(input.comment ? { comment: input.comment } : {}) }) }, mapOrderDocument),
    async print(orderId, input, key) {
      const response = await http.send(`/production-documents/orders/${encodeURIComponent(orderId)}/print`, { method: "POST", headers: { "Idempotency-Key": key },
        body: JSON.stringify({ revisionNumber: input.revisionNumber, ...(input.reason ? { reason: input.reason } : {}) }) });
      if (!response.ok) throw await http.errorFromResponse(response, "/production-documents/print");
      return response.blob();
    },
    async preview(orderId, revisionNumber) {
      const response = await http.send(`/production-documents/orders/${encodeURIComponent(orderId)}/revisions/${revisionNumber}/preview`, {});
      if (!response.ok) throw await http.errorFromResponse(response, "/production-documents/preview");
      return response.blob();
    },
  };
  const cutting: import("../../domain/cutting").CuttingApi = {
    pool: (signal) => http.request("/cutting/pool", { signal }),
    targets: (signal) => http.request("/cutting/targets", { signal }),
    transfers: (signal) => http.request("/cutting/transfers", { signal }),
    request: (orderId, input, key) => http.request(`/cutting/orders/${encodeURIComponent(orderId)}/transfers`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }),
    change: (id, action, input, key) => http.request(`/cutting/transfers/${encodeURIComponent(id)}/${action}`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }),
  };
  const authority: AuthorityApi = {
    inspect: (globalOrderId, signal) => http.request(`/orders/${encodeURIComponent(globalOrderId)}/production-authority`, { signal }, mapAuthorityView),
    takeOver: (globalOrderId, input, key) => http.request(`/orders/${encodeURIComponent(globalOrderId)}/production-authority/takeover`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapAuthorityChange),
    release: (globalOrderId, input, key) => http.request(`/orders/${encodeURIComponent(globalOrderId)}/production-authority/release`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapAuthorityChange),
  };
  const productionQr: ProductionQrApi = {
    inspect: (globalOrderId, signal) => http.request(`/orders/${encodeURIComponent(globalOrderId)}/production-qr`, { signal }, mapProductionQrView),
    rotate: (globalOrderId, input, key) => http.request(`/orders/${encodeURIComponent(globalOrderId)}/production-qr/rotate`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapProductionQrView),
  };
  const trendyolPackage = (packageId: string) => `/trendyol/packages/${encodeURIComponent(packageId)}`;
  const trendyol: TrendyolApi = {
    overview: (signal) => http.request("/trendyol/overview", { signal }, mapTrendyolOverview),
    activity: (signal) => http.request("/trendyol/activity", { signal }, mapTrendyolActivity),
    list: (view, signal) => http.request(`/trendyol/packages?view=${view}`, { signal }, (value) => itemsOf(value).map(mapTrendyolSummary)),
    ignored: (signal) => http.request("/trendyol/packages?view=ignored", { signal }, (value) => itemsOf(value).map(mapTrendyolIgnored)),
    detail: (packageId, signal) => http.request(trendyolPackage(packageId), { signal }, mapTrendyolDetail),
    prepareLine: (packageId, lineId, input, key) => http.request(`${trendyolPackage(packageId)}/lines/${encodeURIComponent(lineId)}`, { method: "PUT", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapTrendyolDetail),
    dismiss: (packageId, input, key) => http.request(`${trendyolPackage(packageId)}/dismiss`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapTrendyolDetail),
    reopen: (packageId, input, key) => http.request(`${trendyolPackage(packageId)}/reopen`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapTrendyolDetail),
    release: (packageId, input, key) => http.request(`${trendyolPackage(packageId)}/release`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapTrendyolDetail),
  };
  const workspace: WorkspaceApi = {
    stageQueue: (stageId, signal) => http.request(`/orders/stage-queue?stage=${encodeURIComponent(stageId)}`, { signal }, mapStageQueue),
    stageSummary: (signal) => http.request("/orders/stage-summary", { signal }, mapStageSummary),
  };
  const management: ManagementApi = { productionOverview: (signal) => http.request("/management/production-overview", { signal }, mapProductionOverview) };
  return { auth, employee, orders, activity, workflow, exceptions, live, cutting, documents, authority, productionQr, trendyol, workspace, management, mode: "production" };
}

// ---------------------------------------------------------------- Trendyol workspace (strict; unexpected shapes fail closed)
const PACKAGE_ID = /^[1-9][0-9]{0,18}$/;
const intakeStatuses: readonly TrendyolIntakeStatus[] = ["pending", "released", "dismissed", "marketplace_cancelled"];
const lineKinds: readonly TrendyolLineKind[] = ["curtain", "drapery", "other"];
function nonNegativeInteger(value: unknown): number {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 0) throw new StaffServiceError("SERVER_ERROR");
  return value;
}
function itemsOf(value: unknown): unknown[] {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  return raw.items;
}
function packageIdValue(value: unknown): string {
  const id = stringValue(value);
  if (!PACKAGE_ID.test(id)) throw new StaffServiceError("SERVER_ERROR");
  return id;
}
function mapTrendyolCapabilities(value: unknown): TrendyolCapabilities {
  const raw = objectValue(value);
  return { view: booleanValue(raw.view), prepare: booleanValue(raw.prepare), release: booleanValue(raw.release) };
}
const marketplaceClasses = ["new", "payment_pending", "review", "fulfilment", "returned", "cancelled", "split"] as const;
const blockedReasons = ["payment_pending", "review", "fulfilment", "returned", "cancelled", "split", "unknown_status"] as const;
export function mapTrendyolActivity(value: unknown): TrendyolActivity {
  const raw = objectValue(value);
  const today = objectValue(raw.today);
  if (!Array.isArray(raw.recent)) throw new StaffServiceError("SERVER_ERROR");
  return {
    today: { linesPrepared: nonNegativeInteger(today.linesPrepared), released: nonNegativeInteger(today.released), dismissed: nonNegativeInteger(today.dismissed), reopened: nonNegativeInteger(today.reopened) },
    recent: raw.recent.map((item) => {
      const event = objectValue(item);
      return { action: oneOf(event.action, ["line_prepared", "released", "dismissed", "reopened"] as const), packageId: event.packageId == null ? null : packageIdValue(event.packageId), orderNumber: nullableText(event.orderNumber), at: nullableTimestamp(event.at) };
    }),
  };
}
export function mapStageQueue(value: unknown): StageQueue {
  const raw = objectValue(value);
  const counts = objectValue(raw.counts);
  if (!Array.isArray(raw.items) || typeof raw.countsComplete !== "boolean") throw new StaffServiceError("SERVER_ERROR");
  return {
    stageId: stringValue(raw.stageId),
    counts: { total: nonNegativeInteger(counts.total), mine: nonNegativeInteger(counts.mine), available: nonNegativeInteger(counts.available), blocked: nonNegativeInteger(counts.blocked), claimedByOthers: nonNegativeInteger(counts.claimedByOthers) },
    countsComplete: raw.countsComplete,
    items: raw.items.map(mapProductionOrder),
  };
}
export function mapStageSummary(value: unknown): StageSummary {
  const raw = objectValue(value);
  if (!Array.isArray(raw.stages)) throw new StaffServiceError("SERVER_ERROR");
  return raw.stages.map((item) => { const stage = objectValue(item); return { stageId: stringValue(stage.stageId), total: nonNegativeInteger(stage.total), mine: nonNegativeInteger(stage.mine) }; });
}
export function mapProductionOverview(value: unknown): ProductionOverview {
  const raw = objectValue(value);
  const summary = objectValue(raw.summary);
  if (!Array.isArray(raw.stages)) throw new StaffServiceError("SERVER_ERROR");
  return {
    generatedAt: timestampValue(raw.generatedAt),
    summary: { active: nonNegativeInteger(summary.active), waiting: nonNegativeInteger(summary.waiting), inWork: nonNegativeInteger(summary.inWork), unassigned: nonNegativeInteger(summary.unassigned), completedToday: nonNegativeInteger(summary.completedToday) },
    stages: raw.stages.map((item) => {
      const stage = objectValue(item);
      return { id: stringValue(stage.id), label: stringValue(stage.label), ordinal: positiveInteger(stage.ordinal), active: nonNegativeInteger(stage.active), unassigned: nonNegativeInteger(stage.unassigned), oldestEnteredAt: nullableTimestamp(stage.oldestEnteredAt) };
    }),
  };
}
export function mapTrendyolOverview(value: unknown): TrendyolOverview {
  const raw = objectValue(value);
  const intake = objectValue(raw.intake);
  const counts = objectValue(raw.counts);
  return {
    intake: { status: oneOf(intake.status, ["inactive", "active", "paused"] as const), baselineAt: nullableTimestamp(intake.baselineAt), lastRunAt: nullableTimestamp(intake.lastRunAt), lastRunOutcome: nullableText(intake.lastRunOutcome) },
    counts: { pending: nonNegativeInteger(counts.pending), attention: counts.attention === undefined ? 0 : nonNegativeInteger(counts.attention), released: nonNegativeInteger(counts.released), closed: nonNegativeInteger(counts.closed), ignored: nonNegativeInteger(counts.ignored) },
    capabilities: mapTrendyolCapabilities(raw.capabilities),
  };
}
export function mapTrendyolSummary(value: unknown): TrendyolPackageSummary {
  const raw = objectValue(value);
  return {
    packageId: packageIdValue(raw.packageId),
    orderNumber: stringValue(raw.orderNumber),
    intakeStatus: oneOf(raw.intakeStatus, intakeStatuses),
    marketplaceStatus: stringValue(raw.marketplaceStatus),
    marketplaceClass: raw.marketplaceClass === undefined ? marketplaceClassOf(stringValue(raw.marketplaceStatus)) : oneOf(raw.marketplaceClass, marketplaceClasses),
    marketplaceStatusKnown: raw.marketplaceStatusKnown === undefined ? stringValue(raw.marketplaceStatus) in marketplaceStatusLabels : booleanValue(raw.marketplaceStatusKnown),
    orderDate: nullableTimestamp(raw.orderDate),
    orderDateNearActivation: raw.orderDateNearActivation === undefined ? false : booleanValue(raw.orderDateNearActivation),
    changedAfterRelease: booleanValue(raw.changedAfterRelease),
    version: positiveInteger(raw.version),
    ...(raw.lineCount === undefined ? {} : { lineCount: nonNegativeInteger(raw.lineCount), preparedCount: nonNegativeInteger(raw.preparedCount) }),
    ...(raw.globalOrderId === undefined ? {} : { globalOrderId: nullableText(raw.globalOrderId), stageId: nullableText(raw.stageId) }),
  };
}
function mapTrendyolIgnored(value: unknown): TrendyolIgnoredPackage {
  const raw = objectValue(value);
  return { packageId: packageIdValue(raw.packageId), orderNumber: stringValue(raw.orderNumber), reason: oneOf(raw.reason, ["historical", "status_not_eligible", "order_date_missing"] as const), marketplaceStatus: stringValue(raw.marketplaceStatus), orderDate: nullableTimestamp(raw.orderDate), firstSeenAt: nullableTimestamp(raw.firstSeenAt) };
}
function mapTrendyolLine(value: unknown): TrendyolLine {
  const raw = objectValue(value);
  const suggestion = raw.sizeSuggestion == null ? null : objectValue(raw.sizeSuggestion);
  const prepared = raw.prepared == null ? null : objectValue(raw.prepared);
  return {
    lineId: packageIdValue(raw.lineId),
    lineNumber: positiveInteger(raw.lineNumber),
    productName: stringValue(raw.productName),
    stockCode: nullableText(raw.stockCode),
    barcode: nullableText(raw.barcode),
    productSize: nullableText(raw.productSize),
    productColor: nullableText(raw.productColor),
    quantity: positiveInteger(raw.quantity),
    sizeSuggestion: suggestion === null ? null : { width: stringValue(suggestion.width), height: stringValue(suggestion.height) },
    prepared: prepared === null ? null : { kind: oneOf(prepared.kind, lineKinds), widthCm: nullableText(prepared.widthCm), heightCm: nullableText(prepared.heightCm), meters: nullableText(prepared.meters), notes: nullableText(prepared.notes), preparedBy: nullableText(prepared.preparedBy), preparedAt: nullableTimestamp(prepared.preparedAt) },
  };
}
export function mapTrendyolDetail(value: unknown): TrendyolPackageDetail {
  const raw = objectValue(value);
  const readiness = objectValue(raw.readiness);
  const capabilities = objectValue(raw.capabilities);
  const delivery = raw.delivery == null ? null : objectValue(raw.delivery);
  const production = raw.production == null ? null : objectValue(raw.production);
  const dismissal = raw.dismissal == null ? null : objectValue(raw.dismissal);
  if (!Array.isArray(raw.lines) || !Array.isArray(raw.siblings) || !Array.isArray(raw.history) || !Array.isArray(readiness.missingLines) || (delivery !== null && !Array.isArray(delivery.addressLines))) throw new StaffServiceError("SERVER_ERROR");
  return {
    ...mapTrendyolSummary(raw),
    delivery: delivery === null ? null : { name: nullableText(delivery.name), addressLines: (delivery.addressLines as unknown[]).map(stringValue), phoneMasked: nullableText(delivery.phoneMasked) },
    lines: raw.lines.map(mapTrendyolLine),
    readiness: { ready: booleanValue(readiness.ready), missingLines: readiness.missingLines.map(positiveInteger), marketplaceReleasable: booleanValue(readiness.marketplaceReleasable), blockedReason: readiness.blockedReason == null ? null : oneOf(readiness.blockedReason, blockedReasons) },
    siblings: raw.siblings.map((item) => { const sibling = objectValue(item); return { packageId: packageIdValue(sibling.packageId), intakeStatus: oneOf(sibling.intakeStatus, intakeStatuses), marketplaceStatus: stringValue(sibling.marketplaceStatus) }; }),
    production: production === null ? null : { globalOrderId: stringValue(production.globalOrderId), stageId: stringValue(production.stageId), stageLabel: nullableText(production.stageLabel), documentStatus: stringValue(production.documentStatus), operationalStatus: stringValue(production.operationalStatus), releasedBy: nullableText(production.releasedBy), releasedAt: nullableTimestamp(production.releasedAt) },
    dismissal: dismissal === null ? null : { reason: stringValue(dismissal.reason), by: nullableText(dismissal.by), at: nullableTimestamp(dismissal.at) },
    history: raw.history.map((item) => { const event = objectValue(item); return { action: stringValue(event.action), actor: nullableText(event.actor), at: nullableTimestamp(event.at) }; }),
    capabilities: { ...mapTrendyolCapabilities(capabilities), prepareNow: booleanValue(capabilities.prepareNow), releaseNow: booleanValue(capabilities.releaseNow), dismissNow: booleanValue(capabilities.dismissNow), reopenNow: booleanValue(capabilities.reopenNow) },
  };
}

const qrAuthorities: readonly QrAuthority[] = ["arasya", "source"];
const qrModes: readonly QrAuthorityMode[] = ["legacy", "observe", "enforce", "internal"];
const qrStates: readonly QrRevisionState[] = ["active", "superseded", "revoked", "retired"];
const qrReasons: readonly QrRotationReason[] = ["label_lost", "label_damaged", "security"];
const QR_PAYLOAD = /^ARASYA:Q1:[A-Z2-7]{26}$/;
const QR_HINT = /^[0-9a-f]{10}$/;
function qrDocumentRevision(value: unknown): number | null {
  return value == null ? null : positiveInteger(value);
}
function mapQrRevision(value: unknown): QrRevision {
  const raw = objectValue(value);
  const hint = stringValue(raw.hint);
  if (!QR_HINT.test(hint)) throw new StaffServiceError("SERVER_ERROR");
  return { revision: positiveInteger(raw.revision), state: oneOf(raw.state, qrStates), issuedAt: timestampValue(raw.issuedAt), retiredAt: raw.retiredAt == null ? null : timestampValue(raw.retiredAt), hint, documentRevision: qrDocumentRevision(raw.documentRevision) };
}
function mapActiveQr(value: unknown): ActiveQr {
  const raw = objectValue(value);
  const payload = stringValue(raw.payload);
  const svg = stringValue(raw.svg);
  const hint = stringValue(raw.hint);
  // Only the server's own QR drawing is accepted: one rect and one path, nothing executable.
  if (!QR_PAYLOAD.test(payload) || !QR_HINT.test(hint) || !/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg" viewBox="0 0 \d+ \d+" shape-rendering="crispEdges" role="img" aria-label="[^"<>]*"><rect width="100%" height="100%" fill="#fff"\/><path fill="#000" d="[Mhvz0-9 -]*"\/><\/svg>$/.test(svg)) throw new StaffServiceError("SERVER_ERROR");
  return { revision: positiveInteger(raw.revision), issuedAt: timestampValue(raw.issuedAt), hint, documentRevision: qrDocumentRevision(raw.documentRevision), payload, svg };
}
export function mapProductionQrView(value: unknown): ProductionQrView {
  const raw = objectValue(value);
  const rotate = objectValue(raw.rotate);
  if (!isProductionAuthority(raw.productionAuthority) || !Array.isArray(raw.history) || !Array.isArray(rotate.reasons)) throw new StaffServiceError("SERVER_ERROR");
  return {
    globalOrderId: stringValue(raw.globalOrderId),
    orderNumber: stringValue(raw.orderNumber),
    source: stringValue(raw.source),
    qrAuthorityMode: oneOf(raw.qrAuthorityMode, qrModes),
    productionAuthority: raw.productionAuthority,
    qrAuthority: oneOf(raw.qrAuthority, qrAuthorities),
    active: raw.active == null ? null : mapActiveQr(raw.active),
    history: raw.history.map(mapQrRevision),
    rotate: { allowed: booleanValue(rotate.allowed), blockedReason: rotate.blockedReason == null ? null : stringValue(rotate.blockedReason), reasons: rotate.reasons.map((reason) => oneOf(reason, qrReasons)) },
  };
}

const authorityModes: readonly ProductionAuthorityMode[] = ["legacy", "observe", "enforce"];
function authorityGate(value: unknown): { allowed: boolean; blockedReason: string | null } {
  const raw = objectValue(value);
  if (typeof raw.allowed !== "boolean") throw new StaffServiceError("SERVER_ERROR");
  return { allowed: raw.allowed, blockedReason: raw.blockedReason == null ? null : stringValue(raw.blockedReason) };
}
function authorityStage(value: unknown): { id: string; label: string | null } {
  const raw = objectValue(value);
  return { id: stringValue(raw.id), label: raw.label == null ? null : stringValue(raw.label) };
}
export function mapAuthorityView(value: unknown): OrderAuthorityView {
  const raw = objectValue(value);
  const workflow = objectValue(raw.workflow);
  if (!isProductionAuthority(raw.productionAuthority) || !authorityModes.includes(raw.authorityMode as ProductionAuthorityMode) || !Array.isArray(workflow.stages)) throw new StaffServiceError("SERVER_ERROR");
  return {
    globalOrderId: stringValue(raw.globalOrderId),
    orderNumber: stringValue(raw.orderNumber),
    source: stringValue(raw.source),
    authorityMode: raw.authorityMode as ProductionAuthorityMode,
    productionAuthority: raw.productionAuthority,
    stage: authorityStage(raw.stage),
    productionVersion: positiveInteger(raw.productionVersion),
    operationalStatus: stringValue(raw.operationalStatus),
    productionCompleted: raw.productionCompleted === true,
    hasOwner: raw.hasOwner === true,
    takeover: authorityGate(raw.takeover),
    release: authorityGate(raw.release),
    workflow: { id: stringValue(workflow.id), version: positiveInteger(workflow.version), stages: workflow.stages.map((stage) => { const s = objectValue(stage); return { id: stringValue(s.id), label: stringValue(s.label), ordinal: positiveInteger(s.ordinal) }; }) },
  };
}
export function mapAuthorityChange(value: unknown): AuthorityChange {
  const raw = objectValue(value);
  if (!isProductionAuthority(raw.productionAuthority) || (raw.action !== "authority_taken_over" && raw.action !== "authority_released") || typeof raw.changed !== "boolean") throw new StaffServiceError("SERVER_ERROR");
  return { globalOrderId: stringValue(raw.globalOrderId), action: raw.action, changed: raw.changed, productionAuthority: raw.productionAuthority, stage: authorityStage(raw.stage), productionVersion: positiveInteger(raw.productionVersion) };
}
