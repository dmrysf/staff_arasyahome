import { useEffect, useMemo, useState } from "react";
import { formatDate, useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { DESK_VIEWS, canSuperviseConfirmare, confirmareSources, sourceLabel, type ConfirmareApi, type ConfirmareItem, type DeskPool, type DeskView } from "../../domain/confirmare";
import { callbackDue, waitingLabel, waitingMinutes } from "../../domain/decisionDesk";
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
 * The operator desk (Staff 2.57.0, Operation Simplification V1): four lists (new, waiting, confirmed, refused) with their
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

  useEffect(() => {
    const path = orderId ? confirmareOrderPath(orderId) : "/confirmare";
    window.history.replaceState(window.history.state, "", `${path}${deskQuery(filters)}`);
  }, [filters, orderId]);
  useEffect(() => {
    if (typed === filters.query) return;
    const timer = setTimeout(() => setFilters((current) => ({ ...current, query: typed })), 200);
    return () => clearTimeout(timer);
  }, [typed, filters.query]);

  if (orderId) {
    return <OrderWorkbench key={orderId} orderId={orderId} employee={employee} service={service} documents={documents} navigate={navigate}
      backPath={`/confirmare${deskQuery(filters)}`} listView={filters.view} listSource={filters.source} onChanged={pool.refresh} />;
  }

  const query = filters.query.trim().toLocaleLowerCase("ro");
  const rows = data ? data.items.filter((item) => query === "" || item.orderNumber.includes(query) || (item.customerName ?? "").toLocaleLowerCase("ro").includes(query)) : null;
  const failure = pool.error && (!data || pool.stopped) ? getErrorPresentation(pool.error).message : "";
  const open = (item: ConfirmareItem) => navigate(`${confirmareOrderPath(item.globalOrderId)}${deskQuery(filters)}`);

  return (
    <div className="dd-desk" data-testid="dd-desk">
      {deskWorkspace && onWorkspace && workspaces.length > 1 && <div className="cf-desk-switcher"><WorkspaceSwitcher workspaces={workspaces} current={deskWorkspace} onSelect={onWorkspace} /></div>}
      <header className="dd-desk-top">
        <div>
          <h1>{t("dd.title")}</h1>
          <p className="dd-desk-date">{formatDate(new Date().toISOString(), { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Bucharest" })}</p>
        </div>
        <strong className="dd-done" data-testid="dd-done-today">{t("dd.doneToday", { count: data?.counts.doneToday ?? 0 })}</strong>
        <DeskTools employee={employee} navigate={navigate} />
      </header>
      <nav className="dd-views" aria-label={t("cf.queue.tabs")}>
        {DESK_VIEWS.map((view) => (
          <button key={view} type="button" className={`dd-view dd-view-${view}`} aria-pressed={filters.view === view} onClick={() => setFilters((current) => ({ ...current, view }))} data-testid={`dd-view-${view}`}>
            <span>{t(`dd.view.${view}` as MessageKey)}</span>
            <strong>{data ? data.counts[view] : "—"}</strong>
          </button>
        ))}
      </nav>
      <div className="dd-filters">
        <div className="dd-shops" role="group" aria-label={t("dd.col.shop")}>
          <button type="button" className="dd-chip" aria-pressed={filters.source === null} onClick={() => setFilters((current) => ({ ...current, source: null }))} data-testid="dd-shop-all">{t("dd.shop.all")}</button>
          {shops.map((shop) => <button key={shop} type="button" className="dd-chip" aria-pressed={filters.source === shop} onClick={() => setFilters((current) => ({ ...current, source: shop }))} data-testid={`dd-shop-${shop}`}>{sourceLabel(shop)}</button>)}
        </div>
        <label className="dd-search"><AppIcon name="search" size={18} /><span className="visually-hidden">{t("dd.search")}</span>
          <input type="search" value={typed} maxLength={60} placeholder={t("dd.search")} onChange={(event) => setTyped(event.target.value)} data-testid="dd-search" />
        </label>
      </div>
      {failure && <p className="order-notice order-notice-muted" role="alert">{failure}</p>}
      <div className="dd-table-wrap" aria-busy={!rows && !failure}>
        {!rows && !failure && <QueueSkeleton rows={6} />}
        {rows && rows.length === 0 && <EmptyPanel icon="orders" title={t(`dd.empty.${filters.view}` as MessageKey)}>{""}</EmptyPanel>}
        {rows && rows.length > 0 && (
          <table className="dd-table" data-testid="dd-table">
            <thead><tr>
              <th scope="col">{t("dd.col.order")}</th><th scope="col">{t("dd.col.shop")}</th><th scope="col">{t("dd.col.customer")}</th>
              <th scope="col" className="num">{t("dd.col.total")}</th><th scope="col" className="num">{t("dd.col.products")}</th>
              <th scope="col">{t("dd.col.waiting")}</th><th scope="col">{t("dd.col.notes")}</th>
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

function DeskRow({ item, employee, onOpen }: { item: ConfirmareItem; employee: Employee; onOpen: () => void }) {
  const { t } = useI18n();
  const wait = waitingLabel(waitingMinutes(item));
  const due = callbackDue(item);
  const other = item.assignment && item.assignment.employeeId !== employee.employeeUuid ? item.assignment.displayName : null;
  const total = item.display?.total ? formatShopMoney(item.display.total, item.display.currency ?? "RON") : "—";
  return (
    <tr className={`dd-row${due ? " is-due" : ""}`} onClick={onOpen} data-testid={`dd-row-${item.orderNumber}`}>
      <td><button type="button" className="dd-row-open" onClick={(event) => { event.stopPropagation(); onOpen(); }}>#{item.orderNumber}</button>{other && <small className="dd-row-owner">{other}</small>}</td>
      <td><span className="cf-source" data-source={item.sourceKey}>{sourceLabel(item.sourceKey)}</span></td>
      <td className="dd-row-customer">{item.customerName ?? "—"}</td>
      <td className="num">{total}{item.display && !item.display.current && <small className="dd-row-stale" title={formatDate(item.display.readAt, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" })}> *</small>}</td>
      <td className="num">{item.lineCount ?? "—"}</td>
      <td>{t(`dd.wait.${wait.unit}` as MessageKey, { value: wait.value })}</td>
      <td className="dd-row-notes">
        {(item.display?.warnings ?? []).map((warning) => <span key={warning} className="dd-badge tone-warning">{t(`dd.warn.${warning}` as MessageKey)}</span>)}
        {item.waiting?.callbackAt && <span className={`dd-badge ${due ? "tone-action" : ""}`}>{due ? t("dd.callbackDue") : t("dd.callback", { date: formatDate(item.waiting.callbackAt, { hour: "2-digit", minute: "2-digit", day: "numeric", month: "short", timeZone: "Europe/Bucharest" }) })}</span>}
        {item.waiting?.note && <span className="dd-note">{item.waiting.note}</span>}
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
