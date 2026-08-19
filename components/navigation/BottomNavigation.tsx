import { AppIcon } from "../icons/AppIcon";
import { PrimaryScanButton } from "./PrimaryScanButton";
import type { Employee } from "../../domain/models";
import { hasPermission, type StaffPermission } from "../../domain/permissions";

export const bottomNavigationItems = [
  { path: "/", label: "Acasă", icon: "home" as const, permission: null },
  { path: "/orders", label: "Comenzi", icon: "orders" as const, permission: "orders.view_mine" as StaffPermission },
  { path: "/history", label: "Istoric", icon: "history" as const, permission: "history.view_mine" as StaffPermission },
  { path: "/profile", label: "Profil", icon: "profile" as const, permission: "profile.view_self" as StaffPermission },
];

export function BottomNavigation({ employee, route, navigate }: { employee: Employee; route: string; navigate: (path: string) => void }) {
  const [home, orders, history, profile] = bottomNavigationItems;
  const renderItem = (item: (typeof bottomNavigationItems)[number]) => item.permission && !hasPermission(employee, item.permission)
    ? <span key={item.path} className="nav-permission-space" aria-hidden="true" />
    : (
    <button key={item.path} className={route === item.path ? "active" : ""} type="button" onClick={() => navigate(item.path)} aria-current={route === item.path ? "page" : undefined}>
      <AppIcon name={item.icon} />
      <small>{item.label}</small>
    </button>
    );

  return (
    <nav className="app-nav" aria-label="Navigare principală">
      {renderItem(home)}
      {renderItem(orders)}
      <span className="scan-nav-space" aria-hidden="true" />
      {renderItem(history)}
      {renderItem(profile)}
      {hasPermission(employee, "orders.scan") && <PrimaryScanButton active={route === "/scan"} onSelect={() => navigate("/scan")} />}
    </nav>
  );
}
