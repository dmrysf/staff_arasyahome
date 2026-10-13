import type { AppIconName } from "../../components/icons/AppIcon";
import { t } from "../../i18n";
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
  if (!first) return { primary: t("common.productsUnavailable"), chips: [] };
  const more = products.length > 1 ? ` +${products.length - 1}` : "";
  if (detail === "items") {
    return { primary: `${t("common.products", { count: products.length })} · ${t("product.pieces", { count: products.reduce((sum, item) => sum + item.quantity, 0) })}`, chips: products.slice(0, 3).map((item) => `${item.name} × ${item.quantity}`) };
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
    return { tone: "action", eyebrow: t("next.step"), title: t("next.continueTitle"), detail: others > 0 ? t("next.continueMore", { count: others }) : t("next.continueFinish"), orderNumber: mine.orderNumber, cta: { label: t("common.openOrderNumber", { number: mine.orderNumber }), path: `/orders/${encodeURIComponent(mine.id)}` } };
  }
  if (queue.counts.available > 0) {
    if (profile.claimByScan) {
      return { tone: "action", eyebrow: t("next.step"), title: t("next.takeNewTitle"), detail: t("next.takeNewDetail", { available: t("next.available", { count: queue.counts.available }) }), orderNumber: null, cta: canScan ? { label: t("next.scanLabel"), path: "/scan" } : null };
    }
    const available = items.find((order) => order.employeeAllowedAction?.id === "claim");
    if (available) {
      return { tone: "action", eyebrow: t("next.step"), title: t("next.takeNextTitle"), detail: t("next.takeNextDetail", { available: t("next.available", { count: queue.counts.available }) }), orderNumber: available.orderNumber, cta: { label: t("common.openOrderNumber", { number: available.orderNumber }), path: `/orders/${encodeURIComponent(available.id)}` } };
    }
  }
  if (queue.counts.blocked > 0) {
    return { tone: "attention", eyebrow: t("next.checkEyebrow"), title: t("next.blockedTitle", { count: queue.counts.blocked }), detail: t("next.blockedDetail"), orderNumber: null, cta: null };
  }
  if (profile.claimByScan) {
    // Stage-1 orders are not listed for cutting; their label is scanned directly and the scan hands them over.
    return { tone: "calm", eyebrow: t("next.upToDate"), title: t("next.noWorkTitle"), detail: t("next.noWorkDetail"), orderNumber: null, cta: canScan ? { label: t("next.scanLabel"), path: "/scan" } : null };
  }
  return { tone: "calm", eyebrow: t("next.upToDate"), title: t("next.nothingTitle"), detail: t("next.nothingDetail"), orderNumber: null, cta: null };
}
