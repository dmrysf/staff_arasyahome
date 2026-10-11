import type { ReactNode } from "react";
import { AppIcon, type AppIconName } from "../icons/AppIcon";

export type Tone = "neutral" | "accent" | "success" | "warning" | "danger" | "info";

/** A status always reads as words with an icon; the tone only reinforces it. */
export function StatusPill({ tone = "neutral", icon, children, testId }: { tone?: Tone; icon?: AppIconName; children: ReactNode; testId?: string }) {
  return <span className={`status-pill tone-${tone}`} data-testid={testId}>{icon && <AppIcon name={icon} size={14} />}{children}</span>;
}

const noticeIcons: Record<Tone, AppIconName> = { neutral: "info", accent: "info", info: "info", success: "check", warning: "alert", danger: "alert" };

/** Inline notice. `role` is chosen by the caller: "alert" only for a blocking problem, "status" for results. */
export function Notice({ tone = "neutral", role, children, testId, entering = false }: { tone?: Tone; role?: "alert" | "status" | "note"; children: ReactNode; testId?: string; entering?: boolean }) {
  return <p className={`notice tone-${tone}${entering ? " is-entering" : ""}`} role={role} data-testid={testId}><AppIcon name={noticeIcons[tone]} size={18} /><span>{children}</span></p>;
}
