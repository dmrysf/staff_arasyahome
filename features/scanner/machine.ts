import type { StaffOrder, StaffServiceError } from "../../domain/models";

export type ScannerState =
  | { status: "idle" }
  | { status: "requesting_permission" }
  | { status: "scanning"; torchSupported: boolean; torchOn: boolean }
  | { status: "decoded"; token: string }
  | { status: "resolving"; token: string }
  | { status: "review"; order: StaffOrder }
  | { status: "confirming"; order: StaffOrder }
  | { status: "submitting"; order: StaffOrder; idempotencyKey: string }
  | { status: "success"; order: StaffOrder }
  | { status: "error"; error: StaffServiceError; recovery: "scan" | "manual" | "reload" | "login" };

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
  | { type: "SUBMIT_FAILED"; error: StaffServiceError }
  | { type: "RESET" }
  | { type: "OPEN_MANUAL" };

function recoveryFor(error: StaffServiceError): "scan" | "manual" | "reload" | "login" {
  if (error.code === "SESSION_EXPIRED") return "login";
  if (error.code === "ORDER_CHANGED") return "reload";
  if (["CAMERA_PERMISSION_DENIED", "CAMERA_UNAVAILABLE", "NO_CAMERA_DEVICE", "AUTOMATIC_SCAN_UNAVAILABLE"].includes(error.code)) return "manual";
  return "scan";
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
      return state.status === "confirming" ? { status: "submitting", order: state.order, idempotencyKey: event.idempotencyKey } : state;
    case "SUBMIT_SUCCEEDED":
      return state.status === "submitting" ? { status: "success", order: event.order } : state;
    case "SUBMIT_FAILED":
      return state.status === "submitting" ? { status: "error", error: event.error, recovery: recoveryFor(event.error) } : state;
    case "OPEN_MANUAL":
      return state.status === "scanning" || state.status === "idle" || state.status === "error" ? { status: "idle" } : state;
    case "RESET":
      return { status: "idle" };
    default:
      return state;
  }
}
