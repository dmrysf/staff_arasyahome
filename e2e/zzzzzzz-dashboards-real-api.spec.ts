import { expect, test, type Browser, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

type Fixture = {
  password: string;
  users: { ana: string };
  documents: { requester: string };
  trendyol: { approver: string; outsider: string; cutter: string };
  dashboards: { scoped: string; preparer: string; sewing: string; supervisor: string; multi: string };
  b2b: { id: string };
};
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
// Visual review screenshots locally only: full-page captures after viewport changes are not needed in CI.
const capture = (page: Page, name: string) => process.env.CI ? Promise.resolve() : page.screenshot({ path: `e2e/.runtime/${name}.png`, fullPage: true }).then(() => undefined);
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

async function signIn(browser: Browser, username: string, width = 390) {
  const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  const writes: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  page.on("request", (request) => {
    if (!request.url().startsWith(API)) { if (!request.url().startsWith("http://127.0.0.1:4174")) writes.push(`external ${request.url()}`); return; }
    if (request.method() !== "GET" && !/\/auth\/(login|session|refresh)$/.test(request.url())) writes.push(`${request.method()} ${request.url()}`);
  });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună, .+!$/ })).toBeVisible();
  return { context, page, errors, writes };
}

const metricValue = (page: Page, testId: string) => page.getByTestId(testId).locator("dd");
const apiStatus = (page: Page, route: string) => page.evaluate(async (url) => (await fetch(url, { credentials: "include" })).status, `${API}${route}`);

test("cutting employee: the cutting dashboard with real counts, no claim on open, every width without overflow", async ({ browser }) => {
  const ana = await signIn(browser, fixture.users.ana, 320);
  const { page } = ana;
  await expect(page.getByTestId("workspace-title")).toContainText("Tăiere");
  await expect(page.getByTestId("workspace-taiere")).toBeVisible();
  await expect(page.getByRole("navigation", { name: "Spațiile mele de lucru" })).toHaveCount(0);
  await expect(metricValue(page, "metric-total")).toHaveText(/^\d+$/);
  const queue = await page.evaluate(async (url) => (await fetch(url, { credentials: "include" })).json(), `${API}/orders/stage-queue?stage=material-preparation`);
  await expect(metricValue(page, "metric-total")).toHaveText(String(queue.counts.total));
  await expect(metricValue(page, "metric-mine")).toHaveText(new RegExp(`^${queue.counts.mine}\\+?$`));
  await expect(page.getByText("Comenzi disponibile", { exact: false }).first()).toBeVisible();
  await expect(page.getByText("Trendyol")).toHaveCount(0);
  for (const width of [320, 360, 375, 390, 430, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await overflow(page), `overflow at ${width}`).toBeLessThanOrEqual(0);
  }
  await capture(page, "dashboard-cutting-1280");
  await page.setViewportSize({ width: 360, height: 800 });
  await capture(page, "dashboard-cutting-360");
  // Direct API access outside the employee's grants is refused, not only hidden.
  expect(await apiStatus(page, "/orders/stage-queue?stage=packing")).toBe(403);
  expect(await apiStatus(page, "/management/production-overview")).toBe(403);
  expect(await apiStatus(page, "/trendyol/activity")).toBe(403);
  expect(ana.writes).toEqual([]);
  expect(ana.errors).toEqual([]);
  await ana.context.close();
  const anonymous = await browser.newContext();
  const anonymousPage = await anonymous.newPage();
  await anonymousPage.goto("/login");
  expect(await apiStatus(anonymousPage, "/orders/stage-queue?stage=material-preparation")).toBe(401);
  await anonymous.close();
});

test("multi-stage sewing employee: one Croitorie workspace with exactly the three granted stages", async ({ browser }) => {
  const sewing = await signIn(browser, fixture.dashboards.sewing, 375);
  const { page } = sewing;
  await expect(page.getByTestId("workspace-croitorie")).toBeVisible();
  const tabs = page.getByRole("tablist", { name: "Etapele mele" }).getByRole("tab");
  await expect(tabs).toHaveCount(3);
  await expect(tabs.nth(0)).toHaveAttribute("aria-selected", "true");
  await tabs.nth(1).click();
  await expect(tabs.nth(1)).toHaveAttribute("aria-selected", "true");
  await expect(page.getByRole("heading", { name: "Tivul lateral" })).toBeVisible();
  await expect(metricValue(page, "metric-total")).toHaveText(/^\d+$/);
  await expect(page.getByText("Nu există comenzi la această etapă")).toBeVisible();
  expect(await apiStatus(page, "/orders/stage-queue?stage=ironing")).toBe(403);
  expect(await overflow(page)).toBeLessThanOrEqual(0);
  expect(sewing.writes).toEqual([]);
  expect(sewing.errors).toEqual([]);
  await sewing.context.close();
});

test("multi-department employee: switcher, remembered choice after reload, working back button and deep links", async ({ browser }) => {
  const multi = await signIn(browser, fixture.dashboards.multi, 390);
  const { page } = multi;
  const switcher = page.getByRole("navigation", { name: "Spațiile mele de lucru" });
  await expect(switcher.getByRole("button")).toHaveCount(2);
  await expect(page.getByTestId("workspace-taiere")).toBeVisible();
  await switcher.getByRole("button", { name: /Pornire producție/ }).click();
  await expect(page.getByTestId("workspace-pornire")).toBeVisible();
  await expect(page.getByTestId("workspace-title")).toContainText("Pornire producție");
  await page.reload();
  await expect(page.getByTestId("workspace-pornire")).toBeVisible();
  await page.getByRole("button", { name: "Comenzi", exact: true }).click();
  await expect(page).toHaveURL(/\/orders$/);
  await page.goBack();
  await expect(page.getByTestId("workspace-pornire")).toBeVisible();
  await page.goto("/history");
  await expect(page).toHaveURL(/\/history$/);
  await expect(page.locator(".history-list, .empty-state, [role=status]").first()).toBeVisible();
  expect(multi.writes).toEqual([]);
  expect(multi.errors).toEqual([]);
  await multi.context.close();
});

test("supervisor: read-only management overview first, with the authorized stage workspaces next to it", async ({ browser }) => {
  const supervisor = await signIn(browser, fixture.dashboards.supervisor, 1280);
  const { page } = supervisor;
  await expect(page.getByTestId("workspace-management")).toBeVisible();
  await expect(page.locator(".stage-table tbody tr")).toHaveCount(14);
  const switcher = page.getByRole("navigation", { name: "Spațiile mele de lucru" });
  await expect(switcher.getByRole("button")).toHaveCount(3);
  await capture(page, "dashboard-management-1280");
  await switcher.getByRole("button", { name: /Control calitate/ }).click();
  await expect(page.getByTestId("workspace-control-calitate")).toBeVisible();
  await expect(page.getByText("De verificat la această etapă")).toBeVisible();
  for (const width of [320, 430]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await overflow(page), `overflow at ${width}`).toBeLessThanOrEqual(0);
  }
  expect(supervisor.writes).toEqual([]);
  expect(supervisor.errors).toEqual([]);
  await supervisor.context.close();
});

test("Trendyol: the approver's dashboard shows real counts and own activity; a preparer cannot approve; outsiders see nothing", async ({ browser }) => {
  const approver = await signIn(browser, fixture.trendyol.approver, 390);
  const { page } = approver;
  await expect(page.getByTestId("workspace-trendyol")).toBeVisible();
  await expect(page.getByTestId("workspace-title")).toContainText("Operațiuni Trendyol");
  const overview = await page.evaluate(async (url) => (await fetch(url, { credentials: "include" })).json(), `${API}/trendyol/overview`);
  await expect(metricValue(page, "metric-trendyol-pending")).toHaveText(String(overview.counts.pending));
  await expect(metricValue(page, "metric-trendyol-released")).toHaveText(String(overview.counts.released));
  await expect(metricValue(page, "metric-trendyol-attention")).toHaveText(String(overview.counts.attention));
  await expect(page.getByTestId("trendyol-connection")).toContainText("Activă · numai citire");
  await expect(page.getByRole("heading", { name: "Activitatea mea" })).toBeVisible();
  await expect(page.locator(".activity-list")).toContainText("Aprobată pentru producție");
  await capture(page, "dashboard-trendyol-390");
  await page.getByTestId("home-trendyol").click();
  await expect(page.getByRole("heading", { name: "Comenzi Trendyol" })).toBeVisible();
  await page.goBack();
  await expect(page.getByTestId("workspace-trendyol")).toBeVisible();
  for (const width of [320, 360, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    expect(await overflow(page), `overflow at ${width}`).toBeLessThanOrEqual(0);
  }
  expect(approver.writes).toEqual([]);
  expect(approver.errors).toEqual([]);
  await approver.context.close();

  const preparer = await signIn(browser, fixture.dashboards.preparer, 360);
  await expect(preparer.page.getByTestId("workspace-trendyol")).toBeVisible();
  await expect(preparer.page.getByText("aprobarea intrării în producție o face o persoană autorizată")).toBeVisible();
  await expect(preparer.page.getByRole("navigation", { name: "Spațiile mele de lucru" })).toHaveCount(0);
  expect(await apiStatus(preparer.page, "/orders/stage-queue?stage=waiting")).toBe(403);
  expect(preparer.writes).toEqual([]);
  expect(preparer.errors).toEqual([]);
  await preparer.context.close();

  for (const username of [fixture.trendyol.outsider, fixture.trendyol.cutter]) {
    const outsider = await signIn(browser, username, 390);
    await expect(outsider.page.getByTestId("workspace-trendyol")).toHaveCount(0);
    await expect(outsider.page.getByTestId("home-trendyol")).toHaveCount(0);
    expect(await apiStatus(outsider.page, "/trendyol/activity")).toBe(403);
    expect(outsider.errors).toEqual([]);
    await outsider.context.close();
  }
});

test("Trendyol-scoped waiting grant: the stage-1 workspace names its source and other sources stay unreachable through the API", async ({ browser }) => {
  const scoped = await signIn(browser, fixture.dashboards.scoped, 375);
  const { page } = scoped;
  await expect(page.getByTestId("workspace-trendyol")).toBeVisible();
  await page.getByRole("navigation", { name: "Spațiile mele de lucru" }).getByRole("button", { name: /Pornire producție/ }).click();
  await expect(page.getByTestId("workspace-pornire")).toBeVisible();
  await expect(page.getByTestId("stage-source-scope")).toContainText("doar pe comenzile din: Trendyol");
  const queue = await page.evaluate(async (url) => (await fetch(url, { credentials: "include" })).json(), `${API}/orders/stage-queue?stage=waiting`);
  expect(queue.items.every((item: { source: string }) => item.source === "trendyol")).toBe(true);
  await expect(metricValue(page, "metric-total")).toHaveText(String(queue.counts.total));
  expect(await apiStatus(page, `/orders/${encodeURIComponent(fixture.b2b.id)}`)).toBe(404);
  expect(await overflow(page)).toBeLessThanOrEqual(0);
  expect(scoped.writes).toEqual([]);
  expect(scoped.errors).toEqual([]);
  await scoped.context.close();
});

test("document-only channel employee keeps the document lookup as the workspace", async ({ browser }) => {
  const requester = await signIn(browser, fixture.documents.requester, 390);
  await expect(requester.page.getByTestId("workspace-documente")).toBeVisible();
  await expect(requester.page.getByLabel("Numărul comenzii")).toBeVisible();
  expect(requester.errors).toEqual([]);
  await requester.context.close();
});
