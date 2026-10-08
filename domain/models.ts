export type OrderSource = "trendhome" | "outletperdele" | "trendyol" | "b2b" | "marketplace" | "unknown";

export type Employee = {
  employeeUuid: string;
  employeeCode?: string;
  displayName: string;
  username: string;
  department: string;
  departmentKey?: string;
  role: string;
  status: "active" | "inactive" | "suspended";
  permissions: string[];
  allowedStageIds: string[];
  /** Central IAM applications this identity may enter; Staff requires "staff". */
  applications: string[];
  /** A temporary password must be replaced before any other use. */
  mustChangePassword: boolean;
  isRoot?: boolean;
  avatar?: string;
  locale: "ro";
};

export type ProductionStage = {
  readonly id: string;
  readonly ordinal: number;
  readonly label: string;
};

export type ProductionWorkflow = {
  readonly id: string;
  readonly name: string;
  readonly version: number;
  readonly stages: readonly ProductionStage[];
};

/** Frozen B2B project location of a production item (labels at conversion time, no money). */
export type ProjectLocation = {
  projectCode: string; projectName: string;
  zone: { name: string; zoneType: 'floor' | 'zone'; level: number | null; building: string | null };
  room: { name: string };
  opening: { name: string; openingType: string; width: string | null; height: string | null; sillHeight: string | null; mounting: 'ceiling' | 'wall' | 'recess' | null; railType: string | null };
  treatment: { treatmentType: 'sheer' | 'drapery' | 'blackout' | 'rail' | 'accessory' | 'other'; panelLayout: 'single' | 'pair' | 'left' | 'right' | null };
};

export type ProductionItem = {
  productionContext?: { kind: 'curtain' | 'drapery' | 'other'; notes: string | null; productionNotes: string | null; project?: ProjectLocation };
  /** Manufacturing options exactly as the source stated them (never inferred). */
  options?: { label: string; value: string }[];
  id: string;
  name: string;
  code?: string;
  variant?: string;
  color?: string;
  measurements?: {
    width?: number;
    height?: number;
    unit?: "mm" | "cm" | "m";
  };
  meters?: number;
  quantity: number;
};

export type EmployeeOrderRelationType =
  | "claimed"
  | "assigned"
  | "updated"
  | "handover_in"
  | "handover_out"
  | "completed";

export type EmployeeOrderRelation = {
  employeeUuid: string;
  type: EmployeeOrderRelationType;
  lastActionAt: string;
};

/** The single operation the server currently allows this employee to perform. */
export type OrderActionId = "claim" | "complete_stage" | "complete_production";

export type OrderActionBlockedReason =
  | "claimed_by_other"
  | "stage_not_allowed"
  | "production_completed"
  | "order_unavailable"
  | "permission_missing"
  | "workflow_unavailable"
  | "exception_pending"
  | "document_revision_pending"
  | "production_authority_source";

export type StaffOrder = {
  productionContext?: { company: { legalName: string; companyCode: string; countryCode: string; taxIdentifier: string } };
  id: string;
  source: OrderSource;
  orderNumber: string;
  productionStageId: string;
  sourceCommerceStatus?: { code: string; label: string };
  employeeAllowedAction?: { id: OrderActionId; label: string };
  employeeActionBlockedReason?: OrderActionBlockedReason;
  products: ProductionItem[];
  productionNotes?: string;
  employeeRelation?: EmployeeOrderRelation;
  acceptedAt?: string;
  updatedAt: string;
  productionCompletedAt?: string;
  status: "in_progress" | "handed_over" | "unavailable";
  freshness?: {
    status: "fresh" | "stale" | "source_unavailable";
    sourceChangedAt: string;
    lastSourceSeenAt: string;
  };
  version: number;
  /** Production revision used as expectedVersion; commerce-only updates never change it. */
  productionVersion: number;
  /** Who manages production: the commerce source (legacy YD SOFT) or Arasya. */
  productionAuthority?: import("./authority").ProductionAuthority;
  /** Internal quality facts, present on single-order views only. Never shown to customers. */
  productionQuality?: OrderQuality;
  /** Central production document state; a stale or revoked document blocks every production action. */
  documentStatus?: import("./documents").DocumentStatus;
  productionDocument?: import("./documents").OrderDocumentSummary;
};

export type FaultExceptionStatus = "awaiting_acknowledgment" | "awaiting_approval" | "approved" | "rejected" | "cancelled";

export type FaultDecision = {
  attempt: number;
  status: "pending" | "approved" | "rejected" | "cancelled";
  openedReason: "acknowledged" | "rereview";
  openedBy: string;
  openedComment: string | null;
  openedAt: string;
  decidedAt: string | null;
  decidedBy: string | null;
  comment: string | null;
};

/** A cutting fault return request. Meters are exact decimal strings from the server ("17.000"). */
export type FaultException = {
  id: string;
  number: string;
  status: FaultExceptionStatus;
  version: number;
  order: { id: string; orderNumber: string; source: OrderSource; sourceName: string };
  reason: { key: string; label: string };
  lineCount: number;
  faultMeters: string;
  arrivalNumber: number;
  reworkCycle: number | null;
  repeatedError: boolean;
  detector: { displayName: string };
  responsible: { displayName: string };
  reportedAt: string;
  acknowledgedAt: string | null;
  resolvedAt: string | null;
  pendingSince: string | null;
  role: "responsible" | "detector" | null;
  actions: { canAcknowledge: boolean; canRequestRereview: boolean };
  detectorComment?: string | null;
  acknowledgmentComment?: string | null;
  lines?: { itemId: string; lineNumber: number; name: string; code: string | null; variant: string | null; color: string | null; quantity: number; meters: string }[];
  decisions?: FaultDecision[];
};

export type OrderQuality = {
  arrivalNumber: number;
  reworkCycles: number;
  repeatedErrors: boolean;
  openException: FaultException | null;
};

export type FaultReason = { key: string; label: string; requiresComment: boolean };

/** One live notification; payloads carry identifiers only and the screen re-reads through REST. */
export type LiveEvent = { seq: number; type: string; exceptionId?: string; transferId?: string; orderId?: string; orderNumber?: string; status?: string; requestId?: string; revisionNumber?: number };

export type OrderPage = {
  items: StaffOrder[];
  nextCursor?: string;
};

export type ActivityAction = "claimed" | "stage_completed" | "production_completed";

export type ActivityEntry = {
  id: string;
  occurredAt: string;
  action: ActivityAction;
  orderId: string;
  orderNumber: string;
  source: OrderSource;
  fromStageId: string;
  fromStageLabelSnapshot: string;
  toStageId?: string;
  toStageLabelSnapshot?: string;
  meters?: number;
};

export type ActivityPage = {
  items: ActivityEntry[];
  nextCursor?: string;
  summary: { processed: number; meters: number; handedOver: number; inProgress: number };
};

export type ServiceErrorCode =
  | "CAMERA_PERMISSION_DENIED"
  | "CAMERA_UNAVAILABLE"
  | "NO_CAMERA_DEVICE"
  | "INVALID_QR"
  | "UNKNOWN_QR"
  | "EXPIRED_QR"
  | "ORDER_NOT_FOUND"
  | "ORDER_UNAVAILABLE"
  | "ORDER_PRODUCTS_UNAVAILABLE"
  | "ORDER_CHANGED"
  | "ORDER_ALREADY_CLAIMED"
  | "ORDER_AMBIGUOUS"
  | "INVALID_ORDER_CODE"
  | "INVALID_STAGE_TRANSITION"
  | "IDEMPOTENCY_CONFLICT"
  | "AUTOMATIC_SCAN_UNAVAILABLE"
  | "NETWORK_UNAVAILABLE"
  | "REQUEST_TIMEOUT"
  | "SERVER_ERROR"
  | "NO_SESSION"
  | "SESSION_EXPIRED"
  | "UNAUTHORIZED_ACTION"
  | "INVALID_CREDENTIALS"
  | "ACCOUNT_INACTIVE"
  | "RATE_LIMITED"
  | "SERVICE_UNAVAILABLE"
  | "CSRF_INVALID"
  | "CONFIGURATION_ERROR"
  | "WORKFLOW_UNAVAILABLE"
  | "PASSWORD_CHANGE_REQUIRED"
  | "APPLICATION_ACCESS_DENIED"
  | "CURRENT_PASSWORD_INVALID"
  | "PASSWORD_POLICY"
  | "ORDER_BLOCKED_BY_EXCEPTION"
  | "EXCEPTION_ALREADY_OPEN"
  | "EXCEPTION_STAGE_INVALID"
  | "FAULT_REPORT_NOT_ALLOWED"
  | "SELF_FAULT_REPORT_DENIED"
  | "RESPONSIBLE_EMPLOYEE_UNKNOWN"
  | "FAULT_LINES_INVALID"
  | "FAULT_LINE_WITHOUT_METERS"
  | "REASON_INVALID"
  | "COMMENT_REQUIRED"
  | "CONFIRMATION_REQUIRED"
  | "QR_REQUIRED"
  | "QR_ORDER_MISMATCH"
  | "EXCEPTION_NOT_FOUND"
  | "EXCEPTION_NOT_ASSIGNED"
  | "EXCEPTION_CHANGED"
  | "EXCEPTION_STATE_INVALID"
  | "EXCEPTION_ALREADY_RESOLVED"
  | "ORDER_BLOCKED_BY_DOCUMENT"
  | "DOCUMENT_SUPERSEDED"
  | "DOCUMENT_REVOKED"
  | "DOCUMENT_NOT_GENERATED"
  | "DOCUMENT_ALREADY_ACTIVE"
  | "DOCUMENT_NOT_STALE"
  | "DOCUMENT_REQUEST_OPEN"
  | "DOCUMENT_APPROVAL_REQUIRED"
  | "DOCUMENT_CONTENT_CHANGED"
  | "DOCUMENT_CHANGED"
  | "DOCUMENT_ORDER_COMPLETED"
  | "DOCUMENT_REVISION_NOT_ACTIVE"
  | "PRODUCTION_AUTHORITY_SOURCE"
  | "AUTHORITY_CUTOVER_DISABLED"
  | "AUTHORITY_NOT_SUPPORTED"
  | "AUTHORITY_ALREADY_OPERATIONS"
  | "AUTHORITY_NOT_OPERATIONS"
  | "AUTHORITY_RELEASE_NOT_ALLOWED"
  | "INVALID_STAGE"
  | "WORKFLOW_MISMATCH"
  | "PRODUCTION_COMPLETED"
  | "QR_SUPERSEDED"
  | "QR_REVOKED"
  | "QR_CHANGED"
  | "QR_CUTOVER_DISABLED"
  | "QR_NOT_ARASYA"
  | "QR_NOT_SUPPORTED"
  | "QR_DOCUMENT_CONTROLLED";

export class StaffServiceError extends Error {
  constructor(public readonly code: ServiceErrorCode, message?: string, public readonly details?: Readonly<Record<string, unknown>>) {
    super(message ?? code);
    this.name = "StaffServiceError";
  }
}
