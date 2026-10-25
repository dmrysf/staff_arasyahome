import { mapAssistedCheckpoint, mapManufacturingPlan, mapPhysicalHistory } from "../../domain/physical";
import { StaffServiceError, type ActivityAction, type ActivityEntry, type ActivityPage, type Employee, type FaultDecision, type FaultException, type FaultReason, type OrderQuality, type QualityCapabilities, type ServiceErrorCode } from "../../domain/models";
import { normalizeLocale } from "../../i18n/locales";
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
import type { ShopLine, ShopOrderDetail, ShopPerson, ShopRead } from "../../domain/confirmareShop";
import { CASH_RECEIPTS, EXCEPTION_REASONS, EXCEPTION_RESOLUTIONS, FINANCE_ACTIONS, PAYMENT_METHODS, FINANCE_NEXT, FINANCE_TASKS, type ExceptionOutcome, type FinanceEntry, type FinanceOutcome, type FinanceQueue, type FinanceQueueItem, type FinanceRead, type FinanceSummary, type FinanceTask, type FinanceTimelineItem, type TransferException, type TransferExceptionList } from "../../domain/confirmareFinance";
import { CANCEL_REASONS, INVOICE_ACTIONS, INVOICE_ROLES, PROFORMA_ACTIONS, PROFORMA_KEYS, PROFORMA_ROUTE, type CodCheck, type ShippingCheck, type InvoiceDeadline, type MoneyPosition, type TotalAcceptance, type InvoiceActionKey, type InvoiceOutcome, type InvoiceRead, type InvoiceSummary, type InvoiceView, type ProformaActionKey, type ProformaSummary, type ShopAssignment, type ShopQueueItem } from "../../domain/confirmareInvoice";
import { EDIT_IMPACTS, type EditBlocker, type EditCatalog, type EditField, type EditLineSnapshot, type EditOutcome, type EditProduct, type EditResult, type EditTotals, type EditVariation, type OpenEdit } from "../../domain/confirmareEdit";
import { mapAwbQueue, mapBankCheck, mapBankChecks, mapContactCycle, mapCycleAttention, mapEfacturaQueue, mapEfacturaTask, mapHealth, mapOperationOutcome, mapOperationsState, mapOrderCycle, mapProformaCycle } from "../../domain/confirmareOps";
import { CLEARANCE_CONDITIONS, CONTACT_OUTCOMES, DOCUMENT_STATES, FINANCIAL_ATTENTION, HANDOFF_BLOCKERS, HANDOFF_STATES, type ConfirmareAlert, type ConfirmareApi, type ConfirmareAvailability, type ConfirmareContact, type ConfirmareCustomer, type ConfirmareDetail, type ConfirmareEvent, type ConfirmareHandoff, type ConfirmareItem, type HandoffItem, type ConfirmareLine, type ConfirmareOperator, type ConfirmarePool, type ConfirmareTeam, type FinancialState } from "../../domain/confirmare";

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
  CUTTING_INTAKE_NOT_ALLOWED: "CUTTING_INTAKE_NOT_APPLICABLE",
  CUTTING_INTAKE_NOT_APPLICABLE: "CUTTING_INTAKE_NOT_APPLICABLE",
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
  ORDER_PRODUCTION_STOPPED: "ORDER_PRODUCTION_STOPPED",
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
  SUPERVISOR_REQUIRED: "SUPERVISOR_REQUIRED",
  SELF_REVIEW_DENIED: "SELF_REVIEW_DENIED",
  RESPONSIBLE_UNAVAILABLE: "RESPONSIBLE_UNAVAILABLE",
  RETURN_CANCEL_CONFIRMATION_REQUIRED: "RETURN_CANCEL_CONFIRMATION_REQUIRED",
  PARTIAL_REWORK_DISABLED: "PARTIAL_REWORK_DISABLED",
  UNITS_INVALID: "UNITS_INVALID",
  UNIT_REVIEW_REQUIRED: "UNIT_REVIEW_REQUIRED",
  UNIT_LABEL_MISMATCH: "UNIT_LABEL_MISMATCH",
  TRACK_NOT_FOUND: "TRACK_NOT_FOUND",
  TRACK_CHANGED: "TRACK_CHANGED",
  TRACK_ALREADY_CLAIMED: "TRACK_ALREADY_CLAIMED",
  TRACK_WAITING_FOR_ORDER: "TRACK_WAITING_FOR_ORDER",
  DEFECT_RESOLUTION_REQUIRED: "DEFECT_RESOLUTION_REQUIRED",
  ORDER_PIECES_IN_REWORK: "ORDER_PIECES_IN_REWORK",
  RECEIVER_NOT_ALLOWED: "RECEIVER_NOT_ALLOWED",
  RESTORE_RECIPIENT_UNAVAILABLE: "RESTORE_RECIPIENT_UNAVAILABLE",
  RECIPIENT_NOT_ALLOWED: "RECIPIENT_NOT_ALLOWED",
  RECIPIENT_INVALID: "RECIPIENT_INVALID",
  RECEIVER_INVALID: "RECEIVER_INVALID",
  RECEIVER_NOT_ELIGIBLE: "RECEIVER_NOT_ELIGIBLE",
  ORDER_BLOCKED_BY_DOCUMENT: "ORDER_BLOCKED_BY_DOCUMENT",
  AVANS_NEVALIDAT: "AVANS_NEVALIDAT",
  CONFIRMARE_NECESARA: "CONFIRMARE_NECESARA",
  CONFIRMARE_DOCUMENT_NECESAR: "CONFIRMARE_DOCUMENT_NECESAR",
  DOCUMENT_REPRINT_REASON_REQUIRED: "DOCUMENT_REPRINT_REASON_REQUIRED",
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
    // An older API (always "ro"), a missing or an unknown value all read as Romanian.
    locale: normalizeLocale(raw.locale),
    confirmareScopes: mapConfirmareScopes(raw.confirmareScopes),
    financeScopes: mapFinanceScopes(raw.financeScopes),
    invoiceScopes: mapInvoiceScopes(raw.invoiceScopes),
  };
}

/** Optional (API 2.41.0); an older API or a malformed value grants no finance screen. */
function mapFinanceScopes(value: unknown): { view: string[]; record: string[]; approve: string[] } {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return { view: [], record: [], approve: [] };
  const raw = value as Record<string, unknown>;
  const list = (entry: unknown): string[] => Array.isArray(entry) && entry.every((item) => typeof item === "string") ? entry as string[] : [];
  return { view: list(raw.view), record: list(raw.record), approve: list(raw.approve) };
}

/** Optional (API 2.43.0); an older API or a malformed value shows no invoice scope. */
function mapInvoiceScopes(value: unknown): { issue: string[]; manage: string[] } {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return { issue: [], manage: [] };
  const raw = value as Record<string, unknown>;
  const list = (entry: unknown): string[] => Array.isArray(entry) && entry.every((item) => typeof item === "string") ? entry as string[] : [];
  return { issue: list(raw.issue), manage: list(raw.manage) };
}

/** Optional (API 2.39.0); an older API or a malformed value grants no Confirmare screen. */
function mapConfirmareScopes(value: unknown): { operate: string[]; supervise: string[] } {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return { operate: [], supervise: [] };
  const raw = value as Record<string, unknown>;
  const list = (entry: unknown): string[] => Array.isArray(entry) && entry.every((item) => typeof item === "string") ? entry as string[] : [];
  return { operate: list(raw.operate), supervise: list(raw.supervise) };
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
    // API 2.42.0: a Confirmare-cohort order needs a reason for every reprint; an older API never asks for one.
    reprintReasonRequired: raw.reprintReasonRequired === true,
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

/** API 2.35.0: the effective production stop. Every field is a closed server enumeration; anything else is a server error. */
export function mapProductionStop(value: unknown): import("../../domain/models").ProductionStopView {
  const raw = objectValue(value);
  if (raw.state !== "stopped" || raw.canContinue !== false || typeof raw.managementAttention !== "boolean") throw new StaffServiceError("SERVER_ERROR");
  return {
    state: "stopped",
    reason: oneOf(raw.reason, ["source_cancelled", "marketplace_cancelled", "manager_stop", "legacy_source_cancellation"] as const),
    origin: oneOf(raw.origin, ["source", "local"] as const),
    sourceStatus: oneOf(raw.sourceStatus, ["cancelled", "reactivated", "not_reported"] as const),
    sourceRequest: oneOf(raw.sourceRequest, ["not_requested", "pending", "confirmed", "withdrawn"] as const),
    phase: oneOf(raw.phase, ["not_started", "in_progress", "completed"] as const),
    physical: oneOf(raw.physical, ["not_required", "pending", "verified"] as const),
    since: timestampValue(raw.since),
    canContinue: false,
    managementAttention: raw.managementAttention,
    // API 2.36.0; absent before it (then no request was ever sent).
    sync: raw.sync == null ? null : oneOf(raw.sync, ["awaiting_confirmation", "attention", "confirmed"] as const),
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
  if (raw.paymentGate != null) {
    const gate = objectValue(raw.paymentGate);
    if (typeof gate.blocking !== "boolean") throw new StaffServiceError("SERVER_ERROR");
    order.paymentGate = {
      state: oneOf(gate.state, ["online", "not_required", "confirmed", "waiting", "partial", "unknown", "unverified"] as const),
      label: stringValue(gate.label),
      mode: oneOf(gate.mode, ["observe", "enforce"] as const),
      blocking: gate.blocking,
      reversedAt: gate.reversedAt == null ? null : timestampValue(gate.reversedAt),
    };
  }

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

  if (raw.pieces != null) order.pieces = mapOrderPieces(raw.pieces);
  if (raw.productionStop != null) order.productionStop = mapProductionStop(raw.productionStop);
  if (raw.scannedPiece != null) {
    const scanned = objectValue(raw.scannedPiece);
    order.scannedPiece = { unitId: stringValue(scanned.unitId), trackId: scanned.trackId == null ? null : stringValue(scanned.trackId) };
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

/** Pieces and tracks of an order (API 2.31.0). Unknown actions fail closed: no action is offered. */
/** API 2.34.0 informational PERDEA status of one carrier; absent on older APIs, a present block must be complete (fails closed). */
function mapAssistedCheckpoints(value: unknown): import("../../domain/models").AssistedCheckpoints | null {
  if (value == null) return null;
  const raw = objectValue(value);
  if (!Array.isArray(raw.pieces) || !Array.isArray(raw.unconfirmedLines) || raw.informational !== true) throw new StaffServiceError("SERVER_ERROR");
  try {
    return { stageId: stringValue(raw.stageId), policyVersion: positiveInteger(raw.policyVersion), informational: true, total: nonNegativeInteger(raw.total), performed: nonNegativeInteger(raw.performed),
      notPerformed: nonNegativeInteger(raw.notPerformed), unmarked: nonNegativeInteger(raw.unmarked), pieces: raw.pieces.map(mapAssistedCheckpoint),
      unconfirmedLines: raw.unconfirmedLines.map(item => { const line = objectValue(item); return { itemId: stringValue(line.itemId), lineNumber: positiveInteger(line.lineNumber), name: stringValue(line.name), code: nullableText(line.code) }; }) };
  } catch {
    throw new StaffServiceError("SERVER_ERROR");
  }
}

export function mapOrderPieces(value: unknown): import("../../domain/models").OrderPieces {
  const raw = objectValue(value);
  if (!Array.isArray(raw.units) || !Array.isArray(raw.tracks)) throw new StaffServiceError("SERVER_ERROR");
  const nonNegative = (item: unknown) => { if (typeof item !== "number" || !Number.isInteger(item) || item < 0) throw new StaffServiceError("SERVER_ERROR"); return item; };
  return {
    total: raw.total == null ? null : nonNegative(raw.total), allPlansKnown: raw.allPlansKnown === true, partialRework: raw.partialRework === true, pieceRouting: raw.pieceRouting === true, perdeaAssistedCheckpoints: raw.perdeaAssistedCheckpoints === true, assistedCheckpoints: mapAssistedCheckpoints(raw.assistedCheckpoints), requiresPieceOutcome: raw.requiresPieceOutcome === true, requiresDefectResolution: raw.requiresDefectResolution === true, onOrder: nonNegative(raw.onOrder), inRework: nonNegative(raw.inRework), mixedProgress: raw.mixedProgress === true,
    units: raw.units.map((item) => {
      const unit = objectValue(item);
      return { id: stringValue(unit.id), itemId: stringValue(unit.itemId), index: positiveInteger(unit.index), count: positiveInteger(unit.count), lineNumber: positiveInteger(unit.lineNumber),
        trackId: unit.trackId == null ? null : stringValue(unit.trackId), reworkCycle: positiveInteger(unit.reworkCycle), reviewRequired: unit.reviewRequired === true, productType: nullableText(unit.productType) ?? undefined, fabricMeters: nullableText(unit.fabricMeters), replacementOf: nullableText(unit.replacementOf) };
    }),
    tracks: raw.tracks.map((item) => {
      const track = objectValue(item);
      const stage = objectValue(track.stage);
      const owner = track.owner == null ? null : objectValue(track.owner);
      const open = track.openException == null ? null : objectValue(track.openException);
      const action = track.allowedAction == null ? null : objectValue(track.allowedAction);
      const actionId = action === null ? null : action.id === "claim" || action.id === "complete_stage" ? action.id : null;
      if (!Array.isArray(track.unitIds)) throw new StaffServiceError("SERVER_ERROR");
      return {
        id: stringValue(track.id), number: positiveInteger(track.number),
        stage: { id: stringValue(stage.id), label: nullableText(stage.label) },
        owner: owner === null ? null : { displayName: stringValue(owner.displayName), isMe: owner.isMe === true },
        version: positiveInteger(track.version), reworkCycle: positiveInteger(track.reworkCycle), reworked: track.reworked === true,
        unitIds: track.unitIds.map(stringValue), quantity: nonNegative(track.quantity),
        openException: open === null ? null : {
          id: stringValue(open.id), number: stringValue(open.number), status: oneOf(open.status, exceptionStatuses),
          ...(open.actions == null ? {} : { actions: mapFaultActions(objectValue(open.actions)) }),
          ...(open.orderScope == null ? {} : { orderScope: mapOrderScope(open.orderScope) }),
        },
        waitingForOrder: track.waitingForOrder === true,
        verifyStageId: track.verifyStageId == null ? null : stringValue(track.verifyStageId),
        resolutionConfirmed: track.resolutionConfirmed === true,
        requiresDefectResolution: track.requiresDefectResolution === true, requiresPieceOutcome: track.requiresPieceOutcome === true, kind: track.kind === "route" ? "route" : "rework",
        nextStageId: track.nextStageId == null ? null : stringValue(track.nextStageId),
        changedAt: timestampValue(track.changedAt),
        allowedAction: actionId === null ? null : { id: actionId },
        blockedReason: track.blockedReason == null ? null : stringValue(track.blockedReason),
        assistedCheckpoints: mapAssistedCheckpoints(track.assistedCheckpoints),
      };
    }),
  };
}

export function mapPieceActionResult(value: unknown): import("../../domain/pieces").PieceActionResult {
  const raw = objectValue(value);
  return { orderId: stringValue(raw.orderId), trackId: stringValue(raw.trackId), trackStatus: raw.trackStatus == null ? null : oneOf(raw.trackStatus, ["active", "merged"] as const),
    stageId: raw.stageId == null ? null : stringValue(raw.stageId), pieces: mapOrderPieces(raw.pieces) };
}

export function mapPieceLabels(value: unknown): import("../../domain/pieces").PieceLabels {
  const raw = objectValue(value);
  if (!Array.isArray(raw.labels)) throw new StaffServiceError("SERVER_ERROR");
  return { trackId: stringValue(raw.trackId), orderId: stringValue(raw.orderId), orderNumber: stringValue(raw.orderNumber), reworkCycle: positiveInteger(raw.reworkCycle),
    labels: raw.labels.map((item) => {
      const label = objectValue(item);
      return { unitId: stringValue(label.unitId), piece: stringValue(label.piece), name: stringValue(label.name), code: nullableText(label.code), color: nullableText(label.color),
        payload: label.payload == null ? null : stringValue(label.payload), svg: label.svg == null ? null : stringValue(label.svg) };
    }) };
}

const decimalString = (value: unknown) => {
  if (typeof value !== "string" || !/^\d{1,9}\.\d{3}$/.test(value)) throw new StaffServiceError("SERVER_ERROR");
  return value;
};
const nullableText = (value: unknown) => value === null || value === undefined ? null : stringValue(value);
const nullableTimestamp = (value: unknown) => value === null || value === undefined ? null : timestampValue(value);
const exceptionStatuses = ["awaiting_acknowledgment", "awaiting_approval", "approved", "rejected", "cancelled", "awaiting_supervisor_review", "dismissed", "in_supervisor_custody", "return_rejected", "awaiting_restore_receipt", "restored"] as const;
const faultSources = ["trendhome", "outletperdele", "trendyol", "b2b", "marketplace"] as const;
const person = (value: unknown): { displayName: string } | null => value == null ? null : { displayName: stringValue(objectValue(value).displayName) };

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
function mapOrderScope(value: unknown): NonNullable<FaultException["orderScope"]> {
  const raw = objectValue(value);
  return { orderLineCount: nonNegativeInteger(raw.orderLineCount), orderQuantity: nonNegativeInteger(raw.orderQuantity), affectedLineCount: nonNegativeInteger(raw.affectedLineCount),
    affectedQuantity: nonNegativeInteger(raw.affectedQuantity), wholeOrderMoves: raw.wholeOrderMoves === true,
    // API 2.31.0 additions; absent from older APIs (then the whole order moves, as stated by wholeOrderMoves).
    ...(raw.movingQuantity == null ? {} : { movingQuantity: nonNegativeInteger(raw.movingQuantity) }),
    ...(raw.unaffectedQuantity == null ? {} : { unaffectedQuantity: nonNegativeInteger(raw.unaffectedQuantity) }),
    ...(raw.partial == null ? {} : { partial: raw.partial === true }),
    ...(raw.piecesRecorded == null ? {} : { piecesRecorded: raw.piecesRecorded === true }),
    ...(raw.carrier == null ? {} : { carrier: oneOf(raw.carrier, ["order", "track"] as const) }),
    ...(raw.trackId === undefined ? {} : { trackId: raw.trackId === null ? null : stringValue(raw.trackId) }) };
}
export function mapFaultException(value: unknown): FaultException {
  const raw = objectValue(value);
  const order = objectValue(raw.order), reason = objectValue(raw.reason), actions = raw.actions == null ? null : objectValue(raw.actions);
  const source = stringValue(order.source);
  const mapped: FaultException = {
    id: stringValue(raw.id),
    number: stringValue(raw.number),
    type: raw.type === "tailoring_defect" ? "tailoring_defect" : "cutting_fault",
    status: oneOf(raw.status, exceptionStatuses),
    version: positiveInteger(raw.version),
    order: { id: stringValue(order.id), orderNumber: stringValue(order.orderNumber), sourceName: stringValue(order.sourceName),
      source: (faultSources as readonly string[]).includes(source) ? source as FaultException["order"]["source"] : "unknown" },
    reason: { key: stringValue(reason.key), label: stringValue(reason.label) },
    lineCount: positiveInteger(raw.lineCount),
    faultMeters: raw.faultMeters === null ? null : decimalString(raw.faultMeters),
    unmeasuredLineCount: raw.unmeasuredLineCount == null ? 0 : count(raw.unmeasuredLineCount),
    arrivalNumber: positiveInteger(raw.arrivalNumber),
    reworkCycle: raw.reworkCycle == null ? null : positiveInteger(raw.reworkCycle),
    repeatedError: booleanValue(raw.repeatedError),
    detector: { displayName: stringValue(objectValue(raw.detector).displayName) },
    responsible: { displayName: stringValue(objectValue(raw.responsible).displayName) },
    reportedAt: timestampValue(raw.reportedAt),
    acknowledgedAt: nullableTimestamp(raw.acknowledgedAt),
    resolvedAt: nullableTimestamp(raw.resolvedAt),
    pendingSince: nullableTimestamp(raw.pendingSince),
    detectedStage: raw.detectedStage == null ? { id: "workshop-receiving", label: null } : { id: stringValue(objectValue(raw.detectedStage).id), label: nullableText(objectValue(raw.detectedStage).label) },
    confirmedReason: raw.confirmedReason == null ? null : { key: stringValue(objectValue(raw.confirmedReason).key), label: stringValue(objectValue(raw.confirmedReason).label), cuttingAttributable: booleanValue(objectValue(raw.confirmedReason).cuttingAttributable) },
    supervisor: person(raw.supervisor),
    receiver: person(raw.receiver),
    physicalCustodian: person(raw.physicalCustodian),
    responsibilityPosition: raw.responsibilityPosition == null ? null : oneOf(raw.responsibilityPosition, ["accepted", "disputed", "not_stated"] as const),
    attribution: raw.attribution == null ? null : oneOf(raw.attribution, ["cutter", "none"] as const),
    returnRequestedAt: nullableTimestamp(raw.returnRequestedAt),
    restoreRecipient: person(raw.restoreRecipient),
    restoreRequestedAt: nullableTimestamp(raw.restoreRequestedAt),
    restoreReceivedAt: nullableTimestamp(raw.restoreReceivedAt),
    cancelledByRoot: raw.cancelledByRoot === true,
    ...(raw.orderScope == null ? {} : { orderScope: mapOrderScope(raw.orderScope) }),
    role: raw.role == null ? null : oneOf(raw.role, ["responsible", "detector", "receiver", "recipient", "supervisor"] as const),
    // Absent flags (an older API) are false: the screen never offers an action the server did not grant.
    actions: mapFaultActions(actions),
  };
  if ("detectorComment" in raw) mapped.detectorComment = nullableText(raw.detectorComment);
  if ("supervisorComment" in raw) mapped.supervisorComment = nullableText(raw.supervisorComment);
  if (raw.currentStage != null) mapped.currentStage = { id: stringValue(objectValue(raw.currentStage).id), label: nullableText(objectValue(raw.currentStage).label) };
  if ("cuttingHandoff" in raw) {
    const handoff = raw.cuttingHandoff == null ? null : objectValue(raw.cuttingHandoff);
    mapped.cuttingHandoff = handoff === null ? null : { employee: stringValue(handoff.employee), at: timestampValue(handoff.at), arrivalNumber: positiveInteger(handoff.arrivalNumber) };
  }
  if (raw.timeline != null) {
    if (!Array.isArray(raw.timeline)) throw new StaffServiceError("SERVER_ERROR");
    mapped.timeline = raw.timeline.map((item) => {
      const entry = objectValue(item);
      const details = entry.details == null ? null : objectValue(entry.details);
      return { action: stringValue(entry.action), actor: stringValue(entry.actor), at: timestampValue(entry.at), version: positiveInteger(entry.version), comment: details && typeof details.comment === "string" ? details.comment : null };
    });
  }
  if ("acknowledgmentComment" in raw) mapped.acknowledgmentComment = nullableText(raw.acknowledgmentComment);
  if (raw.lines != null) {
    if (!Array.isArray(raw.lines)) throw new StaffServiceError("SERVER_ERROR");
    mapped.lines = raw.lines.map((item) => {
      const line = objectValue(item);
      return { itemId: stringValue(line.itemId), lineNumber: positiveInteger(line.lineNumber), name: stringValue(line.name), code: nullableText(line.code),
        variant: nullableText(line.variant), color: nullableText(line.color), quantity: positiveInteger(line.quantity), meters: line.meters === null ? null : decimalString(line.meters),
        ...(line.affectedQuantity == null ? {} : { affectedQuantity: nonNegativeInteger(line.affectedQuantity) }) };
    });
  }
  if (raw.affectedPieces != null) {
    if (!Array.isArray(raw.affectedPieces)) throw new StaffServiceError("SERVER_ERROR");
    mapped.affectedPieces = raw.affectedPieces.map((item) => {
      const piece = objectValue(item);
      return { unitId: stringValue(piece.unitId), itemId: stringValue(piece.itemId), piece: stringValue(piece.piece), index: positiveInteger(piece.index), count: positiveInteger(piece.count),
        lineNumber: piece.lineNumber == null ? null : positiveInteger(piece.lineNumber), name: nullableText(piece.name), code: nullableText(piece.code), color: nullableText(piece.color),
        reworkCycleAtReport: positiveInteger(piece.reworkCycleAtReport), trackId: piece.trackId == null ? null : stringValue(piece.trackId) };
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
  return raw.items.map((item) => {
    const reason = objectValue(item);
    return { key: stringValue(reason.key), label: stringValue(reason.label), requiresComment: booleanValue(reason.requiresComment), cuttingAttributable: reason.cuttingAttributable == null ? true : booleanValue(reason.cuttingAttributable) };
  });
}

/** Granted actions of one request; an absent flag is false. */
function mapFaultActions(actions: Record<string, unknown> | null): FaultException["actions"] {
  return {
    canAcknowledge: flag(actions, "canAcknowledge"), statesResponsibility: flag(actions, "statesResponsibility"), canRequestRereview: flag(actions, "canRequestRereview"),
    canReview: flag(actions, "canReview"), canRequestReturn: flag(actions, "canRequestReturn"), canRestore: flag(actions, "canRestore"),
    canAskManagersAgain: flag(actions, "canAskManagersAgain"), canAddNote: flag(actions, "canAddNote"), canConfirmRestore: flag(actions, "canConfirmRestore"),
  };
}

function flag(actions: Record<string, unknown> | null, name: string): boolean {
  return actions !== null && actions[name] === true;
}

export function mapQualityCapabilities(value: unknown): QualityCapabilities {
  const raw = objectValue(value);
  const sources = Array.isArray(raw.supervisedSources) ? raw.supervisedSources.filter((item): item is QualityCapabilities["supervisedSources"][number] => typeof item === "string" && (faultSources as readonly string[]).includes(item)) : [];
  return { tailoringSupervisor: raw.tailoringSupervisor === true, supervisedSources: sources, supervisedFlowActive: raw.supervisedFlowActive === true, partialRework: raw.partialRework === true };
}

function mapReceivers(value: unknown): { id: string; displayName: string }[] {
  const raw = objectValue(value);
  if (!Array.isArray(raw.items)) throw new StaffServiceError("SERVER_ERROR");
  return raw.items.map((item) => { const receiver = objectValue(item); return { id: stringValue(receiver.id), displayName: stringValue(receiver.displayName) }; });
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

/** Strict mapping of a committed cutting intake: the outcome and the order exactly as the server reloaded it. */
export function mapCuttingIntake(value: unknown): import("../../domain/cutting").CuttingIntakeResult {
  const raw = objectValue(value);
  if (raw.outcome !== "started" && raw.outcome !== "claimed" && raw.outcome !== "already_mine") throw new StaffServiceError("SERVER_ERROR");
  return { outcome: raw.outcome, order: mapProductionOrder(raw.order) };
}

function createRequest(apiBaseUrl: string, options: ProductionServicesOptions, onSessionExpired: (error: StaffServiceError) => void) {
  const fetchImpl = options.fetchImpl ?? fetch;
  const isOnline = options.isOnline ?? (() => typeof navigator === "undefined" || navigator.onLine);
  const nextRequestId = options.requestId ?? (() => globalThis.crypto?.randomUUID?.() ?? `staff-${Date.now()}`);
  let csrfToken = "";

  async function errorFromResponse(response: Response, path: string) {
    let backendCode = "";
    let details: Record<string, unknown> | undefined;
    let errorDetails: unknown = null;
    try {
      const payload = objectValue(await response.json());
      const error = objectValue(payload.error);
      errorDetails = error.details ?? null;
      backendCode = typeof error.code === "string" ? error.code : "";
      // Only the active revision number is kept from details (an old QR scan tells which revision to use).
      if (error.details && typeof error.details === "object" && typeof (error.details as Record<string, unknown>).activeRevisionNumber === "number") details = { activeRevisionNumber: (error.details as Record<string, unknown>).activeRevisionNumber };
      if (error.details && typeof error.details === "object" && typeof (error.details as Record<string, unknown>).activeQrRevision === "number") details = { activeQrRevision: (error.details as Record<string, unknown>).activeQrRevision };
      // The cutting workload count tells the scanner which multiple-job confirmation to ask for.
      if (error.details && typeof error.details === "object" && typeof (error.details as Record<string, unknown>).ownedCount === "number") details = { ownedCount: (error.details as Record<string, unknown>).ownedCount };
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
    // Confirmare refusals keep their code, the current version and the missing conditions, never anything else.
    if (path.startsWith("/confirmare/")) details = { ...details, ...confirmareErrorDetails(errorDetails) };
    const error = new StaffServiceError(backendErrorCodes[backendCode] ?? fallback, undefined, /^(PLAN_|SOURCE_PLAN_|PIECE_OUTCOME|UNIT_RETIRED|ORDER_PIECES_|ORDER_ROUTE_|CUSTODY_|MATERIAL_|EXACT_PIECES_|CUTTING_NOT_|RECONCILIATION_|ASSISTED_|CONFIRMARE_)/.test(backendCode) || /\/manufacturing-plan|^\/production-units|^\/confirmare\//.test(path) ? { ...details, backendCode, httpStatus: response.status } : details);
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
    confirmStageTransition: (id, input, requestOptions) => http.request(`/orders/${encodeURIComponent(id)}/transition`, { method: "POST", body: JSON.stringify({ expectedVersion: input.expectedVersion, ...(input.defectResolved ? { defectResolved: true } : {}), ...(input.pieceOutcomes ? { pieceOutcomes: input.pieceOutcomes } : {}) }), headers: { "Idempotency-Key": input.idempotencyKey }, signal: requestOptions?.signal }, mapProductionOrder),
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
    // POST keeps the scanned code out of URLs and access logs. An API older than 2.34.1 answers the route with 404.
    scan: async (token, requestOptions) => {
      const response = await http.send("/production-exceptions/scan", { method: "POST", body: JSON.stringify({ token }), signal: requestOptions?.signal });
      if (response.status === 404) return null;
      if (!response.ok) throw await http.errorFromResponse(response, "/production-exceptions/scan");
      let payload: unknown;
      try { payload = await response.json(); } catch { throw new StaffServiceError("SERVER_ERROR"); }
      return mapFaultList(payload);
    },
    reasons: (requestOptions) => http.request("/production-exceptions/reasons", { signal: requestOptions?.signal }, mapReasons),
    report: (orderId, input) => http.request(`/orders/${encodeURIComponent(orderId)}/fault-reports`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      // Exact pieces (API 2.31.0) when chosen; otherwise the lines, exactly as older APIs expect.
      body: JSON.stringify({ expectedVersion: input.expectedVersion, ...(input.units ? { units: input.units, ...(input.trackId ? { trackId: input.trackId } : {}) } : { itemIds: input.itemIds }), reasonKey: input.reasonKey, ...(input.recoversExceptionId ? { recoversExceptionId: input.recoversExceptionId } : {}), ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    acknowledge: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/acknowledge`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, confirmed: true, qrToken: input.qrToken, ...(input.comment ? { comment: input.comment } : {}), ...(input.responsibility ? { responsibility: input.responsibility } : {}) }) }, mapFaultException),
    rereview: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/rereview`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, comment: input.comment }) }, mapFaultException),
    capabilities: (requestOptions) => http.request("/production-exceptions/capabilities", { signal: requestOptions?.signal }, mapQualityCapabilities),
    supervisorList: (view, requestOptions) => http.request(`/production-exceptions/supervisor?view=${encodeURIComponent(view)}`, { signal: requestOptions?.signal }, mapFaultList),
    receivers: (id, requestOptions) => http.request(`/production-exceptions/${encodeURIComponent(id)}/receivers`, { signal: requestOptions?.signal }, mapReceivers),
    review: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/review`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, decision: input.decision, ...(input.decision === "accept" ? { reasonKey: input.reasonKey, qrToken: input.qrToken } : {}), ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    sendReturn: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/return`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, ...(input.receiverEmployeeId ? { receiverEmployeeId: input.receiverEmployeeId } : {}), ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    restoreRecipients: (id, requestOptions) => http.request(`/production-exceptions/${encodeURIComponent(id)}/restore-recipients`, { signal: requestOptions?.signal }, mapReceivers),
    restore: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/restore`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, qrToken: input.qrToken, comment: input.comment, ...(input.recipientEmployeeId ? { recipientEmployeeId: input.recipientEmployeeId } : {}), ...(input.cancelReturn ? { cancelReturn: true } : {}) }) }, mapFaultException),
    receiveRestore: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/receive`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, confirmed: true, qrToken: input.qrToken, ...(input.comment ? { comment: input.comment } : {}) }) }, mapFaultException),
    addNote: (id, input) => http.request(`/production-exceptions/${encodeURIComponent(id)}/notes`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
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
    intake: (input, key, signal) => http.request("/cutting/intake", { method: "POST", headers: { "Idempotency-Key": key }, signal,
      body: JSON.stringify({ qrToken: input.qrToken, ...(input.confirmedMultiple !== undefined ? { confirmedMultiple: input.confirmedMultiple } : {}), ...(input.ownedCount !== undefined ? { ownedCount: input.ownedCount } : {}) }) }, mapCuttingIntake),
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
    correctIdentity: (packageId, input, key) => http.request(`${trendyolPackage(packageId)}/correct-identity`, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapTrendyolDetail),
  };
  const workspace: WorkspaceApi = {
    stageQueue: (stageId, signal) => http.request(`/orders/stage-queue?stage=${encodeURIComponent(stageId)}`, { signal }, mapStageQueue),
    stageSummary: (signal) => http.request("/orders/stage-summary", { signal }, mapStageSummary),
  };
  const management: ManagementApi = { productionOverview: (signal) => http.request("/management/production-overview", { signal }, mapProductionOverview) };
  const pieces: import("../../domain/pieces").PieceApi = {
    claim: (trackId, input) => http.request(`/production-tracks/${encodeURIComponent(trackId)}/claim`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, qrToken: input.qrToken }) }, mapPieceActionResult),
    complete: (trackId, input) => http.request(`/production-tracks/${encodeURIComponent(trackId)}/complete`, { method: "POST", headers: { "Idempotency-Key": input.idempotencyKey },
      body: JSON.stringify({ expectedVersion: input.expectedVersion, ...(input.defectResolved ? { defectResolved: true } : {}), ...(input.pieceOutcomes ? { pieceOutcomes: input.pieceOutcomes } : {}), ...(input.pieceOutcome ? { pieceOutcome: input.pieceOutcome } : {}), ...(input.originalDisposition ? { originalDisposition: input.originalDisposition } : {}) }) }, mapPieceActionResult),
    labels: (trackId, signal) => http.request(`/production-tracks/${encodeURIComponent(trackId)}/labels`, { signal }, mapPieceLabels),
  };
  const physical: import("../../domain/physical").StaffPhysicalApi = {
    plan: (id, signal) => http.request(`/orders/${encodeURIComponent(id)}/manufacturing-plan`, { signal }, mapManufacturingPlan),
    confirmPlan: (id, lineId, input, key) => http.request(`/orders/${encodeURIComponent(id)}/manufacturing-plan/lines/${encodeURIComponent(lineId)}/confirm`, { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }, mapManufacturingPlan),
    history: (id, signal) => http.request(`/production-units/${encodeURIComponent(id)}/history`, { signal }, mapPhysicalHistory),
    reconcileRoute: (id, input, key) => http.request(`/production-units/${encodeURIComponent(id)}/route-evidence`, { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }, mapManufacturingPlan),
    assistedCheckpoint: (id, input, key) => http.request(`/production-units/${encodeURIComponent(id)}/assisted-checkpoint`, { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }, mapManufacturingPlan),
    locations: signal => http.request("/production-locations", { signal }, value => value as { locations: { id: string; label: string }[] }),
    custody: (id, input, key) => http.request(`/production-units/${encodeURIComponent(id)}/custody`, { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }),
    reunite: (input, key) => http.request("/production-units/reunite", { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }),
    material: (id, input, key) => http.request(`/production-units/${encodeURIComponent(id)}/material`, { method: "POST", body: JSON.stringify(input), headers: { "Idempotency-Key": key } }),
  };
  const sourceQuery = (source: string | null) => (source ? `?${new URLSearchParams({ source }).toString()}` : "");
  const confirmareOrder = (globalOrderId: string, action: string) => `/confirmare/orders/${encodeURIComponent(globalOrderId)}/${action}`;
  const command = <T>(path: string, input: unknown, key: string, map: (value: unknown) => T) => http.request(path, { method: "POST", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, map);
  const confirmare: ConfirmareApi = {
    pool: (view, source, signal) => http.request(`/confirmare/pool?view=${view}${source ? `&source=${encodeURIComponent(source)}` : ""}`, { signal }, mapConfirmarePool),
    handoffList: (source, signal) => http.request(`/confirmare/handoff${source ? `?source=${encodeURIComponent(source)}` : ""}`, { signal }, mapHandoffList),
    detail: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}`, { signal }, mapConfirmareDetail),
    next: (source, key) => command("/confirmare/next", { source }, key, (value) => { const assigned = objectValue(value).assigned; return assigned == null ? null : mapConfirmareItem(assigned); }),
    claim: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "claim"), input, key, mapConfirmareItem),
    release: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "release"), input, key, mapConfirmareItem),
    reassign: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "reassign"), input, key, mapConfirmareItem),
    reopen: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "reopen"), input, key, mapConfirmareItem),
    approve: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "approve"), input, key, mapConfirmareItem),
    reject: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "reject"), input, key, mapConfirmareItem),
    contact: (globalOrderId, input, key) => command(confirmareOrder(globalOrderId, "contact"), input, key, (value) => { const raw = objectValue(value); return { contact: mapConfirmareContact(raw.contact), item: mapConfirmareItem(raw.item), proformaCycle: mapContactCycle(raw.proformaCycle) }; }),
    availability: (signal) => http.request("/confirmare/availability", { signal }, mapConfirmareAvailability),
    setAvailability: (input, key) => http.request("/confirmare/availability", { method: "PUT", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapConfirmareAvailability),
    team: (signal) => http.request("/confirmare/team", { signal }, mapConfirmareTeam),
    alerts: (signal) => http.request("/confirmare/alerts", { signal }, (value) => { const raw = objectValue(value); return { items: itemsOf(raw).map(mapConfirmareAlert), inactivityMinutes: nonNegativeInteger(raw.inactivityMinutes) }; }),
    // Customer data: never kept in the browser's HTTP cache (the server also answers no-store).
    sourceOrder: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source`, { signal, cache: "no-store" }, mapShopRead),
    // Phase 4B: controlled shop edits. Amounts come from the shop; nothing is cached or kept.
    sourceCatalog: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source/catalog`, { signal, cache: "no-store" }, (value) => mapEditCatalog(objectValue(value).catalog)),
    sourceProduct: (globalOrderId, sku) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source/product`, { method: "POST", cache: "no-store", body: JSON.stringify({ sku }) }, (value) => mapEditProduct(objectValue(value).product)),
    sourcePreview: (globalOrderId, input) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source/preview`, { method: "POST", cache: "no-store", body: JSON.stringify(input) }, (value) => { const raw = objectValue(objectValue(value).preview); return { ...mapEditResult(raw), previewToken: hexToken(raw.previewToken) }; }),
    sourceApply: (globalOrderId, input, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source/apply`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapEditOutcome),
    sourceReconcile: (globalOrderId, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/source/reconcile`, { method: "POST", cache: "no-store", body: JSON.stringify({ idempotencyKey: key }) }, mapEditOutcome),
    // Confirmare finance (API 2.41.0): amounts are read when asked, never cached; actions need an Idempotency-Key.
    financeRead: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/finance`, { signal, cache: "no-store" }, mapFinanceRead),
    financeAct: (globalOrderId, action, input, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/finance/${action.replace(/_/g, "-")}`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapFinanceOutcome),
    financeReconcile: (globalOrderId, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/finance/reconcile`, { method: "POST", cache: "no-store", body: JSON.stringify({ idempotencyKey: key }) }, mapFinanceOutcome),
    financeQueue: (task, source, signal) => http.request(`/confirmare/finance${task || source ? `?${new URLSearchParams({ ...(task ? { task } : {}), ...(source ? { source } : {}) }).toString()}` : ""}`, { signal, cache: "no-store" }, mapFinanceQueue),
    // API 2.44.0: the shop's bank transfers that match no order (read from the shop when shown, never cached).
    transferExceptions: (source, signal) => http.request(`/confirmare/finance/transfer-exceptions${source ? `?${new URLSearchParams({ source }).toString()}` : ""}`, { signal, cache: "no-store" }, mapTransferExceptionList),
    transferExceptionAct: (source, action, input, key) => http.request(`/confirmare/finance/transfer-exceptions/${action}?${new URLSearchParams({ source }).toString()}`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapExceptionOutcome),
    // SmartBill invoicing (API 2.43.0): the state is read when shown, never cached; actions need an Idempotency-Key.
    invoiceRead: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/invoice`, { signal, cache: "no-store" }, mapInvoiceRead),
    invoiceAct: (globalOrderId, action, input, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/invoice/${action.replace(/_/g, "-")}`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapInvoiceOutcome),
    invoiceReconcile: (globalOrderId, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/invoice/reconcile`, { method: "POST", cache: "no-store", body: JSON.stringify({ idempotencyKey: key }) }, mapInvoiceOutcome),
    // API 2.44.0: the operator's advance proforma, the routing to a shop employee and the shop employee's QR scan.
    proformaAct: (globalOrderId, action, input, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/proforma/${PROFORMA_ROUTE[action]}`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapInvoiceOutcome),
    shopCandidates: (globalOrderId, signal) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/shop-employees`, { signal, cache: "no-store" }, (value) => listOf(objectValue(value).items, 200, (entry) => { const item = objectValue(entry); return { id: uuidValue(item.id), name: textValue(item.name, 200) }; })),
    assignShop: (globalOrderId, input, key) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/shop-assignment`, { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, (value) => mapShopAssignment(objectValue(value).assignment)),
    verifyInvoiceQr: (globalOrderId, qrToken) => http.request(`/confirmare/orders/${encodeURIComponent(globalOrderId)}/invoice/qr`, { method: "POST", cache: "no-store", body: JSON.stringify({ qrToken }) }, (value) => { const raw = objectValue(value); if (raw.verified !== true) throw new StaffServiceError("SERVER_ERROR"); return { verified: true as const, validSeconds: nonNegativeInteger(raw.validSeconds) }; }),
    shopQueue: (signal) => http.request("/confirmare/shop-queue", { signal, cache: "no-store" }, (value) => listOf(objectValue(value).items, 200, mapShopQueueItem)),
    // API 2.46.0: the proforma cycles, the bank checks, the shop operations (reactivation, AWB, card refund), e-Factura, health.
    cycleAttention: (source, signal) => http.request(`/confirmare/cycles${sourceQuery(source)}`, { signal, cache: "no-store" }, mapCycleAttention),
    bankChecks: (source, signal) => http.request(`/confirmare/finance/bank-checks${sourceQuery(source)}`, { signal, cache: "no-store" }, mapBankChecks),
    recordBankCheck: (source, key) => http.request("/confirmare/finance/bank-check", { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify({ source }) }, (value) => mapBankCheck(objectValue(value).check)),
    advanceCycle: (globalOrderId) => http.request(confirmareOrder(globalOrderId, "cycle/advance"), { method: "POST", cache: "no-store", body: "{}" }, (value) => mapProformaCycle(objectValue(value).proformaCycle)),
    operations: (globalOrderId, signal) => http.request(confirmareOrder(globalOrderId, "operations"), { signal, cache: "no-store" }, mapOperationsState),
    operate: (globalOrderId, path, input, key) => http.request(confirmareOrder(globalOrderId, path), { method: "POST", cache: "no-store", headers: { "Idempotency-Key": key }, body: JSON.stringify(input) }, mapOperationOutcome),
    operationsReconcile: (globalOrderId, key) => http.request(confirmareOrder(globalOrderId, "operations/reconcile"), { method: "POST", cache: "no-store", body: JSON.stringify({ idempotencyKey: key }) }, mapOperationOutcome),
    async awbLabel(globalOrderId) {
      const response = await http.send(confirmareOrder(globalOrderId, "awb/label"), { cache: "no-store" });
      if (!response.ok) throw await http.errorFromResponse(response, "/confirmare/awb/label");
      return response.blob();
    },
    awbQueue: (source, signal) => http.request(`/confirmare/awb-queue${sourceQuery(source)}`, { signal, cache: "no-store" }, mapAwbQueue),
    efactura: (source, state, signal) => http.request(`/confirmare/efactura${source || state ? `?${new URLSearchParams({ ...(source ? { source } : {}), ...(state ? { state } : {}) }).toString()}` : ""}`, { signal, cache: "no-store" }, mapEfacturaQueue),
    recordEfactura: (taskId, input) => http.request(`/confirmare/efactura/${encodeURIComponent(taskId)}`, { method: "POST", cache: "no-store", body: JSON.stringify(input) }, (value) => mapEfacturaTask(objectValue(value).task)),
    health: (source, signal) => http.request(`/confirmare/health${sourceQuery(source)}`, { signal, cache: "no-store" }, mapHealth),
  };
  return { physical, auth, employee, orders, activity, workflow, exceptions, live, cutting, documents, authority, productionQr, trendyol, workspace, management, pieces, confirmare, mode: "production" };
}

// ---------------------------------------------------------------- Confirmare workspace (strict; unexpected shapes fail closed)
const confirmareStatuses = ["pending", "approved", "rejected", "cancelled"] as const;
const financialStates: readonly FinancialState[] = ["cleared", "waiting", "partial", "unknown"];
const nullableString = (value: unknown): string | null => value === null || value === undefined ? null : stringValue(value);
const nullableNumber = (value: unknown): number | null => {
  if (value === null || value === undefined) return null;
  if (typeof value !== "number" || !Number.isFinite(value)) throw new StaffServiceError("SERVER_ERROR");
  return value;
};
const fingerprintValue = (value: unknown): string => { const text = stringValue(value); if (!/^[0-9a-f]{64}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };

/** Only what a Confirmare screen needs from a refusal: the current version and the missing conditions. */
export function confirmareErrorDetails(value: unknown): Record<string, unknown> {
  if (value === null || typeof value !== "object" || Array.isArray(value)) return {};
  const raw = value as Record<string, unknown>;
  const out: Record<string, unknown> = {};
  if (typeof raw.currentVersion === "number" && Number.isInteger(raw.currentVersion)) out.currentVersion = raw.currentVersion;
  if (Array.isArray(raw.missing) && raw.missing.every((item) => typeof item === "string")) out.missing = raw.missing;
  // Phase 4B: why the shop refused an edit, the shop's current order version and the blockers (codes only).
  if (typeof raw.reason === "string" && /^[a-z_]{2,60}$/.test(raw.reason)) out.editReason = raw.reason;
  if (typeof raw.version === "string" && /^sha256:[0-9a-f]{64}$/.test(raw.version)) out.editVersion = raw.version;
  if (Array.isArray(raw.blockers)) { try { out.editBlockers = listOf(raw.blockers, 100, mapEditBlocker); } catch { out.editBlockers = []; } }
  // API 2.43.0: the invoice refusal codes (Arasya's or the shop's) behind a refused invoice action.
  if (Array.isArray(raw.blockers) && raw.blockers.length <= 40 && raw.blockers.every((item) => typeof item === "string" && /^[a-z_]{2,60}$/.test(item))) out.invoiceBlockers = raw.blockers;
  // Phase 5: the shop's current finance revision after a refused finance action (`reason` is editReason above).
  if (typeof raw.revision === "string" && /^sha256:[0-9a-f]{64}$/.test(raw.revision)) out.financeRevision = raw.revision;
  return out;
}

export function mapConfirmareItem(value: unknown): ConfirmareItem {
  const raw = objectValue(value);
  const financial = objectValue(raw.financial);
  const contact = raw.contact == null ? null : objectValue(raw.contact);
  const assignment = raw.assignment == null ? null : objectValue(raw.assignment);
  const decision = raw.decision == null ? null : objectValue(raw.decision);
  return {
    globalOrderId: stringValue(raw.globalOrderId),
    orderNumber: stringValue(raw.orderNumber),
    sourceKey: stringValue(raw.sourceKey),
    status: oneOf(raw.status, confirmareStatuses),
    version: positiveInteger(raw.version),
    workflowCohort: stringValue(raw.workflowCohort),
    openedAt: timestampValue(raw.openedAt),
    submittedAt: raw.submittedAt == null ? null : timestampValue(raw.submittedAt),
    productionStageId: stringValue(raw.productionStageId),
    commerceStatus: nullableString(raw.commerceStatus),
    commerceClosed: booleanValue(raw.commerceClosed),
    payment: { state: stringValue(objectValue(raw.payment).state) },
    // An older API (2.38) has no `open`: unknown reads as closed.
    financial: { state: oneOf(financial.state, financialStates), basis: nullableString(financial.basis), open: financial.open === true, attention: financial.attention == null ? null : oneOf(financial.attention, FINANCIAL_ATTENTION) },
    pricingAvailable: raw.pricingAvailable === true,
    dueDate: raw.dueDate == null ? null : stringValue(raw.dueDate),
    productionCleared: booleanValue(raw.productionCleared),
    contact: contact === null ? null : { outcome: oneOf(contact.outcome, CONTACT_OUTCOMES), at: timestampValue(contact.at), attempts: positiveInteger(contact.attempts), current: booleanValue(contact.current) },
    assignment: assignment === null ? null : { employeeId: stringValue(assignment.employeeId), displayName: stringValue(assignment.displayName), at: timestampValue(assignment.at), kind: stringValue(assignment.kind) },
    decision: decision === null ? null : { at: timestampValue(decision.at), by: nullableString(decision.by), reasonCode: nullableString(decision.reasonCode) },
  };
}

/** API 2.42.0: the production handoff of an order; strict (codes only, never customer data). */
export function mapConfirmareHandoff(value: unknown): ConfirmareHandoff {
  const raw = objectValue(value);
  const actions = objectValue(raw.actions);
  const document = raw.document == null ? null : objectValue(raw.document);
  return {
    state: oneOf(raw.state, HANDOFF_STATES),
    blockers: stringList(raw.blockers).map((blocker) => oneOf(blocker, HANDOFF_BLOCKERS)),
    stageId: raw.stageId == null ? null : stringValue(raw.stageId),
    document: document === null ? null : { status: oneOf(document.status, DOCUMENT_STATES), revision: document.revision == null ? null : positiveInteger(document.revision), prints: nonNegativeInteger(document.prints) },
    actions: { generate: booleanValue(actions.generate), print: booleanValue(actions.print) },
  };
}

/** API 2.42.0: the production handoff list; strict, identity and next step only. */
export function mapHandoffList(value: unknown): HandoffItem[] {
  return itemsOf(objectValue(value)).map((entry) => {
    const raw = objectValue(entry);
    return {
      globalOrderId: stringValue(raw.globalOrderId), orderNumber: stringValue(raw.orderNumber), sourceKey: stringValue(raw.sourceKey),
      dueDate: raw.dueDate == null ? null : stringValue(raw.dueDate), submittedAt: raw.submittedAt == null ? null : timestampValue(raw.submittedAt),
      approvedAt: raw.approvedAt == null ? null : timestampValue(raw.approvedAt), step: oneOf(raw.step, ["ready", "print", "awaiting_cutting"] as const),
    };
  });
}

export function mapConfirmarePool(value: unknown): ConfirmarePool {
  const raw = objectValue(value);
  return { view: oneOf(raw.view, ["mine", "open", "all", "followup"] as const), items: itemsOf(raw).map(mapConfirmareItem), limit: positiveInteger(raw.limit), targetOpenPerOperator: positiveInteger(raw.targetOpenPerOperator) };
}

function mapConfirmareLine(value: unknown): ConfirmareLine {
  const raw = objectValue(value);
  const options = Array.isArray(raw.options) ? raw.options.map((option) => { const entry = objectValue(option); return { label: stringValue(entry.label), value: stringValue(entry.value) }; }) : [];
  return {
    lineId: stringValue(raw.lineId), lineNumber: positiveInteger(raw.lineNumber), name: stringValue(raw.name),
    productCode: nullableString(raw.productCode), variant: nullableString(raw.variant), color: nullableString(raw.color),
    width: nullableNumber(raw.width), height: nullableNumber(raw.height), unit: nullableString(raw.unit), meters: nullableNumber(raw.meters),
    quantity: positiveInteger(raw.quantity), options, notes: nullableString(raw.notes), confirmed: booleanValue(raw.confirmed),
  };
}

export function mapConfirmareContact(value: unknown): ConfirmareContact {
  const raw = objectValue(value);
  return { attempt: positiveInteger(raw.attempt), outcome: oneOf(raw.outcome, CONTACT_OUTCOMES), by: nullableString(raw.by), at: timestampValue(raw.at), current: booleanValue(raw.current) };
}

function mapConfirmareEvent(value: unknown): ConfirmareEvent {
  const raw = objectValue(value);
  return { action: stringValue(raw.action), actor: nullableString(raw.actor), previousStatus: nullableString(raw.previousStatus), status: stringValue(raw.status), previousAssignee: nullableString(raw.previousAssignee), assignee: nullableString(raw.assignee), reasonCode: nullableString(raw.reasonCode), version: positiveInteger(raw.version), at: timestampValue(raw.at) };
}

/** The customer block: hidden unless the server says it is visible; a full telephone number is never accepted. */
function mapConfirmareCustomer(value: unknown): ConfirmareCustomer {
  const raw = value == null ? {} : objectValue(value);
  if (raw.visible !== true) return { visible: false, phoneAvailable: false };
  const phoneMasked = nullableString(raw.phoneMasked);
  // Defence in depth: a masked phone keeps at most two digits; anything longer is treated as unavailable.
  const safePhone = phoneMasked !== null && (phoneMasked.match(/[0-9]/g) ?? []).length <= 2 ? phoneMasked : null;
  return { visible: true, name: nullableString(raw.name), company: nullableString(raw.company), addressLines: Array.isArray(raw.addressLines) ? raw.addressLines.map(stringValue) : [], phoneMasked: safePhone, phoneAvailable: false };
}

export function mapConfirmareDetail(value: unknown): ConfirmareDetail {
  const raw = objectValue(value);
  const review = objectValue(raw.review);
  const source = raw.source == null ? {} : objectValue(raw.source);
  const production = objectValue(raw.production);
  return {
    ...mapConfirmareItem(raw),
    review: { fingerprint: fingerprintValue(review.fingerprint), lineIds: stringList(review.lineIds), pricingAvailable: booleanValue(review.pricingAvailable), callRecorded: review.callRecorded === true, contactAvailable: review.contactAvailable === true },
    lines: Array.isArray(raw.lines) ? raw.lines.map(mapConfirmareLine) : [],
    contacts: Array.isArray(raw.contacts) ? raw.contacts.map(mapConfirmareContact) : [],
    customer: mapConfirmareCustomer(raw.customer),
    source: { commerceStatusLabel: nullableString(source.commerceStatusLabel), changedAt: source.changedAt == null ? null : timestampValue(source.changedAt), lastSeenAt: source.lastSeenAt == null ? null : timestampValue(source.lastSeenAt), freshness: nullableString(source.freshness) },
    production: { cleared: booleanValue(production.cleared), missing: stringList(production.missing).map((condition) => oneOf(condition, CLEARANCE_CONDITIONS)) },
    events: Array.isArray(raw.events) ? raw.events.map(mapConfirmareEvent) : [],
    // An older API (2.45) has no cycle: no counter and no timer are shown.
    cycle: mapOrderCycle(raw.cycle),
    // An older API (2.41) has no handoff state: the panel is not shown.
    handoff: raw.handoff == null ? null : mapConfirmareHandoff(raw.handoff),
    // An older API (2.42) has no invoicing: nothing can be read or issued.
    invoice: raw.invoice == null ? { configured: false, read: false, issue: false, manage: false, proforma: false, route: false } : (() => {
      const invoice = objectValue(raw.invoice);
      return { configured: invoice.configured === true, read: invoice.read === true, issue: invoice.issue === true, manage: invoice.manage === true, proforma: invoice.proforma === true, route: invoice.route === true };
    })(),
    // An older API (2.40) has no finance: nothing can be read or recorded.
    finance: raw.finance == null ? { configured: false, read: false, record: false, approve: false } : (() => {
      const finance = objectValue(raw.finance);
      return { configured: finance.configured === true, read: finance.read === true, record: finance.record === true, approve: finance.approve === true };
    })(),
    // An older API (2.39) has no source access: nothing can be read from the shop.
    sourceAccess: raw.sourceAccess == null ? { configured: false, read: false, edit: false, changePending: false, openEdits: [] } : (() => {
      const access = objectValue(raw.sourceAccess);
      return { configured: access.configured === true, read: access.read === true, edit: access.edit === true, changePending: access.changePending === true, openEdits: access.openEdits == null ? [] : listOf(access.openEdits, 20, mapOpenEdit) };
    })(),
  };
}

// ---- The shop's own order (API 2.40.0): strict; anything unexpected is refused, never half shown
const MONEY = /^-?[0-9]{1,12}\.[0-9]{0,4}$/;
const textValue = (value: unknown, max = 500): string => { if (typeof value !== "string" || value.length > max) throw new StaffServiceError("SERVER_ERROR"); return value; };
const moneyValue = (value: unknown): string => { const text = textValue(value, 20); if (!MONEY.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const listOf = <T>(value: unknown, max: number, map: (entry: unknown) => T): T[] => { if (!Array.isArray(value) || value.length > max) throw new StaffServiceError("SERVER_ERROR"); return value.map(map); };
const shopPerson = (value: unknown): ShopPerson => { const raw = objectValue(value); return { name: textValue(raw.name, 200), company: textValue(raw.company, 200), phone: textValue(raw.phone, 40), addressLines: listOf(raw.addressLines, 6, (line) => textValue(line, 300)) }; };
const shopLine = (value: unknown): ShopLine => {
  const raw = objectValue(value);
  return {
    id: textValue(raw.id, 20), line: positiveInteger(raw.line), productId: textValue(raw.productId, 20), variationId: textValue(raw.variationId, 20), name: textValue(raw.name, 300), sku: textValue(raw.sku, 100),
    width: nullableNumber(raw.width), height: nullableNumber(raw.height), unit: raw.unit == null ? null : oneOf(raw.unit, ["m"] as const),
    details: listOf(raw.details, 40, (entry) => { const detail = objectValue(entry); return { label: textValue(detail.label, 200), value: textValue(detail.value, 500) }; }),
    quantity: nonNegativeNumber(raw.quantity), unitPrice: moneyValue(raw.unitPrice), subtotal: moneyValue(raw.subtotal), discount: moneyValue(raw.discount), tax: moneyValue(raw.tax), total: moneyValue(raw.total), calculator: booleanValue(raw.calculator),
  };
};
export function mapShopOrderDetail(value: unknown): ShopOrderDetail {
  const raw = objectValue(value);
  const order = objectValue(raw.order);
  const status = objectValue(order.status);
  const contact = objectValue(raw.contact);
  const totals = objectValue(raw.totals);
  const payment = objectValue(raw.payment);
  const financial = objectValue(payment.financial);
  const advance = payment.advance == null ? null : objectValue(payment.advance);
  return {
    readAt: timestampValue(raw.readAt),
    order: {
      id: textValue(order.id, 20), number: textValue(order.number, 64), status: { code: textValue(status.code, 40), label: textValue(status.label, 100) },
      createdAt: order.createdAt == null ? null : timestampValue(order.createdAt), modifiedAt: order.modifiedAt == null ? null : timestampValue(order.modifiedAt),
      storage: oneOf(order.storage, ["hpos", "posts"] as const), currency: textValue(order.currency, 3), pricesIncludeTax: booleanValue(order.pricesIncludeTax),
    },
    version: textValue(raw.version, 71),
    pricingFingerprint: raw.pricingFingerprint == null ? null : textValue(raw.pricingFingerprint, 71),
    contact: { phoneAvailable: booleanValue(contact.phoneAvailable), billing: shopPerson(contact.billing), shipping: shopPerson(contact.shipping) },
    lines: listOf(raw.lines, 200, shopLine),
    fees: listOf(raw.fees, 20, (entry) => { const fee = objectValue(entry); return { name: textValue(fee.name, 200), total: moneyValue(fee.total), tax: moneyValue(fee.tax) }; }),
    coupons: listOf(raw.coupons, 20, (entry) => { const coupon = objectValue(entry); return { code: textValue(coupon.code, 100), discount: moneyValue(coupon.discount), discountTax: moneyValue(coupon.discountTax) }; }),
    shipping: listOf(raw.shipping, 20, (entry) => { const line = objectValue(entry); return { id: textValue(line.id, 20), methodId: textValue(line.methodId, 100), instanceId: textValue(line.instanceId, 20), title: textValue(line.title, 200), total: moneyValue(line.total), tax: moneyValue(line.tax) }; }),
    totals: { subtotal: moneyValue(totals.subtotal), discount: moneyValue(totals.discount), discountTax: moneyValue(totals.discountTax), shipping: moneyValue(totals.shipping), shippingTax: moneyValue(totals.shippingTax), fees: moneyValue(totals.fees), tax: moneyValue(totals.tax), total: moneyValue(totals.total), refunded: moneyValue(totals.refunded) },
    payment: {
      method: textValue(payment.method, 100), methodTitle: textValue(payment.methodTitle, 200), cod: booleanValue(payment.cod), paidRecorded: booleanValue(payment.paidRecorded),
      financial: { state: oneOf(financial.state, financialStates), basis: nullableString(financial.basis) },
      advance: advance === null ? null : { percentage: nonNegativeNumber(advance.percentage), required: advance.required == null ? null : moneyValue(advance.required), collected: advance.collected == null ? null : moneyValue(advance.collected) },
    },
  };
}
// ---- Phase 4B: controlled shop edits (strict; anything unexpected is refused, never half shown)
const EDIT_ID = /^[0-9]{1,20}$/;
const editId = (value: unknown): string => { const text = textValue(value, 20); if (!EDIT_ID.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const editCode = (value: unknown): string => { const text = textValue(value, 60); if (!/^[a-z_]{2,60}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const hexToken = (value: unknown): string => { const text = textValue(value, 64); if (!/^[0-9a-f]{64}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const shopVersion = (value: unknown): string => { const text = textValue(value, 71); if (!/^sha256:[0-9a-f]{64}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const finiteOrNull = (value: unknown): number | null => { if (value == null) return null; if (typeof value !== "number" || !Number.isFinite(value)) throw new StaffServiceError("SERVER_ERROR"); return value; };
const integerOrNull = (value: unknown): number | null => { if (value == null) return null; if (typeof value !== "number" || !Number.isInteger(value)) throw new StaffServiceError("SERVER_ERROR"); return value; };
function mapEditField(value: unknown): EditField {
  const raw = objectValue(value);
  return {
    name: textValue(raw.name, 64), title: textValue(raw.title, 200), type: textValue(raw.type, 40), editable: booleanValue(raw.editable), required: booleanValue(raw.required),
    min: finiteOrNull(raw.min), max: finiteOrNull(raw.max),
    options: raw.options == null ? null : listOf(raw.options, 200, (entry) => { const option = objectValue(entry); return { value: textValue(option.value, 200), title: textValue(option.title, 300) }; }),
    ...(raw.value === undefined ? {} : { value: raw.value === null ? null : textValue(raw.value, 200) }),
  };
}
function mapEditVariation(value: unknown): EditVariation {
  const raw = objectValue(value);
  return { id: editId(raw.id), attributes: listOf(raw.attributes, 20, (entry) => { const attribute = objectValue(entry); return { label: textValue(attribute.label, 200), value: textValue(attribute.value, 200) }; }), inStock: booleanValue(raw.inStock), purchasable: booleanValue(raw.purchasable), stock: integerOrNull(raw.stock) };
}
export function mapEditCatalog(value: unknown): EditCatalog {
  const raw = objectValue(value);
  return {
    version: shopVersion(raw.version), editable: booleanValue(raw.editable), reason: raw.reason == null ? null : editCode(raw.reason),
    lines: listOf(raw.lines, 200, (entry) => {
      const line = objectValue(entry);
      return {
        id: editId(line.id), productId: editId(line.productId), variationId: editId(line.variationId), name: textValue(line.name, 300), quantity: positiveInteger(line.quantity),
        calculator: line.calculator == null ? null : { fields: listOf(objectValue(line.calculator).fields, 30, mapEditField) },
        variations: listOf(line.variations, 200, mapEditVariation), editable: booleanValue(line.editable), reason: line.reason == null ? null : editCode(line.reason),
      };
    }),
    shipping: listOf(raw.shipping, 20, (entry) => { const line = objectValue(entry); return { id: editId(line.id), method: textValue(line.method, 60), title: textValue(line.title, 200) }; }),
    shippingOptions: listOf(raw.shippingOptions, 20, (entry) => { const option = objectValue(entry); return { method: textValue(option.method, 60), title: textValue(option.title, 200) }; }),
  };
}
export function mapEditProduct(value: unknown): EditProduct {
  const raw = objectValue(value);
  const calculator = raw.calculator == null ? null : objectValue(raw.calculator);
  return {
    id: editId(raw.id), name: textValue(raw.name, 300), sku: textValue(raw.sku, 100), type: oneOf(raw.type, ["simple", "variable"] as const), purchasable: booleanValue(raw.purchasable),
    inStock: booleanValue(raw.inStock), stock: integerOrNull(raw.stock), variations: listOf(raw.variations, 200, mapEditVariation),
    calculator: calculator === null ? null : typeof calculator.unavailable === "string" ? { unavailable: editCode(calculator.unavailable) } : { fields: listOf(calculator.fields, 30, mapEditField) },
  };
}
function mapEditBlocker(value: unknown): EditBlocker {
  const raw = objectValue(value);
  return {
    code: editCode(raw.code),
    ...(raw.productId == null ? {} : { productId: editId(raw.productId) }), ...(raw.method == null ? {} : { method: textValue(raw.method, 60) }),
    ...(raw.coupon == null ? {} : { coupon: textValue(raw.coupon, 100) }), ...(raw.available == null ? {} : { available: nonNegativeInteger(raw.available) }), ...(raw.needed == null ? {} : { needed: nonNegativeInteger(raw.needed) }),
  };
}
const editTotals = (value: unknown): EditTotals => {
  const raw = objectValue(value);
  return { subtotal: moneyValue(raw.subtotal), subtotalTax: moneyValue(raw.subtotalTax), discount: moneyValue(raw.discount), discountTax: moneyValue(raw.discountTax), shipping: moneyValue(raw.shipping), shippingTax: moneyValue(raw.shippingTax), fees: moneyValue(raw.fees), tax: moneyValue(raw.tax), total: moneyValue(raw.total) };
};
const editSnapshot = (value: unknown): EditLineSnapshot | null => {
  if (value == null) return null;
  const raw = objectValue(value);
  let fields: Record<string, string> | null = null;
  if (raw.fields != null) {
    const entries = Array.isArray(raw.fields) && raw.fields.length === 0 ? {} : objectValue(raw.fields);
    fields = Object.fromEntries(Object.entries(entries).slice(0, 30).map(([name, field]) => [textValue(name, 64), textValue(field, 200)]));
  }
  return { name: textValue(raw.name, 300), productId: editId(raw.productId), variationId: editId(raw.variationId), quantity: positiveInteger(raw.quantity), fields, subtotal: moneyValue(raw.subtotal), total: moneyValue(raw.total), tax: moneyValue(raw.tax) };
};
export function mapEditResult(value: unknown): EditResult {
  const raw = objectValue(value);
  const financial = objectValue(raw.financial);
  return {
    version: shopVersion(raw.version), applicable: booleanValue(raw.applicable), blockers: listOf(raw.blockers, 100, mapEditBlocker), before: editTotals(raw.before), after: editTotals(raw.after),
    lines: listOf(raw.lines, 200, (entry) => { const line = objectValue(entry); return { lineId: textValue(line.lineId, 30), state: oneOf(line.state, ["added", "removed", "changed", "unchanged"] as const), before: editSnapshot(line.before), after: editSnapshot(line.after) }; }),
    shipping: listOf(raw.shipping, 20, (entry) => { const line = objectValue(entry); return { id: editId(line.id), method: textValue(line.method, 60), before: moneyValue(line.before), after: moneyValue(line.after) }; }),
    financial: { delta: moneyValue(financial.delta), impact: oneOf(financial.impact, EDIT_IMPACTS), cod: booleanValue(financial.cod) },
  };
}
export function mapEditOutcome(value: unknown): EditOutcome {
  const raw = objectValue(value);
  const key = textValue(raw.idempotencyKey, 100);
  if (raw.state === "applied") {
    if (raw.result == null) return { state: "applied", idempotencyKey: key, result: null };
    const result = objectValue(raw.result);
    const money = objectValue(result.financialState);
    return { state: "applied", idempotencyKey: key, result: {
      ...mapEditResult(result), modifiedAt: timestampValue(result.modifiedAt),
      financialState: { state: oneOf(money.state, financialStates), basis: nullableString(money.basis), attention: money.attention == null ? null : oneOf(money.attention, ["additional_payment_due", "refund_due"] as const) },
    } };
  }
  return { state: oneOf(raw.state, ["pending", "unknown"] as const), idempotencyKey: key, reachable: typeof raw.reachable === "boolean" ? raw.reachable : null };
}
function mapOpenEdit(value: unknown): OpenEdit {
  const raw = objectValue(value);
  return { idempotencyKey: textValue(raw.idempotencyKey, 100), state: oneOf(raw.state, ["pending", "unknown"] as const), requestedAt: timestampValue(raw.requestedAt), mine: booleanValue(raw.mine) };
}
export function mapShopRead(value: unknown): ShopRead {
  const raw = objectValue(value);
  if (raw.available !== true) return { available: false, reason: textValue(raw.reason, 60) };
  const freshness = objectValue(raw.freshness);
  return {
    available: true,
    role: oneOf(raw.role, ["assignee", "supervisor"] as const),
    detail: mapShopOrderDetail(raw.detail),
    freshness: {
      readAt: timestampValue(freshness.readAt), shopModifiedAt: freshness.shopModifiedAt == null ? null : timestampValue(freshness.shopModifiedAt),
      arasyaChangedAt: freshness.arasyaChangedAt == null ? null : timestampValue(freshness.arasyaChangedAt),
      pricingMatches: freshness.pricingMatches == null ? null : booleanValue(freshness.pricingMatches), inSync: booleanValue(freshness.inSync),
    },
  };
}

// ---- Confirmare finance (API 2.41.0): strict; anything unexpected is refused, never half shown
const percentageValue = (value: unknown): number => { if (typeof value !== "number" || !Number.isFinite(value) || value < 0 || value > 100) throw new StaffServiceError("SERVER_ERROR"); return value; };
const optionalMoney = (value: unknown): string | null => (value == null ? null : moneyValue(value));
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const uuidValue = (value: unknown): string => { const text = textValue(value, 36); if (!UUID.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
const ATTENTIONS = ["additional_payment_due", "refund_due", "transfer_pending", "reconciliation_required", "refund_liability", "refund_pending"] as const;
function mapFinanceEntry(value: unknown): FinanceEntry {
  const raw = objectValue(value);
  return {
    id: uuidValue(raw.id), kind: oneOf(raw.kind, ["transfer_in", "refund_out", "liability_ack"] as const), purpose: oneOf(raw.purpose, ["advance", "additional_payment", "refund", "liability"] as const),
    amount: moneyValue(raw.amount), reference: textValue(raw.reference, 64), valueDate: raw.valueDate == null ? null : textValue(raw.valueDate, 10), state: oneOf(raw.state, ["submitted", "verified", "rejected"] as const),
    submittedBy: uuidValue(raw.submittedBy), submittedAt: timestampValue(raw.submittedAt), decidedBy: raw.decidedBy == null ? null : uuidValue(raw.decidedBy),
    decidedAt: raw.decidedAt == null ? null : timestampValue(raw.decidedAt), decisionCode: raw.decisionCode == null ? null : textValue(raw.decisionCode, 40),
    // API 2.45.0: how the money came, the cash document and collector, the explicit confirmations (absent: a bank transfer).
    method: raw.method == null ? "bank_transfer" : oneOf(raw.method, PAYMENT_METHODS), receipt: raw.receipt == null ? null : oneOf(raw.receipt, CASH_RECEIPTS),
    collector: raw.collector == null ? null : textValue(raw.collector, 64), flags: raw.flags == null ? [] : listOf(raw.flags, 4, (flag) => oneOf(flag, ["overpayment", "cancelled_proforma", "card_full_refund"] as const)),
  };
}
export function mapFinanceSummary(value: unknown): FinanceSummary {
  const raw = objectValue(value);
  const order = objectValue(raw.order);
  const totals = objectValue(raw.totals);
  const received = objectValue(raw.received);
  const payment = objectValue(raw.payment);
  const balance = objectValue(raw.balance);
  const financial = objectValue(raw.financial);
  const capabilities = objectValue(raw.capabilities);
  const advance = raw.advance == null ? null : objectValue(raw.advance);
  if (capabilities.electronicRefund !== false) throw new StaffServiceError("SERVER_ERROR");
  return {
    readAt: timestampValue(raw.readAt), revision: textValue(raw.revision, 71), currency: textValue(raw.currency, 3),
    order: { id: textValue(order.id, 20), number: textValue(order.number, 64), status: textValue(order.status, 40), modifiedAt: order.modifiedAt == null ? null : timestampValue(order.modifiedAt), method: textValue(order.method, 100), methodTitle: textValue(order.methodTitle, 200), cod: booleanValue(order.cod) },
    totals: { current: optionalMoney(totals.current), original: optionalMoney(totals.original), refunded: optionalMoney(totals.refunded) },
    received: { online: optionalMoney(received.online), advance: optionalMoney(received.advance), transfers: optionalMoney(received.transfers), net: optionalMoney(received.net) },
    payment: { onlineVerified: booleanValue(payment.onlineVerified), paidRecorded: booleanValue(payment.paidRecorded), evidence: oneOf(payment.evidence, ["netopia_ipn", "gateway", "none"] as const) },
    advance: advance === null ? null : {
      percentage: percentageValue(advance.percentage), required: optionalMoney(advance.required), collected: optionalMoney(advance.collected), outstanding: optionalMoney(advance.outstanding), onDelivery: optionalMoney(advance.onDelivery),
      paymentReference: paymentReference(advance.paymentReference), proforma: advance.proforma == null ? null : mapFinanceProforma(advance.proforma),
      accepted: advance.accepted == null ? false : booleanValue(advance.accepted), proformaCancelled: advance.proformaCancelled == null ? false : booleanValue(advance.proformaCancelled),
    },
    balance: { outstanding: optionalMoney(balance.outstanding), refundLiability: moneyValue(balance.refundLiability), acknowledged: booleanValue(balance.acknowledged) },
    financial: { state: oneOf(financial.state, ["cleared", "waiting", "partial", "unknown"] as const), basis: nullableString(financial.basis), attention: financial.attention == null ? null : oneOf(financial.attention, ATTENTIONS) },
    entries: listOf(raw.entries, 200, mapFinanceEntry),
    capabilities: { record: booleanValue(capabilities.record), refunds: oneOf(capabilities.refunds, ["off", "manual"] as const), electronicRefund: false },
    position: raw.position == null ? null : mapMoneyPosition(raw.position),
    deadline: raw.deadline == null ? null : mapInvoiceDeadline(raw.deadline),
    cod: raw.cod == null ? null : mapCodCheck(raw.cod),
  };
}
function mapFinanceProforma(value: unknown): NonNullable<NonNullable<FinanceSummary["advance"]>["proforma"]> {
  const raw = objectValue(value);
  const code = (text: unknown, min: number): string => { const v = textValue(text, 20); if (!new RegExp(`^[A-Za-z0-9-]{${min},20}$`).test(v)) throw new StaffServiceError("SERVER_ERROR"); return v; };
  return { series: code(raw.series, 0), number: code(raw.number, 1), amount: optionalMoney(raw.amount), currency: raw.currency == null ? null : textValue(raw.currency, 3), reference: paymentReference(raw.reference) };
}
function mapTransferException(value: unknown): TransferException {
  const raw = objectValue(value);
  const order = raw.order == null ? null : objectValue(raw.order);
  const state = oneOf(raw.state, ["open", "resolved"] as const);
  const resolution = raw.resolution == null ? null : oneOf(raw.resolution, EXCEPTION_RESOLUTIONS);
  if ((state === "open") !== (resolution === null)) throw new StaffServiceError("SERVER_ERROR");
  return {
    id: uuidValue(raw.id), amount: moneyValue(raw.amount), currency: textValue(raw.currency, 3), reference: textValue(raw.reference, 64), valueDate: raw.valueDate == null ? null : textValue(raw.valueDate, 10),
    reason: oneOf(raw.reason, EXCEPTION_REASONS), state, submittedBy: uuidValue(raw.submittedBy), submittedAt: raw.submittedAt == null ? null : timestampValue(raw.submittedAt),
    resolvedBy: raw.resolvedBy == null ? null : uuidValue(raw.resolvedBy), resolvedAt: raw.resolvedAt == null ? null : timestampValue(raw.resolvedAt), resolution,
    order: order === null ? null : { id: textValue(order.id, 20), number: order.number == null ? null : textValue(order.number, 64) },
  };
}
export function mapTransferExceptionList(value: unknown): TransferExceptionList {
  const raw = objectValue(value);
  const capabilities = objectValue(raw.capabilities);
  const base = { source: textValue(raw.source, 40), capabilities: { record: booleanValue(capabilities.record), approve: booleanValue(capabilities.approve) } };
  if (raw.available !== true) return { ...base, available: false, reason: textValue(raw.reason, 60), exceptions: [] };
  return { ...base, available: true, currency: raw.currency == null ? null : textValue(raw.currency, 3), exceptions: listOf(raw.exceptions, 300, mapTransferException) };
}
export function mapExceptionOutcome(value: unknown): ExceptionOutcome {
  const raw = objectValue(value);
  if (raw.state === "unknown") return { state: "unknown", idempotencyKey: textValue(raw.idempotencyKey, 100) };
  if (raw.state !== "applied") throw new StaffServiceError("SERVER_ERROR");
  const list = mapTransferExceptionList({ ...raw, available: true });
  if (!list.available) throw new StaffServiceError("SERVER_ERROR");
  return { ...list, state: "applied", entryId: uuidValue(raw.entryId) };
}
function mapFinanceTimeline(value: unknown): FinanceTimelineItem {
  const raw = objectValue(value);
  return {
    employeeId: uuidValue(raw.employeeId), action: oneOf(raw.action, FINANCE_ACTIONS), decision: raw.decision == null ? null : oneOf(raw.decision, ["verify", "reject"] as const),
    reasonCode: nullableString(raw.reasonCode), purpose: nullableString(raw.purpose), entryId: nullableString(raw.entryId), idempotencyKey: textValue(raw.idempotencyKey, 100),
    state: oneOf(raw.state, ["pending", "applied", "rejected", "unknown"] as const), outcome: nullableString(raw.outcome), requestedAt: timestampValue(raw.requestedAt), completedAt: raw.completedAt == null ? null : timestampValue(raw.completedAt),
  };
}
function mapFinanceView(raw: Record<string, unknown>) {
  const production = objectValue(raw.production);
  const employees = objectValue(raw.employees);
  return {
    summary: mapFinanceSummary(raw.summary),
    production: { stageId: textValue(production.stageId, 100), handedOver: booleanValue(production.handedOver), refundEligible: booleanValue(production.refundEligible) },
    allowedActions: listOf(raw.allowedActions, 5, (action) => oneOf(action, FINANCE_ACTIONS)),
    nextAction: oneOf(raw.nextAction, FINANCE_NEXT),
    actionOpen: booleanValue(raw.actionOpen),
    timeline: listOf(raw.timeline, 200, mapFinanceTimeline),
    employees: Object.fromEntries(Object.entries(employees).map(([id, name]) => [uuidValue(id), textValue(name, 200)])),
    me: uuidValue(raw.me),
  };
}
export function mapFinanceRead(value: unknown): FinanceRead {
  const raw = objectValue(value);
  if (raw.available !== true) return { available: false, reason: textValue(raw.reason, 60) };
  return { available: true, role: oneOf(raw.role, ["finance", "assignee", "supervisor"] as const), ...mapFinanceView(raw) };
}
export function mapFinanceOutcome(value: unknown): FinanceOutcome {
  const raw = objectValue(value);
  const state = oneOf(raw.state, ["applied", "pending", "unknown"] as const);
  if (state !== "applied") return { state, idempotencyKey: textValue(raw.idempotencyKey, 100), ...(typeof raw.reachable === "boolean" ? { reachable: raw.reachable } : {}) };
  // The view after an applied action is optional (a replayed key answers with the entry only).
  return { state, idempotencyKey: textValue(raw.idempotencyKey, 100), entryId: uuidValue(raw.entryId), ...(raw.summary == null ? {} : mapFinanceView(raw)) };
}
function mapFinanceQueueItem(value: unknown): FinanceQueueItem {
  const raw = objectValue(value);
  const financial = objectValue(raw.financial);
  return {
    globalOrderId: textValue(raw.globalOrderId, 200), source: textValue(raw.source, 40), orderNumber: textValue(raw.orderNumber, 64), task: oneOf(raw.task, FINANCE_TASKS),
    financial: { state: nullableString(financial.state), basis: nullableString(financial.basis), attention: nullableString(financial.attention) },
    paymentMethod: nullableString(raw.paymentMethod), stageId: textValue(raw.stageId, 100), confirmation: textValue(raw.confirmation, 20),
    dueDate: raw.dueDate == null ? null : textValue(raw.dueDate, 10), sourceChangedAt: raw.sourceChangedAt == null ? null : timestampValue(raw.sourceChangedAt),
    verifying: raw.verifying == null ? false : booleanValue(raw.verifying),
  };
}
export function mapFinanceQueue(value: unknown): FinanceQueue {
  const raw = objectValue(value);
  const tasks = objectValue(raw.tasks);
  return {
    tasks: Object.fromEntries(FINANCE_TASKS.map((task) => [task, nonNegativeInteger(tasks[task])])) as Record<FinanceTask, number>,
    orders: listOf(raw.orders, 500, mapFinanceQueueItem),
    sources: stringList(raw.sources),
  };
}


// ---- SmartBill invoicing (API 2.43.0): strict; anything unexpected is refused, never half shown
const INVOICE_KEYS = ["issue", "sendMail", "reissue", "resolve"] as const;
const invoiceCodes = (value: unknown): string[] => listOf(value, 40, (code) => { const text = textValue(code, 60); if (!/^[a-z_]{2,60}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; });
const invoiceUuid = (value: unknown): string | null => (value == null ? null : uuidValue(value));
const invoiceText = (value: unknown, max: number): string | null => (value == null ? null : textValue(value, max));
const invoiceInstant = (value: unknown): string | null => (value == null ? null : timestampValue(value));
function invoiceLink(value: unknown): string | null {
  if (value == null) return null;
  const text = textValue(value, 1000);
  let url: URL;
  try { url = new URL(text); } catch { throw new StaffServiceError("SERVER_ERROR"); }
  const host = url.hostname.toLowerCase();
  if (url.protocol !== "https:" || url.username || url.password || url.port || (host !== "smartbill.ro" && !host.endsWith(".smartbill.ro"))) throw new StaffServiceError("SERVER_ERROR");
  return url.toString();
}
function mapInvoiceSummary(value: unknown): InvoiceSummary {
  const raw = objectValue(value);
  const smartbill = objectValue(raw.smartbill);
  const order = objectValue(raw.order);
  const financial = objectValue(raw.financial);
  const document = objectValue(raw.document);
  const operation = objectValue(raw.operation);
  const mail = objectValue(raw.mail);
  const blockers = objectValue(raw.blockers);
  return {
    readAt: timestampValue(raw.readAt), revision: textValue(raw.revision, 71), mode: oneOf(raw.mode, ["observe", "enforce"] as const),
    smartbill: { available: booleanValue(smartbill.available), reason: invoiceText(smartbill.reason, 60), documentType: oneOf(smartbill.documentType, ["invoice", "proforma"] as const), autoEmail: booleanValue(smartbill.autoEmail),
      cashing: booleanValue(smartbill.cashing), draft: booleanValue(smartbill.draft), mailSupported: booleanValue(smartbill.mailSupported), autoIssue: booleanValue(smartbill.autoIssue) },
    order: { id: textValue(order.id, 20), number: textValue(order.number, 64), status: textValue(order.status, 40), total: textValue(order.total, 20), currency: textValue(order.currency, 3), billingEmail: booleanValue(order.billingEmail) },
    financial: { state: textValue(financial.state, 20), basis: invoiceText(financial.basis, 40), attention: invoiceText(financial.attention, 40), pending: booleanValue(financial.pending) },
    document: {
      exists: booleanValue(document.exists), type: document.type == null ? null : oneOf(document.type, ["invoice", "proforma"] as const), series: invoiceText(document.series, 40), number: invoiceText(document.number, 40),
      status: document.status == null ? null : oneOf(document.status, ["final", "draft"] as const), conflict: booleanValue(document.conflict), through: document.through == null ? null : oneOf(document.through, ["arasya", "shop"] as const),
      issuedAt: invoiceInstant(document.issuedAt), issuedBy: invoiceUuid(document.issuedBy), total: invoiceText(document.total, 20), currency: invoiceText(document.currency, 3),
      outdated: document.outdated == null ? null : booleanValue(document.outdated), link: invoiceLink(document.link),
    },
    operation: { state: oneOf(operation.state, ["none", "in_flight", "issued", "unknown"] as const), kind: invoiceText(operation.kind, 20), startedAt: invoiceInstant(operation.startedAt), by: invoiceUuid(operation.by), error: invoiceText(operation.error, 40) },
    mail: { state: oneOf(mail.state, ["none", "smartbill", "in_flight", "sent", "unknown", "failed"] as const), at: invoiceInstant(mail.at), by: invoiceUuid(mail.by), count: nonNegativeInteger(mail.count) },
    history: listOf(raw.history, 50, (entry) => {
      const item = objectValue(entry);
      const reason = item.reason == null ? null : objectValue(item.reason);
      return { kind: textValue(item.kind, 30), at: invoiceInstant(item.at), by: invoiceUuid(item.by), outcome: textValue(item.outcome, 20), reason: reason === null ? null : { code: textValue(reason.code, 30), note: textValue(reason.note, 300) },
        series: invoiceText(item.series, 40), number: invoiceText(item.number, 40) };
    }),
    blockers: Object.fromEntries(INVOICE_KEYS.map((key) => [key, invoiceCodes(blockers[key])])) as Record<InvoiceActionKey, string[]>,
    proforma: mapProformaSummary(raw.proforma),
    // API 2.45.0: e-Factura is never tracked by the shop; nothing else is accepted.
    eFactura: raw.eFactura == null ? null : { state: oneOf(objectValue(raw.eFactura).state, ["not_tracked"] as const) },
  };
}
function mapProformaSummary(value: unknown): ProformaSummary | null {
  if (value == null) return null;
  const raw = objectValue(value);
  const advance = objectValue(raw.advance);
  const mail = objectValue(raw.mail);
  const blockers = objectValue(raw.blockers);
  const money = (amount: unknown): string | null => (amount == null ? null : moneyText(amount));
  const document = raw.document == null ? null : objectValue(raw.document);
  const invoiced = raw.invoiced == null ? null : objectValue(raw.invoiced);
  const transfer = raw.transfer == null ? null : objectValue(raw.transfer);
  const percentage = advance.percentage == null ? null : Number(advance.percentage);
  if (percentage !== null && (!Number.isFinite(percentage) || percentage < 0 || percentage > 100)) throw new StaffServiceError("SERVER_ERROR");
  return {
    advance: {
      required: money(advance.required), received: money(advance.received), outstanding: money(advance.outstanding), percentage, cod: advance.cod == null ? null : booleanValue(advance.cod),
      state: advance.state == null ? null : oneOf(advance.state, ["waiting", "partial", "paid"] as const), verifying: advance.verifying == null ? false : booleanValue(advance.verifying),
      accepted: advance.accepted == null ? false : booleanValue(advance.accepted),
    },
    transfer: transfer === null ? null : {
      iban: ibanValue(transfer.iban), bank: textValue(transfer.bank, 100), beneficiary: textValue(transfer.beneficiary, 100), reference: paymentReference(transfer.reference),
      bic: transfer.bic == null ? null : bicValue(transfer.bic),
    },
    state: oneOf(raw.state, ["none", "in_flight", "issued", "unknown"] as const),
    document: document === null ? null : {
      series: invoiceText(document.series, 40), number: invoiceText(document.number, 40), amount: money(document.amount), currency: invoiceText(document.currency, 3), reference: paymentReference(document.reference),
      issuedAt: invoiceInstant(document.issuedAt), issuedBy: invoiceUuid(document.issuedBy), outdated: booleanValue(document.outdated), link: invoiceLink(document.link),
    },
    mail: { state: oneOf(mail.state, ["none", "smartbill", "in_flight", "sent", "unknown", "failed"] as const), at: invoiceInstant(mail.at), by: invoiceUuid(mail.by), count: nonNegativeInteger(mail.count) },
    invoiced: invoiced === null ? null : { invoiced: invoiced.invoiced == null ? null : booleanValue(invoiced.invoiced), at: invoiceInstant(invoiced.at) },
    error: invoiceText(raw.error, 40),
    history: listOf(raw.history, 50, (entry) => {
      const item = objectValue(entry);
      return { kind: textValue(item.kind, 40), at: invoiceInstant(item.at), by: invoiceUuid(item.by), outcome: textValue(item.outcome, 20), series: invoiceText(item.series, 40), number: invoiceText(item.number, 40),
        reason: item.reason == null ? null : oneOf(item.reason, CANCEL_REASONS) };
    }),
    // A YD SOFT 2.18.0 shop sends no 2.45.0 groups: those actions are not supported.
    blockers: Object.fromEntries(PROFORMA_KEYS.map((key) => [key, blockers[key] === undefined ? ["not_supported"] : invoiceCodes(blockers[key])])) as Record<ProformaActionKey, string[]>,
    money: raw.money == null ? null : mapMoneyPosition(raw.money),
    totalAcceptance: raw.totalAcceptance == null ? null : mapTotalAcceptance(raw.totalAcceptance),
    advanceAcceptance: raw.advanceAcceptance == null ? null : (() => {
      const a = objectValue(raw.advanceAcceptance);
      return { at: invoiceInstant(a.at), by: invoiceUuid(a.by), required: money(a.required), received: money(a.received), reason: invoiceText(a.reason, 300), low: booleanValue(a.low),
        kind: a.kind == null ? "accepted" as const : oneOf(a.kind, ["accepted", "kept"] as const) };
    })(),
    lowAdvance: raw.lowAdvance == null ? false : booleanValue(raw.lowAdvance),
    cancel: raw.cancel == null ? null : (() => {
      const c = objectValue(raw.cancel);
      return { state: oneOf(c.state, ["in_flight", "unknown"] as const), at: invoiceInstant(c.at), by: invoiceUuid(c.by), reason: c.reason == null ? null : oneOf(c.reason, CANCEL_REASONS) };
    })(),
    cancelled: listOf(raw.cancelled ?? [], 50, (entry) => {
      const c = objectValue(entry);
      return { series: invoiceText(c.series, 40), number: invoiceText(c.number, 40), amount: money(c.amount), currency: invoiceText(c.currency, 3), cancelledAt: invoiceInstant(c.cancelledAt), cancelledBy: invoiceUuid(c.cancelledBy),
        reason: c.reason == null ? null : oneOf(c.reason, CANCEL_REASONS) };
    }),
    superseded: listOf(raw.superseded ?? [], 50, (entry) => {
      const c = objectValue(entry);
      return { series: invoiceText(c.series, 40), number: invoiceText(c.number, 40), amount: money(c.amount), supersededAt: invoiceInstant(c.supersededAt) };
    }),
    deadline: raw.deadline == null ? null : mapInvoiceDeadline(raw.deadline),
    cod: raw.cod == null ? null : mapCodCheck(raw.cod),
    shipping: raw.shipping == null ? null : mapShippingCheck(raw.shipping),
  };
}
/** The shop's one money calculation; refused when its own numbers do not add up (never shown half trusted). */
export function mapMoneyPosition(value: unknown): MoneyPosition {
  const raw = objectValue(value);
  const money = (amount: unknown): string | null => (amount == null ? null : moneyText(amount));
  const percentage = raw.percentage == null ? null : Number(raw.percentage);
  if (percentage !== null && (!Number.isFinite(percentage) || percentage < 0 || percentage > 100)) throw new StaffServiceError("SERVER_ERROR");
  const position: MoneyPosition = {
    currency: textValue(raw.currency, 3), percentage, known: booleanValue(raw.known), total: money(raw.total), advanceRequired: money(raw.advanceRequired), received: money(raw.received),
    refunded: money(raw.refunded), net: money(raw.net), remaining: money(raw.remaining), excess: money(raw.excess), advanceGap: money(raw.advanceGap),
  };
  if (position.known) {
    const c = (text: string | null): number | null => (text === null ? null : Math.round(Number(text) * 100));
    const [t, a, p, i, n, k, f, g] = [position.total, position.advanceRequired, position.received, position.refunded, position.net, position.remaining, position.excess, position.advanceGap].map(c);
    if (t === null || p === null || i === null || n === null || k === null || f === null || n !== p - i || k !== Math.max(0, t - n) || f !== Math.max(0, n - t) || (a !== null && g !== Math.max(0, a - n))) {
      throw new StaffServiceError("SERVER_ERROR");
    }
  }
  return position;
}
function mapTotalAcceptance(value: unknown): TotalAcceptance {
  const raw = objectValue(value);
  return {
    state: oneOf(raw.state, ["accepted", "required"] as const), at: invoiceInstant(raw.at), by: invoiceUuid(raw.by),
    previousTotal: raw.previousTotal == null ? null : moneyText(raw.previousTotal), total: raw.total == null ? null : moneyText(raw.total),
    changes: listOf(raw.changes, 40, (entry) => { const c = objectValue(entry); return { kind: oneOf(c.kind, ["added", "removed", "changed", "shipping"] as const), name: c.name === "" ? "" : textValue(c.name, 120) }; }),
    note: invoiceText(raw.note, 300),
    advanceDecisionRequired: raw.advanceDecisionRequired == null ? null : booleanValue(raw.advanceDecisionRequired),
  };
}
const SHIPPING_CODES = ["shipping_cost_mismatch", "free_shipping_threshold_lost", "shipping_rate_unavailable", "shipping_method_unsupported", "free_shipping_unverifiable", "shipping_line_not_found", "shipping_check_failed"] as const;
/** The shipping check; a mismatch or an unverifiable method always names its code. */
export function mapShippingCheck(value: unknown): ShippingCheck {
  const raw = objectValue(value);
  const state = oneOf(raw.state, ["none", "match", "mismatch", "unverifiable"] as const);
  const code = raw.code == null ? null : oneOf(raw.code, SHIPPING_CODES);
  if ((state === "mismatch" || state === "unverifiable") !== (code !== null)) throw new StaffServiceError("SERVER_ERROR");
  return { state, code, current: raw.current == null ? null : moneyText(raw.current), expected: raw.expected == null ? null : moneyText(raw.expected) };
}
export function mapInvoiceDeadline(value: unknown): InvoiceDeadline {
  const raw = objectValue(value);
  const date = (v: unknown): string | null => { if (v == null) return null; const text = textValue(v, 10); if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) throw new StaffServiceError("SERVER_ERROR"); return text; };
  const days = raw.daysLeft == null ? null : Number(raw.daysLeft);
  if (days !== null && (!Number.isInteger(days) || Math.abs(days) > 3660)) throw new StaffServiceError("SERVER_ERROR");
  return { state: oneOf(raw.state, ["none", "open", "invoiced"] as const), firstCollection: date(raw.firstCollection), deadline: date(raw.deadline), daysLeft: days,
    level: raw.level == null ? null : oneOf(raw.level, ["none", "notice", "warning", "critical", "overdue"] as const) };
}
export function mapCodCheck(value: unknown): CodCheck {
  const raw = objectValue(value);
  return { state: oneOf(raw.state, ["none", "match", "mismatch", "delivered", "unknown"] as const), awb: invoiceText(raw.awb, 100), awbCod: raw.awbCod == null ? null : moneyText(raw.awbCod),
    expected: raw.expected == null ? null : moneyText(raw.expected), status: invoiceText(raw.status, 255) };
}
function bicValue(value: unknown): string {
  const text = textValue(value, 11);
  if (!/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(text)) throw new StaffServiceError("SERVER_ERROR");
  return text;
}
function ibanValue(value: unknown): string {
  const text = textValue(value, 34);
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/.test(text)) throw new StaffServiceError("SERVER_ERROR");
  return text;
}
/** The order's payment reference the customer writes in the transfer details (shop prefix and order number). */
function paymentReference(value: unknown): string | null {
  if (value == null) return null;
  const text = textValue(value, 24);
  if (!/^[A-Z]{2,4}[A-Z0-9]{1,20}$/.test(text)) throw new StaffServiceError("SERVER_ERROR");
  return text;
}
function moneyText(value: unknown): string {
  const text = textValue(value, 20);
  if (!/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/.test(text)) throw new StaffServiceError("SERVER_ERROR");
  return text;
}
function mapShopAssignment(value: unknown): ShopAssignment | null {
  if (value == null) return null;
  const raw = objectValue(value);
  return { employeeId: uuidValue(raw.employeeId), assignedBy: uuidValue(raw.assignedBy), role: oneOf(raw.role, ["operator", "manager"] as const), assignedAt: timestampValue(raw.assignedAt) };
}
function mapShopQueueItem(value: unknown): ShopQueueItem {
  const raw = objectValue(value);
  return { globalOrderId: textValue(raw.globalOrderId, 200), source: textValue(raw.source, 40), orderNumber: textValue(raw.orderNumber, 64), assignedAt: timestampValue(raw.assignedAt),
    assignedBy: raw.assignedBy === "" ? "" : textValue(raw.assignedBy, 200), handoff: textValue(raw.handoff, 30), invoiced: booleanValue(raw.invoiced) };
}
function mapInvoiceView(raw: Record<string, unknown>): InvoiceView {
  const handoff = objectValue(raw.handoff);
  const blockers = objectValue(raw.blockers);
  const proforma = raw.proforma == null ? null : objectValue(raw.proforma);
  const proformaBlockers = proforma === null ? {} : objectValue(proforma.blockers);
  const routing = raw.routing == null ? null : objectValue(raw.routing);
  const qr = raw.qr == null ? null : objectValue(raw.qr);
  return {
    summary: mapInvoiceSummary(raw.summary),
    handoff: { state: textValue(handoff.state, 30), blockers: invoiceCodes(handoff.blockers) },
    blockers: Object.fromEntries(INVOICE_KEYS.map((key) => [key, invoiceCodes(blockers[key])])) as Record<InvoiceActionKey, string[]>,
    allowedActions: listOf(raw.allowedActions, 4, (action) => oneOf(action, INVOICE_KEYS)),
    actionOpen: booleanValue(raw.actionOpen),
    timeline: listOf(raw.timeline, 200, (entry) => {
      const item = objectValue(entry);
      return { employeeId: uuidValue(item.employeeId), role: textValue(item.role, 12), action: oneOf(item.action, [...INVOICE_ACTIONS, ...PROFORMA_ACTIONS] as const), reasonCode: invoiceText(item.reasonCode, 30), idempotencyKey: textValue(item.idempotencyKey, 100),
        state: oneOf(item.state, ["pending", "applied", "rejected", "unknown"] as const), outcome: invoiceText(item.outcome, 40), result: invoiceText(item.result, 20), requestedAt: timestampValue(item.requestedAt), completedAt: invoiceInstant(item.completedAt) };
    }),
    employees: Object.fromEntries(Object.entries(objectValue(raw.employees)).map(([id, name]) => [id, textValue(name, 200)])),
    me: uuidValue(raw.me),
    proforma: proforma === null ? null : {
      // An API before 2.45.0 names no blockers of the newer actions: they are not supported there.
      blockers: Object.fromEntries(PROFORMA_KEYS.map((key) => [key, proformaBlockers[key] === undefined ? ["not_supported"] : invoiceCodes(proformaBlockers[key])])) as Record<ProformaActionKey, string[]>,
      allowedActions: listOf(proforma.allowedActions, PROFORMA_KEYS.length, (action) => oneOf(action, PROFORMA_KEYS)),
      role: proforma.role == null ? null : oneOf(proforma.role, ["operator", "manager", "documents"] as const),
    },
    assignment: mapShopAssignment(raw.assignment),
    routing: routing === null ? null : { allowed: booleanValue(routing.allowed), blockers: invoiceCodes(routing.blockers) },
    // Older APIs (2.43.0) send no QR state: nothing was scanned.
    qr: qr === null ? { verified: false, validSeconds: 0 } : { verified: booleanValue(qr.verified), validSeconds: nonNegativeInteger(qr.validSeconds) },
  };
}
export function mapInvoiceRead(value: unknown): InvoiceRead {
  const raw = objectValue(value);
  if (raw.available !== true) return { available: false, reason: textValue(raw.reason, 60) };
  return { available: true, role: oneOf(raw.role, INVOICE_ROLES), ...mapInvoiceView(raw) };
}
export function mapInvoiceOutcome(value: unknown): InvoiceOutcome {
  const raw = objectValue(value);
  const state = oneOf(raw.state, ["applied", "pending", "unknown"] as const);
  if (state !== "applied") return { state, idempotencyKey: textValue(raw.idempotencyKey, 100), ...(typeof raw.reachable === "boolean" ? { reachable: raw.reachable } : {}) };
  // The view after an applied action is optional (a replayed key answers with the result only).
  return { state, idempotencyKey: textValue(raw.idempotencyKey, 100), result: invoiceText(raw.result, 20), ...(raw.summary == null ? {} : mapInvoiceView(raw)) };
}

function mapConfirmareOperator(value: unknown): ConfirmareOperator {
  const raw = objectValue(value);
  return {
    employeeId: stringValue(raw.employeeId), displayName: stringValue(raw.displayName), sources: stringList(raw.sources), canWork: booleanValue(raw.canWork), available: booleanValue(raw.available),
    availableChangedAt: raw.availableChangedAt == null ? null : timestampValue(raw.availableChangedAt), lastAssignedAt: raw.lastAssignedAt == null ? null : timestampValue(raw.lastAssignedAt),
    open: nonNegativeInteger(raw.open), oldestAssignedAt: raw.oldestAssignedAt == null ? null : timestampValue(raw.oldestAssignedAt),
  };
}

const countMap = (value: unknown): Record<string, number> => Object.fromEntries(Object.entries(objectValue(value)).map(([key, count]) => [key, nonNegativeInteger(count)]));

export function mapConfirmareTeam(value: unknown): ConfirmareTeam {
  const raw = objectValue(value);
  return { operators: Array.isArray(raw.operators) ? raw.operators.map(mapConfirmareOperator) : [], unassigned: countMap(raw.unassigned), undetermined: countMap(raw.undetermined), held: stringList(raw.held), targetOpenPerOperator: positiveInteger(raw.targetOpenPerOperator), inactivityMinutes: positiveInteger(raw.inactivityMinutes) };
}

function mapConfirmareAlert(value: unknown): ConfirmareAlert {
  const raw = objectValue(value);
  const assignee = objectValue(raw.assignee);
  return {
    globalOrderId: stringValue(raw.globalOrderId), orderNumber: stringValue(raw.orderNumber), sourceKey: stringValue(raw.sourceKey), kind: stringValue(raw.kind), raisedAt: timestampValue(raw.raisedAt),
    assignee: { employeeId: stringValue(assignee.employeeId), displayName: stringValue(assignee.displayName) }, assignedAt: raw.assignedAt == null ? null : timestampValue(raw.assignedAt),
    confirmationVersion: positiveInteger(raw.confirmationVersion), lastContactAt: raw.lastContactAt == null ? null : timestampValue(raw.lastContactAt),
  };
}

function mapConfirmareAvailability(value: unknown): ConfirmareAvailability {
  const raw = objectValue(value);
  return { employeeId: stringValue(raw.employeeId), available: booleanValue(raw.available), changedAt: raw.changedAt == null ? null : timestampValue(raw.changedAt), lastAssignedAt: raw.lastAssignedAt == null ? null : timestampValue(raw.lastAssignedAt), open: nonNegativeInteger(raw.open), targetOpen: positiveInteger(raw.targetOpen) };
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
    // Pieces in rework at this stage (API 2.31.0): each entry names the track and carries its order.
    trackItems: Array.isArray(raw.trackItems) ? raw.trackItems.map((item) => { const entry = objectValue(item); return { trackId: stringValue(entry.trackId), order: mapProductionOrder(entry.order) }; }) : [],
  };
}
export function mapStageSummary(value: unknown): StageSummary {
  const raw = objectValue(value);
  if (!Array.isArray(raw.stages)) throw new StaffServiceError("SERVER_ERROR");
  return raw.stages.map((item) => { const stage = objectValue(item); return { stageId: stringValue(stage.stageId), total: nonNegativeInteger(stage.total), mine: nonNegativeInteger(stage.mine),
    reworkTotal: stage.reworkTotal == null ? 0 : nonNegativeInteger(stage.reworkTotal), reworkMine: stage.reworkMine == null ? 0 : nonNegativeInteger(stage.reworkMine) }; });
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
    counts: { pending: nonNegativeInteger(counts.pending), attention: counts.attention === undefined ? 0 : nonNegativeInteger(counts.attention), released: nonNegativeInteger(counts.released), closed: nonNegativeInteger(counts.closed), earlier: counts.earlier === undefined ? 0 : nonNegativeInteger(counts.earlier), ignored: nonNegativeInteger(counts.ignored) },
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
    ...(raw.beforeBaseline === undefined ? {} : { beforeBaseline: booleanValue(raw.beforeBaseline) }),
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
  const item = raw.productionItem == null ? null : objectValue(raw.productionItem);
  return {
    lineId: packageIdValue(raw.lineId),
    lineNumber: positiveInteger(raw.lineNumber),
    productName: stringValue(raw.productName),
    stockCode: nullableText(raw.stockCode),
    productCode: nullableText(raw.productCode),
    barcode: nullableText(raw.barcode),
    suggestedTitle: nullableText(raw.suggestedTitle),
    suggestedTitleApproval: raw.suggestedTitleApproval == null ? null : { by: nullableText(objectValue(raw.suggestedTitleApproval).by), at: nullableTimestamp(objectValue(raw.suggestedTitleApproval).at) },
    productSize: nullableText(raw.productSize),
    productColor: nullableText(raw.productColor),
    quantity: positiveInteger(raw.quantity),
    sizeSuggestion: suggestion === null ? null : { width: stringValue(suggestion.width), height: stringValue(suggestion.height) },
    prepared: prepared === null ? null : { kind: oneOf(prepared.kind, lineKinds), widthCm: nullableText(prepared.widthCm), heightCm: nullableText(prepared.heightCm), meters: nullableText(prepared.meters), notes: nullableText(prepared.notes), productionTitle: nullableText(prepared.productionTitle), preparedBy: nullableText(prepared.preparedBy), preparedAt: nullableTimestamp(prepared.preparedAt) },
    productionItem: item === null ? null : { name: stringValue(item.name), productCode: nullableText(item.productCode) },
  };
}
export function mapTrendyolDetail(value: unknown): TrendyolPackageDetail {
  const raw = objectValue(value);
  const readiness = objectValue(raw.readiness);
  const capabilities = objectValue(raw.capabilities);
  const delivery = raw.delivery == null ? null : objectValue(raw.delivery);
  const production = raw.production == null ? null : objectValue(raw.production);
  const dismissal = raw.dismissal == null ? null : objectValue(raw.dismissal);
  const correction = raw.correction == null ? null : objectValue(raw.correction);
  if (!Array.isArray(raw.lines) || !Array.isArray(raw.siblings) || !Array.isArray(raw.history) || !Array.isArray(readiness.missingLines) || (delivery !== null && !Array.isArray(delivery.addressLines))) throw new StaffServiceError("SERVER_ERROR");
  return {
    ...mapTrendyolSummary(raw),
    delivery: delivery === null ? null : { name: nullableText(delivery.name), addressLines: (delivery.addressLines as unknown[]).map(stringValue), phoneMasked: nullableText(delivery.phoneMasked) },
    lines: raw.lines.map(mapTrendyolLine),
    readiness: { ready: booleanValue(readiness.ready), missingLines: readiness.missingLines.map(positiveInteger), marketplaceReleasable: booleanValue(readiness.marketplaceReleasable), blockedReason: readiness.blockedReason == null ? null : oneOf(readiness.blockedReason, blockedReasons) },
    siblings: raw.siblings.map((item) => { const sibling = objectValue(item); return { packageId: packageIdValue(sibling.packageId), intakeStatus: oneOf(sibling.intakeStatus, intakeStatuses), marketplaceStatus: stringValue(sibling.marketplaceStatus) }; }),
    production: production === null ? null : { globalOrderId: stringValue(production.globalOrderId), stageId: stringValue(production.stageId), stageLabel: nullableText(production.stageLabel), documentStatus: stringValue(production.documentStatus), operationalStatus: stringValue(production.operationalStatus), releasedBy: nullableText(production.releasedBy), releasedAt: nullableTimestamp(production.releasedAt) },
    dismissal: dismissal === null ? null : { reason: stringValue(dismissal.reason), by: nullableText(dismissal.by), at: nullableTimestamp(dismissal.at) },
    correction: correction === null ? null : { needed: booleanValue(correction.needed), allowed: booleanValue(correction.allowed) },
    history: raw.history.map((item) => { const event = objectValue(item); return { action: stringValue(event.action), actor: nullableText(event.actor), at: nullableTimestamp(event.at) }; }),
    capabilities: { ...mapTrendyolCapabilities(capabilities), prepareNow: booleanValue(capabilities.prepareNow), releaseNow: booleanValue(capabilities.releaseNow), dismissNow: booleanValue(capabilities.dismissNow), reopenNow: booleanValue(capabilities.reopenNow), correctNow: capabilities.correctNow === true },
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
