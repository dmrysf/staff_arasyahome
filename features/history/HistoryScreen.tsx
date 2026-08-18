"use client";

import { useEffect, useState } from "react";
import type { ActivityPage } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { ActivityService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";

type Range = "today" | "7days" | "month" | "custom";
const rangeLabels: Record<Range, string> = { today: "Astăzi", "7days": "7 zile", month: "Luna aceasta", custom: "Calendar" };

export function HistoryScreen({ service, navigate }: { service: ActivityService; navigate: (path: string) => void }) {
  const [range, setRange] = useState<Range>("today");
  const [page, setPage] = useState<ActivityPage | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  useEffect(() => { const controller = new AbortController(); service.listMine({ range }, { signal: controller.signal }).then(setPage).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); }); return () => controller.abort(); }, [range, reloadKey, service]);
  return (
    <div className="screen-stack">
      <section className="screen-heading"><p className="eyebrow">Activitatea mea</p><h1>Istoric</h1><p>Ritmul tău, fără indicatori de vânzări.</p></section>
      <div className="range-scroll" role="group" aria-label="Interval istoric">{(Object.keys(rangeLabels) as Range[]).map((item) => <button key={item} className={item === range ? "active" : ""} type="button" onClick={() => { setError(null); setPage(null); setRange(item); }}>{rangeLabels[item]}</button>)}</div>
      {range === "custom" && <div className="date-range"><label>De la<input type="date" /></label><span>→</span><label>Până la<input type="date" /></label></div>}
      {error ? <ErrorState error={error} onAction={() => { setError(null); setPage(null); setReloadKey((value) => value + 1); }} /> : page ? <><section className="metrics-grid"><div><span>Comenzi procesate</span><strong>{page.summary.processed}</strong></div><div><span>Metri procesați</span><strong>{page.summary.meters}<small>m</small></strong></div><div><span>Predate</span><strong>{page.summary.handedOver}</strong></div><div><span>În lucru</span><strong>{page.summary.inProgress}</strong></div></section><section className="history-list"><p className="eyebrow">Cronologie</p>{page.items.map((entry) => <button className="history-row" type="button" key={entry.id} onClick={() => navigate(`/orders/${entry.orderId}`)}><time dateTime={entry.occurredAt}>{new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit" }).format(new Date(entry.occurredAt))}</time><span className="history-dot" /><span className="history-copy"><span><b>#{entry.orderNumber}</b><SourceBadge source={entry.source} /></span><small>{entry.fromStage} <b aria-hidden="true">→</b> {entry.toStage}</small></span><span aria-hidden="true">›</span></button>)}</section></> : <div className="inline-loading"><span /> Se încarcă activitatea…</div>}
    </div>
  );
}
