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
