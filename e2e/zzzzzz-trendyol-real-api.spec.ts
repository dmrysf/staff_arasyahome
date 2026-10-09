import { expect, test, type Browser, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

type Fixture = { password: string; trendyol: { package: string; orderNumber: string; ignored: string; approver: string; outsider: string; cutter: string } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const go = (page: Page, target: string) => page.evaluate((p) => { window.history.pushState({}, "", p); window.dispatchEvent(new PopStateEvent("popstate")); }, target);

test.describe.configure({ mode: "serial" });

async function signIn(browser: Browser, username: string, width = 390) {
  const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  return { context, page, errors };
}

test("an employee without Trendyol permission never reaches the Trendyol workspace", async ({ browser }) => {
  const outsider = await signIn(browser, fixture.trendyol.outsider);
  await expect(outsider.page.getByTestId("home-trendyol")).toHaveCount(0);
  await go(outsider.page, "/trendyol");
  await expect(outsider.page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  await expect(outsider.page.getByRole("heading", { name: "Comenzi Trendyol" })).toHaveCount(0);
  const denied = await outsider.page.evaluate(async () => (await fetch("http://127.0.0.1:8787/trendyol/overview", { credentials: "include" })).status);
  expect(denied).toBe(403);
  expect(outsider.errors).toEqual([]);
  await outsider.context.close();
});

test("Trendyol package: review, confirm measurements, explicit approval to stage 1 with QR and R1, then the existing hand-off to cutting", async ({ browser }) => {
  test.setTimeout(120_000);
  const approver = await signIn(browser, fixture.trendyol.approver, 360);
  const { page } = approver;
  await page.getByTestId("home-trendyol").click();
  await expect(page.getByRole("heading", { name: "Comenzi Trendyol" })).toBeVisible();
  await expect(page.getByTestId("trendyol-intake-state")).toContainText("Conexiune activă (numai citire).");
  await page.getByRole("tab", { name: /Ignorate/ }).click();
  await expect(page.getByLabel("Pachete ignorate")).toContainText(`Comanda #TY${fixture.trendyol.ignored}`);
  await expect(page.getByLabel("Pachete ignorate")).toContainText("istorică");
  await page.getByRole("tab", { name: /De pregătit/ }).click();
  await page.getByRole("button", { name: new RegExp(`Comanda #${fixture.trendyol.orderNumber}`) }).click();

  const detail = page.getByTestId("trendyol-package");
  await expect(detail.getByRole("heading", { name: `Comanda #${fixture.trendyol.orderNumber}` })).toBeVisible();
  await expect(detail).toContainText("Arasya nu modifică nimic în Trendyol");
  await expect(detail).not.toContainText("0744111222");
  const approve = page.getByRole("button", { name: "Aprobă și trimite în producție" });
  await expect(approve).toBeDisabled();
  expect(await overflow(page)).toBeLessThanOrEqual(0);

  const line = page.getByTestId("trendyol-line-1");
  await line.getByRole("button", { name: /Preia sugestia 300 × 260 cm/ }).click();
  await expect(line.getByLabel("Lățime (cm)")).toHaveValue("300");
  await line.getByLabel("Tip produs").selectOption("curtain");
  await line.getByLabel("Lățime (cm)").fill("298,5");
  await line.getByLabel(/Note pentru atelier/).fill("Rejansă 2x");
  await line.getByRole("button", { name: "Salvează linia 1" }).click();
  await expect(page.getByText("Linia 1 a fost salvată.")).toBeVisible();
  await expect(line).toContainText("Confirmat de Ilinca Trendyol");

  await page.setViewportSize({ width: 320, height: 760 });
  expect(await overflow(page)).toBeLessThanOrEqual(0);
  await page.getByLabel(/Confirm că am verificat produsele și măsurile/).check();
  await approve.click();
  await expect(page.getByText("Comanda a intrat în producție la etapa 1.")).toBeVisible();
  const production = page.getByTestId("trendyol-production");
  await expect(production).toContainText("În așteptare");
  await expect(production).toContainText("Activă (cu cod QR Arasya)");
  expect(await overflow(page)).toBeLessThanOrEqual(0);

  // The canonical document (revision 1) is the existing production document screen.
  await production.getByRole("button", { name: "Fișa de producție" }).click();
  await expect(page.getByRole("heading", { name: "REVIZIA 1" })).toBeVisible();
  await go(page, `/trendyol/${fixture.trendyol.package}`);
  await page.getByTestId("trendyol-production").getByRole("button", { name: /Deschide comanda/ }).click();

  // Stage 1 -> stage 2 with the ordinary Staff claim and stage completion.
  await expect(page.locator(".detail-hero")).toContainText("În așteptare");
  await page.getByRole("button", { name: /Preia comanda/ }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Confirmă" }).click();
  await expect(page.getByText("Comanda este la tine")).toBeVisible();
  await expect(page.locator(".detail-products")).toContainText("298,5 × 260 cm");
  await page.getByRole("button", { name: /Finalizează etapa/ }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Confirmă" }).click();
  await expect(page.locator(".detail-hero")).toContainText("Tăiere");
  expect(approver.errors).toEqual([]);
  await approver.context.close();

  // From stage 2 on, the order is ordinary cutting work for the cutting stage.
  const cutter = await signIn(browser, fixture.trendyol.cutter);
  const visible = await cutter.page.evaluate(async (id) => (await fetch(`http://127.0.0.1:8787/orders/${encodeURIComponent(id)}`, { credentials: "include" })).status, `trendyol:${fixture.trendyol.package}`);
  expect(visible).toBe(200);
  await cutter.context.close();
});
