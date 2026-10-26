import { useMemo, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel, type ConfirmareApi } from "../../domain/confirmare";
import { opsErrorKey, type BankChecks, type CycleAttentionItem } from "../../domain/confirmareOps";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { QueueSkeleton } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { confirmareOrderPath, when } from "./ConfirmareParts";

type Data = { checks: BankChecks; cycles: CycleAttentionItem[] };

/**
 * The bank check of the advance proformas (API 2.46.0, owner decision K-05): before an expired advance proforma and its
 * order are cancelled, a finance employee declares that the shop's bank statement was checked up to now. The declaration
 * covers every proforma of the shop that expired before it; nothing is cancelled while a transfer may be on its way.
 * Below, the open proforma cycles of the employee's shops, the ones that need a person first.
 */
export function BankCheckSection({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [confirm, setConfirm] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const key = useRef<{ source: string; key: string } | null>(null);
  const load = useMemo(() => async (signal: AbortSignal): Promise<Data> => {
    const [checks, cycles] = await Promise.all([service.bankChecks(null, signal), service.cycleAttention(null, signal)]);
    return { checks, cycles };
  }, [service]);
  const view = useAutoRefresh(load, "confirmare-bank-checks");
  const data = view.data;

  async function record(source: string) {
    setBusy(true); setResult(null);
    if (key.current?.source !== source) key.current = { source, key: createIdempotencyKey() };
    try {
      const check = await service.recordBankCheck(source, key.current.key);
      key.current = null;
      setConfirm(null);
      setResult({ tone: "success", text: t("cf.bank.recorded", { source: sourceLabel(source), time: when(check.coversUntil) }) });
    } catch (caught) {
      const failed = toServiceError(caught);
      const known = opsErrorKey(typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined, undefined);
      setResult({ tone: "danger", text: known ? t(known) : getErrorPresentation(failed).message });
    } finally {
      setBusy(false);
      view.refresh();
    }
  }

  if (!data) return view.error ? <Notice tone="warning" role="note">{getErrorPresentation(view.error).message}</Notice> : <QueueSkeleton />;
  const urgent = data.cycles.filter((cycle) => cycle.state === "waiting_bank_check" || cycle.lastError !== null || cycle.state === "cancel_unknown");
  const others = data.cycles.filter((cycle) => !urgent.includes(cycle));

  return (
    <section className="detail-section" aria-labelledby="cf-bank" data-testid="cf-bank">
      <div className="section-title"><h2 id="cf-bank">{t("cf.bank.title")}</h2></div>
      <p className="dashboard-lead">{t("cf.bank.lead")}</p>
      {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} entering>{result.text}</Notice>}
      <ul className="cf-checklist">
        {data.checks.sources.map((source) => {
          const last = data.checks.items.find((check) => check.source === source) ?? null;
          const waiting = data.checks.waiting[source] ?? 0;
          return <li key={source} className={waiting > 0 ? "is-missing" : "is-met"} data-testid={`cf-bank-${source}`}>
            <AppIcon name={waiting > 0 ? "clock" : "check"} size={18} />
            <span><strong>{sourceLabel(source)}</strong> · {last ? t("cf.bank.last", { time: when(last.coversUntil), name: last.by ?? "—" }) : t("cf.bank.never")}{waiting > 0 ? ` · ${t("cf.bank.waiting", { count: waiting })}` : ""}</span>
            {data.checks.canRecord.includes(source) && (confirm === source
              ? <span className="cf-confirm-actions">
                <button type="button" className="button button-primary" disabled={busy} onClick={() => { void record(source); }} data-testid={`cf-bank-confirm-${source}`}>{busy ? t("common.sending") : t("cf.bank.confirm")}</button>
                <button type="button" className="button button-secondary" disabled={busy} onClick={() => setConfirm(null)}>{t("common.cancel")}</button>
              </span>
              : <button type="button" className="button button-secondary" disabled={busy} onClick={() => setConfirm(source)} data-testid={`cf-bank-record-${source}`}>{t("cf.bank.record")}</button>)}
          </li>;
        })}
      </ul>
      {confirm && <p className="dashboard-footnote">{t("cf.bank.question", { source: sourceLabel(confirm) })}</p>}
      <h3>{t("cf.bank.cycles")}</h3>
      {data.cycles.length === 0
        ? <p className="dashboard-footnote">{t("cf.bank.noCycles")}</p>
        : <ul className="queue-list">{[...urgent, ...others].map((cycle) => <li key={`${cycle.globalOrderId}-${cycle.cycle}`}>
          <button type="button" className={`queue-row cf-row tone-${urgent.includes(cycle) ? "warning" : "neutral"}`} onClick={() => navigate(confirmareOrderPath(cycle.globalOrderId))} data-testid={`cf-cycle-row-${cycle.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top"><strong className="order-id">#{cycle.orderNumber}</strong></span>
              <small>{t("cf.cycle.expires")}: {when(cycle.expiresAt)}{cycle.lastError ? ` · ${t("cf.bank.cycleError")}` : ""}</small>
            </span>
            <StatusPill tone={urgent.includes(cycle) ? "warning" : "neutral"}>{t(`cf.cycle.state.${cycle.state}` as MessageKey)}</StatusPill>
            <AppIcon name="chevron" size={18} />
          </button>
        </li>)}</ul>}
    </section>
  );
}
