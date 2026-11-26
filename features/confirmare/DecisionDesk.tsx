import { useEffect, useMemo, useRef, useState } from "react";
import { formatDate, useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { DESK_VIEWS, canSuperviseConfirmare, confirmareSources, sourceLabel, type ConfirmareApi, type ConfirmareItem, type DeskPool, type DeskView } from "../../domain/confirmare";
import { callbackDue, waitingLabel, waitingMinutes } from "../../domain/decisionDesk";
import { DESK_DECIDED_DAYS, deskMatches, deskWarningKey, waitTone } from "../../domain/operatorWorkspace";
import { formatShopMoney } from "../../domain/confirmareShop";
import type { DocumentApi } from "../../domain/documents";
import type { Workspace } from "../../domain/workspaces";
import { canUseShopTasks, canWatchHealth } from "../../domain/confirmareOps";
import { canOverseeFinance, canUseFinanceInbox, oversightPath } from "../../domain/financeRequests";
import { getErrorPresentation } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton, WorkspaceSwitcher } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { confirmareOrderPath } from "./ConfirmareParts";
import { OrderWorkbench } from "./OrderWorkbench";
import { OperatorDesk } from "./OperatorDesk";
import { ShopTaskButtons } from "./ConfirmareWorkspace";

export const DESK_REFRESH_MS = 15_000;
type Filters = { view: DeskView; source: string | null; query: string };

function readFilters(): Filters {
  const params = new URLSearchParams(typeof window === "undefined" ? "" : window.location.search);
  const view = params.get("view");
  return {
    view: (DESK_VIEWS as readonly string[]).includes(view ?? "") ? view as DeskView : "new",
    source: params.get("source") || null,
    query: (params.get("q") ?? "").slice(0, 60),
  };
}

export function deskQuery(filters: Filters): string {
  const params = new URLSearchParams();
  if (filters.view !== "new") params.set("view", filters.view);
  if (filters.source) params.set("source", filters.source);
  if (filters.query.trim()) params.set("q", filters.query.trim());
  const query = params.toString();
  return query ? `?${query}` : "";
}

/**
 * The operator desk (Staff 2.57.0, Operation Simplification V1; Staff 2.58.0 Operator Workspace V2 layout): four lists (new, waiting, confirmed, refused) with their
 * counts and today's completed decisions, a shop filter built from the shops the server returns (no shop name is written
 * into the screen), and one row per order with what the operator needs before calling: number, shop, customer, total,
 * products, how long it waits and a warning when the shop reported one. The list comes from Arasya's own data and the
 * display copy (never a shop call per row). Opening an order shows it full width; the server's priority order is kept,
 * but the operator opens whichever order she wants (no strict FIFO).
 */
export function DecisionDesk({ employee, service, documents, orderId, navigate, workspaces = [], onWorkspace }: {
  employee: Employee; service: ConfirmareApi; documents?: DocumentApi; orderId: string | null; navigate: (path: string) => void;
  workspaces?: readonly Workspace[]; onWorkspace?: (workspace: Workspace) => void;
}) {
  const { t } = useI18n();
  const [filters, setFilters] = useState<Filters>(() => readFilters());
  const [typed, setTyped] = useState(filters.query);
  const load = useMemo(() => (signal: AbortSignal) => service.desk(filters.view, filters.source, signal), [service, filters.view, filters.source]);
  const pool = useAutoRefresh<DeskPool>(load, `decision-desk|${filters.view}|${filters.source ?? "all"}`, DESK_REFRESH_MS);
  const data = pool.data?.view === filters.view ? pool.data : null;
  const deskWorkspace = workspaces.find((workspace) => workspace.kind === "confirmare");
  const shops = useMemo(() => [...new Set([...(data?.sources ?? []), ...confirmareSources(employee)])].sort(), [data?.sources, employee]);
  const deskRef = useRef<HTMLDivElement>(null);
  const stickyRef = useRef<HTMLDivElement>(null);
  // The table header sticks right under the sticky lists and filters, whatever height they wrap to (no overlap).
  useEffect(() => {
    const sticky = stickyRef.current;
    const desk = deskRef.current;
    if (!sticky || !desk || typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(() => desk.style.setProperty("--ow-sticky-h", `${Math.ceil(sticky.getBoundingClientRect().height)}px`));
    observer.observe(sticky);
    return () => observer.disconnect();
  }, [orderId, pool.data?.decisions]);

  useEffect(() => {
    const path = orderId ? confirmareOrderPath(orderId) : "/confirmare";
    window.history.replaceState(window.history.state, "", `${path}${deskQuery(filters)}`);
  }, [filters, orderId]);
  useEffect(() => {
    if (typed === filters.query) return;
    // The list is already on screen: searching filters it locally, without any request.
    const timer = setTimeout(() => setFilters((current) => ({ ...current, query: typed })), 150);
    return () => clearTimeout(timer);
  }, [typed, filters.query]);

  // API 2.82.0: until the operator decisions are switched on (ARASYA_CONFIRMARE_DECISIONS), the earlier detailed desk works.
  if (pool.data?.decisions === false) {
    return <OperatorDesk employee={employee} service={service} documents={documents} orderId={orderId} navigate={navigate} workspaces={workspaces} onWorkspace={onWorkspace} />;
  }
  if (orderId) {
    return <OrderWorkbench key={orderId} orderId={orderId} employee={employee} service={service} documents={documents} navigate={navigate}
      backPath={`/confirmare${deskQuery(filters)}`} listView={filters.view} listSource={filters.source} onChanged={pool.refresh} />;
  }

  const query = filters.query.trim();
  const rows = data ? data.items.filter((item) => deskMatches(item, query)) : null;
  const failure = pool.error && (!data || pool.stopped) ? getErrorPresentation(pool.error).message : "";
  const open = (item: ConfirmareItem) => navigate(`${confirmareOrderPath(item.globalOrderId)}${deskQuery(filters)}`);

  return (
    <div className="ow-desk dd-desk" data-testid="dd-desk" ref={deskRef}>
      {deskWorkspace && onWorkspace && workspaces.length > 1 && <div className="cf-desk-switcher"><WorkspaceSwitcher workspaces={workspaces} current={deskWorkspace} onSelect={onWorkspace} /></div>}
      <header className="ow-desk-head">
        <div className="ow-desk-title">
          <h1>{t("dd.title")}</h1>
          <p className="ow-desk-date">{formatDate(new Date().toISOString(), { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Bucharest" })}</p>
        </div>
        <div className="ow-done" data-testid="dd-done-today" aria-label={t("dd.doneToday", { count: data?.counts.doneToday ?? 0 })}>
          <strong>{data ? data.counts.doneToday : "—"}</strong>
          <span>{t("dd.doneTodayLabel")}</span>
        </div>
        <DeskTools employee={employee} navigate={navigate} />
      </header>
      <div className="ow-desk-sticky" ref={stickyRef}>
        <nav className="ow-views" aria-label={t("cf.queue.tabs")}>
          {DESK_VIEWS.map((view) => (
            <button key={view} type="button" className={`ow-view ow-view-${view}`} aria-pressed={filters.view === view} onClick={() => setFilters((current) => ({ ...current, view }))} data-testid={`dd-view-${view}`}>
              <span className="ow-view-label">{t(`dd.view.${view}` as MessageKey)}</span>
              <strong className="ow-view-count">{data ? data.counts[view] : "—"}</strong>
              <small className="ow-view-period">{view === "confirmed" || view === "refused" ? t("dd.view.period", { days: DESK_DECIDED_DAYS }) : t("dd.view.now")}</small>
            </button>
          ))}
        </nav>
        <div className="ow-filters">
          <div className="ow-shops" role="group" aria-label={t("dd.col.shop")}>
            <button type="button" className="ow-chip" aria-pressed={filters.source === null} onClick={() => setFilters((current) => ({ ...current, source: null }))} data-testid="dd-shop-all">{t("dd.shop.all")}</button>
            {shops.map((shop) => <button key={shop} type="button" className="ow-chip" aria-pressed={filters.source === shop} onClick={() => setFilters((current) => ({ ...current, source: shop }))} data-testid={`dd-shop-${shop}`}>{sourceLabel(shop)}</button>)}
          </div>
          <div className="ow-search" role="search">
            <AppIcon name="search" size={20} />
            <label className="visually-hidden" htmlFor="ow-desk-search">{t("dd.search")}</label>
            <input id="ow-desk-search" type="search" value={typed} maxLength={60} placeholder={t("dd.search")} autoComplete="off" spellCheck={false}
              onChange={(event) => setTyped(event.target.value)} onKeyDown={(event) => { if (event.key === "Escape") setTyped(""); }} data-testid="dd-search" />
            {typed !== "" && <button type="button" className="ow-search-clear" onClick={() => setTyped("")} aria-label={t("dd.searchClear")} title={t("dd.searchClear")}><AppIcon name="close" size={16} /></button>}
          </div>
        </div>
        {query !== "" && rows && <p className="ow-search-result" role="status" data-testid="dd-search-result">{rows.length > 0 ? t("dd.searchResults", { count: rows.length, query }) : t("dd.searchNone", { query })}</p>}
      </div>
      {failure && <p className="order-notice order-notice-muted" role="alert">{failure}</p>}
      <div className="ow-table-wrap" aria-busy={!rows && !failure}>
        {!rows && !failure && <QueueSkeleton rows={6} />}
        {rows && rows.length === 0 && query === "" && <EmptyPanel icon="orders" title={t(`dd.empty.${filters.view}` as MessageKey)}>{""}</EmptyPanel>}
        {rows && rows.length > 0 && (
          <table className="ow-table" data-testid="dd-table">
            <thead><tr>
              <th scope="col" className="c-order">{t("dd.col.order")}</th><th scope="col" className="c-shop">{t("dd.col.shop")}</th><th scope="col" className="c-customer">{t("dd.col.customer")}</th>
              <th scope="col" className="c-total num">{t("dd.col.total")}</th><th scope="col" className="c-lines num">{t("dd.col.products")}</th>
              <th scope="col" className="c-wait">{t("dd.col.waiting")}</th><th scope="col" className="c-notes">{t("dd.col.notes")}</th>
            </tr></thead>
            <tbody>{rows.map((item) => <DeskRow key={item.globalOrderId} item={item} employee={employee} onOpen={() => open(item)} />)}</tbody>
          </table>
        )}
      </div>
      {data && data.total > data.items.length && <p className="dashboard-footnote">{t("cf.queue.limit", { count: data.total - data.items.length })}</p>}
      <AutoRefreshBar onRefresh={pool.refresh} busy={pool.busy} refreshing={pool.refreshing} updatedAt={pool.updatedAt} stale={data !== null && pool.error !== null} stopped={pool.stopped} testId="dd-refresh" every="refresh.every15s" />
    </div>
  );
}

/**
 * One order of the list: the whole row opens it (the number is its keyboard target), the total right-aligned in tabular
 * figures (a total not read yet says so, never 0), the waiting time weighted by how long it has waited (display only)
 * and the shop's warnings in words (an unknown code reads as "check this order", never a raw key).
 */
function DeskRow({ item, employee, onOpen }: { item: ConfirmareItem; employee: Employee; onOpen: () => void }) {
  const { t } = useI18n();
  const minutes = waitingMinutes(item);
  const wait = waitingLabel(minutes);
  const tone = waitTone(minutes);
  const due = callbackDue(item);
  const other = item.assignment && item.assignment.employeeId !== employee.employeeUuid ? item.assignment.displayName : null;
  const readAt = item.display?.readAt ? formatDate(item.display.readAt, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" }) : "";
  return (
    <tr className={`ow-row${due ? " is-due" : ""} wait-${tone}`} onClick={onOpen} data-testid={`dd-row-${item.orderNumber}`}>
      <td className="c-order">
        <button type="button" className="ow-row-open" onClick={(event) => { event.stopPropagation(); onOpen(); }} aria-label={t("dd.openOrder", { number: item.orderNumber })}>#{item.orderNumber}</button>
        <small className="ow-row-shop" aria-hidden="true">{sourceLabel(item.sourceKey)}</small>
        {other && <small className="ow-row-owner">{other}</small>}
      </td>
      <td className="c-shop"><span className="ow-shop-name" data-source={item.sourceKey}>{sourceLabel(item.sourceKey)}</span></td>
      <td className="c-customer">{item.customerName ?? "—"}</td>
      <td className="c-total num">
        {item.display?.total
          ? <>{formatShopMoney(item.display.total, item.display.currency ?? "RON")}{!item.display.current && <span className="ow-stale" title={t("dd.listTotalStale", { date: readAt })}><AppIcon name="clock" size={13} /><span className="visually-hidden">{t("dd.listTotalStale", { date: readAt })}</span></span>}</>
          : <span className="ow-muted ow-pending">{t("dd.listTotalPending")}</span>}
      </td>
      <td className="c-lines num">{item.lineCount ?? "—"}</td>
      <td className="c-wait">
        <span className={`ow-wait is-${tone}`}>
          {tone !== "normal" && <AppIcon name={tone === "critical" ? "alert" : "clock"} size={14} />}
          {t(`dd.wait.${wait.unit}` as MessageKey, { value: wait.value })}
          {tone !== "normal" && <span className="visually-hidden"> · {t(`dd.wait.tone.${tone}` as MessageKey)}</span>}
        </span>
      </td>
      <td className="c-notes">
        {(item.display?.warnings ?? []).map((warning) => <span key={warning} className={`ow-badge ${warning === "out_of_stock" ? "tone-danger" : "tone-warning"}`}><AppIcon name="alert" size={13} />{t(deskWarningKey(warning))}</span>)}
        {item.waiting?.callbackAt && <span className={`ow-badge ${due ? "tone-action" : ""}`}><AppIcon name="clock" size={13} />{due ? t("dd.callbackDue") : t("dd.callback", { date: formatDate(item.waiting.callbackAt, { hour: "2-digit", minute: "2-digit", day: "numeric", month: "short", timeZone: "Europe/Bucharest" }) })}</span>}
        {item.waiting?.note && <span className="ow-note">{item.waiting.note}</span>}
      </td>
    </tr>
  );
}

/** The other Confirmare screens, each shown only to the employee it is for. */
function DeskTools({ employee, navigate }: { employee: Employee; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const tools = [
    canSuperviseConfirmare(employee) && <button key="team" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/team")} data-testid="cf-open-team"><AppIcon name="team" size={18} />{t("cf.home.team")}</button>,
    canUseFinanceInbox(employee) && <button key="inbox" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/finance/requests")} data-testid="fr-open-inbox"><AppIcon name="check" size={18} />{t("fr.home.open")}</button>,
    canOverseeFinance(employee) && <button key="oversight" type="button" className="button button-secondary" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight"><AppIcon name="team" size={18} />{t("fr.oversight.open")}</button>,
    canUseShopTasks(employee) && <ShopTaskButtons key="shop" navigate={navigate} />,
    canWatchHealth(employee) && <button key="health" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/health")} data-testid="cf-open-health"><AppIcon name="orders" size={18} />{t("cf.health.open")}</button>,
  ].filter(Boolean);
  if (tools.length === 0) return null;
  return <nav className="dd-tools" aria-label={t("desk.tools.label")}>{tools}</nav>;
}
