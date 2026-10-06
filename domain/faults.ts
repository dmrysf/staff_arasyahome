import type { FaultException, FaultExceptionStatus, ProductionItem } from "./models";

export const faultStatusLabels: Record<FaultExceptionStatus, string> = {
  awaiting_acknowledgment: "Așteaptă confirmarea de la tăiere",
  awaiting_approval: "Așteaptă aprobarea managerului",
  approved: "Aprobată · lucrarea se reface",
  rejected: "Respinsă de manager",
  cancelled: "Anulată",
};

/** "17.000" -> "17 m", "8.400" -> "8,4 m". Exact server decimals, display only. */
export function formatDecimalMeters(value: string): string {
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
  return fault.actions.canAcknowledge || fault.actions.canRequestRereview;
}

const liveMessages: Partial<Record<string, (orderNumber: string) => string>> = {
  "exception.acknowledgment_required": (n) => `Comanda #${n} a fost returnată la tăiere. Confirmă eroarea și scanează eticheta.`,
  "exception.approved": (n) => `Aprobarea a fost acordată. Lucrarea poate fi refăcută. Comanda #${n}.`,
  "exception.rejected": (n) => `Managerul a respins cererea pentru comanda #${n}.`,
};

export function liveMessage(type: string, orderNumber?: string): string | null {
  const message = liveMessages[type];
  return message && orderNumber ? message(orderNumber) : null;
}
