import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Store Workspace V1 / V1.1 (API 2.83.1, Staff 2.60.0) against the real Operations API, after the Confirmare, invoice and
// operations specs. TEST ONLY: invented customers; the TEST shop simulates FAN Courier (no AWB is made or cancelled
// anywhere real). The shop employee works on a laptop: 1280×720 to 1920×1080 at 100/110/125 % zoom (the CSS viewport of a
// 1280 px screen at 125 % is 1024 px), never a horizontal page scroll. Screenshots go to e2e/.runtime/store.
type Fixture = { password: string; username: string; docs: string[]; printed: string; check: string; ready: string; address: string; blocked: string; card: string; history: string };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "store");
const LAPTOPS = [[1280, 720], [1366, 768], [1920, 1080], [1164, 655], [1024, 576]] as const; // 100 %, 100 %, 100 %, 110 %, 125 %
let F: Fixture;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const shot = (page: Page, name: string) => page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-store-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
});

async function signIn(browser: Browser, width = 1366, height = 768) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.locator("input[autocomplete=username]").fill(F.username);
  await page.locator("input[type=password]").fill(F.password);
  await page.locator("button[type=submit]").click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
  return { context, page, errors };
}

async function accessible(page: Page, scope: string, screen: string) {
  const results = await new AxeBuilder({ page }).include(`[data-testid=${scope}]`).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
}

async function noOverflow(page: Page, screen: string) {
  for (const [width, height] of LAPTOPS) {
    await page.setViewportSize({ width, height });
    expect(await overflow(page), `${screen} at ${width}×${height}: no horizontal page scroll`).toBeLessThanOrEqual(0);
  }
  await page.setViewportSize({ width: 1366, height: 768 });
}

test("home: three sections with the day's real counters; documents: prepare and print, reprint with a reason", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    await expect(page.getByTestId("sw-home")).toBeVisible();
    await expect(page.getByTestId("sw-section-documents")).toBeVisible();
    await expect(page.getByTestId("sw-section-awb")).toBeVisible();
    await expect(page.getByTestId("sw-section-search")).toBeVisible();
    await expect(page.getByTestId("sw-c-docs-waiting").locator(".sw-counter-value")).not.toHaveText("—");
    await expect(page.getByTestId("sw-c-awb-waiting").locator(".sw-counter-value")).not.toHaveText("—");
    const size = await page.getByTestId("sw-c-awb-waiting").locator(".sw-counter-value").evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(size).toBeGreaterThanOrEqual(30);
    expect(size).toBeLessThanOrEqual(34);
    await accessible(page, "sw-home", "home");
    await noOverflow(page, "home");
    await shot(page, "01-home");

    await page.getByTestId("sw-go-documents").click();
    await expect(page.getByTestId("sw-documents")).toBeVisible();
    const rows = page.getByTestId("sw-doc-row");
    await expect(rows.first()).toBeVisible();
    await expect(page.getByTestId("sw-docs-todo")).toContainText(F.docs[0]);
    await accessible(page, "sw-documents", "documents");
    await noOverflow(page, "documents");
    await shot(page, "02-documents-to-print");

    const row = rows.filter({ hasText: F.docs[0] });
    await row.getByTestId("sw-doc-prepare").click();
    await expect(page.getByTestId("sw-docs-flash")).toContainText(`#${F.docs[0]}`, { timeout: 20_000 });
    await expect(page.getByTestId("sw-docs-todo")).not.toContainText(F.docs[0]);
    // V1.1: the final invoice follows the document by itself (the order is routed to this employee); no SmartBill panel.
    await expect(page.getByTestId("sw-docs-flash")).toContainText(/Factura finală .*emisă/, { timeout: 20_000 });
    await expect(page.getByTestId("cf-invoice")).toHaveCount(0);
    await shot(page, "03-document-prepared-and-printed");

    await page.getByTestId("sw-docs-tabs-today").click();
    await expect(page.getByTestId("sw-docs-today")).toContainText(F.printed);
    await shot(page, "04-documents-printed-today");
    await page.getByTestId("sw-docs-today").locator("tr", { hasText: F.printed }).first().getByTestId("sw-doc-reprint").click();
    const dialog = page.getByTestId("sw-reprint-dialog");
    await expect(dialog).toBeVisible();
    await expect(page.getByTestId("sw-reprint-confirm")).toBeDisabled();
    await dialog.getByLabel("Eroare imprimantă").check();
    await shot(page, "05-reprint-reason");
    await page.getByTestId("sw-reprint-confirm").click();
    await expect(page.getByTestId("sw-docs-flash")).toContainText(`#${F.printed}`, { timeout: 20_000 });
    await expect(page.getByTestId("sw-docs-today").locator("tr", { hasText: "Eroare imprimantă" }).first()).toBeVisible({ timeout: 20_000 });
    await page.getByTestId("sw-docs-tabs-history").click();
    await expect(page.getByTestId("sw-docs-history")).toBeVisible();
    await shot(page, "06-documents-history");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("AWB: real blockers, parcels stepper, one-click AWB, correction at FAN, address correction, history linked", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    await page.goto("/magazin/awb");
    await expect(page.getByTestId("sw-awb-list")).toBeVisible();
    const ready = page.locator(`[data-testid=sw-awb-row][data-order="${F.ready}"]`);
    const address = page.locator(`[data-testid=sw-awb-row][data-order="${F.address}"]`);
    const blocked = page.locator(`[data-testid=sw-awb-row][data-order="${F.blocked}"]`);
    await expect(ready.getByTestId("sw-awb-create")).toHaveText(/Emite AWB · 1 colet/);
    await expect(address.getByTestId("sw-awb-fix-address")).toBeVisible();
    await expect(blocked.getByTestId("sw-awb-blocker")).toContainText("Factura finală");
    await expect(ready).toContainText("350,00");
    await accessible(page, "sw-awb", "awb");
    await noOverflow(page, "awb");
    await shot(page, "07-awb-to-create");

    const stepper = ready.getByTestId("sw-parcels");
    await stepper.focus();
    await page.keyboard.press("ArrowUp");
    await page.keyboard.press("ArrowUp");
    await expect(ready.getByTestId("sw-awb-create")).toHaveText(/Emite AWB · 3 colete/);
    await page.keyboard.press("End");
    await expect(stepper).toHaveValue("20");
    await page.keyboard.press("PageDown");
    await page.keyboard.press("PageDown");
    await page.keyboard.press("PageDown");
    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("ArrowDown");
    await expect(stepper).toHaveValue("3");
    await shot(page, "08-awb-parcels-3");

    await ready.getByTestId("sw-awb-create").click();
    await expect(ready.locator(".sw-pill-green")).toContainText("AWB", { timeout: 20_000 });
    await expect(ready.getByTestId("sw-awb-label")).toBeVisible();
    // The label opens by itself for printing (or the screen says to press the print button); never "printed".
    await expect(ready.getByTestId("sw-awb-note")).toContainText(/Eticheta s-a deschis pentru tipărire|Eticheta nu s-a deschis automat/, { timeout: 20_000 });
    await expect(ready.getByTestId("sw-awb-note")).not.toContainText("tipărită");
    await shot(page, "09-awb-created");

    await ready.getByTestId("sw-awb-correct").click();
    const correct = page.getByTestId("sw-correct-dialog");
    await expect(correct).toBeVisible();
    await expect(page.getByTestId("sw-correct-confirm")).toBeDisabled();
    await correct.getByLabel("Număr incorect de colete").check();
    await shot(page, "10-awb-correction-reason");
    await page.getByTestId("sw-correct-confirm").click();
    await expect(ready.locator(".sw-tone-green")).toContainText("anulat la FAN Courier", { timeout: 20_000 });
    await shot(page, "11-awb-cancelled-at-fan");

    await page.reload();
    await address.getByTestId("sw-awb-fix-address").click();
    const panel = page.getByTestId("sw-address-dialog");
    await expect(panel).toBeVisible();
    await expect(panel.getByTestId("sw-addr-phone-big")).toBeVisible();
    await expect(panel).toContainText("FAN Courier nu cunoaște localitatea");
    await accessible(page, "sw-address-dialog", "address");
    await shot(page, "12-address-correction");
    await panel.getByTestId("sw-addr-city").fill("Brașov");
    await panel.getByTestId("sw-addr-save").click();
    await expect(panel.getByTestId("sw-addr-result")).toContainText("salvată", { timeout: 20_000 });
    await shot(page, "13-address-saved");
    await expect(panel).toBeHidden({ timeout: 5_000 });

    await page.getByTestId("sw-awb-filter-problem").click();
    await expect(page.getByTestId("sw-awb-filter-problem")).toHaveAttribute("aria-pressed", "true");
    await shot(page, "14-awb-needs-correction-filter");

    await page.getByTestId("sw-awb-tabs-history").click();
    const history = page.getByTestId("sw-awb-history");
    await expect(history.getByTestId("sw-hist-row").filter({ hasText: F.history }).first()).toBeVisible();
    await expect(history).toContainText("Anulat");
    await expect(history).toContainText("Înlocuit de");
    await noOverflow(page, "awb history");
    await shot(page, "15-awb-history-cancelled-linked");

    // The period's figures (orders, AWBs, parcels, cancelled, renewed), in the history only.
    const stats = page.getByTestId("sw-hist-stats");
    await expect(stats).toBeVisible();
    await expect(page.getByTestId("sw-stat-awbs").locator(".sw-counter-value")).not.toHaveText("0");
    await expect(page.getByTestId("sw-stat-cancelled").locator(".sw-counter-value")).not.toHaveText("0");
    await expect(page.getByTestId("sw-stat-renewed").locator(".sw-counter-value")).not.toHaveText("0");
    await accessible(page, "sw-awb-history", "awb history");
    await shot(page, "19-awb-history-figures");

    // A wrong address on an existing AWB, corrected from the history: the new address is checked first (nothing changes),
    // only then the AWB is cancelled at FAN Courier and the address saved; the order goes back to "De emis".
    const current = history.getByTestId("sw-hist-row").filter({ hasText: F.history }).filter({ has: page.getByTestId("sw-hist-correct") }).first();
    await current.getByTestId("sw-hist-correct").click();
    await page.getByTestId("sw-correct-dialog").getByLabel("Adresă incorectă").check();
    await expect(page.getByTestId("sw-correct-confirm")).toHaveCount(0);
    await page.getByTestId("sw-correct-address").click();
    const prep = page.getByTestId("sw-address-dialog");
    await expect(prep.getByTestId("sw-addr-awb-lead")).toBeVisible();
    await expect(prep.getByTestId("sw-addr-cancel-save")).toBeDisabled();
    await prep.getByTestId("sw-addr-city").fill("");
    await expect(prep).toContainText("Localitatea lipsește.");
    await expect(prep.getByTestId("sw-addr-check")).toBeDisabled();
    await expect(prep.getByTestId("sw-addr-cancel-save")).toBeDisabled();
    await prep.getByTestId("sw-addr-city").fill("Brașov");
    await prep.getByTestId("sw-addr-check").click();
    await expect(prep.getByTestId("sw-addr-result")).toContainText("Adresa nouă este corectă", { timeout: 20_000 });
    await expect(prep.getByTestId("sw-addr-cancel-save")).toBeEnabled();
    await accessible(page, "sw-address-dialog", "address with AWB");
    await shot(page, "20-history-address-checked-before-cancel");
    await prep.getByTestId("sw-addr-cancel-save").click();
    await expect(prep.getByTestId("sw-addr-result")).toContainText("anulat la FAN Courier și adresa salvată", { timeout: 20_000 });
    await shot(page, "21-history-awb-cancelled-address-saved");
    await expect(prep).toBeHidden({ timeout: 5_000 });
    await expect(page.getByTestId("sw-hist-note")).toContainText("din nou în „De emis”", { timeout: 20_000 });
    await page.getByTestId("sw-hist-go-todo").click();
    await expect(page.locator(`[data-testid=sw-awb-row][data-order="${F.history}"]`).getByTestId("sw-awb-create")).toBeVisible({ timeout: 20_000 });
    await shot(page, "22-awb-back-to-create-after-history-correction");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("V1.1: earlier addresses open the Store Workspace; the customer check is a small tab; no SmartBill panel", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    const order = encodeURIComponent(`trendhome:${F.printed}`);
    for (const earlier of [`/documents/${order}`, `/orders/${order}`]) {
      await page.goto(earlier);
      await page.waitForURL((url) => url.pathname === `/magazin/comenzi/${order}`);
      await expect(page.getByTestId("sw-order")).toBeVisible();
      await expect(page.locator(".document-screen, [data-testid=cf-invoice], [data-testid=cf-customer-checks]")).toHaveCount(0);
    }
    await expect(page.getByTestId("sw-order-prepare")).toBeVisible();
    await expect(page.getByTestId("sw-order-invoice")).toBeVisible();
    await shot(page, "23-old-document-link-opens-store-order");
    await page.goto("/confirmare/handoff");
    await page.waitForURL((url) => url.pathname === "/magazin/documente");
    await expect(page.getByTestId("sw-documents")).toBeVisible();
    // The back button never shows an earlier screen: the earlier address was replaced.
    await page.goto("/magazin");
    await page.goto(`/documents/${order}`);
    await page.waitForURL((url) => url.pathname === `/magazin/comenzi/${order}`);
    await page.goBack();
    await page.waitForURL((url) => url.pathname === "/magazin");
    await expect(page.getByTestId("sw-home")).toBeVisible();

    await page.goto("/magazin/documente");
    await expect(page.getByTestId("sw-docs-checks-hint")).toBeVisible();
    await expect(page.getByTestId("cf-customer-checks")).toHaveCount(0);
    await shot(page, "24-documents-with-check-hint");
    await page.getByTestId("sw-docs-tabs-checks").click();
    const row = page.getByTestId(`sw-check-row-${F.check}`);
    await expect(row).toBeVisible();
    await accessible(page, "sw-documents", "customer checks");
    await noOverflow(page, "customer checks");
    await shot(page, "25-customer-check-tab");
    await row.getByTestId("sw-check-confirmed").click();
    await expect(page.getByTestId("sw-checks-result")).toContainText(`#${F.check}`, { timeout: 20_000 });
    await expect(row).toHaveCount(0);
    await page.getByTestId("sw-docs-tabs-todo").click();
    await expect(page.getByTestId("sw-docs-todo")).toContainText(F.check, { timeout: 20_000 });
    await shot(page, "26-checked-order-ready-to-print");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("search, order detail with the 14-stage indicator, and my orders", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser);
  try {
    await page.goto("/magazin/cauta");
    await page.getByTestId("sw-search-input").fill(F.history);
    await page.getByTestId("sw-search-input").press("Enter");
    await expect(page.getByTestId("sw-search-results")).toContainText(F.history);
    await shot(page, "16-search");
    await page.getByTestId("sw-search-results").getByRole("button").first().click();
    await expect(page.getByTestId("sw-order")).toBeVisible();
    await expect(page.getByTestId("sw-stagebar")).toContainText("14 / 14");
    await accessible(page, "sw-order", "order");
    await noOverflow(page, "order");
    await shot(page, "17-order-detail");
    await page.goto("/magazin/istoric");
    await expect(page.getByTestId("sw-mine")).toContainText(F.history);
    await shot(page, "18-my-orders");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});
