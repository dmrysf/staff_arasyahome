import { useEffect, useRef, useState } from "react";
import type { FaultException, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { useLive } from "../../app/liveContext";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorState } from "../../components/ErrorState";
import { SourceBadge } from "../../components/SourceBadge";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { arrivalLabel, faultStatusLabels, formatDecimalMeters } from "../../domain/faults";
import { QrCapture } from "./QrCapture";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" });
const ACK_TEXT = "Confirm că eroarea îmi aparține și refac lucrarea.";

/** One cutting fault request for the responsible employee or the detector, updated live. */
export function ExceptionScreen({ exceptionId, service, navigate, onSessionExpired }: { exceptionId: string; service: ExceptionService; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const { revision } = useLive();
  const [fault, setFault] = useState<FaultException | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reload, setReload] = useState(0);
  const [confirmed, setConfirmed] = useState(false);
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [actionError, setActionError] = useState<StaffServiceError | null>(null);
  const keyRef = useRef<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    service.get(exceptionId, { signal: controller.signal })
      .then((loaded) => { setError(null); setFault(loaded); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  // Every live event re-reads the request (one small GET), so the screen never needs a manual refresh.
  }, [exceptionId, service, revision, reload]);

  async function run(action: (key: string) => Promise<FaultException>) {
    if (submitting) return;
    keyRef.current ??= createIdempotencyKey();
    setSubmitting(true);
    setActionError(null);
    try {
      const updated = await action(keyRef.current);
      keyRef.current = null;
      setFault(updated);
      setComment("");
      setConfirmed(false);
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      // A transient failure keeps the key so a retry replays the same intent; anything else starts over.
      if (!["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(failure.code)) keyRef.current = null;
      setActionError(failure);
    } finally { setSubmitting(false); }
  }

  if (error) return <ErrorState error={error} onAction={() => { setError(null); if (error.code === "EXCEPTION_NOT_FOUND") navigate("/"); else setReload((value) => value + 1); }} />;
  if (!fault) return <div className="inline-loading"><span /> Se încarcă cererea…</div>;
  const arrival = arrivalLabel(fault.arrivalNumber);
  return (
    <article className="screen-stack detail-screen exception-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <section className="detail-hero"><div><SourceBadge source={fault.order.source} /><p className="eyebrow">Returnare la tăiere · {fault.number}</p><h1>Comanda<br />#{fault.order.orderNumber}</h1></div>
        <span className={`exception-status status-${fault.status}`} role="status">{faultStatusLabels[fault.status]}</span></section>
      {fault.repeatedError && <p className="order-notice order-notice-warning" role="note">Eroare repetată: comanda a mai fost refăcută.</p>}
      {fault.status === "approved" && fault.role === "responsible" && <p className="order-action-done" role="status">✓ Aprobarea a fost acordată. Lucrarea poate fi refăcută.</p>}
      <section className="detail-section">
        <p className="eyebrow">Produse cu eroare</p>
        <h2>{fault.lineCount === 1 ? "1 produs" : `${fault.lineCount} produse`} · {formatDecimalMeters(fault.faultMeters)}</h2>
        <ul className="fault-lines">{fault.lines?.map((line) => <li key={line.itemId}><span><strong>{line.name}</strong><small>{[line.code, line.color, line.variant].filter(Boolean).join(" · ")}</small></span><b>{formatDecimalMeters(line.meters)}</b></li>)}</ul>
        <dl className="fault-facts">
          <div><dt>Motiv</dt><dd>{fault.reason.label}</dd></div>
          {fault.detectorComment && <div><dt>Comentariu Primire Croitorie</dt><dd>{fault.detectorComment}</dd></div>}
          <div><dt>Detectat de</dt><dd>{fault.detector.displayName}</dd></div>
          <div><dt>Tăiat de</dt><dd>{fault.responsible.displayName}</dd></div>
          <div><dt>Raportat</dt><dd><time dateTime={fault.reportedAt}>{time.format(new Date(fault.reportedAt))}</time></dd></div>
          {arrival && <div><dt>Sosire</dt><dd>{arrival}</dd></div>}
        </dl>
      </section>

      {fault.actions.canAcknowledge && <section className="detail-section fault-action" aria-labelledby="ack-title">
        <h2 id="ack-title">Confirmă eroarea</h2>
        <p>Verifică produsele de mai sus. Metrajul ales la Primire Croitorie nu poate fi micșorat.</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /> <span>{ACK_TEXT}</span></label>
        <label className="field"><span>Comentariu (opțional)</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => setComment(event.target.value)} /></label>
        <p className="eyebrow">Pasul 2 · Scanează eticheta comenzii returnate</p>
        <QrCapture disabled={!confirmed || submitting} onToken={(token) => { if (confirmed) void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, idempotencyKey: key })); }} />
        {!confirmed && <small className="server-note">Bifează confirmarea înainte de scanare.</small>}
      </section>}

      {fault.status === "awaiting_approval" && <p className="order-notice" role="status">Cererea așteaptă aprobarea managerului operațional. Lucrarea nu începe până la aprobare.</p>}

      {fault.actions.canRequestRereview && <section className="detail-section fault-action" aria-labelledby="rereview-title">
        <h2 id="rereview-title">Solicită reanalizarea</h2>
        <p>Dacă decizia pare greșită, cere o nouă analiză. Respingerea rămâne în istoric.</p>
        <label className="field"><span>Motivul reanalizării (obligatoriu)</span><textarea maxLength={1000} rows={3} value={comment} onChange={(event) => setComment(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || comment.trim().length < 3} onClick={() => void run((key) => service.rereview(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{submitting ? "Se trimite…" : "Solicită reanalizarea"}</button>
      </section>}

      {actionError && <ErrorState error={actionError} compact onAction={() => { setActionError(null); setReload((value) => value + 1); }} />}
      {submitting && <p className="server-note" role="status">Așteptăm confirmarea serverului.</p>}

      {fault.decisions && fault.decisions.length > 0 && <section className="detail-section"><p className="eyebrow">Decizii</p>
        <ol className="decision-list">{fault.decisions.map((decision) => <li key={decision.attempt}>
          <strong>Încercarea {decision.attempt} · {decision.status === "pending" ? "în așteptare" : decision.status === "approved" ? "aprobată" : decision.status === "rejected" ? "respinsă" : "anulată"}</strong>
          {decision.openedReason === "rereview" && <small>Reanalizare cerută de {decision.openedBy}{decision.openedComment ? `: „${decision.openedComment}”` : ""}</small>}
          {decision.decidedBy && <small>{decision.decidedBy}{decision.comment ? `: „${decision.comment}”` : ""}</small>}
        </li>)}</ol>
      </section>}
      <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}`)}>Vezi comanda</button>
    </article>
  );
}
