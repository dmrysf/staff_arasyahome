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
  return { context, get: async (url: string) => { const r = await context.get(url); expect(r.status(), await r.text()).toBe(200); return r.json(); }, post: async (url: string, data: unknown) => {
    const r = await context.post(url, { data, headers: { "X-CSRF-Token": csrf, "Idempotency-Key": `roi36-browser-${crypto.randomUUID()}` } }); expect(r.status(), await r.text()).toBeLessThan(300); return r.json();
  } };
}
async function login(browser: Browser, user: string) {
  const context = await browser.newContext({ viewport: { width: 320, height: 844 }, reducedMotion: "reduce" });
  const page = await context.newPage(); const errors: string[] = []; page.on("pageerror", e => errors.push(e.message));
  await page.goto("/login"); await page.getByLabel("Nume utilizator").fill(user); await page.getByLabel("Parolă").fill(fixture.password); await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible(); return { context, page, errors };
}
const go = (page: Page, to: string) => page.evaluate(target => { history.pushState({}, "", target); dispatchEvent(new PopStateEvent("popstate")); }, to);
async function scan(page: Page, qr: string) {
  const panel = page.getByTestId("physical-operations");
  await panel.getByRole("button", { name: "Introdu codul de pe etichetă" }).click(); await panel.getByLabel(/Cod etichetă/).fill(qr); await panel.getByRole("button", { name: "Verifică codul" }).click();
}

test("Test39/40: confirmed physical counts, committed recovery, normal mixed routes and independent custody at 320/360/390/1280", async ({ browser }) => {
  test.setTimeout(180_000);
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "manifest-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const chiefId = (await root.get(`/management/employees?search=${fixture.supervision.chief}`)).items.find((e: { username: string }) => e.username === fixture.supervision.chief).id;
  const appointed = await root.post("/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId });
  const assignment = appointed.responsibilities.find((r: { state: string; responsibility: string }) => r.state === "active" && r.responsibility === "tailoring_quality_supervisor").id;
  const chief = await login(browser, fixture.supervision.chief);
  await go(chief.page, `/orders/${encodeURIComponent(created.order)}`);
  const panel = chief.page.getByTestId("manufacturing-plan"); const first = panel.getByTestId("plan-line").filter({ hasText: "P-QA" });
  await expect(panel).toContainText("Număr de bucăți neconfirmat");
  await first.getByRole("button", { name: "Verifică și confirmă planul" }).click();
  let dialog = chief.page.getByRole("dialog", { name: "Confirmă planul fizic" });
  await expect(dialog.getByLabel("Număr fizic de bucăți")).toHaveValue("");
  await dialog.getByLabel("Tip verificat").selectOption("perdea"); await dialog.getByLabel("Număr fizic de bucăți").fill("2");
  await expect(dialog.getByLabel("Metraj (m)", { exact: true }).first()).toHaveValue("");
  await dialog.getByLabel("Metraj (m)", { exact: true }).nth(0).fill("3"); await dialog.getByLabel("Metraj (m)", { exact: true }).nth(1).fill("2");
  await dialog.getByLabel("Confirmarea fizică și sursa verificării").fill("Două bucăți măsurate fizic; 3 m și 2 m.");
  let dropped = false;
  await chief.page.route("**/manufacturing-plan/lines/*/confirm", async route => { await route.fetch(); dropped = true; await route.abort("failed"); });
  await dialog.getByRole("button", { name: "Confirmă planul verificat" }).click();
  await expect(dialog.getByRole("alert")).toContainText("Răspuns neconfirmat"); expect(dropped).toBe(true);
  await expect(dialog.getByLabel("Număr fizic de bucăți")).toBeDisabled();
  await chief.page.unroute("**/manufacturing-plan/lines/*/confirm");
  await dialog.getByRole("button", { name: "Închide și reîncarcă" }).click(); await panel.getByRole("button", { name: "Reîncarcă starea salvată" }).click();
  await expect(first).toContainText("3.000 m"); await expect(first).toContainText("2.000 m");
  const second = panel.getByTestId("plan-line").filter({ hasText: "D-QA" });
  await second.getByRole("button", { name: "Verifică și confirmă planul" }).click(); dialog = chief.page.getByRole("dialog", { name: "Confirmă planul fizic" });
  await dialog.getByLabel("Tip verificat").selectOption("draperii"); await dialog.getByLabel("Număr fizic de bucăți").fill("1");
  await dialog.getByLabel("Confirmarea fizică și sursa verificării").fill("O bucată verificată fizic; metraj necunoscut."); await dialog.getByRole("button", { name: "Confirmă planul verificat" }).click(); await expect(dialog).toHaveCount(0);
  const plan = await root.get(`/orders/${encodeURIComponent(created.order)}/manufacturing-plan`);
  expect(plan.lines.map((l: { commercialQuantity: number; plan: { pieceCount: number } }) => [l.commercialQuantity, l.plan.pieceCount])).toEqual([[1, 2], [1, 1]]);
  expect(plan.lines[1].plan.materialTotal).toBeNull();
  const cutter = await api(fixture.supervision.cutter); const tailor = await api(fixture.supervision.tailor);
  const pathOrder = `/orders/${encodeURIComponent(created.order)}`;
  let state = await cutter.get(pathOrder); const pool = await cutter.get("/cutting/pool");
  state = await cutter.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion, qrToken: created.qr, ownedCount: pool.ownedCount, confirmedMultiple: pool.ownedCount > 0 });
  state = await cutter.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion });
  for (let i = 0; i < 3; i++) { state = await tailor.post(`${pathOrder}/claim`, { expectedVersion: state.productionVersion }); state = await tailor.post(`${pathOrder}/transition`, { expectedVersion: state.productionVersion }); }
  expect(state.productionStageId).toBe("bottom-hem"); expect(state.pieces.tracks[0].kind).toBe("route"); expect(state.pieces.tracks[0].stage.id).toBe("ironing"); expect(state.pieces.tracks[0].openException).toBeNull();
  await panel.getByRole("button", { name: "Reîncarcă starea salvată" }).click();
  await expect(chief.page.getByRole("heading", { name: new RegExp(`Comanda.*${created.number}`) })).toBeVisible();
  await expect(panel.getByTestId("piece-position").first()).toContainText("Călcare");
  for (const width of [320, 360, 390, 1280]) { await chief.page.setViewportSize({ width, height: 844 }); expect(await overflow(chief.page), `${width}px overflow`).toBeLessThanOrEqual(0); await chief.page.screenshot({ path: `e2e/.runtime/staff-mixed-${width}.png`, fullPage: true }); }
  const unit = state.pieces.units.find((u: { itemId: string }) => u.itemId === plan.lines[1].itemId);
  const productionVersion = state.productionVersion; const physical = chief.page.getByTestId("physical-operations");
  await physical.getByRole("button", { name: "Deschide operațiunile fizice" }).click(); await physical.getByLabel("Bucata exactă").selectOption(unit.id);
  await expect(physical).toContainText("Custode:"); await scan(chief.page, created.qr); await physical.getByRole("checkbox", { name: "Confirm bucata și operațiunea fizică selectată." }).check(); await physical.getByRole("button", { name: "Salvează operațiunea fizică" }).click(); await expect(physical.getByText("Operațiune salvată de server.", { exact: true })).toBeVisible();
  await physical.getByLabel("Operațiune", { exact: true }).selectOption("locate"); await physical.getByLabel("Loc înregistrat").selectOption("roi36-rail"); await scan(chief.page, created.qr); await physical.getByRole("checkbox", { name: "Confirm bucata și operațiunea fizică selectată." }).check(); await physical.getByRole("button", { name: "Salvează operațiunea fizică" }).click(); await expect(physical).toContainText("Loc: Șina QA înregistrată");
  await physical.getByLabel("Metri verificați").fill("0.333"); await physical.getByLabel("Explicație").fill("Pierdere măsurată în scenariul izolat."); await physical.getByRole("button", { name: "Înregistrează materialul măsurat" }).click();
  await expect(physical.getByText("Operațiune salvată de server.", { exact: true })).toBeVisible();
  const h = await root.get(`/production-units/${unit.id}/history`); expect(h.custody.location.id).toBe("roi36-rail"); expect(h.material.find((r: { kind: string }) => r.kind === "waste").stockEffect).toBe("none");
  expect((await tailor.get(pathOrder)).productionVersion).toBe(productionVersion); expect(chief.errors).toEqual([]);
  await root.post(`/management/organization/responsibilities/${assignment}/revoke`, { reason: "Scenariu frontend încheiat" });
  await chief.context.close(); await root.context.dispose(); await cutter.context.dispose(); await tailor.context.dispose();
});
