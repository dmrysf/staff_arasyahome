import type { ReactNode } from "react";
import type { Workspace } from "../../domain/workspaces";
import { AppIcon } from "../../components/icons/AppIcon";

/** A metric is either a real server value, still loading, or unavailable; never a placeholder number. */
export type MetricValue = number | "loading" | "unavailable";

export function formatMetric(value: MetricValue, partial = false): string {
  if (value === "loading") return "…";
  if (value === "unavailable") return "—";
  return partial ? `${value}+` : String(value);
}

export function Metric({ label, value, hint, tone = "neutral", partial = false, testId }: { label: string; value: MetricValue; hint?: string; tone?: "neutral" | "action" | "warning" | "success"; partial?: boolean; testId?: string }) {
  return (
    <div className={`dashboard-metric tone-${tone}`} data-testid={testId}>
      <dd className={typeof value === "number" ? undefined : "metric-unavailable"}>{formatMetric(value, partial)}</dd>
      <dt>{label}</dt>
      {hint && <small>{hint}</small>}
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

const clock = new Intl.DateTimeFormat("ro-RO", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" });

export function RefreshButton({ onRefresh, busy, updatedAt }: { onRefresh: () => void; busy: boolean; updatedAt: Date | null }) {
  return (
    <div className="dashboard-refresh">
      {updatedAt && <span aria-live="polite">Ultima actualizare {clock.format(updatedAt)}</span>}
      <button type="button" className="button button-secondary button-compact" onClick={onRefresh} disabled={busy} aria-label="Actualizează panoul">
        <AppIcon name="refresh" size={18} /> {busy ? "Se actualizează…" : "Actualizează"}
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
  return (
    <div className="dashboard-refresh" data-testid={testId}>
      <span>{stopped ? "Actualizare automată oprită" : "Actualizare automată la fiecare minut"}</span>
      {updatedAt !== null && <span>Ultima actualizare {clock.format(updatedAt)}</span>}
      {refreshing && <span>Se actualizează…</span>}
      {stale && !busy && <span className="refresh-stale" role="status">Datele pot fi neactualizate</span>}
      <button type="button" className="button button-secondary button-compact" onClick={onRefresh} disabled={busy} aria-label="Actualizează panoul">
        <AppIcon name="refresh" size={18} /> {busy ? "Se actualizează…" : "Actualizează"}
      </button>
    </div>
  );
}

/** Shown only when the employee holds more than one authorized workspace; switching never changes permissions. */
export function WorkspaceSwitcher({ workspaces, current, onSelect }: { workspaces: readonly Workspace[]; current: Workspace; onSelect: (workspace: Workspace) => void }) {
  if (workspaces.length < 2) return null;
  return (
    <nav className="workspace-switcher" aria-label="Spațiile mele de lucru">
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
