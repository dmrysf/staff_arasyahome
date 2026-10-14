import { useMemo, useState } from "react";
import { formatDate, useI18n } from "../../i18n";
import { ignoredReasonLabels, intakeStateLabels, marketplaceLabel, type TrendyolApi, type TrendyolView } from "../../domain/trendyol";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { PackageRow } from "../workspaces/TrendyolDashboard";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { TrendyolLogo } from "../../components/brand/BrandLogo";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { trendyolInboxLoader } from "./trendyolRefresh";

const views: readonly TrendyolView[] = ["pending", "attention", "released", "closed", "earlier", "ignored"];

const date = (value: string | null): string => value ? formatDate(value, { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) : "—";

/**
 * Trendyol workspace inbox for explicitly authorized Trendyol personnel. Packages here are not production orders:
 * they enter production only after an explicit approval on the package screen. The selected list refreshes every
 * minute while visible, in place (same tab, same scroll position); a failed refresh keeps the last good list.
 */
export function TrendyolInboxScreen({ service, navigate }: { service: TrendyolApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [view, setView] = useState<TrendyolView>("pending");
  const load = useMemo(() => trendyolInboxLoader(service, view), [service, view]);
  const inbox = useAutoRefresh(load, view);
  // A list is shown only for the tab it was read for; the counts of the previous read stay while a tab loads.
  const overview = inbox.data?.overview ?? null;
  const current = inbox.data?.view === view ? inbox.data : null;
  const items = current?.items ?? null;
  const ignored = current?.ignored ?? null;
  const message = inbox.error && (!current || inbox.stopped) ? getErrorPresentation(inbox.error).message : "";

  const count = (id: TrendyolView): number | undefined => overview?.counts[id];
  return (
    <article className="screen-stack trendyol-screen trendyol-inbox">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <PageHeader brand={<TrendyolLogo className="inbox-logo" />} title={t("ty.inbox.title")} description={t("ty.inbox.description")} />
      <div className="inbox-status">
        {overview && <p className={`inbox-connection is-${overview.intake.status}`} data-testid="trendyol-intake-state">{intakeStateLabels[overview.intake.status]}{overview.intake.lastRunAt ? ` ${t("ty.inbox.lastSync", { date: date(overview.intake.lastRunAt) })}` : ""}</p>}
        {overview?.intake.baselineAt && <p className="inbox-cohort" data-testid="trendyol-cohort">{t("ty.inbox.cohort", { date: date(overview.intake.baselineAt) })}</p>}
        <AutoRefreshBar onRefresh={inbox.refresh} busy={inbox.busy} refreshing={inbox.refreshing} updatedAt={inbox.updatedAt} stale={current !== null && inbox.error !== null} stopped={inbox.stopped} testId="trendyol-inbox-refresh" />
      </div>
      <div className="segmented trendyol-tabs" role="tablist" aria-label={t("ty.inbox.filter")}>
        {views.map((item) => <button key={item} type="button" role="tab" aria-selected={view === item} onClick={() => { if (item !== view) setView(item); }}>{t(`ty.view.${item}`)}{count(item) !== undefined && <span>{count(item)}</span>}</button>)}
      </div>
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {view === "earlier" && <p className="order-notice order-notice-muted" data-testid="trendyol-earlier-hint">{t("ty.inbox.earlierHint")}</p>}
      {view !== "ignored" && !items && !message && <QueueSkeleton />}
      {view !== "ignored" && items && (items.length === 0
        ? <EmptyPanel icon="package" title={t("ty.inbox.empty")}>{t("ty.inbox.emptyHint")}</EmptyPanel>
        : <ul className="queue-list trendyol-list" aria-label={t("ty.inbox.packages")}>
          {items.map((item) => <PackageRow key={item.packageId} item={item} view={view} navigate={navigate} />)}
        </ul>)}
      {view === "ignored" && ignored && (ignored.length === 0
        ? <EmptyPanel icon="package" title={t("ty.inbox.noIgnored")} />
        : <ul className="trendyol-list" aria-label={t("ty.inbox.ignoredPackages")}>
          {ignored.map((item) => <li key={item.packageId} className="trendyol-ignored">
            <strong>{t("common.order", { number: item.orderNumber })}</strong>
            <small>{t("ty.inbox.packageLine", { id: item.packageId, date: date(item.orderDate), status: marketplaceLabel(item.marketplaceStatus) })}</small>
            <small>{t("ty.inbox.notAutomatic", { reason: ignoredReasonLabels[item.reason] })}</small>
          </li>)}
        </ul>)}
    </article>
  );
}
