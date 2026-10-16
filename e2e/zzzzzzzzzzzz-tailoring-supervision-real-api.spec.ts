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
  // The account language decides the greeting (the EX-000001 journey uses a Turkish tailor account).
  await expect(page.getByRole("heading", { name: /^(Bună|Merhaba),/ })).toBeVisible();
  await page.evaluate(() => { (window as unknown as { __noReload: boolean }).__noReload = true; });
  return { context, page, errors, unreloaded: () => page.evaluate(() => (window as unknown as { __noReload?: boolean }).__noReload === true) };
}
const go = (page: Page, to: string) => page.evaluate((target) => { window.history.pushState({}, "", target); window.dispatchEvent(new PopStateEvent("popstate")); }, to);
/** The bottom-bar scanner with a typed label code (the fake camera shows one fixed QR); the code never enters a URL. */
async function scanCode(page: Page, code: string) {
  await page.getByRole("button", { name: "Introdu manual numărul / codul comenzii" }).click();
  await page.getByLabel(/Număr \/ cod comandă/).fill(code);
  await page.getByRole("button", { name: "Caută comanda" }).click();
  expect(page.url()).not.toContain("ARASYA");
}
/** A request's QR action opens the one scanner for that request; the action itself happens in the scanner. */
async function scanFor(page: Page, section: string, code: string): Promise<Locator> {
  await page.getByTestId(section).getByRole("button", { name: "Deschide scanerul" }).click();
  await expect(page).toHaveURL(/\/scan\?exception=[0-9a-f-]{36}$/);
  await scanCode(page, code);
  const action = page.getByTestId("scan-exception").getByTestId(section);
  await expect(action).toBeVisible();
  await expect(action.getByTestId("code-reused")).toBeVisible();
  return action;
}
/** The committed answer in the scanner, then the request screen again. */
async function committed(page: Page, status: string) {
  await expect(page.getByTestId("scan-exception-done-status")).toHaveText(status);
  await page.getByRole("button", { name: "Vezi cererea" }).click();
  await expect(page.getByTestId("exception-status")).toHaveText(status);
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
let orders = { stage7: "", stage5: "", stage3: "", stage8: "" };
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
  await expect(chief.page.getByTestId("supervisor-review").getByTestId("scan-prompt")).toContainText("scanează eticheta lui cu scanerul principal");
  const review = await scanFor(chief.page, "supervisor-review", labels[number]);
  for (const width of [320, 360, 390]) { await chief.page.setViewportSize({ width, height: 740 }); expect(await overflow(chief.page), `scanner review at ${width}`).toBeLessThanOrEqual(0); }
  await review.getByLabel("Motivul confirmat").selectOption({ label: "Metraj tăiat greșit" });
  await review.getByRole("button", { name: "Confirmă problema și preia produsul" }).click();
  await committed(chief.page, "În grija șefului de croitorie");
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
  await expect(cutter.page.getByTestId("cutter-receipt")).toContainText("Nu înseamnă că recunoști o greșeală.");
  const receipt = await scanFor(cutter.page, "cutter-receipt", labels[number]);
  await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
  await receipt.getByRole("radio", { name: "Recunosc eroarea de tăiere" }).check();
  await receipt.getByRole("button", { name: "Confirmă primirea la tăiere" }).click();
  await expect(cutter.page.getByTestId("scan-exception-done-next")).toHaveText("Următorul pas: aprobarea managerului");
  await committed(cutter.page, "Așteaptă aprobarea managerului");
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

test("manager rejects the cutting return: the order stays blocked until the tailor scans the product back and confirms receipt", async ({ browser }) => {
  test.setTimeout(150_000);
  const orderId = orders.stage3;
  const number = numberOf(orderId);
  const tailor = await signIn(browser, sup.tailor);
  const chief = await signIn(browser, sup.chief, 360, 740);
  const cutter = await signIn(browser, sup.cutter);
  const manager = await api(sup.manager);
  await report(tailor.page, orderId, /Voal Alb/, "Metraj tăiat greșit", "Pare scurt");
  await openCase(chief.page, number, "De verificat");
  const review = await scanFor(chief.page, "supervisor-review", labels[number]);
  await review.getByLabel("Motivul confirmat").selectOption({ label: "Metraj tăiat greșit" });
  await review.getByRole("button", { name: "Confirmă problema și preia produsul" }).click();
  await committed(chief.page, "În grija șefului de croitorie");
  await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");
  await go(cutter.page, "/");
  await cutter.page.locator(".live-notice").getByRole("button", { name: "Deschide cererea" }).click({ timeout: 15_000 });
  const receipt = await scanFor(cutter.page, "cutter-receipt", labels[number]);
  await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
  await receipt.getByRole("radio", { name: /Contest responsabilitatea/ }).check();
  await expect(receipt.getByRole("button", { name: "Confirmă primirea la tăiere" })).toBeDisabled();
  await receipt.getByLabel(/Comentariu/).fill("Am tăiat după fișa de producție");
  await receipt.getByRole("button", { name: "Confirmă primirea la tăiere" }).click();
  await committed(cutter.page, "Așteaptă aprobarea managerului");

  const pending = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  const item = pending.items.find((entry: { order: { id: string } }) => entry.order.id === orderId);
  const rejected = await post(manager, `/management/production-exceptions/${item.id}/decision`, { expectedVersion: item.version, decision: "reject", comment: "Fișa era corectă" });
  expect(rejected.status(), await rejected.text()).toBe(200);

  await expect(chief.page.getByTestId("exception-status")).toHaveText("Returnarea la tăiere a fost respinsă", { timeout: 15_000 });
  await expect(chief.page.getByText("Comanda rămâne blocată în grija șefului de croitorie")).toBeVisible();
  const restore = await scanFor(chief.page, "supervisor-restore", labels[number]);
  await restore.getByLabel(/Motiv/).fill("Produsul a revenit la croitorie");
  await restore.getByRole("button", { name: "Predă produsul înapoi la croitorie" }).click();
  // The supervisor's scan alone releases nothing: the order stays blocked, unowned, at the custody stage.
  await committed(chief.page, "Așteaptă primirea înapoi la croitorie");
  await expect(chief.page.getByTestId("supervisor-restore")).toContainText("Predă produsul altui angajat");
  expect(await stageOf(orderId), "pending handover: still blocked, no production owner").toEqual({ stage: "workshop-receiving", owner: null });

  // The original tailor is asked live, ticks the receipt, scans the label: only now the order is released to them.
  const notice = tailor.page.locator(".live-notice");
  await expect(notice).toContainText(`Comanda #${number} îți este predată înapoi`, { timeout: 15_000 });
  await notice.getByRole("button", { name: "Deschide cererea" }).click();
  await expect(tailor.page.getByTestId("restore-receipt")).toContainText("Primește comanda înapoi");
  const back = await scanFor(tailor.page, "restore-receipt", labels[number]);
  await back.getByRole("checkbox", { name: "Am primit produsul în mână și continui lucrul." }).check();
  await back.getByRole("button", { name: "Confirmă primirea produsului" }).click();
  await expect(tailor.page.getByTestId("scan-exception-owner")).toHaveText("✓ Comanda este la tine și poți continua lucrul.");
  await committed(tailor.page, "Rezolvată în croitorie");
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Rezolvată în croitorie", { timeout: 15_000 });
  await expect(chief.page.getByText("Predată înapoi la croitorie · așteaptă confirmarea primirii")).toBeVisible();
  await expect(chief.page.getByText("Primită înapoi (etichetă scanată) · producția continuă")).toBeVisible();
  for (const width of [320, 360, 390]) { await chief.page.setViewportSize({ width, height: 740 }); expect(await overflow(chief.page), `case at ${width}`).toBeLessThanOrEqual(0); }
  await chief.page.screenshot({ path: "e2e/.runtime/supervision-restored-320.png", fullPage: true });
  expect(await stageOf(orderId), "restored at the detected stage with the original owner, after their receipt").toEqual({ stage: "workshop-receiving", owner: "Elena Croitorie" });
  await go(tailor.page, `/orders/${encodeURIComponent(orderId)}`);
  await expect(tailor.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  for (const who of [tailor, chief, cutter]) expect(who.errors).toEqual([]);
  await manager.ctx.dispose();
  for (const who of [tailor, chief, cutter]) await who.context.close();
});

/**
 * The EX-000001 incident shape, end to end through the universal scanner only (bottom bar, one scan per action):
 * a defect found at ironing, the supervisor withdraws the return before the cutter received it, the cutter's scan
 * explains and changes nothing, the designated tailor (Turkish account) receives it with the same code, reports
 * again, and the cutter's single scan opens exactly one manager decision; the manager returns the order to cutting.
 */
test("EX-000001 shape through the universal scanner: withdrawn return, cutter cannot act, Turkish receipt back, second return with one approval", async ({ browser }) => {
  test.setTimeout(240_000);
  const orderId = orders.stage8;
  const number = numberOf(orderId);
  const code = labels[number];
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const tailorUser = (await (await root.ctx.get(`/management/employees?search=${encodeURIComponent(sup.tailor)}`)).json()).items.find((item: { username: string }) => item.username === sup.tailor);
  const setLocale = async (locale: string | null) => {
    const r = await root.ctx.put(`/management/employees/${tailorUser.id}/staff-locale`, { headers: { "X-CSRF-Token": root.csrf }, data: { locale } });
    expect(r.status(), await r.text()).toBe(200);
  };
  await setLocale("tr");
  const tailorApi = await api(sup.tailor);
  const manager = await api(sup.manager);
  const pendingFor = async () => (await (await manager.ctx.get("/management/production-exceptions?view=pending")).json()).items.filter((entry: { order: { id: string } }) => entry.order.id === orderId);
  const reportViaApi = async (comment: string) => {
    const order = await (await tailorApi.ctx.get(`/orders/${encodeURIComponent(orderId)}`)).json();
    const reported = await post(tailorApi, `/orders/${encodeURIComponent(orderId)}/fault-reports`, { expectedVersion: order.productionVersion, itemIds: [order.products[0].id], reasonKey: "material-defect", comment });
    expect(reported.status(), await reported.text()).toBe(201);
  };
  try {
    // 1. A tailoring defect detected at ironing (stage 8).
    expect((await stageOf(orderId)).stage).toBe("ironing");
    await reportViaApi("Fir tras observat la călcat");
    const chief = await signIn(browser, sup.chief, 360, 740);
    // 2. The supervisor scans with the bottom-bar scanner: the review is offered in the scanner itself.
    await go(chief.page, "/scan");
    await scanCode(chief.page, code);
    const review = chief.page.getByTestId("scan-exception").getByTestId("supervisor-review");
    await expect(review).toBeVisible();
    await expect(chief.page.getByTestId("scan-exception")).toContainText(`Comanda #${number}`);
    await review.getByLabel("Motivul confirmat").selectOption({ label: "Defect material neobservat" });
    await review.getByRole("button", { name: "Confirmă problema și preia produsul" }).click();
    await committed(chief.page, "În grija șefului de croitorie");
    // 3. The supervisor sends it to cutting (no product scan needed).
    await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
    await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");
    // 4. Before the cutter received it, the supervisor deliberately withdraws the return, with the product in hand.
    const withdraw = await scanFor(chief.page, "supervisor-restore", code);
    await expect(withdraw).toContainText("Anulează trimiterea la tăiere");
    await withdraw.getByLabel(/Motiv/).fill("Nu mai trimitem la tăiere");
    await expect(withdraw.getByRole("button", { name: "Predă produsul înapoi la croitorie" })).toBeDisabled();
    await withdraw.getByTestId("cancel-return-confirm").getByRole("checkbox").check();
    await withdraw.getByRole("button", { name: "Predă produsul înapoi la croitorie" }).click();
    // 5. EX-000001 state: awaiting_restore_receipt, stage 3, no owner, no manager decision.
    await committed(chief.page, "Așteaptă primirea înapoi la croitorie");
    expect(await stageOf(orderId)).toEqual({ stage: "workshop-receiving", owner: null });
    expect(await pendingFor()).toHaveLength(0);

    // 6-7. The cutter scans the same label with the bottom-bar scanner: told who acts next, nothing offered, nothing changes.
    const cutter = await signIn(browser, sup.cutter);
    const cutterWrites: string[] = [];
    cutter.page.on("request", (request) => { if (request.method() !== "GET" && !request.url().endsWith("/production-exceptions/scan")) cutterWrites.push(request.url()); });
    await go(cutter.page, "/scan");
    await scanCode(cutter.page, code);
    const cutterSheet = cutter.page.getByTestId("scan-exception");
    await expect(cutterSheet.getByTestId("scan-exception-waiting")).toContainText("Comanda este acum în lucru la alt angajat.");
    await expect(cutterSheet.getByTestId("scan-exception-next")).toHaveText("Următorul pas: primirea înapoi la croitorie de către Elena Croitorie");
    await expect(cutterSheet.locator(".fault-action")).toHaveCount(0);
    expect(cutterWrites, "the cutter's scan sent no command (no intake, no claim, no receipt)").toEqual([]);
    expect(await stageOf(orderId)).toEqual({ stage: "workshop-receiving", owner: null });
    expect(await pendingFor()).toHaveLength(0);

    // 8-12. The designated tailor (Turkish account) scans the SAME label: physical receipt in the scanner, one command.
    const tailor = await signIn(browser, sup.tailor, 360, 800);
    const receives: string[] = [];
    tailor.page.on("request", (request) => { if (request.method() === "POST" && request.url().endsWith("/receive")) receives.push(request.postData() ?? ""); });
    await go(tailor.page, "/scan");
    await tailor.page.getByRole("button", { name: "Sipariş numarasını / kodunu elle gir" }).click();
    await tailor.page.getByLabel(/Sipariş numarası \/ kodu/).fill(code);
    await tailor.page.getByRole("button", { name: "Siparişi bul" }).click();
    expect(tailor.page.url()).not.toContain("ARASYA");
    const sheet = tailor.page.getByTestId("scan-exception");
    await expect(sheet).toContainText("Bu ürün sana geri teslim edilmek üzere bekliyor.");
    await expect(sheet).toContainText("Teslim eden");
    await expect(sheet).toContainText("VASILOAIA IULIANA");
    await expect(sheet).toContainText("Ütü");
    for (const width of [320, 360, 390]) { await tailor.page.setViewportSize({ width, height: 800 }); expect(await overflow(tailor.page), `Turkish receipt at ${width}`).toBeLessThanOrEqual(0); }
    const back = sheet.getByTestId("restore-receipt");
    const confirm = back.getByRole("button", { name: "Ürün teslimini onayla" });
    await expect(confirm).toBeDisabled();
    await back.getByRole("checkbox", { name: "Ürünü elime aldım ve çalışmaya devam ediyorum." }).check();
    await confirm.dblclick();
    await expect(tailor.page.getByTestId("scan-exception-done")).toContainText("Sipariş güncellendi.");
    await expect(tailor.page.getByTestId("scan-exception-done-status")).toHaveText("Terzihanede çözüldü");
    await expect(tailor.page.getByTestId("scan-exception-owner")).toHaveText("✓ Sipariş sende, çalışmaya devam edebilirsin.");
    expect(receives, "a double tap sent exactly one receipt").toHaveLength(1);
    expect(Object.keys(JSON.parse(receives[0])).sort(), "the Turkish screen sends the same codes as any other").toEqual(["confirmed", "expectedVersion", "qrToken"]);
    expect(JSON.parse(receives[0]).qrToken, "the one scanned code is the evidence").toBe(code);
    expect(await stageOf(orderId)).toEqual({ stage: "ironing", owner: "Elena Croitorie" });
    expect(await tailor.page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain("ARASYA");

    // 13-14. The defect persists: a new report; the supervisor accepts and returns it to cutting again.
    await reportViaApi("Defectul persistă după călcat");
    await go(chief.page, "/scan");
    await scanCode(chief.page, code);
    const review2 = chief.page.getByTestId("scan-exception").getByTestId("supervisor-review");
    await review2.getByLabel("Motivul confirmat").selectOption({ label: "Defect material neobservat" });
    await review2.getByRole("button", { name: "Confirmă problema și preia produsul" }).click();
    await committed(chief.page, "În grija șefului de croitorie");
    await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
    await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");

    // 15-17. The cutter scans once more with the bottom-bar scanner and confirms there; the first answer is lost on
    // the way back, the retry replays the same command, and exactly one manager decision exists.
    let lost = false;
    await cutter.page.route("**/production-exceptions/*/acknowledge", async (route) => {
      if (lost) { await route.continue(); return; }
      lost = true;
      await route.fetch();
      await route.abort("connectionreset");
    });
    // Still on the scanner from the first scan: "scan another code" starts a new scan.
    await cutter.page.getByRole("button", { name: "Scanează alt cod" }).click();
    await scanCode(cutter.page, code);
    const receipt = cutter.page.getByTestId("scan-exception").getByTestId("cutter-receipt");
    await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
    await receipt.getByRole("radio", { name: /Contest responsabilitatea/ }).check();
    await receipt.getByLabel(/Comentariu/).fill("Defect de material");
    await receipt.getByRole("button", { name: "Confirmă primirea la tăiere" }).click();
    const lostAnswer = cutter.page.getByTestId("scan-exception").locator(".error-state");
    await expect(lostAnswer).toBeVisible();
    expect(await pendingFor(), "the lost answer was committed on the server").toHaveLength(1);
    await lostAnswer.getByRole("button").first().click();
    await receipt.getByRole("button", { name: "Confirmă primirea la tăiere" }).click();
    await expect(cutter.page.getByTestId("scan-exception-done-status")).toHaveText("Așteaptă aprobarea managerului");
    await expect(cutter.page.getByTestId("scan-exception-done-next")).toHaveText("Următorul pas: aprobarea managerului");
    const pending = await pendingFor();
    expect(pending, "exactly one pending manager approval").toHaveLength(1);
    expect((await stageOf(orderId)).stage, "nothing moves before the manager").toBe("workshop-receiving");

    // 18-19. The manager approves through the Dashboard API: the whole order (no partial rework) returns to cutting.
    const approved = await post(manager, `/management/production-exceptions/${pending[0].id}/decision`, { expectedVersion: pending[0].version, decision: "approve", attribution: "none" });
    expect(approved.status(), await approved.text()).toBe(200);
    expect(await stageOf(orderId)).toEqual({ stage: "material-preparation", owner: "Mihai Tăiere" });
    for (const who of [chief, cutter, tailor]) { expect(who.errors.filter((error) => !/Failed to fetch|NetworkError|ERR_CONNECTION_RESET|net::/.test(error))).toEqual([]); await who.context.close(); }
  } finally {
    await setLocale(null);
    await tailorApi.ctx.dispose();
    await manager.ctx.dispose();
    await root.ctx.dispose();
  }
});
