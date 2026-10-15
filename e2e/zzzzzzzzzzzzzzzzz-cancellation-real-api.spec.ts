import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

// API 2.35.0 cancellation control, worker view, against the real Operations API, MySQL and Chromium (synthetic order).
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8"));
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const run = (...args: string[]) => JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "cancellation-fixture.php"), ...args], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
async function api(username: string, password = fixture.password) {
  const context = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await context.post("/auth/login", { data: { username, password } }); expect(login.status()).toBe(200);
  const csrf = (await login.json()).csrfToken;
  const send = (url: string, data: unknown) => context.post(url, { data, headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `cancel-browser-${crypto.randomUUID()}` } });
  return { send, get: async (url: string) => { const r = await context.get(url); expect(r.status(), await r.text()).toBe(200); return r.json(); } };
}
async function login(browser: Browser, user: string) {
  const context = await browser.newContext({ viewport: { width: 320, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage(); const errors: string[] = []; page.on("pageerror", e => errors.push(e.message));
  await page.goto("/login"); await page.getByLabel("Nume utilizator").fill(user); await page.getByLabel("Parolă").fill(fixture.password); await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible(); return { context, page, errors };
}
const go = (page: Page, to: string) => page.evaluate(target => { history.pushState({}, "", target); dispatchEvent(new PopStateEvent("popstate")); }, to);

test("a cancelled order stays at its stage, read-only, and the API refuses the stage completion, at 320/390/1280", async ({ browser }) => {
  test.setTimeout(120_000);
  const created = run("create");
  const tailor = await api(fixture.supervision.tailor);
  const pathOrder = `/orders/${encodeURIComponent(created.order)}`;
  let state = await tailor.get(pathOrder);
  const claimed = await tailor.send(`${pathOrder}/claim`, { expectedVersion: state.productionVersion });
  expect(claimed.status()).toBe(200);
  state = await claimed.json();
  expect(state.employeeAllowedAction.id).toBe("complete_stage");

  const worker = await login(browser, fixture.supervision.tailor);
  await go(worker.page, pathOrder);
  await expect(worker.page.getByRole("button", { name: "Finalizează etapa" })).toBeVisible();
  run("cancel", created.number);

  // The screen that was open before the cancellation: the server refuses, nothing moves.
  const refused = await tailor.send(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  expect(refused.status()).toBe(409);
  expect((await refused.json()).error.code).toBe("ORDER_UNAVAILABLE");
  const after = await tailor.get(pathOrder);
  expect(after.productionStageId).toBe("ironing");
  expect(after.productionStop.state).toBe("stopped");
  expect(after.employeeAllowedAction).toBeUndefined();

  await worker.page.reload();
  const notice = worker.page.getByTestId("production-stop");
  await expect(notice).toBeVisible();
  await expect(notice).toContainText("Comandă anulată");
  await expect(notice).toContainText("Nu poți continua producția acestei comenzi");
  await expect(notice).toContainText("Anularea a fost raportată de sursă");
  await expect(notice).toContainText("Este necesară verificarea fizică a produsului");
  await expect(worker.page.getByRole("button", { name: "Finalizează etapa" })).toHaveCount(0);
  await expect(worker.page.getByRole("button", { name: "Preia comanda" })).toHaveCount(0);
  for (const width of [320, 390, 1280]) {
    await worker.page.setViewportSize({ width, height: 844 });
    expect(await overflow(worker.page)).toBeLessThanOrEqual(0);
    await worker.page.screenshot({ path: `e2e/.runtime/staff-cancellation-${width}.png`, fullPage: true });
  }
  // A stopped order is not work in progress: it is listed under the recent orders, marked as stopped.
  await go(worker.page, "/orders");
  await worker.page.getByRole("tab", { name: /Recente/ }).click();
  await expect(worker.page.getByRole("button", { name: new RegExp(created.number) }).getByTestId("order-card-stop")).toBeVisible();
  expect(worker.errors).toEqual([]);
});
