import { useCallback, useEffect, useState } from "react";
import { useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { sourceLabel, type ConfirmareApi } from "../../domain/confirmare";
import {
  EXCEPTION_REASONS, EXCEPTION_RESOLUTIONS, exceptionReasonLabel, exceptionResolutionLabel, financeErrorKey, financeScopesOf, formatFinanceMoney, normalizeAmount, refusalLabel, validReference,
  type ExceptionReason, type ExceptionRecordInput, type ExceptionResolution, type ExceptionResolveInput, type TransferException, type TransferExceptionList,
} from "../../domain/confirmareFinance";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { Notice } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

type Pending = { action: "record" | "resolve"; input: ExceptionRecordInput | ExceptionResolveInput; key: string; summary: string };

/**
 * The shop's bank transfers that match no order (API 2.44.0, YD SOFT 2.18.0): a finance employee who finds a transfer on
 * the bank statement that cannot be recorded on an order (no payment reference, more than the order costs, duplicated)
 * records it here; ANOTHER finance employee closes it (recorded on the order it belongs to, returned to the payer, or not
 * a customer payment). Never money of an order. Amounts are read from the shop when shown, never kept.
 */
export function TransferExceptionsSection({ employee, service }: { employee: Employee; service: ConfirmareApi }) {
  const { t } = useI18n();
  const scopes = financeScopesOf(employee);
  const sources = (["trendhome", "outletperdele"] as const).filter((source) => scopes.view.includes(source) || scopes.record.includes(source) || scopes.approve.includes(source));
  const [source, setSource] = useState<string>(sources[0] ?? "trendhome");
  // The answer belongs to one shop: another shop's answer is never shown (adjusted while rendering).
  const [loaded, setLoaded] = useState<{ source: string; list: TransferExceptionList | null; error: string } | null>(null);
  const [actionError, setActionError] = useState("");
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Pending | null>(null);
  const [unknown, setUnknown] = useState<Pending | null>(null);
  const [done, setDone] = useState("");
  const load = useCallback((signal?: AbortSignal) => service.transferExceptions(source, signal).then(
    (list) => setLoaded({ source, list, error: "" }),
    (reason: unknown) => { if (!signal?.aborted) setLoaded({ source, list: null, error: getErrorPresentation(reason).message }); },
  ), [service, source]);
  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal);
    return () => controller.abort();
  }, [load]);
  const current = loaded?.source === source ? loaded : null;
  const list = current?.list ?? null;
  const error = actionError || current?.error || "";
  if (sources.length === 0) return null;

  const send = async (value: Pending) => {
    setBusy(true);
    setActionError("");
    setDone("");
    try {
      const outcome = await service.transferExceptionAct(source, value.action, value.input, value.key);
      if (outcome.state === "unknown") {
        // The shop may have stored it: the same key gets its stored answer, never a second record.
        setUnknown(value);
      } else {
        setUnknown(null);
        setLoaded({ source, list: outcome, error: "" });
        setDone(t("cf.fin.exc.done"));
      }
      setPending(null);
    } catch (caught: unknown) {
      // The same mapping as the order's finance panel: a shop refusal in the employee's words, then the known API errors.
      const failed = toServiceError(caught);
      const code = typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined;
      const key = financeErrorKey(code);
      setActionError(code === "CONFIRMARE_FINANCE_REFUSED" ? t("cf.fin.refused", { reason: refusalLabel(typeof failed.details?.editReason === "string" ? failed.details.editReason : undefined) }) : key ? t(key) : getErrorPresentation(failed).message);
      setPending(null);
    } finally {
      setBusy(false);
    }
  };
  const money = (amount: string, currency: string) => formatFinanceMoney(amount, currency);

  return (
    <section className="cf-shop-block cf-exceptions" aria-labelledby="cf-exc-title" data-testid="cf-exceptions">
      <h3 id="cf-exc-title">{t("cf.fin.exc.title")}</h3>
      <p className="dashboard-footnote">{t("cf.fin.exc.lead")}</p>
      {sources.length > 1 && <div className="segmented cf-tabs" role="tablist" aria-label={t("cf.fin.exc.title")}>
        {sources.map((id) => <button key={id} type="button" role="tab" aria-selected={source === id} onClick={() => { setSource(id); setActionError(""); setDone(""); setUnknown(null); setPending(null); }} data-testid={`cf-exc-source-${id}`}>{sourceLabel(id)}</button>)}
      </div>}
      {error && <Notice tone="danger" role="alert" testId="cf-exc-error">{error}</Notice>}
      {done && <Notice tone="success" role="status" testId="cf-exc-done">{done}</Notice>}
      {unknown && <div className="cf-finance-decision" data-testid="cf-exc-unknown">
        <Notice tone="warning" role="alert">{t("cf.fin.exc.unknown")}</Notice>
        <button type="button" className="button button-primary" disabled={busy} onClick={() => void send(unknown)} data-testid="cf-exc-retry">{busy ? t("common.processing") : t("cf.fin.exc.retry")}</button>
      </div>}
      {list && !list.available && <p className="dashboard-footnote" data-testid="cf-exc-unavailable">{t("cf.fin.exc.unavailable")}</p>}
      {list?.available && (list.exceptions.length === 0
        ? <p className="dashboard-footnote" data-testid="cf-exc-empty">{t("cf.fin.exc.empty")}</p>
        : <ul className="cf-finance-list" data-testid="cf-exc-list">{list.exceptions.map((item) => (
          <ExceptionRow key={item.id} item={item} me={employee.employeeUuid} approve={list.capabilities.approve} busy={busy || pending !== null} money={money}
            onPrepare={(input, summary) => setPending({ action: "resolve", input, key: createIdempotencyKey(), summary })} />
        ))}</ul>)}
      {pending
        ? <div className="cf-shop-block cf-finance-confirm" role="group" aria-labelledby="cf-exc-confirm-title" data-testid="cf-exc-confirm">
          <h4 id="cf-exc-confirm-title">{t("cf.fin.confirmTitle")}</h4>
          <p>{pending.summary}</p>
          <div className="cf-shop-actions">
            <button type="button" className="button button-primary" disabled={busy} onClick={() => void send(pending)} data-testid="cf-exc-send">{busy ? t("common.processing") : t("cf.fin.send")}</button>
            <button type="button" className="button button-secondary" disabled={busy} onClick={() => setPending(null)} data-testid="cf-exc-cancel">{t("cf.fin.cancel")}</button>
          </div>
        </div>
        : list?.available && list.capabilities.record && <RecordForm currency={list.currency ?? "RON"} busy={busy} money={money}
          onPrepare={(input, summary) => setPending({ action: "record", input, key: createIdempotencyKey(), summary })} />}
    </section>
  );
}

function ExceptionRow({ item, me, approve, busy, money, onPrepare }: {
  item: TransferException; me: string; approve: boolean; busy: boolean; money: (amount: string, currency: string) => string; onPrepare: (input: ExceptionResolveInput, summary: string) => void;
}) {
  const { t } = useI18n();
  const [resolution, setResolution] = useState<ExceptionResolution>("recorded_on_order");
  const [orderNumber, setOrderNumber] = useState("");
  const [problem, setProblem] = useState("");
  const own = item.submittedBy === me;
  const prepare = () => {
    const number = orderNumber.trim();
    if (resolution === "recorded_on_order" && !/^#?[A-Za-z0-9._-]{1,64}$/.test(number)) { setProblem(t("cf.fin.exc.invalidOrder")); return; }
    setProblem("");
    onPrepare({ entryId: item.id, resolution, ...(resolution === "recorded_on_order" ? { orderNumber: number.replace(/^#/, "") } : {}) },
      t("cf.fin.exc.confirmResolve", { amount: money(item.amount, item.currency), reference: item.reference, resolution: exceptionResolutionLabel(resolution) + (resolution === "recorded_on_order" ? ` #${number.replace(/^#/, "")}` : "") }));
  };
  return (
    <li className="cf-exception" data-testid={`cf-exc-${item.state}`}>
      <span><strong>{money(item.amount, item.currency)}</strong> · <span translate="no">{item.reference}</span>{item.valueDate ? ` · ${item.valueDate}` : ""}</span>
      <span className="dashboard-footnote">{exceptionReasonLabel(item.reason)} · {t("cf.fin.exc.recordedAt", { time: when(item.submittedAt) })}</span>
      {item.state === "resolved" && item.resolution && <span className="dashboard-footnote" data-testid="cf-exc-resolution">
        {t("cf.fin.exc.resolved", { resolution: exceptionResolutionLabel(item.resolution) })}{item.order ? ` #${item.order.number ?? item.order.id}` : ""} · {when(item.resolvedAt)}
      </span>}
      {item.state === "open" && own && <span className="dashboard-footnote">{t("cf.fin.exc.own")}</span>}
      {item.state === "open" && approve && !own && <form className="cf-shop-actions" onSubmit={(event) => { event.preventDefault(); prepare(); }} data-testid="cf-exc-resolve-form">
        <label className="field-inline">{t("cf.fin.exc.resolutionLabel")}
          <select value={resolution} onChange={(event) => setResolution(event.target.value as ExceptionResolution)} data-testid="cf-exc-resolution-select">
            {EXCEPTION_RESOLUTIONS.map((code) => <option key={code} value={code}>{exceptionResolutionLabel(code)}</option>)}
          </select>
        </label>
        {resolution === "recorded_on_order" && <label className="field-inline">{t("cf.fin.exc.orderNumber")}
          <input autoComplete="off" maxLength={65} value={orderNumber} onChange={(event) => setOrderNumber(event.target.value)} data-testid="cf-exc-order" />
        </label>}
        <button type="submit" className="button button-secondary" disabled={busy} data-testid="cf-exc-resolve">{t("cf.fin.exc.resolve")}</button>
        {problem && <Notice tone="danger" role="alert">{problem}</Notice>}
      </form>}
    </li>
  );
}

function RecordForm({ currency, busy, money, onPrepare }: { currency: string; busy: boolean; money: (amount: string, currency: string) => string; onPrepare: (input: ExceptionRecordInput, summary: string) => void }) {
  const { t } = useI18n();
  const [amount, setAmount] = useState("");
  const [reference, setReference] = useState("");
  const [valueDate, setValueDate] = useState(new Date().toISOString().slice(0, 10));
  const [reason, setReason] = useState<ExceptionReason>("unmatched");
  const [problem, setProblem] = useState("");
  const prepare = () => {
    const normalized = normalizeAmount(amount);
    if (normalized === null) { setProblem(t("cf.fin.invalidAmount")); return; }
    if (!validReference(reference)) { setProblem(t("cf.fin.invalidReference")); return; }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(valueDate)) { setProblem(t("cf.fin.invalidDate")); return; }
    setProblem("");
    onPrepare({ amount: normalized, currency, reference: reference.trim(), valueDate, reason },
      t("cf.fin.exc.confirmRecord", { amount: money(normalized, currency), reference: reference.trim(), date: valueDate, reason: exceptionReasonLabel(reason) }));
  };
  return (
    <form className="cf-finance-form" onSubmit={(event) => { event.preventDefault(); prepare(); }} data-testid="cf-exc-record-form">
      <label>{t("cf.fin.amount")} ({currency})<input inputMode="decimal" autoComplete="off" value={amount} onChange={(event) => setAmount(event.target.value)} data-testid="cf-exc-amount" /></label>
      <label>{t("cf.fin.reference")}<input autoComplete="off" maxLength={64} value={reference} onChange={(event) => setReference(event.target.value)} data-testid="cf-exc-reference" /></label>
      <label>{t("cf.fin.valueDate")}<input type="date" value={valueDate} onChange={(event) => setValueDate(event.target.value)} data-testid="cf-exc-date" /></label>
      <label>{t("cf.fin.exc.reasonLabel")}
        <select value={reason} onChange={(event) => setReason(event.target.value as ExceptionReason)} data-testid="cf-exc-reason">
          {EXCEPTION_REASONS.map((code) => <option key={code} value={code}>{exceptionReasonLabel(code)}</option>)}
        </select>
      </label>
      {problem && <Notice tone="danger" role="alert" testId="cf-exc-problem">{problem}</Notice>}
      <button type="submit" className="button button-primary" disabled={busy} data-testid="cf-exc-prepare">{t("cf.fin.exc.record")}</button>
    </form>
  );
}
