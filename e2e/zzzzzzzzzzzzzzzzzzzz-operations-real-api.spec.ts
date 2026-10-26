import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// The dual-store operations (API 2.46.0, Staff 2.36.0) against the real Operations API, after the invoice spec. The TEST
// shop simulates FAN Courier: no AWB is ever made anywhere. Covers the shop's invoice manager making the AWB of an
// invoiced order at stage 12 (the collection shown and confirmed, then the label), the e-Factura follow-up and the health
// counts, at the phone widths 320 to 430 px (no horizontal scroll, 44 px targets) and WCAG 2.2 AA.
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

async function accessible(page: Page, scope: string, screen: string) {
  const results = await new AxeBuilder({ page }).include(`[data-testid=${scope}]`).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate((selector) => [...document.querySelectorAll<HTMLElement>(`${selector} button, ${selector} a[href], ${selector} input, ${selector} select`)]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0; })
    .map((element) => { const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: target.width, height: target.height }; })
    .filter((item) => item.width < 44 || item.height < 44), `[data-testid=${scope}]`);
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

test("the shop's invoice manager makes the AWB of an invoiced order at stage 12: the collection once, then the label (320 to 430 px)", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.manager, 320, 740);
  try {
    await page.goto("/confirmare/awb");
    const row = page.getByTestId(`cf-awb-row-${F.number}`);
    await expect(row).toBeVisible();
    await row.click();
    const panel = page.getByTestId("cf-ops");
    await expect(panel.getByTestId("cf-ops-awb-cod")).toContainText("0,00");
    await expect(panel.getByTestId("cf-awb-create")).toBeDisabled();
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 740 });
      expect(await overflow(page), `${width} px: AWB queue overflow`).toBeLessThanOrEqual(0);
    }
    await page.setViewportSize({ width: 320, height: 740 });
    await accessible(page, "cf-awb-queue", "AWB form 320");
    await page.screenshot({ path: path.join(SHOTS, "awb-form-320.png"), fullPage: true });
    await panel.getByTestId("cf-awb-parcels").fill("2");
    await panel.getByTestId("cf-awb-cod-seen").check();
    await panel.getByTestId("cf-awb-create").click();
    await expect(panel.getByTestId("cf-ops-result")).toContainText("AWB");
    await expect(panel.getByTestId("cf-ops-awb-state")).toContainText("AWB 2150900000");
    await expect(panel.getByTestId("cf-awb-label")).toBeVisible();
    const [download] = await Promise.all([page.waitForEvent("download"), panel.getByTestId("cf-awb-label").click()]);
    expect(download.suggestedFilename()).toMatch(/^awb-2150900000\d+\.pdf$/);
    await expect(panel.getByTestId("cf-awb-address-task")).toBeVisible();
    for (const width of PHONES) {
      await page.setViewportSize({ width, height: 740 });
      expect(await overflow(page), `${width} px: AWB made overflow`).toBeLessThanOrEqual(0);
    }
    await page.screenshot({ path: path.join(SHOTS, "awb-made-320.png"), fullPage: true });
    // The order leaves the queue once its AWB exists (it stayed open on screen for the label).
    await page.reload();
    await expect(page.getByTestId(`cf-awb-row-${F.number}`)).toHaveCount(0);
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
