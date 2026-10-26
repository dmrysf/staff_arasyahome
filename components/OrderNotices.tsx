import type { StaffOrder } from "../domain/models";
import { useI18n } from "../i18n";
import { orderActionBlockedCopy } from "../domain/orderActions";

/** Source freshness and action availability, shown without blocking production reads. */
export function OrderNotices({ order }: { order: StaffOrder }) {
  const { t } = useI18n();
  const freshness = order.freshness?.status;
  return (
    <>
      {freshness === "stale" && <p className="order-notice" role="status">{t("notice.stale")}</p>}
      {freshness === "source_unavailable" && <p className="order-notice" role="status">{t("notice.sourceUnavailable")}</p>}
      {/* With pieces apart, the order's blocked reason concerns only the pieces that travel with it (API 2.31.0). */}
      {/* A production stop has its own notice (ProductionStopNotice); its reason is not repeated here. */}
      {order.employeeActionBlockedReason && !order.productionStop && <p className="order-notice order-notice-muted" role="note">{order.pieces?.mixedProgress ? `${t("piece.withOrder")}: ` : ""}{orderActionBlockedCopy[order.employeeActionBlockedReason]}</p>}
      {order.paymentGate?.reversedAt && <p className="order-notice" role="status">{order.paymentGate.state === "unverified" ? t("notice.paymentReversed") : t("notice.advanceReversed")}</p>}
    </>
  );
}
