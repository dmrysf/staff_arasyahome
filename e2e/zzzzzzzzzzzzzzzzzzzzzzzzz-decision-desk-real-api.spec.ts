import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Operation Simplification V1 (API 2.82.0, Staff 2.57.0) against the real Operations API and the loopback TEST shop, after
// every earlier suite: the operator desk (four lists with counts, shops from the server, customer, total, products, waiting
// time), the full-page order editor with the sticky money header on laptop screens (1280x800, 1366x768, 1440x900,
// 1920x1080, no horizontal scroll), the three decisions, each one click (yellow and red without any form, green without a
// call record, on the total shown) with the undo before the system acts, and the finance employee's search of any order. Generic TEST
// people and orders only (tests/e2e-confirmare-fixture.php --desk).
type Desk = { orders: { first: string; second: string; third: string } };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "decision-desk");
const PASSWORD = "e2e passphrase 2026";
const LAPTOPS = [[1280, 800], [1366, 768], [1440, 900], [1920, 1080]] as const;
const php = (args: string[]) => execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-confirmare-fixture.php"), ...args], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
const number = (globalId: string) => globalId.split(":")[1];
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
let D: Desk;

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  // Standalone (the screenshots run) the main seed creates the people; after the Confirmare spec they exist already.
  try { php([]); } catch { /* seeded by the Confirmare spec */ }
  D = JSON.parse(php(["--desk"]).trim().split("\n").pop() ?? "{}") as Desk;
});

async function signIn(browser: Browser, username: string, width = 1366, height = 768) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(PASSWORD);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByTestId("dd-desk").or(page.getByTestId("fr-inbox")).or(page.getByRole("heading", { name: /^Bună,/ }))).toBeVisible();
  return { context, page, errors };
}

async function shoot(page: Page, name: string) {
  mkdirSync(SHOTS, { recursive: true });
  for (const [width, height] of LAPTOPS) {
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(150);
    expect(await overflow(page), `${name} at ${width}x${height} scrolls sideways`).toBeLessThanOrEqual(0);
    await page.screenshot({ path: path.join(SHOTS, `${name}-${width}x${height}.png`) });
  }
  await page.setViewportSize({ width: 1366, height: 768 });
}

test("the operator desk: four lists, counts, the customer and the waiting time; no shop name written into the screen", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    await expect(page.getByTestId("dd-desk")).toBeVisible();
    await expect(page.getByTestId("dd-view-new")).toContainText(/\d/);
    const row = page.getByTestId(`dd-row-${number(D.orders.first)}`);
    await expect(row).toBeVisible();
    await expect(row).toContainText("TEST Client Confirmare");
    await expect(row).toContainText(/min|h|z/);
    await expect(page.getByTestId("dd-shop-trendhome")).toBeVisible();
    await page.getByTestId("dd-shop-trendhome").click();
    await expect(page.getByTestId("dd-shop-trendhome")).toHaveAttribute("aria-pressed", "true");
    await expect(row).toBeVisible();
    const axe = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]).analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);
    await shoot(page, "desk");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the full-page editor: the money stays on screen while scrolling; yellow needs nothing; the order waits", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    await page.getByTestId(`dd-row-${number(D.orders.first)}`).click();
    await expect(page.getByTestId("dd-work")).toBeVisible();
    await expect(page.getByTestId("dd-sticky")).toBeVisible();
    await expect(page.getByTestId("dd-total")).toContainText(/\d/);
    await expect(page.getByTestId("dd-advance")).toContainText("215,70");
    await expect(page.getByTestId("dd-ramburs-now")).toBeVisible();
    await expect(page.getByTestId("dd-green")).toBeEnabled();
    // No call record, no line ticks, no price box, no shipping choice: only the order and three decisions.
    await expect(page.getByText("Am vorbit cu clientul")).toHaveCount(0);
    await shoot(page, "order");
    await page.mouse.wheel(0, 3000);
    await page.waitForTimeout(200);
    const box = await page.getByTestId("dd-money").boundingBox();
    expect(box && box.y >= 0 && box.y < 140, "the money header stays at the top while scrolling").toBe(true);
    // Yellow: one click, nothing to fill in; a note or a callback time can be added afterwards (optional).
    await page.getByTestId("dd-yellow").click();
    await expect(page.getByTestId("dd-notice")).toContainText(number(D.orders.first));
    await expect(page.getByTestId("dd-waiting-panel")).toHaveCount(0);
    await page.getByTestId("dd-waiting-note").click();
    await page.getByTestId("dd-waiting-panel").getByRole("textbox", { name: /Notă/ }).fill("Revine după ora 17");
    await page.getByTestId("dd-waiting-save").click();
    await expect(page.getByTestId("dd-notice")).toContainText(number(D.orders.first));
    await page.getByTestId("dd-autonext").uncheck();
    await page.getByTestId("dd-back").click();
    await page.getByTestId("dd-view-waiting").click();
    await expect(page.getByTestId(`dd-row-${number(D.orders.first)}`)).toBeVisible();
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("green confirms on the order the screen showed, then it can be undone before the system acts; red needs no reason", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    await page.goto(`/confirmare/orders/${encodeURIComponent(D.orders.first)}`);
    await expect(page.getByTestId("dd-green")).toBeEnabled();
    await page.getByTestId("dd-green").click();
    await expect(page.getByTestId("dd-after")).toBeVisible();
    await expect(page.getByTestId("dd-notice")).toContainText(number(D.orders.first));
    await page.getByTestId("dd-autonext").uncheck();
    await expect(page.getByTestId("dd-undo")).toBeVisible();
    await page.getByTestId("dd-undo").click();
    await expect(page.getByTestId("dd-green")).toBeEnabled();
    await page.getByTestId("dd-green").click();
    await expect(page.getByTestId("dd-after")).toBeVisible();
    await page.goto(`/confirmare/orders/${encodeURIComponent(D.orders.second)}`);
    // Red: one click, no reason, no form; the undo stays for a wrong click.
    await page.getByTestId("dd-red").click();
    await expect(page.getByTestId("dd-after")).toBeVisible();
    await expect(page.getByTestId("dd-undo")).toBeVisible();
    await page.goto("/confirmare?view=refused");
    await expect(page.getByTestId(`dd-row-${number(D.orders.second)}`)).toBeVisible();
    await page.getByTestId("dd-view-confirmed").click();
    await expect(page.getByTestId(`dd-row-${number(D.orders.first)}`)).toBeVisible();
    await expect(page.getByTestId("dd-done-today")).toContainText(/[1-9]/);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("rapid edits ask the shop once: the 450 ms preview never sends one shop call per click", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    const previews: string[] = [];
    page.on("request", (request) => { if (request.url().includes("/source/preview")) previews.push(request.url()); });
    await page.goto(`/confirmare/orders/${encodeURIComponent(D.orders.third)}`);
    const plus = page.locator('[data-testid^="dd-qty-"][data-testid$="-plus"]').first();
    await expect(plus).toBeEnabled();
    for (let click = 0; click < 8; click++) { await plus.click(); await page.waitForTimeout(100); }
    await expect(page.getByTestId("dd-savebar")).toBeVisible();
    await page.waitForTimeout(1500);
    // Eight clicks within about a second: one preview for the final quantity (the debounce restarts on every click).
    expect(previews.length, `previews sent for 8 rapid clicks: ${previews.length}`).toBeLessThanOrEqual(1);
    await page.getByRole("button", { name: /Renunță|Discard/ }).click();
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("finance: any order of her shops is found by its number, with or without a task", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.finrec.e2e");
  try {
    await page.goto("/confirmare/finance/requests");
    await expect(page.getByTestId("fd-lookup")).toBeVisible();
    await page.getByTestId("fd-lookup-input").fill(number(D.orders.third));
    await page.getByTestId("fd-lookup-search").click();
    await expect(page.getByTestId(`fd-lookup-${number(D.orders.third)}`)).toBeVisible();
    await shoot(page, "finance-desk");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});
