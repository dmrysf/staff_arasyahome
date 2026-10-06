import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

type Fixture = { password: string; qr: Record<string, string>; documents: { order: string; superseded: string; requester: string; approver: string; cutter: string } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
const ORIGIN = "http://127.0.0.1:4174";
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

async function signIn(browser: Browser, username: string, width = 390) {
  const context = await browser.newContext({ viewport: { width, height: 844 }, reducedMotion: "reduce", acceptDownloads: true });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  await page.evaluate(() => { (window as unknown as { __noReload: boolean }).__noReload = true; });
  return { context, page, errors, unreloaded: () => page.evaluate(() => (window as unknown as { __noReload?: boolean }).__noReload === true) };
}

const go = (page: Page, target: string) => page.evaluate((p) => { window.history.pushState({}, "", p); window.dispatchEvent(new PopStateEvent("popstate")); }, target);

test("production document: R1 without approval, reprint keeps QR, stale blocks the owner live, approval live, R2, old QR invalid", async ({ browser }) => {
  test.setTimeout(180_000);
  const orderId = `trendhome:${fixture.documents.order}`;
  const online = await signIn(browser, fixture.documents.requester);
  const cutter = await signIn(browser, fixture.documents.cutter, 360);

  // Requester: exact search, then revision 1 without any approval, print and reprint (same revision and QR).
  await online.page.getByLabel("Numărul comenzii").fill(fixture.documents.order);
  await online.page.getByRole("button", { name: "Caută", exact: true }).click();
  await expect(online.page.getByRole("heading", { name: `Comanda #${fixture.documents.order}` })).toBeVisible();
  await online.page.getByRole("button", { name: "Generează documentul (REVIZIA 1)" }).click();
  const panel = online.page.getByTestId("document-panel");
  await expect(panel.getByRole("heading", { name: "REVIZIA 1" })).toBeVisible();
  const first = online.page.waitForEvent("download");
  await panel.getByRole("button", { name: "Tipărește REVIZIA 1" }).click();
  expect((await first).suggestedFilename()).toBe(`ARASYA-${fixture.documents.order}-R1.pdf`);
  await expect(panel.getByRole("button", { name: "Retipărește REVIZIA 1" })).toBeVisible();
  await panel.getByLabel(/Motivul retipăririi/).fill("Hârtie deteriorată");
  const second = online.page.waitForEvent("download");
  await panel.getByRole("button", { name: "Retipărește REVIZIA 1" }).click();
  expect((await second).suggestedFilename()).toBe(`ARASYA-${fixture.documents.order}-R1.pdf`);
  await expect(panel).toContainText("tipărit de 2 ori");

  // The owner keeps working from his order page; the customer changes 8 m to 10 m at the source.
  await go(cutter.page, `/orders/${encodeURIComponent(orderId)}`);
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();
  execFileSync("php", [path.resolve(import.meta.dirname, "../operations-api/tests/fixtures/e2e-source-change.php"), fixture.documents.order, "10", "5"], { env: process.env });
  await expect(cutter.page.getByTestId("document-blocked")).toContainText("DOCUMENT BLOCAT", { timeout: 15_000 });
  await expect(cutter.page.getByTestId("document-blocked")).toContainText("Așteaptă aprobarea și documentul nou.");
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toHaveCount(0);

  // The requester sees the stale document live and asks for revision 2 with the exact diff.
  await expect(panel.getByRole("button", { name: "Cere REVIZIA 2" })).toBeVisible({ timeout: 15_000 });
  await panel.getByLabel(/Comentariu pentru aprobare/).fill("Clientul a schimbat metrajul.");
  await panel.getByRole("button", { name: "Cere REVIZIA 2" }).click();
  await expect(panel).toContainText("Așteaptă aprobarea pentru REVIZIA 2.");
  await expect(panel.locator(".document-changes")).toContainText("Linia 1 · Metri (linie)");
  await expect(panel.locator(".document-changes")).toContainText("8 m");
  await expect(panel.locator(".document-changes")).toContainText("10 m");

  // The revision approver decides in the central platform (Dashboard API); the requester sees it live.
  const api = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: ORIGIN } });
  const login = await api.post("/auth/login", { data: { username: fixture.documents.approver, password: fixture.password } });
  const csrf = (await login.json()).csrfToken as string;
  const queue = await (await api.get("/production-documents/revision-requests?view=pending")).json();
  const pending = queue.items.find((item: { order: { id: string } }) => item.order.id === orderId);
  expect(pending.changes[0]).toEqual({ field: "line.meters", line: 1, before: "8 m", after: "10 m" });
  expect(JSON.stringify(queue)).not.toMatch(/@|price|preț|payment/i);
  const decision = await api.post(`/production-documents/revision-requests/${pending.id}/decision`, { data: { expectedVersion: pending.version, decision: "approve" }, headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `doc-e2e-approve-${Date.now()}` } });
  expect(decision.status()).toBe(200);
  await expect(online.page.locator(".live-notice")).toContainText(`Revizia 2 a fost aprobată pentru comanda #${fixture.documents.order}`, { timeout: 15_000 });
  await expect(panel.getByText("Revizia 2 a fost aprobată.")).toBeVisible();
  await panel.getByRole("button", { name: "Generează documentul nou (REVIZIA 2)" }).click();
  await expect(panel.getByRole("heading", { name: "REVIZIA 2" })).toBeVisible();
  await expect(panel).toContainText("Document activ");

  // The owner is unblocked live and continues from the same stage.
  await expect(cutter.page.getByTestId("document-blocked")).toHaveCount(0, { timeout: 15_000 });
  await expect(cutter.page.getByRole("button", { name: /Finalizează etapa/ })).toBeVisible();

  // An old QR (revision 1 of order 72002, already replaced by revision 2) can never claim work.
  const superseded = `trendhome:${fixture.documents.superseded}`;
  await go(cutter.page, `/orders/${encodeURIComponent(superseded)}`);
  await cutter.page.getByRole("button", { name: "Preia comanda" }).click();
  const dialog = cutter.page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Introdu codul de pe etichetă" }).click();
  await dialog.getByLabel(/Cod etichetă/).fill(fixture.qr[fixture.documents.superseded]);
  await dialog.getByRole("button", { name: "Verifică codul" }).click();
  await dialog.getByRole("checkbox").first().check().catch(() => undefined);
  await dialog.getByRole("button", { name: "Confirmă" }).click();
  await expect(cutter.page.getByText("DOCUMENT INVALID")).toBeVisible();
  await expect(cutter.page.getByText(/Folosește REVIZIA 2\./)).toBeVisible();

  for (const [label, session] of [["requester", online], ["cutter", cutter]] as const) {
    for (const width of [1440, 1024, 768, 390, 360]) {
      await session.page.setViewportSize({ width, height: 900 });
      // Measured once layout settles after the resize.
      await expect.poll(() => overflow(session.page), { message: `no horizontal overflow for the ${label} at ${width}`, timeout: 3_000 }).toBeLessThanOrEqual(0);
    }
    expect(await session.unreloaded()).toBe(true);
    expect(session.errors).toEqual([]);
    expect(await session.page.evaluate(() => [Object.keys(localStorage).filter((k) => /token|session|csrf/i.test(k)), Object.keys(sessionStorage).filter((k) => /token|session|csrf/i.test(k))])).toEqual([[], []]);
  }
  await online.page.screenshot({ path: "e2e/.runtime/documents-requester.png", fullPage: true });
  await api.dispose();
  await online.context.close();
  await cutter.context.close();
});
