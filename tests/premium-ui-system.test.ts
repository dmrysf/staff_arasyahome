import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { BootScreen, EmptyPanel, LoadingState, PageHeader } from "../components/ui/States";
import { ErrorState } from "../components/ErrorState";
import { StaffServiceError } from "../domain/models";

const root = path.resolve(import.meta.dirname, "..");

test("every screen stylesheet takes its colours from tokens.css", () => {
  for (const file of ["app.css", "foundation.css", "experiences.css", "motion.css"]) {
    const css = readFileSync(path.join(root, "styles", file), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
    assert.doesNotMatch(css, /#[0-9a-f]{3,8}\b/i, `${file}: hex colour outside tokens.css`);
    assert.doesNotMatch(css, /rgba?\(/i, `${file}: rgb colour outside tokens.css`);
  }
});

test("shared states: loading is announced and shaped, empty states name what appears, boot screens show the brand", () => {
  const loading = renderToStaticMarkup(createElement(LoadingState, { label: "Se încarcă comenzile…" }));
  assert.match(loading, /role="status"/);
  assert.match(loading, /Se încarcă comenzile…/);
  assert.equal((loading.match(/skeleton-card/g) ?? []).length, 3);
  const empty = renderToStaticMarkup(createElement(EmptyPanel, { title: "Nu ai comenzi active." }, "Scanează codul unei comenzi."));
  assert.match(empty, /<h2>Nu ai comenzi active\.<\/h2>/);
  const boot = renderToStaticMarkup(createElement(BootScreen, { message: "Se pregătește spațiul tău…", busy: true }));
  assert.match(boot, /aria-busy="true"/);
  assert.match(boot, /brand-logo/);
  assert.match(boot, /alt="Arasya Home"/);
  assert.match(renderToStaticMarkup(createElement(PageHeader, { title: "Istoric", description: "Acțiunile tale." })), /<h1>Istoric<\/h1>/);
});

test("errors keep their words and action; icons are drawn, never text glyphs", () => {
  const html = renderToStaticMarkup(createElement(ErrorState, { error: new StaffServiceError("NETWORK_UNAVAILABLE"), onAction: () => undefined }));
  assert.match(html, /role="alert"/);
  assert.match(html, /<svg/);
  assert.doesNotMatch(html, /aria-hidden="true">!</);
  const sources = ["features/orders/OrderActionPanel.tsx", "features/scanner/ScannerScreen.tsx"].map((file) => readFileSync(path.join(root, file), "utf8"));
  for (const source of sources) assert.doesNotMatch(source, /confirm-icon" aria-hidden="true">\?</);
});

test("no screen still uses the retired boot and loading markup", () => {
  const list = (dir: string): string[] => readdirSync(dir, { withFileTypes: true }).flatMap((entry) => entry.isDirectory() ? list(path.join(dir, entry.name)) : /\.tsx$/.test(entry.name) ? [path.join(dir, entry.name)] : []);
  for (const file of ["app", "features", "components"].flatMap((dir) => list(path.join(root, dir)))) {
    const source = readFileSync(file, "utf8");
    assert.doesNotMatch(source, /className="(session-check|inline-loading)"/, path.relative(root, file));
  }
});
