import { useCallback, useEffect, useMemo, useRef, useState } from "react";
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
import { BootScreen } from "../components/ui/States";
import { ExceptionScreen } from "../features/exceptions/ExceptionScreen";
import { DocumentScreen } from "../features/documents/DocumentScreen";
import { AuthorityScreen } from "../features/authority/AuthorityScreen";
import { canManageAuthority } from "../domain/authority";
import { TrendyolInboxScreen } from "../features/trendyol/TrendyolInboxScreen";
import { TrendyolPackageScreen } from "../features/trendyol/TrendyolPackageScreen";
import { dashboardUrlFor } from "../domain/workspaces";
import { LoginIntroStage, introPlaysHere } from "../features/auth/LoginIntro";

type StaffAppProps = { initialRoute: string; mode: StaffRuntimeMode; apiBaseUrl: string };

/** Staff, with the sign-in intro: every successful sign-in from the login form plays it (a restored session does not). */
export function StaffApp(props: StaffAppProps) {
  const runs = useRef(0);
  const [introRun, setIntroRun] = useState(0);
  const playIntro = useCallback(() => { if (introPlaysHere()) setIntroRun(++runs.current); }, []);
  const finishIntro = useCallback(() => setIntroRun(0), []);
  return <LoginIntroStage run={introRun} onDone={finishIntro}><StaffScreens {...props} onSignedIn={playIntro} /></LoginIntroStage>;
}

function StaffScreens({ initialRoute, mode, apiBaseUrl, onSignedIn }: StaffAppProps & { onSignedIn: () => void }) {
  const services = useMemo(() => createServices({ mode, apiBaseUrl }), [apiBaseUrl, mode]);
  const dashboardUrl = useMemo(() => mode === "production" ? dashboardUrlFor(apiBaseUrl) : null, [apiBaseUrl, mode]);
  const [route, setRoute] = useState(initialRoute);
  const [session, setSession] = useState<Session | null>(null);
  const [checkingSession, setCheckingSession] = useState(true);
  const [sessionCheckError, setSessionCheckError] = useState<StaffServiceError | null>(null);
  const [sessionNotice, setSessionNotice] = useState("");
  const [bootstrapKey, setBootstrapKey] = useState(0);
  const [changingPassword, setChangingPassword] = useState(false);
  const [passwordChanged, setPasswordChanged] = useState(false);

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
    setChangingPassword(false);
    setPasswordChanged(false);
    navigate("/login");
  }

  if (checkingSession) return <BootScreen busy message="Se pregătește spațiul tău…" />;
  if (sessionCheckError) return <BootScreen role="alert" message={sessionCheckError.code === "CONFIGURATION_ERROR" ? "Serviciul de autentificare nu este configurat." : "Serviciul nu este disponibil momentan."} action={<button className="button button-secondary" type="button" onClick={() => { setCheckingSession(true); setSessionCheckError(null); setBootstrapKey((value) => value + 1); }}>Reîncearcă</button>} />;
  if (!session || guardedRoute === "/login") {
    return <LoginScreen mode={mode} notice={sessionNotice} onLogin={async (input) => { const next = await services.auth.login(input); workflowLifecycle.reset(); setSession(next); setSessionNotice(""); navigate("/"); onSignedIn(); return next; }} />;
  }
  if (session.employee.mustChangePassword) {
    return <ChangePasswordScreen displayName={session.employee.displayName} username={session.employee.username} onLogout={logout} onChange={async (input) => { const next = await services.auth.changePassword(input); setSession(next); navigate("/"); }} />;
  }
  if (changingPassword) {
    return <ChangePasswordScreen voluntary displayName={session.employee.displayName} username={session.employee.username} onLogout={logout} onCancel={() => setChangingPassword(false)} onChange={async (input) => { const next = await services.auth.changePassword(input); setSession(next); setChangingPassword(false); setPasswordChanged(true); }} />;
  }
  if (!session.employee.applications.includes("staff")) return <NoStaffAccessScreen displayName={session.employee.displayName} onLogout={logout} />;
  const workflow = workflowLifecycle.workflow;
  if (workflowLifecycle.initialError) return <BootScreen role="alert" message="Fluxul de producție nu este disponibil momentan." action={<button className="button button-secondary" type="button" onClick={workflowLifecycle.retry}>Reîncearcă</button>} />;
  if (!workflow) return <BootScreen busy message="Se încarcă fluxul de producție…" />;

  const orderId = parsedRoute.kind === "order-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.orderId : "";
  const immersive = guardedRoute === "/scan";
  const exceptionId = parsedRoute.kind === "exception-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.exceptionId : "";
  const documentOrderId = parsedRoute.kind === "document-detail" && guardedRoute === parsedRoute.pathname ? parsedRoute.orderId : "";
  const trendyolPackageId = parsedRoute.kind === "trendyol-package" && guardedRoute === parsedRoute.pathname ? parsedRoute.packageId : "";
  let screen = <HomeScreen employee={session.employee} services={services} workflow={workflow} dashboardUrl={dashboardUrl} navigate={navigate} />;
  if (guardedRoute === "/scan") screen = <ScannerScreen service={services.orders} cutting={services.cutting} workflow={workflow} mode={mode} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (guardedRoute === "/orders") screen = <OrdersScreen service={services.orders} workflow={workflow} navigate={navigate} />;
  else if (orderId) screen = <OrderDetailScreen key={orderId} orderId={orderId} service={services.orders} exceptions={services.exceptions} cutting={services.cutting} documents={services.documents} productionQr={canManageAuthority(session.employee) ? services.productionQr : undefined} permissions={session.employee.permissions} workflow={workflow} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (documentOrderId && services.documents) screen = <DocumentScreen key={documentOrderId} orderId={documentOrderId} service={services.documents} permissions={session.employee.permissions} navigate={navigate} />;
  else if (exceptionId) screen = <ExceptionScreen key={exceptionId} exceptionId={exceptionId} service={services.exceptions} navigate={navigate} onSessionExpired={() => expireSession(new StaffServiceError("SESSION_EXPIRED"))} />;
  else if (guardedRoute === "/authority" && services.authority) screen = <AuthorityScreen service={services.authority} initialOrderId={new URLSearchParams(window.location.search).get("order") ?? undefined} navigate={navigate} />;
  else if (guardedRoute === "/trendyol" && services.trendyol) screen = <TrendyolInboxScreen service={services.trendyol} navigate={navigate} />;
  else if (trendyolPackageId && services.trendyol) screen = <TrendyolPackageScreen key={trendyolPackageId} packageId={trendyolPackageId} service={services.trendyol} navigate={navigate} />;
  else if (guardedRoute === "/history") screen = <HistoryScreen service={services.activity} navigate={navigate} />;
  else if (guardedRoute === "/profile") screen = <ProfileScreen employee={session.employee} mode={services.mode} onLogout={logout} passwordChanged={passwordChanged} onChangePassword={() => { setPasswordChanged(false); setChangingPassword(true); }} />;

  return <LiveProvider service={services.live} enabled={staffReady}>
    <AppShell employee={session.employee} route={guardedRoute} mode={mode} navigate={navigate} immersive={immersive}>{screen}</AppShell>
    <LiveNotice navigate={navigate} />
  </LiveProvider>;
}
