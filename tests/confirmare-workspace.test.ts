import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { Employee } from "../domain/models";
import { StaffServiceError } from "../domain/models";
import {
  approvalMissing, canDecide, canSuperviseConfirmare, canUseConfirmare, carryDraft, confirmareErrorKey, confirmareSources, inTab, itemStatus, nextAction, QUEUE_TABS,
  type ConfirmareDetail, type ConfirmareItem,
} from "../domain/confirmare";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { resolveWorkspaces, selectPrimaryWorkspace } from "../domain/workspaces";
import { confirmareErrorDetails, createProductionServices, mapConfirmareDetail, mapConfirmareItem, mapProductionEmployee } from "../services/production/httpServices";
import { confirmareQueueLoader } from "../features/confirmare/ConfirmareParts";
import { HomeScreen } from "../features/home/HomeScreen";
import { CATALOGS, type MessageKey } from "../i18n";
import type { ServiceBundle } from "../services/contracts";

const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-000000000001";
const OTHER = "00000000-0000-4000-8000-000000000002";
const FP = "a".repeat(64);
const person = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: ME, displayName: "Operator Test", username: "op.test", department: "Vânzări online", departmentKey: "vanzari-online",
  role: "employee", status: "active", permissions: ["profile.view_self"], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: ["trendhome"], supervise: [] }, ...overrides,
});
const item = (overrides: Partial<ConfirmareItem> = {}): ConfirmareItem => ({
  globalOrderId: "trendhome:1001", orderNumber: "1001", sourceKey: "trendhome", status: "pending", version: 3, workflowCohort: "confirmare",
  openedAt: "2026-10-22T08:00:00.000000Z", submittedAt: "2026-10-22T07:59:00.000000Z", productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false,
  payment: { state: "online" }, financial: { state: "cleared", basis: "online_payment", open: true, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: { employeeId: ME, displayName: "Operator Test", at: "2026-10-22T08:01:00.000000Z", kind: "auto" }, decision: null, ...overrides,
});
const detail = (overrides: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  ...item(), review: { fingerprint: FP, lineIds: ["11", "12"], pricingAvailable: true, callRecorded: true, contactAvailable: true }, lines: [], contacts: [],
  customer: { visible: false, phoneAvailable: false }, source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: null, freshness: "fresh" },
  production: { cleared: false, missing: ["approval"] }, events: [], sourceAccess: { configured: false, read: false, edit: false, changePending: false, openEdits: [] }, invoice: { configured: false, read: false, issue: false, manage: false, proforma: false, route: false }, finance: { configured: false, read: false, record: false, approve: false }, handoff: null, cycle: null, ...overrides,
});
const contact = (outcome: "confirmed" | "no_answer" | "callback" | "needs_correction", current = true) => ({ outcome, at: "2026-10-22T09:00:00.000000Z", attempts: 1, current });

test("routes: the queue, the team and an order of a shop source; never another channel or a path in the id", () => {
  assert.deepEqual(parseStaffRoute("/confirmare"), { kind: "confirmare", pathname: "/confirmare" });
  assert.deepEqual(parseStaffRoute("/confirmare/team"), { kind: "confirmare-team", pathname: "/confirmare/team" });
  assert.deepEqual(parseStaffRoute("/confirmare/orders/trendhome%3A63438"), { kind: "confirmare-order", pathname: "/confirmare/orders/trendhome%3A63438", orderId: "trendhome:63438" });
  assert.equal(parseStaffRoute("/confirmare/orders/outletperdele%3A9").kind, "confirmare-order");
  for (const bad of ["/confirmare/orders/trendyol%3A1", "/confirmare/orders/b2b%3A1", "/confirmare/orders/trendhome%3A1%2F2", "/confirmare/orders/", "/confirmare/orders/%E0%A4%A", "/confirmare/orders/trendhome"]) {
    assert.equal(parseStaffRoute(bad).kind, "invalid", bad);
  }
});

test("route visibility follows the server-granted Confirmare scopes only", () => {
  const operator = person();
  const supervisor = person({ confirmareScopes: { operate: [], supervise: ["trendhome", "outletperdele"] } });
  const nobody = person({ confirmareScopes: { operate: [], supervise: [] } });
  assert.equal(canAccessRoute(operator, "/confirmare"), true);
  assert.equal(canAccessRoute(operator, "/confirmare/orders/trendhome:1"), true);
  assert.equal(canAccessRoute(operator, "/confirmare/team"), false, "an operator never opens the team view");
  assert.equal(canAccessRoute(supervisor, "/confirmare/team"), true);
  for (const blocked of [nobody, person({ confirmareScopes: undefined }), person({ applications: ["dashboard"] }), person({ mustChangePassword: true }), person({ status: "inactive" })]) {
    assert.equal(canAccessRoute(blocked, "/confirmare"), false);
    assert.equal(canUseConfirmare(blocked), false);
  }
  // A name, a department or a role never grants it.
  assert.equal(canUseConfirmare(person({ displayName: "IVAN IRINA", departmentKey: "vanzari-online", role: "supervisor", confirmareScopes: { operate: [], supervise: [] } })), false);
  // Unknown sources in a scope (Trendyol, B2B) are ignored: they never become a filter.
  assert.deepEqual(confirmareSources(person({ confirmareScopes: { operate: ["trendyol", "b2b", "outletperdele"], supervise: ["trendhome"] } })), ["trendhome", "outletperdele"]);
  assert.equal(canSuperviseConfirmare(operator), false);
});

test("the session maps the Confirmare scopes; an older API or a malformed value grants nothing", () => {
  const base = { employeeUuid: ME, displayName: "X", username: "x", department: "D", role: "employee", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false };
  assert.deepEqual(mapProductionEmployee({ ...base, confirmareScopes: { operate: ["trendhome"], supervise: [] } }).confirmareScopes, { operate: ["trendhome"], supervise: [] });
  assert.deepEqual(mapProductionEmployee(base).confirmareScopes, { operate: [], supervise: [] });
  assert.deepEqual(mapProductionEmployee({ ...base, confirmareScopes: { operate: "trendhome", supervise: [1] } }).confirmareScopes, { operate: [], supervise: [] });
});

test("the workspace appears only with a scope and the service; online sales open Confirmare first, Trendyol otherwise", () => {
  const available = { trendyol: true, documents: true, management: true, confirmare: true };
  const both = person({ permissions: ["profile.view_self", "trendyol.orders.view"] });
  assert.deepEqual(resolveWorkspaces(both, available).map((w) => w.id), ["confirmare", "trendyol"]);
  assert.equal(selectPrimaryWorkspace(resolveWorkspaces(both, available), both, null)?.id, "confirmare");
  const trendyolOnly = person({ permissions: ["profile.view_self", "trendyol.orders.view"], confirmareScopes: { operate: [], supervise: [] } });
  assert.equal(selectPrimaryWorkspace(resolveWorkspaces(trendyolOnly, available), trendyolOnly, null)?.id, "trendyol", "unchanged for Trendyol personnel without a scope");
  assert.deepEqual(resolveWorkspaces(both, { ...available, confirmare: false }).map((w) => w.id), ["trendyol"], "preview and demo have no Confirmare service");
});

test("presentation groups come from the API facts; nothing is a new state and a missed call is never a cancellation", () => {
  const rows = {
    toCall: item(),
    unassigned: item({ assignment: null }),
    undetermined: item({ assignment: null, workflowCohort: "undetermined" }),
    confirmed: item({ contact: contact("confirmed") }),
    staleConfirmed: item({ contact: contact("confirmed", false) }),
    noAnswer: item({ contact: contact("no_answer") }),
    callback: item({ contact: contact("callback"), assignment: { employeeId: OTHER, displayName: "Coleg", at: "2026-10-22T08:00:00Z", kind: "auto" } }),
    correction: item({ contact: contact("needs_correction") }),
    cod: item({ financial: { state: "waiting", basis: null, open: false, attention: null } }),
    approvedWaiting: item({ status: "approved", assignment: null, financial: { state: "partial", basis: null, open: false, attention: null } }),
    approvedChanged: item({ status: "approved", assignment: null }),
    cleared: item({ status: "approved", assignment: null, productionCleared: true }),
    rejected: item({ status: "rejected", assignment: null, financial: { state: "waiting", basis: null, open: false, attention: null } }),
    closed: item({ commerceClosed: true }),
    noPrice: item({ pricingAvailable: false }),
  };
  const statuses = Object.fromEntries(Object.entries(rows).map(([key, row]) => [key, itemStatus(row)]));
  assert.deepEqual(statuses, {
    toCall: "toCall", unassigned: "unassigned", undetermined: "undetermined", confirmed: "confirmed", staleConfirmed: "toCall", noAnswer: "noAnswer", callback: "callback",
    correction: "needsCorrection", cod: "toCall", approvedWaiting: "awaitingPayment", approvedChanged: "reverify", cleared: "cleared", rejected: "rejected", closed: "closedInShop", noPrice: "toCall",
  });
  const tab = (row: ConfirmareItem) => QUEUE_TABS.filter((id) => inTab(row, id, ME));
  assert.deepEqual(tab(rows.unassigned), ["unassigned"]);
  assert.deepEqual(tab(rows.undetermined), ["attention"], "an undetermined order is never offered as unassigned work");
  assert.deepEqual(tab(rows.confirmed), ["mine", "inProgress"]);
  assert.deepEqual(tab(rows.staleConfirmed), ["mine"], "a confirmation of an older order is not in progress");
  assert.deepEqual(tab(rows.noAnswer), ["mine", "callback"]);
  assert.deepEqual(tab(rows.callback), ["callback"], "a colleague's callback is not mine");
  assert.deepEqual(tab(rows.correction), ["mine", "correction"]);
  assert.deepEqual(tab(rows.cod), ["mine", "payment"], "a pending order may wait for a call and for the payment");
  assert.deepEqual(tab(rows.approvedWaiting), ["payment"]);
  assert.deepEqual(tab(rows.approvedChanged), ["attention"]);
  assert.deepEqual(tab(rows.cleared), []);
  assert.deepEqual(tab(rows.rejected), [], "a rejected order leaves every working group");
  assert.deepEqual(tab(rows.closed), ["mine", "attention"]);
  assert.deepEqual(tab(rows.noPrice), ["mine", "attention"]);
});

test("the next step keeps the server order: finish, call, callback, then ask the server for the next order", () => {
  const first = item({ globalOrderId: "trendhome:1", orderNumber: "1" });
  const second = item({ globalOrderId: "trendhome:2", orderNumber: "2" });
  assert.deepEqual(nextAction([first, second], ME, true), { kind: "call", item: first }, "the first row the server ordered (due date, then FIFO)");
  const done = item({ globalOrderId: "trendhome:3", contact: contact("confirmed") });
  assert.equal(nextAction([first, done], ME, true).kind, "finish");
  const missed = item({ contact: contact("no_answer") });
  assert.equal(nextAction([missed], ME, true).kind, "callback");
  assert.equal(nextAction([missed, item({ assignment: null })], ME, true).kind, "callback");
  assert.equal(nextAction([item({ assignment: null })], ME, true).kind, "takeNext");
  assert.equal(nextAction([item({ assignment: null })], ME, false).kind, "idle", "a supervisor without operate never takes orders");
  assert.equal(nextAction([item({ contact: contact("needs_correction") })], ME, true).kind, "correction");
  assert.equal(nextAction([item({ commerceClosed: true })], ME, true).kind, "idle");
  assert.equal(nextAction([], ME, true).kind, "idle");
});

test("the final review lists exactly what is missing; payment is independent and never part of the approval", () => {
  const ready = { confirmedLineIds: ["11", "12"], priceReviewed: true };
  assert.deepEqual(approvalMissing(detail(), ready, true), []);
  assert.deepEqual(approvalMissing(detail({ financial: { state: "waiting", basis: null, open: false, attention: null } }), ready, true), [], "an approval is possible while the advance is still missing");
  assert.deepEqual(approvalMissing(detail({ review: { fingerprint: FP, lineIds: ["11", "12"], pricingAvailable: true, callRecorded: false, contactAvailable: true } }), ready, true), ["call"], "no recorded confirmation, no approval");
  assert.deepEqual(approvalMissing(detail(), { confirmedLineIds: ["11"], priceReviewed: true }, true), ["lines"], "each current line must be ticked");
  assert.deepEqual(approvalMissing(detail(), { confirmedLineIds: ["11", "11", "99"], priceReviewed: true }, true), ["lines"], "duplicates or unknown lines never count");
  assert.deepEqual(approvalMissing(detail({ review: { fingerprint: FP, lineIds: [], pricingAvailable: true, callRecorded: true, contactAvailable: true } }), { confirmedLineIds: [], priceReviewed: true }, true), ["lines"]);
  assert.deepEqual(approvalMissing(detail({ review: { fingerprint: FP, lineIds: ["11", "12"], pricingAvailable: false, callRecorded: true, contactAvailable: true } }), ready, true), ["priceEvidence"], "no price evidence, no approval even with the box ticked");
  assert.deepEqual(approvalMissing(detail(), { confirmedLineIds: ["11", "12"], priceReviewed: false }, true), ["priceReviewed"]);
  assert.deepEqual(approvalMissing(detail({ workflowCohort: "undetermined" }), ready, true), ["eligible"]);
  assert.deepEqual(approvalMissing(detail({ review: { fingerprint: FP, lineIds: ["11", "12"], pricingAvailable: true, callRecorded: true, contactAvailable: false } }), ready, true), ["contact"], "no customer telephone number from the shop, no approval");
  assert.deepEqual(approvalMissing(detail({ commerceClosed: true }), ready, true), ["open"]);
  assert.deepEqual(approvalMissing(detail({ status: "approved" }), ready, true), ["pending"]);
  assert.deepEqual(approvalMissing(detail(), ready, false), ["decider"]);
});

test("who decides: the assignee of an operated source or a supervisor of the source", () => {
  assert.equal(canDecide(item(), person()), true);
  assert.equal(canDecide(item({ assignment: null }), person()), false, "take it first");
  assert.equal(canDecide(item({ assignment: { employeeId: OTHER, displayName: "Coleg", at: "2026-10-22T08:00:00Z", kind: "auto" } }), person()), false);
  assert.equal(canDecide(item({ sourceKey: "outletperdele" }), person()), false, "no cross-store decisions");
  assert.equal(canDecide(item({ assignment: null }), person({ confirmareScopes: { operate: [], supervise: ["trendhome"] } })), true);
  assert.equal(canDecide(item({ status: "approved" }), person({ confirmareScopes: { operate: [], supervise: ["trendhome"] } })), false);
});

test("an order changed in the shop drops the ticks; a refresh of the same order keeps them", () => {
  const draft = { confirmedLineIds: ["11", "12"], priceReviewed: true };
  assert.deepEqual(carryDraft({ fingerprint: FP, draft }, detail()), { draft, reset: false });
  assert.deepEqual(carryDraft({ fingerprint: FP, draft }, detail({ review: { fingerprint: "b".repeat(64), lineIds: ["11", "12"], pricingAvailable: true, callRecorded: false, contactAvailable: true } })), { draft: { confirmedLineIds: [], priceReviewed: false }, reset: true });
  assert.deepEqual(carryDraft(null, detail()), { draft: { confirmedLineIds: [], priceReviewed: false }, reset: false });
});

test("strict mapping: no full telephone number survives, unknown outcomes and shapes fail closed", () => {
  const raw = {
    ...item(), contact: { outcome: "no_answer", at: "2026-10-22T09:00:00.000000Z", attempts: 2, current: true },
    review: { fingerprint: FP, lineIds: ["11"], pricingAvailable: true, callRecorded: false, contactAvailable: true },
    lines: [{ lineId: "11", lineNumber: 1, name: "Draperie", productCode: "DR", variant: null, color: "Bej", width: 2, height: 2.6, unit: "m", meters: null, quantity: 1, options: [{ label: "Rejansă", value: "Inele" }], notes: null, confirmed: false }],
    contacts: [{ attempt: 1, outcome: "no_answer", by: "Operator Test", at: "2026-10-22T09:00:00.000000Z", current: true }],
    customer: { visible: true, name: "Client", company: null, addressLines: ["Str. 1"], phoneMasked: "0744123987", phoneAvailable: true },
    source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: "2026-10-22T09:00:00Z", freshness: "fresh" },
    production: { cleared: false, missing: ["approval", "call"] }, events: [],
  };
  const mapped = mapConfirmareDetail(raw);
  assert.equal(mapped.customer.visible && mapped.customer.phoneMasked, null, "a number with more than two digits is never shown");
  assert.equal(mapped.customer.phoneAvailable, false, "a full phone is never available through Arasya");
  assert.deepEqual(mapConfirmareDetail({ ...raw, customer: { visible: false, name: "Client" } }).customer, { visible: false, phoneAvailable: false }, "hidden means nothing is kept");
  assert.equal(mapConfirmareDetail({ ...raw, customer: { ...raw.customer, phoneMasked: "07** *** ***" } }).customer.visible, true);
  // Phase 4B: the shop change state comes with the capabilities; an unknown state fails closed.
  const open = { idempotencyKey: "a".repeat(36), state: "unknown", requestedAt: "2026-10-22T10:00:00Z", mine: true };
  assert.deepEqual(mapConfirmareDetail({ ...raw, sourceAccess: { configured: true, read: true, edit: true, changePending: true, openEdits: [open] } }).sourceAccess, { configured: true, read: true, edit: true, changePending: true, openEdits: [open] });
  assert.deepEqual(mapConfirmareDetail(raw).sourceAccess, { configured: false, read: false, edit: false, changePending: false, openEdits: [] }, "an older API: no shop access, nothing pending");
  assert.throws(() => mapConfirmareDetail({ ...raw, sourceAccess: { configured: true, read: true, edit: true, changePending: true, openEdits: [{ ...open, state: "applied" }] } }));
  const failsClosed = (value: unknown) => assert.throws(() => mapConfirmareDetail(value), (error) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
  failsClosed({ ...raw, contacts: [{ ...raw.contacts[0], outcome: "cancelled" }] });
  failsClosed({ ...raw, review: { ...raw.review, fingerprint: "not-a-fingerprint" } });
  assert.equal(mapConfirmareDetail({ ...raw, review: { ...raw.review, contactAvailable: undefined } }).review.contactAvailable, false, "an unknown contact availability counts as none");
  failsClosed({ ...raw, production: { cleared: false, missing: ["exemption"] } });
  failsClosed({ ...raw, financial: { state: "exempt", basis: null } });
  assert.equal(mapConfirmareItem({ ...item(), financial: { state: "cleared", basis: "online_payment" } }).financial.open, false, "an API without `open` reads as closed");
});

test("HTTP: commands carry the version, review and Idempotency-Key; refusals keep only the code, version and missing conditions", async () => {
  const calls: { path: string; init: RequestInit }[] = [];
  const answers: Response[] = [];
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  const fetchImpl = (async (url: string, init: RequestInit) => { calls.push({ path: new URL(url).pathname + new URL(url).search, init }); return answers.shift()!; }) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  const api = services.confirmare!;
  answers.push(json(200, { contact: { attempt: 1, outcome: "no_answer", by: "Op", at: "2026-10-22T09:00:00Z", current: true }, item: item() }));
  const result = await api.contact("trendhome:1001", { expectedVersion: 3, outcome: "no_answer", reviewFingerprint: FP }, "key-0123456789abcdef");
  assert.equal(result.contact.outcome, "no_answer");
  assert.equal(calls[0].path, "/confirmare/orders/trendhome%3A1001/contact");
  assert.equal(new Headers(calls[0].init.headers).get("Idempotency-Key"), "key-0123456789abcdef");
  assert.deepEqual(JSON.parse(String(calls[0].init.body)), { expectedVersion: 3, outcome: "no_answer", reviewFingerprint: FP });
  answers.push(json(409, { error: { code: "CONFIRMARE_CHANGED", message: "x", details: { currentVersion: 5, customer: "Popescu", phone: "0744123987" } } }));
  const conflict = await api.claim("trendhome:1001", { expectedVersion: 3 }, "key-0123456789abcdef").then(() => null, (error: unknown) => error as StaffServiceError);
  assert.ok(conflict instanceof StaffServiceError);
  assert.equal(conflict.details?.backendCode, "CONFIRMARE_CHANGED");
  assert.equal(conflict.details?.currentVersion, 5);
  assert.equal(JSON.stringify(conflict.details).includes("0744"), false, "nothing else from a refusal is kept");
  assert.deepEqual(confirmareErrorDetails({ missing: ["payment"], currentVersion: 2, extra: "x" }), { currentVersion: 2, missing: ["payment"] });
  answers.push(json(200, { view: "open", items: [], limit: 200, targetOpenPerOperator: 10 }));
  await api.pool("open", "outletperdele");
  assert.equal(calls.at(-1)!.path, "/confirmare/pool?view=open&source=outletperdele", "the source filter is decided by the server");
});

test("HTTP: an expired session or revoked access stops the work; a network outage keeps it", async () => {
  let expired = 0;
  const fetchImpl = (async () => new Response(JSON.stringify({ error: { code: "SESSION_EXPIRED" } }), { status: 401, headers: { "Content-Type": "application/json" } })) as typeof fetch;
  const services = createProductionServices("https://api.arasyahome.ro", { fetchImpl, isOnline: () => true });
  services.auth.onSessionExpired(() => { expired += 1; });
  await assert.rejects(services.confirmare!.pool("mine", null), (error) => error instanceof StaffServiceError && error.code === "SESSION_EXPIRED");
  assert.equal(expired, 1, "the app returns to the sign-in screen");
  const denied = createProductionServices("https://api.arasyahome.ro", { fetchImpl: (async () => new Response(JSON.stringify({ error: { code: "CONFIRMARE_SCOPE_DENIED" } }), { status: 403, headers: { "Content-Type": "application/json" } })) as typeof fetch, isOnline: () => true });
  await assert.rejects(denied.confirmare!.detail("trendhome:1"), (error) => error instanceof StaffServiceError && error.code === "UNAUTHORIZED_ACTION" && confirmareErrorKey(String(error.details?.backendCode)) === "cf.error.CONFIRMARE_SCOPE_DENIED");
  const offline = createProductionServices("https://api.arasyahome.ro", { fetchImpl: (async () => { throw new TypeError("offline"); }) as typeof fetch, isOnline: () => false });
  await assert.rejects(offline.confirmare!.pool("open", null), (error) => error instanceof StaffServiceError && error.code === "NETWORK_UNAVAILABLE");
});

test("refresh reads only: the queue loader asks the pool and nothing else; no screen records a call by itself", async () => {
  const called: string[] = [];
  const api = new Proxy({}, { get: (_target, name: string) => (...args: unknown[]) => { called.push(name); return Promise.resolve(name === "pool" ? { view: args[0], items: [item({ globalOrderId: `trendhome:${String(args[0])}` })], limit: 200, targetOpenPerOperator: 10 } : null); } }) as never;
  const data = await confirmareQueueLoader(api, null)(new AbortController().signal);
  assert.deepEqual(called, ["pool", "pool"]);
  assert.deepEqual(data.items.map((row) => row.globalOrderId), ["trendhome:open", "trendhome:followup"]);
  const sources = ["ConfirmareParts.tsx", "ConfirmareWorkspace.tsx", "OperatorDesk.tsx", "ConfirmareTeamScreen.tsx", "ConfirmareOrderScreen.tsx"].map((file) => [file, readFileSync(path.join(root, "features/confirmare", file), "utf8")] as const);
  for (const [file, source] of sources) {
    assert.doesNotMatch(source, /tel:|href=\{?["'`]tel/, `${file}: no telephone link that could be mistaken for a call`);
    for (const effect of source.matchAll(/useEffect\(\(\) => \{([^]*?)\}, \[/g)) {
      assert.doesNotMatch(effect[1], /service\.(contact|approve|reject|claim|release|reassign|reopen|next|setAvailability)\(/, `${file}: no command runs from an effect`);
    }
  }
  const order = sources.find(([file]) => file === "ConfirmareOrderScreen.tsx")![1];
  assert.match(order, /onSubmit=\{\(event\) => \{[^]*?if \(!outcome \|\| !callHappened\) return;[^]*?service\.contact\(/, "a call is recorded only by the explicit form with an outcome and the 'call happened' confirmation");
  assert.match(order, /priceVerified: true as const/);
  assert.match(order, /disabled=\{busy !== null \|\| missing\.length > 0\}/, "the approval stays disabled until every condition is met");
  const loaders = sources.filter(([file]) => file === "ConfirmareParts.tsx" || file === "ConfirmareTeamScreen.tsx").map(([, source]) => source.slice(source.indexOf("Loader("), source.indexOf("Loader(") + 700)).join("\n");
  assert.doesNotMatch(loaders, /\.(contact|approve|reject|claim|release|reassign|reopen|next|setAvailability)\(/, "refresh loaders never change anything");
});

test("Romanian statuses and every Confirmare text exist in all four languages", () => {
  const ro = CATALOGS.ro as Record<string, unknown>;
  assert.equal(ro["cf.status.confirmed"], "Confirmat");
  assert.equal(ro["cf.status.noAnswer"], "Nu răspunde");
  assert.equal(ro["cf.status.callback"], "Reapelează");
  assert.equal(ro["cf.status.needsCorrection"], "Necesită corectare");
  assert.equal(ro["cf.status.awaitingPayment"], "În așteptarea plății");
  const keys = Object.keys(ro).filter((key) => key.startsWith("cf.") || key.startsWith("workspace.confirmare."));
  assert.ok(keys.length > 200, `Confirmare keys: ${keys.length}`);
  for (const locale of ["tr", "en", "si"] as const) for (const key of keys) assert.ok((CATALOGS[locale] as Record<string, unknown>)[key], `${locale}: ${key as MessageKey}`);
});

test("home: an operator with a scope sees the Confirmare workspace and the honest contact-data dependency", () => {
  const pending = new Promise<never>(() => undefined);
  const confirmare = new Proxy({}, { get: () => () => pending }) as never;
  const services = { mode: "production", confirmare, exceptions: { supervisorList: () => pending, capabilities: () => pending, listMine: () => pending } } as unknown as ServiceBundle;
  const html = renderToStaticMarkup(createElement(HomeScreen, { employee: person(), services, workflow: { id: "w", name: "w", version: 1, stages: [] }, dashboardUrl: null, navigate: () => undefined }));
  assert.match(html, /data-testid="workspace-confirmare"/);
  assert.match(html, /Confirmare comenzi/);
  assert.match(html, /data-testid="cf-contact-dependency"/);
  assert.match(html, /Arasya nu păstrează telefonul complet al clientului/);
});
