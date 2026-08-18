"use client";

import { useEffect, useState } from "react";
import type { StaffOrder } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { OrderCard } from "./OrderCard";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";

export function OrdersScreen({ service, navigate }: { service: OrderService; navigate: (path: string) => void }) {
  const [orders, setOrders] = useState<StaffOrder[]>([]);
  const [tab, setTab] = useState<"in_progress" | "handed_over">("in_progress");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<StaffServiceError | null>(null);
  useEffect(() => { const controller = new AbortController(); service.listMine({ signal: controller.signal }).then(setOrders).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); }).finally(() => { if (!controller.signal.aborted) setLoading(false); }); return () => controller.abort(); }, [service]);
  const visible = orders.filter((order) => order.status === tab);
  return (
    <div className="screen-stack">
      <section className="screen-heading"><p className="eyebrow">Spațiul meu</p><h1>Comenzile mele</h1><p>Doar comenzile de care ești responsabil.</p></section>
      <div className="segmented" role="tablist" aria-label="Stare comenzi"><button role="tab" aria-selected={tab === "in_progress"} onClick={() => setTab("in_progress")}>În lucru <span>{orders.filter((order) => order.status === "in_progress").length}</span></button><button role="tab" aria-selected={tab === "handed_over"} onClick={() => setTab("handed_over")}>Predate <span>{orders.filter((order) => order.status === "handed_over").length}</span></button></div>
      {error ? <ErrorState error={error} onAction={() => { setError(null); setLoading(true); service.listMine().then(setOrders).catch((caught) => setError(toServiceError(caught))).finally(() => setLoading(false)); }} /> : <div className="order-list" aria-live="polite">
        {loading ? <div className="inline-loading"><span /> Se încarcă comenzile…</div> : visible.map((order) => <OrderCard key={order.id} order={order} onOpen={() => navigate(`/orders/${order.id}`)} />)}
        {!loading && visible.length === 0 && <div className="empty-state"><span aria-hidden="true">✓</span><h2>Nicio comandă aici</h2><p>Comenzile vor apărea automat când sunt disponibile.</p></div>}
      </div>}
      <aside className="handover-note"><span aria-hidden="true">⇄</span><div><strong>Transfer cu acceptare</strong><p>Responsabilitatea rămâne la tine până când colegul acceptă transferul.</p></div><span className="coming-soon">În curând</span></aside>
    </div>
  );
}
