import { defineConfig, devices } from "@playwright/test";
import path from "node:path";

const apiPort = 8787;
const webPort = 4174;
const root = import.meta.dirname;

/**
 * Real Operations API + MySQL + Chromium. Never points at production: the PHP built-in
 * server uses a disposable `*e2e*test*` database prepared by e2e/prepare-real-api.mjs.
 */
export default defineConfig({
  testDir: "./e2e",
  testMatch: /real-api\.spec\.ts/,
  timeout: 60_000,
  expect: { timeout: 8_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: process.env.CI ? "line" : "list",
  use: {
    baseURL: `http://127.0.0.1:${webPort}`,
    trace: "retain-on-failure",
    ...devices["Desktop Chrome"],
    viewport: { width: 375, height: 667 },
    launchOptions: {
      args: [
        "--use-fake-ui-for-media-stream",
        "--use-fake-device-for-media-stream",
        `--use-file-for-fake-video-capture=${path.join(root, "e2e", ".runtime", "real-api-qr.y4m")}`,
      ],
    },
  },
  webServer: [
    {
      command: `php -S 127.0.0.1:${apiPort} operations-api/public/index.php`,
      url: `http://127.0.0.1:${apiPort}/health`,
      reuseExistingServer: false,
      timeout: 30_000,
      env: {
        HOME: path.join(root, "e2e", ".runtime", "home"),
        PHP_CLI_SERVER_WORKERS: "4",
        ARASYA_APP_ENV: "test",
        ARASYA_APP_SECRET: "t".repeat(32),
        ARASYA_DB_HOST: process.env.ARASYA_TEST_DB_HOST ?? "127.0.0.1",
        ARASYA_DB_PORT: process.env.ARASYA_TEST_DB_PORT ?? "3306",
        ARASYA_DB_NAME: process.env.ARASYA_E2E_DB_NAME ?? "",
        ARASYA_DB_USER: process.env.ARASYA_TEST_DB_USER ?? "",
        ARASYA_DB_PASSWORD: process.env.ARASYA_TEST_DB_PASSWORD ?? "",
        ARASYA_ALLOWED_ORIGINS: `http://127.0.0.1:${webPort}`,
        ARASYA_LOGIN_USERNAME_LIMIT: "50",
        ARASYA_LOGIN_IP_LIMIT: "500",
      },
    },
    {
      command: `pnpm exec vite preview --mode e2e --host 127.0.0.1 --port ${webPort} --strictPort`,
      url: `http://127.0.0.1:${webPort}/login`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
  ],
});
