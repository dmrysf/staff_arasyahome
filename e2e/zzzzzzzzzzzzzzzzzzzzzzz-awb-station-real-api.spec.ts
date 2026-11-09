import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Trendyol AWB station (Staff 2.47.0, API 2.65.0) against the real Operations API with Trendyol answered by the test
// fixture transport (official-shaped package, label and PDF). Synthetic people, orders and labels only; nothing reaches
// Trendyol, a courier or a printer. A headless browser has no printer, so these tests prove the print request, never a
// physical print.
type Order = { id: string; number: string; packageId: string; qr: string };
type Fixture = { password: string; people: { station: string; packer: string; outsider: string }; orders: { single: Order; parcels: Order; unavailable: Order; cancelled: Order; delivery: Order } };
const root = path.resolve(import.meta.dirname, "..");
const script = path.join(root, "operations-api", "tests", "e2e-awb-fixture.php");
const shots = process.env.ARASYA_AWB_SCREENSHOTS;
let F: Fixture;

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  F = JSON.parse(execFileSync("php", [script], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
  if (shots) mkdirSync(shots, { recursive: true });
});

async function signIn(browser: Browser, username: string, viewport = { width: 1440, height: 900 }) {
  const context = await browser.newContext({ viewport, reducedMotion: "reduce" });
  // The test browser has no printer: the label frame's print() is only counted.
  await context.addInitScript(() => { try { window.print = () => { window.parent.postMessage({ awbPrint: true }, "*"); }; } catch { /* a frame without access */ } });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.locator("input[autocomplete=username]").fill(username);
  await page.locator("input[type=password]").fill(F.password);
  await page.locator("button[type=submit]").click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
  return { context, page, errors };
}
const row = (page: Page, order: Order) => page.getByTestId(`awb-row-${order.number}`);
/** The dialog opens the browser's print flow by itself when the PDF frame loads; a browser without a PDF viewer (the headless shell) never fires that load, and the visible button does the same. */
const ensurePrintRequested = async (page: Page) => {
  const button = page.getByRole("dialog").getByRole("button", { name: "Imprimă", exact: true });
  await page.waitForTimeout(800);
  if (await button.isVisible()) await button.click();
};
const shot = async (page: Page, name: string) => { if (shots) await page.screenshot({ path: path.join(shots, `${name}.png`), fullPage: false }); };
const noCustomer = async (page: Page) => {
  const body = await page.locator("body").innerText();
  for (const secret of ["TEST NEVER SHOWN", "0744000111", "Str. Test 1", "FANCOURIER", "trendyol.awb"]) expect(body).not.toContain(secret);
};

test("the station account sees only the station: stage 13 orders, Romanian by default, no Staff navigation", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.station);
  try {
    await expect(page).toHaveURL(/\/awb$/);
    await expect(page.getByRole("heading", { level: 1, name: "AWB Trendyol" })).toBeVisible();
    await expect(page.getByText("Etichete de transport pentru comenzile din împachetare")).toBeVisible();
    await expect(page.getByPlaceholder("Caută comanda sau scanează codul QR")).toBeFocused();
    const first = row(page, F.orders.single);
    await expect(first.getByRole("heading", { name: `Comanda #${F.orders.single.number}` })).toBeVisible();
    await expect(first.getByText("Împachetare · Etapa 13")).toBeVisible();
    await expect(first.getByRole("button", { name: "Imprimă AWB" })).toBeVisible();
    await expect(first.getByText("Imprimată anterior")).toHaveCount(0);
    await expect(row(page, F.orders.delivery)).toHaveCount(0);
    await expect(page.locator(".app-shell, nav")).toHaveCount(0);
    await noCustomer(page);
    await shot(page, "01-ro-queue-1440");
    // Any other Staff address stays on the station for this account.
    for (const address of ["/orders", "/trendyol", "/scan", "/profile"]) {
      await page.goto(address);
      await expect(page.getByRole("heading", { level: 1, name: "AWB Trendyol" })).toBeVisible();
      await expect(page).toHaveURL(/\/awb$/);
    }
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("Turkish at one touch, remembered on this browser only", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    await page.getByRole("button", { name: "TR", exact: true }).click();
    await expect(page.getByText("Paketleme aşamasındaki siparişlerin kargo etiketleri")).toBeVisible();
    const first = row(page, F.orders.single);
    await expect(first.getByRole("button", { name: "AWB Yazdır" })).toBeVisible();
    await expect(first.getByText("Paketleme · 13. Aşama")).toBeVisible();
    await expect(page.getByPlaceholder("Sipariş ara veya QR kodunu okut")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Etiket bekleyen siparişler" })).toBeVisible();
    await shot(page, "02-tr-queue-1440");
    await page.reload();
    await expect(page.getByText("Paketleme aşamasındaki siparişlerin kargo etiketleri")).toBeVisible();
    await page.getByRole("button", { name: "RO", exact: true }).click();
    await expect(page.getByText("Etichete de transport pentru comenzile din împachetare")).toBeVisible();
  } finally { await context.close(); }
});

test("print: official label, print dialog, print request recorded; then a small notice and an immediate reprint", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.station);
  try {
    const first = row(page, F.orders.single);
    const labelResponse = page.waitForResponse((response) => response.url().includes(`/trendyol/awb/orders/${F.orders.single.id}/label`));
    const attempt = page.waitForResponse((response) => response.url().endsWith("/print-attempts") && response.request().method() === "POST");
    await first.getByRole("button", { name: "Imprimă AWB" }).click();
    const label = await labelResponse;
    expect(label.status()).toBe(200);
    expect(label.headers()["content-type"]).toContain("application/pdf");
    expect(label.headers()["cache-control"]).toContain("no-store");
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(dialog.locator("iframe")).toHaveAttribute("src", /^blob:/);
    // What the browser actually holds is the PDF the server streamed.
    const held = await page.evaluate(async (src) => { const blob = await (await fetch(src as string)).blob(); return { type: blob.type, head: await blob.slice(0, 5).text(), size: blob.size }; }, await dialog.locator("iframe").getAttribute("src"));
    expect(held.type).toBe("application/pdf");
    expect(held.head).toBe("%PDF-");
    expect(held.size).toBeGreaterThan(100);
    // The real Trendyol label is A6 (105 x 148 mm): the browser holds the exact page box, nothing re-rendered or scaled.
    const pageBox = await page.evaluate(async (src) => (await (await fetch(src as string)).text()).match(/\/MediaBox\[([^\]]+)\]/)?.[1] ?? null, await dialog.locator("iframe").getAttribute("src"));
    expect(pageBox).toBe("0 0 298 420");
    const settings = dialog.getByRole("region", { name: "Setări de imprimare pentru eticheta Trendyol" });
    await expect(settings).toContainText("Hârtie: A6 · 105 × 148 mm, portret");
    await expect(settings).toContainText("Scală: 100% / Dimensiune reală");
    await expect(settings).toContainText("Margini: niciuna");
    await ensurePrintRequested(page);
    const recorded = await attempt;
    expect(recorded.status()).toBe(201);
    expect((await recorded.json()).print.kind).toBe("initial");
    await expect(dialog.getByText("Cererea de imprimare a fost trimisă.")).toBeVisible();
    await shot(page, "03-ro-print-preview-1440");
    await dialog.getByRole("button", { name: "Închide" }).last().click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.getByPlaceholder("Caută comanda sau scanează codul QR")).toBeFocused();
    await expect(first.getByText("Imprimată anterior")).toBeVisible();
    await expect(first.getByRole("button", { name: "Reimprimă" })).toBeVisible();
    await expect(first).toBeVisible(); // Still at stage 13: printing moves nothing.
    await shot(page, "04-ro-printed-before-1440");
    // Reprint in Turkish: the label is asked from Trendyol again and the same printer settings are shown in Turkish.
    await page.getByRole("button", { name: "TR", exact: true }).click();
    const again = page.waitForResponse((response) => response.url().endsWith("/print-attempts") && response.request().method() === "POST");
    const relabel = page.waitForResponse((response) => response.url().includes(`/trendyol/awb/orders/${F.orders.single.id}/label`));
    await first.getByRole("button", { name: "Tekrar Yazdır" }).click();
    expect((await relabel).status()).toBe(200);
    const trSettings = page.getByRole("dialog").getByRole("region", { name: "Trendyol etiketi için yazdırma ayarları" });
    await expect(trSettings).toContainText("Kağıt: A6 · 105 × 148 mm, dikey");
    await expect(trSettings).toContainText("Ölçek: %100 / Gerçek boyut");
    await ensurePrintRequested(page);
    expect((await (await again).json()).print.kind).toBe("reprint");
    await page.getByRole("dialog").getByRole("button", { name: "Kapat" }).last().click();
    await page.getByRole("button", { name: "RO", exact: true }).click();
    await first.getByRole("button", { name: "Istoric" }).click();
    const history = page.getByRole("dialog");
    await expect(history.getByText("Reimprimare cerută")).toBeVisible();
    await expect(history.getByText("Imprimare cerută", { exact: true })).toBeVisible();
    await expect(history.getByText("ARASYA AWB STATION").first()).toBeVisible();
    await noCustomer(page);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("several parcels: each parcel prints its own label", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    await row(page, F.orders.parcels).getByRole("button", { name: "Imprimă AWB" }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog.getByText("Această comandă are 2 colete. Imprimă eticheta fiecărui colet.")).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Colet 1 din 2" })).toHaveAttribute("aria-pressed", "true");
    const second = page.waitForResponse((response) => response.url().includes(`/orders/${F.orders.parcels.id}/label?parcel=2`));
    await dialog.getByRole("button", { name: "Colet 2 din 2" }).click();
    expect((await second).status()).toBe(200);
    await expect(dialog.getByRole("button", { name: "Colet 2 din 2" })).toHaveAttribute("aria-pressed", "true");
  } finally { await context.close(); }
});

test("short messages when a label cannot be printed, with a retry; never a fabricated label", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    const unavailable = row(page, F.orders.unavailable);
    await unavailable.getByRole("button", { name: "Imprimă AWB" }).click();
    await expect(unavailable.getByRole("alert")).toContainText("Eticheta nu este disponibilă momentan");
    await expect(unavailable.getByRole("button", { name: "Încearcă din nou" })).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await shot(page, "05-ro-label-unavailable-1440");
    const cancelled = row(page, F.orders.cancelled);
    await cancelled.getByRole("button", { name: "Imprimă AWB" }).click();
    await expect(cancelled.getByRole("alert")).toContainText("Pachetul este anulat sau returnat în Trendyol");
    await page.getByRole("button", { name: "TR", exact: true }).click();
    await unavailable.getByRole("button", { name: "AWB Yazdır" }).click();
    await expect(unavailable.getByRole("alert")).toContainText("Etiket şu anda alınamıyor");
    await expect(unavailable.getByRole("button", { name: "Tekrar Dene" })).toBeVisible();
    await page.getByRole("button", { name: "RO", exact: true }).click();
  } finally { await context.close(); }
});

test("scanner: the Arasya QR finds the order, an old order by exact search, an invalid code one short message", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    const search = page.getByPlaceholder("Caută comanda sau scanează codul QR");
    await search.pressSequentially(F.orders.parcels.qr);
    await search.press("Enter");
    await expect(row(page, F.orders.parcels)).toHaveAttribute("data-highlight", "true");
    await expect(row(page, F.orders.parcels).getByRole("button", { name: /Imprimă AWB|Reimprimă/ })).toBeFocused();
    await expect(search).toHaveValue("");
    // A keyboard-wedge scanner while the focus is elsewhere still lands in the search field.
    await page.locator("h1").click();
    await page.keyboard.type(F.orders.delivery.qr);
    await expect(search).toBeFocused();
    await page.keyboard.press("Enter");
    const result = page.locator(".awb-results");
    await expect(result.getByRole("heading", { name: `Comanda #${F.orders.delivery.number}` })).toBeVisible();
    await expect(result.getByText("Nu mai este în împachetare")).toBeVisible();
    await expect(result.getByRole("button", { name: "Imprimă AWB" })).toBeEnabled();
    await shot(page, "06-ro-search-result-1440");
    await search.fill("ARASYA:Q1:AAAAAAAAAAAAAAAAAAAAAAAAAA");
    await search.press("Enter");
    await expect(page.getByRole("alert").filter({ hasText: "Nu există nicio comandă Trendyol cu acest cod." })).toBeVisible();
    await search.fill("<nu-este-cod>");
    await search.press("Enter");
    await expect(page.getByRole("alert").filter({ hasText: "Codul scanat nu este valid." })).toBeVisible();
  } finally { await context.close(); }
});

test("a new stage 13 order appears on its own", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    await expect(row(page, F.orders.single)).toBeVisible();
    const added = JSON.parse(execFileSync("php", [script, "add"], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Order;
    await expect(row(page, added)).toBeVisible({ timeout: 20_000 });
  } finally { await context.close(); }
});

test("empty queue, connection failure and session expiry explain themselves", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.station);
  try {
    await page.route("**/trendyol/awb/queue*", async (route) => {
      const response = await route.fetch();
      await route.fulfill({ response, json: { ...(await response.json()), total: 0, items: [], nextCursor: null } });
    });
    await page.reload();
    await expect(page.getByText("Nu există comenzi în etapa de împachetare")).toBeVisible();
    await shot(page, "07-ro-empty-1440");
    await page.getByRole("button", { name: "TR", exact: true }).click();
    await expect(page.getByText("Paketleme aşamasında sipariş bulunmuyor")).toBeVisible();
    await page.getByRole("button", { name: "RO", exact: true }).click();
    await page.unroute("**/trendyol/awb/queue*");
    await page.route("**/trendyol/awb/queue*", (route) => route.abort());
    await page.reload();
    await expect(page.getByRole("alert").filter({ hasText: "Nu există conexiune cu serverul" })).toBeVisible();
    await expect(page.getByRole("alert").getByRole("button", { name: "Încearcă din nou" })).toBeVisible();
    await page.unroute("**/trendyol/awb/queue*");
    await page.getByRole("alert").getByRole("button", { name: "Încearcă din nou" }).click();
    await expect(row(page, F.orders.single)).toBeVisible();
    await context.clearCookies();
    await row(page, F.orders.single).getByRole("button", { name: "Reimprimă" }).click();
    await page.waitForURL(/\/login/);
    await page.locator("input[autocomplete=username]").fill(F.people.station);
    await page.locator("input[type=password]").fill(F.password);
    await page.locator("button[type=submit]").click();
    await expect(page).toHaveURL(/\/awb$/);
    await expect(row(page, F.orders.single).getByText("Imprimată anterior")).toBeVisible();
  } finally { await context.close(); }
});

test("access: a named employee opens it from home and returns; without the permission there is no station", async ({ browser }) => {
  const named = await signIn(browser, F.people.packer);
  try {
    await named.page.getByTestId("home-open-awb").click();
    await expect(named.page).toHaveURL(/\/awb$/);
    await expect(row(named.page, F.orders.single)).toBeVisible();
    await named.page.getByRole("button", { name: "Înapoi la Staff" }).click();
    await expect(named.page).toHaveURL(/\/$/);
    await expect(named.page.getByTestId("home-open-awb")).toBeVisible();
  } finally { await named.context.close(); }
  const outsider = await signIn(browser, F.people.outsider);
  try {
    await expect(outsider.page.getByTestId("home-open-awb")).toHaveCount(0);
    await outsider.page.goto("/awb");
    await expect(outsider.page.getByRole("heading", { level: 1, name: "AWB Trendyol" })).toHaveCount(0);
  } finally { await outsider.context.close(); }
});

for (const width of [1920, 1440, 1024, 768, 390, 360]) {
  for (const language of ["RO", "TR"] as const) {
    test(`layout at ${width} px in ${language}: no sideways scroll, large controls, no accessibility violation`, async ({ browser }) => {
      const { context, page } = await signIn(browser, F.people.station, { width, height: width < 500 ? 800 : 900 });
      try {
        await page.getByRole("button", { name: language, exact: true }).click();
        await expect(row(page, F.orders.single)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        const box = await row(page, F.orders.single).getByRole("button", { name: /Reimprimă|Tekrar Yazdır/ }).boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(60);
        const results = await new AxeBuilder({ page }).include(".awb-station").analyze();
        expect(results.violations.filter((violation) => ["serious", "critical"].includes(violation.impact ?? "")).map((violation) => violation.id)).toEqual([]);
        await shot(page, `layout-${language.toLowerCase()}-${width}`);
      } finally { await context.close(); }
    });
  }
}
