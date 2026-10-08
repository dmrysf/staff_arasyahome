import { expect, test, type Browser, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";

type Fixture = {
  b2b: { id: string; code: string; username: string };
  password: string;
  users: { ana: string; bogdan: string; mihai: string; dashboardOnly: string; temporary: string };
  orders: { flow: string; qr: string; claimedByOther: string; conflict: string };
  qr: Record<string, string>;
};

test('a real B2B handoff shows frozen manufacturing context in Staff and uses the same claim/transition UI', async ({ page }) => {
  const errors = trackErrors(page);
  await login(page, fixture.b2b.username); await lookup(page, fixture.b2b.code);
  await expect(page.getByRole('heading', { name: `Comanda #${fixture.b2b.code}` })).toBeVisible();
  await page.getByRole('button', { name: /Preia comanda/ }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmă' }).click();
  await page.getByRole('button', { name: 'Vezi comanda' }).click();
  await expect(page.getByRole('heading', { name: 'Companie înghețată E2E' })).toBeVisible();
  await expect(page.locator('.detail-products')).toContainText('200 × 260 cm');
  await expect(page.locator('.detail-products')).toContainText('13,5 m');
  await expect(page.locator('.production-note')).toHaveCount(2);
  await expect(page.getByText('Notă linie înghețată', { exact: true })).toBeVisible();
  await expect(page.getByText('Instrucțiune producție înghețată', { exact: true })).toBeVisible();
  await expect(page.locator('.commerce-status')).toContainText('Stare comercială: Finalizată');
  await page.getByRole('button', { name: /Finalizează etapa/ }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmă' }).click();
  await expect(page.locator('.detail-hero')).toContainText('Tăiere');
  expect(errors).toEqual([]);
});

const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const apiOrigin = "http://127.0.0.1:8787";

test.describe.configure({ mode: "serial" });

function trackErrors(page: Page) {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => {
    // Expected HTTP failures (409/404/401) are logged by Chromium as resource errors; app errors are not allowed.
    if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text());
  });
  return errors;
}

async function login(page: Page, username: string) {
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
}

async function lookup(page: Page, code: string) {
  await page.goto("/scan");
  await page.getByRole("button", { name: /Introdu manual/ }).click();
  await page.getByLabel("Număr / cod comandă").fill(code);
  await page.getByRole("button", { name: "Caută comanda" }).click();
}

async function newEmployeePage(browser: Browser, username: string) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await login(page, username);
  return { context, page };
}

async function captureClaimLabel(page: Page, orderNumber: string) {
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("button", { name: "Confirmă", exact: true })).toBeDisabled();
  await dialog.getByRole("button", { name: "Introdu codul de pe etichetă" }).click();
  await dialog.getByLabel("Cod etichetă (ARASYA:Q1:…)").fill(fixture.qr[orderNumber]);
  await dialog.getByRole("button", { name: "Verifică codul" }).click();
  const multiple = dialog.getByRole("checkbox");
  if (await multiple.count()) await multiple.check();
}

test("login, session restore, manual lookup, claim, stage completion, handover and history use the real API", async ({ page }) => {
  const errors = trackErrors(page);
  const mutations: string[] = [];
  page.on("request", (request) => { if (request.method() === "POST" && /\/orders\//.test(request.url())) mutations.push(request.url()); });

  await page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.orders.flow}`)}`);
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  await login(page, fixture.users.ana);
  await page.reload();
  await expect(page.getByRole("heading", { name: "Bună, Ana." })).toBeVisible();
  await expect(page.locator(".work-summary")).toContainText("Astăzi");

  await lookup(page, `#${fixture.orders.flow}`);
  await expect(page.getByRole("heading", { name: `Comanda #${fixture.orders.flow}` })).toBeVisible();
  await expect(page.getByText("300 × 260 cm")).toBeVisible();
  await page.getByRole("button", { name: /Preia comanda/ }).click();
  await expect(page.getByRole("dialog")).toContainText("Preiei comanda?");
  await captureClaimLabel(page, fixture.orders.flow);
  await page.getByRole("button", { name: "Confirmă" }).click();
  await expect(page.getByRole("heading", { name: "Comanda este la tine" })).toBeVisible();

  await page.getByRole("button", { name: "Vezi comanda" }).click();
  await expect(page.getByText("Preluată de tine")).toBeVisible();
  await page.getByRole("button", { name: /Finalizează etapa/ }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toContainText("Tăiere");
  await expect(dialog).toContainText("Primire Croitorie");
  await dialog.getByRole("button", { name: "Confirmă" }).dblclick();
  await expect(page.getByText("✓ Comanda a fost predată")).toBeVisible();
  await expect(page.getByText("Comanda este la o etapă care nu îți este alocată.")).toBeVisible();
  await expect(page.locator(".detail-hero")).toContainText("Primire Croitorie");
  expect(mutations.filter((url) => url.endsWith("/transition"))).toHaveLength(1);
  expect(mutations.filter((url) => url.endsWith("/claim"))).toHaveLength(1);

  await page.getByRole("button", { name: "Comenzi", exact: true }).click();
  await page.getByRole("tab", { name: /Predate/ }).click();
  await expect(page.locator(".order-card")).toContainText(`#${fixture.orders.flow}`);

  await page.getByRole("button", { name: "Istoric", exact: true }).click();
  await expect(page.locator(".history-list")).toContainText("Tăiere → Primire Croitorie");
  await expect(page.locator(".history-list")).toContainText("Preluată la Tăiere");
  await page.getByRole("button", { name: "Calendar" }).click();
  await expect(page.locator(".history-list")).toContainText(`#${fixture.orders.flow}`);

  await page.getByRole("button", { name: "Acasă", exact: true }).click();
  await expect(page.locator(".work-summary dl > div").nth(1)).toContainText("1");
  expect(errors).toEqual([]);
});

test("the next stage employee receives the handover; others are blocked or cannot see the order", async ({ browser }) => {
  const mihai = await newEmployeePage(browser, fixture.users.mihai);
  await lookup(mihai.page, fixture.orders.flow);
  await mihai.page.getByRole("button", { name: /Preia comanda/ }).click();
  await mihai.page.getByRole("button", { name: "Confirmă" }).click();
  await expect(mihai.page.getByRole("heading", { name: "Comanda este la tine" })).toBeVisible();
  await mihai.page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.orders.flow}`)}`);
  await expect(mihai.page.getByText("Primită prin transfer")).toBeVisible();
  await lookup(mihai.page, fixture.orders.qr);
  await expect(mihai.page.getByRole("heading", { name: "Comanda nu a fost găsită" })).toBeVisible();
  await mihai.context.close();

  const ana = await newEmployeePage(browser, fixture.users.ana);
  await lookup(ana.page, fixture.orders.claimedByOther);
  await expect(ana.page.getByText("Un coleg lucrează deja la această comandă.")).toBeVisible();
  await expect(ana.page.getByRole("button", { name: /Preia comanda|Finalizează/ })).toHaveCount(0);
  const crossEmployee = await ana.page.request.get(`${apiOrigin}/orders/mine`);
  expect((await crossEmployee.json()).items.map((order: { id: string }) => order.id)).not.toContain(`outletperdele:${fixture.orders.claimedByOther}`);
  await ana.context.close();
});

test("a concurrent claim produces ORDER_CHANGED and a safe reload instead of fake success", async ({ browser }) => {
  const ana = await newEmployeePage(browser, fixture.users.ana);
  const bogdan = await newEmployeePage(browser, fixture.users.bogdan);
  await lookup(ana.page, fixture.orders.conflict);
  await expect(ana.page.getByRole("button", { name: /Preia comanda/ })).toBeVisible();

  await lookup(bogdan.page, fixture.orders.conflict);
  await bogdan.page.getByRole("button", { name: /Preia comanda/ }).click();
  await captureClaimLabel(bogdan.page, fixture.orders.conflict);
  await bogdan.page.getByRole("button", { name: "Confirmă" }).click();
  await expect(bogdan.page.getByRole("heading", { name: "Comanda este la tine" })).toBeVisible();

  await ana.page.getByRole("button", { name: /Preia comanda/ }).click();
  await captureClaimLabel(ana.page, fixture.orders.conflict);
  await ana.page.getByRole("button", { name: "Confirmă" }).click();
  await expect(ana.page.getByRole("heading", { name: "Comanda s-a modificat" })).toBeVisible();
  await ana.page.getByRole("button", { name: "Reîncarcă" }).click();
  await expect(ana.page.getByText("Un coleg lucrează deja la această comandă.")).toBeVisible();
  await ana.context.close();
  await bogdan.context.close();
});

test("camera QR scanning uses the lazy fallback decoder, resolves the opaque reference and stops the stream", async ({ page }) => {
  await page.addInitScript(() => { Reflect.deleteProperty(window, "BarcodeDetector"); });
  const errors = trackErrors(page);
  const fallbackRequests: string[] = [];
  page.on("request", (request) => { if (/jsqrDecoder-/.test(request.url())) fallbackRequests.push(request.url()); });
  await login(page, fixture.users.ana);
  await page.goto("/scan");
  await page.waitForTimeout(300);
  expect(fallbackRequests).toHaveLength(0);
  await page.getByRole("button", { name: "Deschide camera" }).click();
  await expect(page.getByRole("heading", { name: `Comanda #${fixture.orders.qr}` })).toBeVisible({ timeout: 15_000 });
  expect(fallbackRequests).toHaveLength(1);
  const liveTracks = await page.evaluate(() => {
    const video = document.querySelector("video");
    return video?.srcObject instanceof MediaStream ? video.srcObject.getTracks().filter((track) => track.readyState === "live").length : 0;
  });
  expect(liveTracks).toBe(0);
  await expect(page.getByRole("button", { name: /Preia comanda/ })).toBeVisible();
  expect(errors).toEqual([]);
});

test("camera denial keeps manual lookup available; offline and expired sessions fail safely", async ({ page, context }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator.mediaDevices, "getUserMedia", { value: () => Promise.reject(new DOMException("denied", "NotAllowedError")) });
  });
  await login(page, fixture.users.ana);
  await page.goto("/scan");
  await page.getByRole("button", { name: "Deschide camera" }).click();
  await expect(page.getByRole("heading", { name: "Acces la cameră blocat" })).toBeVisible();
  await page.getByRole("button", { name: "Încearcă din nou" }).click();
  await expect(page.getByLabel("Număr / cod comandă")).toBeVisible();

  await context.setOffline(true);
  await page.getByLabel("Număr / cod comandă").fill(fixture.orders.qr);
  await page.getByRole("button", { name: "Caută comanda" }).click();
  await expect(page.getByRole("heading", { name: "Nu există conexiune" })).toBeVisible();
  await context.setOffline(false);

  await context.clearCookies();
  await page.goto("/orders");
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
});

test("factory phone widths: home, lookup, order detail, claim dialog and navigation fit 320–430 px without a mutation", async ({ page }) => {
  const errors = trackErrors(page);
  const mutations: string[] = [];
  page.on("request", (request) => { if (request.method() !== "GET" && /\/orders\//.test(request.url())) mutations.push(request.url()); });
  const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  const inside = async (name: RegExp | string, width: number) => {
    const box = await page.getByRole("button", { name }).first().boundingBox();
    expect(box, `${name} at ${width}px`).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(width);
    expect(box!.height).toBeGreaterThanOrEqual(36);
  };
  await page.setViewportSize({ width: 320, height: 640 });
  await login(page, fixture.users.ana);
  for (const width of [320, 360, 375, 390, 430]) {
    await page.setViewportSize({ width, height: 740 });
    await page.getByRole("button", { name: "Acasă", exact: true }).click();
    expect(await overflow(), `home at ${width}px`).toBeLessThanOrEqual(0);
    await lookup(page, fixture.orders.qr);
    await expect(page.getByRole("heading", { name: `Comanda #${fixture.orders.qr}` })).toBeVisible();
    expect(await overflow(), `lookup result at ${width}px`).toBeLessThanOrEqual(0);
    await inside(/Preia comanda/, width);
    await page.getByRole("button", { name: /Preia comanda/ }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toContainText("Preiei comanda?");
    expect(await overflow(), `claim dialog at ${width}px`).toBeLessThanOrEqual(0);
    await inside("Confirmă", width);
    await inside("Anulează", width);
    await dialog.getByRole("button", { name: "Anulează" }).click();
    await expect(dialog).toHaveCount(0);
    await page.getByRole("button", { name: "Vezi detalii" }).click();
    await expect(page.locator(".detail-hero")).toBeVisible();
    expect(await overflow(), `order detail at ${width}px`).toBeLessThanOrEqual(0);
    await inside(/Preia comanda/, width);
    for (const tab of ["Comenzi", "Istoric", "Profil"]) {
      await page.getByRole("button", { name: tab, exact: true }).click();
      expect(await overflow(), `${tab} at ${width}px`).toBeLessThanOrEqual(0);
    }
  }
  expect(mutations).toEqual([]);
  expect(errors).toEqual([]);
});

test("service worker never handles API mutations, deep links reload, logout ends the session, and tablet layout fits", async ({ page }) => {
  await login(page, fixture.users.ana);
  const controlled = await page.evaluate(async () => (await navigator.serviceWorker.ready).active !== null);
  expect(controlled).toBe(true);
  await page.reload();
  const responses: Array<{ url: string; fromServiceWorker: boolean }> = [];
  page.on("response", (response) => { if (response.url().startsWith(apiOrigin)) responses.push({ url: response.url(), fromServiceWorker: response.fromServiceWorker() }); });
  const detail = `/orders/${encodeURIComponent(`trendhome:${fixture.orders.flow}`)}`;
  await page.goto(detail);
  await expect(page.locator("[data-stage-id]")).toHaveCount(14);
  expect(responses.length).toBeGreaterThan(0);
  expect(responses.every((response) => !response.fromServiceWorker)).toBe(true);

  await page.setViewportSize({ width: 1024, height: 768 });
  await page.reload();
  await expect(page.locator("[data-stage-id]")).toHaveCount(14);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  await page.setViewportSize({ width: 360, height: 740 });
  const mobileOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(mobileOverflow).toBeLessThanOrEqual(0);

  await page.getByRole("button", { name: "Profil", exact: true }).click();
  await page.getByRole("button", { name: "Ieși din cont" }).click();
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  const afterLogout = await page.request.get(`${apiOrigin}/orders/mine`);
  expect(afterLogout.status()).toBe(401);
});

test("central IAM: a Dashboard-only identity is refused by Staff and a temporary password must be changed first", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(fixture.users.dashboardOnly);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: "Nu ai acces la Staff." })).toBeVisible();
  const denied = await page.request.get(`${apiOrigin}/orders/mine`);
  expect(denied.status()).toBe(403);
  expect((await denied.json()).error.code).toBe("APPLICATION_ACCESS_DENIED");
  await page.getByRole("button", { name: "Ieși din cont" }).click();
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();

  await page.getByLabel("Nume utilizator").fill(fixture.users.temporary);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: "Setează parola personală" })).toBeVisible();
  await expect(page.getByRole("note")).toContainText("Înainte de a accesa informațiile companiei");
  const blocked = await page.request.get(`${apiOrigin}/orders/mine`);
  expect((await blocked.json()).error.code).toBe("PASSWORD_CHANGE_REQUIRED");
  await page.getByLabel("Parola actuală").fill(fixture.password);
  await page.getByLabel("Parola nouă", { exact: true }).fill("o parolă nouă pentru e2e");
  await page.getByLabel("Confirmă parola nouă").fill("o parolă diferită pentru e2e");
  // Each field has its own Show/Hide control; showing one never reveals another.
  await page.getByRole("button", { name: "Afișează noua parolă" }).click();
  await expect(page.getByLabel("Parola nouă", { exact: true })).toHaveAttribute("type", "text");
  await expect(page.getByLabel("Parola actuală")).toHaveAttribute("type", "password");
  await expect(page.getByLabel("Confirmă parola nouă")).toHaveAttribute("type", "password");
  await expect(page.getByRole("button", { name: "Ascunde noua parolă" })).toHaveAttribute("aria-pressed", "true");
  await page.getByRole("button", { name: "Ascunde noua parolă" }).click();
  await expect(page.getByLabel("Parola nouă", { exact: true })).toHaveAttribute("type", "password");
  await page.getByRole("button", { name: "Salvează parola nouă" }).click();
  await expect(page.getByRole("alert")).toHaveText("Confirmarea nu coincide cu parola nouă.");
  await page.getByLabel("Parola nouă", { exact: true }).fill("scurtă");
  await page.getByRole("button", { name: "Salvează parola nouă" }).click();
  await expect(page.getByRole("alert")).toHaveText("Parola nouă trebuie să aibă cel puțin 12 caractere.");
  expect((await (await page.request.get(`${apiOrigin}/auth/session`)).json()).employee.mustChangePassword).toBe(true);
  await page.getByLabel("Parola nouă", { exact: true }).fill("o parolă nouă pentru e2e");
  await page.getByLabel("Confirmă parola nouă").fill("o parolă nouă pentru e2e");
  await page.getByRole("button", { name: "Salvează parola nouă" }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
  expect((await page.request.get(`${apiOrigin}/orders/mine`)).status()).toBe(200);

  // Voluntary change from the profile, with the same central policy; the new password works at the next login.
  await page.goto("/profile");
  await page.getByRole("button", { name: "Schimbă parola" }).click();
  await expect(page.getByRole("heading", { name: "Schimbă parola" })).toBeVisible();
  await expect(page.getByRole("note")).toHaveCount(0);
  await page.getByLabel("Parola actuală").fill("o parolă nouă pentru e2e");
  await page.getByLabel("Parola nouă", { exact: true }).fill("a doua parolă pentru e2e");
  await page.getByLabel("Confirmă parola nouă").fill("a doua parolă pentru e2e");
  await page.getByRole("button", { name: "Salvează parola nouă" }).click();
  await expect(page.getByText("Parola a fost schimbată. Celelalte sesiuni au fost închise.")).toBeVisible();
  await page.getByRole("button", { name: "Ieși din cont" }).click();
  await page.getByLabel("Nume utilizator").fill(fixture.users.temporary);
  await page.getByLabel("Parolă").fill("a doua parolă pentru e2e");
  await page.getByRole("button", { name: "Afișează parola" }).click();
  await expect(page.getByLabel("Parolă")).toHaveAttribute("type", "text");
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^Bună,/ })).toBeVisible();
});
