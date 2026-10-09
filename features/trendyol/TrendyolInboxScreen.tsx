import { useEffect, useState } from "react";
import { ignoredReasonLabels, intakeLabel, intakeStateLabels, marketplaceClassLabels, marketplaceLabel, type TrendyolApi, type TrendyolIgnoredPackage, type TrendyolOverview, type TrendyolPackageSummary, type TrendyolView } from "../../domain/trendyol";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";

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
 * they enter production only after an explicit approval on the package screen.
 */
export function TrendyolInboxScreen({ service, navigate }: { service: TrendyolApi; navigate: (path: string) => void }) {
  const [view, setView] = useState<TrendyolView>("pending");
  const [overview, setOverview] = useState<TrendyolOverview | null>(null);
  const [items, setItems] = useState<TrendyolPackageSummary[] | null>(null);
  const [ignored, setIgnored] = useState<TrendyolIgnoredPackage[] | null>(null);
  const [message, setMessage] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    service.overview(controller.signal).then(setOverview).catch((caught) => { if (!controller.signal.aborted) setMessage(getErrorPresentation(toServiceError(caught)).message); });
    return () => controller.abort();
  }, [service]);

  useEffect(() => {
    const controller = new AbortController();
    const load = view === "ignored"
      ? service.ignored(controller.signal).then(setIgnored)
      : service.list(view, controller.signal).then(setItems);
    load.catch((caught) => { if (!controller.signal.aborted) setMessage(getErrorPresentation(toServiceError(caught)).message); });
    return () => controller.abort();
  }, [service, view]);

  const count = (id: TrendyolView): number | undefined => overview?.counts[id];
  return (
    <article className="screen-stack detail-screen trendyol-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <section className="detail-section">
        <p className="eyebrow">Trendyol</p>
        <h1>Comenzi Trendyol</h1>
        <p>Comenzile noi din Trendyol intră aici, nu direct în fabrică. Completezi măsurile, apoi o persoană autorizată aprobă intrarea în producție. Statusurile, expedierea și facturile rămân în Trendyol Seller Panel.</p>
        {overview && <p className="order-notice order-notice-muted" role="status" data-testid="trendyol-intake-state">{intakeStateLabels[overview.intake.status]}{overview.intake.lastRunAt ? ` Ultima citire: ${date(overview.intake.lastRunAt)}.` : ""}</p>}
      </section>
      <div className="segmented trendyol-tabs" role="tablist" aria-label="Filtrare comenzi Trendyol">
        {views.map((item) => <button key={item.id} type="button" role="tab" aria-selected={view === item.id} onClick={() => { if (item.id === view) return; setItems(null); setIgnored(null); setMessage(""); setView(item.id); }}>{item.label}{count(item.id) !== undefined && <span>{count(item.id)}</span>}</button>)}
      </div>
      {message && <p className="order-notice order-notice-muted" role="alert">{message}</p>}
      {view !== "ignored" && items && (items.length === 0
        ? <p className="order-notice order-notice-muted">Nu există comenzi în această listă.</p>
        : <ul className="trendyol-list" aria-label="Pachete Trendyol">
          {items.map((item) => <li key={item.packageId}>
            <button type="button" className={view === "pending" ? "exception-row needs-action" : "exception-row"} onClick={() => navigate(`/trendyol/${item.packageId}`)}>
              <span><strong>Comanda #{item.orderNumber}</strong><small>Pachet {item.packageId} · {date(item.orderDate)} · Trendyol: {marketplaceLabel(item.marketplaceStatus)}</small></span>
              <span className="exception-status">{view === "attention" ? (item.marketplaceStatusKnown ? marketplaceClassLabels[item.marketplaceClass] : "Status necunoscut — verificare necesară") : item.intakeStatus === "pending" && item.lineCount !== undefined ? `${item.preparedCount}/${item.lineCount} linii pregătite` : intakeLabel(item)}{item.changedAfterRelease ? " · modificată după aprobare" : ""}{item.orderDateNearActivation && item.intakeStatus === "pending" ? " · verifică dublura manuală" : ""}</span>
            </button>
          </li>)}
        </ul>)}
      {view === "ignored" && ignored && (ignored.length === 0
        ? <p className="order-notice order-notice-muted">Nicio comandă ignorată.</p>
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
