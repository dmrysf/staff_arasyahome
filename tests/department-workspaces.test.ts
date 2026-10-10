import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { Employee, StaffOrder } from "../domain/models";
import { CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE } from "../domain/canonicalProductionWorkflow";
import { canUseManagementWorkspace, dashboardUrlFor, firstName, productionDepartments, readWorkspacePreference, resolveWorkspaces, saveWorkspacePreference, selectPrimaryWorkspace, stageGuides } from "../domain/workspaces";
import { mapProductionOverview, mapStageQueue, mapStageSummary, mapTrendyolActivity } from "../services/production/httpServices";
import { queueStateOf } from "../features/workspaces/StageWorkspace";
import { connectionCopy, lastRunFailed, linesToComplete } from "../features/workspaces/TrendyolDashboard";
import { formatMetric } from "../features/workspaces/DashboardParts";
import { HomeScreen } from "../features/home/HomeScreen";
import { StaffServiceError } from "../domain/models";
import type { ServiceBundle } from "../services/contracts";
import type { TrendyolPackageSummary } from "../domain/trendyol";

const STAFF = ["orders.scan", "orders.view_mine", "orders.claim", "orders.advance_stage", "orders.handover", "history.view_mine", "profile.view_self"];
const all = { trendyol: true, documents: true, management: true };
const person = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: "00000000-0000-4000-8000-000000000001", displayName: "Ana Popescu", username: "ana", department: "Tăiere", departmentKey: "taiere",
  role: "employee", status: "active", permissions: STAFF, allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro", ...overrides,
});
const ids = (employee: Employee, available = all) => resolveWorkspaces(employee, available).map((workspace) => workspace.id);
const primary = (employee: Employee, preference: string | null = null) => selectPrimaryWorkspace(resolveWorkspaces(employee, all), employee, preference)?.id;

test("the stage grouping covers each of the fourteen canonical stages exactly once and has guidance for every stage", () => {
  const grouped = productionDepartments.flatMap((department) => [...department.stageIds]);
  assert.deepEqual(grouped, CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.map((stage) => stage.id));
  for (const stage of CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE) assert.ok(stageGuides[stage.id]?.checks.length, stage.id);
  const employeeFacing = JSON.stringify([productionDepartments.map((department) => [department.label, department.description]), Object.values(stageGuides)]);
  for (const stage of CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE) assert.ok(!employeeFacing.includes(stage.id), `no technical stage id in employee copy: ${stage.id}`);
});

test("a single-stage employee gets exactly that production workspace, and only the granted stages appear", () => {
  assert.deepEqual(ids(person({ allowedStageIds: ["material-preparation"] })), ["taiere"]);
  const sewing = resolveWorkspaces(person({ departmentKey: "croitorie", allowedStageIds: ["header-tape", "bottom-hem", "side-hem"] }), all);
  assert.deepEqual(sewing.map((workspace) => workspace.id), ["croitorie"]);
  assert.deepEqual(sewing[0].stageIds, ["bottom-hem", "side-hem", "header-tape"], "one sewing workspace with the granted stages in workflow order");
});

test("several departments are separate workspaces; the first one is deterministic", () => {
  const employee = person({ departmentKey: "pregatire-material", allowedStageIds: ["waiting", "material-preparation", "ironing"] });
  assert.deepEqual(ids(employee), ["pornire", "taiere", "croitorie"]);
  assert.equal(primary(employee), "taiere", "the IAM department key is a hint among authorized workspaces");
  assert.equal(primary(person({ departmentKey: "necunoscut", allowedStageIds: ["waiting", "ironing"] })), "pornire", "unknown department: registry order");
  assert.equal(primary(employee, "croitorie"), "croitorie", "a still-authorized saved choice wins");
});

test("Trendyol access follows only the Trendyol permission inside Staff, never the department metadata", () => {
  const trendyol = person({ department: "Vânzări Online", departmentKey: "vanzari-online", permissions: [...STAFF, "trendyol.orders.view", "trendyol.orders.prepare"] });
  assert.deepEqual(ids(trendyol), ["trendyol"]);
  assert.equal(primary(trendyol), "trendyol");
  assert.deepEqual(ids(person({ departmentKey: "vanzari-online", allowedStageIds: [] })), ["general"], "the department name alone never opens Trendyol");
  assert.deepEqual(ids(person({ departmentKey: "taiere", permissions: [...STAFF, "trendyol.orders.view"], applications: ["dashboard"] })), [], "no Staff application, no workspace");
  assert.deepEqual(ids(trendyol, { ...all, trendyol: false }), ["general"], "no Trendyol service in this runtime, no Trendyol workspace");
  const withWaiting = person({ departmentKey: "vanzari-online", allowedStageIds: ["waiting"], permissions: [...STAFF, "trendyol.orders.view"] });
  assert.deepEqual(ids(withWaiting), ["trendyol", "pornire"], "Trendyol intake plus the stage-1 handoff");
  assert.deepEqual(ids(person({ allowedStageIds: ["material-preparation"] })).includes("trendyol"), false, "a cutting employee has no Trendyol workspace");
});

test("management needs Dashboard access and production.view; root follows the same data", () => {
  const manager = person({ departmentKey: "operatiuni", applications: ["staff", "dashboard"], permissions: [...STAFF, "production.view"], allowedStageIds: ["quality-control"] });
  assert.ok(canUseManagementWorkspace(manager));
  assert.deepEqual(ids(manager), ["control-calitate", "management"]);
  assert.equal(primary(manager), "management");
  assert.deepEqual(ids(person({ applications: ["staff", "dashboard"], permissions: [...STAFF] })), ["general"], "Dashboard access without production.view is not management");
  assert.deepEqual(ids(person({ applications: ["staff"], permissions: [...STAFF, "production.view"] })), ["general"], "production.view without Dashboard access is not management");
  const root = person({ isRoot: true, applications: ["staff", "dashboard", "b2b"], permissions: [...STAFF, "production.view", "trendyol.orders.view", "production.documents.generate"], allowedStageIds: [] });
  assert.deepEqual(ids(root), ["trendyol", "documente", "management"], "root gets no stage workspace without stage grants");
});

test("inactive, temporary-password and Staff-less accounts resolve to nothing; minimal accounts get the safe fallback", () => {
  assert.deepEqual(ids(person({ status: "inactive", allowedStageIds: ["packing"] })), []);
  assert.deepEqual(ids(person({ mustChangePassword: true, allowedStageIds: ["packing"] })), []);
  assert.deepEqual(ids(person({ applications: ["b2b"], allowedStageIds: ["packing"] })), []);
  assert.deepEqual(ids(person({ permissions: ["profile.view_self"], allowedStageIds: ["packing"] })), ["general"], "a stage without orders.view_mine is not a workspace");
  assert.equal(selectPrimaryWorkspace([], person(), "taiere"), null);
});

test("a saved preference never adds access and survives the loss of the workspace safely", () => {
  const cutter = person({ allowedStageIds: ["material-preparation"] });
  assert.equal(primary(cutter, "trendyol"), "taiere", "an unauthorized saved choice is ignored");
  assert.equal(primary(cutter, "management"), "taiere");
  const store = new Map<string, string>();
  const storage = { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => { store.set(key, value); } };
  saveWorkspacePreference(cutter.employeeUuid, "trendyol", storage);
  assert.equal(readWorkspacePreference(cutter.employeeUuid, storage), "trendyol");
  assert.equal(readWorkspacePreference("other", storage), null, "per employee");
  store.set(`arasya_staff_workspace:${cutter.employeeUuid}`, "<script>");
  assert.equal(readWorkspacePreference(cutter.employeeUuid, storage), null, "malformed values are ignored");
  const broken = { getItem: () => { throw new Error("blocked"); }, setItem: () => { throw new Error("blocked"); } };
  assert.equal(readWorkspacePreference(cutter.employeeUuid, broken), null);
  assert.doesNotThrow(() => saveWorkspacePreference(cutter.employeeUuid, "taiere", broken));
});

test("stage queue, summary, activity and overview answers are mapped strictly", () => {
  const order = { id: "trendhome:1", source: "trendhome", orderNumber: "1", productionStageId: "height", products: [], updatedAt: "2026-10-10T08:00:00Z", status: "in_progress", version: 1, productionVersion: 1, employeeAllowedAction: { id: "claim", label: "Preia" } };
  const queue = mapStageQueue({ stageId: "height", counts: { total: 3, mine: 1, available: 1, blocked: 0, claimedByOthers: 1 }, countsComplete: true, items: [order] });
  assert.equal(queue.counts.total, 3);
  assert.equal(queue.items[0].id, "trendhome:1");
  for (const bad of [{}, { stageId: "height", counts: { total: -1 }, countsComplete: true, items: [] }, { stageId: "height", counts: { total: 1, mine: 0, available: 0, blocked: 0, claimedByOthers: 0 }, countsComplete: "yes", items: [] }]) {
    assert.throws(() => mapStageQueue(bad), (error) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
  }
  assert.deepEqual(mapStageSummary({ stages: [{ stageId: "height", total: 2, mine: 1 }] }), [{ stageId: "height", total: 2, mine: 1 }]);
  assert.throws(() => mapStageSummary({ stages: [{ stageId: "height", total: 1.5, mine: 0 }] }));
  const activity = mapTrendyolActivity({ today: { linesPrepared: 2, released: 1, dismissed: 0, reopened: 0 }, recent: [{ action: "released", packageId: "3318470214", orderNumber: "10847291463", at: "2026-10-10T08:00:00Z" }] });
  assert.equal(activity.recent[0].action, "released");
  assert.throws(() => mapTrendyolActivity({ today: { linesPrepared: 0, released: 0, dismissed: 0, reopened: 0 }, recent: [{ action: "deleted", packageId: null, orderNumber: null, at: null }] }));
  const overview = mapProductionOverview({ generatedAt: "2026-10-10T08:00:00Z", summary: { active: 4, waiting: 1, inWork: 3, unassigned: 2, completedToday: 0 }, stages: [{ id: "waiting", label: "În așteptare", ordinal: 1, active: 1, unassigned: 1, oldestEnteredAt: null }] });
  assert.equal(overview.summary.active, 4);
  assert.throws(() => mapProductionOverview({ generatedAt: "2026-10-10T08:00:00Z", summary: {}, stages: [] }));
});

test("queue states come only from the server-evaluated action; metrics never invent numbers", () => {
  const order = (action?: string, blocked?: string) => ({ employeeAllowedAction: action ? { id: action, label: "" } : undefined, employeeActionBlockedReason: blocked }) as unknown as StaffOrder;
  assert.equal(queueStateOf(order("complete_stage")), "mine");
  assert.equal(queueStateOf(order("complete_production")), "mine");
  assert.equal(queueStateOf(order("claim")), "available");
  assert.equal(queueStateOf(order(undefined, "claimed_by_other")), "claimedByOthers");
  assert.equal(queueStateOf(order(undefined, "exception_pending")), "blocked");
  assert.equal(formatMetric("loading"), "…");
  assert.equal(formatMetric("unavailable"), "—");
  assert.equal(formatMetric(0), "0");
  assert.equal(formatMetric(200, true), "200+");
  const pending = [{ lineCount: 3, preparedCount: 1 }, { lineCount: 2, preparedCount: 2 }, { lineCount: undefined, preparedCount: undefined }] as unknown as TrendyolPackageSummary[];
  assert.equal(linesToComplete(pending), 2);
  assert.equal(lastRunFailed(null), false);
  assert.equal(lastRunFailed("ok"), false);
  assert.equal(lastRunFailed("truncated"), false);
  assert.equal(lastRunFailed("TRENDYOL_RATE_LIMITED"), true);
  assert.match(connectionCopy.inactive.detail, /nu este activată/);
});

test("names and links: first name only, Dashboard link only for an https api host", () => {
  assert.equal(firstName("Ayşe Hopcu"), "Ayşe");
  assert.equal(firstName("  Ana  "), "Ana");
  assert.equal(dashboardUrlFor("https://api.arasyahome.ro"), "https://dashboard.arasyahome.ro/");
  assert.equal(dashboardUrlFor("http://127.0.0.1:8787"), null);
  assert.equal(dashboardUrlFor("not a url"), null);
});

const unused = () => Promise.reject(new StaffServiceError("SERVICE_UNAVAILABLE"));
const services = (extra: Partial<ServiceBundle> = {}): ServiceBundle => ({
  auth: {} as ServiceBundle["auth"], employee: {} as ServiceBundle["employee"], workflow: {} as ServiceBundle["workflow"], live: { subscribe: () => () => undefined },
  orders: { listMine: unused, getById: unused, lookup: unused, resolveQr: unused, claim: unused, confirmStageTransition: unused },
  activity: { listMine: unused }, exceptions: { listMine: unused, get: unused, reasons: unused, report: unused, acknowledge: unused, rereview: unused },
  workspace: { stageQueue: unused, stageSummary: unused }, management: { productionOverview: unused },
  trendyol: { overview: unused, activity: unused, list: unused, ignored: unused, detail: unused, prepareLine: unused, dismiss: unused, reopen: unused, release: unused },
  mode: "production", ...extra,
});
const workflow = { id: "curtain-production", name: "Flux", version: 1, stages: CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.map((stage) => ({ id: stage.id, ordinal: stage.ordinal, label: `Etapa ${stage.ordinal}` })) };
const render = (employee: Employee, bundle = services()) => renderToStaticMarkup(createElement(HomeScreen, { employee, services: bundle, workflow, dashboardUrl: null, navigate: () => undefined }));

test("the home renders the authorized workspace with Romanian copy and only the permitted controls", () => {
  const trendyol = render(person({ displayName: "Ayşe Test", department: "Vânzări Online", departmentKey: "vanzari-online", permissions: [...STAFF, "trendyol.orders.view"] }));
  assert.match(trendyol, /Bună, Ayşe!/);
  assert.match(trendyol, /Departament: Vânzări Online/);
  assert.match(trendyol, /Operațiuni Trendyol/);
  assert.match(trendyol, /Comenzi de pregătit/);
  assert.match(trendyol, /Linii de completat/);
  assert.ok(!trendyol.includes("workspace-switcher"), "one workspace, no switcher");
  assert.ok(!/>\d+</.test(trendyol.replace(/Etapa \d+/g, "")), "no number is shown before the server answers");

  const cutter = render(person({ allowedStageIds: ["material-preparation"] }));
  assert.match(cutter, /Tăiere/);
  assert.match(cutter, /Scanează comanda/);
  assert.ok(!cutter.includes("Trendyol"), "a cutting employee sees nothing of Trendyol");

  const noScan = render(person({ permissions: ["orders.view_mine", "profile.view_self"], allowedStageIds: ["packing"] }));
  assert.ok(!noScan.includes("Scanează comanda"), "no scanner action without orders.scan");

  const multi = render(person({ departmentKey: "necunoscut", allowedStageIds: ["waiting", "packing"] }));
  assert.match(multi, /aria-label="Spațiile mele de lucru"/);
  assert.match(multi, /aria-pressed="true"[^>]*><strong>Pornire producție/);

  const sewing = render(person({ departmentKey: "croitorie", allowedStageIds: ["bottom-hem", "side-hem", "header-tape"] }));
  assert.match(sewing, /role="tablist" aria-label="Etapele mele"/);
  assert.equal((sewing.match(/role="tab"/g) ?? []).length, 3, "only the employee's three stages");

  const fallback = render(person({ allowedStageIds: [] }));
  assert.match(fallback, /Nu ai încă un spațiu de lucru operațional/);

  const preview = render(person({ allowedStageIds: ["material-preparation"] }), services({ workspace: undefined, mode: "preview" }));
  assert.match(preview, /Coada etapei nu este disponibilă în acest mod/);
});
