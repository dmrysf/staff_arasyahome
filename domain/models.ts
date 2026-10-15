import type { Locale } from "../i18n/locales";

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
  /**
   * Granted stages narrowed to named order sources (API 2.26.0). A stage absent here reaches every source. The
   * server enforces it on every order route; Staff only uses it to say which sources a stage dashboard covers.
   */
  stageSourceScopes?: Readonly<Record<string, readonly string[]>>;
  /** Central IAM applications this identity may enter; Staff requires "staff". */
  applications: string[];
  /** A temporary password must be replaced before any other use. */
  mustChangePassword: boolean;
  isRoot?: boolean;
  avatar?: string;
  /** Staff interface language assigned to the account in central IAM (API 2.30.0); Romanian when none. */
  locale: Locale;
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
  | "production_authority_source"
  | "payment_gate_closed"
  /** The order is at packing while some of its pieces are still in rework (API 2.31.0). */
  | "pieces_in_rework"
  /** PieceGate (API 2.32.0): a held piece, a piece apart on its route, an unconfirmed plan, missing pieces, an unfinished route or an unconfirmed defect resolution. */
  | "pieces_held"
  | "pieces_awaiting_reunion"
  | "plan_confirmation_required"
  | "pieces_incomplete"
  | "route_incomplete"
  | "defect_unresolved";

/** One physical piece of an order line ("Bucata 1/2"); trackId is set while the piece travels apart, in rework. */
export type ProductionPiece = { id: string; itemId: string; index: number; count: number; lineNumber: number; trackId: string | null; reworkCycle: number; reviewRequired: boolean; productType?: string; fabricMeters?: string | null; replacementOf?: string | null };

export type PieceTrackActionId = "claim" | "complete_stage";

/** Pieces that left the order because only they were defective: their own stage, owner, rework cycle and work. */
export type PieceTrack = {
  id: string;
  number: number;
  stage: { id: string; label: string | null };
  owner: { displayName: string; isMe: boolean } | null;
  version: number;
  reworkCycle: number;
  /** The pieces went through an approved return to cutting (not only a report that was later closed). */
  reworked: boolean;
  unitIds: string[];
  quantity: number;
  /** The open request of these pieces: as the employee sees it on single-order views, otherwise id/number/status. */
  openException: { id: string; number: string; status: FaultExceptionStatus; actions?: FaultActions; orderScope?: FaultException["orderScope"] } | null;
  /** At packing: the pieces wait for the rest of the order and rejoin it there. */
  waitingForOrder: boolean;
  verifyStageId: string | null;
  resolutionConfirmed: boolean;
  /** Completing the current stage needs the confirmation that the original defect is resolved. */
  requiresDefectResolution: boolean;
  requiresPieceOutcome?: boolean;
  kind?: "route" | "rework";
  nextStageId: string | null;
  changedAt: string;
  allowedAction: { id: PieceTrackActionId } | null;
  blockedReason: string | null;
};

/** Every physical piece of the order and the tracks of pieces in rework. mixedProgress: not every piece is at the order's stage. */
export type OrderPieces = { total: number | null; allPlansKnown?: boolean; partialRework?: boolean; pieceRouting?: boolean; requiresPieceOutcome?: boolean; requiresDefectResolution?: boolean; onOrder: number; inRework: number; mixedProgress: boolean; units: ProductionPiece[]; tracks: PieceTrack[] };

/** Advance payment gate of a Trend Home / Outlet Perdele order: state only, never an amount. */
export type PaymentGateState = "online" | "not_required" | "confirmed" | "waiting" | "partial" | "unknown" | "unverified";
export type PaymentGateView = { state: PaymentGateState; label: string; mode: "observe" | "enforce"; blocking: boolean; reversedAt: string | null };

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
  /** Present only while the source's advance gate is observed or enforced. */
  paymentGate?: PaymentGateView;
  /** Physical pieces (API 2.31.0). productionStageId is the stage of the pieces that travel with the order. */
  pieces?: OrderPieces;
  /** The exact piece a scanned piece label (ARASYA:U1:) identified. */
  scannedPiece?: { unitId: string; trackId: string | null };
};

export type FaultExceptionStatus = "awaiting_acknowledgment" | "awaiting_approval" | "approved" | "rejected" | "cancelled"
  | "awaiting_supervisor_review" | "dismissed" | "in_supervisor_custody" | "return_rejected" | "awaiting_restore_receipt" | "restored";

/** What the signed-in employee may do with one request; the server decides every flag. */
export type FaultActions = {
  canAcknowledge: boolean;
  /** The receiver is also the responsible cutting employee and states "accepted" or "disputed". */
  statesResponsibility: boolean;
  canRequestRereview: boolean;
  canReview: boolean;
  canRequestReturn: boolean;
  canRestore: boolean;
  canAskManagersAgain: boolean;
  canAddNote: boolean;
  /** The addressed tailoring employee confirms, with the product's QR, that the product is back in their hands. */
  canConfirmRestore: boolean;
};

export type FaultTimelineEntry = { action: string; actor: string; at: string; version: number; comment: string | null };

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

/**
 * A production exception. `cutting_fault`: the original return from tailoring intake to cutting.
 * `tailoring_defect`: a defect found at stages 3-12, handled by the tailoring quality supervisor; only
 * its final return to cutting needs a manager. Meters are exact decimal strings ("17.000").
 */
export type FaultException = {
  id: string;
  number: string;
  type: "cutting_fault" | "tailoring_defect";
  status: FaultExceptionStatus;
  version: number;
  order: { id: string; orderNumber: string; source: OrderSource; sourceName: string };
  reason: { key: string; label: string };
  lineCount: number;
  /** Sum of the measured lines; null when no line was measured. Unknown meters are never a measured zero. */
  faultMeters: string | null;
  /** Lines the source sent without meters. */
  unmeasuredLineCount: number;
  arrivalNumber: number;
  reworkCycle: number | null;
  repeatedError: boolean;
  detector: { displayName: string };
  responsible: { displayName: string };
  reportedAt: string;
  acknowledgedAt: string | null;
  resolvedAt: string | null;
  pendingSince: string | null;
  detectedStage: { id: string; label: string | null };
  confirmedReason: { key: string; label: string; cuttingAttributable: boolean } | null;
  supervisor: { displayName: string } | null;
  receiver: { displayName: string } | null;
  physicalCustodian: { displayName: string } | null;
  responsibilityPosition: "accepted" | "disputed" | "not_stated" | null;
  attribution: "cutter" | "none" | null;
  returnRequestedAt: string | null;
  /** Tailoring employee who must physically receive the product back before the order is unblocked. */
  restoreRecipient: { displayName: string } | null;
  restoreRequestedAt: string | null;
  restoreReceivedAt: string | null;
  /** Root recovery: the case ends as cancelled once the recipient receives the product. */
  cancelledByRoot: boolean;
  /** Production is routed per order: an approved return moves the whole order, not only the affected lines. Absent on older APIs. */
  orderScope?: {
    orderLineCount: number; orderQuantity: number; affectedLineCount: number; affectedQuantity: number; wholeOrderMoves: boolean;
    /** API 2.31.0: pieces an approval moves, pieces not affected, whether only the named pieces move (on their own track). */
    movingQuantity?: number; unaffectedQuantity?: number; partial?: boolean; piecesRecorded?: boolean; carrier?: "order" | "track"; trackId?: string | null;
  };
  /** The exact pieces the report named (API 2.31.0); absent for older line reports. */
  affectedPieces?: { unitId: string; itemId: string; piece: string; index: number; count: number; lineNumber: number | null; name: string | null; code: string | null; color: string | null; reworkCycleAtReport: number; trackId: string | null }[];
  role: "responsible" | "detector" | "receiver" | "recipient" | "supervisor" | null;
  actions: FaultActions;
  detectorComment?: string | null;
  supervisorComment?: string | null;
  timeline?: FaultTimelineEntry[];
  cuttingHandoff?: { employee: string; at: string; arrivalNumber: number } | null;
  currentStage?: { id: string; label: string | null };
  acknowledgmentComment?: string | null;
  lines?: { itemId: string; lineNumber: number; name: string; code: string | null; variant: string | null; color: string | null; quantity: number; meters: string | null; affectedQuantity?: number }[];
  decisions?: FaultDecision[];
};

export type OrderQuality = {
  arrivalNumber: number;
  reworkCycles: number;
  repeatedErrors: boolean;
  openException: FaultException | null;
};

export type FaultReason = { key: string; label: string; requiresComment: boolean; cuttingAttributable: boolean };

/** Whether the signed-in employee supervises tailoring defects, and whether the supervised flow is active. */
export type QualityCapabilities = { tailoringSupervisor: boolean; supervisedSources: OrderSource[]; supervisedFlowActive: boolean; /** Reporting single pieces is enabled (API 2.31.0). */ partialRework?: boolean };

export type SupervisorView = "review" | "custody" | "return" | "done";

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
  | "TRENDYOL_PACKAGE_NOT_FOUND"
  | "TRENDYOL_PACKAGE_CHANGED"
  | "TRENDYOL_PACKAGE_NOT_PENDING"
  | "TRENDYOL_PACKAGE_NOT_PREPARED"
  | "TRENDYOL_STATUS_NOT_RELEASABLE"
  | "TRENDYOL_INPUT_INVALID"
  | "TRENDYOL_VERIFICATION_FAILED"
  | "TRENDYOL_PACKAGE_UNAVAILABLE"
  | "TRENDYOL_LINES_CHANGED"
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
  | "SUPERVISOR_REQUIRED"
  | "SELF_REVIEW_DENIED"
  | "RESPONSIBLE_UNAVAILABLE"
  | "RETURN_CANCEL_CONFIRMATION_REQUIRED"
  | "RESTORE_RECIPIENT_UNAVAILABLE"
  | "RECIPIENT_NOT_ALLOWED"
  | "RECIPIENT_INVALID"
  | "RECEIVER_NOT_ALLOWED"
  | "RECEIVER_INVALID"
  | "RECEIVER_NOT_ELIGIBLE"
  | "ORDER_BLOCKED_BY_DOCUMENT"
  | "AVANS_NEVALIDAT"
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
  | "DOCUMENT_AUTHORITY_SOURCE"
  | "DOCUMENT_NOT_FOUND"
  | "PRODUCTION_AUTHORITY_SOURCE"
  | "CUTTING_INTAKE_NOT_APPLICABLE"
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
  | "QR_DOCUMENT_CONTROLLED"
  | "PARTIAL_REWORK_DISABLED"
  | "UNITS_INVALID"
  | "UNIT_REVIEW_REQUIRED"
  | "UNIT_LABEL_MISMATCH"
  | "TRACK_NOT_FOUND"
  | "TRACK_CHANGED"
  | "TRACK_ALREADY_CLAIMED"
  | "TRACK_WAITING_FOR_ORDER"
  | "DEFECT_RESOLUTION_REQUIRED"
  | "ORDER_PIECES_IN_REWORK";

export class StaffServiceError extends Error {
  constructor(public readonly code: ServiceErrorCode, message?: string, public readonly details?: Readonly<Record<string, unknown>>) {
    super(message ?? code);
    this.name = "StaffServiceError";
  }
}
