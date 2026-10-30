import { AppIcon } from "../icons/AppIcon";
import { t, useI18n } from "../../i18n";
import { PrimaryScanButton } from "./PrimaryScanButton";
import { ArasyaLogo } from "../brand/BrandLogo";
import type { Employee } from "../../domain/models";
import { hasPermission, type StaffPermission } from "../../domain/permissions";
import { isDeskRoute, usesProductionScanner } from "../../domain/operatorDesk";

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
 *
 * Staff 2.42.1: when the home is the operator desk (`deskHome`), "Acasă" stays lit on every desk screen, and an
 * employee without a production stage has no production order list ("Comenzi"): their orders are the desk's queue.
 */
export function BottomNavigation({ employee, route, navigate, deskHome = false }: { employee: Employee; route: string; navigate: (path: string) => void; deskHome?: boolean }) {
  useI18n();
  const [home, orders, history, profile] = bottomNavigationItems;
  const hidden = (item: (typeof bottomNavigationItems)[number]) => (item.permission !== null && !hasPermission(employee, item.permission)) || (deskHome && item === orders && employee.allowedStageIds.length === 0);
  const current = (item: (typeof bottomNavigationItems)[number]) => route === item.path || (deskHome && item === home && isDeskRoute(route));
  const renderItem = (item: (typeof bottomNavigationItems)[number]) => hidden(item)
    ? <span key={item.path} className="nav-permission-space" aria-hidden="true" />
    : (
    <button key={item.path} className={`nav-item nav-${item.icon}${current(item) ? " active" : ""}`} type="button" onClick={() => navigate(item.path)} aria-current={current(item) ? "page" : undefined}>
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
      {usesProductionScanner(employee) && <PrimaryScanButton active={route === "/scan"} onSelect={() => navigate("/scan")} />}
    </nav>
  );
}
