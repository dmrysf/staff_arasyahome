import { expect, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

// Finance verification requests (Staff 2.41.0, API 2.50.0) against the real Operations API and the TEST shop: the
// operator sends the advance to finance, the assigned finance employee records the bank transfer, a second employee
// verifies it (four eyes, the default), the operator sees "Avansul a fost verificat de contabilitate." and the finance
// supervisor reads the activity without any action. A second order is not received (and measures the operator's polling
// in a real browser: 10 s while waiting, 5 s while finance checks, nothing while the tab is hidden, nothing once
// decided); a third goes back to the operator for a clarification. TEST people, TEST orders, TEST amounts only.
type Person = { username: string; name: string; id: string };
type Order = { order: string; number: string; reference: string };
type Fixture = { password: string; people: { rec: Person; app: Person; sup: Person }; operator: string; order: string; number: string; reference: string; notReceived: Order; clarify: Order; legacy: Order; before: Order; customer: string; phone: string };
const root = path.resolve(import.meta.dirname, "..");
let F: Fixture;

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-finance-requests-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
});

async function signIn(browser: Browser, username: string) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: "reduce" });
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

const orderPath = (order = F.order) => `/confirmare/orders/${encodeURIComponent(order)}`;
const noCustomer = async (page: Page) => {
  const text = await page.locator("main, body").first().innerText();
  expect(text).not.toContain(F.customer);
  expect(text).not.toContain(F.phone);
  expect(text).not.toContain("Draperie Velvet");
};

test("the operator sends the advance to finance from step 4; the card says who has it", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.operator);
  try {
    await page.goto(orderPath());
    const card = page.getByTestId("fr-step");
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute("data-phase", "none");
    await page.getByTestId("fr-step-request").click();
    await expect(page.getByTestId("fr-step-confirm")).toBeVisible();
    await page.getByTestId("fr-step-confirm-send").click();
    await expect(card).toHaveAttribute("data-phase", "assigned");
    await expect(page.getByTestId("fr-step-message")).toContainText(F.people.rec.name);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the finance employee sees only the task: no customer, no order screen; records the transfer with an explicit confirmation", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.rec.username);
  try {
    await expect(page.getByTestId("fr-open-inbox")).toBeVisible();
    await page.getByTestId("fr-open-inbox").click();
    await expect(page.getByTestId("fr-inbox")).toBeVisible();
    await expect(page.getByTestId(`fr-card-${F.number}`)).toBeVisible();
    await noCustomer(page);
    await page.getByTestId(`fr-open-${F.number}`).click();
    await expect(page.getByTestId("fr-task")).toBeVisible();
    await expect(page.getByTestId("fr-payment-reference")).toHaveText(F.reference);
    await noCustomer(page);
    await page.getByTestId("fr-start").click();
    await expect(page.getByTestId("fr-task")).toHaveAttribute("data-state", "checking");
    await expect(page.getByTestId("fr-start")).toHaveCount(0);
    await page.getByTestId("fr-mode-verified").click();
    // Owner 2026-10-29: a smaller amount on the statement is recorded as it is (never "not received"); the rest is named.
    await expect(page.getByTestId("fr-remaining")).toContainText("215,70");
    await page.getByTestId("fr-amount").fill("190,00");
    await expect(page.getByTestId("fr-partial-notice")).toContainText("25,70");
    await expect(page.getByTestId("fr-partial-notice")).toContainText("Plata nu a fost primită");
    await page.getByTestId("fr-amount").fill("215,70");
    await expect(page.getByTestId("fr-partial-notice")).toHaveCount(0);
    // A different payment reference is refused in the browser (a clarification, never a guess).
    await page.getByTestId("fr-amount").fill("215,70");
    await page.getByTestId("fr-bank-reference").fill("BT E2E 0001");
    await page.getByTestId("fr-value-date").fill(new Date().toISOString().slice(0, 10));
    await page.getByTestId("fr-statement-reference").fill("TH0000");
    await page.getByTestId("fr-statement").check();
    await page.getByTestId("fr-verify-next").click();
    await expect(page.getByTestId("fr-form-problem")).toBeVisible();
    await page.getByTestId("fr-statement-reference").fill(F.reference);
    await page.getByTestId("fr-verify-next").click();
    await expect(page.getByTestId("fr-confirm-text")).toContainText("BT E2E 0001");
    await page.getByTestId("fr-confirm-send").click();
    await expect(page.getByTestId("fr-receipt")).toHaveAttribute("data-state", "checking");
    await expect(page.getByTestId("fr-receipt")).toContainText("al doilea control");
    // The Confirmare order itself stays closed to a finance scope (the route falls back to Home).
    await page.goto(orderPath());
    await expect(page.getByTestId("cf-order")).toHaveCount(0);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the recorder cannot verify their own transfer; another employee does the second check", async ({ browser }) => {
  const own = await signIn(browser, F.people.rec.username);
  try {
    await own.page.goto("/confirmare/finance/requests");
    await own.page.getByTestId(`fr-open-${F.number}`).click();
    await expect(own.page.getByTestId("fr-awaiting-second")).toBeVisible();
    await expect(own.page.getByTestId("fr-second-verify")).toHaveCount(0);
  } finally { await own.context.close(); }
  const { context, page, errors } = await signIn(browser, F.people.app.username);
  try {
    await page.goto("/confirmare/finance/requests");
    await expect(page.getByTestId(`fr-card-${F.number}`)).toBeVisible();
    await page.getByTestId(`fr-open-${F.number}`).click();
    await expect(page.getByTestId("fr-transfers")).toContainText("BT E2E 0001");
    await noCustomer(page);
    await page.getByTestId("fr-second-verify").click();
    await page.getByTestId("fr-confirm-send").click();
    await expect(page.getByTestId("fr-receipt")).toHaveAttribute("data-state", "verified");
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the operator's step 4 shows the verified advance", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.operator);
  try {
    await page.goto(orderPath());
    await expect(page.getByTestId("fr-step")).toHaveAttribute("data-phase", "verified");
    await expect(page.getByTestId("fr-step-verified")).toHaveText("Avansul a fost verificat de contabilitate.");
    await expect(page.getByTestId("fr-step-request")).toHaveCount(0);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

test("the finance supervisor reads who recorded and who verified, and has no action", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, F.people.sup.username);
  try {
    await page.getByTestId("fr-open-oversight").click();
    await expect(page.getByTestId("fr-oversight")).toBeVisible();
    await expect(page.getByTestId("fr-oversight-readonly")).toBeVisible();
    const row = page.getByTestId(`fr-oversight-row-${F.number}`);
    await expect(row).toContainText(F.people.rec.name);
    await expect(row).toContainText(F.people.app.name);
    await row.click();
    await expect(page.getByTestId("fr-oversight-detail")).toBeVisible();
    await expect(page.getByTestId("fr-events")).toContainText("Al doilea control: verificat");
    await noCustomer(page);
    expect(await page.locator("button.button-primary").count()).toBe(0);
    await page.goto("/confirmare/finance/requests");
    await expect(page.getByTestId("fr-inbox")).toHaveCount(0);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
});

/** Every request of the step 4 card (GET .../finance-request), with the instant the browser sent it. */
function pollLog(page: Page, order: string) {
  const times: number[] = [];
  const suffix = `/confirmare/orders/${encodeURIComponent(order)}/finance-request`;
  page.on("request", (request) => { if (request.method() === "GET" && new URL(request.url()).pathname.endsWith(suffix)) times.push(Date.now()); });
  return times;
}
const setHidden = (page: Page, hidden: boolean) => page.evaluate((value) => {
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => (value ? "hidden" : "visible") });
  Object.defineProperty(document, "hidden", { configurable: true, get: () => value });
  document.dispatchEvent(new Event("visibilitychange"));
}, hidden);

test("not received, in a real browser: the operator's card follows finance by polling (10 s waiting, 5 s checking), never while hidden, and stops once decided", async ({ browser }) => {
  test.setTimeout(180_000);
  const order = F.notReceived;
  const op = await signIn(browser, F.operator);
  const fin = await signIn(browser, F.people.rec.username);
  try {
    const polls = pollLog(op.page, order.order);
    await op.page.goto(orderPath(order.order));
    const card = op.page.getByTestId("fr-step");
    await expect(card).toHaveAttribute("data-phase", "none");
    await op.page.getByTestId("fr-step-request").click();
    await op.page.getByTestId("fr-step-confirm-send").click();
    await expect(card).toHaveAttribute("data-phase", "assigned");

    // Waiting for finance: the card asks every 10 s (never faster than 5 s).
    const before = polls.length;
    await expect.poll(() => polls.length, { timeout: 25_000, intervals: [250] }).toBeGreaterThanOrEqual(before + 2);
    const waiting = polls[before + 1]! - polls[before]!;
    expect(waiting).toBeGreaterThanOrEqual(9_000);
    expect(waiting).toBeLessThanOrEqual(11_500);

    // A hidden tab never asks; showing it again resumes.
    await setHidden(op.page, true);
    const hiddenAt = polls.length;
    await op.page.waitForTimeout(12_000);
    expect(polls.length).toBe(hiddenAt);
    await setHidden(op.page, false);
    await expect.poll(() => polls.length, { timeout: 12_000, intervals: [250] }).toBeGreaterThan(hiddenAt);

    // Finance starts the check in another browser: the operator's card shows it without a reload, within one interval.
    await fin.page.goto("/confirmare/finance/requests");
    await fin.page.getByTestId(`fr-open-${order.number}`).click();
    await expect(fin.page.getByTestId("fr-task")).toBeVisible();
    await noCustomer(fin.page);
    const startedAt = Date.now();
    await fin.page.getByTestId("fr-start").click();
    await expect(fin.page.getByTestId("fr-task")).toHaveAttribute("data-state", "checking");
    await expect(card).toHaveAttribute("data-phase", "checking", { timeout: 12_000 });
    expect(Date.now() - startedAt).toBeLessThanOrEqual(12_000);

    // While finance checks: every 5 s.
    const checking = polls.length;
    await expect.poll(() => polls.length, { timeout: 15_000, intervals: [250] }).toBeGreaterThanOrEqual(checking + 2);
    const active = polls[checking + 1]! - polls[checking]!;
    expect(active).toBeGreaterThanOrEqual(4_500);
    expect(active).toBeLessThanOrEqual(6_500);

    // "Plata nu a fost primită", with an explicit confirmation and a receipt.
    await fin.page.getByTestId("fr-mode-not-received").click();
    await fin.page.getByTestId("fr-reason-not_found").check();
    await fin.page.getByTestId("fr-reason-next").click();
    await expect(fin.page.getByTestId("fr-confirm")).toBeVisible();
    await fin.page.getByTestId("fr-confirm-send").click();
    await expect(fin.page.getByTestId("fr-receipt")).toHaveAttribute("data-state", "not_received");
    await expect(card).toHaveAttribute("data-phase", "notReceived", { timeout: 8_000 });
    await expect(op.page.getByTestId("fr-step-request")).toBeVisible();

    // Decided: nothing more to wait for, the card stops asking.
    const decided = polls.length;
    await op.page.waitForTimeout(12_000);
    expect(polls.length).toBe(decided);
    expect([...op.errors, ...fin.errors]).toEqual([]);
  } finally { await op.context.close(); await fin.context.close(); }
});

test("clarification, in a real browser: finance asks, the operator answers, the task returns to finance", async ({ browser }) => {
  const order = F.clarify;
  const op = await signIn(browser, F.operator);
  try {
    await op.page.goto(orderPath(order.order));
    await op.page.getByTestId("fr-step-request").click();
    await op.page.getByTestId("fr-step-confirm-send").click();
    await expect(op.page.getByTestId("fr-step")).toHaveAttribute("data-phase", "assigned");
  } finally { await op.context.close(); }

  const fin = await signIn(browser, F.people.rec.username);
  try {
    await fin.page.goto("/confirmare/finance/requests");
    await fin.page.getByTestId(`fr-open-${order.number}`).click();
    await expect(fin.page.getByTestId("fr-payment-reference")).toHaveText(order.reference);
    await fin.page.getByTestId("fr-mode-clarify").click();
    await fin.page.getByTestId("fr-reason-reference_missing").check();
    await fin.page.getByTestId("fr-reason-next").click();
    await expect(fin.page.getByTestId("fr-confirm")).toBeVisible();
    await fin.page.getByTestId("fr-confirm-send").click();
    await expect(fin.page.getByTestId("fr-receipt")).toHaveAttribute("data-state", "needs_clarification");
    await fin.page.goto("/confirmare/finance/requests");
    await fin.page.getByTestId("fr-category-clarification").click();
    await expect(fin.page.getByTestId(`fr-card-${order.number}`)).toBeVisible();
    await noCustomer(fin.page);
    expect(fin.errors).toEqual([]);
  } finally { await fin.context.close(); }

  const back = await signIn(browser, F.operator);
  try {
    await back.page.goto(orderPath(order.order));
    const card = back.page.getByTestId("fr-step");
    await expect(card).toHaveAttribute("data-phase", "clarification");
    await expect(back.page.getByTestId("fr-step-request")).toHaveCount(0);
    await back.page.getByTestId("fr-step-resubmit").click();
    await expect(back.page.getByTestId("fr-step-confirm")).toBeVisible();
    await back.page.getByTestId("fr-step-confirm-send").click();
    await expect(card).toHaveAttribute("data-phase", "assigned");
    await expect(back.page.getByTestId("fr-step-message")).toContainText(F.people.rec.name);
    expect(back.errors).toEqual([]);
  } finally { await back.context.close(); }

  const again = await signIn(browser, F.people.rec.username);
  try {
    await again.page.goto("/confirmare/finance/requests");
    await again.page.getByTestId("fr-category-todo").click();
    await expect(again.page.getByTestId(`fr-card-${order.number}`)).toBeVisible();
    expect(again.errors).toEqual([]);
  } finally { await again.context.close(); }
});

test("an order of 23.10.2026 outside Confirmare (old date): the operator sends it by number, finance checks it, nothing is recorded as money (manual review)", async ({ browser }) => {
  const order = F.legacy;
  const op = await signIn(browser, F.operator);
  try {
    // 21.10.2026 23:59:59 Bucharest: outside the scope, no request offered. Staff 2.42.1: the lookup sits on the desk.
    await op.page.getByTestId("cf-desk-legacy").locator("summary").click();
    await op.page.getByTestId("fr-legacy-number").fill(F.before.number);
    await op.page.getByTestId("fr-legacy-open").click();
    await expect(op.page.getByTestId("fr-legacy-order")).toBeVisible();
    await expect(op.page.getByTestId("fr-step-scope")).toBeVisible();
    await expect(op.page.getByTestId("fr-step-request")).toHaveCount(0);
    await noCustomer(op.page);
    // 23.10.2026, the old workflow: covered, manual ledger.
    await op.page.goto("/");
    await op.page.getByTestId("cf-desk-legacy").locator("summary").click();
    await op.page.getByTestId("fr-legacy-number").fill(order.number);
    await op.page.getByTestId("fr-legacy-open").click();
    const card = op.page.getByTestId("fr-step");
    await expect(card).toHaveAttribute("data-phase", "none");
    await expect(op.page.getByTestId("fr-step-manual")).toBeVisible();
    await noCustomer(op.page);
    await op.page.getByTestId("fr-step-request").click();
    await op.page.getByTestId("fr-step-confirm-send").click();
    await expect(card).toHaveAttribute("data-phase", "assigned");
    await expect(op.page.getByTestId("fr-step-message")).toContainText(F.people.rec.name);
    expect(op.errors).toEqual([]);
  } finally { await op.context.close(); }

  const fin = await signIn(browser, F.people.rec.username);
  try {
    await fin.page.goto("/confirmare/finance/requests");
    await expect(fin.page.getByTestId(`fr-card-${order.number}`)).toBeVisible();
    await expect(fin.page.getByTestId(`fr-card-manual-${order.number}`)).toBeVisible();
    await expect(fin.page.getByTestId(`fr-card-${F.before.number}`)).toHaveCount(0);
    await fin.page.getByTestId(`fr-open-${order.number}`).click();
    await expect(fin.page.getByTestId("fr-task-manual")).toBeVisible();
    await expect(fin.page.getByTestId("fr-payment-reference")).toHaveText(order.reference);
    await noCustomer(fin.page);
    await fin.page.getByTestId("fr-mode-verified").click();
    await fin.page.getByTestId("fr-amount").fill("215,70");
    await fin.page.getByTestId("fr-bank-reference").fill("BT E2E 0043");
    await fin.page.getByTestId("fr-value-date").fill(new Date().toISOString().slice(0, 10));
    await fin.page.getByTestId("fr-statement-reference").fill(order.reference);
    await fin.page.getByTestId("fr-statement").check();
    await fin.page.getByTestId("fr-verify-next").click();
    await expect(fin.page.getByTestId("fr-confirm-text")).toContainText("fluxul vechi");
    await fin.page.getByTestId("fr-confirm-send").click();
    await expect(fin.page.getByTestId("fr-receipt")).toHaveAttribute("data-state", "requires_manual_review");
    // The order of 21.10 (no task) never appears to finance; the API refuses it (covered by the API integration suite).
    await fin.page.goto("/confirmare/finance/requests");
    await fin.page.getByTestId("fr-category-done").click();
    await expect(fin.page.getByTestId(`fr-card-${order.number}`)).toBeVisible();
    await expect(fin.page.getByTestId(`fr-card-${F.before.number}`)).toHaveCount(0);
    expect(fin.errors).toEqual([]);
  } finally { await fin.context.close(); }

  const back = await signIn(browser, F.operator);
  try {
    await back.page.goto(`/confirmare/finance/legacy/${encodeURIComponent(order.order)}`);
    await expect(back.page.getByTestId("fr-step")).toHaveAttribute("data-phase", "manualReview");
    await expect(back.page.getByTestId("fr-step-verified")).toHaveCount(0);
    await expect(back.page.getByTestId("fr-step-request")).toHaveCount(0);
    expect(back.errors).toEqual([]);
  } finally { await back.context.close(); }
});
