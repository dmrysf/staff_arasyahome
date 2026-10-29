import AxeBuilder from "@axe-core/playwright";
import { expect, request, test, type APIRequestContext, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

// Confirmare Staff workspace (API 2.40.0, Staff 2.30.0) against the real Operations API, last of the suites (it
// activates both shop sources in the disposable E2E database with the TEST override of WORKFLOW_READY). Covers the
// operator's day (next step, queue, recorded calls, the shop's own order with the real telephone number and prices read
// through source access from a loopback TEST shop, line and price review, approval), a shop change after the review,
// a 409 after a supervisor's reassignment, the supervisor view, isolation of sources and channels, session expiry,
// revoked access, a network interruption, layouts from 320 to 1920 px and WCAG 2.2 AA. Generic TEST people only.
// API 2.41.0 / Staff 2.31.0 (Phase 5): the money of an order read from the TEST shop, a bank transfer recorded by one
// finance employee and verified by another (never by the recorder), nothing shown as done before the shop confirms.
// API 2.42.0 / Staff 2.32.0 (Phase 6): the authorized shop employee hands the cleared order to production through the
// central production document (revision 1, print, a reprint only with a reason); the cutting QR scan starts production.
type Person = { username: string; name: string; id: string };
type Fixture = { skip?: string; password: string; people: Record<"operator" | "colleague" | "outlet" | "supervisor" | "finrec" | "finapp" | "handoff", Person>; orders: Record<"call" | "cod" | "colleague" | "noPrice" | "unassigned" | "outlet" | "legacy" | "undetermined" | "finance", string> };
const root = path.resolve(import.meta.dirname, "..");
const base = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as { users: { ana: string }; password: string };
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const SHOTS = path.join(root, "e2e", ".runtime", "confirmare");
const php = (args: string[] = []) => execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-confirmare-fixture.php"), ...args], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] });
let F: Fixture;
const number = (globalId: string) => globalId.split(":")[1];
const orderPath = (globalId: string) => `/confirmare/orders/${encodeURIComponent(globalId)}`;
// Staff 2.41.0 (API 2.50.0): a finance scope works an exceptional money task in the finance-only money panel, never the Confirmare order.
const financeOrderPath = (globalId: string) => `/confirmare/finance/orders/${encodeURIComponent(globalId)}`;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const capture = async (page: Page, name: string) => { mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true }); };

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  F = JSON.parse(php()) as Fixture;
});

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(F?.password ?? base.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  return { context, page, errors };
}

async function api(username: string, password: string): Promise<{ ctx: APIRequestContext; csrf: string }> {
  const ctx = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await ctx.post("/auth/login", { data: { username, password } });
  expect(login.status(), `login ${username}`).toBe(200);
  return { ctx, csrf: (await login.json()).csrfToken as string };
}
const send = (who: { ctx: APIRequestContext; csrf: string }, method: "post" | "put", url: string, data: unknown) => who.ctx[method](url, { headers: { "X-CSRF-Token": who.csrf, "Idempotency-Key": `e2e-cf-${Date.now()}-${Math.random().toString(36).slice(2)}` }, data });
const detailOf = async (who: { ctx: APIRequestContext }, globalId: string) => (await (await who.ctx.get(`/confirmare/orders/${encodeURIComponent(globalId)}`)).json()) as { version: number; status: string; events: { action: string }[]; assignment: { employeeId: string } | null };

async function accessible(page: Page, screen: string) {
  await page.waitForTimeout(300);
  const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>("main button, main a[href], main input:not([type=hidden]), main select, main textarea, main [role=tab]")]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0 && getComputedStyle(element).visibility !== "hidden" && !element.closest("[aria-hidden=true]"); })
    .map((element) => { const own = element.getBoundingClientRect(); const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: Math.max(own.width, target.width), height: Math.max(own.height, target.height) }; })
    .filter((item) => item.width < 44 || item.height < 44)
    .map((item) => `${item.name} ${Math.round(item.width)}×${Math.round(item.height)}`));
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

/** Staff 2.40.0: the guided order. The open step and its task, as the screen states them. */
async function at(page: Page, step: string, task: string, state = "active") {
  await expect(page.getByTestId(`cfw-step-${step}`)).toHaveAttribute("data-state", state);
  await expect(page.getByTestId("cfw-task")).toHaveAttribute("data-task", task);
}
async function openMore(page: Page) {
  if (await page.getByTestId("cfw-more").getAttribute("open") === null) await page.getByTestId("cfw-more-open").click();
}
/** Every product, one by one, checked with the customer (the screen moves to the next by itself). */
async function checkProducts(page: Page, count: number) {
  for (let index = 1; index <= count; index += 1) {
    await expect(page.getByTestId("cfw-product-of")).toContainText(`Produsul ${index} din ${count}`);
    await page.getByTestId("cfw-product-check").click();
  }
}

async function recordCall(page: Page, outcome: RegExp, keyboard = false) {
  const radio = page.getByRole("radio", { name: outcome });
  const happened = page.getByTestId("cf-call-happened");
  const submit = page.getByTestId("cf-record-call");
  await expect(submit).toBeDisabled();
  if (keyboard) {
    await radio.focus();
    await page.keyboard.press("Space");
    await happened.focus();
    await page.keyboard.press("Space");
    await expect(submit).toBeEnabled();
    await submit.focus();
    await page.keyboard.press("Enter");
  } else {
    await radio.check();
    await expect(submit, "an outcome alone never records a call").toBeDisabled();
    await happened.check();
    await submit.click();
  }
  await expect(page.getByTestId("cf-message")).toContainText("Apel înregistrat");
}

test("operator: next step, queue, recorded calls (never a cancellation), line and price review, one approval", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(120_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await expect(page.getByTestId("workspace-confirmare")).toBeVisible();
  await expect(page.getByTestId("cf-next-action")).toContainText(`#${number(F.orders.call)}`);
  await expect(page.getByTestId("cf-next-action")).toContainText("Sunați clientul");
  await expect(page.getByTestId("cf-contact-dependency")).toContainText("Arasya nu păstrează telefonul complet");
  await capture(page, "home-operator-390");

  await page.getByTestId("cf-open-queue").click();
  await expect(page).toHaveURL(/\/confirmare$/);
  const list = page.locator("#cf-tab-panel");
  await expect(list.getByTestId(`cf-row-${number(F.orders.call)}`)).toBeVisible();
  await expect(list.getByTestId(`cf-row-${number(F.orders.cod)}`)).toBeVisible();
  for (const hidden of [F.orders.legacy, F.orders.outlet, F.orders.undetermined, F.orders.colleague]) await expect(page.getByTestId(`cf-row-${number(hidden)}`)).toHaveCount(0);
  await expect(page.locator("body")).not.toContainText("TEST Client Confirmare");
  await page.getByRole("tab", { name: /Neatribuite/ }).click();
  await expect(page).toHaveURL(/tab=unassigned/);
  await expect(list.getByTestId(`cf-row-${number(F.orders.unassigned)}`)).toBeVisible();
  await capture(page, "queue-operator-390");
  await page.getByRole("tab", { name: /Ale mele/ }).click();

  await list.getByTestId(`cf-row-${number(F.orders.call)}`).click();
  await expect(page.getByTestId("cf-order")).toBeVisible();
  // One step open, one task: the customer's telephone first. Later steps are closed, nothing is approvable yet.
  await expect(page.getByTestId("cfw-progress-of")).toHaveText("Pasul 2 din 5");
  await at(page, "contact", "customer");
  await expect(page.getByTestId("cfw-step-take")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-step-final")).toHaveAttribute("data-state", "locked");
  await expect(page.getByTestId("cfw-step-payment"), "the verified online payment is a fact already").toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cf-customer-facts")).toContainText("TEST Client Confirmare");
  await expect(page.getByTestId("cf-customer-facts")).toContainText("07** *** ***");
  await expect(page.locator("body"), "the full number is shown only on request").not.toContainText("0700000123");
  await expect(page.getByTestId("cf-phone-in-shop")).toBeVisible();
  await expect(page.getByTestId("cf-approve"), "no approval form before the call").toHaveCount(0);
  await expect(page.getByTestId("cf-record-call"), "only the current task's form is open").toHaveCount(0);
  await expect(page.getByTestId("cf-history"), "the history waits in the additional details").toHaveCount(0);
  await capture(page, "order-before-call-390");
  await accessible(page, "order customer 390");
  // Opening, reloading and looking at the details change nothing on the server (no call, no event, no new version).
  const watcher = await api(F.people.supervisor.username, F.password);
  const untouched = await detailOf(watcher, F.orders.call);
  await page.reload();
  await at(page, "contact", "customer");
  await openMore(page);
  await page.getByTestId("cfw-more-open").click();
  const still = await detailOf(watcher, F.orders.call);
  expect(still.version, "navigation never changes the order").toBe(untouched.version);
  expect(still.events).toEqual(untouched.events);
  // The real number, read from the shop now (audited), never before; reading moves on to the call by itself.
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-call-billing")).toHaveAttribute("href", "tel:0700000123");
  await at(page, "contact", "call");
  await expect(page.getByTestId("cf-message"), "a read records no call").toHaveCount(0);

  await recordCall(page, /^Nu răspunde/);
  await expect(page.getByTestId("cf-order-status")).toHaveText("Nu răspunde");
  await at(page, "contact", "callAgain");
  await openMore(page);
  await expect(page.getByTestId("cf-history")).toContainText("Apel: Nu răspunde");
  await page.getByRole("button", { name: "Înapoi la Confirmare" }).click();
  await page.getByRole("tab", { name: /De reapelat/ }).click();
  await expect(list.getByTestId(`cf-row-${number(F.orders.call)}`)).toContainText("Nu răspunde");
  await list.getByTestId(`cf-row-${number(F.orders.call)}`).click();

  // The customer confirms on the second call; recorded with the keyboard only.
  await at(page, "contact", "callAgain");
  await recordCall(page, /^Confirmat/, true);
  await expect(page.getByTestId("cf-order-status")).toHaveText("Confirmat");
  // The products one by one; the review moves on by itself and only then opens the final review.
  await at(page, "contact", "products");
  await page.getByTestId("cfw-product-check").click();
  await expect(page.getByTestId("cfw-product-of")).toContainText("Produsul 2 din 2");
  // A reload keeps what the server recorded (the call) and never pretends the unsent product checks were saved.
  await page.reload();
  await at(page, "contact", "products");
  await expect(page.getByTestId("cfw-sub-call")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cf-lines-progress")).toHaveText("0 din 2 confirmate");
  await expect(page.getByTestId("cfw-sub-call")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-product-1")).toContainText("Draperie Velvet");
  await capture(page, "order-product-390");
  await accessible(page, "order product 390");
  await checkProducts(page, 2);
  await at(page, "final", "price");
  await expect(page.getByTestId("cfw-step-contact")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cf-approve"), "no approval before the prices are reviewed").toHaveCount(0);
  // The prices, read from the shop now (audited), never before.
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-view")).toBeVisible();
  await expect(page.getByTestId("cf-shop-in-sync")).toBeVisible();
  await expect(page.getByTestId("cf-shop-total")).toContainText("719,00");
  await capture(page, "order-shop-390");
  await page.getByTestId("cf-price-reviewed").click(); // ticking moves on to the approval by itself
  await at(page, "final", "approve");
  await expect(page.getByTestId("cfw-verification-state")).toHaveText("Toate verificările sunt îndeplinite.");
  await page.getByTestId("cfw-verification-open").click();
  await expect(page.getByTestId("cf-checklist")).toContainText("Comanda din magazin a fost citită în Staff");
  await expect(page.getByTestId("cfw-final-total")).toContainText("719,00");
  await expect(page.getByTestId("cf-approve")).toBeEnabled();
  await capture(page, "order-ready-390");
  await accessible(page, "order approve 390");
  await page.getByTestId("cf-approve").click();
  await expect(page.getByText(`Aprobați comanda #${number(F.orders.call)}?`)).toBeVisible();
  await page.getByTestId("cf-approve-confirm").dblclick();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda a fost aprobată.");
  await expect(page.getByTestId("cf-order-status")).toHaveText("Aprobată pentru producție");
  // The approval is step 3; the money (step 4) was a separate fact; the handoff is the operator's last step.
  await expect(page.getByTestId("cfw-step-final")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-step-final-summary")).toContainText("aceasta nu confirmă plata");
  await expect(page.getByTestId("cfw-progress-of")).toHaveText("Pasul 5 din 5");
  await openMore(page);
  await expect(page.getByTestId("cf-lines-progress")).toHaveText("2 din 2 confirmate");
  const supervisor = await api(F.people.supervisor.username, F.password);
  const approved = await detailOf(supervisor, F.orders.call);
  expect(approved.events.filter((event) => event.action === "approved"), "a double click approves once").toHaveLength(1);
  await capture(page, "order-approved-390");
  expect(op.errors).toEqual([]);
  await op.context.close();
});

test("a shop change after the review needs a fresh verification; a confirmed COD order waits for its advance", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await page.goto(orderPath(F.orders.cod));
  await expect(page.getByTestId("cf-order")).toBeVisible();
  await expect(page.getByTestId("cfw-step-payment"), "a COD order's money is not proven").toHaveAttribute("data-state", "locked");
  await page.getByTestId("cf-shop-load").click();
  await recordCall(page, /^Confirmat/);
  await checkProducts(page, 2);
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-advance")).toContainText("Avans 30%");
  await page.getByTestId("cf-price-reviewed").click(); // ticking moves on to the approval by itself
  await at(page, "final", "approve");
  // The shop changes an option while the operator still looks at the old order.
  php([`--change=${number(F.orders.cod)}`]);
  await page.getByTestId("cf-approve").click();
  await page.getByTestId("cf-approve-confirm").click();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda s-a schimbat în magazin");
  await expect(page.getByTestId("cf-changed")).toBeVisible();
  // The review reopens where it is invalid now: the call of the old order no longer counts, the ticks are gone.
  await at(page, "contact", "callAgain");
  await expect(page.getByTestId("cfw-step-final")).toHaveAttribute("data-state", "locked");
  await expect(page.getByTestId("cf-approve")).toHaveCount(0);
  await expect(page.getByTestId("cf-shop-view"), "the old shop view is dropped with the old review").toHaveCount(0);
  await openMore(page);
  await expect(page.getByTestId("cf-history")).toContainText("comanda s-a schimbat de atunci");
  await expect(page.getByTestId("cf-line-1")).toContainText("Neconfirmat");
  await capture(page, "order-changed-in-shop-390");
  await page.getByTestId("cfw-more-open").click();
  await recordCall(page, /^Confirmat/);
  await at(page, "contact", "products");
  await expect(page.getByTestId("cf-lines-progress")).toHaveText("0 din 2 confirmate");
  await checkProducts(page, 2);
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-in-sync")).toBeVisible();
  await page.getByTestId("cf-price-reviewed").click(); // ticking moves on to the approval by itself
  await page.getByTestId("cf-approve").click();
  await expect(page.getByRole("group", { name: /Aprobați comanda/ })).toContainText("producția rămâne blocată până când magazinul confirmă plata");
  await page.getByTestId("cf-approve-confirm").click();
  await expect(page.getByTestId("cf-order-status")).toHaveText("În așteptarea plății");
  // Approved is not paid: step 4 is open and waits; the handoff stays closed.
  await expect(page.getByTestId("cfw-step-final")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-step-payment")).not.toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-step-handoff")).toHaveAttribute("data-state", "locked");
  await expect(page.getByTestId("cf-financial")).toHaveText("Așteaptă plata");
  await capture(page, "order-payment-390");
  await accessible(page, "order payment 390");
  await openMore(page);
  await expect(page.getByRole("region", { name: "Producție", exact: true })).toContainText("Plata sau avansul confirmate de magazin");
  await page.goto("/confirmare?tab=payment");
  await expect(page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.cod)}`)).toContainText("În așteptarea plății");
  await capture(page, "queue-payment-390");
  expect(op.errors).toEqual([]);
  await op.context.close();
});

test("409: a supervisor reassigns an order a colleague still has open; the colleague's view reloads safely", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  const colleague = await signIn(browser, F.people.colleague.username);
  const page = colleague.page;
  await page.goto(orderPath(F.orders.colleague));
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-call-billing")).toBeVisible();
  await expect(page.getByTestId("cf-record-call")).toBeVisible();
  const supervisor = await api(F.people.supervisor.username, F.password);
  const before = await detailOf(supervisor, F.orders.colleague);
  expect((await send(supervisor, "post", `/confirmare/orders/${encodeURIComponent(F.orders.colleague)}/reassign`, { expectedVersion: before.version, employeeId: F.people.operator.id })).status()).toBe(200);
  await page.getByRole("radio", { name: /^Confirmat/ }).check();
  await page.getByTestId("cf-call-happened").check();
  await page.getByTestId("cf-record-call").click();
  await expect(page.getByTestId("cf-message")).toContainText("Comanda a fost modificată între timp");
  await expect(page.getByText(`Atribuită: ${F.people.operator.name}`).first()).toBeVisible();
  await expect(page.getByTestId("cf-record-call")).toHaveCount(0);
  await at(page, "take", "colleague", "waiting");
  await expect(page.getByTestId("cfw-owner")).toContainText(F.people.operator.name);
  // The shop data goes with the access: the former assignee sees no telephone number any more.
  await openMore(page);
  await expect(page.getByTestId("cf-shop-not-yours")).toBeVisible();
  await expect(page.locator("body")).not.toContainText("0700000123");
  const after = await detailOf(supervisor, F.orders.colleague);
  expect(after.events.some((event) => event.action === "approved")).toBe(false);
  expect(after.assignment?.employeeId).toBe(F.people.operator.id);
  expect(colleague.errors).toEqual([]);
  await colleague.context.close();
});

test("supervisor: team, leave with redistribution, alerts and attention; no bypass of the call or the price", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const sup = await signIn(browser, F.people.supervisor.username, 1440, 900);
  const page = sup.page;
  await expect(page.getByTestId("workspace-confirmare")).toBeVisible();
  await expect(page.getByTestId("cf-open-team")).toBeVisible();
  await capture(page, "home-supervisor-1440");
  await page.getByTestId("cf-open-team").click();
  await expect(page.getByTestId("cf-team")).toBeVisible();
  for (const person of [F.people.operator, F.people.colleague, F.people.outlet]) await expect(page.getByTestId(`cf-person-${person.id}`)).toContainText(person.name);
  await expect(page.getByTestId(`cf-person-${F.people.supervisor.id}`)).toHaveCount(0);
  const attention = page.getByRole("region", { name: "Atenție" });
  await expect(attention.getByTestId(`cf-row-${number(F.orders.noPrice)}`)).toBeVisible();
  await expect(attention.getByTestId(`cf-row-${number(F.orders.undetermined)}`)).toBeVisible();
  await capture(page, "team-1440");
  const colleagueCard = page.getByTestId(`cf-person-${F.people.colleague.id}`);
  await colleagueCard.getByTestId("cf-leave").click();
  await colleagueCard.getByTestId("cf-leave-confirm").click();
  await expect(page.getByText("Concediu înregistrat.")).toBeVisible();
  await expect(colleagueCard).toContainText("În concediu");
  await expect(colleagueCard).toContainText("0 comenzi deschise");
  await colleagueCard.getByTestId("cf-leave").click();
  await colleagueCard.getByTestId("cf-leave-confirm").click();
  await expect(colleagueCard).toContainText("Disponibil");

  // No price evidence: even a supervisor cannot approve.
  await page.goto(orderPath(F.orders.noPrice));
  await expect(page.getByTestId("cf-price-missing"), "said from the start, not only at the final review").toBeVisible();
  await expect(page.getByTestId("cf-approve")).toHaveCount(0);
  await expect(page.getByTestId("cfw-supervisor")).toBeVisible();
  // Unknown submission instant: visible to the supervisor, never approvable, never recorded as a call.
  await page.goto(orderPath(F.orders.undetermined));
  await at(page, "take", "undetermined", "attention");
  await expect(page.getByTestId("cfw-task-title")).toHaveText("Comandă în afara fluxului Confirmare");
  await expect(page.getByTestId("cfw-step-contact")).toHaveAttribute("data-state", "locked");
  await expect(page.getByTestId("cf-record-call")).toHaveCount(0);
  await expect(page.getByTestId("cf-approve")).toHaveCount(0);
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});

test("isolation: another shop, legacy orders and people without a scope never reach Confirmare", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  const outlet = await signIn(browser, F.people.outlet.username);
  await outlet.page.goto("/confirmare");
  await expect(outlet.page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.outlet)}`)).toBeVisible();
  await expect(outlet.page.getByTestId(`cf-row-${number(F.orders.call)}`)).toHaveCount(0);
  await expect(outlet.page.getByRole("radiogroup", { name: "Magazin" })).toHaveCount(0);
  await outlet.page.goto(orderPath(F.orders.call));
  await expect(outlet.page.getByText("Comanda nu există sau nu mai este disponibilă pentru dumneavoastră.")).toBeVisible();
  await outlet.page.goto("/confirmare/team");
  await expect(outlet.page.getByTestId("cf-team")).toHaveCount(0);
  await outlet.context.close();

  const supervisor = await signIn(browser, F.people.supervisor.username);
  await supervisor.page.goto(orderPath(F.orders.legacy));
  await expect(supervisor.page.getByText("Comanda nu există sau nu mai este disponibilă pentru dumneavoastră.")).toBeVisible();
  await supervisor.page.goto("/confirmare");
  await expect(supervisor.page.getByRole("radiogroup", { name: "Magazin" })).toBeVisible();
  await supervisor.page.getByRole("radio", { name: "Outlet Perdele" }).click();
  await expect(supervisor.page).toHaveURL(/source=outletperdele/);
  await expect(supervisor.page.getByTestId(`cf-row-${number(F.orders.call)}`)).toHaveCount(0);
  await supervisor.context.close();

  const unrelated = await signIn(browser, base.users.ana);
  await expect(unrelated.page.getByTestId("workspace-confirmare")).toHaveCount(0);
  await unrelated.page.goto("/confirmare");
  await expect(unrelated.page).toHaveURL(/\/confirmare$/);
  await expect(unrelated.page.getByTestId("cf-queue")).toHaveCount(0);
  await unrelated.context.close();
});

test("a network interruption keeps the last data; revoked access and an expired session stop the work", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const op = await signIn(browser, F.people.operator.username);
  const page = op.page;
  await page.goto("/confirmare");
  const row = page.locator("#cf-tab-panel").getByTestId(`cf-row-${number(F.orders.colleague)}`);
  await expect(row).toBeVisible();
  await op.context.setOffline(true);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).toContainText("Datele pot fi neactualizate");
  await expect(row, "the last good list stays").toBeVisible();
  await capture(page, "queue-offline-390");
  await op.context.setOffline(false);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).not.toContainText("Datele pot fi neactualizate");

  const rootUser = await api("arasya.root.owner", "staff root fixture permanent 2026");
  expect((await send(rootUser, "put", `/management/employees/${F.people.operator.id}/confirmare-scopes`, { operate: [], supervise: [] })).status()).toBe(200);
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page.getByTestId("cf-queue-refresh")).toContainText("Actualizare automată oprită");
  expect((await send(rootUser, "put", `/management/employees/${F.people.operator.id}/confirmare-scopes`, { operate: ["trendhome"], supervise: [] })).status()).toBe(200);

  // The session ends while the operator works: the next read returns to the sign-in screen and says why.
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await op.context.clearCookies();
  await page.getByTestId("cf-queue-refresh").getByRole("button").click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByText("Sesiunea a expirat. Autentifică-te din nou.")).toBeVisible();
  await op.context.close();
});

test("shop order: audited reads, never stored or logged in the browser, honest when the shop cannot answer", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(120_000);
  const sup = await signIn(browser, F.people.supervisor.username);
  const page = sup.page;
  const consoleText: string[] = [];
  page.on("console", (message) => consoleText.push(message.text()));
  await page.goto(orderPath(F.orders.unassigned));
  await page.getByTestId("cf-shop-load").click();
  await expect(page.getByTestId("cf-shop-view")).toContainText("Citit ca supervizor");
  await expect(page.getByTestId("cf-shop-call-billing")).toHaveAttribute("href", "tel:0700000123");
  const stored = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage }, url: location.href }));
  expect(stored, "the telephone number never reaches browser storage or the URL").not.toContain("0700000123");
  expect(consoleText.join("\n"), "nor the console").not.toContain("0700000123");
  await page.getByTestId("cf-shop-hide").click();
  await expect(page.locator("body")).not.toContainText("0700000123");
  // The shop no longer knows the order: stated as such, nothing invented.
  const shopFile = process.env.ARASYA_E2E_SHOP_FILE ?? "";
  const shop = JSON.parse(readFileSync(shopFile, "utf8")) as { orders: Record<string, unknown> };
  const kept = shop.orders[number(F.orders.unassigned)];
  delete shop.orders[number(F.orders.unassigned)];
  writeFileSync(shopFile, JSON.stringify(shop));
  try {
    await page.getByTestId("cf-shop-load").click();
    await expect(page.getByTestId("cf-shop-unavailable")).toContainText("comanda nu a fost găsită în magazin");
    await capture(page, "order-shop-unavailable-390");
  } finally {
    shop.orders[number(F.orders.unassigned)] = kept;
    writeFileSync(shopFile, JSON.stringify(shop));
  }
  // A store without source access says so; nothing to read.
  const outlet = await signIn(browser, F.people.outlet.username);
  await outlet.page.goto(orderPath(F.orders.outlet));
  await expect(outlet.page.getByTestId("cf-shop-off")).toBeVisible();
  await expect(outlet.page.getByTestId("cf-phone-unavailable")).toBeVisible();
  await outlet.context.close();
  // Every read was audited server-side; the API answers no-store.
  const who = await api(F.people.supervisor.username, F.password);
  const read = await who.ctx.get(`/confirmare/orders/${encodeURIComponent(F.orders.unassigned)}/source`);
  expect(read.status()).toBe(200);
  expect(read.headers()["cache-control"]).toContain("no-store");
  for (const width of [320, 768, 1440]) {
    await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
    await page.getByTestId("cf-shop-load").click();
    await expect(page.getByTestId("cf-shop-view")).toBeVisible();
    await page.waitForTimeout(150);
    expect(await overflow(page), `shop order at ${width}px`).toBeLessThanOrEqual(0);
    await capture(page, `order-shop-${width}`);
    await accessible(page, `shop order ${width}`);
  }
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});

test("shop edit (Phase 4B): the shop prices the change, one explicit save, the approval waits for the shop's update", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(120_000);
  const sup = await signIn(browser, F.people.supervisor.username);
  const page = sup.page;
  await page.goto(orderPath(F.orders.unassigned));
  await expect(page.getByTestId("cf-edit-start"), "no editor until the customer asks for a change").toHaveCount(0);
  await page.getByTestId("cf-shop-load").click();
  await at(page, "contact", "call");
  // During the call the customer asks for another length: the change opens in the same step.
  await page.getByTestId("cfw-open-correction").click();
  await expect(page.getByTestId("cfw-task")).toHaveAttribute("data-task", "correction");
  await expect(page.getByTestId("cf-shop-total"), "the same shop read, now with its prices").toContainText("719,00");
  await page.getByTestId("cf-edit-start").click();
  const lineId = `${Number(number(F.orders.unassigned)) * 10 + 1}`;
  const length = page.getByTestId(`cf-edit-${lineId}-lungimea`);
  await expect(length).toHaveValue("3");
  await expect(page.getByTestId("cf-edit-preview"), "nothing changed, nothing to calculate").toBeDisabled();
  await length.fill("15");
  await expect(page.getByTestId("cf-edit-preview"), "a value outside the shop's limits is never sent").toBeDisabled();
  await length.fill("3,5");
  await page.getByTestId("cf-edit-preview").click();
  await expect(page.getByTestId("cf-edit-preview-view")).toBeVisible();
  await expect(page.getByTestId("cf-edit-new-total")).toContainText("754,00");
  await expect(page.getByTestId("cf-edit-impact-additional_payment_due")).toBeVisible();
  await expect(page.getByTestId("cf-edit-apply"), "the save needs the customer's explicit agreement").toBeDisabled();
  await capture(page, "order-edit-preview-390");
  await accessible(page, "shop edit preview 390");
  await page.getByTestId("cf-edit-confirm").check();
  await page.getByTestId("cf-edit-apply").dblclick();
  await expect(page.getByTestId("cf-edit-result")).toContainText("Modificarea a fost salvată în magazin");
  await expect(page.getByTestId("cf-shop-view"), "the old shop prices are dropped").toHaveCount(0);
  await expect(page.getByTestId("cf-edit-waiting")).toBeVisible();
  // Saved in the shop is not settled: the step waits for the shop's order update before anything else.
  await at(page, "contact", "shopUpdate", "waiting");
  await expect(page.getByTestId("cfw-owner")).toContainText("Magazinul");
  await capture(page, "order-edit-applied-390");
  // Saved once (a double click is one edit), audited server-side; the approval waits for the shop's order update.
  const who = await api(F.people.supervisor.username, F.password);
  const detail = await (await who.ctx.get(`/confirmare/orders/${encodeURIComponent(F.orders.unassigned)}`)).json() as { sourceAccess: { changePending: boolean } };
  expect(detail.sourceAccess.changePending).toBe(true);
  const shop = JSON.parse(readFileSync(process.env.ARASYA_E2E_SHOP_FILE ?? "", "utf8")) as { edits: Record<string, { status: number }> };
  expect(Object.values(shop.edits).filter((edit) => edit.status === 200), "one applied edit in the shop").toHaveLength(1);
  const blocked = await send(who, "post", `/confirmare/orders/${encodeURIComponent(F.orders.unassigned)}/source/preview`, { expectedVersion: `sha256:${"0".repeat(64)}`, operations: [{ op: "update_line", lineId, fields: { lungimea: "4" } }] });
  expect(blocked.status()).toBe(409);
  expect((await blocked.json()).error.code).toBe("CONFIRMARE_SHOP_CHANGE_PENDING");
  for (const width of [320, 1440]) {
    await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
    await page.waitForTimeout(150);
    expect(await overflow(page), `shop edit at ${width}px`).toBeLessThanOrEqual(0);
    await capture(page, `order-edit-applied-${width}`);
  }
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});

test("finance (Phase 5): the shop's money read on request, a transfer recorded by one employee and verified by another", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(120_000);
  const recorder = await signIn(browser, F.people.finrec.username);
  let page = recorder.page;
  await expect(page.getByTestId("workspace-confirmare-finance"), "a finance-only employee works from the finance tasks").toBeVisible();
  await page.getByTestId("cf-open-finance").click();
  await expect(page.getByTestId("cf-finance-queue")).toBeVisible();
  // The additional payment of the order is an exceptional finance task: its row opens the money panel only.
  await page.getByTestId(`cf-finance-row-${number(F.orders.finance)}`).click();
  await expect(page).toHaveURL(new RegExp(`/confirmare/finance/orders/`));
  await expect(page.getByTestId("fr-order")).toBeVisible();
  await expect(page.getByTestId("cf-finance-load")).toBeVisible();
  await expect(page.getByTestId("cf-finance-view"), "no amount before the employee asks").toHaveCount(0);
  await expect(page.getByTestId("cf-customer-facts"), "finance never sees the customer's data").toHaveCount(0);
  await page.getByTestId("cf-finance-load").click();
  await expect(page.getByTestId("cf-finance-current")).toContainText("650,00");
  await expect(page.getByTestId("cf-finance-original")).toContainText("500,00");
  await expect(page.getByTestId("cf-finance-outstanding")).toContainText("150,00");
  await expect(page.getByTestId("cf-finance-next")).toContainText("Plată suplimentară necesară");
  await page.getByTestId("cf-finance-amount").fill("1.500");
  await page.getByTestId("cf-finance-reference").fill("OP E2E 0001");
  await page.getByTestId("cf-finance-prepare").click();
  await expect(page.getByTestId("cf-finance-problem"), "an ambiguous amount is refused, never guessed").toBeVisible();
  await page.getByTestId("cf-finance-amount").fill("150,00");
  await page.getByTestId("cf-finance-prepare").click();
  await expect(page.getByTestId("cf-finance-confirm")).toContainText("Nu este o plată primită");
  await capture(page, "finance-confirm-390");
  await accessible(page, "finance confirm 390");
  await page.getByTestId("cf-finance-send").dblclick();
  await expect(page.getByTestId("cf-finance-result")).toContainText("Magazinul a confirmat");
  await expect(page.getByTestId("cf-finance-next")).toContainText("Transfer de verificat");
  await expect(page.getByTestId("cf-finance-received"), "a recorded transfer is no money received").toContainText("500,00");
  await expect(page.getByTestId("cf-finance-own-pending")).toBeVisible();
  await expect(page.getByTestId("cf-finance-verify"), "the recorder is never offered the verification").toHaveCount(0);
  const shop = JSON.parse(readFileSync(process.env.ARASYA_E2E_SHOP_FILE ?? "", "utf8")) as { orders: Record<string, { finance?: { entries?: { state: string }[] } }> };
  expect(shop.orders[number(F.orders.finance)]?.finance?.entries?.filter((entry) => entry.state === "submitted"), "a double click is one record").toHaveLength(1);
  expect(recorder.errors).toEqual([]);
  await recorder.context.close();

  const approver = await signIn(browser, F.people.finapp.username);
  page = approver.page;
  // The Confirmare order stays closed to a finance scope (the route falls back to Home).
  await page.goto(orderPath(F.orders.finance));
  await expect(page.getByTestId("cf-order")).toHaveCount(0);
  await page.goto(financeOrderPath(F.orders.finance));
  await page.getByTestId("cf-finance-load").click();
  await expect(page.getByTestId("cf-finance-decide")).toContainText("OP E2E 0001");
  await page.getByTestId("cf-finance-verify").click();
  await expect(page.getByTestId("cf-finance-confirm")).toContainText("apare în extrasul de cont");
  await page.getByTestId("cf-finance-send").click();
  await expect(page.getByTestId("cf-finance-result")).toContainText("Magazinul a confirmat");
  await expect(page.getByTestId("cf-finance-received")).toContainText("650,00");
  await expect(page.getByTestId("cf-finance-outstanding")).toContainText("0,00");
  await expect(page.getByTestId("cf-finance-next")).toContainText("Nicio decizie financiară necesară");
  await expect(page.getByTestId("cf-finance-entry-verified")).toBeVisible();
  await capture(page, "finance-verified-390");
  await accessible(page, "finance verified 390");
  for (const width of [320, 1440]) {
    await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
    await page.waitForTimeout(150);
    expect(await overflow(page), `finance at ${width}px`).toBeLessThanOrEqual(0);
    await capture(page, `finance-verified-${width}`);
  }
  // The operator and a supervisor may read the money but never act; nothing of it is stored in the browser.
  const storage = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
  expect(storage.includes("650") || storage.includes("OP E2E")).toBe(false);
  const op = await api(F.people.operator.username, F.password);
  const refused = await send(op, "post", `/confirmare/orders/${encodeURIComponent(F.orders.finance)}/finance/submit-transfer`, { expectedRevision: `sha256:${"0".repeat(64)}`, purpose: "additional_payment", amount: "1.00", reference: "OP E2E 0002", valueDate: "2026-10-22" });
  expect([403, 404]).toContain(refused.status());
  expect(approver.errors).toEqual([]);
  await approver.context.close();
});

test("handoff (Phase 6): a document employee without Confirmare scope generates and prints revision 1 once; a reprint needs a reason", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(90_000);
  const shopApi = await api(F.people.handoff.username, F.password);
  expect((await shopApi.ctx.get("/confirmare/pool?view=open")).status(), "no customer confirmation pool for a document employee").toBe(403);
  const shop = await signIn(browser, F.people.handoff.username);
  const page = shop.page;
  await page.getByTestId("documents-open-handoff").click();
  await expect(page).toHaveURL(/\/confirmare\/handoff$/);
  await expect(page.getByTestId(`cf-handoff-row-${number(F.orders.call)}`)).toContainText("Gata pentru fișă");
  await expect(page.getByTestId(`cf-handoff-row-${number(F.orders.cod)}`), "an order waiting for its advance is not handed over").toHaveCount(0);
  await accessible(page, "handoff list");
  await capture(page, "handoff-list-390");
  await page.getByTestId(`cf-handoff-row-${number(F.orders.call)}`).click();
  await expect(page).toHaveURL(new RegExp(`/documents/${encodeURIComponent(F.orders.call)}$`));
  await page.getByRole("button", { name: "Generează documentul (REVIZIA 1)" }).dblclick();
  await expect(page.getByRole("button", { name: "Tipărește REVIZIA 1" })).toBeVisible();
  const first = page.waitForEvent("download");
  await page.getByRole("button", { name: "Tipărește REVIZIA 1" }).click();
  expect((await first).suggestedFilename()).toMatch(/R1\.pdf$/);
  const reprint = page.getByRole("button", { name: "Retipărește REVIZIA 1" });
  await expect(reprint, "no reprint without a reason").toBeDisabled();
  await page.getByTestId("document-reprint-reason").fill("Hârtie deteriorată la imprimantă");
  await expect(reprint).toBeEnabled();
  const second = page.waitForEvent("download");
  await reprint.click();
  await second;
  await capture(page, "handoff-printed-390");
  const documentView = await (await shopApi.ctx.get(`/production-documents/orders/${encodeURIComponent(F.orders.call)}`)).json() as { revisions: unknown[]; reprintReasonRequired: boolean };
  expect(documentView.revisions, "a double click generates one revision").toHaveLength(1);
  expect(documentView.reprintReasonRequired).toBe(true);
  const refused = await send(shopApi, "post", `/production-documents/orders/${encodeURIComponent(F.orders.call)}/print`, { revisionNumber: 1 });
  expect(refused.status(), "the API refuses a reprint without a reason").toBe(422);
  await page.goto("/confirmare/handoff");
  await expect(page.getByTestId(`cf-handoff-row-${number(F.orders.call)}`)).toContainText("Așteaptă tăierea");
  await page.goto(orderPath(F.orders.call));
  await expect(page.getByTestId("cf-order"), "a document employee never opens the Confirmare order").toHaveCount(0);
  expect(shop.errors).toEqual([]);
  await shop.context.close();
  // The Confirmare supervisor sees the same handoff state on the order, and why the COD order is still closed.
  const sup = await signIn(browser, F.people.supervisor.username);
  await sup.page.goto(orderPath(F.orders.call));
  await expect(sup.page.getByTestId("cf-handoff-state")).toHaveText("Așteaptă tăierea");
  // In production is not "handed over": the order was never routed to a shop employee, so step 5 stays open (honestly).
  await at(sup.page, "handoff", "routing");
  await expect(sup.page.getByTestId("cf-routing")).toBeVisible();
  await expect(sup.page.getByTestId("cfw-step-payment")).toHaveAttribute("data-state", "done");
  await sup.page.goto(orderPath(F.orders.cod));
  await openMore(sup.page);
  await expect(sup.page.getByTestId("cf-handoff-state")).toHaveText("Blocată");
  await expect(sup.page.getByTestId("cf-handoff-blockers")).toContainText("Plata sau avansul confirmate de magazin");
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});

test("layouts from 320 to 1920 px without horizontal scrolling, WCAG 2.2 AA and 44 px targets", async ({ browser }) => {
  test.skip(Boolean(F.skip), F.skip);
  test.setTimeout(240_000);
  const sup = await signIn(browser, F.people.supervisor.username);
  const page = sup.page;
  for (const width of [320, 360, 375, 390, 768, 1024, 1440, 1920]) {
    await page.setViewportSize({ width, height: width < 768 ? 844 : 1000 });
    for (const [name, url, ready] of [["home", "/", "workspace-confirmare"], ["queue", "/confirmare?tab=attention", "cf-queue"], ["order", orderPath(F.orders.cod), "cf-order"], ["team", "/confirmare/team", "cf-team"]] as const) {
      await page.goto(url);
      await expect(page.getByTestId(ready)).toBeVisible();
      await page.waitForTimeout(150);
      expect(await overflow(page), `${name} at ${width}px`).toBeLessThanOrEqual(0);
      await capture(page, `${name}-${width}`);
      if (width === 390 || width === 1440) await accessible(page, `${name} ${width}`);
    }
  }
  expect(sup.errors).toEqual([]);
  await sup.context.close();
});
