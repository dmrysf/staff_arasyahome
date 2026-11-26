import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// The dual-store operations (API 2.46.0, Staff 2.36.0) against the real Operations API, after the invoice spec. The TEST
// shop simulates FAN Courier: no AWB is ever made anywhere. Covers the shop's invoice manager making the AWB of an
// invoiced order at stage 12 (the collection shown and confirmed, then the label), the e-Factura follow-up and the health
// counts, at the phone widths 320 to 430 px (no horizontal scroll, 44 px targets) and WCAG 2.2 AA. Staff 2.60.0 (Store
// Workspace V1.1): the shop's invoice manager makes the AWB in the Store Workspace (the earlier AWB queue redirects there).
type Fixture = { password: string; manager: string; issuer: string; order: string; number: string };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "operations");
const PHONES = [320, 360, 375, 390, 414, 430] as const;
let F: Fixture;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-operations-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
});

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.locator("input[autocomplete=username]").fill(username);
  await page.locator("input[type=password]").fill(F.password);
  await page.locator("button[type=submit]").click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
  return { context, page, errors };
}

// `minimum`: 44 px on the earlier screens. 0 for the Store Workspace lists: axe's WCAG 2.2 AA target-size rule (24 px, with
// its spacing and inline exceptions, such as the order number link in a table cell) decides there; its buttons are 40 px.
async function accessible(page: Page, scope: string, screen: string, minimum = 44) {
  const results = await new AxeBuilder({ page }).include(`[data-testid=${scope}]`).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  if (minimum === 0) return;
  const small = await page.evaluate(({ selector, minimum: min }) => [...document.querySelectorAll<HTMLElement>(`${selector} button, ${selector} a[href], ${selector} input, ${selector} select`)]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0; })
    .map((element) => { const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: target.width, height: target.height, minimum: min }; })
    .filter((item) => item.width < item.minimum || item.height < item.minimum), { selector: `[data-testid=${scope}]`, minimum });
  expect(small, `${screen}: touch targets under ${minimum} px`).toEqual([]);
}

test("the shop's invoice manager makes the AWB of an invoiced order at stage 12 in the Store Workspace, then finds it in the AWB history (320 to 430 px)", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.manager, 320, 740);
  try {
    // Store Workspace V1.1 (Staff 2.60.0): the earlier AWB queue opens the store's "AWB și etichete" for a shop invoice manager.
    await page.goto("/confirmare/awb");
    await expect(page).toHaveURL(/\/magazin\/awb/);
    await expect(page.getByTestId("cf-ops")).toHaveCount(0);
    const row = page.locator(`[data-testid=sw-awb-row][data-order="${F.number}"]`);
    await expect(row.getByTestId("sw-awb-create")).toHaveText(/Emite AWB · 1 colet/);
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 740 });
      expect(await overflow(page), `${width} px: AWB list overflow`).toBeLessThanOrEqual(0);
    }
    await page.setViewportSize({ width: 320, height: 740 });
    await accessible(page, "sw-awb", "AWB list 320", 0);
    await page.screenshot({ path: path.join(SHOTS, "awb-form-320.png"), fullPage: true });
    await row.getByTestId("sw-awb-create").click();
    await expect(row.locator(".sw-pill-green")).toContainText("AWB", { timeout: 20_000 });
    await expect(row.getByTestId("sw-awb-label")).toBeVisible();
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 740 });
      expect(await overflow(page), `${width} px: AWB made overflow`).toBeLessThanOrEqual(0);
    }
    await page.screenshot({ path: path.join(SHOTS, "awb-made-320.png"), fullPage: true });
    // The AWB is listed in the store's AWB history.
    await page.getByTestId("sw-awb-tabs-history").click();
    await expect(page.getByTestId("sw-awb-history")).toContainText(F.number, { timeout: 20_000 });
    // The AWB history is a laptop table (the Store Workspace's contract: 1280×720 to 1920×1080, see the store spec).
    await page.setViewportSize({ width: 1280, height: 720 });
    expect(await overflow(page), "1280 px: AWB history overflow").toBeLessThanOrEqual(0);
    await accessible(page, "sw-awb-history", "AWB history 1280", 0);
    expect(errors).toEqual([]);
  } finally {
    await context.close();
  }
});

test("e-Factura: the invoice of the order is followed, due in 5 working days, and recorded as transmitted (320 to 430 px)", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.manager, 360, 740);
  try {
    await page.goto("/confirmare/efactura");
    const task = page.getByTestId(`cf-efactura-${F.number}`);
    await expect(task).toBeVisible();
    await expect(task).toContainText("De verificat");
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 740 });
      expect(await overflow(page), `${width} px: e-Factura overflow`).toBeLessThanOrEqual(0);
    }
    await page.setViewportSize({ width: 320, height: 740 });
    await accessible(page, "cf-efactura", "e-Factura 320");
    await page.getByTestId(`cf-efactura-${F.number}-sent`).click();
    await expect(page.getByText(`#${F.number}: Transmisă, fără răspuns ANAF înregistrat.`)).toBeVisible();
    await expect(page.getByTestId(`cf-efactura-${F.number}`)).toContainText("Transmisă, fără răspuns ANAF");
    await page.screenshot({ path: path.join(SHOTS, "efactura-320.png"), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    await context.close();
  }
});

test("health: counts per shop only, readable on a phone (320 to 430 px)", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.manager, 390, 844);
  try {
    await page.goto("/confirmare/health");
    const shop = page.getByTestId("cf-health-trendhome");
    await expect(shop).toBeVisible();
    await expect(page.getByTestId("cf-health-outletperdele")).toHaveCount(0);
    await expect(page.getByTestId("cf-health-trendhome-awbWaiting")).toBeVisible();
    expect(await shop.textContent()).not.toContain(F.number);
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 844 });
      expect(await overflow(page), `${width} px: health overflow`).toBeLessThanOrEqual(0);
    }
    await page.setViewportSize({ width: 320, height: 844 });
    await accessible(page, "cf-health", "health 320");
    await page.screenshot({ path: path.join(SHOTS, "health-320.png"), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    await context.close();
  }
});
