import AxeBuilder from "@axe-core/playwright";
import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// SmartBill invoicing (API 2.43.0, Staff 2.33.0) against the real Operations API, after the Confirmare spec. The TEST shop
// simulates the shop's SmartBill: nothing is ever issued or sent anywhere. Covers the production ticket employee issuing
// the document of a paid, approved Confirmare order from the production ticket screen (confirmed once, never twice), the
// resend confirmation, one state in two open tabs, the finance flow (read only), the Turkish texts, layouts from 320 to
// 1920 px and WCAG 2.2 AA.
type Person = { username: string; name: string; id: string };
type Fixture = { password: string; people: Record<"ro" | "tr" | "fin", Person>; order: string; number: string };
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
  const issue = page.getByTestId("cf-invoice-issue");
  await expect(issue).toHaveText("Emite factura în SmartBill");
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

test("the finance flow shows the same SmartBill state read only; finance never issues or sends", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.fin.username);
  await expect(page.getByTestId("workspace-confirmare-finance")).toBeVisible();
  await page.getByTestId("cf-open-finance").click();
  await expect(page.getByTestId("cf-finance-queue")).toBeVisible();
  await page.goto(`/confirmare/orders/${encodeURIComponent(F.order)}`);
  // The SmartBill section sits right after the order's finance section.
  await expect(page.locator("[data-testid=cf-finance] ~ [data-testid=cf-invoice]")).toBeVisible();
  await expect(page.getByTestId("cf-invoice-document")).toContainText("TST");
  await expect(page.getByTestId("cf-invoice-mail")).toContainText("2 trimiteri");
  await expect(page.getByTestId("cf-invoice-readonly")).toBeVisible();
  for (const id of ["cf-invoice-actions", "cf-invoice-issue", "cf-invoice-send-mail", "cf-invoice-reissue", "cf-invoice-resolve", "cf-invoice-view-link"]) {
    await expect(page.getByTestId(id), `finance: no ${id}`).toHaveCount(0);
  }
  await accessible(page, "finance");
  expect(errors).toEqual([]);
  await context.close();
  // The server refuses too: a finance scope is not an invoice permission.
  const api = await request.newContext({ baseURL: apiOrigin, extraHTTPHeaders: { Origin: webOrigin } });
  const csrf = (await (await api.post("/auth/login", { data: { username: F.people.fin.username, password: F.password } })).json()).csrfToken as string;
  const state = await (await api.get(`/confirmare/orders/${encodeURIComponent(F.order)}/invoice`)).json();
  expect(state.role).toBe("finance");
  expect(state.allowedActions).toEqual([]);
  // Each request is well formed (the input checks pass), so only the permission can refuse it.
  const bodies: Record<string, Record<string, unknown>> = { issue: {}, "send-mail": { confirmResend: true }, reissue: { reasonCode: "order_changed", previousCancelled: true }, resolve: { target: "mail", resolution: "sent" } };
  for (const [action, fields] of Object.entries(bodies)) {
    const refused = await api.post(`/confirmare/orders/${encodeURIComponent(F.order)}/invoice/${action}`, { headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `inv-e2e-fin-${action}-${Date.now()}` }, data: { expectedRevision: state.summary.revision, ...fields } });
    expect(refused.status(), `finance ${action}`).toBe(403);
    expect(((await refused.json()) as { error?: { code?: string } }).error?.code, `finance ${action}`).toBe("CONFIRMARE_INVOICE_DENIED");
  }
  await api.dispose();
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
});
