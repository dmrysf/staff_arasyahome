import { expect, test, type Browser, type Page, type Request } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

// QR cutting intake against the real Operations API: a cutter with only the cutting stage scans the label of an
// approved stage-1 order once and receives it into cutting. The fake camera stream plays; the browser's QR
// detector is replaced so each test chooses which label the camera "sees" (it reports it on every frame).
type Fixture = { password: string; qr: Record<string, string>; intake: { cutter: string; cutterName: string; other: string; orders: string[]; sources: Record<string, string> } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const orderId = (number: string) => `${fixture.intake.sources[number]}:${number}`;

test.describe.configure({ mode: "serial" });

async function signIn(browser: Browser, username: string, width = 390) {
  const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  const intakes: Request[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("request", (request) => { if (request.method() === "POST" && new URL(request.url()).pathname === "/cutting/intake") intakes.push(request); });
  await page.addInitScript(() => {
    class LabelDetector {
      static async getSupportedFormats() { return ["qr_code"]; }
      async detect() { const value = (window as unknown as { __label?: string }).__label; return value ? [{ rawValue: value }] : []; }
    }
    Object.defineProperty(window, "BarcodeDetector", { value: LabelDetector, configurable: true, writable: true });
  });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  return { context, page, errors, intakes };
}

async function scan(page: Page, number: string) {
  await page.evaluate((label) => { (window as unknown as { __label?: string }).__label = label; }, fixture.qr[number]);
  await page.evaluate(() => { window.history.pushState({}, "", "/scan"); window.dispatchEvent(new PopStateEvent("popstate")); });
  await page.getByRole("button", { name: "Deschide camera" }).click();
}

const apiOrder = (page: Page, id: string) => page.evaluate(async ([api, order]) => {
  const response = await fetch(`${api}/orders/${encodeURIComponent(order)}`, { credentials: "include" });
  return { status: response.status, body: response.ok ? await response.json() : null };
}, [API, id] as const);

test("one scan receives an approved stage-1 order into cutting, owned by the scanning cutter", async ({ browser }) => {
  const cutter = await signIn(browser, fixture.intake.cutter);
  const [first] = fixture.intake.orders;
  expect((await apiOrder(cutter.page, orderId(first))).status, "stage 1 stays hidden from the cutter on ordinary routes").toBe(404);

  await scan(cutter.page, first);
  const success = cutter.page.getByTestId("cutting-intake-success");
  await expect(success).toBeVisible();
  await expect(success.getByRole("heading", { name: "Comandă preluată cu succes" })).toBeVisible();
  await expect(success).toContainText("Comanda a fost atribuită contului tău. Etapa de tăiere a început.");
  await expect(success).toContainText(`#${first}`);
  await expect(success).toContainText(fixture.intake.cutterName);
  await expect(success).toContainText("Draperie Velvet");
  expect(cutter.intakes, "the detector reports the label on every frame, yet one request is sent").toHaveLength(1);
  expect(cutter.intakes[0].headers()["idempotency-key"]).toMatch(/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/);
  expect(JSON.parse(cutter.intakes[0].postData() ?? "{}")).toEqual({ qrToken: fixture.qr[first] });

  const order = await apiOrder(cutter.page, orderId(first));
  expect(order.status).toBe(200);
  expect(order.body.productionStageId).toBe("material-preparation");
  expect(order.body.employeeAllowedAction?.id, "cutting started, not completed").toBe("complete_stage");
  for (const width of [320, 390]) { await cutter.page.setViewportSize({ width, height: 700 }); expect(await overflow(cutter.page)).toBeLessThanOrEqual(0); }
  await cutter.page.screenshot({ path: "e2e/.runtime/cutting-intake-success-320.png" });

  await success.getByRole("button", { name: "Deschide comanda" }).click();
  await expect(cutter.page.locator(".detail-hero")).toContainText("Tăiere");
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  expect(cutter.errors).toEqual([]);
  await cutter.context.close();
});

test("an unfinished job needs the explicit confirmation, reusing the label already scanned", async ({ browser }) => {
  const cutter = await signIn(browser, fixture.intake.cutter);
  const second = fixture.intake.orders[1];
  await scan(cutter.page, second);
  const dialog = cutter.page.getByTestId("cutting-intake-confirm");
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText("Ai deja 1 comenzi în lucru.");
  await expect(dialog).toContainText("Nu este nevoie să o scanezi din nou.");
  await expect(dialog.getByLabel(/Cod etichetă/)).toHaveCount(0);
  const confirm = dialog.getByRole("button", { name: "Confirmă preluarea" });
  await expect(confirm).toBeDisabled();
  expect((await apiOrder(cutter.page, orderId(second))).status, "nothing was taken before the confirmation").toBe(404);
  await dialog.getByLabel("Confirm explicit preluarea încă unei comenzi.").check();
  await confirm.click();
  await expect(cutter.page.getByTestId("cutting-intake-success")).toContainText(`#${second}`);
  expect(cutter.intakes).toHaveLength(2);
  expect(cutter.intakes[1].headers()["idempotency-key"], "the confirmation is a new intent with a new key").not.toBe(cutter.intakes[0].headers()["idempotency-key"]);
  expect(JSON.parse(cutter.intakes[1].postData() ?? "{}")).toEqual({ qrToken: fixture.qr[second], confirmedMultiple: true, ownedCount: 1 });
  expect((await apiOrder(cutter.page, orderId(second))).body.productionStageId).toBe("material-preparation");
  expect(cutter.errors).toEqual([]);
  await cutter.context.close();
});

test("a lost response is retried with the same key and shows the one committed intake; a colleague is refused", async ({ browser }) => {
  const cutter = await signIn(browser, fixture.intake.other, 1280);
  const third = fixture.intake.orders[2];
  let dropped = false;
  await cutter.page.route(`${API}/cutting/intake`, async (route) => {
    if (dropped) return route.continue();
    dropped = true;
    await route.fetch(); // the server commits the intake, the answer never reaches the browser
    await route.abort("connectionreset");
  });
  await scan(cutter.page, third);
  const retry = cutter.page.getByRole("alert");
  await expect(retry).toBeVisible();
  await expect(cutter.page.getByTestId("cutting-intake-success")).toHaveCount(0);
  await cutter.page.getByRole("button", { name: "Reîncearcă" }).click();
  await expect(cutter.page.getByTestId("cutting-intake-success")).toContainText(`#${third}`);
  expect(cutter.intakes).toHaveLength(2);
  expect(cutter.intakes[1].headers()["idempotency-key"], "the retry replays the same request").toBe(cutter.intakes[0].headers()["idempotency-key"]);
  expect(await overflow(cutter.page)).toBeLessThanOrEqual(0);
  await cutter.context.close();

  const colleague = await signIn(browser, fixture.intake.cutter);
  await scan(colleague.page, third);
  await expect(colleague.page.getByRole("alert")).toContainText("Un coleg lucrează deja la această comandă.");
  await expect(colleague.page.getByTestId("cutting-intake-success")).toHaveCount(0);
  expect(colleague.errors).toEqual([]);
  await colleague.context.close();
});
