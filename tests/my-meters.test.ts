import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { activeLocale, CATALOGS, setActiveLocale } from "../i18n";
import { createProductionServices, mapMyMeters } from "../services/production/httpServices";
import { StaffServiceError } from "../domain/models";
import { metersQuery, metersTile, MyMetersCard } from "../features/history/MyMetersCard";

// Staff 2.52.0: the employee's own line meters (API 2.70.0 /production-intelligence/mine). Only the signed-in employee's
// work, per stage, never added together; a completion without known meters is named, never counted as zero; the old
// whole-order snapshot sum is no longer shown.

const row = { stageId: "material-preparation", completions: 3, cuts: 2, recuts: 1, fabricMeters: "12.500", completionsWithUnknownLines: 0, completionsWithoutMeters: 1 };
const inRomanian = <T>(run: () => T): T => { const before = activeLocale(); setActiveLocale("ro"); try { return run(); } finally { setActiveLocale(before); } };
const assertServerError = (run: () => unknown) => assert.throws(run, (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
function jsonResponse(status: number, body: unknown) { return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }); }

test("the own-meters reply is mapped strictly; anything malformed is refused", () => {
  assert.deepEqual(mapMyMeters({ range: {}, stages: [row], items: [{ orderNumber: "1" }] }), { stages: [row] });
  assertServerError(() => mapMyMeters({ stages: [{ ...row, fabricMeters: "12.5" }] }));
  assertServerError(() => mapMyMeters({ stages: [{ ...row, fabricMeters: 12.5 }] }));
  assertServerError(() => mapMyMeters({ stages: [{ ...row, completions: -1 }] }));
  assertServerError(() => mapMyMeters({ stages: [{ ...row, stageId: "" }] }));
  assertServerError(() => mapMyMeters({}));
});

test("the history range becomes the Production Intelligence query, never before 4 October 2026", () => {
  const now = new Date("2026-11-14T21:00:00Z");
  assert.deepEqual(metersQuery("today", { from: "", to: "" }, now), { period: "today" });
  assert.deepEqual(metersQuery("month", { from: "", to: "" }, now), { period: "month" });
  assert.deepEqual(metersQuery("7days", { from: "", to: "" }, now), { from: "2026-11-08", to: "2026-11-14" });
  assert.deepEqual(metersQuery("7days", { from: "", to: "" }, new Date("2026-10-06T10:00:00Z")), { from: "2026-10-04", to: "2026-10-06" });
  assert.deepEqual(metersQuery("custom", { from: "2026-09-20", to: "2026-10-10" }, now), { from: "2026-10-04", to: "2026-10-10" });
  assert.equal(metersQuery("custom", { from: "2026-09-01", to: "2026-09-30" }, now), null);
});

test("the summary tile never adds stages together and shows a dash when the meters are unknown", () => {
  assert.equal(metersTile({ status: "loading" }), "—");
  assert.equal(metersTile({ status: "unavailable" }), "—");
  assert.equal(metersTile({ status: "ready", data: { stages: [] } }), "0");
  assert.match(inRomanian(() => metersTile({ status: "ready", data: { stages: [row] } })), /^12,5$/);
  assert.equal(metersTile({ status: "ready", data: { stages: [row, { ...row, stageId: "labeling" }] } }), "—");
});

test("the card lists each stage with its meters, cuts and the completions without known meters", () => {
  const html = inRomanian(() => renderToStaticMarkup(createElement(MyMetersCard, { state: { status: "ready", data: { stages: [row, { ...row, stageId: "labeling", cuts: 0, recuts: 0, completionsWithoutMeters: 0, fabricMeters: "4.000" }] } } })));
  assert.ok(html.includes("Metrajul meu") && html.includes("Tăiere") && html.includes("Etichetare"));
  assert.ok(html.includes("12,5 m") && html.includes("4 m"));
  assert.ok(html.includes("1 încheieri fără metri cunoscuți"));
  assert.equal((html.match(/Tăieri/g) ?? []).length, 1, "cuts only where the stage cut");
  const empty = inRomanian(() => renderToStaticMarkup(createElement(MyMetersCard, { state: { status: "ready", data: { stages: [] } } })));
  assert.ok(empty.includes("Nicio etapă încheiată"));
  const before = inRomanian(() => renderToStaticMarkup(createElement(MyMetersCard, { state: { status: "before-start" } })));
  assert.ok(before.includes("4 octombrie 2026"));
  assert.equal(renderToStaticMarkup(createElement(MyMetersCard, { state: { status: "loading" } })), "");
});

test("the request asks only for the period: the employee comes from the session; an older API answers 404", async () => {
  const calls: string[] = [];
  let legacy = false;
  const fetchImpl = (async (url: string, init: RequestInit) => {
    calls.push(`${init.method ?? "GET"} ${url.replace("https://api.arasyahome.ro", "")}`);
    if (url.endsWith("/auth/session")) return jsonResponse(200, { employee: { employeeUuid: "e", displayName: "A", username: "a", department: "D", role: "employee", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false }, expiresAt: "2026-08-19T10:00:00Z", csrfToken: "csrf-1" });
    if (url.includes("/production-intelligence/mine")) return legacy ? jsonResponse(404, { error: { code: "NOT_FOUND" } }) : jsonResponse(200, { stages: [row], items: [] });
    return jsonResponse(404, {});
  }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true, requestId: () => "req" });
  await services.auth.getSession();
  assert.deepEqual(await services.activity.myMeters!({ period: "today" }), { stages: [row] });
  assert.deepEqual(await services.activity.myMeters!({ from: "2026-10-04", to: "2026-10-31" }), { stages: [row] });
  legacy = true;
  assert.equal(await services.activity.myMeters!({ period: "month" }), null);
  assert.deepEqual(calls.slice(1), ["GET /production-intelligence/mine?period=today", "GET /production-intelligence/mine?from=2026-10-04&to=2026-10-31", "GET /production-intelligence/mine?period=month"]);
  assert.ok(calls.every((call) => !/employee/i.test(call)), "no employee id is ever sent");
});

test("the history no longer shows the old whole-order meter sum; every language names the new texts", () => {
  const source = readFileSync(new URL("../features/history/HistoryScreen.tsx", import.meta.url), "utf8");
  assert.equal(source.includes("summary.meters"), false);
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("history.meters.") || key === "history.metric.lineMeters");
  assert.equal(keys.length, 12);
  for (const locale of Object.keys(CATALOGS) as (keyof typeof CATALOGS)[]) for (const key of keys) assert.ok((CATALOGS[locale] as unknown as Record<string, unknown>)[key], `${locale} ${key}`);
});
