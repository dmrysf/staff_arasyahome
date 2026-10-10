import { afterEach, beforeEach, mock, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { StaffServiceError } from "../domain/models";
import type { TrendyolApi, TrendyolOverview, TrendyolPackageSummary, TrendyolView } from "../domain/trendyol";
import { createRefreshScheduler, type RefreshReason, type RefreshSnapshot, type RefreshVisibility } from "../features/workspaces/refreshScheduler";
import { AUTO_REFRESH_MS } from "../features/workspaces/useAutoRefresh";
import { LIST_MAX_AGE_MS, trendyolDashboardLoader, trendyolInboxLoader, type TrendyolDashboardData, type TrendyolInboxData } from "../features/trendyol/trendyolRefresh";
import { AutoRefreshBar } from "../features/workspaces/DashboardParts";
import { TrendyolDashboard } from "../features/workspaces/TrendyolDashboard";

const MINUTE = 60_000;
const root = path.resolve(import.meta.dirname, "..");

beforeEach(() => mock.timers.enable({ apis: ["setTimeout", "Date"], now: 0 }));
afterEach(() => mock.timers.reset());

/** Lets settled promises run their callbacks (microtasks only; the mocked timers do not move). */
const flush = async () => { for (let i = 0; i < 5; i++) await Promise.resolve(); };
const advance = async (ms: number) => { mock.timers.tick(ms); await flush(); };

function fakeVisibility(): RefreshVisibility & { set: (visible: boolean) => void; listeners: number } {
  let visible = true;
  const listeners = new Set<() => void>();
  return {
    isVisible: () => visible,
    subscribe: (listener) => { listeners.add(listener); return () => listeners.delete(listener); },
    set(next) { visible = next; for (const listener of [...listeners]) listener(); },
    get listeners() { return listeners.size; },
  };
}

type Call = { reason: RefreshReason; signal: AbortSignal; resolve: (value: number) => void; reject: (error: unknown) => void };
function harness(intervalMs: number | undefined = AUTO_REFRESH_MS) {
  const calls: Call[] = [];
  const snapshots: RefreshSnapshot<number>[] = [];
  const visibility = fakeVisibility();
  const scheduler = createRefreshScheduler<number>({
    load: (signal, _previous, reason) => new Promise<number>((resolve, reject) => { calls.push({ reason, signal, resolve, reject }); }),
    toError: (caught) => caught instanceof StaffServiceError ? caught : new StaffServiceError("SERVER_ERROR"),
    onChange: (snapshot) => snapshots.push(snapshot),
    intervalMs,
    visibility,
  });
  const last = () => snapshots[snapshots.length - 1];
  /** Answers the newest read. */
  const answer = async (value: number) => { calls[calls.length - 1].resolve(value); await flush(); };
  const fail = async (code: ConstructorParameters<typeof StaffServiceError>[0]) => { calls[calls.length - 1].reject(new StaffServiceError(code)); await flush(); };
  return { calls, snapshots, visibility, scheduler, last, answer, fail };
}

test("the first read starts at once, as a foreground read, and the next one exactly 60 seconds after it settled", async () => {
  const h = harness();
  h.scheduler.start();
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].reason, "initial");
  assert.equal(h.last().busy, true);
  await advance(1_000);
  await h.answer(7);
  assert.deepEqual([h.last().data, h.last().busy, h.last().error, h.last().updatedAt], [7, false, null, 1_000]);
  await advance(MINUTE - 1);
  assert.equal(h.calls.length, 1, "nothing before the minute is over");
  await advance(1);
  assert.equal(h.calls.length, 2);
  assert.equal(h.calls[1].reason, "interval");
  assert.deepEqual([h.last().busy, h.last().refreshing, h.last().data], [false, true, 7], "a background read keeps the data on screen");
  await h.answer(8);
  assert.deepEqual([h.last().data, h.last().refreshing], [8, false]);
  h.scheduler.dispose();
});

test("the button reads at once and restarts the minute; it never adds a second timer", async () => {
  const h = harness();
  h.scheduler.start();
  await h.answer(1);
  await advance(30_000);
  h.scheduler.refresh();
  assert.equal(h.calls[1].reason, "manual");
  assert.equal(h.last().busy, true);
  await h.answer(2);
  await advance(MINUTE - 1);
  assert.equal(h.calls.length, 2, "the timer counts from the manual read, not from the first one");
  await advance(1);
  assert.equal(h.calls.length, 3);
  await h.answer(3);
  // Ten minutes: exactly ten more reads, one per minute.
  for (let minute = 0; minute < 10; minute++) { await advance(MINUTE); await h.answer(10 + minute); }
  assert.equal(h.calls.length, 13);
  assert.ok(h.calls.slice(2).every((call) => call.reason === "interval"));
  h.scheduler.dispose();
});

test("a hidden tab reads nothing; visible again it reads at once only if the data is older than a minute", async () => {
  const h = harness();
  h.scheduler.start();
  await h.answer(1);
  h.visibility.set(false);
  await advance(10 * MINUTE);
  assert.equal(h.calls.length, 1, "no read while hidden");
  h.visibility.set(true);
  assert.equal(h.calls.length, 2);
  assert.equal(h.calls[1].reason, "visible");
  assert.equal(h.last().busy, false, "the visible read is a background read");
  await h.answer(2);
  // Hidden for only 20 seconds: no immediate read, the minute simply continues.
  await advance(20_000);
  h.visibility.set(false);
  await advance(20_000);
  h.visibility.set(true);
  assert.equal(h.calls.length, 2);
  await advance(MINUTE - 40_000);
  assert.equal(h.calls.length, 3, "the remaining 20 seconds of the minute");
  // Repeated visibility events never multiply reads or timers.
  await h.answer(3);
  for (let i = 0; i < 5; i++) { h.visibility.set(false); h.visibility.set(true); }
  await advance(MINUTE);
  assert.equal(h.calls.length, 4);
  h.scheduler.dispose();
});

test("unmounting (navigation away) aborts the read in flight, stops the timer and ignores late answers", async () => {
  const h = harness();
  h.scheduler.start();
  await h.answer(1);
  await advance(MINUTE);
  const inFlight = h.calls[1];
  const before = h.snapshots.length;
  h.scheduler.dispose();
  assert.equal(inFlight.signal.aborted, true);
  assert.equal(h.visibility.listeners, 0, "the visibility listener is removed");
  inFlight.resolve(99);
  await advance(30 * MINUTE);
  h.visibility.set(true);
  assert.equal(h.calls.length, 2, "no read after unmount");
  assert.equal(h.snapshots.length, before, "no state update after unmount");
});

test("one read at a time: a slow read blocks the timer, and a newer read wins over an older answer", async () => {
  const h = harness();
  h.scheduler.start();
  await h.answer(1);
  await advance(MINUTE);
  assert.equal(h.calls.length, 2);
  await advance(5 * MINUTE);
  assert.equal(h.calls.length, 2, "no overlapping background reads while one is in flight");
  h.scheduler.refresh();
  assert.equal(h.calls[1].signal.aborted, true, "the button supersedes the slow background read");
  h.calls[1].resolve(50);
  await flush();
  assert.equal(h.last().data, 1, "the superseded answer is ignored");
  await h.answer(3);
  assert.equal(h.last().data, 3);
  h.scheduler.dispose();
});

test("a failed refresh keeps the last good data, backs off, and recovers to one read per minute", async () => {
  const h = harness();
  h.scheduler.start();
  await h.answer(5);
  await advance(MINUTE);
  await h.fail("NETWORK_UNAVAILABLE");
  assert.deepEqual([h.last().data, h.last().error?.code, h.last().stopped, h.last().updatedAt], [5, "NETWORK_UNAVAILABLE", false, 0], "stale data, never zeros");
  await advance(2 * MINUTE - 1);
  assert.equal(h.calls.length, 2, "after one failure the next read waits two minutes");
  await advance(1);
  await h.fail("SERVICE_UNAVAILABLE");
  await advance(4 * MINUTE);
  assert.equal(h.calls.length, 4, "then four minutes");
  await h.fail("SERVER_ERROR");
  await advance(5 * MINUTE);
  assert.equal(h.calls.length, 5, "capped at five minutes");
  await h.answer(6);
  assert.deepEqual([h.last().data, h.last().error], [6, null]);
  await advance(MINUTE);
  assert.equal(h.calls.length, 6, "recovered: one read per minute again");
  h.scheduler.dispose();
});

test("an expired session or refused access stops automatic reads; only the button reads again", async () => {
  for (const code of ["SESSION_EXPIRED", "NO_SESSION", "ACCOUNT_INACTIVE", "UNAUTHORIZED_ACTION", "APPLICATION_ACCESS_DENIED", "PASSWORD_CHANGE_REQUIRED"] as const) {
    const h = harness();
    h.scheduler.start();
    await h.answer(1);
    await advance(MINUTE);
    await h.fail(code);
    assert.equal(h.last().stopped, true, code);
    await advance(60 * MINUTE);
    h.visibility.set(false);
    h.visibility.set(true);
    assert.equal(h.calls.length, 2, `${code}: no further automatic read`);
    h.scheduler.refresh();
    assert.equal(h.calls.length, 3);
    assert.equal(h.last().stopped, false);
    await h.answer(2);
    await advance(MINUTE);
    assert.equal(h.calls.length, 4, `${code}: polling resumes after a successful manual read`);
    h.scheduler.dispose();
  }
});

test("without an interval the scheduler reads only on start and on the button", async () => {
  const h = harness(0);
  h.scheduler.start();
  await h.answer(1);
  assert.equal(h.visibility.listeners, 0);
  await advance(60 * MINUTE);
  assert.equal(h.calls.length, 1);
  h.scheduler.dispose();
});

// ---- Trendyol loaders: few reads, read-only ---------------------------------------------------------------------
const overview = (counts: Partial<TrendyolOverview["counts"]> = {}): TrendyolOverview => ({
  intake: { status: "active", baselineAt: "2026-10-10T16:48:04Z", lastRunAt: "2026-10-10T16:50:03Z", lastRunOutcome: "ok" },
  counts: { pending: 0, attention: 0, released: 0, closed: 0, ignored: 2, ...counts },
  capabilities: { view: true, prepare: true, release: true },
});
const summary = (packageId: string): TrendyolPackageSummary => ({ packageId, orderNumber: `TY${packageId}`, intakeStatus: "pending", marketplaceStatus: "Created", marketplaceClass: "new", marketplaceStatusKnown: true, orderDate: "2026-10-10T17:00:00Z", lineCount: 1, preparedCount: 0 } as TrendyolPackageSummary);

function fakeService() {
  const state = { overview: overview(), lists: { pending: [] as TrendyolPackageSummary[], attention: [] as TrendyolPackageSummary[], released: [] as TrendyolPackageSummary[], closed: [] as TrendyolPackageSummary[] } };
  const calls: string[] = [];
  const mutation = () => { throw new Error("a refresh must never change data"); };
  const service = {
    overview: async () => { calls.push("overview"); return state.overview; },
    list: async (view: Exclude<TrendyolView, "ignored">) => { calls.push(`list:${view}`); return state.lists[view]; },
    ignored: async () => { calls.push("ignored"); return []; },
    activity: async () => { calls.push("activity"); return { today: { linesPrepared: 0, released: 0 }, recent: [] }; },
    detail: mutation, prepareLine: mutation, dismiss: mutation, reopen: mutation, release: mutation,
  } as unknown as TrendyolApi;
  return { state, calls, service };
}

test("dashboard: a full read on open, then one overview per minute until the counts change", async () => {
  const { state, calls, service } = fakeService();
  let clock = 0;
  const load = trendyolDashboardLoader(service, () => clock);
  const signal = new AbortController().signal;
  let data: TrendyolDashboardData = await load(signal, null, "initial");
  assert.deepEqual([...calls].sort(), ["activity", "list:attention", "list:pending", "list:released", "overview"], "five reads only on open");
  calls.length = 0;
  clock += MINUTE;
  data = await load(signal, data, "interval");
  assert.deepEqual(calls, ["overview"], "an unchanged minute costs one small read");
  // A new eligible package is imported by the server-side sync: the counts change and the lists are read.
  state.overview = overview({ pending: 1 });
  state.lists.pending = [summary("3001")];
  calls.length = 0;
  clock += MINUTE;
  data = await load(signal, data, "interval");
  assert.deepEqual([...calls].sort(), ["list:attention", "list:pending", "list:released", "overview"]);
  assert.equal(data.overview.counts.pending, 1);
  assert.deepEqual(data.pending.map((item) => item.packageId), ["3001"], "the new package appears after the next refresh");
  assert.ok(data.activity, "the employee's activity is kept, not re-read");
  // Changes inside a list (another preparer's lines) are picked up within five minutes.
  calls.length = 0;
  clock += LIST_MAX_AGE_MS - 1;
  data = await load(signal, data, "interval");
  assert.deepEqual(calls, ["overview"]);
  clock += 1;
  data = await load(signal, data, "interval");
  assert.equal(calls.length, 5);
  // The button and a visible tab read everything.
  calls.length = 0;
  await load(signal, data, "manual");
  await load(signal, data, "visible");
  assert.equal(calls.length, 10);
});

test("inbox: the selected tab is kept and refreshed in place; another tab is read in full", async () => {
  const { state, calls, service } = fakeService();
  let clock = 0;
  const signal = new AbortController().signal;
  const attention = trendyolInboxLoader(service, "attention", () => clock);
  let data: TrendyolInboxData = await attention(signal, null, "initial");
  assert.deepEqual([...calls].sort(), ["list:attention", "overview"]);
  calls.length = 0;
  clock += MINUTE;
  data = await attention(signal, data, "interval");
  assert.deepEqual(calls, ["overview"]);
  assert.equal(data.view, "attention", "the tab is kept");
  state.overview = overview({ attention: 1 });
  state.lists.attention = [summary("4001")];
  calls.length = 0;
  data = await attention(signal, data, "interval");
  assert.deepEqual(calls, ["overview", "list:attention"], "only the selected list is read");
  assert.deepEqual(data.items?.map((item) => item.packageId), ["4001"]);
  // Switching to the ignored tab with the attention data as seed: never shows the attention list as ignored.
  calls.length = 0;
  const ignored = trendyolInboxLoader(service, "ignored", () => clock);
  const next = await ignored(signal, data, "interval");
  assert.deepEqual([...calls].sort(), ["ignored", "overview"]);
  assert.deepEqual([next.view, next.items, next.ignored], ["ignored", null, []]);
});

// ---- Rendering and scope -------------------------------------------------------------------------------------------
test("the refresh bar names the Staff refresh, the stale state and a stop; it never claims a Trendyol synchronization", () => {
  const bar = (props: Partial<Parameters<typeof AutoRefreshBar>[0]>) => renderToStaticMarkup(createElement(AutoRefreshBar, { onRefresh: () => undefined, busy: false, refreshing: false, updatedAt: null, stale: false, stopped: false, ...props }));
  const fresh = bar({ updatedAt: Date.UTC(2026, 9, 10, 17, 5) });
  assert.match(fresh, /Actualizare automată la fiecare minut/);
  assert.match(fresh, /Ultima actualizare 20:05/);
  assert.doesNotMatch(fresh, /neactualizate|Trendyol/);
  assert.match(bar({ refreshing: true }), /Se actualizează…/);
  assert.match(bar({ stale: true }), /role="status"[^>]*>Datele pot fi neactualizate/);
  assert.doesNotMatch(bar({ stale: true }), /role="alert"/, "no alert every minute");
  assert.match(bar({ stopped: true }), /Actualizare automată oprită/);
  assert.match(bar({ busy: true }), /disabled/);
});

test("the dashboard renders loading markers, never zero counts, before its first read", () => {
  const { service } = fakeService();
  const employee = { displayName: "Ayșe" } as Parameters<typeof TrendyolDashboard>[0]["employee"];
  const html = renderToStaticMarkup(createElement(TrendyolDashboard, { employee, service, navigate: () => undefined }));
  assert.match(html, /data-testid="metric-trendyol-pending"><dd class="metric-unavailable">…<\/dd>/);
  assert.doesNotMatch(html, /<dd>0<\/dd>/);
});

test("automatic refresh is limited to the Trendyol dashboard and inbox; the package form never refreshes itself", () => {
  const users = ["features", "app", "components"].flatMap((dir) => listSources(path.join(root, dir))).filter((file) => /useAutoRefresh|createRefreshScheduler/.test(readFileSync(file, "utf8")));
  assert.deepEqual(users.map((file) => path.relative(root, file)).sort(), [
    "features/trendyol/TrendyolInboxScreen.tsx", "features/workspaces/TrendyolDashboard.tsx",
    "features/workspaces/refreshScheduler.ts", "features/workspaces/useAutoRefresh.ts",
  ]);
  const packageScreen = readFileSync(path.join(root, "features/trendyol/TrendyolPackageScreen.tsx"), "utf8");
  assert.doesNotMatch(packageScreen, /setInterval|setTimeout|useAutoRefresh|visibilitychange/, "the measurement form is never refreshed under the employee");
  const loaders = readFileSync(path.join(root, "features/trendyol/trendyolRefresh.ts"), "utf8");
  assert.doesNotMatch(loaders, /\.(prepareLine|release|dismiss|reopen|detail)\(/, "refresh loaders only read");
});

function listSources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => entry.isDirectory() ? listSources(path.join(dir, entry.name)) : /\.(ts|tsx)$/.test(entry.name) ? [path.join(dir, entry.name)] : []);
}
