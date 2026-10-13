import { test } from "node:test";
import { ro } from "../i18n/messages/ro";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "..");
const tokensCss = readFileSync(path.join(root, "styles/tokens.css"), "utf8");
const defaultBlock = tokensCss.slice(tokensCss.indexOf(":root {"), tokensCss.indexOf(':root[data-theme="dark"]'));
const token = (name: string): string => {
  const match = defaultBlock.match(new RegExp(`--${name}:\\s*([^;]+);`));
  assert.ok(match, `token --${name}`);
  return match[1].trim();
};

type Rgb = [number, number, number];
function parse(value: string, over: Rgb = [255, 255, 255]): Rgb {
  const hex = value.match(/^#([0-9a-f]{6})$/i);
  if (hex) return [0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16)) as Rgb;
  const rgba = value.match(/^rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\s*\)$/);
  assert.ok(rgba, `colour ${value}`);
  const alpha = rgba[4] === undefined ? 1 : Number(rgba[4]);
  return [1, 2, 3].map((i, k) => Math.round(Number(rgba[i]) * alpha + over[k] * (1 - alpha))) as Rgb;
}
const luminance = ([r, g, b]: Rgb) => [r, g, b].map((c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
const contrast = (fg: Rgb, bg: Rgb) => { const [a, b] = [luminance(fg), luminance(bg)].sort((x, y) => y - x); return (a + 0.05) / (b + 0.05); };

test("text tokens meet WCAG AA (4.5:1) on every surface of the default white theme", () => {
  const page = parse(token("color-bg"));
  const surfaces = ["color-bg", "color-surface", "color-surface-soft", "color-surface-raised", "color-warning-soft", "color-danger-soft", "color-success-soft", "color-info-soft", "color-accent-soft"].map((name) => [name, parse(token(name), page)] as const);
  for (const text of ["color-text", "color-text-muted", "color-text-subtle", "color-warning-text", "color-danger-text", "color-success-text"]) {
    for (const [surface, background] of surfaces) {
      const ratio = contrast(parse(token(text), background), background);
      assert.ok(ratio >= 4.5, `${text} on ${surface}: ${ratio.toFixed(2)}`);
    }
  }
});

test("buttons and signals: labels on filled buttons pass AA, status dots and focus are visible (3:1)", () => {
  const page = parse(token("color-bg"));
  assert.ok(contrast(parse(token("color-accent-contrast")), parse(token("color-accent"))) >= 4.5, "primary button label");
  for (const signal of ["color-success", "color-warning", "color-danger"]) {
    assert.ok(contrast(parse(token(signal)), page) >= 3, `${signal} dot/icon on white`);
  }
  assert.ok(contrast(parse(token("color-focus"), page), page) >= 3, "focus outline");
  assert.ok(contrast(parse(token("color-border-strong"), page), page) < 3, "hairlines stay quiet (decorative)");
});

test("the shell offers a skip link to the work and the content can receive it", () => {
  const shell = readFileSync(path.join(root, "components/AppShell.tsx"), "utf8");
  assert.match(shell, /className="skip-link" href="#content">\{t\("shell\.skip"\)\}</);
  assert.equal(ro["shell.skip"], "Sari la conținut");
  assert.match(shell, /id="content" tabIndex=\{-1\}/);
});

test("package validation is shown next to the field and moves focus to it", () => {
  const screen = readFileSync(path.join(root, "features/trendyol/TrendyolPackageScreen.tsx"), "utf8");
  for (const field of ["kind", "widthCm\" \\|\\| error\\?\\.field === \"heightCm", "meters"]) assert.match(screen, new RegExp(`error\\?\\.field === "${field}"`));
  assert.match(screen, /document\.getElementById\(fieldId\(line\.lineId, failure\.field \?\? "kind"\)\)\?\.focus\(\)/);
});
