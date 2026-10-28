import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement, type ReactElement, type ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { Employee } from "../domain/models";
import { ProfileScreen } from "../features/profile/ProfileScreen";
import { I18nProvider, CATALOGS, SUPPORTED_LOCALES, type Locale } from "../i18n";
import { createProductionServices } from "../services/production/httpServices";
import { createDemoServices } from "../services/dev/demoServices";

// Staff 2.39.0 (API 2.49.0, owner decision 2026-10-28): the employee chooses the language of their own account on
// the Profile screen. The request names only the language; the answer is the same session with the new locale.

const employee = (locale: Locale): Employee => ({
  employeeUuid: "00000000-0000-4000-8000-000000000001", displayName: "Iancu Test", username: "iancu.test", department: "Vanzari", departmentKey: "vanzari-online",
  role: "employee", status: "active", permissions: ["profile.view_self"], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale,
});
const sessionBody = (locale: Locale, csrfToken: string) => ({ employee: employee(locale), expiresAt: "2026-10-28T20:00:00Z", csrfToken });

const Provider = I18nProvider as (props: { locale: Locale; children?: ReactNode }) => ReactElement;
const render = (locale: Locale, withHandler = true) => renderToStaticMarkup(createElement(Provider, { locale }, createElement(ProfileScreen, { employee: employee(locale), mode: "production", onLogout: async () => undefined, ...(withHandler ? { onChangeLocale: async () => undefined } : {}) })));

function jsonResponse(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("changeLocale sends PUT /auth/locale with the CSRF token and only the language", async () => {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  const fetchImpl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    if (url.endsWith("/auth/session")) return jsonResponse(200, sessionBody("ro", "csrf-1"));
    return jsonResponse(200, sessionBody("tr", "csrf-1"));
  }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, requestId: () => "req" });
  await services.auth.getSession();
  const next = await services.auth.changeLocale("tr");
  assert.equal(next.employee.locale, "tr");
  const call = calls[1];
  assert.equal(call.url, "https://api.arasyahome.ro/auth/locale");
  assert.equal(call.init.method, "PUT");
  assert.equal(new Headers(call.init.headers).get("X-CSRF-Token"), "csrf-1");
  assert.equal(call.init.credentials, "include");
  assert.deepEqual(JSON.parse(String(call.init.body)), { locale: "tr" });
});

test("a refused language change surfaces as an error and leaves the session to the caller", async () => {
  const fetchImpl = (async (url: string) => url.endsWith("/auth/session")
    ? jsonResponse(200, sessionBody("ro", "csrf-1"))
    : jsonResponse(404, { error: { code: "NOT_FOUND", message: "x", requestId: "r" } })) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, requestId: () => "req" });
  await services.auth.getSession();
  await assert.rejects(services.auth.changeLocale("tr"));
});

test("the demo session switches its own language", async () => {
  const services = createDemoServices();
  await services.auth.login({ username: "demo", password: "x" });
  assert.equal((await services.auth.changeLocale("tr")).employee.locale, "tr");
  assert.equal((await services.auth.getSession())?.employee.locale, "tr");
});

test("the profile offers the four languages with the account language pressed, in the account language", () => {
  for (const locale of SUPPORTED_LOCALES) {
    const html = render(locale);
    assert.match(html, new RegExp(`role="group" aria-label="${CATALOGS[locale]["profile.languageChoose"]}"`), locale);
    assert.equal((html.match(/aria-pressed="true"/g) ?? []).length, 1, locale);
    assert.match(html, new RegExp(`lang="${locale}" aria-pressed="true"`), locale);
    for (const code of SUPPORTED_LOCALES) assert.match(html, new RegExp(`<button type="button" lang="${code}"`), `${locale}: ${code}`);
    assert.ok(html.includes(CATALOGS[locale]["profile.languageNote"]), locale);
  }
  const turkish = render("tr");
  assert.ok(turkish.includes("Uygulamanın dilini seç. Tüm cihazlarında geçerli olur."));
  assert.ok(!turkish.includes("yöneticine başvur"), "the old 'ask your manager' note is gone");
});

test("without a language handler the profile shows only the account language", () => {
  const html = render("ro", false);
  assert.ok(!html.includes("aria-pressed"));
  assert.match(html, /data-testid="profile-language"/);
});
