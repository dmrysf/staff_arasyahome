import { expect, request, test, type APIRequestContext, type Browser, type Locator, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

// Tailoring quality supervision against the real Operations API, on phone viewports (browser emulation, not a
// real device). The spec appoints the supervisor itself (root, through the API) and revokes it at the end.
// Rule under test: stage 7 -> stage 3 happens without any manager; only stage 3 -> stage 2 waits for one.
type Fixture = {
  password: string; qr: Record<string, string>;
  supervision: { chief: string; chiefName: string; tailor: string; cutter: string; manager: string };
  cutting: { root: { username: string; password: string } };
};
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const sup = fixture.supervision;
const numberOf = (id: string) => id.split(":")[1];
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

async function api(username: string, password = fixture.password): Promise<{ ctx: APIRequestContext; csrf: string }> {
  const ctx = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await ctx.post("/auth/login", { data: { username, password } });
  expect(login.status(), `login ${username}`).toBe(200);
  return { ctx, csrf: (await login.json()).csrfToken as string };
}
const post = (who: { ctx: APIRequestContext; csrf: string }, url: string, data: unknown) => who.ctx.post(url, { headers: { "X-CSRF-Token": who.csrf, "Idempotency-Key": `e2e-sup-${Date.now()}-${Math.random().toString(36).slice(2)}` }, data });

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  await page.evaluate(() => { (window as unknown as { __noReload: boolean }).__noReload = true; });
  return { context, page, errors, unreloaded: () => page.evaluate(() => (window as unknown as { __noReload?: boolean }).__noReload === true) };
}
const go = (page: Page, to: string) => page.evaluate((target) => { window.history.pushState({}, "", target); window.dispatchEvent(new PopStateEvent("popstate")); }, to);
async function typeLabel(section: Locator, code: string) {
  await section.getByRole("button", { name: "Introdu codul de pe etichetă" }).click();
  await section.getByLabel(/Cod etichetă/).fill(code);
  await section.getByRole("button", { name: "Verifică codul" }).click();
}
async function report(page: Page, orderId: string, line: RegExp, reason: string, description: string) {
  await go(page, `/orders/${encodeURIComponent(orderId)}`);
  await page.getByRole("button", { name: "Raportează o problemă" }).click();
  const dialog = page.getByRole("dialog", { name: "Raportează o problemă" });
  await dialog.getByRole("checkbox", { name: line }).check();
  await dialog.getByLabel("Problema observată").selectOption({ label: reason });
  await dialog.getByLabel(/Descriere/).fill(description);
  expect(await overflow(page)).toBeLessThanOrEqual(0);
  await dialog.getByRole("button", { name: "Raportează șefului de croitorie" }).click();
  await expect(page.getByText("✓ Problema a fost raportată șefului de croitorie.")).toBeVisible();
  await expect(page.getByTestId("exception-status")).toHaveText("Așteaptă verificarea șefului de croitorie");
}
async function openCase(page: Page, orderNumber: string, tab: string) {
  await go(page, "/problems");
  await page.getByRole("tab", { name: tab }).click();
  await page.getByRole("button", { name: new RegExp(`Comanda #${orderNumber}`) }).click();
  await expect(page.getByRole("heading", { name: `Comanda #${orderNumber}` })).toBeVisible();
}
/** Root's read-only order control view: current stage and production owner. */
async function stageOf(orderId: string): Promise<{ stage: string; owner: string | null }> {
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const detail = await (await root.ctx.get(`/management/orders/${encodeURIComponent(orderId)}`)).json();
  await root.ctx.dispose();
  return { stage: detail.production.stage.id, owner: detail.production.owner?.displayName ?? null };
}

let assignmentId = "";
// Synthetic orders created when this spec starts (after the earlier suites), through the real API commands.
let orders = { stage7: "", stage5: "", stage3: "" };
let labels: Record<string, string> = {};

test.beforeAll(async () => {
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "..", "operations-api", "tests", "e2e-supervision-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  orders = created.orders;
  labels = created.qr;
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const found = await (await root.ctx.get(`/management/employees?search=${encodeURIComponent(sup.chief)}`)).json();
  const chief = found.items.find((item: { username: string }) => item.username === sup.chief);
  const appointed = await post(root, "/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chief.id });
  expect(appointed.status(), await appointed.text()).toBe(201);
  assignmentId = (await appointed.json()).responsibilities.find((item: { responsibility: string; state: string }) => item.responsibility === "tailoring_quality_supervisor" && item.state === "active").id;
  await root.ctx.dispose();
});

test.afterAll(async () => {
  if (!assignmentId) return;
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  await post(root, `/management/organization/responsibilities/${assignmentId}/revoke`, { reason: "Sfârșit test E2E" });
  await root.ctx.dispose();
});

test("stage 7 defect: worker reports, supervisor takes it to stage 3 without a manager, cutter receives it, only the manager returns it to cutting", async ({ browser }) => {
  test.setTimeout(180_000);
  const orderId = orders.stage7;
  const number = numberOf(orderId);
  const tailor = await signIn(browser, sup.tailor);
  const chief = await signIn(browser, sup.chief, 360, 740);
  const cutter = await signIn(browser, sup.cutter);
  const manager = await api(sup.manager);

  await report(tailor.page, orderId, /Draperie Gri/, "Metraj tăiat greșit", "Lungimea este mai scurtă cu 20 cm");
  await expect(chief.page.locator(".live-notice")).toContainText(`Problemă nouă raportată la comanda #${number}`, { timeout: 15_000 });
  const pendingBefore = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  expect(pendingBefore.items.some((item: { order: { id: string } }) => item.order.id === orderId), "an internal report never reaches the managers").toBe(false);

  // The supervisor's own Staff screen; opening it changes nothing.
  await go(chief.page, "/");
  await expect(chief.page.getByTestId("supervisor-entry")).toContainText("Comenzi cu probleme");
  await chief.page.getByTestId("supervisor-entry").click();
  await expect(chief.page.getByTestId("supervisor-screen")).toBeVisible();
  for (const width of [320, 360, 390]) { await chief.page.setViewportSize({ width, height: 740 }); expect(await overflow(chief.page), `tabs at ${width}`).toBeLessThanOrEqual(0); }
  await openCase(chief.page, number, "De verificat");
  await expect(chief.page.getByText("Tivul lateral").first()).toBeVisible();
  await expect(chief.page.getByText("Mihai Tăiere").first()).toBeVisible();
  const review = chief.page.getByTestId("supervisor-review");
  await review.getByLabel("Motivul confirmat").selectOption({ label: "Metraj tăiat greșit" });
  await typeLabel(review, labels[number]);
  await expect(chief.page.getByTestId("exception-status")).toHaveText("În grija șefului de croitorie");
  await chief.page.screenshot({ path: "e2e/.runtime/supervision-custody-360.png", fullPage: true });
  expect(await stageOf(orderId), "stage 7 -> stage 3, no production owner while the supervisor holds it").toEqual({ stage: "workshop-receiving", owner: null });
  const pendingCustody = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  expect(pendingCustody.items.some((item: { order: { id: string } }) => item.order.id === orderId), "7 -> 3 needed no manager").toBe(false);
  await expect(tailor.page.getByTestId("exception-status")).toHaveText("În grija șefului de croitorie", { timeout: 15_000 });

  await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");

  // The cutter is asked live, records physical receipt with the label and states a position separately.
  const notice = cutter.page.locator(".live-notice");
  await expect(notice).toContainText(`Comanda #${number} este trimisă la tăiere`, { timeout: 15_000 });
  await notice.getByRole("button", { name: "Deschide cererea" }).click();
  const receipt = cutter.page.getByTestId("cutter-receipt");
  await expect(receipt).toContainText("Nu înseamnă că recunoști o greșeală.");
  await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
  await receipt.getByRole("radio", { name: "Recunosc eroarea de tăiere" }).check();
  await typeLabel(receipt, labels[number]);
  await expect(cutter.page.getByTestId("exception-status")).toHaveText("Așteaptă aprobarea managerului");
  await expect(cutter.page.getByText("Returnarea la tăiere așteaptă aprobarea managerului operațional.")).toBeVisible();
  expect((await stageOf(orderId)).stage, "receipt alone never returns the order to cutting").toBe("workshop-receiving");

  const pending = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  const item = pending.items.find((entry: { order: { id: string } }) => entry.order.id === orderId);
  expect(item.type).toBe("tailoring_defect");
  expect((await post(manager, `/management/production-exceptions/${item.id}/decision`, { expectedVersion: item.version, decision: "approve" })).status(), "the manager states the attribution").toBe(422);
  const approved = await post(manager, `/management/production-exceptions/${item.id}/decision`, { expectedVersion: item.version, decision: "approve", attribution: "cutter" });
  expect(approved.status(), await approved.text()).toBe(200);

  await expect(cutter.page.getByText("✓ Aprobarea a fost acordată. Lucrarea poate fi refăcută.")).toBeVisible({ timeout: 15_000 });
  expect(await stageOf(orderId), "only after the approval: stage 2, owned by the cutter").toEqual({ stage: "material-preparation", owner: "Mihai Tăiere" });
  await cutter.page.getByRole("button", { name: "Vezi comanda" }).click();
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Aprobată · se reface la tăiere", { timeout: 15_000 });
  for (const who of [tailor, chief, cutter]) { expect(await who.unreloaded()).toBe(true); expect(who.errors).toEqual([]); }
  await manager.ctx.dispose();
  for (const who of [tailor, chief, cutter]) await who.context.close();
});

test("supervisor dismisses a stage 5 report: the worker keeps the order where it was", async ({ browser }) => {
  const orderId = orders.stage5;
  const number = numberOf(orderId);
  const tailor = await signIn(browser, sup.tailor);
  const chief = await signIn(browser, sup.chief, 360, 740);
  await report(tailor.page, orderId, /Voal Alb/, "Culoare tăiată greșit", "Nuanța pare diferită");
  await openCase(chief.page, number, "De verificat");
  const review = chief.page.getByTestId("supervisor-review");
  await review.getByLabel(/Comentariu/).fill("Nuanța corespunde comenzii");
  await review.getByRole("button", { name: "Închide raportul (nu este o problemă)" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Închisă de șeful de croitorie");
  await expect(tailor.page.getByTestId("exception-status")).toHaveText("Închisă de șeful de croitorie", { timeout: 15_000 });
  await expect(tailor.page.getByText("Nuanța corespunde comenzii", { exact: true })).toBeVisible();
  await tailor.page.getByRole("button", { name: "Vezi comanda" }).click();
  await expect(tailor.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  expect(tailor.errors).toEqual([]);
  expect(chief.errors).toEqual([]);
  await tailor.context.close();
  await chief.context.close();
});

test("manager rejects the cutting return: the order stays blocked with the supervisor until she restores it with the product", async ({ browser }) => {
  test.setTimeout(150_000);
  const orderId = orders.stage3;
  const number = numberOf(orderId);
  const tailor = await signIn(browser, sup.tailor);
  const chief = await signIn(browser, sup.chief, 360, 740);
  const cutter = await signIn(browser, sup.cutter);
  const manager = await api(sup.manager);
  await report(tailor.page, orderId, /Voal Alb/, "Metraj tăiat greșit", "Pare scurt");
  await openCase(chief.page, number, "De verificat");
  const review = chief.page.getByTestId("supervisor-review");
  await review.getByLabel("Motivul confirmat").selectOption({ label: "Metraj tăiat greșit" });
  await typeLabel(review, labels[number]);
  await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");
  await go(cutter.page, "/");
  await cutter.page.locator(".live-notice").getByRole("button", { name: "Deschide cererea" }).click({ timeout: 15_000 });
  const receipt = cutter.page.getByTestId("cutter-receipt");
  await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
  await receipt.getByRole("radio", { name: /Contest responsabilitatea/ }).check();
  await receipt.getByLabel(/Comentariu/).fill("Am tăiat după fișa de producție");
  await typeLabel(receipt, labels[number]);
  await expect(cutter.page.getByTestId("exception-status")).toHaveText("Așteaptă aprobarea managerului");

  const pending = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  const item = pending.items.find((entry: { order: { id: string } }) => entry.order.id === orderId);
  const rejected = await post(manager, `/management/production-exceptions/${item.id}/decision`, { expectedVersion: item.version, decision: "reject", comment: "Fișa era corectă" });
  expect(rejected.status(), await rejected.text()).toBe(200);

  await expect(chief.page.getByTestId("exception-status")).toHaveText("Returnarea la tăiere a fost respinsă", { timeout: 15_000 });
  await expect(chief.page.getByText("Comanda rămâne blocată în grija șefului de croitorie")).toBeVisible();
  const restore = chief.page.getByTestId("supervisor-restore");
  await restore.getByLabel(/Motiv/).fill("Produsul a revenit la croitorie");
  await typeLabel(restore, labels[number]);
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Rezolvată în croitorie");
  await expect(chief.page.getByText("Readusă în producție la croitorie")).toBeVisible();
  for (const width of [320, 360, 390]) { await chief.page.setViewportSize({ width, height: 740 }); expect(await overflow(chief.page), `case at ${width}`).toBeLessThanOrEqual(0); }
  await chief.page.screenshot({ path: "e2e/.runtime/supervision-restored-320.png", fullPage: true });
  expect(await stageOf(orderId), "restored at the detected stage with the original owner").toEqual({ stage: "workshop-receiving", owner: "Elena Croitorie" });
  await go(tailor.page, `/orders/${encodeURIComponent(orderId)}`);
  await expect(tailor.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  for (const who of [tailor, chief, cutter]) expect(who.errors).toEqual([]);
  await manager.ctx.dispose();
  for (const who of [tailor, chief, cutter]) await who.context.close();
});
