import type { StaffOrder } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";

const time = new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit" });

export function OrderCard({ order, onOpen }: { order: StaffOrder; onOpen: () => void }) {
  const item = order.products[0];
  return (
    <button className="order-card" type="button" onClick={onOpen}>
      <span className="order-card-top"><SourceBadge source={order.source} /><time dateTime={order.updatedAt}>{time.format(new Date(order.updatedAt))}</time></span>
      <strong>Comanda #{order.orderNumber}</strong>
      <span className="order-product">{item.name}{order.products.length > 1 ? ` +${order.products.length - 1}` : ""}</span>
      <span className="order-card-bottom"><StageLabel stage={order.currentStage} /><b aria-hidden="true">→</b></span>
    </button>
  );
}
