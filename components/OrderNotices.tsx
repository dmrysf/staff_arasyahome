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
      {order.employeeActionBlockedReason && <p className="order-notice order-notice-muted" role="note">{orderActionBlockedCopy[order.employeeActionBlockedReason]}</p>}
      {order.paymentGate?.reversedAt && <p className="order-notice" role="status">{order.paymentGate.state === "unverified" ? t("notice.paymentReversed") : t("notice.advanceReversed")}</p>}
    </>
  );
}
