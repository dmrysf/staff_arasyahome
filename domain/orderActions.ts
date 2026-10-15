import type { OrderActionBlockedReason, OrderActionId } from "./models";
import { liveLabels, liveRecords } from "../i18n";

const ACTIONS: readonly OrderActionId[] = ["claim", "complete_stage", "complete_production"];
const BLOCKED_REASONS: readonly OrderActionBlockedReason[] = [
  "claimed_by_other", "stage_not_allowed", "production_completed", "order_unavailable", "permission_missing", "workflow_unavailable",
  "exception_pending", "document_revision_pending", "production_authority_source", "payment_gate_closed", "pieces_in_rework",
  "pieces_held", "pieces_awaiting_reunion", "plan_confirmation_required", "pieces_incomplete", "route_incomplete", "defect_unresolved",
];

// Words in the active language (i18n catalogs); the action ids and blocked reasons are the server's codes.
export const orderActionLabels: Readonly<Record<OrderActionId, string>> = liveLabels("orderAction.", ACTIONS);
export const orderActionConfirmation = liveRecords("orderAction.confirm.", ACTIONS, ["title", "note"]);
export const orderActionSuccess = liveRecords("orderAction.success.", ACTIONS, ["eyebrow", "title"]);
export const orderActionBlockedCopy: Readonly<Record<OrderActionBlockedReason, string>> = liveLabels("orderAction.blocked.", BLOCKED_REASONS);

export function isOrderActionId(value: unknown): value is OrderActionId {
  return value === "claim" || value === "complete_stage" || value === "complete_production";
}

export function isOrderActionBlockedReason(value: unknown): value is OrderActionBlockedReason {
  return typeof value === "string" && Object.hasOwn(orderActionBlockedCopy, value);
}
