import { useEffect, useMemo, useState } from "react";
import type { Session } from "../services/contracts";
import { createServices } from "../services/createServices";
import { AppShell } from "../components/AppShell";
import { LoginScreen } from "../features/auth/LoginScreen";
import { routeForSession } from "../features/auth/routeProtection";
import { HomeScreen } from "../features/home/HomeScreen";
import { ScannerScreen } from "../features/scanner/ScannerScreen";
import { OrdersScreen } from "../features/orders/OrdersScreen";
import { OrderDetailScreen } from "../features/orders/OrderDetailScreen";
import { HistoryScreen } from "../features/history/HistoryScreen";
import { ProfileScreen } from "../features/profile/ProfileScreen";

export function StaffApp({ initialRoute, demoMode, apiBaseUrl }: { initialRoute: string; demoMode: boolean; apiBaseUrl: string }) {
  const services = useMemo(() => createServices({ demoMode, apiBaseUrl }), [apiBaseUrl, demoMode]);
  const [route, setRoute] = useState(initialRoute);
  const [session, setSession] = useState<Session | null>(null);
  const [checkingSession, setCheckingSession] = useState(true);

  useEffect(() => {
    services.auth.getSession().then(setSession).catch(() => setSession(null)).finally(() => setCheckingSession(false));
  }, [services]);

  useEffect(() => {
    const onPopState = () => setRoute(window.location.pathname);
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, []);

  function navigate(path: string) {
    if (window.location.pathname !== path) window.history.pushState({}, "", path);
    setRoute(path);
    window.scrollTo({ top: 0, behavior: "instant" });
  }

  async function logout() {
    await services.auth.logout();
    setSession(null);
    navigate("/login");
  }

  const guardedRoute = routeForSession(route, Boolean(session));

  if (checkingSession) return <main className="session-check" aria-live="polite"><span className="brand-mark">A</span><p>Se pregătește spațiul tău…</p></main>;
  if (!session || guardedRoute === "/login") {
    return <LoginScreen demoMode={demoMode} onLogin={async (input) => { const next = await services.auth.login(input); setSession(next); navigate("/"); return next; }} />;
  }

  const orderId = guardedRoute.startsWith("/orders/") ? decodeURIComponent(guardedRoute.slice("/orders/".length)) : "";
  const immersive = guardedRoute === "/scan";
  let screen = <HomeScreen employee={session.employee} activityService={services.activity} navigate={navigate} />;
  if (guardedRoute === "/scan") screen = <ScannerScreen service={services.orders} demoMode={demoMode} navigate={navigate} onSessionExpired={logout} />;
  else if (guardedRoute === "/orders") screen = <OrdersScreen service={services.orders} navigate={navigate} />;
  else if (orderId) screen = <OrderDetailScreen orderId={orderId} service={services.orders} navigate={navigate} />;
  else if (guardedRoute === "/history") screen = <HistoryScreen service={services.activity} navigate={navigate} />;
  else if (guardedRoute === "/profile") screen = <ProfileScreen employee={session.employee} mode={services.mode} onLogout={logout} />;

  return <AppShell employee={session.employee} route={guardedRoute} navigate={navigate} immersive={immersive}>{screen}</AppShell>;
}
