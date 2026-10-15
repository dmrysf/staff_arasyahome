import { expect, test, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import type { SimulationOverview } from "../../domain/simulation";
const file = process.env.ARASYA_SIMULATION_MANIFEST;
if (!file) throw new Error("ARASYA_SIMULATION_MANIFEST is required");
const manifest = JSON.parse(readFileSync(file, "utf8")) as { accounts: Record<string, { username: string; password: string; locale: string }> };
const api = process.env.ARASYA_SIMULATION_API_URL ?? "http://127.0.0.1:8788";
const staff = process.env.ARASYA_SIMULATION_STAFF_URL ?? "http://127.0.0.1:4175";
async function login(page: Page, account: string) {
  await page.goto("/login");
  await expect(page.locator(".sim-banner")).toContainText("SIMULARE / TEST");
  await page.getByLabel("Nume utilizator").fill(manifest.accounts[account].username);
  await page.getByLabel("Parolă").fill(manifest.accounts[account].password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.locator(".app-main")).toBeVisible();
}
async function controllerOverview(page: Page): Promise<SimulationOverview> {
  const response = await page.request.get(`${api}/simulation/overview`, { headers: { Origin: staff } });
  expect(response.status()).toBe(200); return response.json();
}

test("real Staff simulation center, filters, logical replay and controller access", async ({ page }) => {
  const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message));
  await login(page, "controller"); await page.goto("/simulation");
  await expect(page.getByRole("heading", { name: "Centrul de simulare" })).toBeVisible();
  await expect(page.locator(".sim-card")).toHaveCount(20);
  await page.getByRole("combobox").selectOption("trendhome");
  await expect(page.locator(".sim-card")).toHaveCount(2);
  const card = page.locator(".sim-card").filter({ hasText: "WOO-TH-001" });
  await card.getByRole("button", { name: "Oprește" }).click();
  await expect(card.getByRole("button", { name: "Continuă" })).toBeVisible();
  await expect(card.getByRole("button", { name: "Injectează condiția" })).toBeDisabled();
  await card.getByRole("button", { name: "Continuă" }).click();
  await expect(card.getByRole("button", { name: "Oprește" })).toBeVisible();
  await card.getByRole("button", { name: "Injectează condiția" }).click();
  await card.locator("summary").click();
  await expect(card).toContainText("event_conflict_rejected");
  const before = await controllerOverview(page);
  const replay = before.runs.find((r) => r.scenario_id === "WOO-TH-001")!.replay_number;
  await card.getByRole("button", { name: "Reia cu date noi" }).click();
  await expect(card).toContainText(`R${replay + 1}`);
  for (const width of [360, 390, 430, 768, 1440]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect(page.locator(".sim-banner")).toBeVisible();
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => { window.scrollTo(0, 0); (document.activeElement as HTMLElement)?.blur(); });
  await page.screenshot({ path: "work/simulation-center-mobile.png", fullPage: true });
  expect(errors).toEqual([]);
});

test("normal employee cannot control scenarios or select administrator roles", async ({ page }) => {
  await login(page, "waiting"); await page.goto("/simulation");
  await expect(page.locator(".sim-center")).toHaveCount(0);
  const response = await page.request.get(`${api}/simulation/overview`);
  expect(response.status()).toBe(403);
  await expect(page.locator(".sim-banner")).toBeVisible();
});

for (const name of ["waiting", "material-preparation", "workshop-receiving", "labeling"]) {
  test(`actual ${manifest.accounts[name].locale} Staff at 360px without overflow or script failure`, async ({ page }) => {
    const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message));
    await page.setViewportSize({ width: 360, height: 800 }); await login(page, name);
    await expect(page.locator("html")).toHaveAttribute("lang", manifest.accounts[name].locale);
    await page.goto("/profile"); await expect(page.locator(".app-content")).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    expect(errors).toEqual([]);
  });
}

test("real order detail preserves two measured lines and double-click cannot duplicate claim", async ({ browser }) => {
  const control = await browser.newPage(); await login(control, "controller");
  const overview = await controllerOverview(control);
  const order = overview.orders.find((o) => o.scenario_id === "WOO-TH-001")!;
  const page = await browser.newPage(); await login(page, "waiting");
  const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(`/orders/${encodeURIComponent(order.global_order_id)}`);
  await expect(page.locator(".detail-products")).toContainText("Perdea Voal");
  await expect(page.locator(".detail-products")).toContainText("Perdea Premium");
  const claimRequests: string[] = [];
  page.on("request", (r) => { if (r.url().endsWith("/claim")) claimRequests.push(r.url()); });
  await page.getByRole("button", { name: /Preia comanda/ }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Confirmă", exact: true }).dblclick();
  await expect(page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  expect(claimRequests).toHaveLength(1);
  await page.getByRole("button", { name: /Finalizează etapa/ }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Confirmă", exact: true }).click();
  await expect(page.locator(".detail-hero")).toContainText("Tăiere");
  await page.reload(); await expect(page.locator(".detail-hero")).toContainText("Tăiere");
  expect(errors).toEqual([]); await page.close(); await control.close();
});

test("center displays a retryable service error and restores real state", async ({ page }) => {
  await login(page, "controller");
  await page.route(`${api}/simulation/overview`, (route) => route.fulfill({ status: 503, contentType: "application/json", body: '{"error":{"code":"SERVICE_UNAVAILABLE","message":"Fixture failure"}}' }));
  await page.goto("/simulation"); await expect(page.getByRole("alert")).toContainText("SERVICE_UNAVAILABLE");
  await page.unroute(`${api}/simulation/overview`);
  await page.getByRole("alert").getByRole("button", { name: "Reîncarcă" }).click();
  await expect(page.locator(".sim-card")).toHaveCount(20);
});

test("session loss removes control access and retains simulation environment marker", async ({ page }) => {
  await login(page, "controller"); await page.goto("/simulation"); await expect(page.locator(".sim-card")).toHaveCount(20);
  await page.context().clearCookies(); await page.reload();
  await expect(page.getByRole("button", { name: /Autentificare/ })).toBeVisible();
  await expect(page.locator(".sim-banner")).toBeVisible();
});


test("malformed successful center response shows an error instead of a blank screen", async ({ page }) => {
  const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message));
  await login(page, "controller");
  await page.route(`${api}/simulation/overview`, (route) => route.fulfill({ status: 200, contentType: "application/json", body: '{"environment":"SIMULARE / TEST","scenarios":[],"runs":[]}' }));
  await page.goto("/simulation"); await expect(page.getByRole("alert")).toContainText("SERVER_ERROR");
  expect(errors).toEqual([]);
});
