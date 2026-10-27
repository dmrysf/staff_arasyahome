import type { Employee } from "./models";
import type { StaffRoute } from "./staffRoute";
import type { Workspace } from "./workspaces";
import { canUseManagementWorkspace } from "./workspaces";
import { canHandOff, confirmareScopesOf, financeScopesOf, sourceLabel } from "./confirmare";
import { canUseShopTasks, canWatchHealth } from "./confirmareOps";
import { canManageAuthority } from "./authority";
import { canUseCuttingIntake } from "./cutting";
import { FLOW_STEPS, GUIDE_LANGS, guideTopics, scopeLabels, stageLines, type FlowStep, type GuideLang, type GuideLine, type GuideNeed, type GuideText, type GuideTopicId } from "../i18n/messages/guide";
import type { Locale } from "../i18n";

/**
 * Contextual employee guide (Staff 2.38.0). Pure presentation: it picks the explanation of the screen the employee is
 * on and keeps only the lines whose capability the session grants. It reads nothing but the session and the route,
 * never calls the API and never grants or performs anything; the server still checks every action on its own.
 */
export type { GuideLang, GuideText, GuideTopicId };

/** Shared runtime facts the guide may mention (a Dashboard link exists only in production). */
export type GuideContext = { dashboardLink: boolean };

export function guideCapabilities(employee: Employee, context: GuideContext): ReadonlySet<GuideNeed> {
  const has = (permission: string) => employee.permissions.includes(permission);
  const confirmare = confirmareScopesOf(employee);
  const finance = financeScopesOf(employee);
  const invoice = employee.invoiceScopes;
  const needs: [GuideNeed, boolean][] = [
    ["scan", has("orders.scan")],
    ["claim", has("orders.claim")],
    ["advance", has("orders.advance_stage")],
    ["reportFault", has("orders.report_fault")],
    ["cuttingIntake", canUseCuttingIntake(employee)],
    ["cfOperate", confirmare.operate.length > 0],
    ["cfSupervise", confirmare.supervise.length > 0],
    ["finRecord", finance.record.length > 0],
    ["finApprove", finance.approve.length > 0],
    ["finAny", finance.view.length + finance.record.length + finance.approve.length > 0],
    ["invIssue", (invoice?.issue.length ?? 0) > 0],
    ["invManage", (invoice?.manage.length ?? 0) > 0],
    ["shopTasks", canUseShopTasks(employee)],
    ["health", canWatchHealth(employee)],
    ["handoff", canHandOff(employee)],
    ["docGenerate", has("production.documents.generate")],
    ["docRevision", has("production.documents.request_revision")],
    ["docReprint", has("production.documents.reprint")],
    ["authority", canManageAuthority(employee)],
    ["dashboardLink", context.dashboardLink && canUseManagementWorkspace(employee)],
  ];
  return new Set(needs.filter(([, held]) => held).map(([need]) => need));
}

/** The topic of a screen: the route decides, and on the home screen the open workspace does. */
export function guideTopicFor(route: StaffRoute, workspace: Workspace | null, employee: Employee): GuideTopicId {
  switch (route.kind) {
    case "orders": return "scr.orders";
    case "order-detail": return "scr.order";
    case "history": return "scr.history";
    case "profile": return "scr.profile";
    case "problems": return "scr.problems";
    case "exception-detail": return "scr.exception";
    case "authority": return "scr.authority";
    case "trendyol": return "ws.trendyol";
    case "trendyol-package": return "scr.trendyol.package";
    case "document-detail": return "scr.document";
    case "confirmare": return homeConfirmare(employee);
    case "confirmare-order": return "scr.cf.order";
    case "confirmare-team": return "scr.cf.team";
    case "confirmare-finance": return "scr.cf.finance";
    case "confirmare-handoff": return "scr.cf.handoff";
    case "confirmare-awb": return "scr.cf.awb";
    case "confirmare-efactura": return "scr.cf.efactura";
    case "confirmare-health": return "scr.cf.health";
    default: return workspace ? workspaceTopic(workspace, employee) : "overview";
  }
}

function homeConfirmare(employee: Employee): GuideTopicId {
  const scopes = confirmareScopesOf(employee);
  return scopes.operate.length + scopes.supervise.length > 0 ? "ws.confirmare" : "ws.confirmare.finance";
}

function workspaceTopic(workspace: Workspace, employee: Employee): GuideTopicId {
  switch (workspace.kind) {
    case "confirmare": return homeConfirmare(employee);
    case "trendyol": return "ws.trendyol";
    case "documents": return "ws.documente";
    case "management": return "ws.management";
    case "general": return "ws.general";
    case "production": {
      const id = `ws.${workspace.id}`;
      return id in guideTopics ? id as GuideTopicId : "overview";
    }
  }
}

/** A line is shown when it needs nothing, or when the employee holds at least one of its capabilities. */
export const lineVisible = (line: GuideLine, capabilities: ReadonlySet<GuideNeed>): boolean => !line.need || line.need.some((need) => capabilities.has(need));

export type ResolvedGuide = {
  topicId: GuideTopicId;
  title: GuideText;
  summary: GuideText;
  purpose: GuideText;
  steps: GuideText[];
  after: GuideText[];
  caution: GuideText[];
  details: GuideText[];
  /** The employee's own shop scopes relevant to this screen ("Emiți factura finală: Trend Home"). */
  scopes: GuideText[];
  /** One line per stage the employee holds that this screen covers. */
  stages: GuideText[];
  /** The overview steps that are the employee's own area. */
  flow: ReadonlySet<FlowStep>;
};

const CONFIRMARE_TOPICS: readonly GuideTopicId[] = ["ws.confirmare", "ws.confirmare.finance", "scr.cf.order", "scr.cf.team", "scr.cf.finance", "scr.cf.handoff", "scr.cf.awb", "scr.cf.efactura", "scr.cf.health", "ws.documente"];
const STAGE_TOPICS: readonly GuideTopicId[] = ["scr.order", "scr.orders", "overview"];

export function resolveGuide(employee: Employee, route: StaffRoute, workspace: Workspace | null, context: GuideContext): ResolvedGuide {
  const topicId = guideTopicFor(route, workspace, employee);
  const topic = guideTopics[topicId];
  const capabilities = guideCapabilities(employee, context);
  const visible = (lines: readonly GuideLine[] | undefined) => (lines ?? []).filter((line) => lineVisible(line, capabilities)).map((line) => line.text);
  // A one-stage workspace already explains its stage; the list helps where several stages share a screen.
  const stageIds = route.kind === "home" && workspace?.kind === "production" ? (workspace.stageIds.length > 1 ? workspace.stageIds : []) : STAGE_TOPICS.includes(topicId) ? employee.allowedStageIds : [];
  return {
    topicId,
    title: topic.title,
    summary: topic.summary,
    purpose: topic.purpose,
    steps: visible(topic.steps),
    after: visible(topic.after),
    caution: visible("caution" in topic ? topic.caution : undefined),
    details: visible("details" in topic ? topic.details : undefined),
    scopes: CONFIRMARE_TOPICS.includes(topicId) ? scopeLines(employee) : [],
    stages: stageIds.filter((stageId) => employee.allowedStageIds.includes(stageId) && stageLines[stageId]).map((stageId) => stageLines[stageId]),
    flow: flowOf(employee),
  };
}

const fill = (template: GuideText, stores: string): GuideText => ({ ro: template.ro.replace("{stores}", stores), tr: template.tr.replace("{stores}", stores) });

/** Shop scopes read from the session: only the shops the employee holds, never another shop's data. */
export function scopeLines(employee: Employee): GuideText[] {
  const confirmare = confirmareScopesOf(employee);
  const finance = financeScopesOf(employee);
  const invoice = employee.invoiceScopes;
  const rows: [keyof typeof scopeLabels, readonly string[]][] = [
    ["cfOperate", confirmare.operate],
    ["cfSupervise", confirmare.supervise],
    ["finRecord", finance.record],
    ["finApprove", finance.approve],
    ["finView", finance.record.length + finance.approve.length > 0 ? [] : finance.view],
    ["invIssue", invoice?.issue ?? []],
    ["invManage", invoice?.manage ?? []],
  ];
  return rows.filter(([, sources]) => sources.length > 0).map(([key, sources]) => fill(scopeLabels[key], [...sources].sort(shopOrder).map(sourceLabel).join(", ")));
}

const SHOP_ORDER = ["trendhome", "outletperdele"];
const shopOrder = (a: string, b: string) => (SHOP_ORDER.indexOf(a) + 1 || 99) - (SHOP_ORDER.indexOf(b) + 1 || 99);

const PRODUCTION_STAGES = ["waiting", "material-preparation", "workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing"];

/** Which steps of the overview are the employee's own (highlighted, never a permission). */
export function flowOf(employee: Employee): ReadonlySet<FlowStep> {
  const confirmare = confirmareScopesOf(employee);
  const finance = financeScopesOf(employee);
  const stages = employee.allowedStageIds;
  const own: Record<FlowStep, boolean> = {
    order: employee.permissions.includes("trendyol.orders.view"),
    confirmare: confirmare.operate.length + confirmare.supervise.length > 0,
    finance: finance.view.length + finance.record.length + finance.approve.length > 0,
    production: stages.some((stage) => PRODUCTION_STAGES.includes(stage)) || employee.permissions.includes("production.documents.generate"),
    quality: stages.includes("quality-control"),
    packing: stages.includes("packing"),
    delivery: stages.includes("delivery") || canUseShopTasks(employee),
  };
  return new Set(FLOW_STEPS.filter((step) => own[step]));
}

// ---- Guide language: chosen in the guide, per employee, independent of the Staff interface language ------------

const LANG_PREFIX = "arasya_staff_guide_lang:";
const CARD_PREFIX = "arasya_staff_guide_card:";
type PreferenceStorage = Pick<Storage, "getItem" | "setItem">;

function browserStorage(): PreferenceStorage | undefined {
  try { return globalThis.localStorage; }
  catch { return undefined; }
}

/** Turkish-speaking accounts start in Turkish, everyone else in Romanian, until they choose. */
export const defaultGuideLang = (locale: Locale): GuideLang => locale === "tr" ? "tr" : "ro";

/** Keyed by the employee, so the next person on a shared phone never inherits the previous choice. */
export function readGuideLang(employeeUuid: string, fallback: GuideLang, storage: PreferenceStorage | undefined = browserStorage()): GuideLang {
  try {
    const value = storage?.getItem(LANG_PREFIX + employeeUuid);
    return (GUIDE_LANGS as readonly string[]).includes(value ?? "") ? value as GuideLang : fallback;
  } catch { return fallback; }
}

export function saveGuideLang(employeeUuid: string, lang: GuideLang, storage: PreferenceStorage | undefined = browserStorage()): void {
  try { storage?.setItem(LANG_PREFIX + employeeUuid, lang); }
  catch { /* The guide works without browser storage. */ }
}

/** Whether the employee closed the workspace card on the home screen (it can be opened again). */
export function readGuideCardHidden(employeeUuid: string, storage: PreferenceStorage | undefined = browserStorage()): boolean {
  try { return storage?.getItem(CARD_PREFIX + employeeUuid) === "hidden"; }
  catch { return false; }
}

export function saveGuideCardHidden(employeeUuid: string, hidden: boolean, storage: PreferenceStorage | undefined = browserStorage()): void {
  try { storage?.setItem(CARD_PREFIX + employeeUuid, hidden ? "hidden" : "shown"); }
  catch { /* The card stays as chosen for this visit. */ }
}
