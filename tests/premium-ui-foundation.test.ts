import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { TrendyolLine, TrendyolPackageDetail, TrendyolPackageSummary } from "../domain/trendyol";
import { draftOf, lineStatus, preparationSteps } from "../features/trendyol/preparation";
import { trendyolNextAction } from "../features/workspaces/trendyolNextAction";
import type { TrendyolDashboardData } from "../features/trendyol/trendyolRefresh";
import { printCount } from "../features/documents/DocumentPanel";
import { TaskSteps } from "../components/ui/TaskSteps";
import { BottomNavigation } from "../components/navigation/BottomNavigation";
import type { Employee } from "../domain/models";

const line = (number: number, prepared: TrendyolLine["prepared"] = null): TrendyolLine => ({
  lineId: `l${number}`, lineNumber: number, productName: "Draperie blackout catifea verde smarald cu rejansă și inele", stockCode: "DB-1", productCode: "DB-1", barcode: null,
  suggestedTitle: null, suggestedTitleApproval: null, productSize: "280x250", productColor: "Verde", quantity: 2, sizeSuggestion: { width: "280", height: "250" }, prepared, productionItem: null,
});
const confirmed = { kind: "drapery" as const, widthCm: "280", heightCm: "250", meters: null, notes: null, productionTitle: "Draperie blackout verde smarald", preparedBy: "Ayşe", preparedAt: "2026-10-11T08:00:00Z" };
const pkg = (patch: Partial<TrendyolPackageDetail> = {}): TrendyolPackageDetail => ({
  packageId: "74101", orderNumber: "TY74101", intakeStatus: "pending", marketplaceStatus: "Created", marketplaceClass: "new", marketplaceStatusKnown: true,
  orderDate: "2026-10-11T08:00:00Z", orderDateNearActivation: false, changedAfterRelease: false, version: 3,
  delivery: null, lines: [line(1), line(2)], readiness: { ready: false, missingLines: [1, 2], marketplaceReleasable: true, blockedReason: null },
  siblings: [], production: null, dismissal: null, correction: null, history: [],
  capabilities: { view: true, prepare: true, release: true, prepareNow: true, releaseNow: false, dismissNow: true, reopenNow: false, correctNow: false },
  ...patch,
});
const states = (detail: TrendyolPackageDetail) => preparationSteps(detail).map((step) => `${step.id}:${step.state}`);

test("the guided Trendyol steps mirror server states only and never claim a printed sheet", () => {
  assert.deepEqual(states(pkg()), ["review:current", "measure:upcoming", "approve:upcoming", "print:upcoming", "handoff:upcoming"]);
  const half = pkg({ lines: [line(1, confirmed), line(2)], readiness: { ready: false, missingLines: [2], marketplaceReleasable: true, blockedReason: null } });
  assert.deepEqual(states(half), ["review:done", "measure:current", "approve:upcoming", "print:upcoming", "handoff:upcoming"]);
  const ready = pkg({ lines: [line(1, confirmed), line(2, confirmed)], readiness: { ready: true, missingLines: [], marketplaceReleasable: true, blockedReason: null } });
  assert.deepEqual(states(ready), ["review:done", "measure:done", "approve:current", "print:upcoming", "handoff:upcoming"]);
  const blocked = pkg({ ...ready, readiness: { ready: false, missingLines: [], marketplaceReleasable: false, blockedReason: "payment_pending" } });
  assert.equal(preparationSteps(blocked)[2].state, "blocked", "a Trendyol status that forbids release blocks the approval step");

  const production = { globalOrderId: "trendyol:74101", stageId: "waiting", stageLabel: "În așteptare", documentStatus: "active", operationalStatus: "active", releasedBy: "Ayşe", releasedAt: "2026-10-11T09:00:00Z" };
  const released = pkg({ intakeStatus: "released", lines: ready.lines, readiness: ready.readiness, production });
  assert.deepEqual(states(released), ["review:done", "measure:done", "approve:done", "print:current", "handoff:upcoming"]);
  const cut = pkg({ ...released, production: { ...production, stageId: "material-preparation", stageLabel: "Tăiere" } });
  assert.deepEqual(states(cut), ["review:done", "measure:done", "approve:done", "print:ready", "handoff:done"], "printing is never reported as done");
  assert.ok(!preparationSteps(cut).some((step) => step.id === "print" && step.state === "done"));
  assert.equal(preparationSteps(pkg({ ...released, production: { ...production, documentStatus: "stale" } }))[3].state, "blocked");
  assert.deepEqual(preparationSteps(pkg({ intakeStatus: "dismissed" })), [], "a dismissed package has no production sequence");
});

test("a line is confirmed only while the screen shows exactly what the server saved", () => {
  const saved = line(1, confirmed);
  assert.equal(lineStatus(saved, draftOf(saved)), "confirmed");
  assert.equal(lineStatus(saved, { ...draftOf(saved), widthCm: "281" }), "unsaved");
  assert.equal(lineStatus(line(1), draftOf(line(1))), "todo");
  assert.equal(lineStatus(line(1), { ...draftOf(line(1)), kind: "curtain" }), "unsaved");
  assert.equal(lineStatus(saved, { ...draftOf(saved), productionTitle: "Draperie" }), "unsaved", "editing the Romanian title is an unsaved change");
  const beforeTitles = line(1, { ...confirmed, productionTitle: null });
  assert.equal(lineStatus(beforeTitles, draftOf(beforeTitles)), "todo", "a line saved before Romanian titles existed still needs its title");
  const suggested: TrendyolLine = { ...line(2), suggestedTitle: "Draperie blackout verde", suggestedTitleApproval: { by: "Ayşe", at: "2026-10-11T08:00:00Z" } };
  assert.equal(draftOf(suggested).productionTitle, "Draperie blackout verde", "the approved title of the product code prefills the draft");
  assert.equal(draftOf(line(1, confirmed)).productionTitle, "Draperie blackout verde smarald", "a saved title wins over the suggestion");
});

const summary = (id: string, lineCount: number, preparedCount: number): TrendyolPackageSummary => ({
  packageId: id, orderNumber: `TY${id}`, intakeStatus: "pending", marketplaceStatus: "Created", marketplaceClass: "new", marketplaceStatusKnown: true,
  orderDate: null, orderDateNearActivation: false, changedAfterRelease: false, version: 1, lineCount, preparedCount,
});
const dashboard = (pending: TrendyolPackageSummary[], patch: { release?: boolean; prepare?: boolean; attention?: number; status?: "active" | "paused" | "inactive" } = {}): TrendyolDashboardData => ({
  overview: { intake: { status: patch.status ?? "active", baselineAt: null, lastRunAt: null, lastRunOutcome: null }, counts: { pending: pending.length, attention: patch.attention ?? 0, released: 0, closed: 0, ignored: 0 }, capabilities: { view: true, prepare: patch.prepare ?? true, release: patch.release ?? true } },
  pending, attention: [], released: [], activity: null, listsAt: 0,
});

test("the home names one next step from data already read, and only links to the existing screens", () => {
  const ready = trendyolNextAction(dashboard([summary("1", 3, 1), summary("2", 2, 2)]));
  assert.equal(ready.tone, "ready");
  assert.deepEqual(ready.cta, { label: "Deschide comanda #TY2", path: "/trendyol/2" });
  const prepare = trendyolNextAction(dashboard([summary("1", 3, 1), summary("2", 2, 2)], { release: false }));
  assert.equal(prepare.title, "Completează măsurile", "without approval rights the preparer is sent to the measurements");
  assert.equal(prepare.detail, "2 din 3 linii de completat.");
  assert.equal(trendyolNextAction(dashboard([summary("1", 1, 0)], { prepare: false, release: false })).cta?.path, "/trendyol");
  assert.equal(trendyolNextAction(dashboard([], { attention: 2 })).tone, "attention");
  assert.equal(trendyolNextAction(dashboard([])).cta, null);
  assert.match(trendyolNextAction(dashboard([], { status: "paused" })).title, /oprită temporar/);
});

test("print counts speak of downloads for printing, never of printed paper", () => {
  assert.equal(printCount(0), "PDF-ul pentru tipărire nu a fost descărcat încă.");
  assert.equal(printCount(1), "PDF pentru tipărire descărcat o dată.");
  assert.equal(printCount(3), "PDF pentru tipărire descărcat de 3 ori.");
  for (const value of [0, 1, 3]) assert.doesNotMatch(printCount(value), /tipărit[ăa]? (o dată|de)|netipărit/);
});

test("steps carry words for every state and mark the current step for assistive technology", () => {
  const html = renderToStaticMarkup(createElement(TaskSteps, { steps: preparationSteps(pkg({ lines: [line(1, confirmed), line(2)], readiness: { ready: false, missingLines: [2], marketplaceReleasable: true, blockedReason: null } })), label: "Pașii" }));
  assert.match(html, /aria-label="Pașii"/);
  assert.match(html, /aria-current="step"[^>]*data-state="current"/);
  assert.match(html, /Finalizat/);
  assert.match(html, /Urmează/);
});

test("navigation renders only authorized destinations and keeps the QR action behind orders.scan", () => {
  const person = (permissions: string[]) => ({ permissions, applications: ["staff"] }) as unknown as Employee;
  const full = renderToStaticMarkup(createElement(BottomNavigation, { employee: person(["orders.view_mine", "history.view_mine", "profile.view_self", "orders.scan"]), route: "/", navigate: () => undefined }));
  assert.match(full, /aria-label="Scanează codul QR"/);
  assert.match(full, /aria-current="page"/);
  const limited = renderToStaticMarkup(createElement(BottomNavigation, { employee: person(["profile.view_self"]), route: "/profile", navigate: () => undefined }));
  assert.doesNotMatch(limited, /Scanează codul QR|Comenzi|Istoric/);
});

test("the stylesheet foundation: every color of the new layers is a token, inputs never zoom on iOS, motion respects reduced motion", () => {
  const read = (file: string) => readFileSync(new URL(`../styles/${file}`, import.meta.url), "utf8");
  const tokens = read("tokens.css");
  for (const theme of [":root {", ':root[data-theme="dark"]']) assert.ok(tokens.includes(theme), theme);
  assert.match(tokens, /:root \{[^}]*--color-bg: #ffffff;[^}]*--color-text: #000000;/, "the default look is white with black text");
  for (const file of ["foundation.css", "experiences.css"]) {
    const css = read(file).replace(/\/\*[\s\S]*?\*\//g, "");
    assert.doesNotMatch(css, /#[0-9a-f]{3,8}\b/i, `${file}: hex colors belong in tokens.css`);
  }
  assert.match(read("foundation.css"), /\.field input, \.field select, \.field textarea \{[^}]*font-size: var\(--text-base\)/);
  assert.match(tokens, /--text-base: 1rem;/);
  assert.match(readFileSync(new URL("../app/globals.css", import.meta.url), "utf8"), /prefers-reduced-motion: reduce[\s\S]*animation-duration: \.01ms !important/);
});
