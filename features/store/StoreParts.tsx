import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { formatDate, t, useI18n, type MessageKey } from "../../i18n";
import { StaffServiceError } from "../../domain/models";
import { toServiceError } from "../../services/errors";
import { sourceLabel } from "../../domain/confirmare";
import { toneOf, type Tone } from "../../domain/storeWorkspace";
import { AppIcon, type AppIconName } from "../../components/icons/AppIcon";

/** Shared pieces of the Store Workspace (Staff 2.59.0). Presentation only; every rule is the server's. */

const ZONE = "Europe/Bucharest";
export const fmtTime = (iso: string) => formatDate(iso, { timeZone: ZONE, hour: "2-digit", minute: "2-digit" });
export const fmtDateTime = (iso: string) => formatDate(iso, { timeZone: ZONE, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
export const fmtDay = (date: Date) => formatDate(date, { timeZone: ZONE, weekday: "long", day: "numeric", month: "long" });
export const shopName = (source: string) => sourceLabel(source);
export function money(amount: string | null): string {
  if (amount === null) return "—";
  const value = Number(amount);
  return Number.isFinite(value) ? `${value.toLocaleString("ro-RO", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} RON` : "—";
}

/** The Romanian (or active language) text of a refusal / blocker code; never a raw key. */
export function reasonText(code: string): string {
  const key = `sw.reason.${code}` as MessageKey;
  const text = t(key);
  return text === key ? t("sw.reason.other") : text;
}

/** The address check code as text. */
export function addressText(code: string): string {
  const key = `sw.addr.issue.${code}` as MessageKey;
  const text = t(key);
  return text === key ? t("sw.reason.address_invalid") : text;
}

/** What an error of a store action means for the employee: the reason code, its tone and FAN Courier's own text. */
export function explain(error: unknown): { reason: string; tone: Tone; message: string | null; issues: string[] } {
  const e = error instanceof StaffServiceError ? error : toServiceError(error);
  const d = (e.details ?? {}) as Record<string, unknown>;
  const backend = typeof d.backendCode === "string" ? d.backendCode : "";
  let reason = typeof d.editReason === "string" ? d.editReason : "";
  if (!reason) {
    if (backend === "CONFIRMARE_SOURCE_UNAVAILABLE" || e.code === "SERVICE_UNAVAILABLE" || e.code === "NETWORK_UNAVAILABLE" || e.code === "REQUEST_TIMEOUT") reason = "unavailable";
    else if (backend === "CONFIRMARE_OPERATION_DENIED" || e.code === "UNAUTHORIZED_ACTION") reason = "denied";
    else if (backend === "CONFIRMARE_AWB_COD_MISMATCH") reason = "cod_mismatch";
    else if (backend === "CONFIRMARE_AWB_LABEL_UNAVAILABLE") reason = "label_unavailable";
    else reason = "other";
  }
  return { reason, tone: reason === "unavailable" ? "yellow" : toneOf(reason), message: typeof d.shopMessage === "string" ? d.shopMessage : null,
    issues: Array.isArray(d.addressIssues) ? d.addressIssues as string[] : [] };
}

const toneClass: Record<Tone, string> = { green: "sw-tone-green", yellow: "sw-tone-yellow", red: "sw-tone-red" };
const toneIcon: Record<Tone, AppIconName> = { green: "check", yellow: "alert", red: "alert" };

export function ToneMessage({ tone, children, testId, live = true }: { tone: Tone; children: ReactNode; testId?: string; live?: boolean }) {
  return <p className={`sw-message ${toneClass[tone]}`} role={live ? (tone === "red" ? "alert" : "status") : undefined} data-testid={testId}><AppIcon name={toneIcon[tone]} size={16} /><span>{children}</span></p>;
}

export function ErrorMessage({ error, testId }: { error: unknown; testId?: string }) {
  const { reason, tone, message } = explain(error);
  return <ToneMessage tone={tone} testId={testId}>{reasonText(reason)}{message ? <> {t("sw.reason.fanSays", { message })}</> : null}</ToneMessage>;
}

export function StoreTopbar({ title, eyebrow, onBack, actions }: { title: string; eyebrow?: string; onBack?: () => void; actions?: ReactNode }) {
  const { t: tr } = useI18n();
  return <header className="sw-topbar">
    <div className="sw-topbar-title">
      {onBack && <button type="button" className="sw-icon-button" onClick={onBack} aria-label={tr("sw.back")}><AppIcon name="back" size={18} /></button>}
      <div>{eyebrow && <p className="sw-eyebrow">{eyebrow}</p>}<h1>{title}</h1></div>
    </div>
    {actions && <div className="sw-topbar-actions">{actions}</div>}
  </header>;
}

export function Counter({ label, value, detail, tone = "neutral", testId, onClick }: { label: string; value: number | null; detail?: string | null; tone?: "neutral" | "blue" | "green" | "yellow"; testId?: string; onClick?: () => void }) {
  const body = <><span className="sw-counter-value">{value === null ? "—" : value}</span><span className="sw-counter-label">{label}</span>{detail ? <span className="sw-counter-detail">{detail}</span> : null}</>;
  return onClick
    ? <button type="button" className={`sw-counter sw-counter-${tone}`} data-testid={testId} onClick={onClick}>{body}</button>
    : <div className={`sw-counter sw-counter-${tone}`} data-testid={testId}>{body}</div>;
}

export function Tabs<T extends string>({ tabs, value, onChange, label, testId }: { tabs: readonly { id: T; label: string; count?: number | null }[]; value: T; onChange: (id: T) => void; label: string; testId?: string }) {
  return <div className="sw-tabs" role="tablist" aria-label={label} data-testid={testId}>
    {tabs.map((tab) => <button key={tab.id} type="button" role="tab" aria-selected={tab.id === value} className={tab.id === value ? "is-active" : ""} onClick={() => onChange(tab.id)} data-testid={`${testId ?? "sw-tab"}-${tab.id}`}>
      {tab.label}{typeof tab.count === "number" ? <span className="sw-tab-count">{tab.count}</span> : null}
    </button>)}
  </div>;
}

export function Pager({ page, pageSize, total, onPage }: { page: number; pageSize: number; total: number; onPage: (page: number) => void }) {
  const { t: tr } = useI18n();
  const pages = Math.max(1, Math.ceil(total / Math.max(1, pageSize)));
  if (pages <= 1) return <p className="sw-pager-total">{tr("sw.total", { count: total })}</p>;
  return <nav className="sw-pager" aria-label={tr("sw.page", { page, pages })}>
    <span className="sw-pager-total">{tr("sw.total", { count: total })}</span>
    <button type="button" className="sw-icon-button" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label={tr("sw.prev")}><AppIcon name="back" size={16} /></button>
    <span>{tr("sw.page", { page, pages })}</span>
    <button type="button" className="sw-icon-button sw-flip" disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label={tr("sw.next")}><AppIcon name="back" size={16} /></button>
  </nav>;
}

/**
 * Loads one read and keeps the last good answer while a refresh runs (no blank screen, no lost scroll). `reload()` reads
 * again without clearing; a failed refresh keeps the data and shows the error beside it.
 */
export function useStoreRead<T>(load: (signal: AbortSignal) => Promise<T>, deps: readonly unknown[]) {
  const [state, setState] = useState<{ data: T | null; error: unknown; busy: boolean; at: string | null }>({ data: null, error: null, busy: true, at: null });
  const [tick, setTick] = useState(0);
  const loader = useRef(load);
  useEffect(() => { loader.current = load; });
  useEffect(() => {
    const controller = new AbortController();
    loader.current(controller.signal)
      .then((value) => { if (!controller.signal.aborted) setState({ data: value, error: null, busy: false, at: new Date().toISOString() }); })
      .catch((caught) => { if (!controller.signal.aborted) setState((current) => ({ ...current, error: caught, busy: false })); });
    return () => controller.abort();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  const reload = useCallback(() => { setState((current) => ({ ...current, busy: true })); setTick((value) => value + 1); }, []);
  return { ...state, reload };
}

/** Reads again every `ms` while the tab is visible (controlled polling; a hidden tab asks nothing). */
export function useVisiblePolling(callback: () => void, ms: number, enabled = true) {
  const saved = useRef(callback);
  useEffect(() => { saved.current = callback; });
  useEffect(() => {
    if (!enabled) return undefined;
    const timer = window.setInterval(() => { if (document.visibilityState === "visible") saved.current(); }, ms);
    const onVisible = () => { if (document.visibilityState === "visible") saved.current(); };
    document.addEventListener("visibilitychange", onVisible);
    return () => { window.clearInterval(timer); document.removeEventListener("visibilitychange", onVisible); };
  }, [ms, enabled]);
}

/** A query parameter kept in the address (tab, filter, range, page): a reload or "back" keeps the employee's view. */
export function useQueryState<T extends string>(name: string, allowed: readonly T[], fallback: T): [T, (value: T) => void] {
  const read = (): T => {
    const value = new URLSearchParams(window.location.search).get(name);
    return (allowed as readonly string[]).includes(value ?? "") ? value as T : fallback;
  };
  const [value, setValue] = useState<T>(read);
  const update = useCallback((next: T) => {
    setValue(next);
    const params = new URLSearchParams(window.location.search);
    if (next === fallback) params.delete(name); else params.set(name, next);
    const query = params.toString();
    window.history.replaceState(window.history.state, "", `${window.location.pathname}${query ? `?${query}` : ""}`);
  }, [name, fallback]);
  return [value, update];
}

/** Saves a PDF the server rendered (nothing is generated in the browser). */
export function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.rel = "noopener";
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/**
 * Opens the browser's print dialog for a PDF the server rendered (a hidden frame on the same page, so nothing navigates
 * away). Where the browser cannot print a PDF frame, the PDF is saved instead. The paper itself is never confirmed here.
 */
export function printPdf(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const frame = document.createElement("iframe");
  frame.className = "sw-print-frame";
  frame.setAttribute("aria-hidden", "true");
  frame.title = filename;
  frame.src = url;
  frame.onload = () => {
    try { frame.contentWindow?.focus(); frame.contentWindow?.print(); }
    catch { saveBlob(blob, filename); }
  };
  document.body.append(frame);
  setTimeout(() => { frame.remove(); URL.revokeObjectURL(url); }, 120_000);
}

export function SourceTag({ source }: { source: string }) {
  return <span className={`sw-source sw-source-${source}`}>{shopName(source)}</span>;
}
