import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type BrowserContext, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Operator Workspace V2 (Staff 2.58.0) against the real Operations API and the loopback TEST shop: the redesigned desk
// and order workspace at six laptop sizes and three zoom levels, with TH 65794's shape (one curtain, prices with VAT, flat
// shipping), 4, 10 and 20 products, an out-of-stock product, a possible duplicate, a missing postcode, the address editor,
// the SKU search, the dynamic recalculation, the compact menu, Turkish and Romanian. TEST people, customers and products
// only (tests/e2e-confirmare-fixture.php --workspace); product pictures are TEST swatches served by the test itself.
type Workspace = { orders: { one: string; four: string; ten: string; twenty: string } };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "operator-workspace");
const PASSWORD = "e2e passphrase 2026";
const SIZES = [[1280, 720], [1280, 800], [1366, 768], [1440, 900], [1536, 864], [1920, 1080]] as const;
// Browser zoom on a 1366 x 768 laptop: the page sees a smaller CSS viewport.
const ZOOMS = [[100, 1366, 768], [110, 1242, 698], [125, 1093, 614]] as const;
const php = (args: string[]) => execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-confirmare-fixture.php"), ...args], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
const number = (globalId: string) => globalId.split(":")[1];
const overflow = async (page: Page) => {
  const result = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const over = document.documentElement.scrollWidth - width;
    // Names the widest element past the edge, so a failure says what to fix.
    const culprit = over > 0 ? [...document.querySelectorAll("body *")].map((node) => ({ node, right: node.getBoundingClientRect().right })).filter((entry) => entry.right > width + 0.5)
      .sort((a, b) => b.right - a.right).slice(0, 3).map((entry) => `${entry.node.tagName.toLowerCase()}.${String(entry.node.className).split(" ").join(".")} ${Math.round(entry.right)}`) : [];
    const scrollers = over > 0 ? [...document.querySelectorAll("body *")].filter((node) => node.scrollWidth > node.clientWidth + 1 && getComputedStyle(node).overflowX === "visible")
      .slice(0, 5).map((node) => `${node.tagName.toLowerCase()}.${String(node.className).split(" ").join(".")} ${node.scrollWidth}/${node.clientWidth}`) : [];
    return { over, culprit: [...culprit, "scrollers:", ...scrollers] };
  });
  if (result.over > 0) console.log(`overflow ${result.over}px: ${result.culprit.join(" | ")}`);
  return result.over;
};
const RAW_KEY = /\b(?:dd|cf|fd|fr|common)\.[a-zA-Z]+(?:\.[a-zA-Z_]+)+\b/;
let W: Workspace;

// The six sizes and three zooms per order make long tests; the TEST shop answers 10- and 20-line orders slower on CI.
test.describe.configure({ mode: "serial", timeout: 240_000 });

test.beforeAll(() => {
  try { php([]); } catch { /* seeded by an earlier spec */ }
  W = JSON.parse(php(["--workspace"]).trim().split("\n").pop() ?? "{}") as Workspace;
});

const SWATCH: Record<string, string> = { draperie: "#c8b79a", voal: "#ecebe6", gri: "#5f646b", in: "#cdbf9f", sina: "#b8bcc2" };
async function servePictures(context: BrowserContext) {
  await context.route("https://127.0.0.1/wp-content/uploads/e2e/**", async (route) => {
    const name = path.basename(new URL(route.request().url()).pathname, ".png");
    const color = SWATCH[name];
    if (!color) return route.fulfill({ status: 404, body: "" });
    const folds = Array.from({ length: 6 }, (_, i) => `<rect x="${i * 100}" y="0" width="50" height="600" fill="#000" opacity="0.06"/>`).join("");
    await route.fulfill({ status: 200, contentType: "image/svg+xml", body: `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="${color}"/>${folds}</svg>` });
  });
}

async function signIn(browser: Browser, username: string, width = 1366, height = 768) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  await servePictures(context);
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(PASSWORD);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByTestId("dd-desk")).toBeVisible();
  return { context, page, errors };
}

async function shot(page: Page, name: string) {
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
}

/** Every size: no sideways scroll, no raw translation key, the header not under anything; a screenshot of each. */
async function sizes(page: Page, name: string) {
  for (const [width, height] of SIZES) {
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(150);
    expect(await overflow(page), `${name} at ${width}x${height} scrolls sideways`).toBeLessThanOrEqual(0);
    await shot(page, `${name}-${width}x${height}`);
  }
  for (const [zoom, width, height] of ZOOMS) {
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(150);
    expect(await overflow(page), `${name} at ${zoom}% zoom scrolls sideways`).toBeLessThanOrEqual(0);
    await shot(page, `${name}-zoom${zoom}`);
  }
  await page.setViewportSize({ width: 1366, height: 768 });
  await page.waitForTimeout(100);
}

async function noRawKeys(page: Page) {
  const text = await page.locator("main").innerText();
  expect(text.match(RAW_KEY)?.[0] ?? null, "a translation key is shown").toBeNull();
  expect(text).not.toContain("trendhome-tools_monopera_cost");
}

async function axe(page: Page) {
  const result = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]).analyze();
  expect(result.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).slice(0, 3).join(" | ")}`)).toEqual([]);
}

/** API requests the page sent and that have not finished (the stall diagnosis below names them with their age). */
function trackApi(page: Page): () => string[] {
  const open = new Map<object, { url: string; at: number }>();
  page.on("request", (request) => { if (/\/confirmare\//.test(request.url())) open.set(request, { url: `${request.method()} ${new URL(request.url()).pathname}`, at: Date.now() }); });
  const done = (request: object) => open.delete(request);
  page.on("requestfinished", done);
  page.on("requestfailed", done);
  return () => [...open.values()].map((entry) => `${entry.url} ${Math.round((Date.now() - entry.at) / 1000)}s`);
}

/** The PHP processes of the test servers with their kernel wait channel (which call a stalled worker sits in). */
function phpProcesses(): string {
  try {
    return execFileSync("ps", ["-eo", "pid,ppid,stat,etime,wchan:24,pcpu,args"], { encoding: "utf8" }).split("\n").filter((line) => /php/.test(line) && !/ps -eo/.test(line)).map((line) => line.trim().replace(/\s+/g, " ")).join(" | ");
  } catch (error) {
    return `ps unavailable: ${String(error).slice(0, 80)}`;
  }
}

async function openOrder(page: Page, globalId: string) {
  const pending = trackApi(page);
  await page.goto(`/confirmare/orders/${encodeURIComponent(globalId)}`);
  await expect(page.getByTestId("dd-work")).toBeVisible();
  // The loopback TEST shop is one PHP process answering from one JSON file: on a busy CI runner a read can stall.
  // One reload asks again (the real screen does the same on its next refresh); the figures must then appear.
  try {
    await expect(page.getByTestId("dd-total")).toContainText(/\d/, { timeout: 20_000 });
  } catch {
    // What the page waits for, the PHP servers' wait channels and the TEST database (transactions, lock waits): CI log.
    console.log(`stalled read ${globalId}: pending=${JSON.stringify(pending())} php=${phpProcesses()}`);
    console.log(`stalled read ${globalId}: ${php(["--locks"]).trim()}`);
    await page.reload();
    await expect(page.getByTestId("dd-work")).toBeVisible();
    try {
      await expect(page.getByTestId("dd-total")).toContainText(/\d/, { timeout: 30_000 });
    } catch (error) {
      console.log(`stalled again ${globalId}: pending=${JSON.stringify(pending())} php=${phpProcesses()}`);
      throw error;
    }
  }
}

test("TH 65794's shape: money header, big decisions, customer, the curtain card and how 355,32 is made up", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    // Performance: the requests one order opening makes (Arasya API and pictures), counted and printed for the report.
    const calls: string[] = [];
    page.on("request", (request) => { const url = new URL(request.url()); if (url.port === "8789" || url.pathname.includes("/wp-content/uploads/")) calls.push(`${request.method()} ${url.pathname.replace(/trendhome%3A\d+/, "{order}")}`); });
    await openOrder(page, W.orders.one);
    await expect(page.locator('[data-testid^="dd-line-"]').first()).toBeVisible();
    await page.waitForTimeout(1500);
    console.log(`perf open-order: ${calls.length} requests: ${JSON.stringify(calls.reduce<Record<string, number>>((all, call) => ({ ...all, [call]: (all[call] ?? 0) + 1 }), {}))}`);
    expect(calls.filter((call) => call.includes("/source/preview")), "opening an order never asks for a preview").toEqual([]);
    // The two figures read out on every call, big and apart; the real money and the estimate never mixed up.
    await expect(page.getByTestId("dd-total")).toContainText("355,32");
    await expect(page.getByTestId("dd-advance")).toContainText("106,60");
    await expect(page.getByTestId("dd-advance")).toContainText("(30%)");
    await expect(page.getByTestId("dd-received")).toContainText("0,00");
    await expect(page.getByTestId("dd-ramburs-now")).toContainText("355,32");
    await expect(page.getByTestId("dd-ramburs-after")).toContainText("248,72");
    const totalSize = await page.getByTestId("dd-total").locator("dd").evaluate((node) => Number.parseFloat(getComputedStyle(node).fontSize));
    expect(totalSize).toBeGreaterThanOrEqual(30);
    // Three action buttons: verbs, a hint, at least 56 px high, one click each (no form).
    for (const [id, label] of [["dd-green", "Confirmă"], ["dd-yellow", "Pune în așteptare"], ["dd-red", "Refuză"]] as const) {
      const button = page.getByTestId(id);
      await expect(button).toContainText(label);
      expect((await button.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(56);
    }
    await expect(page.getByTestId("dd-green")).toContainText("Clientul acceptă comanda.");
    // The customer: the telephone is the biggest contact line; copy says so.
    const phoneSize = await page.getByTestId("dd-call").evaluate((node) => Number.parseFloat(getComputedStyle(node).fontSize));
    expect(phoneSize).toBeGreaterThanOrEqual(22);
    await expect(page.getByTestId("dd-addr-edit-shipping")).toContainText("Editează adresa");
    // The product: the panels apart from the quantity ordered, the sewing readable in full, no technical key.
    const card = page.locator('[data-testid^="dd-line-"]').first();
    await expect(card).toContainText("Număr de bucăți");
    await expect(card).toContainText("Cantitate comandată");
    await expect(card).toContainText("Lungimea (L) (m)");
    await expect(card.getByTestId(/dd-field-.*-manopera/)).toHaveValue(/Manopera Rejansa Bara/);
    await expect(card).toContainText("336,00");
    await expect(card).toContainText("TVA inclus");
    const img = card.locator(".ow-product-img");
    expect((await img.boundingBox())?.width ?? 0).toBeGreaterThanOrEqual(88);
    // How the total is made up: products with VAT + shipping with VAT = the shop's total; VAT shown once, inside.
    await expect(page.getByTestId("dd-sum-products")).toContainText("336,00");
    await expect(page.getByTestId("dd-sum-shipping")).toContainText("19,32");
    await expect(page.getByTestId("dd-sum-total")).toContainText("355,32");
    await expect(page.getByTestId("dd-breakdown")).toContainText("din care TVA");
    await expect(page.getByTestId("dd-sum-vat")).toHaveCount(0);
    await noRawKeys(page);
    await axe(page);
    await sizes(page, "order-1-product");
    await context.grantPermissions(["clipboard-read", "clipboard-write"]);
    await page.getByTestId("dd-copy-phone").click();
    await expect(page.getByTestId("dd-copy-phone")).toContainText("Copiat");
    await page.getByTestId("dd-copy-email").click();
    await expect(page.getByTestId("dd-copy-email")).toContainText("Copiat");
    // The compact menu: icons only on the order workspace, the full menu on request, nothing scrolls sideways.
    const nav = page.locator(".app-nav");
    expect((await nav.boundingBox())?.width ?? 999).toBeLessThanOrEqual(76);
    await page.getByTestId("ow-nav-toggle").click();
    await expect.poll(() => page.evaluate(() => document.documentElement.dataset.owNav)).toBe("wide");
    await expect.poll(async () => (await nav.boundingBox())?.width ?? 0).toBeGreaterThan(200);
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    await shot(page, "order-1-product-wide-menu-1366x768");
    await page.getByTestId("ow-nav-toggle").click();
    await expect.poll(async () => (await nav.boundingBox())?.width ?? 999).toBeLessThanOrEqual(76);
    // Scrolling: the money and the decisions stay on top.
    await page.mouse.wheel(0, 2000);
    await page.waitForTimeout(200);
    const box = await page.getByTestId("dd-money").boundingBox();
    expect(box && box.y >= 0 && box.y < 160, "the money header stays at the top while scrolling").toBe(true);
    await shot(page, "order-1-product-scrolled-1366x768");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("a change is recalculated by the shop: every figure follows together, nothing shows an old total as new", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    const previews: string[] = [];
    page.on("request", (request) => { if (request.url().includes("/source/preview")) previews.push(request.url()); });
    await openOrder(page, W.orders.one);
    const width = page.getByTestId(/dd-field-.*-lungimea/).first();
    await width.fill("6,5");
    await expect(page.getByTestId("dd-total")).toContainText("Se recalculează totalul");
    await shot(page, "order-recalculating-1366x768");
    // The TEST shop adds 35,00 with VAT to the first line: total, advance estimate, ramburs and the card follow.
    await expect(page.getByTestId("dd-total")).toContainText("390,32");
    await expect(page.getByTestId("dd-advance")).toContainText("117,10");
    await expect(page.getByTestId("dd-ramburs-now")).toContainText("390,32");
    await expect(page.getByTestId("dd-sum-total")).toContainText("390,32");
    await expect(page.getByTestId("dd-breakdown")).toContainText("Calcul nou, nesalvat");
    await expect(page.locator('[data-testid^="dd-line-price-"]').first()).toContainText("371,00");
    await expect(page.getByTestId("dd-savebar")).toBeVisible();
    expect(previews.length, "typing one value asks the shop once").toBeLessThanOrEqual(1);
    await shot(page, "order-recalculated-1366x768");
    await page.getByRole("button", { name: /Renunță la modificări/ }).click();
    await expect(page.getByTestId("dd-total")).toContainText("355,32");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("four products: out of stock, a possible duplicate, no postcode, the address editor, SKU search, coupon, remove and undo", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    await openOrder(page, W.orders.four);
    await expect(page.locator('[data-testid^="dd-stock-"]').filter({ hasText: "Stoc epuizat" })).toHaveCount(1);
    await expect(page.getByTestId("dd-duplicate")).toContainText("#91299");
    await expect(page.getByTestId("dd-addr-missing-shipping")).toContainText("Cod poștal lipsă");
    await expect(page.getByTestId("dd-sum-discount")).toContainText("50,00");
    await expect(page.getByTestId("dd-sum-shipping")).toContainText("Transport gratuit");
    await expect(page.getByTestId("dd-sum-total")).toContainText("806,30");
    await expect(page.getByTestId("dd-coupon-add")).toBeVisible();
    await noRawKeys(page);
    await sizes(page, "order-4-products");
    await page.getByTestId("dd-addr-edit-shipping").click();
    await expect(page.getByTestId("dd-addr-postcode")).toBeVisible();
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    await page.getByTestId("dd-addr-postcode").scrollIntoViewIfNeeded();
    await shot(page, "order-address-edit-1366x768");
    await page.locator(".ow-address-form .ow-form-actions button[type=button]").click();
    await page.getByTestId("dd-coupon-add").click();
    await expect(page.getByTestId("dd-coupon-code")).toBeVisible();
    await page.getByTestId("dd-coupon-code").scrollIntoViewIfNeeded();
    await shot(page, "order-coupon-1366x768");
    // SKU: Enter searches once; an unknown SKU is explained in words.
    await page.getByTestId("dd-sku").fill("NU-EXISTA-404");
    await page.getByTestId("dd-sku").press("Enter");
    await expect(page.locator(".ow-sku-search [role=alert]")).toContainText("Nu există un produs publicat cu acest cod");
    await page.getByTestId("dd-sku").scrollIntoViewIfNeeded();
    await shot(page, "order-sku-search-1366x768");
    // A known SKU is found with one Enter and shown before it is added.
    await page.getByTestId("dd-sku").fill("TST-INELE-40");
    await page.getByTestId("dd-sku").press("Enter");
    await expect(page.locator(".ow-sku-found")).toContainText("Inele perdea crom");
    await expect(page.getByTestId("dd-sku-add")).toBeEnabled();
    await page.getByTestId("dd-sku").scrollIntoViewIfNeeded();
    await shot(page, "order-sku-found-1366x768");
    // One click adds it as an editable card (an unsaved change the shop prices); removing it again leaves the order as it was.
    await page.getByTestId("dd-sku-add").click();
    const added = page.getByTestId("dd-added-TST-INELE-40");
    await expect(added).toBeVisible();
    // The shop prices the new product (one preview after 450 ms); the header shows the new total, never the old one.
    await expect(page.getByTestId("dd-total")).toContainText(/\d/, { timeout: 15_000 });
    await expect(added).not.toContainText("Se recalculează");
    await added.scrollIntoViewIfNeeded();
    await shot(page, "order-sku-added-1366x768");
    await added.locator(".ow-action.is-danger").click();
    await expect(added).toHaveCount(0);
    // Replace opens the SKU search right on the card; remove keeps an undo.
    const replace = page.locator('[data-testid^="dd-replace-"]').first();
    await replace.click();
    await expect(page.getByTestId(/^dd-replace-sku-\d+$/)).toBeFocused();
    await shot(page, "order-replace-1366x768");
    await replace.click();
    const firstCard = page.locator('[data-testid^="dd-line-"]').first();
    await page.locator('[data-testid^="dd-remove-"]').nth(1).click();
    await expect(page.locator(".ow-product.is-removed")).toHaveCount(1);
    await page.locator('[data-testid^="dd-restore-"]').first().click();
    await expect(page.locator(".ow-product.is-removed")).toHaveCount(0);
    await expect(firstCard).toBeVisible();
    await axe(page);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("ten and twenty products: cards stay apart, the money and decisions never leave the screen", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    for (const [key, count] of [["ten", 10], ["twenty", 20]] as const) {
      await openOrder(page, W.orders[key]);
      await expect(page.locator('[data-testid^="dd-line-"]:not([data-testid^="dd-line-price"])')).toHaveCount(count);
      await noRawKeys(page);
      await sizes(page, `order-${count}-products`);
      // The cards are compact: a ten-product order is not a wall of scrolling.
      const cardHeight = await page.locator(".ow-product").first().evaluate((node) => node.getBoundingClientRect().height);
      expect(cardHeight, "one product card height").toBeLessThanOrEqual(300);
      await page.mouse.wheel(0, 2500);
      await page.waitForTimeout(200);
      const decisions = await page.getByTestId("dd-green").boundingBox();
      expect(decisions && decisions.y >= 0 && decisions.y < 260, "the decisions stay on screen").toBe(true);
      await shot(page, `order-${count}-products-scrolled-1366x768`);
      await page.mouse.wheel(0, 100000);
      await page.waitForTimeout(200);
      const layout = await page.evaluate(() => {
        const bodyBottom = document.body.getBoundingClientRect().bottom + window.scrollY;
        const below = [...document.querySelectorAll("body *")].map((node) => ({ node, bottom: node.getBoundingClientRect().bottom + window.scrollY })).filter((entry) => entry.bottom > bodyBottom + 1)
          .sort((a, b) => b.bottom - a.bottom).slice(0, 4).map((entry) => `${entry.node.tagName.toLowerCase()}.${String(entry.node.className).split(" ").join(".")}@${Math.round(entry.bottom)}`);
        return { doc: document.documentElement.scrollHeight, body: Math.round(bodyBottom), scrollY: Math.round(window.scrollY), inner: window.innerHeight, work: Math.round((document.querySelector(".ow-work")?.getBoundingClientRect().bottom ?? 0) + window.scrollY), below };
      });
      console.log(`layout ${count}: ${JSON.stringify(layout)}`);
      expect(layout.doc - layout.work, `nothing extends the page below the workspace (${JSON.stringify(layout)})`).toBeLessThanOrEqual(80);
      const atBottom = await page.getByTestId("dd-green").boundingBox();
      expect(atBottom && atBottom.y >= 0 && atBottom.y < 260, "the decisions are still on screen at the very bottom").toBe(true);
      await shot(page, `order-${count}-products-bottom-1366x768`);
    }
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the desk: four lists with periods, totals, warnings in words, the waiting time weighted, search and sticky header", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    // The list copy (total, warnings) is written when an order is read; the earlier tests read all four.
    await page.goto("/confirmare");
    await expect(page.getByTestId("dd-view-confirmed")).toContainText("ultimele 7 zile");
    await expect(page.getByTestId("dd-view-refused")).toContainText("ultimele 7 zile");
    await expect(page.getByTestId("dd-done-today")).toContainText("Finalizate azi");
    const four = page.getByTestId(`dd-row-${number(W.orders.four)}`);
    await expect(four).toContainText("Posibilă comandă dublă");
    await expect(four).toContainText("Stoc epuizat");
    await expect(four.locator(".ow-wait")).toHaveClass(/is-long/);
    await expect(page.getByTestId(`dd-row-${number(W.orders.ten)}`).locator(".ow-wait")).toHaveClass(/is-critical/);
    await expect(page.getByTestId(`dd-row-${number(W.orders.one)}`)).toContainText("355,32");
    await noRawKeys(page);
    await axe(page);
    await sizes(page, "desk");
    // Search: a customer's name without accents, the result said in words; Escape clears it.
    await page.getByTestId("dd-search").fill("mihai");
    await expect(page.getByTestId("dd-search-result")).toContainText("1 comenzi găsite");
    await expect(four).toBeVisible();
    await shot(page, "desk-search-1366x768");
    await page.getByTestId("dd-search").press("Escape");
    await expect(page.getByTestId("dd-search-result")).toHaveCount(0);
    // Scrolling: the lists, the filters and the column titles stay; nothing hides the first row under them.
    await page.setViewportSize({ width: 1366, height: 600 });
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(200);
    const sticky = await page.locator(".ow-desk-sticky").boundingBox();
    const head = await page.locator(".ow-table thead th").first().boundingBox();
    expect(sticky && sticky.y >= -1 && sticky.y < 4, "the lists and filters stay on top").toBe(true);
    expect(head && sticky && head.y >= sticky.y + sticky.height - 2, "the column titles sit right under them").toBe(true);
    await shot(page, "desk-scrolled-1366x600");
    // A row opens with the keyboard too.
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.mouse.wheel(0, -2000);
    await page.getByRole("button", { name: `Deschide comanda #${number(W.orders.one)}` }).focus();
    await page.keyboard.press("Enter");
    await expect(page.getByTestId("dd-work")).toBeVisible();
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("yellow is one click and the waiting list shows it; Turkish and Romanian read in full", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, "cf.operator.e2e");
  try {
    await openOrder(page, W.orders.twenty);
    await page.getByTestId("dd-autonext").uncheck();
    await page.getByTestId("dd-yellow").click();
    await expect(page.getByTestId("dd-notice")).toContainText(number(W.orders.twenty));
    await expect(page.getByTestId("dd-waiting-panel")).toHaveCount(0);
    await shot(page, "order-after-yellow-1366x768");
    await page.goto("/confirmare?view=waiting");
    await expect(page.getByTestId(`dd-row-${number(W.orders.twenty)}`)).toBeVisible();
    await shot(page, "desk-waiting-1366x768");
    // Turkish: the operator's own language for the interface; order data stays as the shop wrote it.
    await page.goto("/profile");
    await page.getByRole("group", { name: "Limba aplicației" }).getByRole("button", { name: "Türkçe" }).click();
    await expect(page.getByRole("group", { name: "Uygulama dili" }).getByRole("button", { name: "Türkçe" })).toHaveAttribute("aria-pressed", "true");
    await page.goto("/confirmare");
    await expect(page.getByRole("heading", { name: "Sipariş yönetimi" })).toBeVisible();
    await expect(page.getByTestId(`dd-row-${number(W.orders.four)}`)).toContainText("Olası mükerrer sipariş");
    await noRawKeys(page);
    await shot(page, "desk-tr-1366x768");
    await openOrder(page, W.orders.one);
    await expect(page.getByTestId("dd-green")).toContainText("Onayla");
    await expect(page.getByTestId("dd-yellow")).toContainText("Beklet");
    await expect(page.getByTestId("dd-red")).toContainText("Reddet");
    await expect(page.getByTestId("dd-addr-edit-shipping")).toContainText("Adresi düzenle");
    await noRawKeys(page);
    expect(await overflow(page)).toBeLessThanOrEqual(0);
    await shot(page, "order-tr-1366x768");
    for (const locale of ["English", "සිංහල"]) {
      await page.goto("/profile");
      await page.locator(".profile-languages button, [role=group] button").filter({ hasText: locale }).first().click();
      await page.waitForTimeout(300);
      await openOrder(page, W.orders.four);
      await noRawKeys(page);
      expect(await overflow(page)).toBeLessThanOrEqual(0);
      await shot(page, `order-${locale === "English" ? "en" : "si"}-1366x768`);
    }
    await page.goto("/profile");
    await page.locator("[role=group] button").filter({ hasText: "Română" }).first().click();
    await openOrder(page, W.orders.one);
    await expect(page.getByTestId("dd-green")).toContainText("Confirmă");
    await shot(page, "order-ro-1366x768");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});
