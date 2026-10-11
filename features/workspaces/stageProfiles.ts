import type { AppIconName } from "../../components/icons/AppIcon";
import type { ProductionItem, StaffOrder } from "../../domain/models";
import type { StageQueue } from "../../domain/workspaces";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { getUsableProductionProducts } from "../../domain/orderValidation";
import type { NextAction } from "./trendyolNextAction";

/**
 * How each production department looks at the same server data. Presentation only: the profile chooses an icon,
 * the facts a queue row shows first and how the next step is phrased. It never changes which orders are listed,
 * which actions exist or who may take them.
 */
export type StageProfile = {
  icon: AppIconName;
  /** What a queue row shows under the order number. */
  detail: "measures" | "items" | "product";
  /** Claiming happens by scanning the original QR label (no order is suggested first). */
  claimByScan: boolean;
};

const profiles: Record<string, StageProfile> = {
  pornire: { icon: "document", detail: "product", claimByScan: false },
  taiere: { icon: "scissors", detail: "measures", claimByScan: true },
  atelier: { icon: "package", detail: "measures", claimByScan: false },
  croitorie: { icon: "ruler", detail: "measures", claimByScan: false },
  "control-calitate": { icon: "check", detail: "measures", claimByScan: false },
  ambalare: { icon: "package", detail: "items", claimByScan: false },
  livrare: { icon: "arrow", detail: "items", claimByScan: false },
};
const fallback: StageProfile = { icon: "layers", detail: "product", claimByScan: false };

export const stageProfile = (workspaceId: string): StageProfile => profiles[workspaceId] ?? fallback;

/** Facts shown on a queue row, chosen by department: sizes and fabric for cutting and sewing, items for packing. */
export function orderFacts(order: StaffOrder, detail: StageProfile["detail"]): { primary: string; chips: string[] } {
  const products = getUsableProductionProducts(order);
  const first: ProductionItem | undefined = products.at(0);
  if (!first) return { primary: "Produse indisponibile", chips: [] };
  const more = products.length > 1 ? ` +${products.length - 1}` : "";
  if (detail === "items") {
    return { primary: `${products.length} ${products.length === 1 ? "produs" : "produse"} · ${products.reduce((sum, item) => sum + item.quantity, 0)} buc.`, chips: products.slice(0, 3).map((item) => `${item.name} × ${item.quantity}`) };
  }
  if (detail === "measures") {
    const chips = [formatMeasurements(first), first.meters != null ? formatMeters(first.meters) : undefined, first.quantity > 1 ? `× ${first.quantity}` : undefined].filter((value): value is string => Boolean(value));
    return { primary: `${first.name}${more}`, chips };
  }
  return { primary: `${first.name}${first.code ? ` · ${first.code}` : ""}${more}`, chips: [] };
}

/**
 * The one next step of a production employee, from the stage queue already read: continue own work first, then
 * take new work (by scanning where the department claims by QR, otherwise by opening the oldest available order),
 * then look at blocked orders. Opening an order never claims it; the order screen keeps the explicit action.
 */
export function stageNextAction(queue: StageQueue, profile: StageProfile, canScan: boolean): NextAction {
  const items = queue.items;
  const mine = items.find((order) => order.employeeAllowedAction?.id === "complete_stage" || order.employeeAllowedAction?.id === "complete_production");
  if (mine) {
    const others = queue.counts.mine - 1;
    return { tone: "action", eyebrow: "Următorul pas", title: "Continuă lucrul", detail: others > 0 ? `Mai ai ${others} ${others === 1 ? "comandă" : "comenzi"} în lucru la această etapă.` : "Finalizează etapa din pagina comenzii.", orderNumber: mine.orderNumber, cta: { label: `Deschide comanda #${mine.orderNumber}`, path: `/orders/${encodeURIComponent(mine.id)}` } };
  }
  if (queue.counts.available > 0) {
    if (profile.claimByScan) {
      return { tone: "action", eyebrow: "Următorul pas", title: "Preia o comandă nouă", detail: `${queue.counts.available} ${queue.counts.available === 1 ? "comandă disponibilă" : "comenzi disponibile"}. Preluarea se face scanând eticheta QR originală.`, orderNumber: null, cta: canScan ? { label: "Scanează eticheta QR", path: "/scan" } : null };
    }
    const available = items.find((order) => order.employeeAllowedAction?.id === "claim");
    if (available) {
      return { tone: "action", eyebrow: "Următorul pas", title: "Preia următoarea comandă", detail: `${queue.counts.available} ${queue.counts.available === 1 ? "comandă disponibilă" : "comenzi disponibile"}. Deschiderea nu o preia: confirmi în pagina comenzii.`, orderNumber: available.orderNumber, cta: { label: `Deschide comanda #${available.orderNumber}`, path: `/orders/${encodeURIComponent(available.id)}` } };
    }
  }
  if (queue.counts.blocked > 0) {
    return { tone: "attention", eyebrow: "Verificare", title: `${queue.counts.blocked} ${queue.counts.blocked === 1 ? "comandă blocată" : "comenzi blocate"}`, detail: "Motivul apare pe fiecare comandă din listă.", orderNumber: null, cta: null };
  }
  return { tone: "calm", eyebrow: "Totul este la zi", title: "Nicio comandă de preluat", detail: "Lista se actualizează automat când o comandă ajunge aici.", orderNumber: null, cta: null };
}
