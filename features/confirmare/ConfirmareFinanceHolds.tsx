import { useMemo, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel, type ConfirmareApi } from "../../domain/confirmare";
import { HOLD_REASONS, HOLD_RELEASE_REASONS, opsErrorKey, type FinanceHold, type FinanceReview, type HoldReason, type HoldReleaseReason } from "../../domain/confirmareOps";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { when } from "./ConfirmareParts";

/**
 * Finance holds and the review list (API 2.71.0, owner decision 2026-11-13), for the shop's Confirmare supervisors and
 * finance employees who record or approve. A hold stops the advance proforma expiry of an order whose customer may already
 * have paid (no cancellation, no production) until ANOTHER authorized employee releases it with a reason. It records no
 * money: payments are still recorded only by the finance rules. Below: open finance tasks older than a day and the
 * expired proformas, so none is forgotten. Nothing here acts by itself.
 */
export function FinanceHoldsSection({ service }: { service: ConfirmareApi }) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal): Promise<FinanceReview | null> => service.financeReview(null, signal).catch((caught: unknown) => {
    // Somebody without review access (an operator, a finance viewer): the section is not shown.
    if (toServiceError(caught).details?.backendCode === "CONFIRMARE_HOLD_DENIED") return null;
    throw caught;
  }), [service]);
  const view = useAutoRefresh(load, "confirmare-finance-holds");
  const [result, setResult] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const failure = (caught: unknown): string => {
    const failed = toServiceError(caught);
    const known = opsErrorKey(typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined, undefined);
    return known ? t(known) : getErrorPresentation(failed).message;
  };
  const data = view.data;
  if (!data) return null;
  const open = data.holds.filter((hold) => hold.open);
  const released = data.holds.filter((hold) => !hold.open);

  return (
    <section className="detail-section" aria-labelledby="cf-holds" data-testid="cf-holds">
      <div className="section-title"><h2 id="cf-holds">{t("cf.hold.title")}</h2></div>
      <p className="dashboard-lead">{t("cf.hold.lead")}</p>
      {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} entering>{result.text}</Notice>}
      {data.canPlace.length > 0 && <PlaceHold sources={data.canPlace} service={service} onDone={(hold) => { setResult({ tone: "success", text: t("cf.hold.placed", { number: hold.orderNumber }) }); view.refresh(); }}
        onError={(caught) => setResult({ tone: "danger", text: failure(caught) })} />}
      <h3>{t("cf.hold.open")}</h3>
      {open.length === 0 ? <p className="dashboard-footnote">{t("cf.hold.none")}</p>
        : <ul className="queue-list">{open.map((hold) => <HoldRow key={hold.id} hold={hold} canRelease={data.canRelease.includes(hold.source)} service={service}
          onDone={(after) => { setResult({ tone: "success", text: t("cf.hold.released", { number: after.orderNumber }) }); view.refresh(); }} onError={(caught) => setResult({ tone: "danger", text: failure(caught) })} />)}</ul>}
      {released.length > 0 && <ul className="queue-list">{released.map((hold) => <HoldRow key={hold.id} hold={hold} canRelease={false} service={service} onDone={() => undefined} onError={() => undefined} />)}</ul>}
      <h3>{t("cf.hold.stale.title", { hours: data.reviewTaskHours })}</h3>
      {data.staleTasks.length === 0 ? <p className="dashboard-footnote">{t("cf.hold.stale.none")}</p>
        : <ul className="queue-list">{data.staleTasks.map((task) => <li key={task.id}>
          <div className="queue-row cf-row tone-warning" data-testid={`cf-hold-stale-${task.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top"><strong className="order-id">#{task.orderNumber}</strong><span className="cf-source">{sourceLabel(task.source)}</span></span>
              <small>{t("cf.hold.stale.item", { state: task.state, name: task.assignee ?? "—", hours: task.ageHours })}</small>
            </span>
          </div>
        </li>)}</ul>}
      <h3>{t("cf.hold.expired.title")}</h3>
      {data.expiredProformas.length === 0 ? <p className="dashboard-footnote">{t("cf.hold.expired.none")}</p>
        : <ul className="queue-list">{data.expiredProformas.map((item) => <li key={item.globalOrderId}>
          <div className={`queue-row cf-row tone-${item.waitsFor ? "neutral" : "warning"}`} data-testid={`cf-hold-expired-${item.orderNumber}`}>
            <span className="queue-row-main">
              <span className="queue-row-top"><strong className="order-id">#{item.orderNumber}</strong><span className="cf-source">{sourceLabel(item.source)}</span></span>
              <small>{t("cf.hold.expired.item", { hours: item.overdueHours })} · {t(`cf.cycle.state.${item.state}` as MessageKey)}</small>
            </span>
            <StatusPill tone={item.waitsFor ? "neutral" : "warning"}>{item.waitsFor ? t(`cf.waits.${item.waitsFor}` as MessageKey) : t("cf.hold.expired.next")}</StatusPill>
          </div>
        </li>)}</ul>}
    </section>
  );
}

function PlaceHold({ sources, service, onDone, onError }: { sources: string[]; service: ConfirmareApi; onDone: (hold: FinanceHold) => void; onError: (caught: unknown) => void }) {
  const { t } = useI18n();
  const [source, setSource] = useState(sources[0] ?? "trendhome");
  const [orderNumber, setOrderNumber] = useState("");
  const [reason, setReason] = useState<HoldReason>("early_transfer");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const key = useRef<{ body: string; key: string } | null>(null);
  const ready = /^#?[A-Za-z0-9._-]{1,64}$/.test(orderNumber.trim()) && note.trim().length >= 5 && note.trim().length <= 500;
  async function submit() {
    const input = { source, orderNumber: orderNumber.trim().replace(/^#/, ""), reasonCode: reason, note: note.trim() };
    const body = JSON.stringify(input);
    if (key.current?.body !== body) key.current = { body, key: createIdempotencyKey() };
    setBusy(true);
    try {
      const hold = await service.placeHold(input, key.current.key);
      key.current = null;
      setOrderNumber(""); setNote("");
      onDone(hold);
    } catch (caught) {
      onError(caught);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="cf-finance-form" onSubmit={(event) => { event.preventDefault(); if (ready) void submit(); }} data-testid="cf-hold-form">
      <h3>{t("cf.hold.place")}</h3>
      {sources.length > 1 && <label>{t("cf.hold.source")}
        <select value={source} onChange={(event) => setSource(event.target.value)} data-testid="cf-hold-source">{sources.map((code) => <option key={code} value={code}>{sourceLabel(code)}</option>)}</select>
      </label>}
      <label>{t("cf.hold.orderNumber")}<input autoComplete="off" maxLength={65} value={orderNumber} onChange={(event) => setOrderNumber(event.target.value)} data-testid="cf-hold-order" /></label>
      <label>{t("cf.hold.reasonLabel")}
        <select value={reason} onChange={(event) => setReason(event.target.value as HoldReason)} data-testid="cf-hold-reason">
          {HOLD_REASONS.map((code) => <option key={code} value={code}>{t(`cf.hold.reason.${code}` as MessageKey)}</option>)}
        </select>
      </label>
      <label>{t("cf.hold.note")}<textarea maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} data-testid="cf-hold-note" /></label>
      <button type="submit" className="button button-primary" disabled={busy || !ready} data-testid="cf-hold-submit">{busy ? t("common.sending") : t("cf.hold.submit")}</button>
    </form>
  );
}

function HoldRow({ hold, canRelease, service, onDone, onError }: { hold: FinanceHold; canRelease: boolean; service: ConfirmareApi; onDone: (hold: FinanceHold) => void; onError: (caught: unknown) => void }) {
  const { t } = useI18n();
  const [releasing, setReleasing] = useState(false);
  const [reason, setReason] = useState<HoldReleaseReason>("payment_recorded");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const key = useRef<{ body: string; key: string } | null>(null);
  async function release() {
    const input = { reasonCode: reason, note: note.trim() };
    const body = JSON.stringify(input);
    if (key.current?.body !== body) key.current = { body, key: createIdempotencyKey() };
    setBusy(true);
    try {
      const after = await service.releaseHold(hold.id, input, key.current.key);
      key.current = null;
      setReleasing(false); setNote("");
      onDone(after);
    } catch (caught) {
      onError(caught);
    } finally {
      setBusy(false);
    }
  }
  return (
    <li>
      <div className={`queue-row cf-row tone-${hold.open ? "warning" : "neutral"}`} data-testid={`cf-hold-${hold.orderNumber}`}>
        <span className="queue-row-main">
          <span className="queue-row-top"><strong className="order-id">#{hold.orderNumber}</strong><span className="cf-source">{sourceLabel(hold.source)}</span></span>
          <small>{t(`cf.hold.reason.${hold.reasonCode}` as MessageKey)} · {t("cf.hold.by", { name: hold.placedBy ?? "—", time: when(hold.placedAt) })}</small>
          <small>{hold.note}</small>
          {!hold.open && <small>{t("cf.hold.releasedLine", { name: hold.releasedBy ?? "—", time: when(hold.releasedAt) })}{hold.releaseReasonCode ? ` · ${t(`cf.hold.releaseReason.${hold.releaseReasonCode}` as MessageKey)}` : ""}</small>}
        </span>
        {canRelease && !releasing && <button type="button" className="button button-secondary" onClick={() => setReleasing(true)} data-testid={`cf-hold-release-${hold.orderNumber}`}>{t("cf.hold.release")}</button>}
      </div>
      {releasing && <form className="cf-finance-form" onSubmit={(event) => { event.preventDefault(); if (note.trim().length >= 5) void release(); }} data-testid={`cf-hold-release-form-${hold.orderNumber}`}>
        <label>{t("cf.hold.reasonLabel")}
          <select value={reason} onChange={(event) => setReason(event.target.value as HoldReleaseReason)} data-testid="cf-hold-release-reason">
            {HOLD_RELEASE_REASONS.map((code) => <option key={code} value={code}>{t(`cf.hold.releaseReason.${code}` as MessageKey)}</option>)}
          </select>
        </label>
        <label>{t("cf.hold.note")}<textarea maxLength={500} value={note} onChange={(event) => setNote(event.target.value)} data-testid="cf-hold-release-note" /></label>
        <span className="cf-confirm-actions">
          <button type="submit" className="button button-primary" disabled={busy || note.trim().length < 5} data-testid="cf-hold-release-confirm">{busy ? t("common.sending") : t("cf.hold.releaseConfirm")}</button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => setReleasing(false)}>{t("common.cancel")}</button>
        </span>
      </form>}
    </li>
  );
}
