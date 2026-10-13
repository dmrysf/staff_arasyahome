import { useEffect, useId, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { CuttingApi, CuttingPool, CuttingTransfer } from "../../domain/cutting";
import { transferReasonLabel, transferStatus } from "../../domain/cutting";
import type { StaffOrder } from "../../domain/models";
import { useLive } from "../../app/liveContext";
import { createIdempotencyKey } from "../../services/idempotency";
import { toServiceError } from "../../services/errors";
import { ErrorState } from "../../components/ErrorState";
import { QrCapture } from "../exceptions/QrCapture";

/** Only server-confirmed state, re-read on live changes and after each committed command. */
export function CuttingWork({ service, navigate }: { service: CuttingApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const revision = useLive().revision;
  const [pool, setPool] = useState<CuttingPool | null>(null);
  const [transfers, setTransfers] = useState<CuttingTransfer[]>([]);
  const [error, setError] = useState<ReturnType<typeof toServiceError> | null>(null);
  const [reload, setReload] = useState(0);
  useEffect(() => {
    const abort = new AbortController();
    Promise.all([service.pool(abort.signal), service.transfers(abort.signal)]).then(([p, t]) => { if (!abort.signal.aborted) { setPool(p); setTransfers(t.items); setError(null); } }, (e) => { if (!abort.signal.aborted) setError(toServiceError(e)); });
    return () => abort.abort();
  }, [service, revision, reload]);
  return <section className="cutting-work screen-stack">
    <div className="detail-section"><p className="eyebrow">{t("cutting.eyebrow")}</p><h2>{t("cutting.available")} {pool && `· ${pool.total}`}</h2>
      <p>{t("cutting.orderNote")}</p>
      {error && <ErrorState error={error} compact onAction={() => setReload(v => v + 1)} />}
      {pool && <><p>{t("cutting.owned", { count: pool.ownedCount })}</p><ul className="cutting-pool-list">{pool.items.slice(0, 20).map(o => <li key={o.id}><button type="button" onClick={() => navigate(`/orders/${encodeURIComponent(o.id)}`)}><strong>#{o.orderNumber}</strong><small>{o.source}</small></button></li>)}</ul>{pool.total > 20 && <p>{t("cutting.more", { count: pool.total - 20 })}</p>}</>}
    </div>
    <div className="detail-section"><h2>{t("cutting.myTransfers")}</h2>{transfers.length === 0 && <p>{t("cutting.noTransfers")}</p>}
      {transfers.map(transfer => <TransferCard key={transfer.id} transfer={transfer} service={service} onChanged={() => setReload(v => v + 1)} />)}
    </div>
  </section>;
}

function TransferCard({ transfer: tr, service, onChanged }: { transfer: CuttingTransfer; service: CuttingApi; onChanged: () => void }) {
  const { t } = useI18n();
  const [qr, setQr] = useState("");
  const [count, setCount] = useState<number | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ReturnType<typeof toServiceError> | null>(null);
  const key = useRef("");
  useEffect(() => {
    if (!tr.actions.canVerify) return;
    const abort = new AbortController();
    service.pool(abort.signal).then(p => { if (!abort.signal.aborted) setCount(p.ownedCount); }, e => { if (!abort.signal.aborted) setError(toServiceError(e)); });
    return () => abort.abort();
  }, [service, tr.actions.canVerify, tr.version]);
  async function submit(action: "accept" | "verify") {
    if (busy) return;
    setBusy(true); setError(null); key.current ||= createIdempotencyKey();
    try {
      await service.change(tr.id, action, { expectedVersion: tr.version, ...(action === "accept" ? { confirmed: true } : { qrToken: qr, confirmedMultiple: confirmed, ownedCount: count ?? 0 }) }, key.current);
      key.current = ""; setQr(""); setConfirmed(false); onChanged();
    } catch (e) {
      const failure = toServiceError(e); setError(failure);
      if (!["NETWORK_UNAVAILABLE","REQUEST_TIMEOUT","SERVER_ERROR","SERVICE_UNAVAILABLE"].includes(failure.code)) { key.current = ""; onChanged(); }
    } finally { setBusy(false); }
  }
  return <article className="cutting-transfer-card"><h3>{t("common.order", { number: tr.order.orderNumber })}</h3><p><strong>{tr.from.name} → {tr.to.name}</strong></p><p>{transferStatus[tr.status]} · {transferReasonLabel(tr.reason.key, tr.reason.label)}</p>
    {tr.reason.comment && <p>{tr.reason.comment}</p>}{tr.decisionComment && <p>{t("cutting.decisionReason", { text: tr.decisionComment })}</p>}
    {tr.decidedBy && <p>{t("cutting.decision", { name: tr.decidedBy })}</p>}
    {error && <ErrorState error={error} compact onAction={() => void submit(tr.actions.canVerify ? "verify" : "accept")} />}
    {tr.actions.canAccept && <><p>{t("cutting.acceptNote")}</p><button type="button" className="button button-primary" disabled={busy} onClick={() => void submit("accept")}>{t("cutting.accept")}</button></>}
    {tr.actions.canVerify && <><QrCapture disabled={busy} onToken={value => { setQr(value); key.current = ""; }} />
      {qr && <p role="status">{t("cutting.captured")}</p>}
      {count !== null && count > 0 && <label className="order-notice order-notice-warning"><input type="checkbox" checked={confirmed} onChange={e => { setConfirmed(e.target.checked); key.current = ""; }} /> {t("cutting.ownedConfirm", { count })}</label>}
      <button type="button" className="button button-primary" disabled={busy || !qr || count === null || (count > 0 && !confirmed)} onClick={() => void submit("verify")}>{busy ? t("cutting.verifying") : t("cutting.verify")}</button></>}
  </article>;
}

export function CuttingTransferRequest({ order, service, onChanged }: { order: StaffOrder; service: CuttingApi; onChanged: () => void }) {
  const { t } = useI18n();
  const fieldId = useId();
  const [targets, setTargets] = useState<{ id: string; name: string }[]>([]);
  const [target, setTarget] = useState(""); const [reason, setReason] = useState("illness"); const [comment, setComment] = useState("");
  const [busy, setBusy] = useState(false); const [open, setOpen] = useState(false);
  const [error, setError] = useState<ReturnType<typeof toServiceError> | null>(null);
  const key = useRef("");
  useEffect(() => {
    if (!open) return;
    const abort = new AbortController();
    service.targets(abort.signal).then(r => setTargets(r.items), e => { if (!abort.signal.aborted) setError(toServiceError(e)); });
    return () => abort.abort();
  }, [open, service]);
  async function submit() {
    if (busy) return; setBusy(true); setError(null); key.current ||= createIdempotencyKey();
    try { await service.request(order.id, { expectedVersion: order.productionVersion, targetId: target, reasonKey: reason, ...(comment.trim() ? { comment: comment.trim() } : {}) }, key.current); key.current = ""; setOpen(false); onChanged(); }
    catch(e) { const failure = toServiceError(e); setError(failure); if (!["NETWORK_UNAVAILABLE","REQUEST_TIMEOUT","SERVER_ERROR","SERVICE_UNAVAILABLE"].includes(failure.code)) key.current = ""; }
    finally { setBusy(false); }
  }
  return <section className="detail-section"><h2>{t("cutting.transferTitle")}</h2><p>{t("cutting.transferNote")}</p>
    {!open ? <button type="button" className="button button-secondary" onClick={() => setOpen(true)}>{t("cutting.requestTransfer")}</button> : <form onSubmit={e => { e.preventDefault(); void submit(); }}>
      <label className="field"><span id={`${fieldId}-target`}>{t("cutting.colleague")}</span><select aria-labelledby={`${fieldId}-target`} value={target} onChange={e => { setTarget(e.target.value); key.current = ""; }} required><option value="">{t("cutting.chooseColleague")}</option>{targets.map(candidate => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}</select></label>
      <label className="field"><span id={`${fieldId}-reason`}>{t("common.reason")}</span><select aria-labelledby={`${fieldId}-reason`} value={reason} onChange={e => { setReason(e.target.value); key.current = ""; }}><option value="illness">{t("transferReason.illness")}</option><option value="unavailable">{t("transferReason.unavailable")}</option><option value="other">{t("transferReason.other")}</option></select></label>
      <label className="field"><span>{reason === "other" ? t("common.commentRequired") : t("common.commentOptional")}</span><textarea maxLength={1000} minLength={reason === "other" ? 3 : undefined} required={reason === "other"} value={comment} onChange={e => { setComment(e.target.value); key.current = ""; }} /></label>
      {error && <ErrorState error={error} compact onAction={() => void submit()} />}<button type="submit" className="button button-primary" disabled={busy || !target}>{busy ? t("common.sending") : t("cutting.send")}</button>
    </form>}
  </section>;
}
