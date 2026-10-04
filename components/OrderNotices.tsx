import type { StaffOrder } from "../domain/models";
import { orderActionBlockedCopy } from "../domain/orderActions";

/** Source freshness and action availability, shown without blocking production reads. */
export function OrderNotices({ order }: { order: StaffOrder }) {
  const freshness = order.freshness?.status;
  return (
    <>
      {freshness === "stale" && <p className="order-notice" role="status">Datele magazinului pot fi întârziate. Etapa de producție este cea confirmată de Arasya.</p>}
      {freshness === "source_unavailable" && <p className="order-notice" role="status">Magazinul sursă nu răspunde momentan. Etapa de producție rămâne valabilă.</p>}
      {order.employeeActionBlockedReason && <p className="order-notice order-notice-muted" role="note">{orderActionBlockedCopy[order.employeeActionBlockedReason]}</p>}
    </>
  );
}
