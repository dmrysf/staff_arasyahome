import { expect, request, test, type Browser, type Locator, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync } from "node:fs";
import path from "node:path";

// Browser-layout regression of the manufacturing-plan warnings on phones (owner photographs, Android, order with an
// unconfirmed physical piece count). A warning combined `.order-notice` (absolute dot) with `.notice` (a 20px icon
// grid column); with no in-flow icon the sentence became the grid item of the 20px column and wrapped one or two
// letters per line. Measured here with real layout: the text must use the card width and wrap word by word.
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8"));
const API = "http://127.0.0.1:8787";
const WEB = "http://127.0.0.1:4174";
const shots = path.join(import.meta.dirname, ".runtime", "notice-layout");
mkdirSync(shots, { recursive: true });
const WARNING_RO = "Număr de bucăți neconfirmat. Ambalarea necesită verificarea tuturor bucăților.";
const WIDTHS = [320, 344, 360, 375, 390, 412, 430];

test.describe.configure({ mode: "serial" });

async function api(username: string, password = fixture.password) {
  const context = await request.newContext({ baseURL: API, extraHTTPHeaders: { Origin: WEB } });
  const login = await context.post("/auth/login", { data: { username, password } });
  expect(login.status()).toBe(200);
  const csrf = (await login.json()).csrfToken as string;
  return { context, csrf };
}
async function signIn(browser: Browser, username: string, width = 360) {
  const context = await browser.newContext({ viewport: { width, height: 780 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, reducedMotion: "reduce", serviceWorkers: "block" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill(username);
  await page.getByLabel("Parolă").fill(fixture.password);
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /^(Bună|Merhaba),/ })).toBeVisible();
  return { context, page, errors };
}
const go = (page: Page, to: string) => page.evaluate((target) => { history.pushState({}, "", target); dispatchEvent(new PopStateEvent("popstate")); }, to);

/** Real layout of one notice: its box against the card, and the line boxes of its first text block. */
async function measure(notice: Locator) {
  return notice.evaluate((element) => {
    // The card that holds the notice: the plan section, or the piece card of a plan line.
    const card = element.parentElement as HTMLElement;
    const textHost = (element.querySelector("p") ?? element) as HTMLElement;
    const range = document.createRange();
    range.selectNodeContents(textHost);
    const rects = [...range.getClientRects()].filter((rect) => rect.width > 0 && rect.height > 0);
    const lines = new Map<number, { left: number; right: number }>();
    for (const rect of rects) {
      const key = Math.round(rect.top);
      const line = lines.get(key);
      lines.set(key, line ? { left: Math.min(line.left, rect.left), right: Math.max(line.right, rect.right) } : { left: rect.left, right: rect.right });
    }
    const widths = [...lines.values()].map((line) => line.right - line.left);
    const box = element.getBoundingClientRect();
    const cardBox = card.getBoundingClientRect();
    const style = getComputedStyle(element);
    const nav = document.querySelector("nav[aria-label]");
    return {
      lineCount: widths.length,
      narrowestFullLine: widths.length > 1 ? Math.min(...widths.slice(0, -1)) : widths[0] ?? 0,
      widestLine: Math.max(0, ...widths),
      textWidth: textHost.getBoundingClientRect().width,
      noticeWidth: box.width,
      cardContentWidth: cardBox.width - parseFloat(getComputedStyle(card).paddingLeft) - parseFloat(getComputedStyle(card).paddingRight),
      display: style.display,
      clipped: element.scrollHeight > element.clientHeight + 1 || element.scrollWidth > element.clientWidth + 1,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      navTop: nav ? nav.getBoundingClientRect().top : Number.POSITIVE_INFINITY,
      bottom: box.bottom,
    };
  });
}
async function assertReadable(page: Page, notice: Locator, label: string) {
  // Bring the notice to the upper part of the screen, as an employee scrolling to it would, then measure.
  await notice.evaluate(async (element) => {
    window.scrollTo({ top: window.scrollY + element.getBoundingClientRect().top - window.innerHeight * 0.2, behavior: "instant" });
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
  const m = await measure(notice);
  expect(m.overflow, `${label}: horizontal page overflow`).toBeLessThanOrEqual(0);
  expect(m.noticeWidth / m.cardContentWidth, `${label}: the notice spans the card (${JSON.stringify(m)})`).toBeGreaterThan(0.9);
  expect(m.widestLine / m.cardContentWidth, `${label}: the text runs across the card, not inside a 20px icon column (${JSON.stringify(m)})`).toBeGreaterThan(0.6);
  // Ordinary word wrapping: every full line holds words (the defect produced 3-20px lines of one or two letters).
  expect(m.narrowestFullLine, `${label}: lines hold words, not one or two letters (${JSON.stringify(m)})`).toBeGreaterThan(Math.max(60, 0.4 * m.textWidth));
  expect(m.lineCount, `${label}: a normal wrapped sentence (${JSON.stringify(m)})`).toBeLessThanOrEqual(6);
  expect(m.clipped, `${label}: nothing clipped`).toBe(false);
  expect(m.bottom, `${label}: not under the bottom navigation once scrolled to (${JSON.stringify(m)})`).toBeLessThanOrEqual(m.navTop);
  return m;
}

let created: { order: string; number: string; qr: string };
let assignment = "";

test.beforeAll(async () => {
  created = JSON.parse(execFileSync("php", [path.join(import.meta.dirname, "manifest-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] }));
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const found = await (await root.context.get(`/management/employees?search=${encodeURIComponent(fixture.supervision.chief)}`)).json();
  const chiefId = found.items.find((item: { username: string }) => item.username === fixture.supervision.chief).id;
  const appointed = await root.context.post("/management/organization/responsibilities", { headers: { "X-CSRF-Token": root.csrf, "Idempotency-Key": `notice-layout-${crypto.randomUUID()}` }, data: { responsibility: "tailoring_quality_supervisor", employeeId: chiefId } });
  expect(appointed.status(), await appointed.text()).toBe(201);
  assignment = (await appointed.json()).responsibilities.find((r: { state: string; responsibility: string }) => r.state === "active" && r.responsibility === "tailoring_quality_supervisor").id;
  await root.context.dispose();
});

test.afterAll(async () => {
  if (!assignment) return;
  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  await root.context.post(`/management/organization/responsibilities/${assignment}/revoke`, { headers: { "X-CSRF-Token": root.csrf, "Idempotency-Key": `notice-layout-${crypto.randomUUID()}` }, data: { reason: "Sfârșit test E2E" } });
  await root.context.dispose();
});

test("B1/B4/B6: unconfirmed piece count warning wraps across the card at every phone width; plan button and Scan stay usable", async ({ browser }) => {
  const chief = await signIn(browser, fixture.supervision.chief, 360);
  await go(chief.page, `/orders/${encodeURIComponent(created.order)}`);
  const panel = chief.page.getByTestId("manufacturing-plan");
  const warning = panel.getByRole("note").filter({ hasText: WARNING_RO });
  await expect(warning).toHaveText(WARNING_RO);
  // Evidence at the photographed width (NOTICE_SHOT=before on the unfixed code).
  await warning.scrollIntoViewIfNeeded();
  await chief.page.screenshot({ path: path.join(shots, `warning-360-${process.env.NOTICE_SHOT ?? "after"}.png`) });
  for (const width of WIDTHS) {
    await chief.page.setViewportSize({ width, height: 780 });
    const m = await assertReadable(chief.page, warning, `${width}px`);
    if (width === 360) expect(m.lineCount).toBeLessThanOrEqual(3);
  }
  // The plan refresh still works and the server state is unchanged (the warning stays: nothing was confirmed).
  await chief.page.setViewportSize({ width: 360, height: 780 });
  await panel.getByRole("button", { name: "Reîncarcă starea salvată" }).click();
  await expect(warning).toHaveText(WARNING_RO);
  const scanButton = chief.page.getByRole("button", { name: "Scanează codul QR" });
  await expect(scanButton).toBeVisible();
  await expect(scanButton).toBeInViewport();
  await scanButton.click();
  await expect(chief.page).toHaveURL(/\/scan$/);
  expect(chief.errors).toEqual([]);
  await chief.context.close();
});

test("B2: the missing route evidence notice uses the full card width", async ({ browser }) => {
  const chief = await signIn(browser, fixture.supervision.chief, 320);
  // Layout fixture: the server's own plan answer with one piece whose route evidence is missing on several stages.
  await chief.page.route("**/manufacturing-plan", async (route) => {
    if (route.request().method() !== "GET") { await route.continue(); return; }
    const response = await route.fetch();
    const plan = await response.json();
    plan.lines[0].pieces = [{ id: "00000000-0000-4000-8000-000000000001", index: 1, count: 2, trackId: null,
      routeEvidence: { reworkCycle: 1, ledgerStartStageId: null, preLedgerStages: [], reconciledStages: [], missingStages: ["workshop-receiving", "labeling", "material-straightening", "bottom-hem"], uncertainStages: ["side-hem"], candidates: [] } }];
    await route.fulfill({ response, json: plan });
  });
  await go(chief.page, `/orders/${encodeURIComponent(created.order)}`);
  const notice = chief.page.getByTestId("route-evidence-missing");
  await expect(notice).toBeVisible();
  for (const width of [320, 360, 390]) {
    await chief.page.setViewportSize({ width, height: 780 });
    await assertReadable(chief.page, notice, `route evidence ${width}px`);
  }
  await chief.page.setViewportSize({ width: 320, height: 780 });
  await notice.scrollIntoViewIfNeeded();
  await chief.page.screenshot({ path: path.join(shots, "route-evidence-320.png") });
  await chief.context.close();
});

test("B3/B5 + Turkish: another role, a Turkish account and larger text keep the warning horizontal", async ({ browser }) => {
  // Another role: the cutting employee of the order's stage sees the same panel.
  const cutter = await signIn(browser, fixture.supervision.cutter, 344);
  await go(cutter.page, `/orders/${encodeURIComponent(created.order)}`);
  const cutterWarning = cutter.page.getByTestId("manufacturing-plan").getByRole("note").filter({ hasText: WARNING_RO });
  await assertReadable(cutter.page, cutterWarning, "cutter 344px");
  // Larger text (Android font scale): the root size grows and the sentence must still wrap by words.
  await cutter.page.addStyleTag({ content: "html { font-size: 130% !important; } body { font-size: 1.3rem !important; }" });
  await assertReadable(cutter.page, cutterWarning, "cutter 344px, larger text");
  await cutter.page.screenshot({ path: path.join(shots, "warning-344-large-text.png") });
  await cutter.context.close();

  const root = await api(fixture.cutting.root.username, fixture.cutting.root.password);
  const found = await (await root.context.get(`/management/employees?search=${encodeURIComponent(fixture.supervision.chief)}`)).json();
  const chiefId = found.items.find((item: { username: string }) => item.username === fixture.supervision.chief).id;
  const locale = (value: string | null) => root.context.put(`/management/employees/${chiefId}/staff-locale`, { headers: { "X-CSRF-Token": root.csrf }, data: { locale: value } });
  expect((await locale("tr")).status()).toBe(200);
  try {
    const chief = await signIn(browser, fixture.supervision.chief, 360);
    await go(chief.page, `/orders/${encodeURIComponent(created.order)}`);
    const panel = chief.page.getByTestId("manufacturing-plan");
    const warning = panel.getByRole("note").first();
    await expect(warning).not.toHaveText(WARNING_RO);
    for (const width of [320, 360, 390]) {
      await chief.page.setViewportSize({ width, height: 780 });
      await assertReadable(chief.page, warning, `Turkish ${width}px`);
    }
    await chief.page.screenshot({ path: path.join(shots, "warning-tr-360.png") });
    await chief.context.close();
  } finally {
    expect((await locale(null)).status()).toBe(200);
    await root.context.dispose();
  }
});

test("B7: the warning disappears only when the server says every plan is known", async ({ browser }) => {
  const chief = await api(fixture.supervision.chief);
  const pathOrder = `/orders/${encodeURIComponent(created.order)}`;
  const before = await (await chief.context.get(`${pathOrder}/manufacturing-plan`)).json();
  expect(before.allPlansKnown).toBe(false);
  const page = await signIn(browser, fixture.supervision.chief, 360);
  await go(page.page, pathOrder);
  const panel = page.page.getByTestId("manufacturing-plan");
  await expect(panel.getByRole("note").filter({ hasText: WARNING_RO })).toBeVisible();
  for (const [index, line] of before.lines.entries()) {
    const body = { expectedRevision: line.plan.revision, expectedSourceHash: line.sourcePlanHash, productType: index === 0 ? "perdea" : "draperii", pieceCount: 1, note: "Verificat fizic pentru testul de afișare." };
    const confirmed = await chief.context.post(`${pathOrder}/manufacturing-plan/lines/${line.itemId}/confirm`, { headers: { "X-CSRF-Token": chief.csrf, "Idempotency-Key": `notice-layout-${crypto.randomUUID()}` }, data: body });
    expect(confirmed.status(), await confirmed.text()).toBeLessThan(300);
  }
  const after = await (await chief.context.get(`${pathOrder}/manufacturing-plan`)).json();
  expect(after.allPlansKnown).toBe(true);
  await panel.getByRole("button", { name: "Reîncarcă starea salvată" }).click();
  await expect(panel.getByRole("note").filter({ hasText: WARNING_RO })).toHaveCount(0);
  await page.context.close();
  await chief.context.dispose();
});
