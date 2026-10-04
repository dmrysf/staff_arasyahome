import type { OrderActionId, StaffOrder, StaffServiceError } from "../../domain/models";

export type ScannerState =
  | { status: "idle" }
  | { status: "requesting_permission" }
  | { status: "scanning"; torchSupported: boolean; torchOn: boolean }
  | { status: "decoded"; token: string }
  | { status: "resolving"; token: string }
  | { status: "review"; order: StaffOrder }
  | { status: "confirming"; order: StaffOrder }
  | { status: "submitting"; order: StaffOrder; idempotencyKey: string; action: OrderActionId }
  | { status: "success"; order: StaffOrder; action: OrderActionId }
  | { status: "error"; error: StaffServiceError; recovery: "scan" | "manual" | "reload" | "login"; failedSubmission?: { order: StaffOrder; idempotencyKey: string; action: OrderActionId } };

export type ScannerEvent =
  | { type: "REQUEST_CAMERA" }
  | { type: "CAMERA_READY"; torchSupported?: boolean }
  | { type: "CAMERA_FAILED"; error: StaffServiceError }
  | { type: "TORCH_CHANGED"; enabled: boolean }
  | { type: "CODE_DETECTED"; token: string }
  | { type: "RESOLVE_STARTED" }
  | { type: "ORDER_RESOLVED"; order: StaffOrder }
  | { type: "RESOLVE_FAILED"; error: StaffServiceError }
  | { type: "OPEN_CONFIRMATION" }
  | { type: "CANCEL_CONFIRMATION" }
  | { type: "SUBMIT"; idempotencyKey: string }
  | { type: "SUBMIT_SUCCEEDED"; order: StaffOrder }
  | { type: "RETRY_SUBMIT" }
  | { type: "SUBMIT_FAILED"; error: StaffServiceError }
  | { type: "RESET" }
  | { type: "OPEN_MANUAL" };

function recoveryFor(error: StaffServiceError): "scan" | "manual" | "reload" | "login" {
  if (error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION" || error.code === "ACCOUNT_INACTIVE") return "login";
  if (["ORDER_CHANGED", "ORDER_ALREADY_CLAIMED", "INVALID_STAGE_TRANSITION", "IDEMPOTENCY_CONFLICT"].includes(error.code)) return "reload";
  if (["CAMERA_PERMISSION_DENIED", "CAMERA_UNAVAILABLE", "NO_CAMERA_DEVICE", "AUTOMATIC_SCAN_UNAVAILABLE"].includes(error.code)) return "manual";
  return "scan";
}

export function isTransient(error: StaffServiceError) {
  return ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(error.code);
}

export const initialScannerState: ScannerState = { status: "idle" };

export function scannerReducer(state: ScannerState, event: ScannerEvent): ScannerState {
  switch (event.type) {
    case "REQUEST_CAMERA":
      return state.status === "idle" || state.status === "error" ? { status: "requesting_permission" } : state;
    case "CAMERA_READY":
      return state.status === "requesting_permission" ? { status: "scanning", torchSupported: Boolean(event.torchSupported), torchOn: false } : state;
    case "CAMERA_FAILED":
      return state.status === "requesting_permission" ? { status: "error", error: event.error, recovery: recoveryFor(event.error) } : state;
    case "TORCH_CHANGED":
      return state.status === "scanning" && state.torchSupported ? { ...state, torchOn: event.enabled } : state;
    case "CODE_DETECTED":
      return state.status === "scanning" || state.status === "idle" ? { status: "decoded", token: event.token } : state;
    case "RESOLVE_STARTED":
      return state.status === "decoded" ? { status: "resolving", token: state.token } : state;
    case "ORDER_RESOLVED":
      return state.status === "resolving" ? { status: "review", order: event.order } : state;
    case "RESOLVE_FAILED":
      return state.status === "resolving" ? { status: "error", error: event.error, recovery: recoveryFor(event.error) } : state;
    case "OPEN_CONFIRMATION":
      return state.status === "review" && state.order.employeeAllowedAction ? { status: "confirming", order: state.order } : state;
    case "CANCEL_CONFIRMATION":
      return state.status === "confirming" ? { status: "review", order: state.order } : state;
    case "SUBMIT":
      return state.status === "confirming" && state.order.employeeAllowedAction
        ? { status: "submitting", order: state.order, idempotencyKey: event.idempotencyKey, action: state.order.employeeAllowedAction.id }
        : state;
    case "SUBMIT_SUCCEEDED":
      return state.status === "submitting" ? { status: "success", order: event.order, action: state.action } : state;
    case "SUBMIT_FAILED":
      return state.status === "submitting"
        ? { status: "error", error: event.error, recovery: recoveryFor(event.error), failedSubmission: { order: state.order, idempotencyKey: state.idempotencyKey, action: state.action } }
        : state;
    case "RETRY_SUBMIT":
      // A transient failure is retried with the same idempotency key: the server returns the
      // original committed result if the first attempt actually succeeded.
      return state.status === "error" && state.failedSubmission && isTransient(state.error)
        ? { status: "submitting", ...state.failedSubmission }
        : state;
    case "OPEN_MANUAL":
      return state.status === "scanning" || state.status === "idle" || state.status === "error" ? { status: "idle" } : state;
    case "RESET":
      return { status: "idle" };
    default:
      return state;
  }
}
