import { useMemo } from "react";
import type { Employee } from "../../domain/models";
import { activityActionLabels, intakeLabel, marketplaceClassLabels, marketplaceLabel, type TrendyolApi, type TrendyolOverview, type TrendyolPackageSummary } from "../../domain/trendyol";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { trendyolDashboardLoader } from "../trendyol/trendyolRefresh";
import { AutoRefreshBar, DashboardSection, EmptyState, Metric, MetricGrid, QueueSkeleton, type MetricValue } from "./DashboardParts";
import { trendyolNextAction, type NextAction } from "./trendyolNextAction";
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
  const progress = view === "pending" && item.lineCount ? (item.preparedCount ?? 0) / item.lineCount : null;
  return (
    <li>
      <button type="button" className={`queue-row ${view === "pending" ? (missing === 0 ? "queue-available" : "queue-mine") : view === "attention" ? "queue-blocked" : "queue-claimedByOthers"}`} onClick={() => navigate(`/trendyol/${item.packageId}`)}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">Comanda #{item.orderNumber}</strong></span>
          <small>Pachet {item.packageId} · {formatDate(item.orderDate)} · Trendyol: {marketplaceLabel(item.marketplaceStatus)}</small>
          {item.changedAfterRelease && <small className="queue-row-reason">Modificată în Trendyol după aprobare — verifică</small>}
          {item.orderDateNearActivation && item.intakeStatus === "pending" && <small className="queue-row-reason">Plasată imediat după activare — verifică să nu fie deja preluată manual</small>}
          {progress !== null && <span className="line-progress" aria-hidden="true"><span style={{ transform: `scaleX(${progress})` }} /></span>}
        </span>
        <span className="queue-chip">{status}</span>
        <AppIcon name="chevron" size={18} />
      </button>
    </li>
  );
}

/** The one next step, chosen from data already on screen; a link to the existing screen, never an action itself. */
function NextActionCard({ action, navigate }: { action: NextAction; navigate: (path: string) => void }) {
  return (
    <section className={`next-action tone-${action.tone}`} aria-labelledby="trendyol-next" data-testid="trendyol-next-action">
      <div className="next-action-copy">
        <p className="eyebrow">{action.eyebrow}</p>
        <h2 id="trendyol-next">{action.title}</h2>
        {action.orderNumber && <p className="next-action-order order-id">#{action.orderNumber}</p>}
        <p className="next-action-detail">{action.detail}</p>
      </div>
      {action.cta && <button type="button" className="button button-primary next-action-cta" onClick={() => navigate(action.cta!.path)}>{action.cta.label}<AppIcon name="arrow" size={20} /></button>}
    </section>
  );
}

function NextActionSkeleton() {
  return <div className="next-action is-loading" aria-hidden="true"><div className="next-action-copy"><span className="skeleton skeleton-line" style={{ width: "28%" }} /><span className="skeleton skeleton-title" /><span className="skeleton skeleton-line" style={{ width: "54%" }} /></div><span className="skeleton next-action-cta-skeleton" /></div>;
}

/**
 * Trendyol operations dashboard for explicitly authorized Trendyol personnel. It only reads the Operations API
 * (never Trendyol itself) and reuses the existing package screens for preparation and approval. It refreshes every
 * minute while visible (overview only unless something changed); a failed refresh keeps the last good numbers.
 * Phones: the next step first, then the numbers and the lists. Desktop: work on the left, status on the right.
 */
export function TrendyolDashboard({ employee, service, navigate }: { employee: Employee; service: TrendyolApi; navigate: (path: string) => void }) {
  const load = useMemo(() => trendyolDashboardLoader(service), [service]);
  const dashboard = useAutoRefresh(load, "trendyol");
  const data = dashboard.data;
  const metric = (value: number | undefined): MetricValue => data ? value ?? "unavailable" : dashboard.error ? "unavailable" : "loading";
  const pendingPartial = data ? data.overview.counts.pending > data.pending.length : false;
  const connection = data ? connectionCopy[data.overview.intake.status] : null;
  const capabilities = data?.overview.capabilities;
  const next = data ? trendyolNextAction(data) : null;
  const loading = !data && !dashboard.error;

  return (
    <div className="workspace-body trendyol-workspace" data-testid="workspace-trendyol">
      <div className="workspace-main">
        {next ? <NextActionCard action={next} navigate={navigate} /> : loading ? <NextActionSkeleton /> : null}

        {loading && <DashboardSection labelledBy="trendyol-pending-loading" eyebrow="De pregătit" title="Comenzi de pregătit"><QueueSkeleton /></DashboardSection>}
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
      </div>

      <div className="workspace-side">
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
            <button type="button" className="button button-secondary" data-testid="home-trendyol" onClick={() => navigate("/trendyol")}><AppIcon name="orders" size={20} />Toate comenzile Trendyol</button>
          </div>
        </DashboardSection>

        {connection && data && (
          <section className={`connection-card tone-${connection.tone}`} aria-labelledby="trendyol-connection" data-testid="trendyol-connection">
            <span className="connection-icon" aria-hidden="true"><AppIcon name={connection.tone === "success" ? "sync" : "info"} size={20} /></span>
            <div>
              <p className="eyebrow">Conexiune Trendyol</p>
              <h2 id="trendyol-connection">{connection.label}</h2>
              <p>{connection.detail}</p>
              {data.overview.intake.lastRunAt && <p data-testid="trendyol-last-sync">Ultima sincronizare Trendyol: {formatDate(data.overview.intake.lastRunAt)}{lastRunFailed(data.overview.intake.lastRunOutcome) ? " · ultima citire nu a reușit; datele pot fi neactualizate" : ""}</p>}
            </div>
          </section>
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
                    <span><strong>{activityActionLabels[event.action]}</strong>{event.orderNumber && <small className="order-id">Comanda #{event.orderNumber}</small>}</span>
                    <time dateTime={event.at ?? undefined}>{formatDate(event.at)}</time>
                  </li>)}</ul>}
              </>}
          </DashboardSection>
        )}
      </div>
    </div>
  );
}
