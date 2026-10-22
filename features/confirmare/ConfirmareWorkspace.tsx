import { useMemo, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { canSuperviseConfirmare, canUseConfirmare, canUseFinance, confirmareScopesOf, inTab, nextAction, type ConfirmareApi, type QueueTab } from "../../domain/confirmare";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice } from "../../components/ui/StatusPill";
import { ErrorState } from "../../components/ErrorState";
import { AutoRefreshBar, DashboardSection, EmptyState, Metric, MetricGrid, NextActionSkeleton, QueueSkeleton, type MetricValue } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ConfirmareRow, confirmareOrderPath, confirmareQueueLoader } from "./ConfirmareParts";

const LIST_LIMIT = 6;

/**
 * The operator's Confirmare home: the one next step, the counts of the presentation groups and the own queue. It
 * reads the server-ordered queue every minute while visible (never claims or decides on refresh). "Take the next
 * order" asks the server, which picks the order (due date first, then FIFO); the browser never chooses one.
 */
export function ConfirmareWorkspace({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  // API 2.41.0: a finance employee without a Confirmare scope works from the finance tasks only.
  if (!canUseConfirmare(employee)) return <FinanceOnlyWorkspace navigate={navigate} />;
  return <OperatorWorkspace employee={employee} service={service} navigate={navigate} />;
}

function FinanceOnlyWorkspace({ navigate }: { navigate: (path: string) => void }) {
  const { t } = useI18n();
  return (
    <div className="workspace-body confirmare-workspace" data-testid="workspace-confirmare-finance">
      <section className="next-action tone-attention" aria-labelledby="cf-finance-next">
        <div className="next-action-copy">
          <p className="eyebrow">{t("cf.fin.homeEyebrow")}</p>
          <h2 id="cf-finance-next">{t("cf.fin.homeTitle")}</h2>
          <p className="next-action-detail">{t("cf.fin.homeDetail")}</p>
        </div>
        <div className="next-action-actions">
          <button type="button" className="button button-primary next-action-cta" onClick={() => navigate("/confirmare/finance")} data-testid="cf-open-finance">{t("cf.fin.open")}<AppIcon name="arrow" size={20} /></button>
        </div>
      </section>
    </div>
  );
}

function OperatorWorkspace({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => confirmareQueueLoader(service, null), [service]);
  const queue = useAutoRefresh(load, "confirmare-home");
  const items = queue.data?.items ?? null;
  const operates = confirmareScopesOf(employee).operate.length > 0;
  const supervisor = canSuperviseConfirmare(employee);
  const [taking, setTaking] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const takeKey = useRef<string | null>(null);

  const count = (tab: QueueTab): MetricValue => items ? items.filter((item) => inTab(item, tab, employee.employeeUuid)).length : queue.error ? "unavailable" : "loading";
  const next = items ? nextAction(items, employee.employeeUuid, operates) : null;
  const mine = items ? items.filter((item) => inTab(item, "mine", employee.employeeUuid)) : [];

  async function takeNext() {
    setTaking(true); setMessage(null);
    takeKey.current ??= createIdempotencyKey();
    try {
      const assigned = await service.next(null, takeKey.current);
      takeKey.current = null;
      if (assigned) navigate(confirmareOrderPath(assigned.globalOrderId));
      else { setMessage(t("cf.home.nothingWaiting")); queue.refresh(); }
    } catch (caught) {
      setMessage(getErrorPresentation(toServiceError(caught)).message);
    } finally { setTaking(false); }
  }

  const nextCopy = next && next.kind !== "takeNext" && next.kind !== "idle" ? {
    finish: { eyebrow: t("cf.next.finishEyebrow"), title: t("cf.next.finishTitle"), detail: t("cf.next.finishDetail") },
    call: { eyebrow: t("cf.next.callEyebrow"), title: t("cf.next.callTitle"), detail: t("cf.next.callDetail") },
    callback: { eyebrow: t("cf.next.callbackEyebrow"), title: t("cf.next.callbackTitle"), detail: t("cf.next.callbackDetail") },
    correction: { eyebrow: t("cf.next.correctionEyebrow"), title: t("cf.next.correctionTitle"), detail: t("cf.next.correctionDetail") },
  }[next.kind] : null;

  return (
    <div className="workspace-body split-workspace confirmare-workspace" data-testid="workspace-confirmare">
      <div className="workspace-main">
        {!items && !queue.error && <NextActionSkeleton />}
        {next && nextCopy && next.kind !== "takeNext" && next.kind !== "idle" && (
          <section className={`next-action ${next.kind === "finish" ? "tone-ready" : "tone-attention"}`} aria-labelledby="cf-next" data-testid="cf-next-action">
            <div className="next-action-copy">
              <p className="eyebrow">{nextCopy.eyebrow}</p>
              <h2 id="cf-next">{nextCopy.title}</h2>
              <p className="next-action-order order-id">#{next.item.orderNumber}</p>
              <p className="next-action-detail">{nextCopy.detail}</p>
            </div>
            <div className="next-action-actions">
              <button type="button" className="button button-primary next-action-cta" onClick={() => navigate(confirmareOrderPath(next.item.globalOrderId))}>{t("cf.next.open")}<AppIcon name="arrow" size={20} /></button>
            </div>
          </section>
        )}
        {next?.kind === "takeNext" && (
          <section className="next-action tone-attention" aria-labelledby="cf-next" data-testid="cf-next-action">
            <div className="next-action-copy">
              <p className="eyebrow">{t("cf.next.takeEyebrow")}</p>
              <h2 id="cf-next">{t("cf.next.takeTitle")}</h2>
              <p className="next-action-detail">{t("cf.next.takeDetail")}</p>
            </div>
            <div className="next-action-actions">
              <button type="button" className="button button-primary next-action-cta" disabled={taking} onClick={() => { void takeNext(); }} data-testid="cf-take-next">{taking ? t("common.processing") : t("cf.next.take")}<AppIcon name="arrow" size={20} /></button>
            </div>
          </section>
        )}
        {next?.kind === "idle" && (
          <section className="next-action tone-calm" aria-labelledby="cf-next" data-testid="cf-next-action">
            <div className="next-action-copy">
              <p className="eyebrow">{t("cf.next.idleEyebrow")}</p>
              <h2 id="cf-next">{t("cf.next.idleTitle")}</h2>
              <p className="next-action-detail">{t("cf.next.idleDetail")}</p>
            </div>
          </section>
        )}
        {message && <Notice tone="info" role="status">{message}</Notice>}

        <DashboardSection labelledBy="cf-mine" eyebrow={t("cf.home.mineEyebrow")} title={t("cf.home.mineTitle")}
          action={mine.length > LIST_LIMIT ? <button type="button" className="button button-link" onClick={() => navigate("/confirmare?tab=mine")}>{t("cf.home.viewAll")}</button> : undefined}>
          {!items && !queue.error && <QueueSkeleton />}
          {items && (mine.length === 0
            ? <EmptyState title={t("cf.home.noMine")}>{operates ? t("cf.home.noMineHint") : t("cf.home.supervisorOnly")}</EmptyState>
            : <ul className="queue-list">{mine.slice(0, LIST_LIMIT).map((item) => <ConfirmareRow key={item.globalOrderId} item={item} employee={employee} navigate={navigate} />)}</ul>)}
        </DashboardSection>
      </div>

      <div className="workspace-side">
        <DashboardSection labelledBy="cf-today" eyebrow={t("cf.home.statusEyebrow")} title={t("cf.home.statusTitle")}
          action={<AutoRefreshBar onRefresh={queue.refresh} busy={queue.busy} refreshing={queue.refreshing} updatedAt={queue.updatedAt} stale={items !== null && queue.error !== null} stopped={queue.stopped} testId="cf-refresh" />}>
          <MetricGrid label={t("cf.home.metrics")}>
            <Metric label={t("cf.tab.mine")} value={count("mine")} tone="action" testId="cf-metric-mine" />
            <Metric label={t("cf.tab.callback")} value={count("callback")} tone="warning" testId="cf-metric-callback" />
            <Metric label={t("cf.tab.correction")} value={count("correction")} tone="warning" testId="cf-metric-correction" />
            <Metric label={t("cf.tab.payment")} value={count("payment")} tone="warning" testId="cf-metric-payment" />
          </MetricGrid>
          {queue.error && (!items || queue.stopped) && <ErrorState error={queue.error} compact onAction={queue.refresh} />}
          <div className="dashboard-actions">
            <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare")} data-testid="cf-open-queue"><AppIcon name="orders" size={20} />{t("cf.home.allOrders")}</button>
            {supervisor && <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/team")} data-testid="cf-open-team"><AppIcon name="team" size={20} />{t("cf.home.team")}</button>}
            {canUseFinance(employee) && <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/finance")} data-testid="cf-open-finance"><AppIcon name="orders" size={20} />{t("cf.fin.open")}</button>}
          </div>
        </DashboardSection>
        <Notice tone="warning" role="note" testId="cf-contact-dependency">{t("cf.home.contactDependency")}</Notice>
      </div>
    </div>
  );
}
