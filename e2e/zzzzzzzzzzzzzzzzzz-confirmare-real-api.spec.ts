import AxeBuilder from "@axe-core/playwright";
import { expect, request, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync } from "node:fs";
import path from "node:path";

// Confirmare Staff workspace (API 2.39.0, Staff 2.29.0) against the real Operations API, last of the suites (it
// activates both shop sources in the disposable E2E database with the TEST override of WORKFLOW_READY). Covers the
// operator's day (next step, queue, recorded calls, line and price review, approval), a shop change after the review,
// a 409 after a supervisor's reassignment, the supervisor view, isolation of sources and channels, session expiry,
// revoked access, a network interruption, layouts from 320 to 1920 px and WCAG 2.2 AA. Generic TEST people only.
type Person = { username: string; name: string; id: string };
type Fixture = { skip?: string; password: string; people: Record<"operator" | "colleague" | "outlet" | "supervisor", Person>; orders: Record<"call" | "cod" | "colleague" | "noPrice" | "unassigned" | "outlet" | "legacy" | "undetermined", string> };
const root = path.resolve(import.meta.dirname, "..");
const base = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as { users: { ana: string }; password: string };
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const SHOTS = path.join(root, "e2e", ".runtime", "confirmare");
const php = (args: string[] = []) => execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-confirmare-fixture.php"), ...args], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] });
let F: Fixture;
const number = (globalId: string) => globalId.split(":")[1];
const orderPath = (globalId: string) => `/confirmare/orders/${encodeURIComponent(globalId)}`;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const capture = async (page: Page, name: string) => { mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true }); };

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  F = JSON.parse(php()) as Fixture;
});

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(F?.password ?? base.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  return { context, page, errors };
}

async function api(username: string, password: string): Promise<{ ctx: APIRequestContext; csrf: string }> {
  const ctx = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await ctx.post("/auth/login", { data: { username, password } });
  expect(login.status(), `login ${username}`).toBe(200);
  return { ctx, csrf: (await login.json()).csrfToken as string };
}
const send = (who: { ctx: APIRequestContext; csrf: string }, method: "post" | "put", url: string, data: unknown) => who.ctx[method](url, { headers: { "X-CSRF-Token": who.csrf, "Idempotency-Key": `e2e-cf-${Date.now()}-${Math.random().toString(36).slice(2)}` }, data });
const detailOf = async (who: { ctx: APIRequestContext }, globalId: string) => (await (await who.ctx.get(`/confirmare/orders/${encodeURIComponent(globalId)}`)).json()) as { version: number; status: string; events: { action: string }[]; assignment: { employeeId: string } | null };

async function accessible(page: Page, screen: string) {
  await page.waitForTimeout(300);
  const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>("main button, main a[href], main input:not([type=hidden]), main select, main textarea, main [role=tab]")]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0 && getComputedStyle(element).visibility !== "hidden" && !element.closest("[aria-hidden=true]"); })
    .map((element) => { const own = element.getBoundingClientRect(); const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: Math.max(own.width, target.width), height: Math.max(own.height, target.height) }; })
    .filter((item) => item.width < 44 || item.height < 44)
    .map((item) => `${item.name} ${Math.round(item.width)}×${Math.round(item.height)}`));
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

async function recordCall(page: Page, outcome: RegExp, keyboard = false) {
  const radio = page.getByRole("radio", { name: outcome });
  const happened = page.getByTestId("cf-call-happened");
  const submit = page.getByTestId("cf-record-call");
  await expect(submit).toBeDisabled();
  if (keyboard) {
    await radio.focus();
    await page.keyboard.press("Space");
    await happened.focus();
    await page.keyboard.press("Space");
    await expect(submit).toBeEnabled();
    await submit.focus();
    await page.keyboard.press("Enter");
  } else {
    await radio.check();
    await expect(submit, "an outcome alone never records a call").toBeDisabled();
    await happened.check();
    await submit.click();
  }
  await expect(page.getByTestId("cf-message")).toContainText("Apel înregistrat");
}

test("operator: next step, queue, recorded calls (never a cancellation), line and price review, one approval", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(120_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await expect(page.getByTestId("workspace-confirmare")).toBeVisible();
  await expect(page.getByTestId("cf-next-action")).toContainText(`#${number(F.orders.call)}`);
  await expect(page.getByTestId("cf-next-action")).toContainText("Sunați clientul");
  await expect(page.getByTestId("cf-contact-dependency")).toContainText("nu este încă disponibil în Arasya");
  await capture(page, "home-operator-390");

  await page.getByTestId("cf-open-queue").click();
  await expect(page).toHaveURL(/\/confirmare$/);
  const list = page.locator("#cf-tab-panel");
  await expect(list.getByTestId(`cf-row-${number(F.orders.call)}`)).toBeVisible();
  await expect(list.getByTestId(`cf-row-${number(F.orders.cod)}`)).toBeVisible();
  for (const hidden of [F.orders.legacy, F.orders.outlet, F.orders.undetermined, F.orders.colleague]) await expect(page.getByTestId(`cf-row-${number(hidden)}`)).toHaveCount(0);
  await expect(page.locator("body")).not.toContainText("TEST Client Confirmare");
  await page.getByRole("tab", { name: /Neatribuite/ }).click();
  await expect(page).toHaveURL(/tab=unassigned/);
  await expect(list.getByTestId(`cf-row-${number(F.orders.unassigned)}`)).toBeVisible();
  await capture(page, "queue-operator-390");
  await page.getByRole("tab", { name: /Ale mele/ }).click();

  await list.getByTestId(`cf-row-${number(F.orders.call)}`).click();
  await expect(page.getByTestId("cf-order")).toBeVisible();
  await expect(page.getByTestId("cf-customer-facts")).toContainText("TEST Client Confirmare");
  await expect(page.getByTestId("cf-customer-facts")).toContainText("07** *** ***");
  await expect(page.locator("body")).not.toContainText("0700000123");
  await expect(page.getByTestId("cf-phone-unavailable")).toBeVisible();
  await expect(page.getByTestId("cf-approve")).toBeDisabled();
  await expect(page.getByTestId("cf-checklist")).toContainText("Clientul a confirmat la telefon");
  await capture(page, "order-before-call-390");

  await recordCall(page, /^Nu răspunde/);
  await expect(page.getByTestId("cf-order-status")).toHaveText("Nu răspunde");
  await expect(page.getByTestId("cf-history")).toContainText("Apel: Nu răspunde");
  await page.getByRole("button", { name: "Înapoi la Confirmare" }).click();
  await page.getByRole("tab", { name: /De reapelat/ }).click();
  await expect(list.getByTestId(`cf-row-${number(F.orders.call)}`)).toContainText("Nu răspunde");
  await list.getByTestId(`cf-row-${number(F.orders.call)}`).click();

  // The customer confirms on the second call; recorded with the keyboard only.
  await recordCall(page, /^Confirmat/, true);
  await expect(page.getByTestId("cf-order-status")).toHaveText("Confirmat");
  await page.getByTestId("cf-line-confirm-1").check();
  await expect(page.getByTestId("cf-approve")).toBeDisabled();
  await page.getByTestId("cf-line-confirm-2").check();
  await expect(page.getByTestId("cf-approve"), "no approval before the prices are reviewed").toBeDisabled();
  await page.getByTestId("cf-price-reviewed").check();
  await expect(page.getByTestId("cf-approve")).toBeEnabled();
  await capture(page, "order-ready-390");
  await page.getByTestId("cf-approve").click();
  await expect(page.getByText(`Aprobați comanda #${number(F.orders.call)}?`)).toBeVisible();
  await page.getByTestId("cf-approve-confirm").dblclick();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda a fost aprobată.");
  await expect(page.getByTestId("cf-order-status")).toHaveText("Aprobată pentru producție");
  await expect(page.getByTestId("cf-lines-progress")).toHaveText("2 din 2 confirmate");
  const supervisor = await api(F.people.supervisor.username, F.password);
  const approved = await detailOf(supervisor, F.orders.call);
  expect(approved.events.filter((event) => event.action === "approved"), "a double click approves once").toHaveLength(1);
  await capture(page, "order-approved-390");
  expect(op.errors).toEqual([]);
  await op.context.close();
});

test("a shop change after the review needs a fresh verification; a confirmed COD order waits for its advance", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await page.goto(orderPath(F.orders.cod));
  await expect(page.getByTestId("cf-order")).toBeVisible();
  await recordCall(page, /^Confirmat/);
  await page.getByTestId("cf-line-confirm-1").check();
  await page.getByTestId("cf-line-confirm-2").check();
  await page.getByTestId("cf-price-reviewed").check();
  // The shop changes an option while the operator still looks at the old order.
  php([`--change=${number(F.orders.cod)}`]);
  await page.getByTestId("cf-approve").click();
  await page.getByTestId("cf-approve-confirm").click();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda s-a schimbat în magazin");
  await expect(page.getByTestId("cf-changed")).toBeVisible();
  await expect(page.getByTestId("cf-line-confirm-1")).not.toBeChecked();
  await expect(page.getByTestId("cf-price-reviewed")).not.toBeChecked();
  await expect(page.getByTestId("cf-approve")).toBeDisabled();
  await expect(page.getByTestId("cf-history")).toContainText("comanda s-a schimbat de atunci");
  await capture(page, "order-changed-in-shop-390");
  await recordCall(page, /^Confirmat/);
  await page.getByTestId("cf-line-confirm-1").check();
  await page.getByTestId("cf-line-confirm-2").check();
  await page.getByTestId("cf-price-reviewed").check();
  await page.getByTestId("cf-approve").click();
  await expect(page.getByRole("group", { name: /Aprobați comanda/ })).toContainText("producția rămâne blocată până când magazinul confirmă plata");
  await page.getByTestId("cf-approve-confirm").click();
  await expect(page.getByTestId("cf-order-status")).toHaveText("În așteptarea plății");
  await expect(page.getByTestId("cf-financial")).toHaveText("Așteaptă plata");
  await expect(page.getByRole("region", { name: "Producție" })).toContainText("Plata sau avansul confirmate de magazin");
  await page.goto("/confirmare?tab=payment");
  await expect(page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.cod)}`)).toContainText("În așteptarea plății");
  await capture(page, "queue-payment-390");
  expect(op.errors).toEqual([]);
  await op.context.close();
});

test("409: a supervisor reassigns an order a colleague still has open; the colleague's view reloads safely", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  const colleague = await signIn(browser, F.people.colleague.username);
  const page = colleague.page;
  await page.goto(orderPath(F.orders.colleague));
  await expect(page.getByTestId("cf-record-call")).toBeVisible();
  const supervisor = await api(F.people.supervisor.username, F.password);
  const before = await detailOf(supervisor, F.orders.colleague);
  expect((await send(supervisor, "post", `/confirmare/orders/${encodeURIComponent(F.orders.colleague)}/reassign`, { expectedVersion: before.version, employeeId: F.people.operator.id })).status()).toBe(200);
  await page.getByRole("radio", { name: /^Confirmat/ }).check();
  await page.getByTestId("cf-call-happened").check();
  await page.getByTestId("cf-record-call").click();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda a fost modificată între timp");
  await expect(page.getByText(`Atribuită: ${F.people.operator.name}`).first()).toBeVisible();
  await expect(page.getByTestId("cf-record-call")).toHaveCount(0);
  const after = await detailOf(supervisor, F.orders.colleague);
  expect(after.events.some((event) => event.action === "approved")).toBe(false);
  expect(after.assignment?.employeeId).toBe(F.people.operator.id);
  expect(colleague.errors).toEqual([]);
  await colleague.context.close();
});

test("supervisor: team, leave with redistribution, alerts and attention; no bypass of the call or the price", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const sup = await signIn(browser, F.people.supervisor.username, 1440, 900);
  const page = sup.page;
  await expect(page.getByTestId("workspace-confirmare")).toBeVisible();
  await expect(page.getByTestId("cf-open-team")).toBeVisible();
  await capture(page, "home-supervisor-1440");
  await page.getByTestId("cf-open-team").click();
  await expect(page.getByTestId("cf-team")).toBeVisible();
  for (const person of [F.people.operator, F.people.colleague, F.people.outlet]) await expect(page.getByTestId(`cf-person-${person.id}`)).toContainText(person.name);
  await expect(page.getByTestId(`cf-person-${F.people.supervisor.id}`)).toHaveCount(0);
  const attention = page.getByRole("region", { name: "Atenție" });
  await expect(attention.getByTestId(`cf-row-${number(F.orders.noPrice)}`)).toBeVisible();
  await expect(attention.getByTestId(`cf-row-${number(F.orders.undetermined)}`)).toBeVisible();
  await capture(page, "team-1440");
  const colleagueCard = page.getByTestId(`cf-person-${F.people.colleague.id}`);
  await colleagueCard.getByTestId("cf-leave").click();
  await colleagueCard.getByTestId("cf-leave-confirm").click();
  await expect(page.getByText("Concediu înregistrat.")).toBeVisible();
  await expect(colleagueCard).toContainText("În concediu");
  await expect(colleagueCard).toContainText("0 comenzi deschise");
  await colleagueCard.getByTestId("cf-leave").click();
  await colleagueCard.getByTestId("cf-leave-confirm").click();
  await expect(colleagueCard).toContainText("Disponibil");

  // No price evidence: even a supervisor cannot approve.
  await page.goto(orderPath(F.orders.noPrice));
  await expect(page.getByTestId("cf-price-missing")).toBeVisible();
  await expect(page.getByTestId("cf-approve")).toBeDisabled();
  await expect(page.getByTestId("cf-checklist")).toContainText("Magazinul a trimis amprenta prețurilor");
  // Unknown submission instant: visible to the supervisor, never approvable, never recorded as a call.
  await page.goto(orderPath(F.orders.undetermined));
  await expect(page.getByText("Momentul plasării comenzii nu este cunoscut").first()).toBeVisible();
  await expect(page.getByTestId("cf-record-call")).toHaveCount(0);
  await expect(page.getByTestId("cf-approve")).toBeDisabled();
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});

test("isolation: another shop, legacy orders and people without a scope never reach Confirmare", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  const outlet = await signIn(browser, F.people.outlet.username);
  await outlet.page.goto("/confirmare");
  await expect(outlet.page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.outlet)}`)).toBeVisible();
  await expect(outlet.page.getByTestId(`cf-row-${number(F.orders.call)}`)).toHaveCount(0);
  await expect(outlet.page.getByRole("radiogroup", { name: "Magazin" })).toHaveCount(0);
  await outlet.page.goto(orderPath(F.orders.call));
  await expect(outlet.page.getByText("Comanda nu există sau nu mai este disponibilă pentru dumneavoastră.")).toBeVisible();
  await outlet.page.goto("/confirmare/team");
  await expect(outlet.page.getByTestId("cf-team")).toHaveCount(0);
  await outlet.context.close();

  const supervisor = await signIn(browser, F.people.supervisor.username);
  await supervisor.page.goto(orderPath(F.orders.legacy));
  await expect(supervisor.page.getByText("Comanda nu există sau nu mai este disponibilă pentru dumneavoastră.")).toBeVisible();
  await supervisor.page.goto("/confirmare");
  await expect(supervisor.page.getByRole("radiogroup", { name: "Magazin" })).toBeVisible();
  await supervisor.page.getByRole("radio", { name: "Outlet Perdele" }).click();
  await expect(supervisor.page).toHaveURL(/source=outletperdele/);
  await expect(supervisor.page.getByTestId(`cf-row-${number(F.orders.call)}`)).toHaveCount(0);
  await supervisor.context.close();

  const unrelated = await signIn(browser, base.users.ana);
  await expect(unrelated.page.getByTestId("workspace-confirmare")).toHaveCount(0);
  await unrelated.page.goto("/confirmare");
  await expect(unrelated.page).toHaveURL(/\/confirmare$/);
  await expect(unrelated.page.getByTestId("cf-queue")).toHaveCount(0);
  await unrelated.context.close();
});

test("a network interruption keeps the last data; revoked access and an expired session stop the work", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await page.goto("/confirmare");
  const row = page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.colleague)}`);
  await expect(row).toBeVisible();
  await op.context.setOffline(true);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).toContainText("Datele pot fi neactualizate");
  await expect(row, "the last good list stays").toBeVisible();
  await capture(page, "queue-offline-390");
  await op.context.setOffline(false);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).not.toContainText("Datele pot fi neactualizate");

  const rootUser = await api("arasya.root.owner", "staff root fixture permanent 2026");
  expect((await send(rootUser, "put", `/management/employees/${F.people.operator.id}/confirmare-scopes`, { operate: [], supervise: [] })).status()).toBe(200);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).toContainText("Actualizare automată oprită");
  expect((await send(rootUser, "put", `/management/employees/${F.people.operator.id}/confirmare-scopes`, { operate: ["trendhome"], supervise: [] })).status()).toBe(200);

  // The session ends while the operator works: the next read returns to the sign-in screen and says why.
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await op.context.clearCookies();
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByText("Sesiunea a expirat. Autentifică-te din nou.")).toBeVisible();
  await op.context.close();
});

test("layouts from 320 to 1920 px without horizontal scrolling, WCAG 2.2 AA and 44 px targets", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(240_000);
  const sup = await signIn(browser, F.people.supervisor.username);
  const page = sup.page;
  for (const width of [320, 360, 390, 768, 1024, 1440, 1920]) {
    await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
    for (const [name, url, ready] of [["home", "/", "workspace-confirmare"], ["queue", "/confirmare?tab=attention", "cf-queue"], ["order", orderPath(F.orders.cod), "cf-order"], ["team", "/confirmare/team", "cf-team"]] as const) {
      await page.goto(url);
      await expect(page.getByTestId(ready)).toBeVisible();
      await page.waitForTimeout(150);
      expect(await overflow(page), `${name} at ${width}px`).toBeLessThanOrEqual(0);
      await capture(page, `${name}-${width}`);
      if (width === 390 || width === 1440) await accessible(page, `${name} ${width}`);
    }
  }
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});
