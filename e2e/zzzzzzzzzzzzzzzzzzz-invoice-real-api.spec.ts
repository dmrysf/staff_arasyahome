import AxeBuilder from "@axe-core/playwright";
import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// SmartBill invoicing (API 2.43.0, Staff 2.33.0) against the real Operations API, after the Confirmare spec. The TEST shop
// simulates the shop's SmartBill: nothing is ever issued or sent anywhere. Covers the production ticket employee issuing
// the document of a paid, approved Confirmare order from the production ticket screen (confirmed once, never twice), the
// resend confirmation, one state in two open tabs, the finance flow (read only), the Turkish texts, layouts from 320 to
// 1920 px and WCAG 2.2 AA. API 2.44.0 / Staff 2.34.0: the employee scans the ticket QR before the invoice; the Confirmare
// operator issues and sends the advance proforma, sees the advance groups and routes the paid order to the shop employee.
// Owner decision (YD SOFT 2.18.0): the proforma carries the shop's bank account and the payment reference; finance keeps
// the transfers that match no order in the shop's exception record, closed by another finance employee.
type Person = { username: string; name: string; id: string };
type Fixture = { password: string; people: Record<"ro" | "tr" | "fin" | "fin2", Person>; order: string; number: string; qr: string; operator: string; waiting: string; paid: string; partial: string; cancel: string; total: string };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "invoice");
let F: Fixture;
const WIDTHS = [320, 360, 390, 768, 1024, 1440, 1920] as const;
const apiOrigin = "http://127.0.0.1:8787";
const webOrigin = "http://127.0.0.1:4174";
const ticket = () => `/documents/${encodeURIComponent(F.order)}`;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-invoice-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
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

async function panelOf(page: Page) {
  await page.goto(ticket());
  const panel = page.getByTestId("cf-invoice");
  await expect(panel).toBeVisible();
  await expect(page.getByTestId("cf-invoice-facts")).toBeVisible();
  return panel;
}

async function accessible(page: Page, screen: string) {
  const results = await new AxeBuilder({ page }).include("[data-testid=cf-invoice]").withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>("[data-testid=cf-invoice] button, [data-testid=cf-invoice] a[href], [data-testid=cf-invoice] input, [data-testid=cf-invoice] select, [data-testid=cf-invoice] textarea")]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0; })
    .map((element) => { const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: target.width, height: target.height }; })
    .filter((item) => item.width < 44 || item.height < 44)
    .map((item) => `${item.name} ${Math.round(item.width)}×${Math.round(item.height)}`));
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

test("the production ticket employee issues the SmartBill invoice once, confirmed, from the ticket screen", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.ro.username);
  const panel = await panelOf(page);
  await expect(panel.getByRole("heading", { name: "Facturare SmartBill" })).toBeVisible();
  await expect(page.getByTestId("cf-invoice-document")).toContainText("Nu există încă un document SmartBill");
  await expect(page.getByTestId("cf-invoice-amount")).toContainText(/\d/);
  // The ticket's QR first (a hand scanner types it); before that there is no invoice action.
  await expect(page.getByTestId("cf-invoice-issue")).toHaveCount(0);
  await expect(page.getByTestId("cf-invoice-blockers")).toContainText("Scanați mai întâi QR-ul fișei de producție");
  await page.getByTestId("cf-invoice-qr-input").fill("ARASYA:Q1:" + "A".repeat(26));
  await page.getByTestId("cf-invoice-qr-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("nu este QR-ul actual");
  await page.getByTestId("cf-invoice-qr-input").fill(F.qr);
  await page.getByTestId("cf-invoice-qr-send").click();
  await expect(page.getByTestId("cf-invoice-qr-state")).toContainText("QR verificat");
  const issue = page.getByTestId("cf-invoice-issue");
  await expect(issue).toHaveText("Emite factura SmartBill");
  await issue.click();
  await expect(page.getByTestId("cf-invoice-confirm-text")).toContainText(`#${F.number}`);
  await expect(page.getByTestId("cf-invoice-send")).toBeDisabled();
  await accessible(page, "confirm");
  await page.getByTestId("cf-invoice-reviewed").check();
  await page.getByTestId("cf-invoice-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("Documentul a fost emis în SmartBill.");
  await expect(page.getByTestId("cf-invoice-document")).toContainText("TST");
  await expect(page.getByTestId("cf-invoice-issue")).toHaveCount(0);
  await expect(page.getByTestId("cf-invoice-view-link")).toHaveAttribute("href", /^https:\/\/cloud\.smartbill\.ro\//);
  await expect(page.getByTestId("cf-invoice-view-link")).toHaveAttribute("rel", "noopener noreferrer");
  await expect(page.getByTestId("cf-invoice-mail")).toContainText("trimis");
  await page.getByTestId("cf-invoice-refresh").click();
  await expect(page.getByTestId("cf-invoice-history").locator("li")).toHaveCount(1);
  expect(errors).toEqual([]);
  await context.close();
});

test("a second e-mail is confirmed first; two open tabs show the same state", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.ro.username);
  await panelOf(page);
  const other = await context.newPage();
  await other.goto(ticket());
  await expect(other.getByTestId("cf-invoice-mail")).toContainText("1 trimiteri");
  await page.getByTestId("cf-invoice-send-mail").click();
  await expect(page.getByTestId("cf-invoice-confirm-text")).toContainText("a fost deja trimis (1)");
  await page.getByTestId("cf-invoice-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("Documentul a fost trimis clientului.");
  // The other tab reads the shop again by itself (only the order id travels between the tabs).
  await expect(other.getByTestId("cf-invoice-mail")).toContainText("2 trimiteri");
  await context.close();
});

test("API 2.50.0: a finance scope without a finance task of the order reads no SmartBill state and never issues or sends", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.fin.username);
  await expect(page.getByTestId("workspace-confirmare-finance")).toBeVisible();
  await page.getByTestId("fr-open-inbox").click();
  await expect(page.getByTestId("fr-inbox")).toBeVisible();
  await expect(page.getByTestId(`fr-card-${F.order.split(":")[1]}`)).toHaveCount(0);
  // The paid order is no finance task: the Confirmare order stays closed (the route falls back to Home).
  await page.goto(`/confirmare/orders/${encodeURIComponent(F.order)}`);
  await expect(page.getByTestId("cf-order")).toHaveCount(0);
  await expect(page.getByTestId("cf-invoice")).toHaveCount(0);
  expect(errors).toEqual([]);
  await context.close();
  // The server refuses too: no finance task of the order, nothing of it is found (no existence leak).
  const api = await request.newContext({ baseURL: apiOrigin, extraHTTPHeaders: { Origin: webOrigin } });
  const csrf = (await (await api.post("/auth/login", { data: { username: F.people.fin.username, password: F.password } })).json()).csrfToken as string;
  const read = await api.get(`/confirmare/orders/${encodeURIComponent(F.order)}/invoice`);
  expect(read.status()).toBe(404);
  const state = { summary: { revision: `sha256:${"0".repeat(64)}` } };
  // Each request is well formed (the input checks pass), so only the reach can refuse it.
  const bodies: Record<string, Record<string, unknown>> = { issue: {}, "send-mail": { confirmResend: true }, reissue: { reasonCode: "order_changed", previousCancelled: true }, resolve: { target: "mail", resolution: "sent" } };
  for (const [action, fields] of Object.entries(bodies)) {
    const refused = await api.post(`/confirmare/orders/${encodeURIComponent(F.order)}/invoice/${action}`, { headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `inv-e2e-fin-${action}-${Date.now()}` }, data: { expectedRevision: state.summary.revision, ...fields } });
    expect(refused.status(), `finance ${action}`).toBe(404);
    expect(((await refused.json()) as { error?: { code?: string } }).error?.code, `finance ${action}`).toBe("CONFIRMARE_NOT_FOUND");
  }
  await api.dispose();
});

test("the Confirmare operator issues and sends the advance proforma; never the final invoice", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.operator);
  await page.goto(`/confirmare/orders/${encodeURIComponent(F.waiting)}`);
  const proforma = page.getByTestId("cf-proforma");
  await expect(proforma).toBeVisible();
  await expect(page.getByTestId("cf-proforma-outstanding")).toContainText(/215,70/);
  await expect(page.getByTestId("cf-invoice-issue")).toHaveCount(0);
  // Staff 2.40.0 guided order: step 4 (the advance) is open; the routing of step 5 is closed until the money is proven.
  await expect(page.getByTestId("cfw-step-payment")).toHaveAttribute("data-state", "active");
  await expect(page.getByTestId("cfw-step-handoff")).toHaveAttribute("data-state", "locked");
  await expect(page.getByTestId("cf-routing-open"), "no routing before the advance is verified").toHaveCount(0);
  await page.getByTestId("cf-proforma-issue").click();
  await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("#91021");
  await expect(page.getByTestId("cf-proforma-send")).toBeDisabled();
  await accessible(page, "operator proforma confirm");
  await page.getByTestId("cf-proforma-accepted").check();
  await page.getByTestId("cf-proforma-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("Proforma de avans a fost emisă în SmartBill.");
  await expect(page.getByTestId("cf-proforma-document")).toContainText("PRF");
  await expect(page.getByTestId("cf-proforma-view")).toHaveAttribute("href", /^https:\/\/cloud\.smartbill\.ro\//);
  await expect(page.getByTestId("cf-proforma-issue")).toHaveCount(0);
  // Paid by bank transfer: the shop's IBAN and the order's payment reference, read out to the customer.
  await expect(page.getByTestId("cf-proforma-iban")).toHaveText("RO49 AAAA 1B31 0075 9384 0000");
  await expect(page.getByTestId("cf-proforma-reference")).toHaveText(/^TH[0-9]+$/);
  await expect(page.getByTestId("cf-proforma-transfer")).toContainText("Plata apare aici automat");
  await page.getByTestId("cf-proforma-send-mail").click();
  await page.getByTestId("cf-proforma-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("Proforma a fost trimisă clientului.");
  await expect(page.getByTestId("cf-proforma-mail")).toContainText("1");
  // A proforma is not money: the order still waits for its advance.
  await expect(page.getByTestId("cf-proforma-paid")).toHaveCount(0);
  await accessible(page, "operator proforma");
  expect(errors).toEqual([]);
  await context.close();
});

test("master update: the operator reads the money, accepts a smaller advance, cancels an unpaid proforma in SmartBill, records the telephone acceptance (320 / 390 px)", async ({ browser }) => {
  for (const width of [320, 390]) {
    const { context, page, errors } = await signIn(browser, F.operator, width);
    if (width === 320) {
      // A smaller verified advance (200.00 of 215.70): the money card, then "Acceptă avansul primit".
      await page.goto(`/confirmare/orders/${encodeURIComponent(F.partial)}`);
      await expect(page.getByTestId("cf-proforma-money")).toBeVisible();
      await expect(page.getByTestId("cf-money-total")).toContainText("719,00");
      await expect(page.getByTestId("cf-money-required")).toContainText("215,70");
      await expect(page.getByTestId("cf-money-received")).toContainText("200,00");
      await expect(page.getByTestId("cf-money-gap")).toContainText("15,70");
      await expect(page.getByTestId("cf-money-remaining")).toContainText("519,00");
      await expect(page.getByTestId("cf-money-advance")).toContainText("Avans parțial");
      await expect(page.getByTestId("cf-money-invoice")).toContainText("Nefacturată");
      await expect(page.getByTestId("cf-money-deadline")).toContainText("2026-11-15");
      // Owner 2026-10-29: the decision of a smaller verified advance, with its four amounts and two plain choices.
      await expect(page.getByTestId("cf-advance-decision")).toBeVisible();
      await expect(page.getByTestId("cf-advance-decision")).toContainText("Avans încasat și verificat");
      await expect(page.getByTestId("cf-advance-decision")).toContainText("Rest de plată la livrare");
      await expect(page.getByTestId("cf-advance-required")).toContainText("215,70");
      await expect(page.getByTestId("cf-advance-received")).toContainText("200,00");
      await expect(page.getByTestId("cf-advance-gap")).toContainText("15,70");
      await expect(page.getByTestId("cf-advance-remaining")).toContainText("519,00");
      await expect(page.getByTestId("cf-proforma-accept-advance")).toHaveText("Acceptă avansul primit și continuă comanda");
      await page.getByTestId("cf-advance-request-difference").click();
      await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("nu se emite o proformă nouă");
      await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("15,70");
      await accessible(page, "request difference confirm");
      expect(await overflow(page), "320 px: request difference overflow").toBeLessThanOrEqual(0);
      await page.getByTestId("cf-proforma-send").click();
      await expect(page.getByTestId("cf-invoice-result")).toContainText("Diferența de avans a fost solicitată. Comanda așteaptă plata.");
      await expect(page.getByTestId("cf-advance-difference-requested")).toContainText("15,70");
      await expect(page.getByTestId("cf-advance-request-difference")).toHaveCount(0);
      await expect(page.getByTestId("cf-money-remaining")).toContainText("519,00");
      await page.screenshot({ path: path.join(SHOTS, "operator-request-difference-320.png"), fullPage: true });
      // The customer then agrees to pay the rest on delivery: the operator accepts the advance received.
      await page.getByTestId("cf-proforma-accept-advance").click();
      await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("Dacă acceptați suma primită, comanda poate continua.");
      await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("519,00");
      await accessible(page, "accept advance confirm");
      expect(await overflow(page), "320 px: accept advance overflow").toBeLessThanOrEqual(0);
      await page.screenshot({ path: path.join(SHOTS, "operator-accept-advance-320.png"), fullPage: true });
      await page.getByTestId("cf-proforma-send").click();
      await expect(page.getByTestId("cf-invoice-result")).toContainText("Avansul primit a fost acceptat. Comanda poate continua.");
      await expect(page.getByTestId("cf-proforma-accepted-partial")).toContainText("200,00");
      await expect(page.getByTestId("cf-money-advance")).toContainText("Avans parțial acceptat");
      await expect(page.getByTestId("cf-money-remaining")).toContainText("519,00");
    } else {
      // An unpaid proforma cancelled IN SmartBill; the order itself is not cancelled.
      await page.goto(`/confirmare/orders/${encodeURIComponent(F.cancel)}`);
      await expect(page.getByTestId("cf-money-proforma")).toContainText("PRF 0924");
      await page.getByTestId("cf-proforma-annul").click();
      await expect(page.getByTestId("cf-proforma-confirm-text")).toContainText("Comanda clientului NU se anulează");
      await expect(page.getByTestId("cf-proforma-send")).toBeDisabled();
      await page.getByTestId("cf-proforma-cancel-reason").selectOption("customer_request");
      await page.getByTestId("cf-proforma-accepted").check();
      await accessible(page, "cancel proforma confirm");
      expect(await overflow(page), "390 px: cancel proforma overflow").toBeLessThanOrEqual(0);
      await page.screenshot({ path: path.join(SHOTS, "operator-cancel-proforma-390.png"), fullPage: true });
      await page.getByTestId("cf-proforma-send").click();
      await expect(page.getByTestId("cf-invoice-result")).toContainText("Proforma a fost anulată în SmartBill.");
      await expect(page.getByTestId("cf-proforma-cancelled")).toContainText("PRF 0924");
      await expect(page.getByTestId("cf-proforma-cancelled")).toContainText("La cererea clientului");
      await expect(page.getByTestId("cf-money-proforma")).toContainText("Proformă anulată");
      await expect(page.getByTestId("cf-proforma-issue")).toBeVisible();
      // The new total, accepted on the telephone before any proforma.
      await page.goto(`/confirmare/orders/${encodeURIComponent(F.total)}`);
      await expect(page.getByTestId("cf-proforma-total-required")).toContainText("699,00");
      await expect(page.getByTestId("cf-proforma-total-required")).toContainText("Draperie Velvet");
      await expect(page.getByTestId("cf-proforma-issue")).toHaveCount(0);
      await page.getByTestId("cf-proforma-accept-total").click();
      await expect(page.getByTestId("cf-proforma-send")).toBeDisabled();
      await page.getByTestId("cf-proforma-accepted").check();
      await page.getByTestId("cf-proforma-note").fill("Clientul a confirmat la telefon.");
      await accessible(page, "telephone acceptance confirm");
      await page.getByTestId("cf-proforma-send").click();
      await expect(page.getByTestId("cf-invoice-result")).toContainText("Confirmarea telefonică a fost înregistrată.");
      await expect(page.getByTestId("cf-proforma-total-accepted")).toBeVisible();
      await expect(page.getByTestId("cf-proforma-issue")).toBeVisible();
    }
    await accessible(page, `master update ${width}`);
    expect(errors).toEqual([]);
    await context.close();
  }
});

test("the paid advance appears in the advance groups and the operator routes it to the shop employee", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.operator);
  await page.goto("/confirmare/finance");
  await expect(page.getByTestId("cf-finance-queue")).toBeVisible();
  await page.getByTestId("cf-finance-task-advance_waiting").click();
  await expect(page.getByTestId("cf-finance-row-91021")).toBeVisible();
  await page.getByTestId("cf-finance-task-advance_verified").click();
  await expect(page.getByTestId("cf-finance-task-advance_verified")).toContainText("Avans plătit");
  await expect(page.getByTestId("cf-finance-row-91022")).toBeVisible();
  await page.getByTestId("cf-finance-row-91022").click();
  // Staff 2.40.0 guided order: the verified advance closes step 4; step 5 opens with the routing.
  await expect(page.getByTestId("cfw-step-payment")).toHaveAttribute("data-state", "done");
  await expect(page.getByTestId("cfw-step-payment-summary")).toContainText("Avans încasat și verificat.");
  await expect(page.getByTestId("cfw-task")).toHaveAttribute("data-task", "routing");
  await page.getByTestId("cf-routing-open").click();
  await page.getByTestId("cf-routing-employee").selectOption({ label: F.people.ro.name });
  await accessible(page, "operator routing");
  await page.getByTestId("cf-routing-send").click();
  await expect(page.getByTestId("cf-invoice-result")).toContainText("Comanda a fost direcționată către magazin.");
  await expect(page.getByTestId("cf-routing-state")).toContainText(F.people.ro.name);
  await expect(page.getByTestId("cfw-task"), "the operator's work ends with the routing").toHaveAttribute("data-task", "handedOver");
  await expect(page.getByTestId("cfw-task-title")).toHaveText("Comanda a fost predată");
  await page.goto("/confirmare/finance");
  await page.getByTestId("cf-finance-task-advance_routed").click();
  await expect(page.getByTestId("cf-finance-task-advance_routed")).toContainText("Direcționate către magazin");
  await expect(page.getByTestId("cf-finance-row-91022")).toBeVisible();
  expect(errors).toEqual([]);
  await context.close();
  const shop = await signIn(browser, F.people.ro.username);
  await shop.page.goto("/confirmare/handoff");
  await expect(shop.page.getByTestId("cf-shop-row-91022")).toBeVisible();
  await expect(shop.page.getByTestId("cf-shop-row-91020")).toContainText("Factură emisă");
  await shop.context.close();
});

async function exceptionsAccessible(page: Page, screen: string) {
  const results = await new AxeBuilder({ page }).include("[data-testid=cf-exceptions]").withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>("[data-testid=cf-exceptions] button, [data-testid=cf-exceptions] input, [data-testid=cf-exceptions] select")]
    .filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && box.height > 0; })
    .map((element) => { const target = (element.closest("label") ?? element).getBoundingClientRect(); return { name: (element.getAttribute("aria-label") ?? element.textContent ?? element.tagName).trim().slice(0, 40), width: target.width, height: target.height }; })
    .filter((item) => item.width < 44 || item.height < 44)
    .map((item) => `${item.name} ${Math.round(item.width)}×${Math.round(item.height)}`));
  expect(small, `${screen}: touch targets under 44 px`).toEqual([]);
}

test("a transfer that matches no order: one finance employee records it, another closes it; never an order's money", async ({ browser }) => {
  const first = await signIn(browser, F.people.fin.username, 320, 900);
  await first.page.goto("/confirmare/finance");
  const section = first.page.getByTestId("cf-exceptions");
  await expect(section).toBeVisible();
  await expect(first.page.getByTestId("cf-exc-empty")).toBeVisible();
  await first.page.getByTestId("cf-exc-amount").fill("250,00");
  await first.page.getByTestId("cf-exc-reference").fill("OP E2E EXC 0001");
  await first.page.getByTestId("cf-exc-reason").selectOption("unmatched");
  await first.page.getByTestId("cf-exc-prepare").click();
  await expect(first.page.getByTestId("cf-exc-confirm")).toContainText("OP E2E EXC 0001");
  await exceptionsAccessible(first.page, "exception confirm 320 px");
  expect(await overflow(first.page), "320 px: exception confirmation overflow").toBeLessThanOrEqual(0);
  await first.page.getByTestId("cf-exc-send").click();
  await expect(first.page.getByTestId("cf-exc-done")).toBeVisible();
  await expect(first.page.getByTestId("cf-exc-open")).toContainText("OP E2E EXC 0001");
  await expect(first.page.getByTestId("cf-exc-resolve-form")).toHaveCount(0);
  expect(first.errors).toEqual([]);
  await first.context.close();
  const second = await signIn(browser, F.people.fin2.username, 390, 900);
  await second.page.goto("/confirmare/finance");
  await expect(second.page.getByTestId("cf-exc-open")).toContainText("OP E2E EXC 0001");
  await second.page.getByTestId("cf-exc-resolution-select").selectOption("returned_to_payer");
  await second.page.getByTestId("cf-exc-resolve").click();
  await expect(second.page.getByTestId("cf-exc-confirm")).toContainText("Returnat plătitorului");
  await exceptionsAccessible(second.page, "exception resolve 390 px");
  await second.page.getByTestId("cf-exc-send").click();
  await expect(second.page.getByTestId("cf-exc-resolved")).toContainText("Returnat plătitorului");
  expect(await overflow(second.page), "390 px: exception list overflow").toBeLessThanOrEqual(0);
  mkdirSync(SHOTS, { recursive: true });
  await second.page.screenshot({ path: path.join(SHOTS, "finance-exceptions-390.png"), fullPage: true });
  expect(second.errors).toEqual([]);
  await second.context.close();
});

test("the Turkish Staff language shows the SmartBill section in Turkish", async ({ browser }) => {
  const { context, page } = await signIn(browser, F.people.tr.username);
  const panel = await panelOf(page);
  await expect(panel.getByRole("heading", { name: "SmartBill Faturalandırma" })).toBeVisible();
  await expect(page.getByTestId("cf-invoice-view-link")).toContainText("SmartBill'de Görüntüle");
  await expect(page.getByTestId("cf-invoice-send-mail")).toHaveText("Faturayı Müşteriye Gönder");
  await context.close();
});

test("layouts from 320 to 1920 px: no horizontal scroll, 44 px targets, WCAG 2.2 AA", async ({ browser }) => {
  mkdirSync(SHOTS, { recursive: true });
  for (const width of WIDTHS) {
    const { context, page } = await signIn(browser, F.people.ro.username, width, 900);
    await panelOf(page);
    expect(await overflow(page), `${width} px: horizontal overflow`).toBeLessThanOrEqual(0);
    await accessible(page, `${width} px`);
    await page.getByTestId("cf-invoice-send-mail").click();
    await expect(page.getByTestId("cf-invoice-confirm")).toBeVisible();
    expect(await overflow(page), `${width} px: overflow with the confirmation open`).toBeLessThanOrEqual(0);
    await page.screenshot({ path: path.join(SHOTS, `invoice-${width}.png`), fullPage: true });
    await page.getByTestId("cf-invoice-cancel").click();
    await context.close();
  }
  // The operator's view (proforma and routing) on the narrowest and a wide screen.
  for (const width of [320, 360, 390, 1440] as const) {
    const { context, page } = await signIn(browser, F.operator, width, 900);
    await page.goto(`/confirmare/orders/${encodeURIComponent(F.waiting)}`);
    await expect(page.getByTestId("cf-proforma")).toBeVisible();
    expect(await overflow(page), `${width} px: operator proforma overflow`).toBeLessThanOrEqual(0);
    await accessible(page, `operator ${width} px`);
    await page.screenshot({ path: path.join(SHOTS, `operator-proforma-${width}.png`), fullPage: true });
    await context.close();
  }
});
