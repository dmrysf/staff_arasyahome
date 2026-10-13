import type { EmployeeOrderRelationType, StaffOrder } from "./models";
import { liveLabels, t } from "../i18n";

export type MyOrdersView = "in_progress" | "recent" | "handed_over";

const relationLabels: Readonly<Record<EmployeeOrderRelationType, string>> = liveLabels("relation.", ["claimed", "assigned", "updated", "handover_in", "handover_out", "completed"]);

const activeRelations = new Set<EmployeeOrderRelationType>(["claimed", "assigned", "handover_in"]);
const handedOverRelations = new Set<EmployeeOrderRelationType>(["handover_out", "completed"]);

export function isEmployeeRelevantOrder(order: StaffOrder, employeeUuid: string): boolean {
  return order.employeeRelation?.employeeUuid === employeeUuid;
}

export function getEmployeeRelationLabel(order: StaffOrder): string {
  return order.employeeRelation ? relationLabels[order.employeeRelation.type] : t("relation.recent");
}

export function matchesMyOrdersView(order: StaffOrder, view: MyOrdersView): boolean {
  if (view === "recent") return true;
  if (view === "in_progress") {
    return order.status === "in_progress" && Boolean(order.employeeRelation && activeRelations.has(order.employeeRelation.type));
  }
  return order.status === "handed_over" || Boolean(order.employeeRelation && handedOverRelations.has(order.employeeRelation.type));
}

export function getEmployeeRelationTime(order: StaffOrder): string {
  return order.employeeRelation?.lastActionAt ?? order.updatedAt;
}
