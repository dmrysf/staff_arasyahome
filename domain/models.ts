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

export type ProductionItem = {
  productionContext?: { kind: 'curtain' | 'drapery' | 'other'; notes: string | null; productionNotes: string | null };
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
  | "workflow_unavailable";

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
};

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
  | "PASSWORD_POLICY";

export class StaffServiceError extends Error {
  constructor(public readonly code: ServiceErrorCode, message?: string) {
    super(message ?? code);
    this.name = "StaffServiceError";
  }
}
