import { AppIcon } from "../icons/AppIcon";
import { PrimaryScanButton } from "./PrimaryScanButton";

export const bottomNavigationItems = [
  { path: "/", label: "Acasă", icon: "home" as const },
  { path: "/orders", label: "Comenzi", icon: "orders" as const },
  { path: "/history", label: "Istoric", icon: "history" as const },
  { path: "/profile", label: "Profil", icon: "profile" as const },
];

export function BottomNavigation({ route, navigate }: { route: string; navigate: (path: string) => void }) {
  const [home, orders, history, profile] = bottomNavigationItems;
  const renderItem = (item: (typeof bottomNavigationItems)[number]) => (
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
      <PrimaryScanButton active={route === "/scan"} onSelect={() => navigate("/scan")} />
    </nav>
  );
}
