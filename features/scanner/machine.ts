import type { OrderActionId, StaffOrder, StaffServiceError } from "../../domain/models";
import type { CuttingIntakeOutcome } from "../../domain/cutting";

/** One cutting-intake request: the scanned label, its own idempotency key and the explicit workload confirmation. */
export type IntakeAttempt = { token: string; idempotencyKey: string; confirmedMultiple?: boolean; ownedCount?: number };

export type ScannerState =
  | { status: "idle" }
  | { status: "requesting_permission" }
  | { status: "scanning"; torchSupported: boolean; torchOn: boolean }
  | { status: "decoded"; token: string }
  | { status: "resolving"; token: string }
  | { status: "taking"; attempt: IntakeAttempt }
  | { status: "take_confirm"; token: string; ownedCount: number; changed: boolean }
  | { status: "review"; order: StaffOrder }
  | { status: "confirming"; order: StaffOrder }
  | { status: "submitting"; order: StaffOrder; idempotencyKey: string; action: OrderActionId }
  | { status: "success"; order: StaffOrder; action: OrderActionId; intake?: CuttingIntakeOutcome }
  | { status: "error"; error: StaffServiceError; recovery: "scan" | "manual" | "reload" | "login"; failedSubmission?: { order: StaffOrder; idempotencyKey: string; action: OrderActionId }; failedIntake?: IntakeAttempt };

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
  | { type: "INTAKE_STARTED"; attempt: IntakeAttempt }
  | { type: "INTAKE_SUCCEEDED"; order: StaffOrder; outcome: CuttingIntakeOutcome }
  | { type: "INTAKE_CONFIRM_REQUIRED"; ownedCount: number }
  | { type: "INTAKE_FAILED"; error: StaffServiceError }
  | { type: "INTAKE_NOT_APPLICABLE" }
  | { type: "RETRY_INTAKE" }
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
    // Cutting intake: one scan, one in-flight request; the order is shown only after the server committed it.
    case "INTAKE_STARTED":
      return state.status === "decoded" || (state.status === "take_confirm" && state.token === event.attempt.token)
        || (state.status === "taking" && state.attempt.token === event.attempt.token && state.attempt.idempotencyKey !== event.attempt.idempotencyKey && event.attempt.confirmedMultiple === undefined)
        ? { status: "taking", attempt: event.attempt }
        : state;
    case "INTAKE_SUCCEEDED":
      return state.status === "taking" ? { status: "success", order: event.order, action: "claim", intake: event.outcome } : state;
    case "INTAKE_CONFIRM_REQUIRED":
      return state.status === "taking" ? { status: "take_confirm", token: state.attempt.token, ownedCount: event.ownedCount, changed: state.attempt.confirmedMultiple === true } : state;
    case "INTAKE_FAILED":
      return state.status === "taking" ? { status: "error", error: event.error, recovery: recoveryFor(event.error), failedIntake: state.attempt } : state;
    case "INTAKE_NOT_APPLICABLE":
      // Not a cutting intake (another stage or source): the same scanned code takes the ordinary verification.
      return state.status === "taking" ? { status: "resolving", token: state.attempt.token } : state;
    case "RETRY_INTAKE":
      // A transient failure is retried with the same key and intent: the server replays a committed intake.
      return state.status === "error" && state.failedIntake && isTransient(state.error) ? { status: "taking", attempt: state.failedIntake } : state;
    case "OPEN_MANUAL":
      return state.status === "scanning" || state.status === "idle" || state.status === "error" ? { status: "idle" } : state;
    case "RESET":
      return { status: "idle" };
    default:
      return state;
  }
}
