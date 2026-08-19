import { expect, test } from "@playwright/test";

test("Preview mobile foundation survives navigation, malformed routes, PWA lifecycle and relogin", async ({ page, request }) => {
  const pageErrors: string[] = [];
  const consoleErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error") consoleErrors.push(message.text()); });

  const manifest = await request.get("/manifest.webmanifest");
  expect(manifest.status()).toBe(200);

  await page.goto("/orders");
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  await page.getByLabel("Nume utilizator").fill("demo");
  await page.getByLabel("Parolă").fill("demo");
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /Bună, Ali/ })).toBeVisible();

  const examples = [
    ["order-61833", "Trendhome"],
    ["order-61829", "OutletPerdele"],
    ["order-trendyol-1048", "Trendyol"],
  ] as const;
  for (const [orderId, source] of examples) {
    await page.goto(`/orders/${orderId}`);
    await expect(page.getByText(source, { exact: true })).toBeVisible();
    await expect(page.locator("[data-stage-id]")).toHaveCount(14);
  }

  await page.goto("/orders/order-61833");
  await page.goto("/orders/order-61829");
  await page.goBack();
  await expect(page.getByRole("heading", { name: /#61833/ })).toBeVisible();
  await page.goForward();
  await expect(page.getByRole("heading", { name: /#61829/ })).toBeVisible();

  await page.evaluate(() => {
    history.pushState({}, "", "/orders/%E0%A4%A");
    window.dispatchEvent(new PopStateEvent("popstate"));
  });
  await expect(page.getByRole("navigation", { name: "Navigare principală" })).toBeVisible();
  await expect(page.locator("#root")).not.toBeEmpty();

  const serviceWorkerActive = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    return Boolean(registration.active || registration.waiting || registration.installing);
  });
  expect(serviceWorkerActive).toBe(true);

  await page.getByRole("button", { name: "Profil", exact: true }).click();
  await page.getByRole("button", { name: "Ieși din cont" }).click();
  await expect(page.getByRole("heading", { name: "Bine ai revenit." })).toBeVisible();
  await page.getByLabel("Nume utilizator").fill("demo");
  await page.getByLabel("Parolă").fill("demo");
  await page.getByRole("button", { name: /Autentificare/ }).click();
  await expect(page.getByRole("heading", { name: /Bună, Ali/ })).toBeVisible();

  expect(pageErrors).toEqual([]);
  expect(consoleErrors).toEqual([]);
});
