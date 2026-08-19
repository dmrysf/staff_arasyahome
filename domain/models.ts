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
  avatar?: string;
  locale: "ro";
};

export type ProductionStage = {
  id: string;
  ordinal: number;
  label: string;
};

export type ProductionWorkflow = {
  id: string;
  name: string;
  version: number;
  stages: ProductionStage[];
};

export type ProductionItem = {
  id: string;
  name: string;
  code: string;
  color?: string;
  dimensions?: string;
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

export type StaffOrder = {
  id: string;
  source: OrderSource;
  orderNumber: string;
  productionStageId: string;
  sourceCommerceStatus?: { code: string; label: string };
  employeeAllowedAction?: { id: string; label: string };
  products: ProductionItem[];
  productionNotes?: string;
  employeeRelation?: EmployeeOrderRelation;
  acceptedAt?: string;
  updatedAt: string;
  status: "in_progress" | "handed_over" | "unavailable";
  version: number;
};

export type ActivityEntry = {
  id: string;
  occurredAt: string;
  orderId: string;
  orderNumber: string;
  source: OrderSource;
  fromStageId: string;
  fromStageLabelSnapshot: string;
  toStageId: string;
  toStageLabelSnapshot: string;
  meters?: number;
};

export type ActivityPage = {
  items: ActivityEntry[];
  nextCursor?: string;
  summary: { processed: number; meters: number; handedOver: number; inProgress: number };
};

export type HandoverStatus =
  | "owned"
  | "transfer_requested"
  | "transfer_accepted"
  | "transfer_rejected"
  | "transfer_cancelled";

export type HandoverRequest = {
  id: string;
  orderId: string;
  fromEmployeeUuid: string;
  toEmployeeUuid: string;
  status: HandoverStatus;
  requestedAt: string;
  resolvedAt?: string;
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
  | "WORKFLOW_UNAVAILABLE";

export class StaffServiceError extends Error {
  constructor(public readonly code: ServiceErrorCode, message?: string) {
    super(message ?? code);
    this.name = "StaffServiceError";
  }
}
