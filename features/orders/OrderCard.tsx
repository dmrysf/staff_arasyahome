import type { StaffOrder } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { getUsableProductionProducts } from "../../domain/orderValidation";

const time = new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit" });

export function OrderCard({ order, onOpen }: { order: StaffOrder; onOpen: () => void }) {
  const products = getUsableProductionProducts(order);
  const item = products.at(0);
  return (
    <button className="order-card" type="button" onClick={onOpen}>
      <span className="order-card-top"><SourceBadge source={order.source} /><time dateTime={order.updatedAt}>{time.format(new Date(order.updatedAt))}</time></span>
      <strong>Comanda #{order.orderNumber}</strong>
      <span className="order-product">{item ? `${item.name}${products.length > 1 ? ` +${products.length - 1}` : ""}` : "Produse indisponibile"}</span>
      <span className="order-card-bottom"><StageLabel stage={order.currentStage} /><b aria-hidden="true">→</b></span>
    </button>
  );
}
