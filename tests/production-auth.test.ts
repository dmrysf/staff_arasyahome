import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { StaffServiceError } from "../domain/models";
import { canAccessRoute, hasPermission } from "../domain/permissions";
import { shouldEndLocalSessionAfterLogout } from "../features/auth/logoutPolicy";
import { createProductionServices, mapProductionEmployee, mapProductionSession } from "../services/production/httpServices";

const employeePayload = {
  employeeUuid: "68ff2a20-a164-4ed8-8659-1872a37d2ced",
  employeeCode: "EMP-0042",
  displayName: "Mehmet Yılmaz",
  username: "Mehmet.Yilmaz",
  department: "Pregătire Material",
  departmentKey: "pregatire-material",
  role: "employee",
  status: "active",
  permissions: ["orders.scan", "orders.view_mine", "history.view_mine", "profile.view_self"],
  allowedStageIds: ["material-preparation"],
};

const sessionPayload = { employee: employeePayload, expiresAt: "2026-08-19T18:00:00Z", csrfToken: "csrf-runtime-token" };

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function queuedFetch(responses: Response[]) {
  const calls: Array<{ input: RequestInfo | URL; init?: RequestInit }> = [];
  const fetchImpl: typeof fetch = async (input, init) => {
    calls.push({ input, init });
    const response = responses.shift();
    if (!response) throw new Error("Unexpected fetch call");
    return response;
  };
  return { fetchImpl, calls };
}

test("production employee and session mapping is explicit and allowlisted", () => {
  const employee = mapProductionEmployee({ ...employeePayload, password_hash: "must-not-map", rawToken: "must-not-map" });
  const session = mapProductionSession(sessionPayload);
  assert.equal(employee.displayName, "Mehmet Yılmaz");
  assert.deepEqual(employee.allowedStageIds, ["material-preparation"]);
  assert.equal("password_hash" in employee, false);
  assert.equal("rawToken" in session, false);
  assert.equal(session.csrfToken, "csrf-runtime-token");
});

test("production login uses credentialed JSON and keeps CSRF only in adapter memory", async () => {
  const { fetchImpl, calls } = queuedFetch([jsonResponse(sessionPayload), jsonResponse({ ok: true })]);
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, requestId: () => "request-id" });
  const session = await services.auth.login({ username: "Mehmet.Yilmaz", password: "test-only-password" });
  assert.equal(session.employee.employeeUuid, employeePayload.employeeUuid);
  assert.equal("csrfToken" in session, false);
  assert.equal(calls[0]?.init?.credentials, "include");
  assert.equal(calls[0]?.init?.method, "POST");
  assert.equal(new Headers(calls[0]?.init?.headers).get("X-CSRF-Token"), null);
  assert.equal(new Headers(calls[0]?.init?.headers).get("X-Request-ID"), "request-id");

  await services.auth.logout();
  assert.equal(new Headers(calls[1]?.init?.headers).get("X-CSRF-Token"), "csrf-runtime-token");
  assert.equal(calls[1]?.init?.credentials, "include");
});

test("production session bootstrap restores identity and unauthenticated bootstrap returns null", async () => {
  const valid = queuedFetch([jsonResponse(sessionPayload)]);
  const validServices = createProductionServices("https://api.arasyahome.ro", { fetchImpl: valid.fetchImpl, isOnline: () => true });
  assert.equal((await validServices.auth.getSession())?.employee.username, "Mehmet.Yilmaz");

  const anonymous = queuedFetch([jsonResponse({ error: { code: "NO_SESSION", message: "Authentication required.", requestId: "r" } }, 401)]);
  const anonymousServices = createProductionServices("https://api.arasyahome.ro", { fetchImpl: anonymous.fetchImpl, isOnline: () => true });
  assert.equal(await anonymousServices.auth.getSession(), null);

  const expired = queuedFetch([jsonResponse({ error: { code: "SESSION_EXPIRED", message: "Expired.", requestId: "r" } }, 401)]);
  const expiredServices = createProductionServices("https://api.arasyahome.ro", { fetchImpl: expired.fetchImpl, isOnline: () => true });
  await assert.rejects(expiredServices.auth.getSession(), (error: unknown) => error instanceof StaffServiceError && error.code === "SESSION_EXPIRED");
});

test("refresh rotates the in-memory CSRF token and logout uses the replacement", async () => {
  const refreshed = { ...sessionPayload, csrfToken: "csrf-after-refresh", expiresAt: "2026-08-20T04:00:00Z" };
  const { fetchImpl, calls } = queuedFetch([jsonResponse(sessionPayload), jsonResponse(refreshed), jsonResponse({ ok: true })]);
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  await services.auth.login({ username: "employee", password: "test-only-password" });
  await services.auth.refreshSession();
  assert.equal(new Headers(calls[1]?.init?.headers).get("X-CSRF-Token"), "csrf-runtime-token");
  await services.auth.logout();
  assert.equal(new Headers(calls[2]?.init?.headers).get("X-CSRF-Token"), "csrf-after-refresh");
});

test("production auth maps safe backend failures and never falls back to Preview or Demo", async () => {
  for (const [backendCode, expected] of [["INVALID_CREDENTIALS", "INVALID_CREDENTIALS"], ["RATE_LIMITED", "RATE_LIMITED"], ["ACCOUNT_INACTIVE", "ACCOUNT_INACTIVE"]] as const) {
    const response = queuedFetch([jsonResponse({ error: { code: backendCode, message: "safe", requestId: "r" } }, backendCode === "RATE_LIMITED" ? 429 : 401)]);
    const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl: response.fetchImpl, isOnline: () => true });
    await assert.rejects(
      services.auth.login({ username: "demo", password: "demo" }),
      (error: unknown) => error instanceof StaffServiceError && error.code === expected,
    );
    assert.equal(services.mode, "production");
  }
});

test("network failures remain controlled and production fails closed", async () => {
  const fetchImpl: typeof fetch = async () => { throw new TypeError("network down"); };
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  await assert.rejects(
    services.auth.login({ username: "demo", password: "demo" }),
    (error: unknown) => error instanceof StaffServiceError && error.code === "SERVICE_UNAVAILABLE",
  );
});

test("a failed network logout preserves in-memory CSRF for a safe retry", async () => {
  const calls: Array<{ input: RequestInfo | URL; init?: RequestInit }> = [];
  let attempt = 0;
  const fetchImpl: typeof fetch = async (input, init) => {
    calls.push({ input, init });
    attempt += 1;
    if (attempt === 1) return jsonResponse(sessionPayload);
    if (attempt === 2) throw new TypeError("network down");
    return jsonResponse({ ok: true });
  };
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  await services.auth.login({ username: "employee", password: "test-only-password" });
  await assert.rejects(services.auth.logout(), (error: unknown) => error instanceof StaffServiceError && error.code === "SERVICE_UNAVAILABLE");
  await services.auth.logout();
  assert.equal(new Headers(calls[2]?.init?.headers).get("X-CSRF-Token"), "csrf-runtime-token");
});

test("CSRF_INVALID logout preserves authentication context and can be retried safely", async () => {
  const { fetchImpl, calls } = queuedFetch([
    jsonResponse(sessionPayload),
    jsonResponse({ error: { code: "CSRF_INVALID", message: "denied", requestId: "r" } }, 403),
    jsonResponse({ ok: true }),
  ]);
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  await services.auth.login({ username: "employee", password: "test-only-password" });
  let terminalNotifications = 0;
  services.auth.onSessionExpired(() => { terminalNotifications += 1; });
  await assert.rejects(services.auth.logout(), (error: unknown) => error instanceof StaffServiceError && error.code === "CSRF_INVALID");
  assert.equal(terminalNotifications, 0);
  await services.auth.logout();
  assert.equal(new Headers(calls[2]?.init?.headers).get("X-CSRF-Token"), "csrf-runtime-token");
});

test("logout state policy clears only terminal server session states", () => {
  assert.equal(shouldEndLocalSessionAfterLogout(new StaffServiceError("SESSION_EXPIRED")), true);
  assert.equal(shouldEndLocalSessionAfterLogout(new StaffServiceError("ACCOUNT_INACTIVE")), true);
  assert.equal(shouldEndLocalSessionAfterLogout(new StaffServiceError("CSRF_INVALID")), false);
  assert.equal(shouldEndLocalSessionAfterLogout(new StaffServiceError("SERVICE_UNAVAILABLE")), false);
});

test("session expiry from an authenticated request notifies the application exactly once", async () => {
  const { fetchImpl } = queuedFetch([
    jsonResponse(sessionPayload),
    jsonResponse({ error: { code: "SESSION_EXPIRED", message: "expired", requestId: "r" } }, 401),
  ]);
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  await services.auth.login({ username: "employee", password: "test-only-password" });
  let notifications = 0;
  services.auth.onSessionExpired(() => { notifications += 1; });
  await assert.rejects(services.orders.listMine(), (error: unknown) => error instanceof StaffServiceError && error.code === "SESSION_EXPIRED");
  assert.equal(notifications, 1);
});

test("frontend permission helpers are presentation hints with fail-closed route checks", () => {
  const employee = mapProductionEmployee(employeePayload);
  assert.equal(hasPermission(employee, "orders.scan"), true);
  assert.equal(canAccessRoute(employee, "/orders"), true);
  assert.equal(canAccessRoute(employee, "/profile"), true);
  assert.equal(canAccessRoute({ ...employee, permissions: [] }, "/orders"), false);
});

test("service worker and production adapter do not persist or cache authentication data", () => {
  const serviceWorker = readFileSync(new URL("../public/sw.js", import.meta.url), "utf8");
  const adapter = readFileSync(new URL("../services/production/httpServices.ts", import.meta.url), "utf8");
  assert.doesNotMatch(serviceWorker, /auth\/|employees\/me|api\.arasyahome/i);
  assert.doesNotMatch(adapter, /localStorage|sessionStorage|indexedDB|caches\.open/);
  assert.match(adapter, /credentials:\s*"include"/);
});
