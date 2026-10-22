import { useMemo, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { inTab, needsAttention, sourceLabel, type ConfirmareAlert, type ConfirmareApi, type ConfirmareItem, type ConfirmareTeam } from "../../domain/confirmare";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { EmptyPanel, PageHeader } from "../../components/ui/States";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { AutoRefreshBar, DashboardSection, EmptyState, Metric, MetricGrid, QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ConfirmareRow, confirmareOrderPath, when } from "./ConfirmareParts";

type TeamData = { team: ConfirmareTeam; alerts: ConfirmareAlert[]; items: ConfirmareItem[] };

/** Reads only: the team, the open alerts and the supervised queue (pending orders and approvals still waiting). */
function teamLoader(service: ConfirmareApi) {
  return async (signal: AbortSignal): Promise<TeamData> => {
    const [team, alerts, open, followup] = await Promise.all([service.team(signal), service.alerts(signal), service.pool("open", null, signal), service.pool("followup", null, signal)]);
    return { team, alerts: alerts.items, items: [...open.items, ...followup.items] };
  };
}

/**
 * Supervisor view of the Confirmare team. It shows the distribution the server made, leave, delayed work and orders
 * whose source facts need a decision. Leave is the only change made here (it returns the person's orders to the pool,
 * which the server redistributes at once); reassigning, releasing and reopening happen on the order itself. Nothing
 * here bypasses the customer confirmation or the payment condition.
 */
export function ConfirmareTeamScreen({ employee, service, navigate }: { employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const load = useMemo(() => teamLoader(service), [service]);
  const view = useAutoRefresh(load, "confirmare-team");
  const data = view.data;
  const [busy, setBusy] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: Tone; text: string } | null>(null);
  const keys = useRef(new Map<string, string>());

  async function setLeave(employeeId: string, available: boolean) {
    const intent = `${employeeId}|${available}`;
    const key = keys.current.get(intent) ?? createIdempotencyKey();
    keys.current.set(intent, key);
    setBusy(employeeId); setMessage(null);
    try {
      await service.setAvailability({ available, employeeId }, key);
      keys.current.delete(intent);
      setMessage({ tone: "success", text: available ? t("cf.team.backDone") : t("cf.team.leaveDone") });
    } catch (caught) {
      setMessage({ tone: "danger", text: getErrorPresentation(toServiceError(caught)).message });
    } finally { setBusy(null); setConfirming(null); view.refresh(); }
  }

  const attention = data ? data.items.filter(needsAttention) : [];
  const unassigned = data ? data.items.filter((item) => inTab(item, "unassigned", employee.employeeUuid)) : [];
  const total = (counts: Record<string, number> | undefined) => counts ? Object.values(counts).reduce((sum, value) => sum + value, 0) : undefined;

  return (
    <article className="screen-stack confirmare-screen" data-testid="cf-team">
      <button className="back-link" type="button" onClick={() => navigate("/confirmare")}><AppIcon name="back" size={20} /> {t("cf.order.back")}</button>
      <PageHeader title={t("cf.team.title")} description={t("cf.team.description")} />
      <AutoRefreshBar onRefresh={view.refresh} busy={view.busy} refreshing={view.refreshing} updatedAt={view.updatedAt} stale={data !== null && view.error !== null} stopped={view.stopped} testId="cf-team-refresh" />
      {view.error && (!data || view.stopped) && <Notice tone="danger" role="alert">{getErrorPresentation(view.error).message}</Notice>}
      {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"} entering>{message.text}</Notice>}
      {data && data.team.held.length > 0 && <Notice tone="warning" role="note">{t("cf.team.held", { sources: data.team.held.map(sourceLabel).join(", ") })}</Notice>}

      <div className="workspace-body split-workspace">
        <div className="workspace-main">
          <DashboardSection labelledBy="cf-team-people" eyebrow={t("cf.team.distributionEyebrow")} title={t("cf.team.people")}>
            {!data && !view.error && <QueueSkeleton />}
            {data && (data.team.operators.length === 0
              ? <EmptyState title={t("cf.team.noOperators")}>{t("cf.team.noOperatorsHint")}</EmptyState>
              : <ul className="cf-team-list">{data.team.operators.map((person) => {
                const tone: Tone = !person.canWork ? "danger" : !person.available ? "warning" : person.open > data.team.targetOpenPerOperator ? "warning" : "success";
                return <li key={person.employeeId} className="cf-person" data-testid={`cf-person-${person.employeeId}`}>
                  <div className="cf-person-copy">
                    <strong>{person.displayName}</strong>
                    <small>{person.sources.map(sourceLabel).join(" · ")}</small>
                    <small>{t("cf.team.openCount", { count: person.open })}{person.oldestAssignedAt ? ` · ${t("cf.team.oldest", { date: when(person.oldestAssignedAt) })}` : ""}</small>
                  </div>
                  <StatusPill tone={tone}>{!person.canWork ? t("cf.team.cannotWork") : person.available ? t("cf.team.available") : t("cf.team.onLeave")}</StatusPill>
                  {person.canWork && (confirming === person.employeeId
                    ? <div className="cf-confirm-actions">
                      <button type="button" className="button button-primary button-compact" disabled={busy !== null} onClick={() => { void setLeave(person.employeeId, !person.available); }} data-testid="cf-leave-confirm">{person.available ? t("cf.team.leaveConfirm") : t("cf.team.backConfirm")}</button>
                      <button type="button" className="button button-secondary button-compact" disabled={busy !== null} onClick={() => setConfirming(null)}>{t("common.cancel")}</button>
                    </div>
                    : <button type="button" className="button button-secondary button-compact" disabled={busy !== null} onClick={() => setConfirming(person.employeeId)} data-testid="cf-leave">{person.available ? t("cf.team.leave") : t("cf.team.back")}</button>)}
                </li>;
              })}</ul>)}
            {data && <p className="dashboard-footnote">{t("cf.team.leaveNote", { target: data.team.targetOpenPerOperator })}</p>}
          </DashboardSection>

          <DashboardSection labelledBy="cf-team-alerts" eyebrow={t("cf.team.alertsEyebrow")} title={t("cf.team.alerts")}>
            {data && (data.alerts.length === 0
              ? <EmptyState title={t("cf.team.noAlerts")}>{t("cf.team.alertsHint", { minutes: data.team.inactivityMinutes })}</EmptyState>
              : <ul className="queue-list">{data.alerts.map((alert) => <li key={`${alert.globalOrderId}-${alert.confirmationVersion}`}>
                <button type="button" className="queue-row tone-warning" onClick={() => navigate(confirmareOrderPath(alert.globalOrderId))}>
                  <span className="queue-row-main">
                    <span className="queue-row-top"><strong className="order-id">#{alert.orderNumber}</strong><span className="cf-source">{sourceLabel(alert.sourceKey)}</span></span>
                    <small>{t("cf.team.alertLine", { name: alert.assignee.displayName, date: when(alert.assignedAt) })}</small>
                    <small>{alert.lastContactAt ? t("cf.team.lastCall", { date: when(alert.lastContactAt) }) : t("cf.team.noCall")}</small>
                  </span>
                  <span className="queue-chip">{t("cf.team.delayed")}</span>
                  <AppIcon name="chevron" size={18} />
                </button>
              </li>)}</ul>)}
          </DashboardSection>

          <DashboardSection labelledBy="cf-team-attention" eyebrow={t("cf.team.attentionEyebrow")} title={t("cf.tab.attention")}>
            {data && (attention.length === 0
              ? <EmptyPanel icon="check" title={t("cf.team.noAttention")} />
              : <ul className="queue-list">{attention.map((item) => <ConfirmareRow key={item.globalOrderId} item={item} employee={employee} navigate={navigate} />)}</ul>)}
            <p className="dashboard-footnote">{t("cf.tabHint.attention")}</p>
          </DashboardSection>
        </div>

        <div className="workspace-side">
          <DashboardSection labelledBy="cf-team-counts" eyebrow={t("cf.team.countsEyebrow")} title={t("cf.team.counts")}>
            <MetricGrid label={t("cf.team.counts")}>
              <Metric label={t("cf.tab.unassigned")} value={data ? total(data.team.unassigned) ?? 0 : "loading"} tone="action" testId="cf-team-unassigned" />
              <Metric label={t("cf.team.undetermined")} value={data ? total(data.team.undetermined) ?? 0 : "loading"} tone="warning" />
              <Metric label={t("cf.team.alerts")} value={data ? data.alerts.length : "loading"} tone="warning" />
              <Metric label={t("cf.tab.attention")} value={data ? attention.length : "loading"} tone="warning" />
            </MetricGrid>
          </DashboardSection>
          <DashboardSection labelledBy="cf-team-unassigned-list" eyebrow={t("cf.team.waitingEyebrow")} title={t("cf.tab.unassigned")}>
            {data && (unassigned.length === 0
              ? <EmptyState title={t("cf.team.noUnassigned")} />
              : <ul className="queue-list">{unassigned.slice(0, 10).map((item) => <ConfirmareRow key={item.globalOrderId} item={item} employee={employee} navigate={navigate} />)}</ul>)}
            <p className="dashboard-footnote">{t("cf.team.distributionNote")}</p>
          </DashboardSection>
        </div>
      </div>
    </article>
  );
}
