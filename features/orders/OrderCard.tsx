import type { StaffOrder } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { getUsableProductionProducts } from "../../domain/orderValidation";
import { getEmployeeRelationLabel, getEmployeeRelationTime } from "../../domain/orderRelation";
import { AppIcon } from "../../components/icons/AppIcon";

const time = new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit" });

export function OrderCard({ order, onOpen }: { order: StaffOrder; onOpen: () => void }) {
  const products = getUsableProductionProducts(order);
  const item = products.at(0);
  const relevantAt = getEmployeeRelationTime(order);
  return (
    <button className="order-card" type="button" onClick={onOpen}>
      <span className="order-card-top"><strong>#{order.orderNumber}</strong><SourceBadge source={order.source} /></span>
      <span className="order-product">{item ? `${item.name} · ${item.code}${products.length > 1 ? ` +${products.length - 1}` : ""}` : "Produse indisponibile"}</span>
      <span className="order-card-stage"><StageLabel stage={order.currentStage} /><AppIcon name="arrow" size={19} /></span>
      <span className="order-relation"><span>{getEmployeeRelationLabel(order)}</span><time dateTime={relevantAt}>{time.format(new Date(relevantAt))}</time></span>
    </button>
  );
}
