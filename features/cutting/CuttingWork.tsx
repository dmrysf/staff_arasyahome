import { useEffect, useId, useRef, useState } from "react";
import type { CuttingApi, CuttingPool, CuttingTransfer } from "../../domain/cutting";
import { transferStatus } from "../../domain/cutting";
import type { StaffOrder } from "../../domain/models";
import { useLive } from "../../app/liveContext";
import { createIdempotencyKey } from "../../services/idempotency";
import { toServiceError } from "../../services/errors";
import { ErrorState } from "../../components/ErrorState";
import { QrCapture } from "../exceptions/QrCapture";

/** Only server-confirmed state, re-read on live changes and after each committed command. */
export function CuttingWork({ service, navigate }: { service: CuttingApi; navigate: (path: string) => void }) {
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
    <div className="detail-section"><p className="eyebrow">Tăiere · întreaga comandă</p><h2>Comenzi disponibile {pool && `· ${pool.total}`}</h2>
      <p>Ordinea afișată nu impune prioritate. Scanează eticheta QR originală pentru a prelua.</p>
      <button type="button" className="button button-primary" onClick={() => navigate("/scan")}>Scanează pentru preluare</button>
      {error && <ErrorState error={error} compact onAction={() => setReload(v => v + 1)} />}
      {pool && <><p>Ai {pool.ownedCount} comenzi neterminate · rămân la tine și după deconectare.</p><ul className="cutting-pool-list">{pool.items.slice(0, 20).map(o => <li key={o.id}><button type="button" onClick={() => navigate(`/orders/${encodeURIComponent(o.id)}`)}><strong>#{o.orderNumber}</strong><small>{o.source}</small></button></li>)}</ul>{pool.total > 20 && <p>+ {pool.total - 20} comenzi în așteptare · disponibile prin scanare</p>}</>}
    </div>
    <div className="detail-section"><h2>Transferurile mele</h2>{transfers.length === 0 && <p>Nu ai cereri de transfer.</p>}
      {transfers.map(t => <TransferCard key={t.id} transfer={t} service={service} onChanged={() => setReload(v => v + 1)} />)}
    </div>
  </section>;
}

function TransferCard({ transfer: t, service, onChanged }: { transfer: CuttingTransfer; service: CuttingApi; onChanged: () => void }) {
  const [qr, setQr] = useState("");
  const [count, setCount] = useState<number | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ReturnType<typeof toServiceError> | null>(null);
  const key = useRef("");
  useEffect(() => {
    if (!t.actions.canVerify) return;
    const abort = new AbortController();
    service.pool(abort.signal).then(p => { if (!abort.signal.aborted) setCount(p.ownedCount); }, e => { if (!abort.signal.aborted) setError(toServiceError(e)); });
    return () => abort.abort();
  }, [service, t.actions.canVerify, t.version]);
  async function submit(action: "accept" | "verify") {
    if (busy) return;
    setBusy(true); setError(null); key.current ||= createIdempotencyKey();
    try {
      await service.change(t.id, action, { expectedVersion: t.version, ...(action === "accept" ? { confirmed: true } : { qrToken: qr, confirmedMultiple: confirmed, ownedCount: count ?? 0 }) }, key.current);
      key.current = ""; setQr(""); setConfirmed(false); onChanged();
    } catch (e) {
      const failure = toServiceError(e); setError(failure);
      if (!["NETWORK_UNAVAILABLE","REQUEST_TIMEOUT","SERVER_ERROR","SERVICE_UNAVAILABLE"].includes(failure.code)) { key.current = ""; onChanged(); }
    } finally { setBusy(false); }
  }
  return <article className="cutting-transfer-card"><h3>Comanda #{t.order.orderNumber}</h3><p><strong>{t.from.name} → {t.to.name}</strong></p><p>{transferStatus[t.status]} · {t.reason.label}</p>
    {t.reason.comment && <p>{t.reason.comment}</p>}{t.decisionComment && <p>Motiv decizie: {t.decisionComment}</p>}
    {t.decidedBy && <p>Decizie: {t.decidedBy}</p>}
    {error && <ErrorState error={error} compact onAction={() => void submit(t.actions.canVerify ? "verify" : "accept")} />}
    {t.actions.canAccept && <><p>Acceptarea nu schimbă încă proprietarul. Apoi trebuie scanată aceeași etichetă QR.</p><button type="button" className="button button-primary" disabled={busy} onClick={() => void submit("accept")}>Accept explicit transferul</button></>}
    {t.actions.canVerify && <><QrCapture disabled={busy} onToken={value => { setQr(value); key.current = ""; }} />
      {qr && <p role="status">Etichetă capturată · verificare pe server.</p>}
      {count !== null && count > 0 && <label className="order-notice order-notice-warning"><input type="checkbox" checked={confirmed} onChange={e => { setConfirmed(e.target.checked); key.current = ""; }} /> Ai deja {count} comenzi în lucru. Confirm că trebuie să finalizez sau să transfer toate comenzile active.</label>}
      <button type="button" className="button button-primary" disabled={busy || !qr || count === null || (count > 0 && !confirmed)} onClick={() => void submit("verify")}>{busy ? "Se verifică…" : "Verifică QR și preia comanda"}</button></>}
  </article>;
}

export function CuttingTransferRequest({ order, service, onChanged }: { order: StaffOrder; service: CuttingApi; onChanged: () => void }) {
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
  return <section className="detail-section"><h2>Transfer de tăiere</h2><p>Rămâi proprietar până când colegul acceptă și scanează aceeași etichetă, după aprobarea managerului.</p>
    {!open ? <button type="button" className="button button-secondary" onClick={() => setOpen(true)}>Solicită transferul întregii comenzi</button> : <form onSubmit={e => { e.preventDefault(); void submit(); }}>
      <label className="field"><span id={`${fieldId}-target`}>Coleg din același departament</span><select aria-labelledby={`${fieldId}-target`} value={target} onChange={e => { setTarget(e.target.value); key.current = ""; }} required><option value="">Alege colegul</option>{targets.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
      <label className="field"><span id={`${fieldId}-reason`}>Motiv</span><select aria-labelledby={`${fieldId}-reason`} value={reason} onChange={e => { setReason(e.target.value); key.current = ""; }}><option value="illness">Boală</option><option value="unavailable">Indisponibilitate / nu poate continua</option><option value="other">Alt motiv</option></select></label>
      <label className="field"><span>Comentariu {reason === "other" ? "(obligatoriu)" : "(opțional)"}</span><textarea maxLength={1000} minLength={reason === "other" ? 3 : undefined} required={reason === "other"} value={comment} onChange={e => { setComment(e.target.value); key.current = ""; }} /></label>
      {error && <ErrorState error={error} compact onAction={() => void submit()} />}<button type="submit" className="button button-primary" disabled={busy || !target}>{busy ? "Se trimite…" : "Confirm și trimit cererea"}</button>
    </form>}
  </section>;
}
