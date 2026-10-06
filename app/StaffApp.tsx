import { useCallback, useEffect, useMemo, useState } from "react";
import type { Session } from "../services/contracts";
import { createServices } from "../services/createServices";
import { AppShell } from "../components/AppShell";
import { LoginScreen } from "../features/auth/LoginScreen";
import { ChangePasswordScreen } from "../features/auth/ChangePasswordScreen";
import { NoStaffAccessScreen } from "../features/auth/NoStaffAccessScreen";
import { routeForSession } from "../features/auth/routeProtection";
import { HomeScreen } from "../features/home/HomeScreen";
import { ScannerScreen } from "../features/scanner/ScannerScreen";
import { OrdersScreen } from "../features/orders/OrdersScreen";
import { OrderDetailScreen } from "../features/orders/OrderDetailScreen";
import { HistoryScreen } from "../features/history/HistoryScreen";
import { ProfileScreen } from "../features/profile/ProfileScreen";
import type { StaffRuntimeMode } from "../src/runtimeConfig";
import { StaffServiceError } from "../domain/models";
import { toServiceError } from "../services/errors";
import { canAccessRoute } from "../domain/permissions";
import { shouldEndLocalSessionAfterLogout } from "../features/auth/logoutPolicy";
import { useProductionWorkflow } from "./useProductionWorkflow";
import { parseStaffRoute } from "../domain/staffRoute";
import { LiveProvider } from "./liveContext";
import { LiveNotice } from "../components/LiveNotice";
import { ExceptionScreen } from "../features/exceptions/ExceptionScreen";
import { DocumentScreen } from "../features/documents/DocumentScreen";

export function StaffApp({ initialRoute, mode, apiBaseUrl }: { initialRoute: string; mode: StaffRuntimeMode; apiBaseUrl: string }) {
  const services = useMemo(() => createServices({ mode, apiBaseUrl }), [apiBaseUrl, mode]);
  const [route, setRoute] = useState(initialRoute);
  const [session, setSession] = useState<Session | null>(null);
  const [checkingSession, setCheckingSession] = useState(true);
  const [sessionCheckError, setSessionCheckError] = useState<StaffServiceError | null>(null);
  const [sessionNotice, setSessionNotice] = useState("");
  const [bootstrapKey, setBootstrapKey] = useState(0);

  const navigate = useCallback((path: string) => {
    if (window.location.pathname !== path) window.history.pushState({}, "", path);
    setRoute(path);
    window.scrollTo({ top: 0, behavior: "instant" });
  }, []);

  const expireSession = useCallback((error?: StaffServiceError) => {
    // Central IAM changed this identity's access: re-read the session instead of logging out.
    if (error?.code === "PASSWORD_CHANGE_REQUIRED" || error?.code === "APPLICATION_ACCESS_DENIED") {
      setCheckingSession(true);
      setBootstrapKey((value) => value + 1);
      return;
    }
    setSession(null);
    setSessionNotice(error?.code === "ACCOUNT_INACTIVE" ? "Contul nu este activ. Contactează managerul." : "Sesiunea a expirat. Autentifică-te din nou.");
    navigate("/login");
  }, [navigate]);

  useEffect(() => {
    let active = true;
    services.auth.getSession()
      .then((next) => { if (active) setSession(next); })
      .catch((caught) => {
        if (!active) return;
        const error = toServiceError(caught);
        if (error.code === "ACCOUNT_INACTIVE" || error.code === "SESSION_EXPIRED") expireSession(error);
        else setSessionCheckError(error);
      })
      .finally(() => { if (active) setCheckingSession(false); });
    return () => { active = false; };
  }, [bootstrapKey, expireSession, services]);

  useEffect(() => services.auth.onSessionExpired(expireSession), [expireSession, services]);

  useEffect(() => {
    const onPopState = () => setRoute(window.location.pathname);
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, []);

  const parsedRoute = parseStaffRoute(route);
  const sessionRoute = routeForSession(parsedRoute.pathname, Boolean(session));
  const guardedRoute = session && !canAccessRoute(session.employee, sessionRoute) ? "/" : sessionRoute;
  const staffReady = Boolean(session && !session.employee.mustChangePassword && session.employee.applications.includes("staff"));
  const workflowLifecycle = useProductionWorkflow({ authenticated: staffReady, route: guardedRoute, service: services.workflow });

  async function logout() {
    try { await services.auth.logout(); }
    catch (caught) {
      const error = toServiceError(caught);
      if (!shouldEndLocalSessionAfterLogout(error)) throw error;
    }
    workflowLifecycle.reset();
    setSession(null);
    setSessionNotice("");
    navigate("/login");
  }

  if (checkingSession) return <main className="session-check" aria-live="polite"><span className="brand-mark">A</span><p>Se pregătește spațiul tău…</p></main>;
  if (sessionCheckError) return <main className="session-check" role="alert"><span className="brand-mark">A</span><p>{sessionCheckError.code === "CONFIGURATION_ERROR" ? "Serviciul de autentificare nu este configurat." : "Serviciul nu este disponibil momentan."}</p><button className="button button-secondary" type="button" onClick={() => { setCheckingSession(true); setSessionCheckError(null); setBootstrapKey((value) => value + 1); }}>Reîncearcă</button></main>;
  if (!session || guardedRoute === "/login") {
    return <LoginScreen mode={mode} notice={sessionNotice} onLogin={async (input) => { const next = await services.auth.login(input); workflowLifecycle.reset(); setSession(next); setSessionNotice(""); navigate("/"); return next; }} />;
  }
  if (session.employee.mustChangePassword) {
    return <ChangePasswordScreen displayName={session.employee.displayName} onLogout={logout} onChange={async (input) => { const next = await services.auth.changePassword(input); setSession(next); navigate("/"); }} />;
  }
  if (!session.employee.applications.includes("staff")) return <NoStaffAccessScreen displayName={session.employee.displayName} onLogout={logout} />;
  const workflow = workflowLifecycle.workflow;
  if (workflowLifecycle.initialError) return <main className="session-check" role="alert"><span className="brand-mark">A</span><p>Fluxul de producție nu este disponibil momentan.</p><button className="button button-secondary" type="button" onClick={workflowLifecycle.retry}>Reîncearcă</button></main>;
  if (!workflow) return <main className="session-check" aria-live="polite"><span className="brand-mark">A</span><p>Se încarcă fluxul de producție…</p></main>;

  const orderId = parsedRoute.kind === "order-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.orderId : "";
  const immersive = guardedRoute === "/scan";
  const exceptionId = parsedRoute.kind === "exception-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.exceptionId : "";
  const documentOrderId = parsedRoute.kind === "document-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.orderId : "";
  let screen = <HomeScreen employee={session.employee} activityService={services.activity} exceptionService={services.exceptions} cutting={services.cutting} documents={services.documents} navigate={navigate} />;
  if (guardedRoute === "/scan") screen = <ScannerScreen service={services.orders} cutting={services.cutting} workflow={workflow} mode={mode} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (guardedRoute === "/orders") screen = <OrdersScreen service={services.orders} workflow={workflow} navigate={navigate} />;
  else if (orderId) screen = <OrderDetailScreen key={orderId} orderId={orderId} service={services.orders} exceptions={services.exceptions} cutting={services.cutting} documents={services.documents} permissions={session.employee.permissions} workflow={workflow} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (documentOrderId && services.documents) screen = <DocumentScreen key={documentOrderId} orderId={documentOrderId} service={services.documents} permissions={session.employee.permissions} navigate={navigate} />;
  else if (exceptionId) screen = <ExceptionScreen key={exceptionId} exceptionId={exceptionId} service={services.exceptions} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (guardedRoute === "/history") screen = <HistoryScreen service={services.activity} navigate={navigate} />;
  else if (guardedRoute === "/profile") screen = <ProfileScreen employee={session.employee} mode={services.mode} onLogout={logout} />;

  return <LiveProvider service={services.live} enabled={staffReady}>
    <AppShell employee={session.employee} route={guardedRoute} mode={mode} navigate={navigate} immersive={immersive}>{screen}</AppShell>
    <LiveNotice navigate={navigate} />
  </LiveProvider>;
}
