import type { Employee } from "./models";

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
  const permission = permissionForRoute(path);
  return permission === null || hasPermission(employee, permission);
}
