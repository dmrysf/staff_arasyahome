import { useMemo, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel, type ConfirmareApi } from "../../domain/confirmare";
import { opsErrorKey, type BankCheckPreview, type BankChecks, type CycleAttentionItem } from "../../domain/confirmareOps";
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
 * API 2.71.0 (owner decision 2026-11-13): before the check is recorded, the orders it may let the worker cancel and the
 * ones that wait (an open finance task, a finance hold) are shown, and the check names exactly that list (the server
 * chooses the instant and refuses a check whose list changed). Below, the open proforma cycles of the employee's shops.
 */
export function BankCheckSection({ service, navigate }: { service: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [preview, setPreview] = useState<BankCheckPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: "success" | "danger" | "warning"; text: string } | null>(null);
  const key = useRef<{ source: string; fingerprint: string; key: string } | null>(null);
  const load = useMemo(() => async (signal: AbortSignal): Promise<Data> => {
    const [checks, cycles] = await Promise.all([service.bankChecks(null, signal), service.cycleAttention(null, signal)]);
    return { checks, cycles };
  }, [service]);
  const view = useAutoRefresh(load, "confirmare-bank-checks");
  const data = view.data;

  const failure = (caught: unknown): string => {
    const failed = toServiceError(caught);
    const known = opsErrorKey(typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined, undefined);
    return known ? t(known) : getErrorPresentation(failed).message;
  };

  async function open(source: string) {
    setBusy(true); setResult(null);
    try {
      setPreview(await service.previewBankCheck(source));
    } catch (caught) {
      setPreview(null);
      setResult({ tone: "danger", text: failure(caught) });
    } finally {
      setBusy(false);
    }
  }

  async function record(shown: BankCheckPreview) {
    setBusy(true); setResult(null);
    if (key.current?.source !== shown.source || key.current.fingerprint !== shown.fingerprint) key.current = { source: shown.source, fingerprint: shown.fingerprint, key: createIdempotencyKey() };
    try {
      const check = await service.recordBankCheck(shown.source, key.current.key, shown);
      key.current = null;
      setPreview(null);
      setResult({ tone: "success", text: t("cf.bank.recorded", { source: sourceLabel(shown.source), time: when(check.coversUntil) }) });
    } catch (caught) {
      const code = toServiceError(caught).details?.backendCode;
      if (code === "CONFIRMARE_BANK_CHECK_PREVIEW_STALE" || code === "CONFIRMARE_BANK_CHECK_PREVIEW_REQUIRED") {
        // The list changed: the new one is shown; nothing was recorded.
        key.current = null;
        setResult({ tone: "warning", text: t("cf.bank.preview.stale") });
        try { setPreview(await service.previewBankCheck(shown.source)); } catch { setPreview(null); }
      } else {
        setResult({ tone: "danger", text: failure(caught) });
      }
    } finally {
      setBusy(false);
      view.refresh();
    }
  }

  if (!data) return view.error ? <Notice tone="warning" role="note">{getErrorPresentation(view.error).message}</Notice> : <QueueSkeleton />;
  const urgent = data.cycles.filter((cycle) => cycle.state === "waiting_bank_check" || (cycle.lastError !== null && cycle.waitsFor === null) || cycle.state === "cancel_unknown");
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
          return <li key={source} className={`cf-check-action ${waiting > 0 ? "is-missing" : "is-met"}`} data-testid={`cf-bank-${source}`}>
            <AppIcon name={waiting > 0 ? "clock" : "check"} size={18} />
            <span><strong>{sourceLabel(source)}</strong> · {last ? t("cf.bank.last", { time: when(last.coversUntil), name: last.by ?? "—" }) : t("cf.bank.never")}{waiting > 0 ? ` · ${t("cf.bank.waiting", { count: waiting })}` : ""}</span>
            {data.checks.canRecord.includes(source) && preview?.source !== source
              && <button type="button" className="button button-secondary" disabled={busy} onClick={() => { void open(source); }} data-testid={`cf-bank-record-${source}`}>{t("cf.bank.record")}</button>}
          </li>;
        })}
      </ul>
      {preview && <div className="detail-section" data-testid="cf-bank-preview">
        <h3>{t("cf.bank.preview.title")} · {sourceLabel(preview.source)}</h3>
        <p className="dashboard-footnote">{t("cf.bank.question", { source: sourceLabel(preview.source) })}</p>
        {preview.items.length === 0
          ? <p className="dashboard-footnote" data-testid="cf-bank-preview-none">{t("cf.bank.preview.none")}</p>
          : <>
            <p className="dashboard-footnote"><strong>{t("cf.bank.preview.mayCancel", { count: preview.mayCancel })}</strong> · {t("cf.bank.preview.waiting", { count: preview.waiting })}</p>
            <ul className="queue-list">{preview.items.map((item) => <li key={item.globalOrderId}>
              <div className={`queue-row cf-row tone-${item.outcome === "may_cancel" ? "warning" : "neutral"}`} data-testid={`cf-bank-preview-${item.orderNumber}`}>
                <span className="queue-row-main">
                  <span className="queue-row-top"><strong className="order-id">#{item.orderNumber}</strong></span>
                  <small>{t("cf.cycle.expires")}: {when(item.expiresAt)}</small>
                </span>
                <StatusPill tone={item.outcome === "may_cancel" ? "warning" : "neutral"}>{item.waitsFor ? t(`cf.waits.${item.waitsFor}` as MessageKey) : t("cf.bank.preview.itemMay")}</StatusPill>
              </div>
            </li>)}</ul>
          </>}
        <span className="cf-confirm-actions">
          <button type="button" className="button button-primary" disabled={busy} onClick={() => { void record(preview); }} data-testid={`cf-bank-confirm-${preview.source}`}>
            {busy ? t("common.sending") : preview.mayCancel > 0 ? t("cf.bank.confirmWith", { count: preview.mayCancel }) : t("cf.bank.confirm")}
          </button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => { setPreview(null); key.current = null; }}>{t("common.cancel")}</button>
        </span>
      </div>}
      <h3>{t("cf.bank.cycles")}</h3>
      {data.cycles.length === 0
        ? <p className="dashboard-footnote">{t("cf.bank.noCycles")}</p>
        : <ul className="queue-list">{[...urgent, ...others].map((cycle) => <li key={`${cycle.globalOrderId}-${cycle.cycle}`}>
          <button type="button" className={`queue-row cf-row tone-${urgent.includes(cycle) ? "warning" : "neutral"}`} onClick={() => navigate(confirmareOrderPath(cycle.globalOrderId))} data-testid={`cf-cycle-row-${cycle.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top"><strong className="order-id">#{cycle.orderNumber}</strong></span>
              <small>{t("cf.cycle.expires")}: {when(cycle.expiresAt)}{cycle.waitsFor ? ` · ${t(`cf.waits.${cycle.waitsFor}` as MessageKey)}` : cycle.lastError ? ` · ${t("cf.bank.cycleError")}` : ""}</small>
            </span>
            <StatusPill tone={urgent.includes(cycle) ? "warning" : "neutral"}>{t(`cf.cycle.state.${cycle.state}` as MessageKey)}</StatusPill>
            <AppIcon name="chevron" size={18} />
          </button>
        </li>)}</ul>}
    </section>
  );
}
