import { useMemo, useState } from "react";
import { ignoredReasonLabels, intakeStateLabels, marketplaceLabel, type TrendyolApi, type TrendyolView } from "../../domain/trendyol";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { AutoRefreshBar, QueueSkeleton } from "../workspaces/DashboardParts";
import { PackageRow } from "../workspaces/TrendyolDashboard";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { TrendyolLogo } from "../../components/brand/BrandLogo";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { trendyolInboxLoader } from "./trendyolRefresh";

const views: { id: TrendyolView; label: string }[] = [
  { id: "pending", label: "De pregătit" },
  { id: "attention", label: "Necesită atenție" },
  { id: "released", label: "În producție" },
  { id: "closed", label: "Închise" },
  { id: "ignored", label: "Ignorate" },
];

const date = (value: string | null): string => value ? new Date(value).toLocaleString("ro-RO", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) : "—";

/**
 * Trendyol workspace inbox for explicitly authorized Trendyol personnel. Packages here are not production orders:
 * they enter production only after an explicit approval on the package screen. The selected list refreshes every
 * minute while visible, in place (same tab, same scroll position); a failed refresh keeps the last good list.
 */
export function TrendyolInboxScreen({ service, navigate }: { service: TrendyolApi; navigate: (path: string) => void }) {
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
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <PageHeader brand={<TrendyolLogo className="inbox-logo" />} title="Comenzi Trendyol" description="Comenzile noi din Trendyol intră aici, nu direct în fabrică. Completezi măsurile, apoi o persoană autorizată aprobă intrarea în producție. Statusurile, expedierea și facturile rămân în Trendyol Seller Panel." />
      <div className="inbox-status">
        {overview && <p className={`inbox-connection is-${overview.intake.status}`} data-testid="trendyol-intake-state">{intakeStateLabels[overview.intake.status]}{overview.intake.lastRunAt ? ` Ultima sincronizare Trendyol: ${date(overview.intake.lastRunAt)}.` : ""}</p>}
        <AutoRefreshBar onRefresh={inbox.refresh} busy={inbox.busy} refreshing={inbox.refreshing} updatedAt={inbox.updatedAt} stale={current !== null && inbox.error !== null} stopped={inbox.stopped} testId="trendyol-inbox-refresh" />
      </div>
      <div className="segmented trendyol-tabs" role="tablist" aria-label="Filtrare comenzi Trendyol">
        {views.map((item) => <button key={item.id} type="button" role="tab" aria-selected={view === item.id} onClick={() => { if (item.id !== view) setView(item.id); }}>{item.label}{count(item.id) !== undefined && <span>{count(item.id)}</span>}</button>)}
      </div>
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {view !== "ignored" && !items && !message && <QueueSkeleton />}
      {view !== "ignored" && items && (items.length === 0
        ? <EmptyPanel icon="package" title="Nu există comenzi în această listă.">Lista se actualizează automat la fiecare minut.</EmptyPanel>
        : <ul className="queue-list trendyol-list" aria-label="Pachete Trendyol">
          {items.map((item) => <PackageRow key={item.packageId} item={item} view={view} navigate={navigate} />)}
        </ul>)}
      {view === "ignored" && ignored && (ignored.length === 0
        ? <EmptyPanel icon="package" title="Nicio comandă ignorată." />
        : <ul className="trendyol-list" aria-label="Pachete ignorate">
          {ignored.map((item) => <li key={item.packageId} className="trendyol-ignored">
            <strong>Comanda #{item.orderNumber}</strong>
            <small>Pachet {item.packageId} · {date(item.orderDate)} · Trendyol: {marketplaceLabel(item.marketplaceStatus)}</small>
            <small>{ignoredReasonLabels[item.reason]} · nu intră în producție automat</small>
          </li>)}
        </ul>)}
    </article>
  );
}
