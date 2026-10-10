import { expect, test, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

// Automatic refresh of the Trendyol workspace against the real Operations API, with the browser clock under test
// control (no real waiting). Packages are added through the real intake store, as the server-side sync would.
type Fixture = { password: string };
const root = path.resolve(import.meta.dirname, "..");
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
const MINUTE = 60_000;
const importPackage = (packageId: number) => execFileSync("php", [path.join(root, "operations-api", "tests", "fixtures", "e2e-trendyol-import.php"), String(packageId)], { env: process.env, encoding: "utf8" }).trim();
const metricValue = (page: Page, testId: string) => page.getByTestId(testId).locator("dd");
const setVisibility = (page: Page, state: "visible" | "hidden") => page.evaluate((next) => {
  Object.defineProperty(document, "visibilityState", { configurable: true, get: () => next });
  document.dispatchEvent(new Event("visibilitychange"));
}, state);

test.describe.configure({ mode: "serial" });

test("Trendyol workspace refreshes every minute while visible, reads little, never writes and never touches the package form", async ({ browser }) => {
  test.setTimeout(120_000);
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  await page.clock.install();
  const reads: string[] = [];
  const writes: string[] = [];
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("request", (request) => {
    if (!request.url().startsWith(API)) return;
    const url = new URL(request.url());
    if (request.method() !== "GET" && !/\/auth\/(login|session|refresh)$/.test(url.pathname)) writes.push(`${request.method()} ${url.pathname}`);
    if (url.pathname.startsWith("/trendyol/")) reads.push(`${url.pathname.slice("/trendyol/".length)}${url.searchParams.get("view") ? `:${url.searchParams.get("view")}` : ""}`);
  });
  const settle = async (expected: number) => { await expect.poll(() => reads.length).toBe(expected); await page.waitForTimeout(300); expect(reads.length).toBe(expected); };
  const take = () => reads.splice(0).sort();

  // The Trendyol operator profile of the fixture: waiting scoped to Trendyol, approver.
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill("ty.scoped.e2e");
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByTestId("workspace-trendyol")).toBeVisible();
  await expect(page.getByTestId("trendyol-refresh")).toContainText("Actualizare automată la fiecare minut");
  await expect(page.getByTestId("trendyol-refresh")).toContainText(/Ultima actualizare \d{2}:\d{2}/);
  await settle(5);
  expect(take()).toEqual(["activity", "overview", "packages:attention", "packages:pending", "packages:released"]);
  const pendingBefore = Number(await metricValue(page, "metric-trendyol-pending").textContent());

  // An unchanged minute: one overview read.
  await page.clock.fastForward(MINUTE);
  await settle(1);
  expect(take()).toEqual(["overview"]);

  // A new eligible package arrives through the intake store: the next refresh reads the lists and shows it.
  expect(importPackage(74001)).toBe("received");
  await page.clock.fastForward(MINUTE);
  await expect(metricValue(page, "metric-trendyol-pending")).toHaveText(String(pendingBefore + 1));
  await expect(page.getByRole("button", { name: /Comanda #TY74001/ })).toBeVisible();
  expect(take()).toEqual(["overview", "packages:attention", "packages:pending", "packages:released"]);

  // A hidden tab reads nothing; visible again after five minutes it reads at once.
  await setVisibility(page, "hidden");
  await page.clock.fastForward(5 * MINUTE);
  await page.waitForTimeout(300);
  expect(reads).toEqual([]);
  await setVisibility(page, "visible");
  await settle(5);
  take();

  // Inbox: the selected tab and its scroll position stay; the counts of the other tabs follow new packages.
  await page.getByTestId("home-trendyol").click();
  await expect(page.getByRole("heading", { name: "Comenzi Trendyol" })).toBeVisible();
  await settle(2);
  take();
  await page.clock.fastForward(5 * MINUTE);
  await page.waitForTimeout(300);
  expect(reads.filter((read) => read === "activity" || read === "packages:released"), "the dashboard stopped when the employee left it").toEqual([]);
  take();
  await page.getByRole("tab", { name: /Ignorate/ }).click();
  await expect(page.getByLabel("Pachete ignorate")).toBeVisible();
  await settle(2);
  take();
  await page.setViewportSize({ width: 390, height: 420 });
  await page.evaluate(() => window.scrollTo(0, 160));
  const scrolled = await page.evaluate(() => window.scrollY);
  expect(importPackage(74002)).toBe("received");
  await page.clock.fastForward(MINUTE);
  await settle(2);
  expect(take(), "a count change re-reads the overview and only the selected list").toEqual(["overview", "packages:ignored"]);
  await page.clock.fastForward(MINUTE);
  await settle(1);
  expect(take(), "an unchanged minute on the inbox reads only the overview").toEqual(["overview"]);
  await expect(page.getByRole("tab", { name: /De pregătit/ })).toContainText(String(pendingBefore + 2));
  await expect(page.getByRole("tab", { name: /Ignorate/ })).toHaveAttribute("aria-selected", "true");
  expect(await page.evaluate(() => window.scrollY)).toBe(scrolled);
  await page.setViewportSize({ width: 390, height: 844 });

  // The package form: typed, unsaved measurements survive minutes; nothing refreshes or saves under the employee.
  await page.getByRole("tab", { name: /De pregătit/ }).click();
  await page.getByRole("button", { name: /Comanda #TY74002/ }).click();
  const line = page.getByTestId("trendyol-line-1");
  await line.getByLabel("Lățime (cm)").fill("287");
  await line.getByLabel("Note pentru atelier (opțional)").fill("Nesalvat");
  reads.splice(0);
  await page.clock.fastForward(5 * MINUTE);
  await page.waitForTimeout(300);
  expect(reads, "the package screen does not poll").toEqual([]);
  await expect(line.getByLabel("Lățime (cm)")).toHaveValue("287");
  await expect(line.getByLabel("Note pentru atelier (opțional)")).toHaveValue("Nesalvat");

  // An expired session ends polling: the app returns to login and no further Trendyol read follows.
  await page.getByRole("button", { name: /Acasă|Înapoi/ }).first().click();
  await expect(page.getByTestId("workspace-trendyol").or(page.getByRole("heading", { name: "Comenzi Trendyol" }))).toBeVisible();
  await context.clearCookies();
  await page.clock.fastForward(MINUTE);
  await expect(page.getByLabel("Nume utilizator")).toBeVisible();
  reads.splice(0);
  await page.clock.fastForward(10 * MINUTE);
  await page.waitForTimeout(300);
  expect(reads).toEqual([]);

  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
  await context.close();
});
