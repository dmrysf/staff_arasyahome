import type { ReactNode } from "react";
import { formatDate, useI18n } from "../../i18n";
import type { Workspace } from "../../domain/workspaces";
import type { NextAction } from "./trendyolNextAction";
import { AppIcon } from "../../components/icons/AppIcon";

/** A metric is either a real server value, still loading, or unavailable; never a placeholder number. */
export type MetricValue = number | "loading" | "unavailable";

export function formatMetric(value: MetricValue, partial = false): string {
  if (value === "loading") return "…";
  if (value === "unavailable") return "—";
  return partial ? `${value}+` : String(value);
}

export function Metric({ label, value, hint, hintLang, tone = "neutral", partial = false, testId }: { label: string; value: MetricValue; hint?: string; hintLang?: string; tone?: "neutral" | "action" | "warning" | "success"; partial?: boolean; testId?: string }) {
  return (
    <div className={`dashboard-metric tone-${tone}`} data-testid={testId}>
      {/* A changed number remounts its span (keyed by value) and settles in once; an unchanged refresh does nothing. */}
      <dd className={typeof value === "number" ? undefined : "metric-unavailable"}>{typeof value === "number" ? <span key={formatMetric(value, partial)} className="metric-number">{formatMetric(value, partial)}</span> : formatMetric(value, partial)}</dd>
      <dt>{label}</dt>
      {hint && <small lang={hintLang}>{hint}</small>}
    </div>
  );
}

export function MetricGrid({ label, children }: { label: string; children: ReactNode }) {
  return <dl className="dashboard-metrics" aria-label={label}>{children}</dl>;
}

export function DashboardSection({ title, eyebrow, action, children, labelledBy }: { title: string; eyebrow?: string; action?: ReactNode; children: ReactNode; labelledBy: string }) {
  return (
    <section className="dashboard-section" aria-labelledby={labelledBy}>
      <div className="dashboard-section-heading">
        <div>{eyebrow && <p className="eyebrow">{eyebrow}</p>}<h2 id={labelledBy}>{title}</h2></div>
        {action}
      </div>
      {children}
    </section>
  );
}

const clock = { format: (value: Date | number): string => formatDate(value, { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" }) };

export function RefreshButton({ onRefresh, busy, updatedAt }: { onRefresh: () => void; busy: boolean; updatedAt: Date | null }) {
  const { t } = useI18n();
  return (
    <div className="dashboard-refresh">
      {updatedAt && <span aria-live="polite">{t("common.lastUpdate", { time: clock.format(updatedAt) })}</span>}
      <button type="button" className="button button-secondary button-compact" onClick={onRefresh} disabled={busy} aria-label={t("common.updatePanel")}>
        <AppIcon name="refresh" size={18} /> {busy ? t("common.updating") : t("common.update")}
      </button>
    </div>
  );
}

/**
 * Automatic refresh status of the Trendyol workspace. "Ultima actualizare" is the last successful Staff read of the
 * Operations API; it says nothing about when Arasya last synchronized with Trendyol (shown separately). A failed
 * background read keeps the last good data and says so once, quietly, instead of an alert every minute.
 */
export function AutoRefreshBar({ onRefresh, busy, refreshing, updatedAt, stale, stopped, testId }: { onRefresh: () => void; busy: boolean; refreshing: boolean; updatedAt: number | null; stale: boolean; stopped: boolean; testId?: string }) {
  const { t } = useI18n();
  return (
    <div className={`dashboard-refresh${stopped ? " is-stopped" : stale ? " is-stale" : refreshing || busy ? " is-refreshing" : ""}`} data-testid={testId}>
      <span className="refresh-pulse" aria-hidden="true" />
      <span className="refresh-copy">
        <span>{stopped ? t("refresh.stopped") : t("refresh.everyMinute")}</span>
        {updatedAt !== null && <span className="refresh-time">{t("common.lastUpdate", { time: clock.format(updatedAt) })}</span>}
        {refreshing && <span>{t("common.updating")}</span>}
        {stale && !busy && <span className="refresh-stale" role="status">{t("refresh.stale")}</span>}
      </span>
      <button type="button" className="button button-ghost button-compact refresh-button" onClick={onRefresh} disabled={busy} aria-label={t("common.updatePanel")}>
        <AppIcon name="refresh" size={18} className={busy || refreshing ? "is-spinning" : undefined} /> <span className="refresh-button-label">{busy ? t("common.updating") : t("common.update")}</span>
      </button>
    </div>
  );
}

/** Shown only when the employee holds more than one authorized workspace; switching never changes permissions. */
export function WorkspaceSwitcher({ workspaces, current, onSelect }: { workspaces: readonly Workspace[]; current: Workspace; onSelect: (workspace: Workspace) => void }) {
  const { t } = useI18n();
  if (workspaces.length < 2) return null;
  return (
    <nav className="workspace-switcher" aria-label={t("workspace.switcher")}>
      {workspaces.map((workspace) => (
        <button key={workspace.id} type="button" aria-pressed={workspace.id === current.id} onClick={() => { if (workspace.id !== current.id) onSelect(workspace); }}>
          <strong>{workspace.label}</strong>
          <small>{workspace.description}</small>
        </button>
      ))}
    </nav>
  );
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return <div className="dashboard-empty"><strong>{title}</strong>{children && <p>{children}</p>}</div>;
}

/** Placeholder rows shaped like the queue rows they stand for, shown only before the first answer. */
export function QueueSkeleton({ rows = 3 }: { rows?: number }) {
  return (
    <ul className="queue-list queue-skeleton" aria-hidden="true">
      {Array.from({ length: rows }, (_, index) => <li key={index} className="queue-row"><span className="queue-row-main"><span className="skeleton skeleton-title" /><span className="skeleton skeleton-line" style={{ width: `${70 - index * 12}%` }} /></span></li>)}
    </ul>
  );
}

/** The one next step, chosen from data already on screen; a link to the existing screen, never an action itself. */
export function NextActionCard({ action, navigate, id, testId, secondary }: { action: NextAction; navigate: (path: string) => void; id: string; testId: string; secondary?: ReactNode }) {
  return (
    <section className={`next-action tone-${action.tone}`} aria-labelledby={id} data-testid={testId}>
      <div className="next-action-copy">
        <p className="eyebrow">{action.eyebrow}</p>
        <h2 id={id}>{action.title}</h2>
        {action.orderNumber && <p className="next-action-order order-id">#{action.orderNumber}</p>}
        <p className="next-action-detail">{action.detail}</p>
      </div>
      {(action.cta || secondary) && <div className="next-action-actions">
        {action.cta && <button type="button" className="button button-primary next-action-cta" onClick={() => navigate(action.cta!.path)}>{action.cta.label}<AppIcon name="arrow" size={20} /></button>}
        {secondary}
      </div>}
    </section>
  );
}

export function NextActionSkeleton() {
  return <div className="next-action is-loading" aria-hidden="true"><div className="next-action-copy"><span className="skeleton skeleton-line" style={{ width: "28%" }} /><span className="skeleton skeleton-title" /><span className="skeleton skeleton-line" style={{ width: "54%" }} /></div><span className="skeleton next-action-cta-skeleton" /></div>;
}
