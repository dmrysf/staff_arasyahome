import { AppIcon } from "../icons/AppIcon";
import { t, useI18n } from "../../i18n";
import { PrimaryScanButton } from "./PrimaryScanButton";
import { ArasyaLogo } from "../brand/BrandLogo";
import type { Employee } from "../../domain/models";
import { hasPermission, type StaffPermission } from "../../domain/permissions";

// Labels are read in the active language when the bar renders.
export const bottomNavigationItems = [
  { path: "/", get label() { return t("nav.home"); }, icon: "home" as const, permission: null },
  { path: "/orders", get label() { return t("nav.orders"); }, icon: "orders" as const, permission: "orders.view_mine" as StaffPermission },
  { path: "/history", get label() { return t("nav.history"); }, icon: "history" as const, permission: "history.view_mine" as StaffPermission },
  { path: "/profile", get label() { return t("nav.profile"); }, icon: "profile" as const, permission: "profile.view_self" as StaffPermission },
];

/**
 * Main navigation: a docked bar on phones (QR action in the thumb zone, centered) and a sidebar on desktop.
 * Items the employee is not authorized for are not rendered; on phones an empty slot keeps the QR action centered.
 */
export function BottomNavigation({ employee, route, navigate }: { employee: Employee; route: string; navigate: (path: string) => void }) {
  useI18n();
  const [home, orders, history, profile] = bottomNavigationItems;
  const renderItem = (item: (typeof bottomNavigationItems)[number]) => item.permission && !hasPermission(employee, item.permission)
    ? <span key={item.path} className="nav-permission-space" aria-hidden="true" />
    : (
    <button key={item.path} className={`nav-item nav-${item.icon}${route === item.path ? " active" : ""}`} type="button" onClick={() => navigate(item.path)} aria-current={route === item.path ? "page" : undefined}>
      <AppIcon name={item.icon} />
      <small>{item.label}</small>
    </button>
    );

  return (
    <nav className="app-nav" aria-label={t("nav.label")}>
      <span className="app-nav-brand" aria-hidden="true"><ArasyaLogo className="sidebar-logo" alt="" /><small>{t("nav.brand")}</small></span>
      {renderItem(home)}
      {renderItem(orders)}
      <span className="scan-nav-space" aria-hidden="true" />
      {renderItem(history)}
      {renderItem(profile)}
      {hasPermission(employee, "orders.scan") && <PrimaryScanButton active={route === "/scan"} onSelect={() => navigate("/scan")} />}
    </nav>
  );
}
