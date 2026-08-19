import { useCallback, useEffect, useMemo, useState } from "react";
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
import type { StaffRuntimeMode } from "../src/runtimeConfig";
import { StaffServiceError, type ProductionWorkflow } from "../domain/models";
import { toServiceError } from "../services/errors";
import { canAccessRoute } from "../domain/permissions";
import { shouldEndLocalSessionAfterLogout } from "../features/auth/logoutPolicy";

export function StaffApp({ initialRoute, mode, apiBaseUrl }: { initialRoute: string; mode: StaffRuntimeMode; apiBaseUrl: string }) {
  const services = useMemo(() => createServices({ mode, apiBaseUrl }), [apiBaseUrl, mode]);
  const [route, setRoute] = useState(initialRoute);
  const [session, setSession] = useState<Session | null>(null);
  const [checkingSession, setCheckingSession] = useState(true);
  const [sessionCheckError, setSessionCheckError] = useState<StaffServiceError | null>(null);
  const [sessionNotice, setSessionNotice] = useState("");
  const [bootstrapKey, setBootstrapKey] = useState(0);
  const [workflow, setWorkflow] = useState<ProductionWorkflow | null>(null);
  const [workflowError, setWorkflowError] = useState<StaffServiceError | null>(null);
  const [workflowKey, setWorkflowKey] = useState(0);

  const navigate = useCallback((path: string) => {
    if (window.location.pathname !== path) window.history.pushState({}, "", path);
    setRoute(path);
    window.scrollTo({ top: 0, behavior: "instant" });
  }, []);

  const expireSession = useCallback((error?: StaffServiceError) => {
    setSession(null);
    setWorkflow(null);
    setWorkflowError(null);
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
    if (!session) return;
    const controller = new AbortController();
    services.workflow.getCurrent({ signal: controller.signal })
      .then(setWorkflow)
      .catch((caught) => {
        if (controller.signal.aborted) return;
        const error = toServiceError(caught);
        if (error.code === "ACCOUNT_INACTIVE" || error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION") expireSession(error);
        else setWorkflowError(error.code === "WORKFLOW_UNAVAILABLE" ? error : new StaffServiceError("WORKFLOW_UNAVAILABLE"));
      });
    return () => controller.abort();
  }, [expireSession, services, session, workflowKey]);

  useEffect(() => {
    const onPopState = () => setRoute(window.location.pathname);
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, []);

  async function logout() {
    try { await services.auth.logout(); }
    catch (caught) {
      const error = toServiceError(caught);
      if (!shouldEndLocalSessionAfterLogout(error)) throw error;
    }
    setSession(null);
    setWorkflow(null);
    setWorkflowError(null);
    setSessionNotice("");
    navigate("/login");
  }

  const sessionRoute = routeForSession(route, Boolean(session));
  const guardedRoute = session && !canAccessRoute(session.employee, sessionRoute) ? "/" : sessionRoute;

  if (checkingSession) return <main className="session-check" aria-live="polite"><span className="brand-mark">A</span><p>Se pregătește spațiul tău…</p></main>;
  if (sessionCheckError) return <main className="session-check" role="alert"><span className="brand-mark">A</span><p>{sessionCheckError.code === "CONFIGURATION_ERROR" ? "Serviciul de autentificare nu este configurat." : "Serviciul nu este disponibil momentan."}</p><button className="button button-secondary" type="button" onClick={() => { setCheckingSession(true); setSessionCheckError(null); setBootstrapKey((value) => value + 1); }}>Reîncearcă</button></main>;
  if (!session || guardedRoute === "/login") {
    return <LoginScreen mode={mode} notice={sessionNotice} onLogin={async (input) => { const next = await services.auth.login(input); setWorkflow(null); setWorkflowError(null); setSession(next); setSessionNotice(""); navigate("/"); return next; }} />;
  }
  if (workflowError) return <main className="session-check" role="alert"><span className="brand-mark">A</span><p>Fluxul de producție nu este disponibil momentan.</p><button className="button button-secondary" type="button" onClick={() => { setWorkflowError(null); setWorkflowKey((value) => value + 1); }}>Reîncearcă</button></main>;
  if (!workflow) return <main className="session-check" aria-live="polite"><span className="brand-mark">A</span><p>Se încarcă fluxul de producție…</p></main>;

  const orderId = guardedRoute.startsWith("/orders/") ? decodeURIComponent(guardedRoute.slice("/orders/".length)) : "";
  const immersive = guardedRoute === "/scan";
  let screen = <HomeScreen employee={session.employee} activityService={services.activity} navigate={navigate} />;
  if (guardedRoute === "/scan") screen = <ScannerScreen service={services.orders} workflow={workflow} mode={mode} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (guardedRoute === "/orders") screen = <OrdersScreen service={services.orders} workflow={workflow} navigate={navigate} />;
  else if (orderId) screen = <OrderDetailScreen orderId={orderId} service={services.orders} workflow={workflow} navigate={navigate} />;
  else if (guardedRoute === "/history") screen = <HistoryScreen service={services.activity} navigate={navigate} />;
  else if (guardedRoute === "/profile") screen = <ProfileScreen employee={session.employee} mode={services.mode} onLogout={logout} />;

  return <AppShell employee={session.employee} route={guardedRoute} mode={mode} navigate={navigate} immersive={immersive}>{screen}</AppShell>;
}
