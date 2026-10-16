import { test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { Metric } from "../features/workspaces/DashboardParts";

const root = path.resolve(import.meta.dirname, "..");
const css = (file: string) => readFileSync(path.join(root, "styles", file), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");

test("motion only moves transform and opacity, never layout, in every stylesheet", () => {
  for (const file of ["foundation.css", "experiences.css", "app.css", "motion.css"]) {
    const keyframes = css(file).match(/@keyframes[^{]+\{(?:[^{}]*\{[^}]*\})*[^}]*\}/g) ?? [];
    for (const block of keyframes) {
      const properties = [...block.matchAll(/([a-z-]+)\s*:/g)].map((match) => match[1]);
      for (const property of properties) assert.ok(["transform", "opacity"].includes(property), `${file}: ${block.slice(0, 40)} animates ${property}`);
    }
  }
});

test("reduced motion removes durations, iterations and delays", () => {
  const globals = readFileSync(path.join(root, "app/globals.css"), "utf8");
  assert.match(globals, /prefers-reduced-motion: reduce[\s\S]*animation-duration: \.01ms !important[\s\S]*animation-iteration-count: 1 !important/);
  assert.match(css("motion.css"), /prefers-reduced-motion: reduce[\s\S]*animation-delay: 0ms !important/);
  assert.doesNotMatch(css("motion.css"), /#[0-9a-f]{3,8}\b|rgba?\(/i, "motion.css has no colours of its own");
});

test("a metric animates only a real number: loading and unavailable values render no animated span", () => {
  const render = (value: number | "loading" | "unavailable") => renderToStaticMarkup(createElement(Metric, { label: "Comenzi", value, testId: "m" }));
  assert.match(render(7), /<dd><span class="metric-number">7<\/span><\/dd>/);
  assert.match(render("loading"), /<dd class="metric-unavailable">…<\/dd>/);
  assert.doesNotMatch(render("unavailable"), /metric-number/);
});

test("haptics follow only the scanner's own states, and every async primary button reports busy", () => {
  const scanner = readFileSync(path.join(root, "features/scanner/ScannerScreen.tsx"), "utf8");
  assert.match(scanner, /state\.status === "decoded"\) navigator\.vibrate\?\.\(/);
  assert.match(scanner, /state\.status === "success"\) navigator\.vibrate\?\.\(/);
  for (const file of ["features/auth/LoginScreen.tsx", "features/auth/ChangePasswordScreen.tsx", "features/orders/OrderActionPanel.tsx", "features/scanner/ScannerScreen.tsx", "features/exceptions/ReturnToCuttingPanel.tsx", "features/exceptions/ExceptionActions.tsx"]) {
    assert.match(readFileSync(path.join(root, file), "utf8"), /aria-busy=/, file);
  }
});

test("a notice uses one layout model: the dot-style order notice is never combined with the icon-grid notice", () => {
  // `.notice` is a 20px icon column + text grid that needs an in-flow icon; `.order-notice` draws its dot with an
  // absolute ::before. Combined, the sentence becomes the grid item of the 20px column and wraps letter by letter
  // (Staff manufacturing-plan warning on Android, 2026-10-16).
  const files = ["app", "components", "features"].flatMap((dir) => readdirSync(path.join(root, dir), { recursive: true, encoding: "utf8" }).filter((file) => file.endsWith(".tsx")).map((file) => path.join(dir, file)));
  for (const file of files) {
    for (const match of readFileSync(path.join(root, file), "utf8").matchAll(/className=(?:"([^"]*)"|\{`([^`]*)`\})/g)) {
      const classes = (match[1] ?? match[2] ?? "").split(/\s+/);
      assert.ok(!(classes.includes("order-notice") && classes.includes("notice")), `${file}: "${match[0]}" mixes order-notice with notice`);
      assert.ok(!classes.includes("notice-warning"), `${file}: notice-warning is not a style`);
      if (classes.includes("notice")) assert.match(match.input!.slice(match.index!, match.index! + 400), /<AppIcon /, `${file}: a .notice needs its in-flow icon`);
    }
  }
});
