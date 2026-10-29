import { useCallback, useEffect, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi } from "../../domain/confirmare";
import { cardRefundEntries, formatFinanceMoney, normalizeAmount, validReference, type FinanceEntry } from "../../domain/confirmareFinance";
import { REACTIVATION_REASONS, blockerKey, opsErrorKey, type OperationOutcome, type OperationPath, type OperationsState, type ReactivationReason } from "../../domain/confirmareOps";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

type Result = { tone: Tone; text: string } | null;
type Unknown = { path: OperationPath; key: string } | null;

const today = (): string => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Bucharest" });

/** Saves the PDF the server relayed from FAN Courier; nothing is generated in the browser. */
function saveBlob(blob: Blob, filename: string) {
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
 * The shop operations of one order (API 2.46.0, owner decisions K-07, K-09 to K-11, K-13, K-14), read from the shop when the
 * panel opens: the reactivation of a cancelled order, the FAN Courier AWB from stage 12 (Control calitate) with the one
 * collection amount of the order, the AWB label, the urgent address change as a FAN Courier panel task, and the NETOPIA
 * full card refund (NETOPIA has no refund API: a manual task, recorded by finance, verified by another employee).
 *
 * Every action is offered only when the server allows it for this employee; the server and the shop check everything
 * again. The card refund is one click by design (owner decision); a second click creates nothing new. A lost answer is
 * checked with the shop by the same key, never sent twice, and nothing is shown as done before the shop confirmed it.
 */
export function ConfirmareOperationsPanel({ globalOrderId, service, mode = "order", onChanged }: { globalOrderId: string; service: ConfirmareApi; mode?: "order" | "awb"; onChanged?: () => void }) {
  const { t } = useI18n();
  const [state, setState] = useState<OperationsState | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [result, setResult] = useState<Result>(null);
  const [unknown, setUnknown] = useState<Unknown>(null);
  const [reason, setReason] = useState<ReactivationReason | "">("");
  const [exceptionEntryId, setExceptionEntryId] = useState("");
  const [parcels, setParcels] = useState("1");
  const [weight, setWeight] = useState("");
  const [codSeen, setCodSeen] = useState(false);
  const [addressNote, setAddressNote] = useState("");
  const [codNote, setCodNote] = useState("");
  const [codFixed, setCodFixed] = useState(false);
  const [refund, setRefund] = useState({ amount: "", reference: "", valueDate: today() });
  const [entries, setEntries] = useState<FinanceEntry[] | null>(null);
  const keys = useRef(new Map<string, string>());

  const [tick, setTick] = useState(0);
  const load = useCallback(() => setTick((value) => value + 1), []);

  useEffect(() => {
    const controller = new AbortController();
    service.operations(globalOrderId, controller.signal).then(async (value) => {
      if (controller.signal.aborted) return;
      setLoadError(null);
      setState(value);
      // A NETOPIA refund recorded by a colleague is verified here; its entry comes from the finance read of the order.
      if (value.allowed.cardRefundVerify && (value.shop?.cardRefund.requests.some((entry) => entry.state === "manual_required") ?? false)) {
        const finance = await service.financeRead(globalOrderId, controller.signal).catch(() => null);
        if (!controller.signal.aborted) setEntries(finance && finance.available ? cardRefundEntries(finance) : []);
      } else setEntries(null);
    }).catch((caught: unknown) => {
      if (!controller.signal.aborted) setLoadError(getErrorPresentation(toServiceError(caught)).message);
    });
    return () => controller.abort();
  }, [service, globalOrderId, tick]);

  const failure = (caught: unknown): string => {
    const failed = toServiceError(caught);
    const code = typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined;
    const key = opsErrorKey(code, failed.details?.editReason);
    return key ? t(key) : getErrorPresentation(failed).message;
  };

  /** One idempotency key per intent: a retried click replays the same operation, never a second one. */
  const keyFor = (intent: string): string => {
    const known = keys.current.get(intent);
    if (known) return known;
    const key = createIdempotencyKey();
    keys.current.set(intent, key);
    return key;
  };

  async function operate(path: OperationPath, intent: string, input: Record<string, unknown>, success: (outcome: OperationOutcome) => string) {
    setBusy(path); setResult(null);
    const key = keyFor(intent);
    try {
      const outcome = await service.operate(globalOrderId, path, input, key);
      conclude(path, key, intent, outcome, success);
    } catch (caught) {
      keys.current.delete(intent);
      setResult({ tone: "danger", text: failure(caught) });
    } finally {
      setBusy(null);
      load();
      onChanged?.();
    }
  }

  function conclude(path: OperationPath, key: string, intent: string | null, outcome: OperationOutcome, success: (outcome: OperationOutcome) => string) {
    if (outcome.state === "applied") {
      if (intent) keys.current.delete(intent);
      setUnknown(null);
      setResult({ tone: "success", text: success(outcome) });
      if (path === "awb") setCodSeen(false);
      if (path === "awb/address") setAddressNote("");
      if (path === "awb/cod-corrected") { setCodFixed(false); setCodNote(""); }
      if (path === "reactivate") { setReason(""); setExceptionEntryId(""); }
      return;
    }
    // The shop may have done it: never send again, ask the shop by the same key.
    setUnknown({ path, key });
    setResult({ tone: "warning", text: t("cf.ops.unknown") });
  }

  async function reconcile() {
    if (!unknown) return;
    setBusy("reconcile"); setResult(null);
    try {
      const outcome = await service.operationsReconcile(globalOrderId, unknown.key);
      conclude(unknown.path, unknown.key, null, outcome, () => t("cf.ops.reconciled"));
    } catch (caught) {
      setUnknown(null);
      setResult({ tone: "danger", text: failure(caught) });
    } finally {
      setBusy(null);
      load();
      onChanged?.();
    }
  }

  async function label() {
    setBusy("label"); setResult(null);
    try {
      saveBlob(await service.awbLabel(globalOrderId), `awb-${state?.shop?.awb.number ?? "fan"}.pdf`);
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
    } finally { setBusy(null); }
  }

  if (!state) {
    return (
      <section className="detail-section" aria-labelledby={`cf-ops-${mode}`} data-testid="cf-ops">
        <div className="section-title"><h2 id={`cf-ops-${mode}`}>{t("cf.ops.title")}</h2></div>
        {loadError
          ? <><Notice tone="warning" role="alert">{loadError}</Notice><button type="button" className="button button-secondary" onClick={() => { load(); }}>{t("common.retry")}</button></>
          : <p className="dashboard-footnote" aria-live="polite">{t("common.loading")}</p>}
      </section>
    );
  }

  const shop = state.shop;
  const awb = shop?.awb ?? null;
  const cardRefund = shop?.cardRefund ?? null;
  const openRefund = cardRefund?.requests.find((entry) => entry.state === "manual_required") ?? null;
  const currency = "RON";
  const showReactivation = mode === "order" && state.allowed.reactivate && (shop?.orderStatus === "cancelled" || state.reactivations.length > 0);
  const showRefund = mode === "order" && (state.allowed.cardRefund || state.allowed.cardRefundRecord || state.allowed.cardRefundVerify) && cardRefund !== null && (cardRefund.available || cardRefund.requests.length > 0);
  const showAwb = awb !== null && (mode === "awb" || state.allowed.awb || awb.state !== "none");
  const parcelCount = Number(parcels);
  const parcelsValid = /^[1-9][0-9]?$/.test(parcels) && parcelCount <= 20;
  const weightValid = weight === "" || /^[0-9]{1,3}([.,][0-9]{1,2})?$/.test(weight);
  const awbBlockers = awb ? awb.blockers.filter((code) => code !== "awb_exists" && code !== "cod_mismatch") : [];
  const codMismatch = awb !== null && !awb.delivered && awb.codCheck?.state === "mismatch";
  const canCreateAwb = state.allowed.awb && awb !== null && awb.state === "none" && awbBlockers.length === 0 && awb.cod !== null;
  const refundAmount = normalizeAmount(refund.amount);

  return (
    <section className="detail-section cf-ops" aria-labelledby={`cf-ops-${mode}`} data-testid="cf-ops">
      <div className="section-title"><h2 id={`cf-ops-${mode}`}>{t(mode === "awb" ? "cf.ops.awbTitle" : "cf.ops.title")}</h2></div>
      {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} entering testId="cf-ops-result">{result.text}</Notice>}
      {unknown && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void reconcile(); }} data-testid="cf-ops-reconcile">{busy === "reconcile" ? t("common.sending") : t("cf.ops.reconcile")}</button>}
      {!state.available && <Notice tone="warning" role="note" testId="cf-ops-unavailable">{t("cf.ops.unavailable")}</Notice>}

      {showAwb && awb && <div className="cf-ops-block" data-testid="cf-ops-awb">
        <h3>{t("cf.ops.awb.title")}</h3>
        <div className="package-pills">
          <StatusPill tone={awb.state === "created" ? "success" : awb.state === "unknown" ? "danger" : "neutral"} icon={awb.state === "created" ? "check" : "clock"} testId="cf-ops-awb-state">
            {awb.state === "created" ? t("cf.ops.awb.created", { number: awb.number ?? "—" }) : t(`cf.ops.awb.state.${awb.state}` as MessageKey)}
          </StatusPill>
          {awb.delivered && <StatusPill tone="success" icon="check">{t("cf.ops.awb.delivered")}</StatusPill>}
          <StatusPill tone="neutral">{t("cf.ops.awb.pickupSeparate")}</StatusPill>
        </div>
        {!state.awbStage && awb.state === "none" && <Notice tone="info" role="note" testId="cf-ops-awb-stage">{t("cf.ops.awb.stage")}</Notice>}
        {awb.cod !== null && <p className="cf-line-facts" data-testid="cf-ops-awb-cod">{t("cf.ops.awb.cod", { amount: formatFinanceMoney(awb.cod, currency) })}</p>}
        {awb.codCheck && awb.codCheck.state === "mismatch" && <Notice tone="danger" role="alert" testId="cf-ops-awb-mismatch">{t("cf.ops.awb.mismatch", { awb: formatFinanceMoney(awb.codCheck.awbCod, currency), expected: formatFinanceMoney(awb.codCheck.expected, currency) })}</Notice>}
        {awb.codCheck && awb.codCheck.state === "match" && awb.codCheck.corrected && <p className="dashboard-footnote" data-testid="cf-ops-awb-corrected">{t("cf.ops.awb.corrected")}</p>}
        {/* API 2.50.0: the label stops while the AWB collects another amount; the employee states the FAN Courier correction. */}
        {codMismatch && state.allowed.awb && awb.codCheck?.expected && <div className="field cf-ops-address" data-testid="cf-ops-cod-fix">
          <Notice tone="warning" role="note">{t("cf.ops.awb.labelBlocked")}</Notice>
          <label htmlFor={`cf-awb-cod-note-${mode}`}>{t("cf.ops.cod.note")}</label>
          <input id={`cf-awb-cod-note-${mode}`} value={codNote} maxLength={200} onChange={(event) => setCodNote(event.target.value)} data-testid="cf-awb-cod-note" />
          <label className="check-field"><input type="checkbox" checked={codFixed} onChange={(event) => setCodFixed(event.target.checked)} data-testid="cf-awb-cod-fixed" /><span className="cf-check">{t("cf.ops.cod.check", { amount: formatFinanceMoney(awb.codCheck.expected, currency) })}</span></label>
          <div className="cf-confirm-actions">
            <button type="button" className="button button-primary" disabled={busy !== null || !codFixed || codNote.trim().length < 3} data-testid="cf-awb-cod-confirm"
              onClick={() => { const amount = awb.codCheck?.expected; if (!amount) return; void operate("awb/cod-corrected", `cod|${amount}|${codNote.trim()}`, { amount, reasonNote: codNote.trim() }, () => t("cf.ops.cod.done")); }}>{t("cf.ops.cod.confirm")}</button>
          </div>
        </div>}
        {awb.state === "unknown" && <Notice tone="danger" role="note">{t("cf.ops.awb.unknownHint")}</Notice>}
        {awbBlockers.length > 0 && awb.state === "none" && <ul className="cf-checklist" data-testid="cf-ops-awb-blockers">{awbBlockers.map((code) => <li key={code} className="is-missing"><AppIcon name="lock" size={18} /><span>{t(blockerKey(code))}</span></li>)}</ul>}
        {canCreateAwb && <form className="cf-ops-form" onSubmit={(event) => {
          event.preventDefault();
          if (!parcelsValid || !weightValid || !codSeen || awb.cod === null) return;
          const input = { parcels: parcelCount, expectedCod: awb.cod, ...(weight ? { weightKg: Number(weight.replace(",", ".")) } : {}) };
          void operate("awb", `awb|${parcelCount}|${weight}|${awb.cod}`, input, (outcome) => t("cf.ops.awb.done", { number: outcome.shop?.awb.number ?? "—" }));
        }}>
          <div className="field"><label htmlFor={`cf-awb-parcels-${mode}`}>{t("cf.ops.awb.parcels")}</label><input id={`cf-awb-parcels-${mode}`} inputMode="numeric" value={parcels} onChange={(event) => setParcels(event.target.value.trim())} aria-invalid={!parcelsValid} data-testid="cf-awb-parcels" /></div>
          <div className="field"><label htmlFor={`cf-awb-weight-${mode}`}>{t("cf.ops.awb.weight")}</label><input id={`cf-awb-weight-${mode}`} inputMode="decimal" value={weight} onChange={(event) => setWeight(event.target.value.trim())} aria-invalid={!weightValid} data-testid="cf-awb-weight" /></div>
          <label className="check-field"><input type="checkbox" checked={codSeen} onChange={(event) => setCodSeen(event.target.checked)} data-testid="cf-awb-cod-seen" /><span className="cf-check">{t("cf.ops.awb.codSeen", { amount: formatFinanceMoney(awb.cod, currency) })}<small>{t("cf.ops.awb.codSeenHint")}</small></span></label>
          <button type="submit" className="button button-primary" disabled={busy !== null || !parcelsValid || !weightValid || !codSeen} data-testid="cf-awb-create">{busy === "awb" ? t("common.sending") : t("cf.ops.awb.create")}</button>
        </form>}
        {awb.state === "created" && state.allowed.awb && <div className="cf-confirm-actions">
          <button type="button" className="button button-secondary" disabled={busy !== null || codMismatch} onClick={() => { void label(); }} data-testid="cf-awb-label"><AppIcon name="document" size={18} />{busy === "label" ? t("common.loading") : t("cf.ops.awb.label")}</button>
        </div>}
        {awb.state === "created" && state.allowed.awb && !awb.delivered && <div className="field cf-ops-address">
          <label htmlFor={`cf-awb-address-${mode}`}>{t("cf.ops.address.note")}</label>
          <input id={`cf-awb-address-${mode}`} value={addressNote} maxLength={200} onChange={(event) => setAddressNote(event.target.value)} data-testid="cf-awb-address-note" />
          <p className="dashboard-footnote">{t("cf.ops.address.hint")}</p>
          <div className="cf-confirm-actions">
            <button type="button" className="button button-secondary" disabled={busy !== null || addressNote.trim().length < 3} onClick={() => { void operate("awb/address", `address|task|${addressNote.trim()}`, { step: "task", reasonNote: addressNote.trim() }, () => t("cf.ops.address.taskDone")); }} data-testid="cf-awb-address-task">{t("cf.ops.address.task")}</button>
            <button type="button" className="button button-secondary" disabled={busy !== null || addressNote.trim().length < 3 || !awb.address.some((entry) => entry.kind === "task")} onClick={() => { void operate("awb/address", `address|done|${addressNote.trim()}`, { step: "done", reasonNote: addressNote.trim() }, () => t("cf.ops.address.doneDone")); }} data-testid="cf-awb-address-done">{t("cf.ops.address.done")}</button>
          </div>
        </div>}
        {awb.address.length > 0 && <ol className="timeline">{awb.address.map((entry, index) => <li key={`${entry.kind}-${index}`}><strong>{t(`cf.ops.address.${entry.kind}Entry` as MessageKey)}</strong><small>{when(entry.at)}</small></li>)}</ol>}
      </div>}

      {showReactivation && <div className="cf-ops-block" data-testid="cf-ops-reactivation">
        <h3>{t("cf.ops.reactivate.title")}</h3>
        <p className="dashboard-footnote">{t(state.reactivations.length === 0 ? "cf.ops.reactivate.first" : "cf.ops.reactivate.next")}</p>
        {state.reactivations.length > 0 && <ol className="timeline">{state.reactivations.map((entry) => <li key={entry.sequence}><strong>{t(`cf.ops.reactivate.reason.${entry.reason}` as MessageKey)}</strong><small>{entry.by} · {t(`cf.ops.reactivate.state.${entry.state === "applied" || entry.state === "completed" ? entry.state : "other"}` as MessageKey)}</small><small>{when(entry.at)}</small></li>)}</ol>}
        {shop?.orderStatus === "cancelled" && <form className="cf-ops-form" onSubmit={(event) => {
          event.preventDefault();
          if (!reason) return;
          const input = { reason, ...(state.reactivations.length > 0 && exceptionEntryId.trim() ? { exceptionEntryId: exceptionEntryId.trim() } : {}) };
          void operate("reactivate", `reactivate|${state.reactivations.length}|${reason}|${exceptionEntryId.trim()}`, input, () => t("cf.ops.reactivate.done"));
        }}>
          <div className="field"><label htmlFor="cf-reactivate-reason">{t("cf.ops.reactivate.reasonLabel")}</label>
            <select id="cf-reactivate-reason" value={reason} onChange={(event) => setReason(event.target.value as ReactivationReason | "")} data-testid="cf-reactivate-reason">
              <option value="">{t("cf.actions.chooseReason")}</option>
              {REACTIVATION_REASONS.map((value) => <option key={value} value={value}>{t(`cf.ops.reactivate.reason.${value}` as MessageKey)}</option>)}
            </select>
          </div>
          {state.reactivations.length > 0 && <div className="field"><label htmlFor="cf-reactivate-entry">{t("cf.ops.reactivate.entry")}</label><input id="cf-reactivate-entry" value={exceptionEntryId} maxLength={64} onChange={(event) => setExceptionEntryId(event.target.value)} data-testid="cf-reactivate-entry" /><p className="dashboard-footnote">{t("cf.ops.reactivate.entryHint")}</p></div>}
          <button type="submit" className="button button-primary" disabled={busy !== null || !reason || (state.reactivations.length > 0 && !/^[A-Za-z0-9_-]{1,64}$/.test(exceptionEntryId.trim()))} data-testid="cf-reactivate">{busy === "reactivate" ? t("common.sending") : t("cf.ops.reactivate.submit")}</button>
        </form>}
      </div>}

      {showRefund && cardRefund && <div className="cf-ops-block" data-testid="cf-ops-refund">
        <h3>{t("cf.ops.refund.title")}</h3>
        <p className="dashboard-footnote">{t("cf.ops.refund.lead", { count: cardRefund.transactions })}</p>
        {cardRefund.requests.length > 0 && <ol className="timeline">{cardRefund.requests.map((entry, index) => <li key={`${entry.at}-${index}`}>
          <strong>{t(`cf.ops.refund.state.${entry.state}` as MessageKey)}</strong>
          <small>{[entry.amount ? formatFinanceMoney(entry.amount, currency) : null, t("cf.ops.refund.transactions", { count: entry.transactions }), entry.mailed ? t("cf.ops.refund.mailed") : null].filter(Boolean).join(" · ")}</small>
          {entry.state === "manual_required" && entry.verified > 0 && <small data-testid="cf-refund-partial">{t("cf.ops.refund.partial", { done: entry.verified, total: entry.transactions })}</small>}
          <small>{when(entry.completedAt ?? entry.at)}</small>
        </li>)}</ol>}
        {state.allowed.cardRefund && cardRefund.available && !openRefund && cardRefund.requests.every((entry) => entry.state !== "completed") && (
          <button type="button" className="button button-danger-outline" disabled={busy !== null} onClick={() => { void operate("card-refund", "card-refund", {}, (outcome) => t(outcome.result === "already_requested" ? "cf.ops.refund.already" : "cf.ops.refund.requested")); }} data-testid="cf-refund-full">
            {busy === "card-refund" ? t("common.sending") : t("cf.ops.refund.full")}
          </button>
        )}
        {openRefund && <Notice tone="warning" role="note" testId="cf-refund-manual">{t("cf.ops.refund.manual", { amount: formatFinanceMoney(openRefund.amount, currency) })}</Notice>}
        {openRefund && state.allowed.cardRefundRecord && <form className="cf-ops-form" onSubmit={(event) => {
          event.preventDefault();
          if (!refundAmount || !validReference(refund.reference) || !/^\d{4}-\d{2}-\d{2}$/.test(refund.valueDate)) return;
          const input = { amount: refundAmount, reference: refund.reference.trim(), valueDate: refund.valueDate };
          void operate("card-refund/record", `refund-record|${refundAmount}|${input.reference}|${input.valueDate}`, input, () => t("cf.ops.refund.recorded"));
        }}>
          <div className="field"><label htmlFor="cf-refund-amount">{t("cf.ops.refund.amount")}</label><input id="cf-refund-amount" inputMode="decimal" value={refund.amount} placeholder={openRefund.amount ?? ""} onChange={(event) => setRefund({ ...refund, amount: event.target.value })} data-testid="cf-refund-amount" /></div>
          <div className="field"><label htmlFor="cf-refund-reference">{t("cf.ops.refund.reference")}</label><input id="cf-refund-reference" value={refund.reference} maxLength={64} onChange={(event) => setRefund({ ...refund, reference: event.target.value })} data-testid="cf-refund-reference" /></div>
          <div className="field"><label htmlFor="cf-refund-date">{t("cf.ops.refund.valueDate")}</label><input id="cf-refund-date" type="date" value={refund.valueDate} onChange={(event) => setRefund({ ...refund, valueDate: event.target.value })} data-testid="cf-refund-date" /></div>
          <p className="dashboard-footnote">{t("cf.ops.refund.recordHint")}</p>
          <button type="submit" className="button button-primary" disabled={busy !== null || !refundAmount || !validReference(refund.reference)} data-testid="cf-refund-record">{busy === "card-refund/record" ? t("common.sending") : t("cf.ops.refund.record")}</button>
        </form>}
        {state.allowed.cardRefundVerify && entries && entries.map((entry) => <div key={entry.id} className="cf-confirm" role="group" data-testid="cf-refund-verify-entry">
          <p><strong>{t("cf.ops.refund.verifyQuestion", { amount: formatFinanceMoney(entry.amount, currency), reference: entry.reference })}</strong></p>
          <p className="dashboard-footnote">{t("cf.ops.refund.verifyHint")}</p>
          <div className="cf-confirm-actions">
            <button type="button" className="button button-primary" disabled={busy !== null} onClick={() => { void operate("card-refund/verify", `refund-verify|${entry.id}|verify`, { entryId: entry.id, decision: "verify" }, () => t("cf.ops.refund.verified")); }} data-testid="cf-refund-verify">{t("cf.ops.refund.verify")}</button>
            <button type="button" className="button button-danger-outline" disabled={busy !== null} onClick={() => { void operate("card-refund/verify", `refund-verify|${entry.id}|reject`, { entryId: entry.id, decision: "reject" }, () => t("cf.ops.refund.rejected")); }} data-testid="cf-refund-reject">{t("cf.ops.refund.reject")}</button>
          </div>
        </div>)}
      </div>}

      {!showAwb && !showReactivation && !showRefund && state.available && <p className="dashboard-footnote" data-testid="cf-ops-none">{t("cf.ops.none")}</p>}
    </section>
  );
}
