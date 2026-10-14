import { documentLiveMessage } from "./documents";
import { liveLabels, t, tKnown, type MessageKey } from "../i18n";
import type { FaultException, FaultExceptionStatus, LiveEvent, OrderSource, ProductionItem, QualityCapabilities, StaffOrder, SupervisorView } from "./models";

export const faultStatusLabels: Readonly<Record<FaultExceptionStatus, string>> = liveLabels("fault.status.", [
  "awaiting_acknowledgment", "awaiting_approval", "approved", "rejected", "cancelled", "awaiting_supervisor_review", "dismissed",
  "in_supervisor_custody", "return_rejected", "awaiting_restore_receipt", "restored",
]);

/** Supervised cases word receipt and rework without implying that anyone admitted a fault. */
export function faultStatusLabel(fault: Pick<FaultException, "type" | "status">): string {
  if (fault.type === "tailoring_defect" && fault.status === "awaiting_acknowledgment") return t("fault.status.tailoringAwaitingAcknowledgment");
  if (fault.type === "tailoring_defect" && fault.status === "approved") return t("fault.status.tailoringApproved");
  return faultStatusLabels[fault.status];
}

/** Stages 3-12, where a worker can report a defect to the tailoring quality supervisor. */
export const TAILORING_STAGE_IDS = ["workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing", "quality-control"] as const;
export const SUPERVISED_SOURCES: readonly OrderSource[] = ["trendyol", "trendhome", "outletperdele"];

/** Whether this order follows the supervised flow; otherwise (B2B, or no supervisor appointed) the intake-only return applies. */
export function isSupervised(order: Pick<StaffOrder, "source">, capabilities: QualityCapabilities | null): boolean {
  return capabilities?.supervisedFlowActive === true && SUPERVISED_SOURCES.includes(order.source);
}

/** "Raportează o problemă": only the employee who owns the order at stages 3-12 and may complete its stage. */
export function canReportDefect(order: StaffOrder, permissions: readonly string[], capabilities: QualityCapabilities | null): boolean {
  return isSupervised(order, capabilities) && (TAILORING_STAGE_IDS as readonly string[]).includes(order.productionStageId)
    && order.employeeAllowedAction?.id === "complete_stage" && !order.productionQuality?.openException && permissions.includes("orders.report_fault");
}

const SUPERVISOR_VIEWS: readonly SupervisorView[] = ["review", "custody", "return", "done"];
/** The supervisor's four tabs; label and empty text are read in the active language. */
export const supervisorViews: readonly { readonly id: SupervisorView; readonly label: string; readonly empty: string }[] = SUPERVISOR_VIEWS.map((id) => Object.freeze({
  id,
  get label() { return t(`supervisor.view.${id}`); },
  get empty() { return t(`supervisor.view.${id}.empty`); },
}));

export const responsibilityLabels: Readonly<Record<"accepted" | "disputed" | "not_stated", string>> = liveLabels("fault.responsibility.", ["accepted", "disputed", "not_stated"]);


export function timelineLabel(action: string): string {
  return tKnown("fault.timeline", action, action);
}

/** "17.000" -> "17 m", "8.400" -> "8,4 m"; null (the source sent no meters) -> "fără metraj". Display only. */
export function formatDecimalMeters(value: string | null): string {
  if (value === null) return t("common.noMeters");
  const trimmed = value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
  return `${trimmed.replace(".", ",")} m`;
}

/**
 * Preview of the selected fault meters while choosing lines. Whole-line meters are summed as integer
 * thousandths, never quantity x meters; the server recomputes the authoritative value.
 */
export function selectedMeters(items: ProductionItem[], selected: ReadonlySet<string>): string {
  const thousandths = items.filter((item) => selected.has(item.id) && item.meters != null).reduce((sum, item) => sum + Math.round((item.meters ?? 0) * 1000), 0);
  return `${Math.floor(thousandths / 1000)}.${String(thousandths % 1000).padStart(3, "0")}`;
}

/** Scope of a request: known meters and, separately, the lines without meters. Never shows unknown meters as 0. */
export function formatFaultMeters(fault: Pick<FaultException, "faultMeters" | "unmeasuredLineCount">): string {
  const unknown = t("fault.unmeasured", { count: fault.unmeasuredLineCount });
  if (fault.faultMeters === null) return unknown;
  return fault.unmeasuredLineCount > 0 ? `${formatDecimalMeters(fault.faultMeters)} + ${unknown}` : formatDecimalMeters(fault.faultMeters);
}

export function arrivalLabel(arrival: number): string | null {
  if (arrival < 2) return null;
  return arrival === 2 ? t("fault.arrivalSecond") : t("fault.arrivalNth", { count: arrival });
}

export function needsMyAction(fault: FaultException): boolean {
  const actions = fault.actions;
  return actions.canAcknowledge || actions.canRequestRereview || actions.canReview || actions.canRequestReturn || actions.canAskManagersAgain || actions.canConfirmRestore;
}

const LIVE_TYPES = new Set([
  "cutting.transfer.approved", "cutting.transfer.rejected", "cutting.transfer.completed", "exception.acknowledgment_required",
  "exception.review_required", "exception.custody_taken", "exception.dismissed", "exception.restored", "exception.restore_receipt_required",
  "exception.approved", "exception.rejected",
]);

export function liveMessage(type: string, orderNumber?: string, revisionNumber?: number): string | null {
  if (LIVE_TYPES.has(type)) return orderNumber ? t(`live.${type}` as MessageKey, { number: orderNumber }) : null;
  return documentLiveMessage(type, orderNumber, revisionNumber);
}

/** Data invalidations refresh screens, but must not erase an actionable employee notice. */
export function nextLiveNotice(current: LiveEvent | null, event: LiveEvent): LiveEvent | null {
  return liveMessage(event.type, event.orderNumber, event.revisionNumber) === null ? current : event;
}

/**
 * What a scanned order's open production exception asks of the signed-in employee. The server decides every flag;
 * a generic scan never acts on the exception, it only guides the employee to the request's own screen, where the
 * receipt still needs the explicit confirmation and a fresh QR scan. Uninvolved employees only learn the order is blocked.
 */
export type ScannerExceptionPrompt = { kind: "receipt" | "restoreReceipt" | "supervisor" | "blocked"; exceptionId: string; number: string; pieces: number | null };
/**
 * The open request a scan concerns: the request of the scanned piece (piece label), otherwise the order's own request,
 * otherwise a request of a piece in rework that is addressed to this employee (API 2.31.0).
 */
export function scannerExceptionPrompt(order: Pick<StaffOrder, "productionQuality"> & Partial<Pick<StaffOrder, "pieces" | "scannedPiece">>): ScannerExceptionPrompt | null {
  const tracks = order.pieces?.tracks ?? [];
  const scannedTrack = order.scannedPiece?.trackId ? tracks.find((track) => track.id === order.scannedPiece?.trackId) : undefined;
  const addressed = tracks.find((track) => track.openException?.actions && (track.openException.actions.canAcknowledge || track.openException.actions.canConfirmRestore))?.openException;
  const trackOpen = scannedTrack?.openException ?? addressed ?? null;
  const open = order.scannedPiece ? (order.scannedPiece.trackId ? trackOpen : order.productionQuality?.openException ?? null) : order.productionQuality?.openException ?? trackOpen;
  if (!open) return null;
  const actions = open.actions;
  const kind = actions?.canAcknowledge ? "receipt"
    : actions?.canConfirmRestore ? "restoreReceipt"
      : actions && (actions.canReview || actions.canRequestReturn || actions.canRestore || actions.canAskManagersAgain) ? "supervisor"
        : "blocked";
  return { kind, exceptionId: open.id, number: open.number, pieces: open.orderScope?.piecesRecorded ? open.orderScope.affectedQuantity : null };
}
