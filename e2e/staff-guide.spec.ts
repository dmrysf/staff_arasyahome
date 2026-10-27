import { expect, test, type Page } from "@playwright/test";
import AxeBuilder from "@axe-core/playwright";

async function signIn(page: Page) {
  await page.goto("/login");
  await page.getByLabel("Nume utilizator").fill("demo");
  await page.getByLabel("Parolă").fill("demo");
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /Bună, Ali/ })).toBeVisible();
}

const noHorizontalScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

test("the contextual guide opens on every framed screen, switches Romanian/Turkish only inside the guide and is keyboard accessible", async ({ page }) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await signIn(page);

  const control = page.getByTestId("guide-open");
  await expect(control).toBeVisible();
  await expect(control).toHaveAccessibleName("Cum funcționează?");
  await expect(page.getByTestId("guide-card")).toBeVisible();

  await control.click();
  const dialog = page.getByRole("dialog", { name: /.+/ });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText("Cum funcționează această secțiune?")).toBeVisible();
  await expect(dialog.getByTestId("guide-lang-ro")).toHaveAttribute("aria-pressed", "true");
  expect(await noHorizontalScroll(page)).toBe(true);
  const axe = await new AxeBuilder({ page }).include(".guide-dialog").analyze();
  expect(axe.violations.map((violation) => violation.id)).toEqual([]);

  await dialog.getByTestId("guide-lang-tr").click();
  await expect(dialog.getByText("Bu bölüm nasıl çalışır?")).toBeVisible();
  await expect(dialog).toHaveAttribute("lang", "tr");
  // The Staff interface stays in the account language.
  await expect(page.locator("html")).toHaveAttribute("lang", "ro");

  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(control).toBeFocused();
  await expect(control).toHaveAccessibleName("Nasıl çalışır?");
  await expect(page.getByRole("navigation", { name: "Navigare principală" })).toBeVisible();

  // The choice is kept for this employee after a reload.
  await page.reload();
  await expect(page.getByTestId("guide-open")).toHaveAccessibleName("Nasıl çalışır?");

  // Another screen explains that screen.
  await page.goto("/history");
  await page.getByTestId("guide-open").click();
  await expect(page.getByRole("heading", { name: "Geçmiş", level: 2 })).toBeVisible();
  await page.getByTestId("guide-close").click();
  await expect(page.getByTestId("guide-dialog")).toBeHidden();

  // The home card closes and comes back.
  await page.goto("/");
  await page.getByTestId("guide-card-hide").click();
  await expect(page.getByTestId("guide-card")).toBeHidden();
  await page.getByTestId("guide-card-show").click();
  await expect(page.getByTestId("guide-card")).toBeVisible();

  expect(errors).toEqual([]);
});

test("the guide fits phones from 320 px and opens as a side panel on desktop", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page);
  expect(await noHorizontalScroll(page)).toBe(true);
  await page.getByTestId("guide-open").click();
  const panel = page.locator(".guide-panel");
  await expect(panel).toBeVisible();
  const phone = await panel.boundingBox();
  expect(phone && phone.width <= 320 && phone.y > 0).toBeTruthy();
  expect(await noHorizontalScroll(page)).toBe(true);
  await page.keyboard.press("Escape");

  await page.setViewportSize({ width: 1366, height: 900 });
  await page.getByTestId("guide-open").click();
  const desktop = await panel.boundingBox();
  expect(desktop && desktop.x > 800 && desktop.width <= 460 && desktop.height >= 890).toBeTruthy();
});
