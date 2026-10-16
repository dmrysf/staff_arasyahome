import { expect, request, test, type APIRequestContext, type Browser, type Locator, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

// Per-piece rework against the real Operations API in Chromium (browser emulation of phone and desktop viewports, not a
// real device). The business shape of Trendyol order 11709027615: one line, commercial quantity 1, physical pieces 2, one defective curtain at
// ironing. Only that curtain goes worker -> supervisor -> cutter receipt -> manager -> cutting, and replays 2..8 through
// the Staff screens, one stage at a time; the healthy curtain stays at ironing.
type Fixture = { password: string; supervision: { chief: string; tailor: string; cutter: string; manager: string }; cutting: { root: { username: string; password: string } } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const sup = fixture.supervision;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

async function api(username: string, password = fixture.password): Promise<{ ctx: APIRequestContext; csrf: string }> {
  const ctx = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await ctx.post("/auth/login", { data: { username, password } });
  expect(login.status(), `login ${username}`).toBe(200);
  return { ctx, csrf: (await login.json()).csrfToken as string };
}
const post = (who: { ctx: APIRequestContext; csrf: string }, url: string, data: unknown) => who.ctx.post(url, { headers: { "X-CSRF-Token": who.csrf, "Idempotency-Key": `e2e-pc-${Date.now()}-${Math.random().toString(36).slice(2)}` }, data });

async function signIn(browser: Browser, username: string, width = 390, height = 844) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text()); });
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  return { context, page, errors };
}
const go = (page: Page, to: string) => page.evaluate((target) => { window.history.pushState({}, "", target); window.dispatchEvent(new PopStateEvent("popstate")); }, to);
async function typeLabel(section: Locator, code: string) {
  await section.getByRole("button", { name: "Introdu codul de pe etichetă" }).click();
  await section.getByLabel(/Cod etichetă/).fill(code);
  await section.getByRole("button", { name: "Verifică codul" }).click();
}
/** A request's QR action opens the one scanner for that request; the code is typed there (the fake camera shows one fixed QR). */
async function scanFor(page: Page, section: string, code: string): Promise<Locator> {
  await page.getByTestId(section).getByRole("button", { name: "Deschide scanerul" }).click();
  await page.getByRole("button", { name: "Introdu manual numărul / codul comenzii" }).click();
  await page.getByLabel(/Număr \/ cod comandă/).fill(code);
  await page.getByRole("button", { name: "Caută comanda" }).click();
  const action = page.getByTestId("scan-exception").getByTestId(section);
  await expect(action).toBeVisible();
  return action;
}
/** The pieces of the order as the given employee sees them through the API (server truth after every UI step). */
async function piecesAs(username: string, orderId: string) {
  const who = await api(username);
  const order = await (await who.ctx.get(`/orders/${encodeURIComponent(orderId)}`)).json();
  await who.ctx.dispose();
  return order as { productionStageId: string; pieces: { mixedProgress: boolean; onOrder: number; units: { id: string; index: number; trackId: string | null }[]; tracks: { id: string; stage: { id: string }; owner: { displayName: string } | null; reworkCycle: number }[] } };
}

let created = { order: "", number: "", qr: "" };
test.beforeAll(async () => {
  created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "..", "e2e", "piece-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
});

test("one defective curtain of two: report, custody, receipt, approval, re-cut and the full replay 2-8; the healthy curtain stays at ironing", async ({ browser }) => {
  test.setTimeout(300_000);
  const { order: orderId, number, qr } = created;
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const found = await (await root.ctx.get(`/management/employees?search=${encodeURIComponent(sup.chief)}`)).json();
  const chiefId = found.items.find((item: { username: string }) => item.username === sup.chief).id;
  const appointed = await post(root, "/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId });
  expect(appointed.status(), await appointed.text()).toBe(201);
  const assignmentId = (await appointed.json()).responsibilities.find((item: { responsibility: string; state: string }) => item.responsibility === "tailoring_quality_supervisor" && item.state === "active").id;

  const tailor = await signIn(browser, sup.tailor, 360, 760);
  const chief = await signIn(browser, sup.chief, 360, 740);
  const cutter = await signIn(browser, sup.cutter, 390, 844);
  const manager = await api(sup.manager);

  // 1-4. The worker at ironing reports exactly one piece: nothing is preselected, the count reads 1 of 2.
  await go(tailor.page, `/orders/${encodeURIComponent(orderId)}`);
  await tailor.page.getByRole("button", { name: "Raportează o problemă" }).click();
  const dialog = tailor.page.getByRole("dialog", { name: "Raportează o problemă" });
  await expect(dialog.getByTestId("piece-picker")).toBeVisible();
  await expect(dialog.getByTestId("piece-count")).toHaveText("Cantitate defectă: 0 din 2");
  await dialog.getByRole("checkbox", { name: "Bucata 1/2" }).check();
  await expect(dialog.getByTestId("piece-count")).toHaveText("Cantitate defectă: 1 din 2");
  await expect(dialog.getByText("Doar bucățile alese se întorc, dacă se aprobă.", { exact: false })).toBeVisible();
  await dialog.getByLabel("Problema observată").selectOption({ label: "Defect material neobservat" });
  await dialog.getByLabel(/Descriere/).fill("Fir tras pe o singură bucată");
  expect(await overflow(tailor.page)).toBeLessThanOrEqual(0);
  await dialog.getByRole("button", { name: "Raportează șefului de croitorie" }).click();
  await expect(tailor.page.getByTestId("piece-counts")).toContainText("1 buc. defectă");
  await expect(tailor.page.getByTestId("piece-counts")).toContainText("1 buc. conformă");
  await expect(tailor.page.getByTestId("affected-pieces")).toContainText("Bucata 1/2 · 12754-21 · Crem");
  let state = await piecesAs(sup.tailor, orderId);
  expect(state.productionStageId, "7. the healthy curtain stays at ironing").toBe("ironing");
  expect(state.pieces.onOrder).toBe(1);
  const trackId = state.pieces.tracks[0].id;

  // 5-8. The supervisor accepts with the order QR, prints the piece label, sends the piece to cutting.
  await go(chief.page, "/problems");
  await chief.page.getByRole("tab", { name: "De verificat" }).click();
  await chief.page.getByRole("button", { name: new RegExp(`Comanda #${number}`) }).click();
  const review = await scanFor(chief.page, "supervisor-review", qr);
  await review.getByLabel("Motivul confirmat").selectOption({ label: "Defect material neobservat" });
  await review.getByRole("button", { name: "Confirmă problema și preia produsul" }).click();
  await expect(chief.page.getByTestId("scan-exception-done-status")).toHaveText("În grija șefului de croitorie");
  await chief.page.getByRole("button", { name: "Vezi cererea" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("În grija șefului de croitorie");
  await expect(chief.page.getByTestId("partial-scope")).toContainText("Doar bucățile din această cerere se întorc la tăiere");
  await chief.page.getByTestId("piece-labels-open").click();
  await expect(chief.page.getByTestId("piece-labels").getByRole("img", { name: "Bucata 1/2" })).toBeVisible();
  const chiefApi = await api(sup.chief);
  const label = (await (await chiefApi.ctx.get(`/production-tracks/${trackId}/labels`)).json()).labels[0].payload as string;
  await chiefApi.ctx.dispose();
  expect(label).toMatch(/^ARASYA:U1:/);
  await chief.page.screenshot({ path: "e2e/.runtime/piece-custody-360.png", fullPage: true });
  await chief.page.getByTestId("supervisor-return").getByRole("button", { name: "Trimite la tăiere" }).click();
  await expect(chief.page.getByTestId("exception-status")).toHaveText("Așteaptă primirea la tăiere");
  state = await piecesAs(sup.tailor, orderId);
  expect([state.productionStageId, state.pieces.tracks[0].stage.id], "piece at stage 3 under custody, healthy piece at ironing").toEqual(["ironing", "workshop-receiving"]);

  // 9-11. The cutter receives the defective piece: explicit tick, disputed responsibility, the piece label scanned.
  const notice = cutter.page.locator(".live-notice");
  await expect(notice).toContainText(`Comanda #${number}`, { timeout: 15_000 });
  await notice.getByRole("button", { name: "Deschide cererea" }).click();
  await expect(cutter.page.getByTestId("affected-pieces")).toContainText("Bucata 1/2");
  // The piece label of the defective piece, scanned once in the one scanner, is the evidence of the receipt.
  const receipt = await scanFor(cutter.page, "cutter-receipt", label);
  await expect(cutter.page.getByTestId("scan-exception")).toContainText("Bucata 1/2");
  await receipt.getByRole("checkbox", { name: "Am primit produsul pentru verificare." }).check();
  await receipt.getByRole("radio", { name: /Contest responsabilitatea/ }).check();
  await receipt.getByLabel(/Comentariu/).fill("Defect de material, nu de tăiere");
  await receipt.getByRole("button", { name: "Confirmă primirea la tăiere" }).click();
  await expect(cutter.page.getByTestId("scan-exception-done-status")).toHaveText("Așteaptă aprobarea managerului");
  await cutter.page.getByRole("button", { name: "Vezi cererea" }).click();
  await expect(cutter.page.getByTestId("exception-status")).toHaveText("Așteaptă aprobarea managerului");
  const pending = await (await manager.ctx.get("/management/production-exceptions?view=pending")).json();
  const item = pending.items.filter((entry: { order: { id: string } }) => entry.order.id === orderId);
  expect(item.length, "11. exactly one pending manager decision").toBe(1);
  expect(item[0].orderScope.affectedQuantity).toBe(1);
  expect(item[0].orderScope.wholeOrderMoves).toBe(false);

  // 12-15. The manager approves; only the defective piece is at cutting, owned by the cutter; his screen says so.
  const approved = await post(manager, `/management/production-exceptions/${item[0].id}/decision`, { expectedVersion: item[0].version, decision: "approve", attribution: "none" });
  expect(approved.status(), await approved.text()).toBe(200);
  await go(cutter.page, `/orders/${encodeURIComponent(orderId)}`);
  const banner = cutter.page.getByTestId("piece-approved");
  await expect(banner).toContainText("Retur aprobat pentru remediere");
  await expect(banner).toContainText(`Comanda #${number}`);
  await expect(banner).toContainText("Produs: 12754-21");
  await expect(banner).toContainText("Cantitate de refăcut: 1 buc.");
  await expect(banner).toContainText("Etapa curentă: Tăiere");
  await expect(banner).toContainText("Responsabil: Mihai Tăiere");
  for (const width of [320, 360, 390]) { await cutter.page.setViewportSize({ width, height: 844 }); expect(await overflow(cutter.page), `cutter at ${width}`).toBeLessThanOrEqual(0); }
  await cutter.page.screenshot({ path: "e2e/.runtime/piece-approved-320.png", fullPage: true });
  state = await piecesAs(sup.tailor, orderId);
  expect(state.productionStageId, "15. the healthy curtain is still at ironing").toBe("ironing");
  expect([state.pieces.tracks[0].stage.id, state.pieces.tracks[0].owner?.displayName, state.pieces.tracks[0].reworkCycle]).toEqual(["material-preparation", "Mihai Tăiere", 2]);

  // 16-17. Re-cut: the piece goes to stage 3, never straight back to ironing.
  await cutter.page.getByTestId("piece-complete").click();
  await cutter.page.getByTestId("piece-outcomes").getByLabel("Rezultat", { exact: true }).selectOption("repair");
  await cutter.page.getByRole("dialog").getByRole("button", { name: "Confirmă" }).click();
  await expect(cutter.page.getByText("✓", { exact: false }).first()).toBeVisible();
  state = await piecesAs(sup.tailor, orderId);
  expect(state.pieces.tracks[0].stage.id, "17. after the re-cut: stage 3").toBe("workshop-receiving");

  // 18-25. The worker claims the piece (label scan) and completes 3, 4, 5, 6, 7 and 8 one by one; ironing needs the fix confirmed.
  await go(tailor.page, `/orders/${encodeURIComponent(orderId)}`);
  const replayed: string[] = [];
  for (const stage of ["workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing"]) {
    const track = tailor.page.getByTestId("piece-track");
    await track.getByTestId("piece-claim").click();
    await typeLabel(tailor.page.getByRole("dialog"), label);
    await expect(track.getByTestId("piece-complete")).toBeVisible();
    await track.getByTestId("piece-complete").click();
    const confirm = tailor.page.getByRole("dialog");
    if (stage === "ironing") {
      await expect(confirm.getByRole("button", { name: "Confirmă" })).toBeDisabled();
      await confirm.getByRole("checkbox", { name: /defectul inițial a fost remediat/ }).check();
    }
    await confirm.getByRole("button", { name: "Confirmă" }).click();
    await expect(tailor.page.getByRole("dialog")).toHaveCount(0);
    replayed.push((await piecesAs(sup.tailor, orderId)).pieces.tracks[0].stage.id);
  }
  expect(replayed, "D. 3 > 4 > 5 > 6 > 7 > 8 > 9, one stage at a time").toEqual(["labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height"]);
  state = await piecesAs(sup.tailor, orderId);
  expect(state.productionStageId, "the healthy curtain never moved").toBe("ironing");

  // Desktop: the same order screen, no horizontal overflow.
  const desktop = await signIn(browser, sup.tailor, 1280, 900);
  await go(desktop.page, `/orders/${encodeURIComponent(orderId)}`);
  await expect(desktop.page.getByTestId("pieces-panel")).toContainText("2 buc. fizice · 1 cu comanda · 1 separate");
  expect(await overflow(desktop.page)).toBeLessThanOrEqual(0);
  await desktop.page.screenshot({ path: "e2e/.runtime/piece-desktop-1280.png", fullPage: true });

  for (const who of [tailor, chief, cutter, desktop]) expect(who.errors).toEqual([]);
  await post(root, `/management/organization/responsibilities/${assignmentId}/revoke`, { reason: "Sfârșit test E2E" });
  await root.ctx.dispose();
  await manager.ctx.dispose();
  for (const who of [tailor, chief, cutter, desktop]) await who.context.close();
});

test("replacement declares original disposition, refreshes the new physical label and rejects the retired QR", async ({ browser }) => {
  test.setTimeout(120_000);
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "piece-fixture.php")], { env: { ...process.env, ARASYA_E2E_PIECE_NUMBER: `76036${Math.floor(Math.random() * 9000 + 1000)}` }, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const chiefId = (await (await root.ctx.get(`/management/employees?search=${sup.chief}`)).json()).items.find((e: { username: string }) => e.username === sup.chief).id;
  const appointment = await post(root, "/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId }); expect(appointment.status()).toBe(201);
  const assignment = (await appointment.json()).responsibilities.find((a: { responsibility: string; state: string }) => a.state === "active" && a.responsibility === "tailoring_quality_supervisor").id;
  const tailor = await api(sup.tailor); const chief = await api(sup.chief); const cutter = await api(sup.cutter); const manager = await api(sup.manager);
  let state = await (await tailor.ctx.get(`/orders/${encodeURIComponent(created.order)}`)).json(); const original = state.pieces.units.find((u: { index: number }) => u.index === 1);
  const reported = await post(tailor, `/orders/${encodeURIComponent(created.order)}/fault-reports`, { expectedVersion: state.productionVersion, units: [original.id], reasonKey: "material-defect", comment: "Înlocuire verificată pentru scenariul sintetic." }); expect(reported.status(), await reported.text()).toBe(201);
  let fault = await reported.json();
  const accepted = await post(chief, `/production-exceptions/${fault.id}/review`, { expectedVersion: fault.version, decision: "accept", reasonKey: "material-defect", qrToken: created.qr }); expect(accepted.status()).toBe(200); fault = await accepted.json();
  const oldLabel = (await (await chief.ctx.get(`/production-tracks/${fault.orderScope.trackId}/labels`)).json()).labels[0].payload;
  const sent = await post(chief, `/production-exceptions/${fault.id}/return`, { expectedVersion: fault.version }); expect(sent.status()).toBe(200); fault = await sent.json();
  const received = await post(cutter, `/production-exceptions/${fault.id}/acknowledge`, { expectedVersion: fault.version, confirmed: true, qrToken: oldLabel, responsibility: "accepted" }); expect(received.status()).toBe(200); fault = await received.json();
  const approved = await post(manager, `/management/production-exceptions/${fault.id}/decision`, { expectedVersion: fault.version, decision: "approve", attribution: "none" }); expect(approved.status()).toBe(200);
  const worker = await signIn(browser, sup.cutter, 320, 844); await go(worker.page, `/orders/${encodeURIComponent(created.order)}`);
  const track = worker.page.getByTestId("piece-track"); await track.getByTestId("piece-labels-open").click(); await expect(track.getByTestId("piece-labels").getByRole("img")).toHaveCount(1);
  const beforeLabel = await track.getByTestId("piece-labels").getByRole("img").getAttribute("src");
  await track.getByTestId("piece-complete").click(); const dialog = worker.page.getByRole("dialog");
  await dialog.getByLabel("Rezultat", { exact: true }).selectOption("replacement"); await expect(dialog.getByRole("button", { name: "Confirmă" })).toBeDisabled(); await dialog.getByLabel("Destinația originalului", { exact: true }).selectOption("quarantined");
  await worker.page.screenshot({ path: "e2e/.runtime/staff-replacement-320.png", fullPage: true }); await dialog.getByRole("button", { name: "Confirmă" }).click(); await expect(dialog).toHaveCount(0);
  await expect.poll(async () => track.getByTestId("piece-labels").getByRole("img").getAttribute("src")).not.toBe(beforeLabel);
  state = await (await tailor.ctx.get(`/orders/${encodeURIComponent(created.order)}`)).json(); expect(state.pieces.total).toBe(2); const replacement = state.pieces.units.find((u: { replacementOf: string }) => u.replacementOf === original.id); expect(replacement).toBeTruthy();
  const old = await (await chief.ctx.get(`/production-units/${original.id}/history`)).json(); expect(old.status).toBe("retired"); expect(old.replacedBy).toBe(replacement.id);
  const obsolete = await cutter.ctx.post("/orders/resolve-qr", { data: { token: oldLabel }, headers: { "X-CSRF-Token": cutter.csrf } }); expect(obsolete.status()).toBe(410);
  expect(worker.errors).toEqual([]);
  const revoked = await post(root, `/management/organization/responsibilities/${assignment}/revoke`, { reason: "Scenariu înlocuire încheiat" }); expect(revoked.status()).toBe(200);
  await Promise.all([worker.context.close(), root.ctx.dispose(), tailor.ctx.dispose(), chief.ctx.dispose(), cutter.ctx.dispose(), manager.ctx.dispose()]);
});

test("administrative recovery selects the original piece explicitly and keeps one detection credit for the same physical incident", async ({ browser }) => {
  test.setTimeout(120_000);
  const created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "piece-fixture.php")], { env: { ...process.env, ARASYA_E2E_PIECE_NUMBER: `76136${Math.floor(Math.random() * 9000 + 1000)}` }, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password); const chief = await api(sup.chief); const tailor = await api(sup.tailor);
  const employees = await (await root.ctx.get(`/management/employees?search=${sup.chief}`)).json(); const chiefId = employees.items.find((e: { username: string }) => e.username === sup.chief).id;
  const appointment = await post(root, "/management/organization/responsibilities", { responsibility: "tailoring_quality_supervisor", employeeId: chiefId }); expect(appointment.status()).toBe(201);
  const assignment = (await appointment.json()).responsibilities.find((a: { responsibility: string; state: string }) => a.state === "active" && a.responsibility === "tailoring_quality_supervisor").id;
  const state = await (await tailor.ctx.get(`/orders/${encodeURIComponent(created.order)}`)).json(); const unit = state.pieces.units.find((p: { index: number }) => p.index === 1);
  const report = await post(tailor, `/orders/${encodeURIComponent(created.order)}/fault-reports`, { expectedVersion: state.productionVersion, units: [unit.id], reasonKey: "material-defect", comment: "Aceeași problemă fizică în scenariul sintetic." }); expect(report.status()).toBe(201); let fault = await report.json(); const originalId = fault.id;
  const accepted = await post(chief, `/production-exceptions/${fault.id}/review`, { expectedVersion: fault.version, decision: "accept", reasonKey: "material-defect", qrToken: created.qr }); expect(accepted.status()).toBe(200); fault = await accepted.json();
  const tailorId = (await (await root.ctx.get(`/management/employees?search=${sup.tailor}`)).json()).items.find((e: { username: string }) => e.username === sup.tailor).id;
  const cancelled = await post(root, `/management/production-exceptions/${fault.id}/cancel`, { expectedVersion: fault.version, reason: "Recuperare administrativă în test, nu o problemă nouă.", recipientEmployeeId: tailorId }); expect(cancelled.status()).toBe(200); fault = await cancelled.json();
  const received = await post(tailor, `/production-exceptions/${fault.id}/receive`, { expectedVersion: fault.version, confirmed: true, qrToken: created.qr }); expect(received.status()).toBe(200); fault = await received.json(); expect(fault.status).toBe("cancelled");
  const worker = await signIn(browser, sup.tailor, 360, 844); await go(worker.page, `/exceptions/${fault.id}`); await worker.page.getByRole("button", { name: "Reia aceeași problemă fizică" }).click();
  await worker.page.getByRole("button", { name: "Raportează o problemă" }).click(); const dialog = worker.page.getByRole("dialog");
  await expect(dialog.getByRole("checkbox", { name: "Bucata 2/2", exact: true })).toHaveCount(0); await expect(dialog.getByRole("checkbox", { name: "Bucata 1/2", exact: true })).not.toBeChecked();
  await dialog.getByRole("checkbox", { name: "Bucata 1/2", exact: true }).check(); await dialog.getByLabel("Problema observată").selectOption("material-defect"); await dialog.getByLabel(/Descriere/).fill("Confirm aceeași problemă fizică; originalul este în audit.");
  await expect(dialog.getByRole("button", { name: "Raportează șefului de croitorie" })).toBeDisabled(); await dialog.getByRole("checkbox", { name: /Confirm că este aceeași problemă fizică/ }).check(); await dialog.getByRole("button", { name: "Raportează șefului de croitorie" }).click();
  await expect(worker.page.getByTestId("exception-status")).toHaveText("Așteaptă verificarea șefului de croitorie");
  const newId = worker.page.url().split("/exceptions/")[1]; const next = await (await chief.ctx.get(`/production-exceptions/${newId}`)).json();
  const verified = await post(chief, `/production-exceptions/${newId}/review`, { expectedVersion: next.version, decision: "accept", reasonKey: "material-defect", qrToken: created.qr }); expect(verified.status()).toBe(200);
  const ledger = JSON.parse(execFileSync("php", ["-r", '$p=new PDO("mysql:host=".getenv("ARASYA_TEST_DB_HOST").";port=".getenv("ARASYA_TEST_DB_PORT").";dbname=".getenv("ARASYA_E2E_DB_NAME"),getenv("ARASYA_TEST_DB_USER"),getenv("ARASYA_TEST_DB_PASSWORD")); $s=$p->prepare("SELECT COUNT(*) FROM production_exception_links WHERE exception_uuid=? AND linked_exception_uuid=? AND link_type=?");$s->execute([$argv[1],$argv[2],"administrative_recovery"]);$link=(int)$s->fetchColumn();$s=$p->prepare("SELECT COUNT(*) FROM production_quality_events WHERE exception_uuid IN (?,?) AND event_type=?");$s->execute([$argv[1],$argv[2],"fault_detected"]);echo json_encode([$link,(int)$s->fetchColumn()]);', newId, originalId], { env: process.env, encoding: "utf8" })); expect(ledger).toEqual([1, 1]);
  expect(worker.errors).toEqual([]); await post(root, `/management/organization/responsibilities/${assignment}/revoke`, { reason: "Scenariu recuperare încheiat" }); await Promise.all([worker.context.close(), root.ctx.dispose(), chief.ctx.dispose(), tailor.ctx.dispose()]);
});
