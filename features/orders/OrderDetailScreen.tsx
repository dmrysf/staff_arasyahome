"use client";

import { useEffect, useState } from "react";
import type { StaffOrder, StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";

export function OrderDetailScreen({ orderId, service, navigate }: { orderId: string; service: OrderService; navigate: (path: string) => void }) {
  const [order, setOrder] = useState<StaffOrder | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  useEffect(() => { const controller = new AbortController(); service.getById(orderId, { signal: controller.signal }).then(setOrder).catch((caught) => setError(toServiceError(caught))); return () => controller.abort(); }, [orderId, service]);
  if (error) return <ErrorState error={error} onAction={() => navigate("/orders")} />;
  if (!order) return <div className="inline-loading"><span /> Se încarcă detaliile…</div>;
  return (
    <article className="screen-stack detail-screen">
      <button className="back-link" type="button" onClick={() => navigate("/orders")}><span aria-hidden="true">←</span> Comenzile mele</button>
      <section className="detail-hero"><div><SourceBadge source={order.source} /><h1>Comanda<br />#{order.orderNumber}</h1></div><StageLabel stage={order.currentStage} /></section>
      <section className="detail-section"><div className="section-title"><p className="eyebrow">Producție</p><h2>{order.products.length === 1 ? "1 produs" : `${order.products.length} produse`}</h2></div>{order.products.map((item) => <div className="product-detail" key={item.id}><div><strong>{item.name}</strong><span>{item.code}</span></div><dl>{item.color && <div><dt>Culoare</dt><dd>{item.color}</dd></div>}{item.dimensions && <div><dt>Dimensiune</dt><dd>{item.dimensions}</dd></div>}{item.meters != null && <div><dt>Metri</dt><dd>{item.meters} m</dd></div>}<div><dt>Cantitate</dt><dd>{item.quantity}</dd></div></dl></div>)}</section>
      {order.productionNotes && <section className="production-note"><p className="eyebrow">Notă de producție</p><p>{order.productionNotes}</p></section>}
      <section className="detail-section"><p className="eyebrow">Istoricul meu</p><div className="mini-timeline"><span /><div><strong>{order.currentStage.label}</strong><time dateTime={order.updatedAt}>{new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" }).format(new Date(order.updatedAt))}</time></div></div></section>
      <button className="exception-action" type="button" disabled><span aria-hidden="true">◇</span> Acțiune excepțională <small>Necesită control manager</small></button>
    </article>
  );
}
