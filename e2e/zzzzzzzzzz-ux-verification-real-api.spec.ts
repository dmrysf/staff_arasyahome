import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

// UX verification of the redesigned Staff against the real Operations API (Package 5): WCAG 2.2 AA (axe), touch
// targets, keyboard bypass, reduced motion, slow network, API failure and field-level validation. Reads only,
// except one new Trendyol package written through the real intake store, as the server-side sync would.
type Fixture = { password: string; users: { ana: string }; orders: { flow: string }; trendyol: { approver: string } };
const root = path.resolve(import.meta.dirname, "..");
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByTestId("workspace-title")).toBeVisible();
  return { context, page, errors };
}

async function accessible(page: Page, screen: string) {
  await page.waitForTimeout(300);
  const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>("button, a[href], input:not([type=hidden]), select, textarea, summary, [role=tab]")]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0 && getComputedStyle(element).visibility !== "hidden" && !element.closest("[aria-hidden=true]"); })
    .map((element) => { const own = element.getBoundingClientRect(); const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: Math.max(own.width, target.width), height: Math.max(own.height, target.height) }; })
    .filter((item) => item.width < 44 || item.height < 44)
    .map((item) => `${item.name} ${Math.round(item.width)}×${Math.round(item.height)}`));
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

test.describe.configure({ mode: "serial" });

test("WCAG 2.2 AA and 44 px touch targets on the redesigned screens", async ({ browser }) => {
  test.setTimeout(150_000);
  const anonymous = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const login = await anonymous.newPage();
  await login.goto("/login");
  await expect(login.getByLabel("Nume utilizator")).toBeVisible();
  await accessible(login, "login");
  await anonymous.close();

  const trendyol = await signIn(browser, fixture.trendyol.approver);
  await accessible(trendyol.page, "trendyol home");
  await trendyol.page.getByTestId("home-trendyol").click();
  await expect(trendyol.page.getByRole("heading", { name: "Comenzi Trendyol" })).toBeVisible();
  await accessible(trendyol.page, "trendyol inbox");
  await trendyol.page.goto("/profile");
  await accessible(trendyol.page, "profile");
  expect(trendyol.errors).toEqual([]);
  await trendyol.context.close();

  const cutting = await signIn(browser, fixture.users.ana);
  await accessible(cutting.page, "cutting home");
  await cutting.page.goto("/orders");
  await accessible(cutting.page, "orders");
  await cutting.page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.orders.flow}`)}`);
  await expect(cutting.page.getByRole("heading", { level: 1 })).toContainText(`#${fixture.orders.flow}`);
  await accessible(cutting.page, "order detail");
  await cutting.page.goto("/history");
  await accessible(cutting.page, "history");
  await cutting.page.goto("/scan");
  await expect(cutting.page.getByRole("button", { name: "Deschide camera" })).toBeVisible();
  await accessible(cutting.page, "scanner");
  expect(cutting.errors).toEqual([]);
  await cutting.context.close();

  const desktop = await signIn(browser, fixture.users.ana, 1280, 800);
  await accessible(desktop.page, "cutting home desktop");
  await desktop.context.close();
});

test("brand logos load, keep their proportions and never shift the login layout", async ({ browser }) => {
  for (const [width, height] of [[320, 568], [390, 844], [768, 1024], [1440, 900]] as const) {
    const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
    const page = await context.newPage();
    await page.addInitScript(() => {
      const state = window as unknown as { __shift: number };
      state.__shift = 0;
      new PerformanceObserver((list) => { for (const entry of list.getEntries() as unknown as { value: number; hadRecentInput: boolean }[]) if (!entry.hadRecentInput) state.__shift += entry.value; }).observe({ type: "layout-shift", buffered: true });
    });
    await page.goto("/login");
    const logo = page.getByRole("img", { name: "Arasya Home" });
    await expect(logo).toBeVisible();
    await expect.poll(() => logo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
    const box = await logo.evaluate((img: HTMLImageElement) => ({ ratio: img.getBoundingClientRect().width / img.getBoundingClientRect().height, natural: img.naturalWidth / img.naturalHeight }));
    expect(Math.abs(box.ratio - box.natural), `logo proportions at ${width}`).toBeLessThan(0.05);
    expect(await page.evaluate(() => (window as unknown as { __shift: number }).__shift), `layout shift at ${width}`).toBeLessThan(0.01);
    await context.close();
  }
  const { context, page } = await signIn(browser, fixture.trendyol.approver);
  const trendyol = page.getByTestId("trendyol-connection").locator(".brand-logo img");
  await expect.poll(() => trendyol.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  await context.close();
});

test("every sign-in plays the intro, even with reduced motion, then opens Staff; signing in again plays it again", async ({ browser }) => {
  test.setTimeout(60_000);
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: "reduce" });
  // Automation is exempt by default (navigator.webdriver); this test signs in as a real browser does.
  await context.addInitScript(() => Object.defineProperty(Navigator.prototype, "webdriver", { get: () => false }));
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  for (let round = 1; round <= 2; round += 1) {
    await page.goto("/login");
    await page.getByLabel("Nume utilizator").fill(fixture.users.ana);
    await page.getByLabel("Parolă").fill(fixture.password);
    await page.getByRole("button", { name: /Autentificare/ }).click();
    const intro = page.getByTestId("login-intro");
    await expect(intro).toBeVisible();
    await expect(page.locator(".intro-stage")).toHaveAttribute("inert", "");
    await expect(intro.getByRole("button")).toHaveCount(0);
    await expect(intro.locator(".intro-text")).toHaveText("Designed to Inspire. Made to Last.", { timeout: 6_000 });
    await expect(intro).toHaveCount(0, { timeout: 10_000 });
    await expect(page.locator(".intro-stage")).not.toHaveAttribute("data-intro", /./);
    await expect(page.getByTestId("workspace-title")).toBeVisible();
    await page.getByRole("button", { name: "Profil", exact: true }).click();
    await page.getByRole("button", { name: "Ieși din cont" }).click();
    await expect(page.getByLabel("Nume utilizator")).toBeVisible();
  }
  expect(errors).toEqual([]);
  await context.close();
});

test("keyboard: the first Tab offers the skip link, which moves focus to the work", async ({ browser }) => {
  const { context, page } = await signIn(browser, fixture.users.ana, 1280, 800);
  await page.locator("body").focus();
  await page.keyboard.press("Tab");
  const skip = page.getByRole("link", { name: "Sari la conținut" });
  await expect(skip).toBeFocused();
  await expect(skip).toBeInViewport();
  await page.keyboard.press("Enter");
  await expect(page.locator("#content")).toBeFocused();
  await page.keyboard.press("Tab");
  expect(await page.evaluate(() => Boolean(document.activeElement?.closest("#content")))).toBe(true);
  await context.close();
});

test("reduced motion: lists, numbers and the scanner do not move", async ({ browser }) => {
  const { context, page } = await signIn(browser, fixture.users.ana);
  await expect(page.getByTestId("stage-next-action")).toBeVisible();
  const durations = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>(".queue-list > li, .metric-number, .next-action, .app-content > *")]
    .map((element) => { const style = getComputedStyle(element); return parseFloat(style.animationDuration) + parseFloat(style.animationDelay); }));
  expect(durations.length).toBeGreaterThan(0);
  for (const total of durations) expect(total).toBeLessThan(0.01);
  await context.close();
});

test("slow network shows content-shaped loading; a failed read shows a recoverable error, not an empty list", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, fixture.users.ana);
  await page.route(`${API}/orders/mine**`, async (route) => { await new Promise((resolve) => setTimeout(resolve, 1500)); await route.continue(); });
  await page.getByRole("button", { name: "Comenzi", exact: true }).click();
  await expect(page.getByRole("status").filter({ hasText: "Se încarcă comenzile…" })).toBeVisible();
  await expect(page.locator(".loading-state .skeleton-card").first()).toBeVisible();
  await expect(page.getByRole("heading", { name: "Comenzile mele" })).toBeVisible();
  await expect(page.locator(".loading-state")).toHaveCount(0, { timeout: 10_000 });
  await page.unrouteAll({ behavior: "wait" });

  await page.route(`${API}/orders/mine**`, (route) => route.abort("failed"));
  await page.getByRole("button", { name: "Acasă", exact: true }).click();
  await page.getByRole("button", { name: "Comenzi", exact: true }).click();
  const failure = page.getByRole("alert").filter({ hasText: "Nu am putut continua" });
  await expect(failure).toBeVisible();
  await expect(page.getByText("Nu ai comenzi active.")).toHaveCount(0);
  await page.unrouteAll({ behavior: "wait" });
  await failure.getByRole("button").click();
  await expect(page.getByRole("tablist", { name: "Filtrare comenzi" })).toBeVisible();
  await expect(failure).toHaveCount(0);
  expect(errors).toEqual([]);
  await context.close();
});

test("package validation appears next to the field and moves focus there; nothing is saved", async ({ browser }) => {
  const packageId = "74090";
  execFileSync("php", [path.join(root, "operations-api", "tests", "fixtures", "e2e-trendyol-import.php"), packageId], { env: process.env, encoding: "utf8" });
  const { context, page, errors } = await signIn(browser, fixture.trendyol.approver);
  const writes: string[] = [];
  page.on("request", (request) => { if (request.url().startsWith(API) && request.method() !== "GET") writes.push(`${request.method()} ${new URL(request.url()).pathname}`); });
  await page.goto(`/trendyol/${packageId}`);
  const line = page.getByTestId("trendyol-line-1");
  await line.getByRole("button", { name: "Salvează linia 1" }).click();
  await expect(line.getByRole("radio", { name: "Perdea" })).toBeFocused();
  const kindError = line.getByRole("alert").filter({ hasText: "Alege tipul produsului." });
  await expect(kindError).toBeVisible();
  expect(await kindError.evaluate((element) => element.previousElementSibling?.classList.contains("kind-picker"))).toBe(true);

  await line.getByRole("radio", { name: "Draperie" }).check();
  // The title may be prefilled from the title approved for this product code; an empty title is refused.
  await line.getByLabel(/Denumire de producție/).fill("");
  await line.getByRole("button", { name: "Salvează linia 1" }).click();
  await expect(line.getByLabel(/Denumire de producție/)).toBeFocused();
  await expect(line.getByRole("alert").filter({ hasText: "Scrie denumirea de producție în limba română" })).toBeVisible();
  await line.getByLabel(/Denumire de producție/).fill("Draperie blackout gri");
  await line.getByLabel("Lățime (cm)").fill("abc");
  await line.getByRole("button", { name: "Salvează linia 1" }).click();
  await expect(line.getByLabel("Lățime (cm)")).toBeFocused();
  await expect(line.getByLabel("Lățime (cm)")).toHaveAttribute("aria-invalid", "true");
  await expect(line.getByRole("alert").filter({ hasText: "Măsurile sunt numere pozitive" })).toBeVisible();
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
  await context.close();
});
