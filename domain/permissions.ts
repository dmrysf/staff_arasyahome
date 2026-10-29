import type { Employee } from "./models";
import { canHandOff, canOpenConfirmareOrder, canSuperviseConfirmare, canUseAdvanceQueue, canUseConfirmare } from "./confirmare";
import { canUseShopTasks, canWatchHealth } from "./confirmareOps";
import { canUseFinance } from "./confirmareFinance";
import { canOverseeFinance, canUseFinanceInbox } from "./financeRequests";

export type StaffPermission =
  | "orders.scan"
  | "orders.view_mine"
  | "orders.claim"
  | "orders.advance_stage"
  | "orders.handover"
  | "history.view_mine"
  | "profile.view_self";

export function hasPermission(employee: Employee, permission: StaffPermission) {
  return employee.permissions.includes(permission);
}

export function permissionForRoute(path: string): StaffPermission | null {
  if (path === "/scan") return "orders.scan";
  if (path === "/orders" || path.startsWith("/orders/")) return "orders.view_mine";
  if (path === "/history") return "history.view_mine";
  if (path === "/profile") return "profile.view_self";
  return null;
}

export function canAccessRoute(employee: Employee, path: string) {
  // Production authority takeover: managers holding the permission together with Dashboard access.
  if (path === "/authority") return employee.permissions.includes("production.manage_authority") && employee.applications.includes("dashboard");
  // Trendyol workspace: only explicitly authorized Trendyol personnel (Staff access plus the Trendyol view permission).
  if (path === "/trendyol" || path.startsWith("/trendyol/")) return employee.permissions.includes("trendyol.orders.view") && employee.applications.includes("staff");
  // Confirmare (API 2.39.0): only identities holding a Confirmare scope; the team view only for supervisors.
  if (path === "/confirmare/team") return canSuperviseConfirmare(employee);
  // Confirmare finance (API 2.41.0): the finance tasks for finance scopes; an order also opens for them (read-only).
  if (path === "/confirmare/finance") return canUseAdvanceQueue(employee);
  // Staff 2.41.0: the payment checks for record/approve/verify holders, the oversight for finance supervisors, an
  // exceptional finance task of one order for record/approve holders (the server decides which orders they reach).
  if (path === "/confirmare/finance/requests" || path.startsWith("/confirmare/finance/requests/")) return canUseFinanceInbox(employee);
  if (path === "/confirmare/finance/oversight" || path.startsWith("/confirmare/finance/oversight/")) return canOverseeFinance(employee);
  if (path.startsWith("/confirmare/finance/orders/")) return canUseFinanceInbox(employee) || canUseFinance(employee);
  if (path.startsWith("/confirmare/finance/legacy/")) return canUseConfirmare(employee);
  // Confirmare production handoff (API 2.42.0): a Confirmare scope and the document generate permission.
  if (path === "/confirmare/handoff") return canHandOff(employee);
  // API 2.46.0: the AWB queue and the e-Factura follow-up of the shop employees; the operating health of the watchers.
  if (path === "/confirmare/awb" || path === "/confirmare/efactura") return canUseShopTasks(employee);
  if (path === "/confirmare/health") return canWatchHealth(employee);
  if (path.startsWith("/confirmare/orders/")) return canOpenConfirmareOrder(employee);
  if (path === "/confirmare" || path.startsWith("/confirmare/")) return canUseConfirmare(employee);
  // Production documents: channel employees with a document permission, without needing a stage.
  if (path.startsWith("/documents/")) return ["production.documents.generate", "production.documents.reprint", "production.documents.request_revision"].some((key) => employee.permissions.includes(key));
  const permission = permissionForRoute(path);
  return permission === null || hasPermission(employee, permission);
}
