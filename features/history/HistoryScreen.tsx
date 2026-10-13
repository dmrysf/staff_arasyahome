import { useEffect, useState } from "react";
import { formatDate, liveLabels, t, useI18n } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import type { ActivityEntry, ActivityPage } from "../../domain/models";
import type { StaffServiceError } from "../../domain/models";
import type { ActivityService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { ErrorState } from "../../components/ErrorState";
import { EmptyPanel, LoadingState, PageHeader } from "../../components/ui/States";
import { toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { historyDate, HISTORY_TIME_ZONE } from "./historyDate";
import { formatMeters } from "../../domain/productFormat";

type Range = "today" | "7days" | "month" | "custom";
const rangeLabels: Readonly<Record<Range, string>> = liveLabels("history.range.", ["today", "7days", "month", "custom"]);
const MAX_CUSTOM_DAYS = 92;

/** Returns a validation message in the active language, or null when the custom range is valid. */
export function validateCustomRange(from: string, to: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from) || !/^\d{4}-\d{2}-\d{2}$/.test(to)) return t("history.invalid.both");
  if (from > to) return t("history.invalid.order");
  const days = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000 + 1;
  return days > MAX_CUSTOM_DAYS ? t("history.invalid.max") : null;
}

export function describeActivity(entry: ActivityEntry): string {
  // Stage names by canonical id in the active language; the stored snapshot only for a stage this release does not know.
  const from = stageName(entry.fromStageId, entry.fromStageLabelSnapshot);
  if (entry.action === "claimed") return t("history.claimedAt", { stage: from });
  if (entry.action === "production_completed") return `${from} → ${t("common.productionCompleted")}`;
  return `${from} → ${entry.toStageId || entry.toStageLabelSnapshot ? stageName(entry.toStageId, entry.toStageLabelSnapshot) : "—"}`;
}

const time = { format: (value: Date): string => formatDate(value, { hour: "2-digit", minute: "2-digit", timeZone: HISTORY_TIME_ZONE }) };
const day = { format: (value: Date): string => formatDate(value, { day: "numeric", month: "short", timeZone: HISTORY_TIME_ZONE }) };

export function HistoryScreen({ service, navigate }: { service: ActivityService; navigate: (path: string) => void }) {
  useI18n();
  const [range, setRange] = useState<Range>("today");
  const [customFrom, setCustomFrom] = useState(() => historyDate(-6));
  const [customTo, setCustomTo] = useState(() => historyDate());
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
      <PageHeader title={t("history.title")} description={t("history.description")} />
      <div className="range-scroll" role="group" aria-label={t("history.rangeLabel")}>{(Object.keys(rangeLabels) as Range[]).map((item) => <button key={item} className={item === range ? "active" : ""} aria-pressed={item === range} type="button" onClick={() => { setError(null); setPage(null); setRange(item); }}>{rangeLabels[item]}</button>)}</div>
      {range === "custom" && <div className="date-range"><label>{t("history.from")}<input type="date" value={customFrom} max={customTo} onChange={(event) => { setPage(null); setCustomFrom(event.target.value); }} /></label><span>→</span><label>{t("history.to")}<input type="date" value={customTo} min={customFrom} max={historyDate()} onChange={(event) => { setPage(null); setCustomTo(event.target.value); }} /></label></div>}
      {customError ? <p className="order-notice" role="alert">{customError}</p> : error ? <ErrorState error={error} onAction={() => { setError(null); setPage(null); setReloadKey((value) => value + 1); }} /> : page ? <>
        <section className="metrics-grid"><div><span>{t("history.metric.orders")}</span><strong>{page.summary.processed}</strong></div><div><span>{t("history.metric.meters")}</span><strong>{formatMeters(page.summary.meters).replace(" m", "")}<small>m</small></strong></div><div><span>{t("history.metric.handedOver")}</span><strong>{page.summary.handedOver}</strong></div><div><span>{t("history.metric.inProgress")}</span><strong>{page.summary.inProgress}</strong></div></section>
        <section className="history-list"><h2>{t("history.timeline")}</h2>
          {page.items.length === 0 && <EmptyPanel icon="history" title={t("history.empty")}>{t("history.emptyHint")}</EmptyPanel>}
          {page.items.map((entry) => <button className="history-row" type="button" key={entry.id} onClick={() => navigate(`/orders/${encodeURIComponent(entry.orderId)}`)}><time dateTime={entry.occurredAt}>{range === "today" ? time.format(new Date(entry.occurredAt)) : <>{day.format(new Date(entry.occurredAt))}<br />{time.format(new Date(entry.occurredAt))}</>}</time><span className={`history-dot history-dot-${entry.action}`} /><span className="history-copy"><span><b>#{entry.orderNumber}</b><SourceBadge source={entry.source} /></span><small>{describeActivity(entry)}</small></span><AppIcon name="arrow" size={18} /></button>)}
          {page.nextCursor && <button className="button button-secondary" type="button" disabled={loadingMore} onClick={loadMore}>{loadingMore ? t("common.loading") : t("common.loadMore")}</button>}
        </section>
      </> : <LoadingState label={t("history.loading")} />}
    </div>
  );
}
