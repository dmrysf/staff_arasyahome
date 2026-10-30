"use client";

import { useEffect, useState } from "react";
import { useI18n } from "../../i18n";
import type { ProductionWorkflow, StaffOrder } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { OrderCard } from "./OrderCard";
import { ErrorState } from "../../components/ErrorState";
import { EmptyPanel, LoadingState, PageHeader } from "../../components/ui/States";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { matchesMyOrdersView, type MyOrdersView } from "../../domain/orderRelation";
import { AppIcon } from "../../components/icons/AppIcon";

const tabs: readonly MyOrdersView[] = ["in_progress", "recent", "handed_over"];

/** `scanner` (Staff 2.42.0): false for an office operator, whose empty list then offers no QR scan. */
export function OrdersScreen({ service, workflow, navigate, scanner = true }: { service: OrderService; workflow: ProductionWorkflow; navigate: (path: string) => void; scanner?: boolean }) {
  const { t } = useI18n();
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
      <PageHeader title={t("orders.title")} description={t("orders.description")} />
      <div className="segmented" role="tablist" aria-label={t("orders.filter")}>{tabs.map((item) => <button key={item} role="tab" aria-selected={tab === item} onClick={() => setTab(item)}>{t(`orders.tab.${item}`)}<span>{orders.filter((order) => matchesMyOrdersView(order, item)).length}</span></button>)}</div>
      {error ? <ErrorState error={error} onAction={() => { setError(null); setLoading(true); service.listMine({ limit: 50 }).then(page => { setOrders(page.items); setNextCursor(page.nextCursor); }).catch((caught) => setError(toServiceError(caught))).finally(() => setLoading(false)); }} /> : <div className="order-list" aria-live="polite">
        {loading ? <LoadingState label={t("orders.loading")} /> : visible.map((order) => <OrderCard key={order.id} order={order} workflow={workflow} onOpen={() => navigate(`/orders/${encodeURIComponent(order.id)}`)} />)}
        {!loading && visible.length === 0 && <EmptyPanel icon="scan" title={tab === "in_progress" ? t("orders.emptyActive") : t("orders.emptyOther")} action={scanner ? <button className="button button-primary" type="button" onClick={() => navigate("/scan")}><span>{t("orders.scanOne")}</span><AppIcon name="arrow" size={19} /></button> : undefined}>{scanner ? t("orders.emptyHint") : null}</EmptyPanel>}
        {!loading && nextCursor && (
          <div className="load-more-container">
            {loadMoreError && <div className="load-more-error">{t("orders.loadMoreFailed")} {getErrorPresentation(loadMoreError).message}</div>}
            <button className="button button-secondary" type="button" disabled={loadingMore} onClick={loadMore}>
              {loadingMore ? t("common.loading") : loadMoreError ? t("common.retry") : t("common.loadMore")}
            </button>
          </div>
        )}
      </div>}
      <aside className="handover-note"><div><strong>{t("orders.handoverTitle")}</strong><p>{t("orders.handoverBody")}</p></div></aside>
    </div>
  );
}
