import { useMemo, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { QUEUE_TABS, canSuperviseConfirmare, confirmareSources, inTab, type ConfirmareApi, type QueueTab } from "../../domain/confirmare";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ConfirmareRow, SourceFilter, confirmareQueueLoader } from "./ConfirmareParts";

/** Tab and source filter live in the address (replaceState), so returning from an order restores them. */
function readFilters(employee: Employee): { tab: QueueTab; source: string | null } {
  const params = new URLSearchParams(typeof window === "undefined" ? "" : window.location.search);
  const tab = params.get("tab");
  const source = params.get("source");
  const sources: readonly string[] = confirmareSources(employee);
  return { tab: (QUEUE_TABS as readonly string[]).includes(tab ?? "") ? tab as QueueTab : "mine", source: source && sources.includes(source) ? source : null };
}

function writeFilters(tab: QueueTab, source: string | null) {
  const params = new URLSearchParams();
  if (tab !== "mine") params.set("tab", tab);
  if (source) params.set("source", source);
  const query = params.toString();
  window.history.replaceState(window.history.state, "", `/confirmare${query ? `?${query}` : ""}`);
}

/**
 * The Confirmare queue: the presentation groups of the server's facts, filtered by the server-granted sources. It
 * refreshes in place every minute while visible (same tab, filter and scroll position); a failed refresh keeps the
 * last good list. Opening a row is the only action here.
 */
export function ConfirmareQueueScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const initial = useMemo(() => readFilters(employee), [employee]);
  const [tab, setTab] = useState<QueueTab>(initial.tab);
  const [source, setSource] = useState<string | null>(initial.source);
  const load = useMemo(() => confirmareQueueLoader(service, source), [service, source]);
  const queue = useAutoRefresh(load, `confirmare-queue|${source ?? "all"}`);
  const data = queue.data?.source === source ? queue.data : null;
  const supervisor = canSuperviseConfirmare(employee);
  const tabs = QUEUE_TABS.filter((id) => id !== "attention" || supervisor);
  const rows = data ? data.items.filter((item) => inTab(item, tab, employee.employeeUuid)) : null;
  const count = (id: QueueTab): number | undefined => data?.items.filter((item) => inTab(item, id, employee.employeeUuid)).length;
  const message = queue.error && (!data || queue.stopped) ? getErrorPresentation(queue.error).message : "";

  const chooseTab = (next: QueueTab) => { setTab(next); writeFilters(next, source); };
  const chooseSource = (next: string | null) => { setSource(next); writeFilters(tab, next); };

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-queue">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader title={t("cf.queue.title")} description={t("cf.queue.description")}
        actions={supervisor ? <button type="button" className="button button-secondary button-compact" onClick={() => navigate("/confirmare/team")}><AppIcon name="team" size={18} />{t("cf.home.team")}</button> : undefined} />
      <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={data !== null && queue.error !== null} stopped={queue.stopped} testId="cf-queue-refresh" />
      <SourceFilter sources={confirmareSources(employee)} value={source} onChange={chooseSource} />
      <div className="segmented cf-tabs" role="tablist" aria-label={t("cf.queue.tabs")}>
        {tabs.map((id) => <button key={id} id={`cf-tab-${id}`} type="button" role="tab" aria-selected={tab === id} aria-controls="cf-tab-panel" onClick={() => { if (id !== tab) chooseTab(id); }}>{t(`cf.tab.${id}` as MessageKey)}{count(id) !== undefined && <span>{count(id)}</span>}</button>)}
      </div>
      <p className="cf-tab-hint">{t(`cf.tabHint.${tab}` as MessageKey)}</p>
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      <div id="cf-tab-panel" role="tabpanel" aria-labelledby={`cf-tab-${tab}`}>
        {!rows && !message && <QueueSkeleton />}
        {rows && (rows.length === 0
          ? <EmptyPanel icon="orders" title={t("cf.queue.empty")}>{t(`cf.tabHint.${tab}` as MessageKey)}</EmptyPanel>
          : <ul className="queue-list" aria-label={t(`cf.tab.${tab}` as MessageKey)}>{rows.map((item) => <ConfirmareRow key={item.globalOrderId} item={item} employee={employee} navigate={navigate} />)}</ul>)}
      </div>
      {data && data.items.length >= 200 && <p className="dashboard-footnote">{t("cf.queue.limit")}</p>}
    </article>
  );
}
