import type { ReactNode } from "react";
import { AppIcon, type AppIconName } from "../icons/AppIcon";

/** Full-screen start-up and blocking states (session check, workflow load): brand, one sentence, optional action. */
export function BootScreen({ message, busy = false, role, action }: { message: string; busy?: boolean; role?: "alert"; action?: ReactNode }) {
  return (
    <main className="boot-screen" role={role} aria-live={busy ? "polite" : undefined} aria-busy={busy || undefined}>
      <span className="brand-mark" aria-hidden="true">A</span>
      <p>{message}</p>
      {busy && <span className="boot-progress" aria-hidden="true" />}
      {action}
    </main>
  );
}

/**
 * Loading placeholder shaped like the content it stands for. The label is announced once; nothing here reads data,
 * so a loading state can never produce a request.
 */
export function LoadingState({ label, shape = "list" }: { label: string; shape?: "list" | "detail" }) {
  return (
    <div className="loading-state" role="status" aria-live="polite">
      <p className="loading-label"><span className="inline-spinner" aria-hidden="true" />{label}</p>
      {shape === "list"
        ? <>{[0, 1, 2].map((index) => <span key={index} className="skeleton skeleton-card" aria-hidden="true" />)}</>
        : <><span className="skeleton skeleton-title" aria-hidden="true" /><span className="skeleton skeleton-block" aria-hidden="true" /><span className="skeleton skeleton-card" aria-hidden="true" /></>}
    </div>
  );
}

/** An empty list says what will appear and, when there is one, the next useful action. */
export function EmptyPanel({ title, children, icon = "orders", action }: { title: string; children?: ReactNode; icon?: AppIconName; action?: ReactNode }) {
  return (
    <div className="empty-state">
      <span className="state-icon" aria-hidden="true"><AppIcon name={icon} size={24} /></span>
      <h2>{title}</h2>
      {children && <p>{children}</p>}
      {action}
    </div>
  );
}

/** Screen title block: optional eyebrow, the h1, one short sentence, optional actions on the right. */
export function PageHeader({ title, eyebrow, description, actions }: { title: ReactNode; eyebrow?: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="page-header">
      {eyebrow && <p className="eyebrow">{eyebrow}</p>}
      <div className="page-header-row"><h1>{title}</h1>{actions}</div>
      {description && <p>{description}</p>}
    </header>
  );
}
