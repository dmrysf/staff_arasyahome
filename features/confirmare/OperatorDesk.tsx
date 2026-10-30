import { useEffect, useMemo, useRef, useState } from "react";
import { formatDate, useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { QUEUE_TABS, canHandOff, canSuperviseConfirmare, canUseAdvanceQueue, canUseFinance, confirmareScopesOf, confirmareSources, inTab, itemStatus, itemStatusLabel, itemStatusTone, sourceLabel, type ConfirmareApi, type ConfirmareItem, type QueueTab } from "../../domain/confirmare";
import { DESK_METRICS, DESK_SORTS, deskCounts, searchQueue, sortQueue, type DeskMetric, type DeskSort } from "../../domain/operatorDesk";
import type { DocumentApi } from "../../domain/documents";
import type { Workspace } from "../../domain/workspaces";
import { canUseShopTasks, canWatchHealth } from "../../domain/confirmareOps";
import { canOverseeFinance, canUseFinanceInbox, oversightPath } from "../../domain/financeRequests";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice } from "../../components/ui/StatusPill";
import { EmptyPanel } from "../../components/ui/States";
import { AutoRefreshBar, QueueSkeleton, WorkspaceSwitcher } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { SourceFilter, confirmareOrderPath, confirmareQueueLoader, dueDay, when } from "./ConfirmareParts";
import { ConfirmareOrderScreen } from "./ConfirmareOrderScreen";
import { ShopTaskButtons } from "./ConfirmareWorkspace";
import { LegacyOrderLookup } from "./FinanceRequestScreens";

const SEARCH_DELAY_MS = 200;

type Filters = { tab: QueueTab; source: string | null; sort: DeskSort; query: string };

/** Filters live in the address (replaceState): a reload, back and forward return to the same list. */
function readFilters(employee: Employee): Filters {
  const params = new URLSearchParams(typeof window === "undefined" ? "" : window.location.search);
  const tab = params.get("tab");
  const source = params.get("source");
  const sort = params.get("sort");
  const sources: readonly string[] = confirmareSources(employee);
  return {
    tab: (QUEUE_TABS as readonly string[]).includes(tab ?? "") ? tab as QueueTab : "mine",
    source: source && sources.includes(source) ? source : null,
    sort: (DESK_SORTS as readonly string[]).includes(sort ?? "") ? sort as DeskSort : "priority",
    query: (params.get("q") ?? "").slice(0, 60),
  };
}

function filterQuery(filters: Filters): string {
  const params = new URLSearchParams();
  if (filters.tab !== "mine") params.set("tab", filters.tab);
  if (filters.source) params.set("source", filters.source);
  if (filters.sort !== "priority") params.set("sort", filters.sort);
  if (filters.query.trim()) params.set("q", filters.query.trim());
  const query = params.toString();
  return query ? `?${query}` : "";
}

/**
 * The Confirmare operator desk (Staff 2.42.0): one stable desktop workspace. The summary and the search on top, the
 * queue on the left, the selected order in the middle (its deep work opens in a drawer); on a narrow screen the queue
 * and the order take turns. The queue reads every minute while visible and again after every command in the order; it
 * keeps its filter, sort, search and scroll while orders are opened. Opening an order is a navigation
 * (`/confirmare/orders/{id}`), so the browser's back and forward work as everywhere else. Reads only: the queue never
 * claims, approves or changes a payment; "Preia următoarea comandă" asks the server, which picks the order.
 *
 * Staff 2.42.1: the desk is also the operator's home (`/`), so it carries what the old Confirmare home linked to (the
 * finance, hand-off, shop and health screens, the legacy order lookup) and, for an employee with several workspaces,
 * the workspace switcher.
 */
export function OperatorDesk({ employee, service, documents, orderId, navigate, workspaces = [], onWorkspace }: { employee: Employee; service: ConfirmareApi; documents?: DocumentApi; orderId: string | null; navigate: (path: string) => void; workspaces?: readonly Workspace[]; onWorkspace?: (workspace: Workspace) => void }) {
  const { t } = useI18n();
  const initial = useMemo(() => readFilters(employee), [employee]);
  const [filters, setFilters] = useState<Filters>(initial);
  const [typed, setTyped] = useState(initial.query);
  const load = useMemo(() => confirmareQueueLoader(service, filters.source), [service, filters.source]);
  // Every minute while visible, and again after every command in the order (a read only; it never claims or decides).
  const queue = useAutoRefresh(load, `confirmare-desk|${filters.source ?? "all"}`);
  const data = queue.data?.source === filters.source ? queue.data : null;
  const supervisor = canSuperviseConfirmare(employee);
  const operates = confirmareScopesOf(employee).operate.length > 0;
  const tabs = QUEUE_TABS.filter((id) => id !== "attention" || supervisor);
  const [taking, setTaking] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const takeKey = useRef<string | null>(null);
  const listRef = useRef<HTMLUListElement | null>(null);
  const deskWorkspace = workspaces.find((workspace) => workspace.kind === "confirmare");
  const sources = confirmareSources(employee);

  // The address keeps the filters; the order part of the path is the navigation's.
  useEffect(() => {
    const path = orderId ? confirmareOrderPath(orderId) : "/confirmare";
    window.history.replaceState(window.history.state, "", `${path}${filterQuery(filters)}`);
  }, [filters, orderId]);

  // Debounced search: typing never re-filters on every key.
  useEffect(() => {
    if (typed === filters.query) return;
    const timer = setTimeout(() => setFilters((current) => ({ ...current, query: typed })), SEARCH_DELAY_MS);
    return () => clearTimeout(timer);
  }, [typed, filters.query]);

  const searching = filters.query.trim() !== "";
  const counts = data ? deskCounts(data.items, employee.employeeUuid) : null;
  // A search looks through the whole held queue (every tab); otherwise the chosen tab.
  const rows = data ? sortQueue(searching ? searchQueue(data.items, filters.query) : data.items.filter((item) => inTab(item, filters.tab, employee.employeeUuid)), filters.sort) : null;
  const tabCount = (id: QueueTab): number | undefined => data?.items.filter((item) => inTab(item, id, employee.employeeUuid)).length;
  const failure = queue.error && (!data || queue.stopped) ? getErrorPresentation(queue.error).message : "";

  const open = (item: ConfirmareItem) => navigate(`${confirmareOrderPath(item.globalOrderId)}${filterQuery(filters)}`);
  const chooseMetric = (metric: DeskMetric) => { setTyped(""); setFilters((current) => ({ ...current, tab: metric, query: "" })); };

  async function takeNext() {
    setTaking(true); setMessage(null);
    takeKey.current ??= createIdempotencyKey();
    try {
      const assigned = await service.next(filters.source, takeKey.current);
      takeKey.current = null;
      if (assigned) navigate(`${confirmareOrderPath(assigned.globalOrderId)}${filterQuery(filters)}`);
      else setMessage(t("cf.home.nothingWaiting"));
      queue.refresh();
    } catch (caught) {
      setMessage(getErrorPresentation(toServiceError(caught)).message);
    } finally { setTaking(false); }
  }

  // Arrow keys move between rows (focus only: opening an order reads it, so it stays an explicit Enter or click).
  const hasRows = Boolean(rows && rows.length > 0);
  useEffect(() => {
    const list = listRef.current;
    if (!list) return;
    const onKey = (event: KeyboardEvent) => {
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
      const buttons = [...list.querySelectorAll<HTMLButtonElement>("button.cf-desk-row")];
      if (buttons.length === 0) return;
      event.preventDefault();
      const at = buttons.indexOf(document.activeElement as HTMLButtonElement);
      const next = event.key === "Home" ? 0 : event.key === "End" ? buttons.length - 1 : event.key === "ArrowDown" ? Math.min(at + 1, buttons.length - 1) : Math.max(at - 1, 0);
      buttons[next]?.focus();
    };
    list.addEventListener("keydown", onKey);
    return () => list.removeEventListener("keydown", onKey);
  }, [hasRows]);

  return (
    <div className={`cf-desk${orderId ? " has-order" : ""}`} data-testid="cf-queue">
      {deskWorkspace && onWorkspace && workspaces.length > 1 && <div className="cf-desk-switcher"><WorkspaceSwitcher workspaces={workspaces} current={deskWorkspace} onSelect={onWorkspace} /></div>}
      <header className="cf-desk-top">
        <div className="cf-desk-title">
          <p className="eyebrow">{t("desk.eyebrow")}</p>
          <h1>{t("desk.title")}</h1>
          <p className="cf-desk-date">{formatDate(new Date().toISOString(), { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Bucharest" })}</p>
        </div>
        <div className="cf-desk-top-actions">
          <DeskTools employee={employee} navigate={navigate} />
          {operates && <button type="button" className="button button-primary" disabled={taking} onClick={() => { void takeNext(); }} data-testid="cf-take-next">{taking ? t("common.processing") : t("cf.next.take")}<AppIcon name="arrow" size={18} /></button>}
          {supervisor && <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/team")} data-testid="cf-open-team"><AppIcon name="team" size={18} />{t("cf.home.team")}</button>}
        </div>
        <div className="cf-desk-kpis" role="group" aria-label={t("cf.home.metrics")}>
          {DESK_METRICS.map((metric) => (
            <button key={metric} type="button" className={`cf-kpi tone-${metric === "mine" ? "action" : "warning"}`} aria-pressed={!searching && filters.tab === metric} onClick={() => chooseMetric(metric)} data-testid={`cf-metric-${metric}`}>
              <span className="cf-kpi-label">{t(`desk.kpi.${metric}` as MessageKey)}</span>
              <strong className="cf-kpi-value">{counts ? counts[metric] : queue.error ? "—" : <span className="skeleton skeleton-line" aria-hidden="true" />}</strong>
              <small className="cf-kpi-hint">{t(`desk.kpiHint.${metric}` as MessageKey)}</small>
            </button>
          ))}
        </div>
      </header>

      {message && <Notice tone="info" role="status">{message}</Notice>}

      <div className="cf-desk-body">
        <section className="cf-desk-queue" aria-labelledby="cf-desk-queue-title">
          <h2 id="cf-desk-queue-title" className="visually-hidden">{t("cf.queue.title")}</h2>
          <div className="cf-desk-search">
            <AppIcon name="search" size={18} />
            <label htmlFor="cf-desk-search" className="visually-hidden">{t("desk.search.label")}</label>
            <input id="cf-desk-search" type="search" value={typed} maxLength={60} autoComplete="off" placeholder={t("desk.search.placeholder")} onChange={(event) => setTyped(event.target.value)} onKeyDown={(event) => { if (event.key === "Escape") { setTyped(""); setFilters((current) => ({ ...current, query: "" })); } }} data-testid="cf-desk-search" />
          </div>
          <SourceFilter sources={sources} value={filters.source} onChange={(source) => setFilters((current) => ({ ...current, source }))} />
          <div className="cf-desk-filters">
            <label className="visually-hidden" htmlFor="cf-desk-tab">{t("cf.queue.tabs")}</label>
            <select id="cf-desk-tab" value={filters.tab} disabled={searching} onChange={(event) => setFilters((current) => ({ ...current, tab: event.target.value as QueueTab }))} data-testid="cf-desk-tab">
              {tabs.map((id) => <option key={id} value={id}>{t(`cf.tab.${id}` as MessageKey)}{tabCount(id) !== undefined ? ` (${tabCount(id)})` : ""}</option>)}
            </select>
            <label className="visually-hidden" htmlFor="cf-desk-sort">{t("desk.sort.label")}</label>
            <select id="cf-desk-sort" value={filters.sort} onChange={(event) => setFilters((current) => ({ ...current, sort: event.target.value as DeskSort }))} data-testid="cf-desk-sort">
              {DESK_SORTS.map((sort) => <option key={sort} value={sort}>{t(`desk.sort.${sort}` as MessageKey)}</option>)}
            </select>
          </div>
          <p className="cf-tab-hint">{searching ? t("desk.search.scope") : t(`cf.tabHint.${filters.tab}` as MessageKey)}</p>
          {failure && <p className="order-notice order-notice-muted" role="alert">{failure}</p>}
          <div id="cf-tab-panel" className="cf-desk-list" aria-busy={!rows && !failure}>
            {!rows && !failure && <QueueSkeleton rows={5} />}
            {rows && (rows.length === 0
              ? <EmptyPanel icon={searching ? "search" : "orders"} title={searching ? t("desk.search.none") : t("cf.queue.empty")}>{searching ? t("desk.search.noneHint") : t(`cf.tabHint.${filters.tab}` as MessageKey)}</EmptyPanel>
              : <ul ref={listRef} className="cf-desk-rows" aria-label={searching ? t("desk.search.results", { count: rows.length }) : t(`cf.tab.${filters.tab}` as MessageKey)}>
                {rows.map((item) => <DeskRow key={item.globalOrderId} item={item} employee={employee} selected={item.globalOrderId === orderId} onOpen={() => open(item)} />)}
              </ul>)}
          </div>
          {data && data.items.length >= 200 && <p className="dashboard-footnote">{t("cf.queue.limit")}</p>}
          <AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={data !== null && queue.error !== null} stopped={queue.stopped} testId="cf-queue-refresh" />
          <details className="cf-desk-legacy" data-testid="cf-desk-legacy">
            <summary>{t("fr.legacyOrder.lookupTitle")}</summary>
            <LegacyOrderLookup sources={sources} navigate={navigate} />
          </details>
          <p className="cf-desk-note" data-testid="cf-contact-dependency">{t("cf.home.contactDependency")}</p>
        </section>

        <section className="cf-desk-order" aria-label={t("desk.order.region")}>
          {orderId
            ? <ConfirmareOrderScreen key={orderId} orderId={orderId} employee={employee} service={service} documents={documents} navigate={navigate} onChanged={queue.refresh} backPath={`/confirmare${filterQuery(filters)}`} />
            : <div className="cf-desk-placeholder" data-testid="cf-desk-placeholder"><EmptyPanel icon="orders" title={t("desk.order.noneTitle")}>{t("desk.order.noneHint")}</EmptyPanel></div>}
        </section>
      </div>
    </div>
  );
}

/** The Confirmare screens the old operator home linked to, each shown only to the employee it is for. */
function DeskTools({ employee, navigate }: { employee: Employee; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const tools = [
    canUseFinanceInbox(employee) && <button key="inbox" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/finance/requests")} data-testid="fr-open-inbox"><AppIcon name="check" size={18} />{t("fr.home.open")}</button>,
    canOverseeFinance(employee) && <button key="oversight" type="button" className="button button-secondary" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight"><AppIcon name="team" size={18} />{t("fr.oversight.open")}</button>,
    canUseAdvanceQueue(employee) && <button key="finance" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/finance")} data-testid="cf-open-finance"><AppIcon name="orders" size={18} />{t(canUseFinance(employee) ? "cf.fin.open" : "cf.fin.openAdvances")}</button>,
    canHandOff(employee) && <button key="handoff" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/handoff")} data-testid="cf-open-handoff"><AppIcon name="document" size={18} />{t("cf.handoff.list.open")}</button>,
    canUseShopTasks(employee) && <ShopTaskButtons key="shop" navigate={navigate} />,
    canWatchHealth(employee) && <button key="health" type="button" className="button button-secondary" onClick={() => navigate("/confirmare/health")} data-testid="cf-open-health"><AppIcon name="orders" size={18} />{t("cf.health.open")}</button>,
  ].filter(Boolean);
  if (tools.length === 0) return null;
  return <nav className="cf-desk-tools" aria-label={t("desk.tools.label")} data-testid="cf-desk-tools">{tools}</nav>;
}

/** One queue row: identity and state only (the queue never carries customer data). Colour always comes with words. */
function DeskRow({ item, employee, selected, onOpen }: { item: ConfirmareItem; employee: Employee; selected: boolean; onOpen: () => void }) {
  const { t } = useI18n();
  const status = itemStatus(item);
  const mine = item.assignment?.employeeId === employee.employeeUuid;
  const blocked = item.financial.attention !== null || (!item.financial.open && item.status !== "rejected" && item.status !== "cancelled");
  return (
    <li>
      <button type="button" className={`cf-desk-row tone-${itemStatusTone[status]}${selected ? " is-selected" : ""}`} aria-current={selected ? "true" : undefined} onClick={onOpen} data-testid={`cf-row-${item.orderNumber}`}>
        <span className="cf-desk-row-top">
          <strong className="order-id">#{item.orderNumber}</strong>
          <span className="cf-source" data-source={item.sourceKey}>{sourceLabel(item.sourceKey)}</span>
        </span>
        <span className="cf-desk-row-status"><span className="cf-dot" aria-hidden="true" />{itemStatusLabel(status)}</span>
        <small>{t("cf.row.received", { date: when(item.submittedAt ?? item.openedAt) })}{item.dueDate ? ` · ${t("cf.row.due", { date: dueDay(item.dueDate) })}` : ""}</small>
        {item.contact && <small>{t("cf.row.lastCall", { outcome: t(`cf.outcome.${item.contact.outcome}` as MessageKey), date: when(item.contact.at) })}</small>}
        <small className="cf-desk-row-owner">{item.assignment ? (mine ? t("desk.row.mine") : t("cf.row.assignedTo", { name: item.assignment.displayName })) : t("cf.order.unassigned")}</small>
        {blocked && <small className="queue-row-reason"><AppIcon name="lock" size={14} /> {item.financial.attention ? t(`cf.financial.attention.${item.financial.attention}` as MessageKey) : t("cf.row.paymentMissing")}</small>}
      </button>
    </li>
  );
}
