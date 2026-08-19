"use client";

import { useEffect, useState } from "react";
import type { ProductionWorkflow, StaffOrder } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { OrderCard } from "./OrderCard";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { matchesMyOrdersView, type MyOrdersView } from "../../domain/orderRelation";
import { AppIcon } from "../../components/icons/AppIcon";

const tabs: Array<{ id: MyOrdersView; label: string }> = [
  { id: "in_progress", label: "În lucru" },
  { id: "recent", label: "Recente" },
  { id: "handed_over", label: "Predate" },
];

export function OrdersScreen({ service, workflow, navigate }: { service: OrderService; workflow: ProductionWorkflow; navigate: (path: string) => void }) {
  const [orders, setOrders] = useState<StaffOrder[]>([]);
  const [nextCursor, setNextCursor] = useState<string | undefined>();
  const [tab, setTab] = useState<MyOrdersView>("in_progress");
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [loadMoreError, setLoadMoreError] = useState<StaffServiceError | null>(null);

  useEffect(() => { 
    const controller = new AbortController(); 
    service.listMine({ limit: 50, signal: controller.signal })
      .then(page => { setOrders(page.items); setNextCursor(page.nextCursor); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); }); 
    return () => controller.abort(); 
  }, [service]);

  function loadMore() {
    if (loadingMore || !nextCursor) return;
    setLoadingMore(true);
    setLoadMoreError(null);
    service.listMine({ cursor: nextCursor, limit: 50 })
      .then(page => {
        setOrders(prev => {
          const map = new Map(prev.map(o => [o.id, o]));
          for (const o of page.items) map.set(o.id, o);
          return Array.from(map.values());
        });
        setNextCursor(page.nextCursor);
      })
      .catch((caught) => {
        setLoadMoreError(toServiceError(caught));
      })
      .finally(() => setLoadingMore(false));
  }

  const visible = orders.filter((order) => matchesMyOrdersView(order, tab)).sort((left, right) => Date.parse(right.employeeRelation?.lastActionAt ?? right.updatedAt) - Date.parse(left.employeeRelation?.lastActionAt ?? left.updatedAt));
  return (
    <div className="screen-stack">
      <section className="screen-heading"><h1>Comenzile mele</h1><p>Comenzi legate direct de activitatea ta.</p></section>
      <div className="segmented" role="tablist" aria-label="Filtrare comenzi">{tabs.map((item) => <button key={item.id} role="tab" aria-selected={tab === item.id} onClick={() => setTab(item.id)}>{item.label}<span>{orders.filter((order) => matchesMyOrdersView(order, item.id)).length}</span></button>)}</div>
      {error ? <ErrorState error={error} onAction={() => { setError(null); setLoading(true); service.listMine({ limit: 50 }).then(page => { setOrders(page.items); setNextCursor(page.nextCursor); }).catch((caught) => setError(toServiceError(caught))).finally(() => setLoading(false)); }} /> : <div className="order-list" aria-live="polite">
        {loading ? <div className="inline-loading"><span /> Se încarcă comenzile…</div> : visible.map((order) => <OrderCard key={order.id} order={order} workflow={workflow} onOpen={() => navigate(`/orders/${encodeURIComponent(order.id)}`)} />)}
        {!loading && visible.length === 0 && <div className="empty-state"><AppIcon name="scan" size={26} /><h2>{tab === "in_progress" ? "Nu ai comenzi active." : "Nu există activitate aici."}</h2><p>Scanează codul unei comenzi pentru a continua.</p><button className="button button-primary" type="button" onClick={() => navigate("/scan")}><span>Scanează o comandă</span><AppIcon name="arrow" size={19} /></button></div>}
        {!loading && nextCursor && (
          <div className="load-more-container">
            {loadMoreError && <div className="load-more-error">Nu s-au putut încărca mai multe. {loadMoreError.message}</div>}
            <button className="button button-secondary" type="button" disabled={loadingMore} onClick={loadMore}>
              {loadingMore ? "Se încarcă…" : loadMoreError ? "Reîncearcă" : "Încarcă mai multe"}
            </button>
          </div>
        )}
      </div>}
      <aside className="handover-note"><div><strong>Transfer cu acceptare</strong><p>Responsabilitatea rămâne la tine până când colegul acceptă.</p></div><span className="coming-soon">În curând</span></aside>
    </div>
  );
}
