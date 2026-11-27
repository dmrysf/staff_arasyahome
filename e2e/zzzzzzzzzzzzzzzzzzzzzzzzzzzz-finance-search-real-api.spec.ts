import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Finance Smart Search V1 (Staff 2.62.0, API 2.85.0) against the real Operations API: one search box on the Contabilitate
// desk, the customer next to the order, no status column, name / amount / sum / bank text searches with their reasons, a
// payment already recorded, "Toate comenzile" and the way back from a task with the search kept. SYNTHETIC customers and
// amounts only (operations-api/tests/e2e-finance-search-fixture.php). Nothing is recorded: no DA / NU is pressed. Laptop
// sizes and browser zoom (a 1280 px window at 110 % and 125 % is 1164 and 1024 CSS px wide) never scroll sideways.
type Fixture = { password: string; finance: string; orders: Record<string, string> };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "finance-search");
const LAPTOPS = [[1280, 720], [1366, 768], [1440, 900], [1536, 864], [1920, 1080], [1164, 655], [1024, 576]] as const;
let F: Fixture;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const tableOverflow = (page: Page) => page.evaluate(() => Math.max(0, ...[...document.querySelectorAll(".fd-table")].map((t) => t.scrollWidth - t.clientWidth)));

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-finance-search-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
});

async function signIn(browser: Browser) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.locator("input[autocomplete=username]").fill(F.finance);
  await page.locator("input[type=password]").fill(F.password);
  await page.locator("button[type=submit]").click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
  await page.goto("/confirmare/finance/requests");
  await expect(page.getByTestId("fd-table")).toBeVisible();
  return { context, page, errors };
}

async function shot(page: Page, name: string, sizes: readonly (readonly [number, number])[] = [[1280, 720]]) {
  for (const [width, height] of sizes) {
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(120);
    expect(await overflow(page), `${name} at ${width}x${height} scrolls sideways`).toBeLessThanOrEqual(0);
    expect(await tableOverflow(page), `${name} at ${width}x${height}: the table is cut`).toBeLessThanOrEqual(1);
    await page.screenshot({ path: path.join(SHOTS, `${name}-${width}x${height}.png`), fullPage: false });
  }
  await page.setViewportSize({ width: 1280, height: 720 });
}

async function search(page: Page, text: string) {
  await page.getByTestId("fd-search").fill(text);
  await page.waitForTimeout(80);
}

const rowOrder = (page: Page) => page.locator('[data-testid="fd-table"] [data-testid^="fr-card-"]').evaluateAll((rows) => rows.map((row) => row.getAttribute("data-testid")?.replace("fr-card-", "")));

test("the desk: the customer next to the order, no status column, one search box (laptops and zoom)", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    await expect(page.getByTestId("fd-client-88001")).toHaveText("Ali Demir");
    await expect(page.locator(".fd-row-head")).not.toContainText(/Stare/);
    await expect(page.locator(".fd-row-head")).toContainText("Comandă / Client");
    await expect(page.getByTestId("fd-search")).toHaveAttribute("placeholder", "Caută după nume, sumă, comandă sau proformă...");
    await expect(page.getByTestId("fd-lookup")).toHaveCount(0);
    for (const kpi of ["todo", "waiting", "clarification", "today"]) await expect(page.getByTestId(`fd-kpi-${kpi}`)).toBeVisible();
    const long = page.getByTestId("fd-client-88016");
    expect(await long.evaluate((node) => node.scrollWidth > node.clientWidth || node.getBoundingClientRect().height < 30), "a long name is cut with an ellipsis on one line").toBe(true);
    await shot(page, "01-desk", LAPTOPS);
    await page.getByTestId("fd-table").screenshot({ path: path.join(SHOTS, "02-names-table.png") });
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("names, family names, amounts, tolerance, a name with an amount, another payer, a sum and a bank description", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    await search(page, "Popescu Maria");
    await expect(page.getByTestId("fd-why-88007")).toHaveText("Nume identic");
    await shot(page, "03-name");
    await search(page, "Tabaras Mariana");
    await expect(page.getByTestId("fd-why-88006")).toHaveText("Nume identic");
    await search(page, "Maria Popesku");
    await expect(page.getByTestId("fd-why-88007")).toHaveText("Nume similar");
    await search(page, "Demir");
    expect((await rowOrder(page)).slice(0, 2).sort()).toEqual(["88001", "88002"]);
    await expect(page.getByTestId("fd-why-88001")).toHaveText("Același nume de familie");
    await shot(page, "04-family-name");
    await search(page, "610");
    await expect(page.getByTestId("fd-amount")).toContainText("610,00");
    await expect(page.getByTestId("fd-tolerance")).toBeVisible();
    expect((await rowOrder(page))[0]).toBe("88003");
    await expect(page.getByTestId("fd-diff-88003")).toContainText("0,00");
    await expect(page.getByTestId("fd-diff-88001")).toContainText("10,00");
    await shot(page, "05-amount");
    await page.getByTestId("fd-tol-0").click();
    expect(await rowOrder(page)).toEqual(["88003"]);
    await page.getByTestId("fd-tol-5000").click();
    await expect(page.getByTestId("fd-tol-5000")).toHaveAttribute("aria-pressed", "true");
    await shot(page, "06-tolerance");
    await page.getByTestId("fd-tol-1000").click();
    await search(page, "Demir 610");
    expect((await rowOrder(page)).slice(0, 3)).toEqual(["88002", "88001", "88003"]);
    await shot(page, "07-name-amount");
    // Scenario A and B (owner): another payer of the same family, near amounts, before the amount alone.
    await search(page, "Emil Demir 610");
    expect((await rowOrder(page)).slice(0, 3)).toEqual(["88002", "88001", "88003"]);
    await expect(page.getByTestId("fd-why-88001")).toHaveText("Același nume de familie · Sumă apropiată");
    await expect(page.getByTestId("fd-why-88003")).toHaveText("Doar suma corespunde — necesită verificare");
    await shot(page, "08-other-payer");
    await search(page, "Lungu 150 + 50");
    await expect(page.getByTestId("fd-sum")).toHaveText(/Total căutat: 200,00\sRON/);
    await expect(page.getByTestId("fd-diff-88005")).toContainText("1,01");
    await shot(page, "09-sum");
    await search(page, "Transfer Emil Demir Avans Ali Demir ARS 0931 610 RON");
    expect((await rowOrder(page))[0]).toBe("88001");
    await expect(page.getByTestId("fd-why-88001")).toContainText("Proformă exactă");
    await shot(page, "10-bank-text");
    // Scenario G: seven equal advances first (exact), the 50.00 advance left 4,88 RON away comes after them within ±10 RON.
    await search(page, "54,88");
    const near = await rowOrder(page);
    expect(near.slice(0, 7).sort()).toEqual(["88008", "88009", "88010", "88011", "88012", "88013", "88014"]);
    await page.getByTestId("fd-tol-0").click();
    expect((await rowOrder(page)).length).toBe(7);
    await page.getByTestId("fd-tol-1000").click();
    await search(page, "50 lei");
    await expect(page.getByTestId("fd-why-88015")).toContainText("avans rămas");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("a payment already recorded, all orders, Enter on an exact order, and the way back keeps the search", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    // Nothing in the current list: the results of all orders come by themselves.
    await search(page, "Zamfirescu");
    await expect(page.getByTestId("fd-lookup")).toBeVisible();
    await expect(page.getByTestId("fd-lookup-none")).toBeVisible();
    // A payment already recorded (a closed task) is found in all orders and says so.
    await search(page, "Sorin Popescu 150");
    await page.getByTestId("fd-scope-all").click();
    await expect(page.getByTestId("fd-lookup")).toBeVisible();
    await expect(page.getByTestId("fd-lookup-why-88018")).toHaveText("Plată deja înregistrată · Nume identic · Sumă exactă");
    await shot(page, "11-recorded");
    await search(page, "Cosmin Vlad");
    await expect(page.getByTestId("fd-lookup-88019")).toBeVisible();
    await expect(page.getByTestId("fd-lookup-why-88019")).toHaveText("Nume identic");
    await shot(page, "12-all-orders");
    await page.getByTestId("fd-scope-current").click();
    // Enter never picks one of many; an exact order of her list opens its check.
    await search(page, "54,88");
    await page.getByTestId("fd-search").press("Enter");
    await expect(page).toHaveURL(/\/confirmare\/finance\/requests$/);
    await search(page, "Demir 610");
    await page.getByTestId("fr-open-88001").click();
    await expect(page.getByTestId("fd-task-client")).toContainText("Ali Demir");
    await shot(page, "13a-check-with-name");
    await page.locator(".back-link").first().click();
    await expect(page.getByTestId("fd-search")).toHaveValue("Demir 610");
    await expect(page.getByTestId("fr-card-88001")).toBeVisible();
    expect((await rowOrder(page)).slice(0, 2)).toEqual(["88002", "88001"]);
    await shot(page, "13-back-to-results");
    await search(page, "88007");
    await page.getByTestId("fd-search").press("Enter");
    await expect(page.getByTestId("fd-task-client")).toContainText("Maria Popescu");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});
