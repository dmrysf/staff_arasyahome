import type { Employee, StaffOrder } from "./models";
import { canUseTrendyolWorkspace } from "./trendyol";
import { canUseConfirmare } from "./confirmare";
import { t, type MessageKey } from "../i18n";

/**
 * Department dashboards. A workspace is a presentation of work the employee is already authorized for; it never
 * grants access. Every list and count comes from an API call the server authorizes on its own (stage access,
 * source-scoped Trendyol permission, Dashboard application and `production.view` for the management overview).
 *
 * Departments are not stages: one production workspace groups several canonical stages, and an employee sees only
 * the stages central IAM assigned to them. The department name shown in the header is the real IAM department;
 * the IAM department key is only a hint for which authorized workspace opens first.
 */
export type WorkspaceKind = "confirmare" | "trendyol" | "production" | "documents" | "management" | "general";

export type Workspace = {
  id: string;
  kind: WorkspaceKind;
  /** Workspace title, e.g. "Tăiere" or "Operațiuni Trendyol". */
  label: string;
  /** Short subtitle for the switcher. */
  description: string;
  /** Authorized canonical stages, in workflow order (production workspaces only). */
  stageIds: string[];
};

export type ProductionDepartment = { id: string; label: string; description: string; stageIds: readonly string[] };

/**
 * Explicit, tested grouping of the fourteen canonical stages (curtain-production@1) into production workspaces.
 * Pure presentation: the employee's stage grants decide which stages of a group appear.
 */
const DEPARTMENT_STAGES: readonly [string, readonly string[]][] = [
  ["pornire", ["waiting"]],
  ["taiere", ["material-preparation"]],
  ["atelier", ["workshop-receiving", "labeling", "material-straightening"]],
  ["croitorie", ["bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing"]],
  ["control-calitate", ["quality-control"]],
  ["ambalare", ["packing"]],
  ["livrare", ["delivery"]],
];
export const productionDepartments: readonly ProductionDepartment[] = Object.freeze(DEPARTMENT_STAGES.map(([id, stageIds]) => Object.freeze({
  id,
  get label() { return t(`workspace.${id}.label` as MessageKey); },
  get description() { return t(`workspace.${id}.description` as MessageKey); },
  stageIds,
})));

/** Stage guidance shown on the stage dashboards: instructions only, every action stays on the order screen. */
const GUIDE_CHECKS: Readonly<Record<string, number>> = Object.freeze({
  "waiting": 3, "material-preparation": 4, "workshop-receiving": 2, "labeling": 2, "material-straightening": 1, "bottom-hem": 2, "side-hem": 1,
  "ironing": 1, "height": 1, "header-tape": 1, "sewing-finishing": 1, "quality-control": 2, "packing": 2, "delivery": 2,
});
export const stageGuides: Readonly<Record<string, { focus: string; checks: readonly string[] }>> = Object.freeze(Object.fromEntries(Object.entries(GUIDE_CHECKS).map(([stageId, count]) => [stageId, Object.freeze({
  get focus() { return t(`guide.${stageId}.focus` as MessageKey); },
  get checks() { return Array.from({ length: count }, (_, index) => t(`guide.${stageId}.${index + 1}` as MessageKey)); },
})])));

const DOCUMENT_PERMISSIONS = ["production.documents.generate", "production.documents.request_revision"];

/**
 * Presentation hint only: which already-authorized workspace opens first for an IAM department, in order (the first
 * one the employee holds wins). Online sales confirm new shop orders first, then Trendyol.
 */
const departmentHints: Readonly<Record<string, readonly string[]>> = Object.freeze({
  "taiere": ["taiere"],
  "pregatire-material": ["taiere"],
  "croitorie": ["croitorie"],
  "croitorie-dragon": ["croitorie"],
  "vanzari-online": ["confirmare", "trendyol"],
  "expediere-curierat": ["ambalare"],
  "depozit": ["ambalare"],
  "logistica-distributie": ["livrare"],
  "operatiuni": ["management"],
  "conducere": ["management"],
});

/** A workspace without stages; its title and subtitle are read in the active language. */
function fixedWorkspace(id: "confirmare" | "trendyol" | "documente" | "management" | "general", kind: WorkspaceKind): Workspace {
  return { id, kind, get label() { return t(`workspace.${id}.label`); }, get description() { return t(`workspace.${id}.description`); }, stageIds: [] };
}

export type WorkspaceAvailability = { trendyol: boolean; documents: boolean; management: boolean; confirmare?: boolean };

export function canUseManagementWorkspace(employee: Employee): boolean {
  return employee.applications.includes("dashboard") && employee.permissions.includes("production.view");
}

/**
 * The workspaces this employee may open, in a fixed order. `available` tells which optional services exist in the
 * current runtime (preview and demo have no Trendyol, document or management API).
 */
export function resolveWorkspaces(employee: Employee, available: WorkspaceAvailability): Workspace[] {
  if (employee.status !== "active" || !employee.applications.includes("staff") || employee.mustChangePassword) return [];
  const workspaces: Workspace[] = [];
  if (available.confirmare && canUseConfirmare(employee)) {
    workspaces.push(fixedWorkspace("confirmare", "confirmare"));
  }
  if (available.trendyol && canUseTrendyolWorkspace(employee)) {
    workspaces.push(fixedWorkspace("trendyol", "trendyol"));
  }
  if (employee.permissions.includes("orders.view_mine")) {
    for (const department of productionDepartments) {
      const stageIds = department.stageIds.filter((stageId) => employee.allowedStageIds.includes(stageId));
      if (stageIds.length > 0) workspaces.push({ id: department.id, kind: "production", get label() { return department.label; }, get description() { return department.description; }, stageIds });
    }
  }
  if (available.documents && DOCUMENT_PERMISSIONS.some((permission) => employee.permissions.includes(permission))) {
    workspaces.push(fixedWorkspace("documente", "documents"));
  }
  if (available.management && canUseManagementWorkspace(employee)) {
    workspaces.push(fixedWorkspace("management", "management"));
  }
  if (workspaces.length === 0) {
    workspaces.push(fixedWorkspace("general", "general"));
  }
  return workspaces;
}

/**
 * Deterministic first workspace: a saved preference that is still authorized, then the management overview,
 * then the IAM department hint, then the fixed registry order. A preference never adds a workspace.
 */
export function selectPrimaryWorkspace(workspaces: readonly Workspace[], employee: Employee, preference: string | null): Workspace | null {
  if (workspaces.length === 0) return null;
  const byId = (id: string | null | undefined) => (id ? workspaces.find((workspace) => workspace.id === id) : undefined);
  return byId(preference)
    ?? byId("management")
    ?? (departmentHints[employee.departmentKey ?? ""] ?? []).map(byId).find((workspace) => workspace !== undefined)
    ?? workspaces[0];
}

const PREFERENCE_PREFIX = "arasya_staff_workspace:";
type PreferenceStorage = Pick<Storage, "getItem" | "setItem">;

function browserStorage(): PreferenceStorage | undefined {
  try { return globalThis.localStorage; }
  catch { return undefined; }
}

/** The last opened workspace, per employee. Navigation state only, validated against the resolved list on read. */
export function readWorkspacePreference(employeeUuid: string, storage: PreferenceStorage | undefined = browserStorage()): string | null {
  try {
    const value = storage?.getItem(PREFERENCE_PREFIX + employeeUuid) ?? null;
    return value && /^[a-z][a-z-]{0,40}$/.test(value) ? value : null;
  } catch { return null; }
}

export function saveWorkspacePreference(employeeUuid: string, workspaceId: string, storage: PreferenceStorage | undefined = browserStorage()): void {
  try { storage?.setItem(PREFERENCE_PREFIX + employeeUuid, workspaceId); }
  catch { /* The dashboard still works without browser storage. */ }
}

/** Sources a granted stage is narrowed to, or null when the grant covers every source (presentation only). */
export function stageSources(employee: Employee, stageId: string): readonly string[] | null {
  return employee.stageSourceScopes?.[stageId] ?? null;
}

export function firstName(displayName: string): string {
  return displayName.trim().split(/\s+/)[0] || displayName;
}

/** Server-evaluated stage queue of one stage (GET /orders/stage-queue). */
export type StageQueue = {
  stageId: string;
  counts: { total: number; mine: number; available: number; blocked: number; claimedByOthers: number };
  /** False when the stage holds more open orders than one request reads; the bucket counts are then partial. */
  countsComplete: boolean;
  items: StaffOrder[];
  /** Pieces in rework at this stage (API 2.31.0); empty from older APIs. */
  trackItems: { trackId: string; order: StaffOrder }[];
};

/** reworkTotal / reworkMine: pieces in rework at the stage, counted apart from orders (API 2.31.0; 0 from older APIs). */
export type StageSummary = { stageId: string; total: number; mine: number; reworkTotal: number; reworkMine: number }[];

export type WorkspaceApi = {
  stageQueue(stageId: string, signal?: AbortSignal): Promise<StageQueue>;
  stageSummary(signal?: AbortSignal): Promise<StageSummary>;
};

/** Read-only production overview of the management workspace (GET /management/production-overview). */
export type ProductionOverview = {
  generatedAt: string;
  summary: { active: number; waiting: number; inWork: number; unassigned: number; completedToday: number };
  stages: { id: string; label: string; ordinal: number; active: number; unassigned: number; oldestEnteredAt: string | null }[];
};

export type ManagementApi = { productionOverview(signal?: AbortSignal): Promise<ProductionOverview> };

/** Dashboard application link derived from the API host (api.example → dashboard.example); none otherwise. */
export function dashboardUrlFor(apiBaseUrl: string): string | null {
  try {
    const url = new URL(apiBaseUrl);
    if (url.protocol !== "https:" || !url.hostname.startsWith("api.")) return null;
    return `https://dashboard.${url.hostname.slice(4)}/`;
  } catch { return null; }
}
