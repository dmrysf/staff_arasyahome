import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

type Fixture = { password: string; qr: Record<string, string>; exceptions: { order: string; cutter: string; intake: string; manager: string } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const apiOrigin = "http://127.0.0.1:8787";
const webOrigin = "http://127.0.0.1:4174";
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  // A full page reload would drop this marker; live updates must never need one.
  await page.evaluate(() => { (window as unknown as { __noReload: boolean }).__noReload = true; });
  return { context, page, errors, unreloaded: () => page.evaluate(() => (window as unknown as { __noReload?: boolean }).__noReload === true) };
}

test("cutting fault: report at tailoring intake, live acknowledgment with QR, manager approval seen live, no refresh", async ({ browser }) => {
  test.setTimeout(150_000);
  const orderId = `trendhome:${fixture.exceptions.order}`;
  const cutter = await signIn(browser, fixture.exceptions.cutter);
  const intake = await signIn(browser, fixture.exceptions.intake, 360, 740);

  // Tailoring intake selects exactly lines C + D (17 m of 29 m).
  await intake.page.evaluate((path) => { window.history.pushState({}, "", path); window.dispatchEvent(new PopStateEvent("popstate")); }, `/orders/${encodeURIComponent(orderId)}`);
  await intake.page.getByRole("button", { name: "Returnează la Tăiere" }).click();
  const dialog = intake.page.getByRole("dialog", { name: "Returnează la Tăiere" });
  await dialog.getByRole("checkbox", { name: /Draperie C/ }).check();
  await dialog.getByRole("checkbox", { name: /Draperie D/ }).check();
  await expect(dialog.getByRole("status")).toContainText("17 m");
  await dialog.getByLabel("Motiv").selectOption({ label: "Tăiere greșită" });
  expect(await overflow(intake.page)).toBeLessThanOrEqual(0);
  await dialog.getByRole("button", { name: "Trimite cererea" }).click();
  await expect(intake.page.getByRole("heading", { name: `Comanda #${fixture.exceptions.order}` })).toBeVisible();
  await expect(intake.page.getByText("Așteaptă confirmarea de la tăiere")).toBeVisible();
  await expect(intake.page.getByText("2 produse · 17 m")).toBeVisible();

  // The responsible cutting employee is told live, without reloading the page.
  const notice = cutter.page.locator(".live-notice");
  await expect(notice).toContainText(`Comanda #${fixture.exceptions.order} a fost returnată la tăiere`, { timeout: 15_000 });
  await notice.getByRole("button", { name: "Deschide cererea" }).click();
  await expect(cutter.page.getByText("Tăiat de")).toBeVisible();
  const ack = cutter.page.locator(".fault-action");
  await ack.getByRole("checkbox", { name: "Confirm că eroarea îmi aparține și refac lucrarea." }).check();
  await ack.getByRole("button", { name: "Introdu codul de pe etichetă" }).click();
  await ack.getByLabel(/Cod etichetă/).fill(fixture.qr[fixture.exceptions.order]);
  await ack.getByRole("button", { name: "Verifică codul" }).click();
  await expect(cutter.page.getByText("Cererea așteaptă aprobarea managerului operațional.")).toBeVisible();
  // Stage 3 detector sees the new state live too.
  await expect(intake.page.getByText("Așteaptă aprobarea managerului").first()).toBeVisible({ timeout: 15_000 });

  // One operations manager approves through the API (the Dashboard suite covers its screens).
  const manager = await request.newContext({ baseURL: apiOrigin, extraHTTPHeaders: { Origin: webOrigin } });
  const login = await manager.post("/auth/login", { data: { username: fixture.exceptions.manager, password: fixture.password } });
  const csrf = (await login.json()).csrfToken as string;
  const pending = await (await manager.get("/management/production-exceptions?view=pending")).json();
  const request0 = pending.items.find((item: { order: { id: string } }) => item.order.id === orderId);
  expect(request0.faultMeters).toBe("17.000");
  const decided = await manager.post(`/management/production-exceptions/${request0.id}/decision`, { headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `e2e-approve-${Date.now()}` }, data: { expectedVersion: request0.version, decision: "approve" } });
  expect(decided.status()).toBe(200);
  await manager.dispose();

  await expect(cutter.page.locator(".live-notice")).toContainText("Aprobarea a fost acordată. Lucrarea poate fi refăcută.", { timeout: 15_000 });
  await expect(cutter.page.getByText("✓ Aprobarea a fost acordată. Lucrarea poate fi refăcută.")).toBeVisible();
  await expect(intake.page.getByText("Aprobată · lucrarea se reface").first()).toBeVisible({ timeout: 15_000 });
  expect(await cutter.unreloaded()).toBe(true);

  // The order is back at cutting, assigned to the cutter, without any refresh.
  await cutter.page.getByRole("button", { name: "Vezi comanda" }).click();
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();

  for (const [width, height] of [[360, 740], [390, 844], [768, 1024], [1024, 768], [1440, 900]]) {
    await intake.page.setViewportSize({ width, height });
    expect(await overflow(intake.page), `overflow at ${width}`).toBeLessThanOrEqual(0);
  }
  expect(await intake.unreloaded()).toBe(true);
  expect(await intake.page.evaluate(() => [Object.keys(localStorage).filter((key) => /token|session|csrf/i.test(key)), sessionStorage.length])).toEqual([[], 0]);
  expect(cutter.errors).toEqual([]);
  expect(intake.errors).toEqual([]);
  await cutter.context.close();
  await intake.context.close();
});
