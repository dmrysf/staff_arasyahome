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

test("PERDEA stage 6: every piece needs its own assisted check before the mixed basket moves, at 320/360/390/1280", async ({ browser }) => {
  test.setTimeout(180_000);
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "assisted-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const chiefId = (await root.get(`/management/employees?search=${fixture.supervision.chief}`)).items.find((e: { username: string }) => e.username === fixture.supervision.chief).id;
  const appointed = await root.post("/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId });
  const assignment = appointed.responsibilities.find((r: { state: string; responsibility: string }) => r.state === "active" && r.responsibility === "tailoring_quality_supervisor").id;
  const chief = await api(fixture.supervision.chief); const cutter = await api(fixture.supervision.cutter); const tailor = await api(fixture.supervision.tailor); const helper = await api("cut.intake.e2e");
  const pathOrder = `/orders/${encodeURIComponent(created.order)}`;
  const plan = await chief.get(`${pathOrder}/manufacturing-plan`);
  await chief.post(`${pathOrder}/manufacturing-plan/lines/${plan.lines[0].itemId}/confirm`, { expectedRevision: 0, expectedSourceHash: plan.lines[0].sourcePlanHash, productType: "perdea", pieceCount: 2, note: "Două panouri verificate fizic" });
  await chief.post(`${pathOrder}/manufacturing-plan/lines/${plan.lines[1].itemId}/confirm`, { expectedRevision: 0, expectedSourceHash: plan.lines[1].sourcePlanHash, productType: "draperii", pieceCount: 1, note: "O bucată verificată fizic" });
  let state = await cutter.get(pathOrder); const pool = await cutter.get("/cutting/pool");
  state = await cutter.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion, qrToken: created.qr, ownedCount: pool.ownedCount, confirmedMultiple: pool.ownedCount > 0 });
  state = await cutter.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  for (let i = 0; i < 3; i++) { state = await tailor.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion }); state = await tailor.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion }); }
  expect(state.productionStageId).toBe("bottom-hem"); expect(state.pieces.tracks).toEqual([]);
  expect(state.pieces.assistedCheckpoints.required).toBe(2); expect(state.pieces.assistedCheckpoints.verified).toBe(0);
  state = await tailor.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion });
  const refused = await tailor.send(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  expect(refused.status()).toBe(409); expect((await refused.json()).error.code).toBe("ASSISTED_VERIFICATION_REQUIRED");
  const [first] = state.pieces.assistedCheckpoints.pieces;
  // A helper from cutting takes piece 1 for the work and hands it back (API; the helper uses the same scanner).
  await helper.post(`/production-units/${first.unitId}/assisted-checkpoint`, { action: "take", stageId: "bottom-hem", expectedVersion: 0, qrToken: created.qr });
  await helper.post(`/production-units/${first.unitId}/assisted-checkpoint`, { action: "return", stageId: "bottom-hem", expectedVersion: 1, qrToken: created.qr });

  const worker = await login(browser, fixture.supervision.tailor);
  await go(worker.page, pathOrder);
  const panel = worker.page.getByTestId("assisted-checkpoints");
  await expect(panel.getByTestId("assisted-progress")).toContainText("Verificate: 0 din 2");
  await expect(panel.getByTestId("assisted-progress")).toContainText("Comanda nu poate avansa. Există produse nefinalizate la această etapă.");
  await expect(panel).toContainText("lucrare de producție la această etapă");
  const rows = panel.getByTestId("assisted-piece");
  await expect(rows.nth(0)).toContainText("Înapoi la punctul de verificare"); await expect(rows.nth(0)).toContainText("Lucrare auxiliară: Ioana Tăiere");
  await expect(rows.nth(1)).toContainText("În așteptarea lucrării auxiliare");
  for (const width of [320, 360, 390, 1280]) { await worker.page.setViewportSize({ width, height: 844 }); expect(await overflow(worker.page), `${width}px overflow`).toBeLessThanOrEqual(0); await worker.page.screenshot({ path: `e2e/.runtime/staff-assisted-${width}.png`, fullPage: true }); }
  await worker.page.setViewportSize({ width: 320, height: 844 });
  const verify = async (row: number, name: string, drop = false) => {
    await rows.nth(row).getByRole("button", { name }).click();
    const dialog = worker.page.getByTestId("assisted-dialog");
    await expect(dialog.getByRole("button", { name: "Trimite" })).toBeDisabled();
    await dialog.getByRole("button", { name: "Introdu codul de pe etichetă" }).click(); await dialog.getByLabel(/Cod etichetă/).fill(created.qr); await dialog.getByRole("button", { name: "Verifică codul" }).click();
    await dialog.getByRole("checkbox").check();
    if (drop) {
      let dropped = false;
      await worker.page.route("**/assisted-checkpoint", async route => { await route.fetch(); dropped = true; await route.abort("failed"); });
      await dialog.getByRole("button", { name: "Trimite" }).click();
      await expect(dialog.getByRole("alert")).toContainText("Răspuns neconfirmat"); expect(dropped).toBe(true);
      await worker.page.unroute("**/assisted-checkpoint");
      // The retry replays the same key: one committed check, never a second one.
      await dialog.getByRole("button", { name: "Reîncearcă aceeași cerere" }).click();
    } else {
      await dialog.getByRole("button", { name: "Trimite" }).click();
    }
    await expect(dialog).toHaveCount(0); await expect(panel.getByRole("status")).toContainText("Salvat de server.");
  };
  await verify(0, "Verificată – lucrare efectuată", true);
  await expect(rows.nth(0)).toContainText("Verificat de: Elena Croitorie");
  await expect(panel.getByTestId("assisted-progress")).toContainText("Verificate: 1 din 2");
  const history1 = await root.get(`/production-units/${first.unitId}/history`);
  expect(history1.assistedCheckpoints.map((e: { action: string }) => e.action)).toEqual(["taken_for_work", "work_returned", "verified"]);
  await verify(1, "Lucrare neefectuată");
  await expect(rows.nth(1)).toContainText("Neefectuată – necesită intervenție");
  state = await tailor.get(pathOrder);
  const stillRefused = await tailor.send(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  expect(stillRefused.status()).toBe(409);
  await verify(1, "Verificată – lucrare efectuată");
  await expect(panel.getByTestId("assisted-progress")).toContainText("Toate perdelele sunt verificate la această etapă.");
  state = await tailor.get(pathOrder);
  state = await tailor.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  expect(state.productionStageId).toBe("side-hem"); expect(state.pieces.assistedCheckpoints.verified).toBe(0);
  expect(worker.errors).toEqual([]);
  await root.post(`/management/organization/responsibilities/${assignment}/revoke`, { reason: "Scenariu frontend încheiat" });
  await worker.context.close(); for (const who of [root, chief, cutter, tailor, helper]) await who.context.dispose();
});
