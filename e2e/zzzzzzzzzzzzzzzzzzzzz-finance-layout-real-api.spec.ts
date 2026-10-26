import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type Locator, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

// Browser-layout regression of the Confirmare finance screen (Staff 2.36.1, live owner report 2026-10-26): the bank check
// button ("Am verificat extrasul") fell into the 20 px icon column and broke one word per line, the twelve task filters
// were squeezed into the card width, and switched-off finance read like a failed read. Measured with real layout against
// the real Operations API, phone to desktop widths. Nothing is recorded: no recording button is pressed.
type Fixture = { password: string; finance: string };
const root = path.resolve(import.meta.dirname, "..");
const SHOTS = path.join(root, "e2e", ".runtime", "finance-layout");
const WIDTHS = [320, 360, 390, 430, 768, 1024, 1280, 1440] as const;
let F: Fixture;
const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
  F = JSON.parse(execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-finance-layout-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })) as Fixture;
});

async function signIn(browser: Browser, width: number) {
  const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/login");
  await page.locator("input[autocomplete=username]").fill(F.finance);
  await page.locator("input[type=password]").fill(F.password);
  await page.locator("button[type=submit]").click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"));
  return { context, page, errors };
}

/** Line boxes of an element's own text (a word broken across lines shows as extra lines), its box and whether it is clipped. */
async function textLayout(element: Locator) {
  return element.evaluate((node) => {
    // The label's own text nodes only: a count badge beside it is a separate box, not a second line.
    const tops = new Set<number>();
    for (const child of node.childNodes) {
      if (child.nodeType !== Node.TEXT_NODE || (child.textContent ?? "").trim() === "") continue;
      const range = document.createRange();
      range.selectNodeContents(child);
      for (const rect of range.getClientRects()) if (rect.width > 0 && rect.height > 0) tops.add(Math.round(rect.top));
    }
    const box = node.getBoundingClientRect();
    return { lines: tops.size, width: box.width, height: box.height, clipped: (node as HTMLElement).scrollWidth > (node as HTMLElement).clientWidth + 1 };
  });
}

test("the bank check button and the task filter stay readable from 320 px to 1440 px; the page never scrolls sideways", async ({ browser }) => {
  for (const width of WIDTHS) {
    const { context, page, errors } = await signIn(browser, width);
    try {
      await page.goto("/confirmare/finance");
      await expect(page.getByTestId("cf-finance-queue")).toBeVisible();
      await expect(page.getByTestId("cf-bank")).toBeVisible();
      expect(await overflow(page), `${width} px: page overflow`).toBeLessThanOrEqual(0);

      for (const source of ["trendhome", "outletperdele"]) {
        const button = page.getByTestId(`cf-bank-record-${source}`);
        await expect(button).toBeVisible();
        const row = await page.getByTestId(`cf-bank-${source}`).boundingBox();
        const layout = await textLayout(button);
        // Old layout: about 20 px wide, one line per word. Now: never narrower than its text, at most two lines on a phone.
        expect(layout.width, `${width} px ${source}: button width`).toBeGreaterThan(120);
        expect(layout.clipped, `${width} px ${source}: button text clipped`).toBe(false);
        expect(layout.lines, `${width} px ${source}: button lines`).toBeLessThanOrEqual(width >= 720 ? 1 : 2);
        if (width < 720) expect(layout.width, `${width} px ${source}: full-width button on a phone`).toBeGreaterThan((row?.width ?? 0) * 0.9);
      }

      const strip = page.getByTestId("cf-finance-tasks");
      const tabs = strip.getByRole("tab");
      expect(await tabs.count(), `${width} px: task count`).toBeGreaterThan(8);
      for (const tab of await tabs.all()) {
        const layout = await textLayout(tab);
        expect(layout.lines, `${width} px: "${await tab.textContent()}" on one line`).toBe(1);
        expect(layout.clipped, `${width} px: "${await tab.textContent()}" not clipped`).toBe(false);
        expect(layout.height, `${width} px: tab target height`).toBeGreaterThanOrEqual(40);
      }
      // The strip scrolls inside itself (never the page), and a chosen task still filters and stays selected.
      const last = tabs.last();
      await last.click();
      await expect(last).toHaveAttribute("aria-selected", "true");
      await expect(last).toBeInViewport();
      expect(await overflow(page), `${width} px: page overflow after choosing the last task`).toBeLessThanOrEqual(0);
      await tabs.first().click();
      await expect(tabs.first()).toHaveAttribute("aria-selected", "true");

      if (width === 320 || width === 1280) await page.screenshot({ path: path.join(SHOTS, `finance-${width}.png`), fullPage: true });
      expect(errors, `${width} px: page errors`).toEqual([]);
    } finally {
      await context.close();
    }
  }
});

test("switched-off finance is named as such: Outlet Perdele (no source access) says not switched on, never a failed read", async ({ browser }) => {
  const { context, page, errors } = await signIn(browser, 390);
  try {
    await page.goto("/confirmare/finance");
    const section = page.getByTestId("cf-exceptions");
    await expect(section).toBeVisible();
    await section.getByTestId("cf-exc-source-outletperdele").click();
    const note = section.getByTestId("cf-exc-unavailable");
    await expect(note).toHaveAttribute("data-reason", "not_configured");
    await expect(note).toHaveText("Finanțele nu sunt încă pornite pentru acest magazin. Transferurile neidentificate apar aici după activare.");
    await expect(section.getByTestId("cf-exc-error")).toHaveCount(0);
    // Trend Home reads the TEST shop (finance record in the E2E API): its register answers, so no unavailable note.
    await section.getByTestId("cf-exc-source-trendhome").click();
    await expect(section.getByTestId("cf-exc-unavailable")).toHaveCount(0);
    const results = await new AxeBuilder({ page }).include("[data-testid=cf-finance-queue]").withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"]).analyze();
    expect(results.violations.map((violation) => `${violation.id} ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
    expect(errors).toEqual([]);
  } finally {
    await context.close();
  }
});
