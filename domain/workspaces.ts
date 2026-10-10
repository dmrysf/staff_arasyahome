import type { Employee, StaffOrder } from "./models";
import { canUseTrendyolWorkspace } from "./trendyol";

/**
 * Department dashboards. A workspace is a presentation of work the employee is already authorized for; it never
 * grants access. Every list and count comes from an API call the server authorizes on its own (stage access,
 * source-scoped Trendyol permission, Dashboard application and `production.view` for the management overview).
 *
 * Departments are not stages: one production workspace groups several canonical stages, and an employee sees only
 * the stages central IAM assigned to them. The department name shown in the header is the real IAM department;
 * the IAM department key is only a hint for which authorized workspace opens first.
 */
export type WorkspaceKind = "trendyol" | "production" | "documents" | "management" | "general";

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
export const productionDepartments: readonly ProductionDepartment[] = Object.freeze([
  { id: "pornire", label: "Pornire producție", description: "Comenzi aprobate, predare la tăiere", stageIds: ["waiting"] },
  { id: "taiere", label: "Tăiere", description: "Tăiere și transferuri", stageIds: ["material-preparation"] },
  { id: "atelier", label: "Atelier · pregătire", description: "Primire, etichetare, îndreptare", stageIds: ["workshop-receiving", "labeling", "material-straightening"] },
  { id: "croitorie", label: "Croitorie", description: "Tivuri, călcare, înălțime, rejansă, finisare", stageIds: ["bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing"] },
  { id: "control-calitate", label: "Control calitate", description: "Verificare înainte de împachetare", stageIds: ["quality-control"] },
  { id: "ambalare", label: "Împachetare", description: "Ambalare și verificarea produselor", stageIds: ["packing"] },
  { id: "livrare", label: "Livrare", description: "Etapa internă finală", stageIds: ["delivery"] },
]);

/** Stage guidance shown on the stage dashboards: instructions only, every action stays on the order screen. */
export const stageGuides: Readonly<Record<string, { focus: string; checks: readonly string[] }>> = Object.freeze({
  "waiting": { focus: "Comenzi intrate în producție care așteaptă predarea la tăiere.", checks: ["Verifică sursa comenzii și documentul de producție activ.", "Preia comanda, apoi finalizează etapa pentru a o trimite la Tăiere."] },
  "material-preparation": { focus: "Comenzi gata de tăiere. Preluarea se face numai cu eticheta QR originală.", checks: ["Scanează eticheta QR originală a comenzii.", "Verifică măsurile și consumul din documentul de producție.", "Pentru boală sau indisponibilitate folosește transferul întregii comenzi."] },
  "workshop-receiving": { focus: "Comenzi sosite de la tăiere în croitorie.", checks: ["Verifică produsele primite față de documentul de producție.", "Dacă tăierea este greșită, folosește «Returnează la Tăiere» din comandă."] },
  "labeling": { focus: "Comenzi de etichetat.", checks: ["Verifică produsele și cantitățile.", "Etichetează conform documentului de producție."] },
  "material-straightening": { focus: "Material de îndreptat.", checks: ["Verifică dimensiunile din document înainte de lucru."] },
  "bottom-hem": { focus: "Tivul de jos.", checks: ["Respectă înălțimea confirmată din documentul de producție.", "Semnalează orice neconcordanță înainte de finalizare."] },
  "side-hem": { focus: "Tivul lateral.", checks: ["Respectă lățimea confirmată din documentul de producție."] },
  "ironing": { focus: "Produse de călcat.", checks: ["Verifică notele de producție înainte de călcare."] },
  "height": { focus: "Reglarea înălțimii.", checks: ["Folosește numai înălțimea confirmată din documentul de producție. Măsurile nu se modifică în aplicație."] },
  "header-tape": { focus: "Coaserea rejansei.", checks: ["Verifică tipul de rejansă din notele de producție."] },
  "sewing-finishing": { focus: "Finisarea coaserii.", checks: ["Verifică instrucțiunile de finisare din document."] },
  "quality-control": { focus: "Produse de verificat înainte de împachetare.", checks: ["Compară produsul cu documentul de producție: măsuri, cantitate, culoare.", "Predă la Împachetare numai produsele conforme."] },
  "packing": { focus: "Comenzi de împachetat.", checks: ["Verifică produsele și cantitățile înainte de ambalare.", "Ambalarea Arasya nu creează etichete de curier în Trendyol sau în magazine."] },
  "delivery": { focus: "Etapa internă finală a producției.", checks: ["Finalizarea etapei Livrare încheie producția în Arasya.", "Statusurile din Trendyol și din magazine nu se schimbă din Arasya."] },
});

const DOCUMENT_PERMISSIONS = ["production.documents.generate", "production.documents.request_revision"];

/** Presentation hint only: which already-authorized workspace opens first for an IAM department. */
const departmentHints: Readonly<Record<string, string>> = Object.freeze({
  "taiere": "taiere",
  "pregatire-material": "taiere",
  "croitorie": "croitorie",
  "croitorie-dragon": "croitorie",
  "vanzari-online": "trendyol",
  "expediere-curierat": "ambalare",
  "depozit": "ambalare",
  "logistica-distributie": "livrare",
  "operatiuni": "management",
  "conducere": "management",
});

export type WorkspaceAvailability = { trendyol: boolean; documents: boolean; management: boolean };

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
  if (available.trendyol && canUseTrendyolWorkspace(employee)) {
    workspaces.push({ id: "trendyol", kind: "trendyol", label: "Operațiuni Trendyol", description: "Pregătire și aprobare comenzi", stageIds: [] });
  }
  if (employee.permissions.includes("orders.view_mine")) {
    for (const department of productionDepartments) {
      const stageIds = department.stageIds.filter((stageId) => employee.allowedStageIds.includes(stageId));
      if (stageIds.length > 0) workspaces.push({ id: department.id, kind: "production", label: department.label, description: department.description, stageIds });
    }
  }
  if (available.documents && DOCUMENT_PERMISSIONS.some((permission) => employee.permissions.includes(permission))) {
    workspaces.push({ id: "documente", kind: "documents", label: "Documente de producție", description: "Revizii, generare și tipărire", stageIds: [] });
  }
  if (available.management && canUseManagementWorkspace(employee)) {
    workspaces.push({ id: "management", kind: "management", label: "Conducere producție", description: "Privire de ansamblu, numai citire", stageIds: [] });
  }
  if (workspaces.length === 0) {
    workspaces.push({ id: "general", kind: "general", label: "Panoul meu", description: "Activitatea mea", stageIds: [] });
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
    ?? byId(departmentHints[employee.departmentKey ?? ""])
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
};

export type StageSummary = { stageId: string; total: number; mine: number }[];

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
