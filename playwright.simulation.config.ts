import { defineConfig } from "@playwright/test";
export default defineConfig({
  testDir: "./e2e/simulation", workers: 1, fullyParallel: false, timeout: 45000,
  expect: { timeout: 12000 }, reporter: [["list"], ["json", { outputFile: "work/simulation-browser.json" }]],
  outputDir: "work/simulation-browser-artifacts",
  use: { baseURL: process.env.ARASYA_SIMULATION_STAFF_URL ?? "http://127.0.0.1:4175", browserName: "chromium", viewport: { width: 390, height: 844 }, reducedMotion: "reduce", trace: "retain-on-failure" },
});
