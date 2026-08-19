import { useEffect, useState } from "react";
import type { ProductionWorkflow, StaffOrder, StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { requireProductionProducts } from "../../domain/orderValidation";
import { AppIcon } from "../../components/icons/AppIcon";
import { getEmployeeRelationLabel, getEmployeeRelationTime } from "../../domain/orderRelation";
import { getStageById } from "../../domain/productionWorkflow";
import { ProductionRoadmap } from "../../components/ProductionRoadmap";

export function OrderDetailScreen({ orderId, service, workflow, navigate }: { orderId: string; service: OrderService; workflow: ProductionWorkflow; navigate: (path: string) => void }) {
  const [order, setOrder] = useState<StaffOrder | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  useEffect(() => { const controller = new AbortController(); service.getById(orderId, { signal: controller.signal }).then(requireProductionProducts).then(setOrder).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); }); return () => controller.abort(); }, [orderId, service]);
  if (error) return <ErrorState error={error} onAction={() => navigate("/orders")} />;
  if (!order) return <div className="inline-loading"><span /> Se încarcă detaliile…</div>;
  return (
    <article className="screen-stack detail-screen">
      <button className="back-link" type="button" onClick={() => navigate("/orders")}><AppIcon name="back" size={20} /> Comenzile mele</button>
      <section className="detail-hero"><div><SourceBadge source={order.source} /><h1>Comanda<br />#{order.orderNumber}</h1></div><StageLabel stage={getStageById(workflow, order.productionStageId)} /></section>
      <ProductionRoadmap workflow={workflow} currentStageId={order.productionStageId} />
      <section className="detail-section detail-products"><div className="section-title"><p className="eyebrow">Producție</p><h2>{order.products.length === 1 ? "1 produs" : `${order.products.length} produse`}</h2></div>{order.products.map((item) => <div className="product-detail" key={item.id}><div><strong>{item.name}</strong><span>{item.code}</span></div><dl>{item.color && <div><dt>Culoare</dt><dd>{item.color}</dd></div>}{item.dimensions && <div><dt>Dimensiune</dt><dd>{item.dimensions}</dd></div>}{item.meters != null && <div><dt>Metri</dt><dd>{item.meters} m</dd></div>}<div><dt>Cantitate</dt><dd>{item.quantity}</dd></div></dl></div>)}</section>
      {order.productionNotes && <section className="production-note"><p className="eyebrow">Notă de producție</p><p>{order.productionNotes}</p></section>}
      <section className="detail-section"><p className="eyebrow">Implicarea mea</p><div className="mini-timeline"><span /><div><strong>{getEmployeeRelationLabel(order)}</strong><time dateTime={getEmployeeRelationTime(order)}>{new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" }).format(new Date(getEmployeeRelationTime(order)))}</time></div></div></section>
      <button className="exception-action" type="button" disabled><span aria-hidden="true">◇</span> Acțiune excepțională <small>Necesită control manager</small></button>
    </article>
  );
}
