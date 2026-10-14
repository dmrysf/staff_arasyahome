import { StaffServiceError, type ServiceErrorCode } from "../domain/models";
import { invalidDocumentMessage } from "../domain/documents";
import { t } from "../i18n";

export type ErrorPresentation = { title: string; message: string; action: string };

type ErrorActionKey = "back" | "reload" | "complete" | "check" | "tryAgain" | "correct" | "enterCode" | "scanAgain" | "searchManually" | "scanAnother" | "enterOrderCode" | "signIn" | "tryLater" | "retry" | "changePassword" | "signOut" | "chooseCutter" | "chooseEmployee";

/** The recovery action of each error code; the words come from the active language catalog. */
const actions: Record<ServiceErrorCode, ErrorActionKey> = {
  TRENDYOL_PACKAGE_NOT_FOUND: "back",
  TRENDYOL_PACKAGE_CHANGED: "reload",
  TRENDYOL_PACKAGE_NOT_PENDING: "reload",
  TRENDYOL_PACKAGE_NOT_PREPARED: "complete",
  TRENDYOL_STATUS_NOT_RELEASABLE: "reload",
  TRENDYOL_LINES_CHANGED: "check",
  TRENDYOL_PACKAGE_UNAVAILABLE: "reload",
  TRENDYOL_VERIFICATION_FAILED: "tryAgain",
  TRENDYOL_INPUT_INVALID: "correct",
  CAMERA_PERMISSION_DENIED: "tryAgain",
  CAMERA_UNAVAILABLE: "enterCode",
  NO_CAMERA_DEVICE: "enterCode",
  INVALID_QR: "scanAgain",
  UNKNOWN_QR: "scanAgain",
  EXPIRED_QR: "searchManually",
  ORDER_NOT_FOUND: "tryAgain",
  ORDER_ALREADY_CLAIMED: "scanAnother",
  ORDER_AMBIGUOUS: "tryAgain",
  INVALID_ORDER_CODE: "tryAgain",
  INVALID_STAGE_TRANSITION: "reload",
  IDEMPOTENCY_CONFLICT: "reload",
  ORDER_UNAVAILABLE: "scanAnother",
  ORDER_PRODUCTS_UNAVAILABLE: "scanAgain",
  ORDER_CHANGED: "reload",
  AUTOMATIC_SCAN_UNAVAILABLE: "enterOrderCode",
  NETWORK_UNAVAILABLE: "tryAgain",
  REQUEST_TIMEOUT: "tryAgain",
  SERVER_ERROR: "tryAgain",
  NO_SESSION: "signIn",
  SESSION_EXPIRED: "signIn",
  UNAUTHORIZED_ACTION: "back",
  INVALID_CREDENTIALS: "tryAgain",
  ACCOUNT_INACTIVE: "back",
  RATE_LIMITED: "tryLater",
  SERVICE_UNAVAILABLE: "retry",
  CSRF_INVALID: "tryAgain",
  CONFIGURATION_ERROR: "retry",
  PASSWORD_CHANGE_REQUIRED: "changePassword",
  APPLICATION_ACCESS_DENIED: "signOut",
  CURRENT_PASSWORD_INVALID: "tryAgain",
  PASSWORD_POLICY: "tryAgain",
  ORDER_BLOCKED_BY_EXCEPTION: "reload",
  EXCEPTION_ALREADY_OPEN: "reload",
  EXCEPTION_STAGE_INVALID: "reload",
  FAULT_REPORT_NOT_ALLOWED: "back",
  SELF_FAULT_REPORT_DENIED: "back",
  RESPONSIBLE_EMPLOYEE_UNKNOWN: "back",
  FAULT_LINES_INVALID: "reload",
  FAULT_LINE_WITHOUT_METERS: "back",
  REASON_INVALID: "reload",
  COMMENT_REQUIRED: "back",
  CONFIRMATION_REQUIRED: "back",
  QR_REQUIRED: "scanAgain",
  QR_ORDER_MISMATCH: "scanAgain",
  EXCEPTION_NOT_FOUND: "back",
  EXCEPTION_NOT_ASSIGNED: "back",
  EXCEPTION_CHANGED: "reload",
  EXCEPTION_STATE_INVALID: "reload",
  EXCEPTION_ALREADY_RESOLVED: "reload",
  SUPERVISOR_REQUIRED: "back",
  SELF_REVIEW_DENIED: "back",
  RESPONSIBLE_UNAVAILABLE: "chooseCutter",
  RETURN_CANCEL_CONFIRMATION_REQUIRED: "back",
  RECEIVER_NOT_ALLOWED: "reload",
  RESTORE_RECIPIENT_UNAVAILABLE: "chooseEmployee",
  RECIPIENT_NOT_ALLOWED: "reload",
  RECIPIENT_INVALID: "reload",
  RECEIVER_INVALID: "reload",
  RECEIVER_NOT_ELIGIBLE: "back",
  AVANS_NEVALIDAT: "back",
  ORDER_BLOCKED_BY_DOCUMENT: "reload",
  DOCUMENT_SUPERSEDED: "scanAgain",
  DOCUMENT_REVOKED: "scanAgain",
  DOCUMENT_NOT_GENERATED: "reload",
  DOCUMENT_ALREADY_ACTIVE: "reload",
  DOCUMENT_NOT_STALE: "reload",
  DOCUMENT_REQUEST_OPEN: "reload",
  DOCUMENT_APPROVAL_REQUIRED: "reload",
  DOCUMENT_CONTENT_CHANGED: "reload",
  DOCUMENT_CHANGED: "reload",
  DOCUMENT_ORDER_COMPLETED: "back",
  DOCUMENT_AUTHORITY_SOURCE: "back",
  DOCUMENT_NOT_FOUND: "reload",
  DOCUMENT_REVISION_NOT_ACTIVE: "reload",
  CUTTING_INTAKE_NOT_APPLICABLE: "scanAgain",
  PRODUCTION_AUTHORITY_SOURCE: "back",
  AUTHORITY_CUTOVER_DISABLED: "back",
  AUTHORITY_NOT_SUPPORTED: "back",
  AUTHORITY_ALREADY_OPERATIONS: "reload",
  AUTHORITY_NOT_OPERATIONS: "reload",
  AUTHORITY_RELEASE_NOT_ALLOWED: "reload",
  INVALID_STAGE: "back",
  WORKFLOW_MISMATCH: "reload",
  PRODUCTION_COMPLETED: "back",
  WORKFLOW_UNAVAILABLE: "retry",
  QR_SUPERSEDED: "scanAgain",
  QR_REVOKED: "scanAgain",
  QR_CHANGED: "reload",
  QR_CUTOVER_DISABLED: "back",
  QR_NOT_ARASYA: "back",
  QR_NOT_SUPPORTED: "back",
  QR_DOCUMENT_CONTROLLED: "back",
  PARTIAL_REWORK_DISABLED: "back",
  UNITS_INVALID: "reload",
  UNIT_REVIEW_REQUIRED: "back",
  UNIT_LABEL_MISMATCH: "scanAgain",
  TRACK_NOT_FOUND: "back",
  TRACK_CHANGED: "reload",
  TRACK_ALREADY_CLAIMED: "reload",
  TRACK_WAITING_FOR_ORDER: "back",
  DEFECT_RESOLUTION_REQUIRED: "back",
  ORDER_PIECES_IN_REWORK: "back",
};

/** Title, message and action of one error code in the active language (catalog keys `error.<CODE>.*`). */
function presentation(code: ServiceErrorCode): ErrorPresentation {
  return { title: t(`error.${code}.title`), message: t(`error.${code}.message`), action: t(`errorAction.${actions[code]}`) };
}

export function getErrorPresentation(error: unknown): ErrorPresentation {
  // An old or blocked production document is shown in strong words, with the active revision.
  const document = invalidDocumentMessage(error);
  if (document && error instanceof StaffServiceError) return { title: document.title, message: document.lines.join(" "), action: presentation(error.code).action };
  // A replaced production QR names the active QR revision when the employee may see the order.
  if (error instanceof StaffServiceError && error.code === "QR_SUPERSEDED" && typeof error.details?.activeQrRevision === "number") {
    return { ...presentation("QR_SUPERSEDED"), message: t("error.QR_SUPERSEDED.messageRevision", { revision: error.details.activeQrRevision }) };
  }
  if (error instanceof StaffServiceError && Object.hasOwn(actions, error.code)) return presentation(error.code);
  return presentation("SERVER_ERROR");
}

export function toServiceError(error: unknown): StaffServiceError {
  if (error instanceof StaffServiceError) return error;
  if (error instanceof DOMException && error.name === "NotAllowedError") return new StaffServiceError("CAMERA_PERMISSION_DENIED");
  if (error instanceof DOMException && error.name === "NotFoundError") return new StaffServiceError("NO_CAMERA_DEVICE");
  if (error instanceof DOMException && (error.name === "NotReadableError" || error.name === "AbortError")) return new StaffServiceError("CAMERA_UNAVAILABLE");
  return new StaffServiceError("SERVER_ERROR");
}

export function mapCameraError(error: { name?: string }): StaffServiceError {
  if (error.name === "NotAllowedError" || error.name === "SecurityError") return new StaffServiceError("CAMERA_PERMISSION_DENIED");
  if (error.name === "NotFoundError" || error.name === "DevicesNotFoundError") return new StaffServiceError("NO_CAMERA_DEVICE");
  return new StaffServiceError("CAMERA_UNAVAILABLE");
}
