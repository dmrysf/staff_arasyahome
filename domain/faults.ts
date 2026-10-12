import { documentLiveMessage } from "./documents";
import type { FaultException, FaultExceptionStatus, LiveEvent, OrderSource, ProductionItem, QualityCapabilities, StaffOrder, SupervisorView } from "./models";

export const faultStatusLabels: Record<FaultExceptionStatus, string> = {
  awaiting_acknowledgment: "Așteaptă confirmarea de la tăiere",
  awaiting_approval: "Așteaptă aprobarea managerului",
  approved: "Aprobată · lucrarea se reface",
  rejected: "Respinsă de manager",
  cancelled: "Anulată",
  awaiting_supervisor_review: "Așteaptă verificarea șefului de croitorie",
  dismissed: "Închisă de șeful de croitorie",
  in_supervisor_custody: "În grija șefului de croitorie",
  return_rejected: "Returnarea la tăiere a fost respinsă",
  restored: "Rezolvată în croitorie",
};

/** Supervised cases word receipt and rework without implying that anyone admitted a fault. */
export function faultStatusLabel(fault: Pick<FaultException, "type" | "status">): string {
  if (fault.type === "tailoring_defect" && fault.status === "awaiting_acknowledgment") return "Așteaptă primirea la tăiere";
  if (fault.type === "tailoring_defect" && fault.status === "approved") return "Aprobată · se reface la tăiere";
  return faultStatusLabels[fault.status];
}

/** Stages 3-12, where a worker can report a defect to the tailoring quality supervisor. */
export const TAILORING_STAGE_IDS = ["workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing", "quality-control"] as const;
export const SUPERVISED_SOURCES: readonly OrderSource[] = ["trendyol", "trendhome", "outletperdele"];

/** Whether this order follows the supervised flow; otherwise (B2B, or no supervisor appointed) the intake-only return applies. */
export function isSupervised(order: Pick<StaffOrder, "source">, capabilities: QualityCapabilities | null): boolean {
  return capabilities?.supervisedFlowActive === true && SUPERVISED_SOURCES.includes(order.source);
}

/** "Raportează o problemă": only the employee who owns the order at stages 3-12 and may complete its stage. */
export function canReportDefect(order: StaffOrder, permissions: readonly string[], capabilities: QualityCapabilities | null): boolean {
  return isSupervised(order, capabilities) && (TAILORING_STAGE_IDS as readonly string[]).includes(order.productionStageId)
    && order.employeeAllowedAction?.id === "complete_stage" && !order.productionQuality?.openException && permissions.includes("orders.report_fault");
}

export const supervisorViews: { id: SupervisorView; label: string; empty: string }[] = [
  { id: "review", label: "De verificat", empty: "Nicio problemă nouă de verificat." },
  { id: "custody", label: "În grija mea", empty: "Nu ai comenzi în grijă." },
  { id: "return", label: "De returnat la tăiere", empty: "Nicio returnare la tăiere în curs." },
  { id: "done", label: "Finalizate", empty: "Nicio comandă finalizată în ultimele 30 de zile." },
];

export const responsibilityLabels: Record<"accepted" | "disputed" | "not_stated", string> = {
  accepted: "Tăietorul recunoaște eroarea",
  disputed: "Tăietorul contestă responsabilitatea",
  not_stated: "Primit de un alt tăietor · fără poziție",
};

const timelineLabels: Record<string, string> = {
  reported: "Problemă raportată",
  supervisor_accepted: "Confirmată de șeful de croitorie · preluată în grijă",
  supervisor_dismissed: "Închisă de șeful de croitorie",
  return_requested: "Trimisă la tăiere pentru verificare",
  acknowledged: "Produs primit la tăiere (etichetă scanată)",
  approval_requested: "Aprobare cerută managerului",
  approved: "Aprobată de manager · returnată la tăiere",
  rejected: "Respinsă de manager",
  rereview_requested: "Reanalizare cerută",
  restored: "Readusă în producție la croitorie",
  note_added: "Informație adăugată",
  cancelled: "Anulată",
};

export function timelineLabel(action: string): string {
  return timelineLabels[action] ?? action;
}

/** "17.000" -> "17 m", "8.400" -> "8,4 m", "0.000" -> "fără metraj" (a line the source sent without meters). Display only. */
export function formatDecimalMeters(value: string): string {
  if (/^0*\.?0*$/.test(value)) return "fără metraj";
  const trimmed = value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
  return `${trimmed.replace(".", ",")} m`;
}

/**
 * Preview of the selected fault meters while choosing lines. Whole-line meters are summed as integer
 * thousandths, never quantity x meters; the server recomputes the authoritative value.
 */
export function selectedMeters(items: ProductionItem[], selected: ReadonlySet<string>): string {
  const thousandths = items.filter((item) => selected.has(item.id) && item.meters != null).reduce((sum, item) => sum + Math.round((item.meters ?? 0) * 1000), 0);
  return `${Math.floor(thousandths / 1000)}.${String(thousandths % 1000).padStart(3, "0")}`;
}

export function arrivalLabel(arrival: number): string | null {
  if (arrival < 2) return null;
  return arrival === 2 ? "A doua sosire · după revizie" : `Sosirea a ${arrival}-a · după revizie`;
}

export function needsMyAction(fault: FaultException): boolean {
  const actions = fault.actions;
  return actions.canAcknowledge || actions.canRequestRereview || actions.canReview || actions.canRequestReturn || actions.canAskManagersAgain;
}

const liveMessages: Partial<Record<string, (orderNumber: string) => string>> = {
  "cutting.transfer.approved": (n) => `Transferul comenzii #${n} a fost aprobat. Acceptă și scanează aceeași etichetă pentru preluare.`,
  "cutting.transfer.rejected": (n) => `Transferul comenzii #${n} a fost respins. Vezi motivul în cerere.`,
  "cutting.transfer.completed": (n) => `Transferul comenzii #${n} este finalizat. Noul proprietar poate continua.`,
  "exception.acknowledgment_required": (n) => `Comanda #${n} este trimisă la tăiere. Verifică produsul și scanează eticheta.`,
  "exception.review_required": (n) => `Problemă nouă raportată la comanda #${n}. Verific-o în „Comenzi cu probleme”.`,
  "exception.custody_taken": (n) => `Șeful de croitorie a preluat comanda #${n}.`,
  "exception.dismissed": (n) => `Șeful de croitorie a închis raportul pentru comanda #${n}.`,
  "exception.restored": (n) => `Comanda #${n} a revenit în producție la croitorie.`,
  "exception.approved": (n) => `Aprobarea a fost acordată. Lucrarea poate fi refăcută. Comanda #${n}.`,
  "exception.rejected": (n) => `Managerul a respins returnarea la tăiere pentru comanda #${n}.`,
};

export function liveMessage(type: string, orderNumber?: string, revisionNumber?: number): string | null {
  const message = liveMessages[type];
  if (message) return orderNumber ? message(orderNumber) : null;
  return documentLiveMessage(type, orderNumber, revisionNumber);
}

/** Data invalidations refresh screens, but must not erase an actionable employee notice. */
export function nextLiveNotice(current: LiveEvent | null, event: LiveEvent): LiveEvent | null {
  return liveMessage(event.type, event.orderNumber, event.revisionNumber) === null ? current : event;
}
