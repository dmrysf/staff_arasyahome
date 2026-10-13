import type { TrendyolPackageSummary } from "../../domain/trendyol";
import { t } from "../../i18n";
import type { TrendyolDashboardData } from "../trendyol/trendyolRefresh";

export type NextAction = {
  tone: "action" | "ready" | "attention" | "calm";
  eyebrow: string;
  title: string;
  detail: string;
  /** The order the action is about (shown in the monospace order style). */
  orderNumber: string | null;
  cta: { label: string; path: string } | null;
};

const missingLines = (item: TrendyolPackageSummary): number | null => item.lineCount === undefined ? null : Math.max(0, item.lineCount - (item.preparedCount ?? 0));

/**
 * The single next step for a Trendyol operator, chosen only from data the server already returned (no extra read,
 * no guess): finish an order that is ready for approval, otherwise continue the first order that still needs
 * measurements, otherwise review the orders that need attention. It links to the existing screens; it never acts.
 */
export function trendyolNextAction(data: TrendyolDashboardData): NextAction {
  const { capabilities, counts, intake } = data.overview;
  const ready = capabilities.release ? data.pending.find((item) => missingLines(item) === 0) : undefined;
  if (ready) {
    return { tone: "ready", eyebrow: t("ty.next.step"), title: t("ty.next.approveTitle"), detail: t("ty.next.approveDetail"), orderNumber: ready.orderNumber, cta: { label: t("common.openOrderNumber", { number: ready.orderNumber }), path: `/trendyol/${ready.packageId}` } };
  }
  const toPrepare = data.pending.find((item) => (missingLines(item) ?? 1) > 0) ?? data.pending[0];
  if (toPrepare && capabilities.prepare) {
    const missing = missingLines(toPrepare);
    const detail = missing === null ? t("ty.next.measureDetail") : t("ty.next.missingLines", { missing, count: toPrepare.lineCount ?? 0 });
    return { tone: "action", eyebrow: t("ty.next.step"), title: t("ty.next.measureTitle"), detail, orderNumber: toPrepare.orderNumber, cta: { label: t("common.openOrderNumber", { number: toPrepare.orderNumber }), path: `/trendyol/${toPrepare.packageId}` } };
  }
  if (counts.pending > 0) {
    return { tone: "action", eyebrow: t("ty.next.pendingEyebrow"), title: t("ty.next.pendingTitle", { count: counts.pending }), detail: t("ty.next.pendingDetail"), orderNumber: null, cta: { label: t("ty.next.viewOrders"), path: "/trendyol" } };
  }
  if (counts.attention > 0) {
    return { tone: "attention", eyebrow: t("ty.next.attentionEyebrow"), title: t("ty.next.attentionTitle", { count: counts.attention }), detail: t("ty.next.attentionDetail"), orderNumber: null, cta: { label: t("ty.next.viewOrders"), path: "/trendyol" } };
  }
  if (intake.status !== "active") {
    return { tone: "calm", eyebrow: t("ty.next.connectionEyebrow"), title: intake.status === "paused" ? t("ty.next.paused") : t("ty.next.inactive"), detail: t("ty.next.nothingToPrepare"), orderNumber: null, cta: null };
  }
  return { tone: "calm", eyebrow: t("ty.next.upToDate"), title: t("ty.next.noneTitle"), detail: t("ty.next.noneDetail"), orderNumber: null, cta: null };
}
