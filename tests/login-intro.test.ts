import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file: string) => readFileSync(path.join(root, file), "utf8");
const strip = (css: string) => css.replace(/\/\*[\s\S]*?\*\//g, "");

test("the sign-in intro types the Arasya Home line and uses the Arasya wordmark in white", async () => {
  const { INTRO_TEXT } = await import("../features/auth/LoginIntro");
  assert.equal(INTRO_TEXT, "Designed to Inspire. Made to Last.");
  const source = read("features/auth/LoginIntro.tsx");
  assert.match(source, /components\/brand\/arasya-logo\.webp/);
  assert.match(strip(read("styles/intro.css")), /\.intro-brand img \{[^}]*filter: invert\(1\)/);
});

test("the intro cannot be skipped and also plays with reduced motion", () => {
  const source = read("features/auth/LoginIntro.tsx").replace(/\/\*[\s\S]*?\*\/|^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(source, /<button|onClick|onKeyDown|Sari|skip/i, "no skip control");
  assert.doesNotMatch(source, /prefers-reduced-motion|matchMedia|localStorage|sessionStorage/, "no reduced-motion or once-per-day exception");
  // Web Animations are not shortened by the global reduced-motion CSS; only transform, opacity and the stage clip move.
  const animated = [...source.matchAll(/\{ (opacity|transform|clipPath)[^}]*\}/g)].map((match) => match[0]);
  assert.ok(animated.length > 10);
  for (const frame of animated) assert.doesNotMatch(frame, /\b(width|height|top|left|margin|filter)\s*:/, frame);
});

test("every sign-in from the login form plays the intro; a restored session does not", () => {
  const app = read("app/StaffApp.tsx");
  assert.match(app, /services\.auth\.login\(input\);[^;]*;[^;]*;[^;]*;[^;]*;\s*onSignedIn\(\);/);
  assert.equal((app.match(/onSignedIn\(\)/g) ?? []).length, 1, "only the login form starts it");
  assert.match(app, /<LoginIntroStage run=\{introRun\} onDone=\{finishIntro\}>/);
});

test("intro styles take colours from tokens and keyframes move only opacity", () => {
  const css = strip(read("styles/intro.css"));
  assert.doesNotMatch(css, /#[0-9a-f]{3,8}\b|rgba?\(/i);
  for (const block of css.match(/@keyframes[^{]+\{(?:[^{}]*\{[^}]*\})*[^}]*\}/g) ?? []) assert.doesNotMatch(block.replace(/@keyframes[^{]+\{/, ""), /\b(?!opacity|transform)[a-z-]+\s*:/);
  assert.match(read("styles/tokens.css"), /--z-intro: 100;[\s\S]*--color-intro-bg:/);
  assert.match(read("src/main.tsx"), /motion\.css";\nimport "\.\.\/styles\/intro\.css";/);
});

test("the map of Europe loads only when a sign-in starts and marks the Krefeld and Bucharest offices", async () => {
  const intro = read("features/auth/LoginIntro.tsx");
  assert.match(intro, /const loadIntroMap = \(\) => import\("\.\/IntroMap"\);/);
  assert.match(intro, /lazy\(loadIntroMap\)/);
  assert.doesNotMatch(intro, /^import[^;]*from "\.\/IntroMap"/m, "no static import: the map is its own chunk");
  assert.match(read("app/StaffApp.tsx"), /onLogin=\{async \(input\) => \{ if \(introPlaysHere\(\)\) preloadIntroMap\(\); const next = await services\.auth\.login\(input\);/);
  const map = read("features/auth/IntroMap.tsx");
  assert.match(map, /name: "Krefeld"/);
  assert.match(map, /name: "Bucharest"/);
  const { INTRO_MAP } = await import("../features/auth/introMapData");
  assert.ok(INTRO_MAP.labels.some((label) => label.text === "Germany" && label.key));
  assert.ok(INTRO_MAP.labels.some((label) => label.text === "Romania" && label.key));
  assert.ok(read("features/auth/introMapData.ts").length < 60_000, "map data stays small");
});
