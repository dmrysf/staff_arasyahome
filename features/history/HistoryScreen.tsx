import { useEffect, useState } from "react";
import type { ActivityEntry, ActivityPage } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { ActivityService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { formatMeters } from "../../domain/productFormat";

type Range = "today" | "7days" | "month" | "custom";
const rangeLabels: Record<Range, string> = { today: "Astăzi", "7days": "7 zile", month: "Luna aceasta", custom: "Calendar" };
const MAX_CUSTOM_DAYS = 92;

function localDate(offsetDays = 0) {
  const date = new Date();
  date.setDate(date.getDate() + offsetDays);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** Returns a Romanian validation message, or null when the custom range is valid. */
export function validateCustomRange(from: string, to: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) return "Alege ambele date.";
  if (from > to) return "Data de început trebuie să fie înaintea datei de final.";
  const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000 + 1;
  return days > MAX_CUSTOM_DAYS ? "Intervalul poate avea cel mult 92 de zile." : null;
}

export function describeActivity(entry: ActivityEntry): string {
  if (entry.action === "claimed") return `Preluată la ${entry.fromStageLabelSnapshot}`;
  if (entry.action === "production_completed") return `${entry.fromStageLabelSnapshot} → Producție finalizată`;
  return `${entry.fromStageLabelSnapshot} → ${entry.toStageLabelSnapshot ?? "—"}`;
}

const time = new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit" });
const day = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "short" });

export function HistoryScreen({ service, navigate }: { service: ActivityService; navigate: (path: string) => void }) {
  const [range, setRange] = useState<Range>("today");
  const [customFrom, setCustomFrom] = useState(() => localDate(-6));
  const [customTo, setCustomTo] = useState(() => localDate());
  const [page, setPage] = useState<ActivityPage | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [loadingMore, setLoadingMore] = useState(false);
  const customError = range === "custom" ? validateCustomRange(customFrom, customTo) : null;

  useEffect(() => {
    if (customError) return;
    const controller = new AbortController();
    const input = range === "custom" ? { range, from: customFrom, to: customTo } : { range };
    service.listMine(input, { signal: controller.signal })
      .then((next) => { setError(null); setPage(next); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [customError, customFrom, customTo, range, reloadKey, service]);

  function loadMore() {
    if (!page?.nextCursor || loadingMore) return;
    setLoadingMore(true);
    const input = range === "custom" ? { range, from: customFrom, to: customTo, cursor: page.nextCursor } : { range, cursor: page.nextCursor };
    service.listMine(input)
      .then((next) => setPage((current) => current ? { ...next, items: [...current.items, ...next.items.filter((item) => !current.items.some((existing) => existing.id === item.id))] } : next))
      .catch((caught) => setError(toServiceError(caught)))
      .finally(() => setLoadingMore(false));
  }

  return (
    <div className="screen-stack">
      <section className="screen-heading"><h1>Istoric</h1><p>Acțiunile tale confirmate în producție.</p></section>
      <div className="range-scroll" role="group" aria-label="Interval istoric">{(Object.keys(rangeLabels) as Range[]).map((item) => <button key={item} className={item === range ? "active" : ""} aria-pressed={item === range} type="button" onClick={() => { setError(null); setPage(null); setRange(item); }}>{rangeLabels[item]}</button>)}</div>
      {range === "custom" && <div className="date-range"><label>De la<input type="date" value={customFrom} max={customTo} onChange={(event) => { setPage(null); setCustomFrom(event.target.value); }} /></label><span>→</span><label>Până la<input type="date" value={customTo} min={customFrom} max={localDate()} onChange={(event) => { setPage(null); setCustomTo(event.target.value); }} /></label></div>}
      {customError ? <p className="order-notice" role="alert">{customError}</p> : error ? <ErrorState error={error} onAction={() => { setError(null); setPage(null); setReloadKey((value) => value + 1); }} /> : page ? <>
        <section className="metrics-grid"><div><span>Comenzi</span><strong>{page.summary.processed}</strong></div><div><span>Metri predați</span><strong>{formatMeters(page.summary.meters).replace(" m", "")}<small>m</small></strong></div><div><span>Predate</span><strong>{page.summary.handedOver}</strong></div><div><span>În lucru acum</span><strong>{page.summary.inProgress}</strong></div></section>
        <section className="history-list"><h2>Cronologie</h2>
          {page.items.length === 0 && <div className="empty-state"><h2>Nicio activitate în acest interval.</h2><p>Acțiunile confirmate de server apar aici.</p></div>}
          {page.items.map((entry) => <button className="history-row" type="button" key={entry.id} onClick={() => navigate(`/orders/${encodeURIComponent(entry.orderId)}`)}><time dateTime={entry.occurredAt}>{range === "today" ? time.format(new Date(entry.occurredAt)) : <>{day.format(new Date(entry.occurredAt))}<br />{time.format(new Date(entry.occurredAt))}</>}</time><span className={`history-dot history-dot-${entry.action}`} /><span className="history-copy"><span><b>#{entry.orderNumber}</b><SourceBadge source={entry.source} /></span><small>{describeActivity(entry)}</small></span><AppIcon name="arrow" size={18} /></button>)}
          {page.nextCursor && <button className="button button-secondary" type="button" disabled={loadingMore} onClick={loadMore}>{loadingMore ? "Se încarcă…" : "Încarcă mai multe"}</button>}
        </section>
      </> : <div className="inline-loading"><span /> Se încarcă activitatea…</div>}
    </div>
  );
}
