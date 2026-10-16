import type { StaffOrder } from "../domain/models";
import { formatDate, useI18n } from "../i18n";
import { stageName } from "../domain/stageNames";

/**
 * API 2.35.0: the effective production stop of an order, read-only. While it lasts the server refuses every manufacturing
 * action (claim, stage completion, packing, delivery, pieces in rework), so no such button is offered; the history and
 * the stage stay visible. The worker is told whether the source cancelled the order, whether management stopped it and
 * whether the physical product still has to be checked. API 2.36.0 adds the state of the cancellation sent to the sales
 * channel (awaiting its confirmation, failed and checked by management, or confirmed); production stays stopped in all three.
 */
export function ProductionStopNotice({ order }: { order: StaffOrder }) {
  const { t } = useI18n();
  const stop = order.productionStop;
  if (!stop) return null;
  const title = stop.sourceStatus === "cancelled" ? t("stop.title.cancelled") : stop.sourceStatus === "reactivated" ? t("stop.title.review") : t("stop.title.stopped");
  const origin = stop.sourceStatus === "cancelled" ? t("stop.source.cancelled") : stop.sourceStatus === "reactivated" ? t("stop.source.reactivated") : t("stop.source.local");
  return <div className="document-blocked production-stop" role="alert" data-testid="production-stop" data-origin={stop.origin} data-source-status={stop.sourceStatus}>
    <strong>{title}</strong>
    <span>{t("stop.body")}</span>
    <span>{origin}</span>
    {stop.sourceRequest === "pending" && stop.sourceStatus !== "cancelled" && stop.sync === null && <span>{t("stop.request.pending")}</span>}
    {stop.sync === "awaiting_confirmation" && stop.sourceStatus !== "cancelled" && <span data-testid="production-stop-sync" data-sync="awaiting_confirmation">{t("stop.sync.awaiting")}</span>}
    {stop.sync === "attention" && <span data-testid="production-stop-sync" data-sync="attention">{t("stop.sync.attention")}</span>}
    {stop.sync === "confirmed" && <span data-testid="production-stop-sync" data-sync="confirmed">{t("stop.sync.confirmed")}</span>}
    {stop.phase === "completed" && <span>{t("stop.completed")}</span>}
    <span>{t("stop.stage", { stage: stageName(order.productionStageId) })}</span>
    {stop.physical === "pending" && <span>{t("stop.physical.pending")}</span>}
    {stop.physical === "verified" && <span>{t("stop.physical.verified")}</span>}
    {stop.managementAttention && stop.physical !== "pending" && <span>{t("stop.attention")}</span>}
    <span>{t("stop.since", { time: formatDate(stop.since, { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" }) })}</span>
  </div>;
}
