import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8"));
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
async function api(username: string, password = fixture.password) {
  const context = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await context.post("/auth/login", { data: { username, password } }); expect(login.status()).toBe(200);
  const csrf = (await login.json()).csrfToken;
  const send = (url: string, data: unknown) => context.post(url, { data, headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `assist-browser-${crypto.randomUUID()}` } });
  return { context, send, get: async (url: string) => { const r = await context.get(url); expect(r.status(), await r.text()).toBe(200); return r.json(); }, post: async (url: string, data: unknown) => {
    const r = await send(url, data); expect(r.status(), await r.text()).toBeLessThan(300); return r.json();
  } };
}
async function login(browser: Browser, user: string) {
  const context = await browser.newContext({ viewport: { width: 320, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage(); const errors: string[] = []; page.on("pageerror", e => errors.push(e.message));
  await page.goto("/login"); await page.getByLabel("Nume utilizator").fill(user); await page.getByLabel("Parolă").fill(fixture.password); await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible(); return { context, page, errors };
}
const go = (page: Page, to: string) => page.evaluate(target => { history.pushState({}, "", target); dispatchEvent(new PopStateEvent("popstate")); }, to);

test("PERDEA stages 6/7: an optional per-piece status that never blocks the mixed basket, at 320/360/390/1280", async ({ browser }) => {
  test.setTimeout(180_000);
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "assisted-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const chiefId = (await root.get(`/management/employees?search=${fixture.supervision.chief}`)).items.find((e: { username: string }) => e.username === fixture.supervision.chief).id;
  const appointed = await root.post("/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId });
  const assignment = appointed.responsibilities.find((r: { state: string; responsibility: string }) => r.state === "active" && r.responsibility === "tailoring_quality_supervisor").id;
  const chief = await api(fixture.supervision.chief); const cutter = await api(fixture.supervision.cutter); const tailor = await api(fixture.supervision.tailor); const other = await api("cut.intake.e2e");
  const pathOrder = `/orders/${encodeURIComponent(created.order)}`;
  const plan = await chief.get(`${pathOrder}/manufacturing-plan`);
  await chief.post(`${pathOrder}/manufacturing-plan/lines/${plan.lines[0].itemId}/confirm`, { expectedRevision: 0, expectedSourceHash: plan.lines[0].sourcePlanHash, productType: "perdea", pieceCount: 2, note: "Două panouri verificate fizic" });
  await chief.post(`${pathOrder}/manufacturing-plan/lines/${plan.lines[1].itemId}/confirm`, { expectedRevision: 0, expectedSourceHash: plan.lines[1].sourcePlanHash, productType: "draperii", pieceCount: 1, note: "O bucată verificată fizic" });
  let state = await cutter.get(pathOrder); const pool = await cutter.get("/cutting/pool");
  state = await cutter.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion, qrToken: created.qr, ownedCount: pool.ownedCount, confirmedMultiple: pool.ownedCount > 0 });
  state = await cutter.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  for (let i = 0; i < 3; i++) { state = await tailor.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion }); state = await tailor.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion }); }
  expect(state.productionStageId).toBe("bottom-hem"); expect(state.pieces.tracks).toEqual([]);
  expect(state.pieces.assistedCheckpoints.informational).toBe(true); expect(state.pieces.assistedCheckpoints.unmarked).toBe(2);
  expect(state.employeeAllowedAction.id).toBe("claim");
  const [first] = state.pieces.assistedCheckpoints.pieces;
  const refused = await other.send(`/production-units/${first.unitId}/assisted-checkpoint`, { stageId: "bottom-hem", status: "performed", expectedVersion: 0 });
  expect(refused.status()).toBe(403); expect((await refused.json()).error.code).toBe("ASSISTED_MARK_NOT_ALLOWED");

  const worker = await login(browser, fixture.supervision.tailor);
  await go(worker.page, pathOrder);
  const panel = worker.page.getByTestId("assisted-checkpoints");
  await expect(panel).toContainText("PERDEA — Lucrare auxiliară");
  await expect(panel).toContainText("Lucrarea a fost efectuată?");
  await expect(panel.getByTestId("assisted-summary")).toContainText("Efectuată: 0 · Neefectuată: 0 · Neconfirmată: 2");
  await expect(panel).not.toContainText("Comanda nu poate avansa");
  await expect(worker.page.getByRole("button", { name: /Preia comanda|Preiau/ }).first()).toBeEnabled();
  const rows = panel.getByTestId("assisted-piece");
  await expect(rows.nth(0)).toContainText("Neconfirmată");
  for (const width of [320, 360, 390, 1280]) { await worker.page.setViewportSize({ width, height: 844 }); expect(await overflow(worker.page), `${width}px overflow`).toBeLessThanOrEqual(0); await worker.page.screenshot({ path: `e2e/.runtime/staff-assisted-${width}.png`, fullPage: true }); }
  await worker.page.setViewportSize({ width: 320, height: 844 });
  // A lost answer keeps the same key: the retry replays one committed mark.
  let dropped = false;
  await worker.page.route("**/assisted-checkpoint", async route => { await route.fetch(); dropped = true; await route.abort("failed"); });
  await rows.nth(0).getByRole("checkbox", { name: "Lucrare efectuată" }).click();
  await expect(panel.getByRole("alert")).toContainText("Răspuns neconfirmat"); expect(dropped).toBe(true);
  await worker.page.unroute("**/assisted-checkpoint");
  await panel.getByRole("button", { name: "Reîncearcă aceeași cerere" }).click();
  await expect(panel.getByRole("status")).toContainText("Salvat de server.");
  await expect(rows.nth(0)).toContainText("Efectuată"); await expect(rows.nth(0)).toContainText("Marcat de Elena Croitorie");
  await rows.nth(1).getByRole("button", { name: "Marchează neefectuată" }).click();
  await expect(rows.nth(1)).toContainText("Neefectuată");
  await expect(panel.getByTestId("assisted-summary")).toContainText("Efectuată: 1 · Neefectuată: 1 · Neconfirmată: 0");
  const history1 = await root.get(`/production-units/${first.unitId}/history`);
  expect(history1.assistedCheckpoints.map((e: { status: string }) => e.status)).toEqual(["performed"]);
  await rows.nth(0).getByRole("checkbox", { name: "Lucrare efectuată" }).click();
  await expect(rows.nth(0)).toContainText("Neconfirmată");
  // Unmarked + not done: the mixed basket still moves on.
  state = await tailor.get(pathOrder);
  state = await tailor.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion });
  state = await tailor.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  expect(state.productionStageId).toBe("side-hem");
  expect(state.pieces.assistedCheckpoints.unmarked).toBe(2);
  expect(worker.errors).toEqual([]);
  await root.post(`/management/organization/responsibilities/${assignment}/revoke`, { reason: "Scenariu frontend încheiat" });
  await worker.context.close(); for (const who of [root, chief, cutter, tailor, other]) await who.context.dispose();
});
