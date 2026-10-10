import { useMemo } from "react";
import type { Employee } from "../../domain/models";
import { activityActionLabels, intakeLabel, marketplaceClassLabels, marketplaceLabel, type TrendyolApi, type TrendyolOverview, type TrendyolPackageSummary } from "../../domain/trendyol";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { trendyolDashboardLoader } from "../trendyol/trendyolRefresh";
import { AutoRefreshBar, DashboardSection, EmptyState, Metric, MetricGrid, type MetricValue } from "./DashboardParts";
import { useAutoRefresh } from "./useAutoRefresh";

const LIST_LIMIT = 6;
const dateTime = new Intl.DateTimeFormat("ro-RO", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" });
const formatDate = (value: string | null): string => value ? dateTime.format(new Date(value)) : "—";

/** Lines still missing confirmed production data across the listed pending packages (the API lists up to 200). */
export function linesToComplete(pending: readonly TrendyolPackageSummary[]): number {
  return pending.reduce((sum, item) => sum + Math.max(0, (item.lineCount ?? 0) - (item.preparedCount ?? 0)), 0);
}

export const connectionCopy: Record<TrendyolOverview["intake"]["status"], { label: string; detail: string; tone: "neutral" | "success" | "warning" }> = {
  inactive: { label: "Inactivă", detail: "Conexiunea Trendyol nu este activată. Nu se citesc comenzi și nu apar comenzi noi aici.", tone: "neutral" },
  active: { label: "Activă · numai citire", detail: "Comenzile noi sunt citite din Trendyol. Arasya nu schimbă nimic în Trendyol.", tone: "success" },
  paused: { label: "Oprită temporar", detail: "Citirea comenzilor este oprită temporar. Comenzile deja primite pot fi lucrate.", tone: "warning" },
};

/** Synchronization outcomes: `ok` and `truncated` (continues on the next run) are progress; anything else failed. */
export function lastRunFailed(outcome: string | null): boolean {
  return outcome !== null && outcome !== "ok" && outcome !== "truncated";
}

function PackageRow({ item, view, navigate }: { item: TrendyolPackageSummary; view: "pending" | "attention" | "released"; navigate: (path: string) => void }) {
  const missing = Math.max(0, (item.lineCount ?? 0) - (item.preparedCount ?? 0));
  const status = view === "attention"
    ? (item.marketplaceStatusKnown ? marketplaceClassLabels[item.marketplaceClass] : "Status necunoscut — verificare necesară")
    : view === "pending" && item.lineCount !== undefined
      ? (missing === 0 ? "Gata de aprobare" : `${item.preparedCount ?? 0}/${item.lineCount} linii pregătite`)
      : intakeLabel(item);
  return (
    <li>
      <button type="button" className={`queue-row ${view === "pending" ? (missing === 0 ? "queue-available" : "queue-mine") : view === "attention" ? "queue-blocked" : "queue-claimedByOthers"}`} onClick={() => navigate(`/trendyol/${item.packageId}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong>Comanda #{item.orderNumber}</strong></span>
          <small>Pachet {item.packageId} · {formatDate(item.orderDate)} · Trendyol: {marketplaceLabel(item.marketplaceStatus)}</small>
          {item.changedAfterRelease && <small className="queue-row-reason">Modificată în Trendyol după aprobare — verifică</small>}
          {item.orderDateNearActivation && item.intakeStatus === "pending" && <small className="queue-row-reason">Plasată imediat după activare — verifică să nu fie deja preluată manual</small>}
        </span>
        <span className="queue-chip">{status}</span>
        <AppIcon name="arrow" size={18} />
      </button>
    </li>
  );
}

/**
 * Trendyol operations dashboard for explicitly authorized Trendyol personnel. It only reads the Operations API
 * (never Trendyol itself) and reuses the existing package screens for preparation and approval. It refreshes every
 * minute while visible (overview only unless something changed); a failed refresh keeps the last good numbers.
 */
export function TrendyolDashboard({ employee, service, navigate }: { employee: Employee; service: TrendyolApi; navigate: (path: string) => void }) {
  const load = useMemo(() => trendyolDashboardLoader(service), [service]);
  const dashboard = useAutoRefresh(load, "trendyol");
  const data = dashboard.data;
  const metric = (value: number | undefined): MetricValue => data ? value ?? "unavailable" : dashboard.error ? "unavailable" : "loading";
  const pendingPartial = data ? data.overview.counts.pending > data.pending.length : false;
  const connection = data ? connectionCopy[data.overview.intake.status] : null;
  const capabilities = data?.overview.capabilities;

  return (
    <div className="workspace-body" data-testid="workspace-trendyol">
      <DashboardSection labelledBy="trendyol-today" eyebrow="Panou operațional Trendyol" title="Activitatea de astăzi"
        action={<AutoRefreshBar onRefresh={dashboard.refresh} busy={dashboard.busy} refreshing={dashboard.refreshing} updatedAt={dashboard.updatedAt} stale={data !== null && dashboard.error !== null} stopped={dashboard.stopped} testId="trendyol-refresh" />}>
        <MetricGrid label="Situația comenzilor Trendyol">
          <Metric label="Comenzi de pregătit" value={metric(data?.overview.counts.pending)} tone="action" testId="metric-trendyol-pending" />
          <Metric label="Linii de completat" value={metric(data ? linesToComplete(data.pending) : undefined)} partial={pendingPartial} testId="metric-trendyol-lines" />
          <Metric label="Necesită atenție" value={metric(data?.overview.counts.attention)} tone="warning" testId="metric-trendyol-attention" />
          <Metric label="Trimise în producție" value={metric(data?.overview.counts.released)} tone="success" testId="metric-trendyol-released" />
        </MetricGrid>
        {dashboard.error && (!data || dashboard.stopped) && <ErrorState error={dashboard.error} compact onAction={dashboard.refresh} />}
        <div className="dashboard-actions">
          <button type="button" className="button button-primary" data-testid="home-trendyol" onClick={() => navigate("/trendyol")}>Toate comenzile Trendyol</button>
        </div>
      </DashboardSection>

      {connection && data && (
        <section className={`connection-card tone-${connection.tone}`} aria-labelledby="trendyol-connection" data-testid="trendyol-connection">
          <p className="eyebrow">Conexiune Trendyol</p>
          <h2 id="trendyol-connection">{connection.label}</h2>
          <p>{connection.detail}</p>
          {data.overview.intake.lastRunAt && <p data-testid="trendyol-last-sync">Ultima sincronizare Trendyol: {formatDate(data.overview.intake.lastRunAt)}{lastRunFailed(data.overview.intake.lastRunOutcome) ? " · ultima citire nu a reușit; datele pot fi neactualizate" : ""}</p>}
        </section>
      )}

      {data && (
        <DashboardSection labelledBy="trendyol-pending" eyebrow="De pregătit" title="Comenzi de pregătit" action={data.pending.length > LIST_LIMIT ? <button type="button" className="button button-link" onClick={() => navigate("/trendyol")}>Vezi toate</button> : undefined}>
          {data.pending.length === 0
            ? <EmptyState title="Nu există comenzi de pregătit">{data.overview.intake.status === "inactive" ? "Conexiunea Trendyol nu este activată încă." : "Comenzile noi apar aici automat după citire."}</EmptyState>
            : <ul className="queue-list">{data.pending.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="pending" navigate={navigate} />)}</ul>}
          {capabilities && !capabilities.prepare && <p className="dashboard-footnote">Poți vedea comenzile; completarea măsurilor necesită permisiunea de pregătire.</p>}
          {capabilities && capabilities.prepare && !capabilities.release && <p className="dashboard-footnote">Completezi măsurile; aprobarea intrării în producție o face o persoană autorizată.</p>}
        </DashboardSection>
      )}

      {data && data.attention.length > 0 && (
        <DashboardSection labelledBy="trendyol-attention" eyebrow="Verificare manuală" title="Necesită atenție">
          <ul className="queue-list">{data.attention.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="attention" navigate={navigate} />)}</ul>
          <p className="dashboard-footnote">Aceste comenzi nu pot intra în producție în statusul actual din Trendyol (plată neconfirmată, pregătită pentru curier sau status necunoscut).</p>
        </DashboardSection>
      )}

      {data && data.released.length > 0 && (
        <DashboardSection labelledBy="trendyol-released" eyebrow="În producție" title="Trimise recent în producție">
          <ul className="queue-list">{data.released.slice(0, LIST_LIMIT).map((item) => <PackageRow key={item.packageId} item={item} view="released" navigate={navigate} />)}</ul>
        </DashboardSection>
      )}

      {data && (
        <DashboardSection labelledBy="trendyol-mine" eyebrow={employee.displayName} title="Activitatea mea">
          {data.activity === null
            ? <p className="order-notice order-notice-muted">Activitatea ta nu este disponibilă momentan.</p>
            : <>
              <MetricGrid label="Activitatea mea astăzi">
                <Metric label="Linii pregătite azi" value={data.activity.today.linesPrepared} />
                <Metric label="Aprobate azi" value={data.activity.today.released} />
              </MetricGrid>
              {data.activity.recent.length === 0
                ? <EmptyState title="Nicio acțiune încă" />
                : <ul className="activity-list">{data.activity.recent.map((event, index) => <li key={`${event.packageId}-${event.at}-${index}`}>
                  <span><strong>{activityActionLabels[event.action]}</strong>{event.orderNumber && <small>Comanda #{event.orderNumber}</small>}</span>
                  <time dateTime={event.at ?? undefined}>{formatDate(event.at)}</time>
                </li>)}</ul>}
            </>}
        </DashboardSection>
      )}
    </div>
  );
}
