import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Page } from "@playwright/test";
import { mkdirSync, readFileSync } from "node:fs";
import path from "node:path";

// Staff languages against the real Operations API (migration 027): the account language decides the interface on
// any device, the sign-in screen keeps a device-only choice, signing out never carries one employee's language to
// the next person, order data is never translated, and requests carry the same codes in every language.
type Fixture = { password: string; users: { ana: string }; locales: { users: Record<"tr" | "si" | "en" | "tr-temp", string>; order: string; product: string; color: string } };
const fixture = JSON.parse(readFileSync(path.join(import.meta.dirname, ".real-api-fixture.json"), "utf8")) as Fixture;
const shots = process.env.STAFF_LOCALE_SCREENSHOTS ?? "";
if (shots) mkdirSync(shots, { recursive: true });

async function open(browser: Browser, width: number, height = 800) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  return { context, page, errors };
}

async function signIn(page: Page, username: string, labels = { username: "Nume utilizator", password: "Parolă", submit: /Autentificare/ }) {
  await page.getByLabel(labels.username).fill(username);
  await page.getByLabel(labels.password, { exact: true }).fill(fixture.password);
  await page.getByRole("button", { name: labels.submit }).click();
}

/** The page never scrolls sideways on a phone: long Turkish and Sinhala words must wrap. */
async function fits(page: Page, screen: string) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, `${screen}: horizontal overflow`).toBeLessThanOrEqual(0);
  if (shots) await page.screenshot({ path: path.join(shots, `${screen}.png`), fullPage: true });
}

/** WCAG 2.2 AA in the account language too (valid lang, names, contrast). */
async function accessible(page: Page, screen: string) {
  const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
  expect(results.violations.map((violation) => `${screen}: ${violation.id}`)).toEqual([]);
}

async function signOut(page: Page, label: string) {
  await page.goto("/profile");
  await page.getByRole("button", { name: label }).click();
}

test.describe.configure({ mode: "serial" });

test("Turkish account: Turkish everywhere on any device, order data unchanged, codes unchanged", async ({ browser }) => {
  test.setTimeout(120_000);
  const phone = await open(browser, 360);
  const { page } = phone;
  // Nobody is known yet: the device has made no choice, so the sign-in screen is Romanian.
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("ro");
  await fits(page, "login-ro-360");
  await signIn(page, fixture.locales.users.tr);

  // The account language applies at once after sign-in.
  await expect(page.getByRole("heading", { name: /^Merhaba, Haydar!$/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("tr");
  await expect(page.getByRole("navigation", { name: "Ana menü" }).getByText("Siparişler")).toBeVisible();
  await expect(page.getByText("Panelim").or(page.getByText("Çalışma paneli")).first()).toBeVisible();
  await fits(page, "home-tr-360");

  // Order detail: Turkish labels, the stored Romanian product data exactly as it is.
  const requests: { url: string; body: string }[] = [];
  page.on("request", (request) => { if (request.method() !== "GET" && request.url().includes("/orders/")) requests.push({ url: request.url(), body: request.postData() ?? "" }); });
  await page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.locales.order}`)}`);
  await expect(page.getByRole("heading", { name: new RegExp(`Sipariş #${fixture.locales.order}`) })).toBeVisible();
  await expect(page.getByText(fixture.locales.product, { exact: true })).toBeVisible();
  await expect(page.getByText("Renk")).toBeVisible();
  await expect(page.getByText(fixture.locales.color, { exact: true })).toBeVisible();
  await expect(page.locator(".detail-hero .stage-label")).toContainText("Etiketleme");
  await fits(page, "order-tr-360");
  await accessible(page, "order-tr");

  // Taking the order: Turkish words, the same request as in Romanian (codes only, no translated text).
  await page.getByRole("button", { name: /Siparişi üstlen/ }).click();
  await expect(page.getByRole("dialog")).toContainText("Siparişi üstleniyor musun?");
  await fits(page, "confirm-tr-360");
  await page.getByRole("dialog").getByRole("button", { name: "Onayla" }).click();
  await expect(page.getByText("Sipariş artık sende")).toBeVisible();
  const claim = requests.find((request) => request.url.endsWith("/claim"));
  expect(claim, "the claim request was sent").toBeTruthy();
  const body = JSON.parse(claim!.body) as Record<string, unknown>;
  expect(Object.keys(body)).toEqual(["expectedVersion"]);
  expect(claim!.body).toMatch(/^[\x20-\x7E]*$/);

  // Scanner, history and profile.
  await page.goto("/scan");
  await expect(page.getByText("QR tarama")).toBeVisible();
  await expect(page.getByRole("button", { name: "Kamerayı aç" })).toBeVisible();
  await fits(page, "scan-tr-360");
  await page.goto("/history");
  await expect(page.getByRole("heading", { name: "Geçmiş" })).toBeVisible();
  await expect(page.getByText(/Etiketleme aşamasında üstlenildi/)).toBeVisible();
  await fits(page, "history-tr-360");
  await page.goto("/profile");
  await expect(page.getByTestId("profile-language")).toContainText("Türkçe");
  await fits(page, "profile-tr-360");

  // A reload restores the session in the same language.
  await page.reload();
  await expect(page.getByTestId("profile-language")).toContainText("Türkçe");
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("tr");

  // Signing out returns to the device choice (Romanian here): the account language does not stay on the phone.
  await signOut(page, "Çıkış yap");
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("ro");

  // The next person on the same phone works in their own language.
  await signIn(page, fixture.users.ana);
  await expect(page.getByRole("heading", { name: /^Bună, Ana!$/ })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("ro");
  expect(await page.evaluate(() => Object.keys(localStorage).filter((key) => /token|session|csrf|password/i.test(key)))).toEqual([]);
  expect(phone.errors).toEqual([]);
  await phone.context.close();

  // Another device (390 px), English sign-in screen chosen there: the Turkish account still opens in Turkish.
  const other = await open(browser, 390, 844);
  await other.page.getByRole("button", { name: "English" }).click();
  await expect(other.page.getByRole("heading", { name: "Welcome back." })).toBeVisible();
  await fits(other.page, "login-en-390");
  await signIn(other.page, fixture.locales.users.tr, { username: "Username", password: "Password", submit: /Sign in/ });
  await expect(other.page.getByRole("heading", { name: /^Merhaba, Haydar!$/ })).toBeVisible();
  await fits(other.page, "home-tr-390");
  await signOut(other.page, "Çıkış yap");
  // The device keeps its own sign-in language.
  await expect(other.page.getByRole("heading", { name: "Welcome back." })).toBeVisible();
  expect(await other.page.evaluate(() => [localStorage.getItem("arasya.staff.locale"), document.documentElement.lang])).toEqual(["en", "en"]);
  expect(other.errors).toEqual([]);
  await other.context.close();
});

test("Sinhala account: real Sinhala script, left to right, fits 360 and 390 px phones", async ({ browser }) => {
  for (const width of [360, 390]) {
    const phone = await open(browser, width, 844);
    await signIn(phone.page, fixture.locales.users.si);
    await expect(phone.page.getByRole("heading", { name: /^ආයුබෝවන්, Priyantha!$/ })).toBeVisible();
    expect(await phone.page.evaluate(() => [document.documentElement.lang, document.documentElement.dir, getComputedStyle(document.body).direction])).toEqual(["si", "ltr", "ltr"]);
    await expect(phone.page.getByRole("navigation", { name: "ප්‍රධාන මෙනුව" }).getByText("ඇණවුම්")).toBeVisible();
    // Unicode survives the round trip: the rendered text is the catalog text, NFC, with Sinhala code points.
    const greeting = await phone.page.getByRole("heading", { level: 1 }).first().textContent();
    expect(greeting).toBe(greeting!.normalize("NFC"));
    expect(greeting).toMatch(/[඀-෿]/);
    await fits(phone.page, `home-si-${width}`);
    if (width === 390) await accessible(phone.page, "home-si");
    await phone.page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.locales.order}`)}`);
    await expect(phone.page.getByText(fixture.locales.product, { exact: true })).toBeVisible();
    await expect(phone.page.getByText("වර්ණය")).toBeVisible();
    await fits(phone.page, `order-si-${width}`);
    await phone.page.goto("/scan");
    await expect(phone.page.getByRole("button", { name: "කැමරාව විවෘත කරන්න" })).toBeVisible();
    await fits(phone.page, `scan-si-${width}`);
    await phone.page.goto("/profile");
    await expect(phone.page.getByTestId("profile-language")).toContainText("සිංහල");
    await fits(phone.page, `profile-si-${width}`);
    await signOut(phone.page, "පිටවන්න");
    await expect(phone.page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
    expect(phone.errors).toEqual([]);
    await phone.context.close();
  }
});

test("English account on a desktop, and the forced password change in the account language", async ({ browser }) => {
  const desktop = await open(browser, 1280, 860);
  await signIn(desktop.page, fixture.locales.users.en);
  await expect(desktop.page.getByRole("heading", { name: /^Hello, Haleem!$/ })).toBeVisible();
  await expect(desktop.page.getByRole("navigation", { name: "Main navigation" }).getByText("History")).toBeVisible();
  await fits(desktop.page, "home-en-1280");
  await desktop.page.goto(`/orders/${encodeURIComponent(`trendhome:${fixture.locales.order}`)}`);
  await expect(desktop.page.getByText(fixture.locales.product, { exact: true })).toBeVisible();
  await expect(desktop.page.getByText("Colour")).toBeVisible();
  await fits(desktop.page, "order-en-1280");
  await signOut(desktop.page, "Sign out");
  await desktop.context.close();

  // A temporary password is replaced first, in the account language; nothing else is reachable before that.
  const phone = await open(browser, 360);
  await signIn(phone.page, fixture.locales.users["tr-temp"]);
  await expect(phone.page.getByRole("heading", { name: "Kişisel şifreni belirle" })).toBeVisible();
  await expect(phone.page.getByText("Zorunlu adım")).toBeVisible();
  await expect(phone.page.getByLabel("Mevcut şifre (geçici)")).toBeVisible();
  await expect(phone.page.getByRole("button", { name: "mevcut şifreyi göster" })).toBeVisible();
  await fits(phone.page, "password-tr-360");
  await accessible(phone.page, "password-tr");
  await phone.page.goto("/orders");
  await expect(phone.page.getByRole("heading", { name: "Kişisel şifreni belirle" })).toBeVisible();
  expect(phone.errors).toEqual([]);
  await phone.context.close();
});
