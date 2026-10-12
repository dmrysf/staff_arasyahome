import { useEffect, useRef, useState } from "react";
import type { FaultException, FaultReason, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { useLive } from "../../app/liveContext";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorState } from "../../components/ErrorState";
import { LoadingState } from "../../components/ui/States";
import { SourceBadge } from "../../components/SourceBadge";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { arrivalLabel, faultStatusLabel, formatDecimalMeters, formatFaultMeters, responsibilityLabels, timelineLabel } from "../../domain/faults";
import { QrCapture } from "./QrCapture";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" });
const ACK_TEXT = "Confirm că eroarea îmi aparține și refac lucrarea.";
const RECEIPT_TEXT = "Am primit produsul pentru verificare.";
const RESTORE_RECEIPT_TEXT = "Am primit produsul în mână și continui lucrul.";
const RETRY_CODES = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];

/**
 * One production exception, updated live. The original cutting fault (detector and responsible cutter) and
 * the supervised tailoring defect (worker, tailoring quality supervisor, cutter, manager). Every action is
 * shown only when the server granted it, and every result is the server's committed state.
 */
export function ExceptionScreen({ exceptionId, service, navigate, onSessionExpired }: { exceptionId: string; service: ExceptionService; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const { revision } = useLive();
  const [fault, setFault] = useState<FaultException | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reload, setReload] = useState(0);
  const [confirmed, setConfirmed] = useState(false);
  const [comment, setComment] = useState("");
  const [position, setPosition] = useState<"accepted" | "disputed" | null>(null);
  const [reasons, setReasons] = useState<FaultReason[]>([]);
  const [reasonKey, setReasonKey] = useState("");
  const [receivers, setReceivers] = useState<{ id: string; displayName: string }[] | null>(null);
  const [receiverId, setReceiverId] = useState("");
  const [recipients, setRecipients] = useState<{ id: string; displayName: string }[] | null>(null);
  const [recipientId, setRecipientId] = useState("");
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

  const reviewing = fault?.actions.canReview ?? false;
  useEffect(() => {
    if (!reviewing) return undefined;
    const controller = new AbortController();
    service.reasons({ signal: controller.signal }).then(setReasons).catch(() => undefined);
    return () => controller.abort();
  }, [reviewing, service]);

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
      setPosition(null);
      setReceivers(null);
      setReceiverId("");
      setRecipients(null);
      setRecipientId("");
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      // A transient failure keeps the key so a retry replays the same intent; anything else starts over.
      if (!RETRY_CODES.includes(failure.code)) keyRef.current = null;
      if (failure.code === "RESPONSIBLE_UNAVAILABLE" && fault) service.receivers(fault.id).then(setReceivers).catch(() => setReceivers([]));
      if (failure.code === "RESTORE_RECIPIENT_UNAVAILABLE" && fault?.actions.canRestore) service.restoreRecipients(fault.id).then(setRecipients).catch(() => setRecipients([]));
      setActionError(failure);
    } finally { setSubmitting(false); }
  }
  /** A new intent (other text, other choice) never reuses the key of a different one. */
  const edit = <T,>(setter: (value: T) => void) => (value: T) => { keyRef.current = null; setter(value); };

  if (error) return <ErrorState error={error} onAction={() => { setError(null); if (error.code === "EXCEPTION_NOT_FOUND") navigate("/"); else setReload((value) => value + 1); }} />;
  if (!fault) return <LoadingState label="Se încarcă cererea…" shape="detail" />;
  const arrival = arrivalLabel(fault.arrivalNumber);
  const tailoring = fault.type === "tailoring_defect";
  const actions = fault.actions;
  const commentReady = comment.trim().length >= 3;
  const reason = reasons.find((item) => item.key === reasonKey);
  return (
    <article className="screen-stack detail-screen exception-screen" data-testid="exception-screen">
      <button className="back-link" type="button" onClick={() => navigate(fault.role === "supervisor" ? "/problems" : "/")}><AppIcon name="back" size={20} /> {fault.role === "supervisor" ? "Comenzi cu probleme" : "Acasă"}</button>
      <section className="detail-hero"><div><SourceBadge source={fault.order.source} /><p className="eyebrow">{tailoring ? "Problemă de producție" : "Returnare la tăiere"} · {fault.number}</p><h1>Comanda <span className="order-id">#{fault.order.orderNumber}</span></h1></div>
        <span className={`exception-status status-${fault.status}`} role="status" data-testid="exception-status">{faultStatusLabel(fault)}</span></section>
      {tailoring && fault.role === "detector" && fault.status === "awaiting_supervisor_review" && <p className="order-action-done" role="status">✓ Problema a fost raportată șefului de croitorie.</p>}
      {fault.repeatedError && <p className="order-notice order-notice-warning" role="note">Eroare repetată: comanda a mai fost refăcută.</p>}
      {fault.status === "approved" && (fault.role === "responsible" || fault.role === "receiver") && <p className="order-action-done" role="status">✓ Aprobarea a fost acordată. Lucrarea poate fi refăcută.</p>}
      {tailoring && fault.status === "return_rejected" && <p className="order-notice order-notice-warning" role="note">Managerul a respins returnarea la tăiere. Comanda rămâne blocată în grija șefului de croitorie până când produsul revine și este readus în producție sau se cere o nouă decizie.</p>}
      {fault.status === "awaiting_restore_receipt" && !actions.canConfirmRestore && <p className="order-notice" role="status" data-testid="restore-pending">Produsul este predat înapoi către {fault.restoreRecipient?.displayName ?? "angajatul de croitorie"}. Comanda rămâne blocată până când acesta scanează eticheta și confirmă primirea.</p>}

      <section className="detail-section">
        <p className="eyebrow">{tailoring ? "Produse afectate" : "Produse cu eroare"}</p>
        <h2>{fault.lineCount === 1 ? "1 produs" : `${fault.lineCount} produse`} · {formatFaultMeters(fault)}</h2>
        <ul className="fault-lines">{fault.lines?.map((line) => <li key={line.itemId}><span><strong>{line.name}</strong><small>{[line.code, line.color, line.variant].filter(Boolean).join(" · ")}</small></span><b>{formatDecimalMeters(line.meters)}</b></li>)}</ul>
        <dl className="fault-facts">
          <div><dt>{tailoring ? "Problema raportată" : "Motiv"}</dt><dd>{fault.reason.label}</dd></div>
          {fault.confirmedReason && <div><dt>Confirmat de șeful de croitorie</dt><dd>{fault.confirmedReason.label}</dd></div>}
          {fault.detectorComment && <div><dt>{tailoring ? "Descriere" : "Comentariu Primire Croitorie"}</dt><dd>{fault.detectorComment}</dd></div>}
          {tailoring && <div><dt>Etapa în care a fost găsită</dt><dd>{fault.detectedStage.label ?? fault.detectedStage.id}</dd></div>}
          {tailoring && fault.currentStage && <div><dt>Etapa curentă</dt><dd>{fault.currentStage.label ?? fault.currentStage.id}</dd></div>}
          <div><dt>Detectat de</dt><dd>{fault.detector.displayName}</dd></div>
          {fault.supervisor && <div><dt>Șef de croitorie</dt><dd>{fault.supervisor.displayName}</dd></div>}
          <div><dt>Tăiat de</dt><dd>{fault.responsible.displayName}</dd></div>
          {fault.cuttingHandoff && <div><dt>Predare de la tăiere</dt><dd><time dateTime={fault.cuttingHandoff.at}>{time.format(new Date(fault.cuttingHandoff.at))}</time></dd></div>}
          {fault.receiver && <div><dt>Trimis la tăiere către</dt><dd>{fault.receiver.displayName}</dd></div>}
          {tailoring && fault.physicalCustodian && <div><dt>Produsul se află la</dt><dd>{fault.physicalCustodian.displayName}</dd></div>}
          {fault.restoreRecipient && <div><dt>{fault.restoreReceivedAt ? "Primit înapoi de" : "Predat înapoi către"}</dt><dd>{fault.restoreRecipient.displayName}</dd></div>}
          {fault.responsibilityPosition && <div><dt>Responsabilitate</dt><dd>{responsibilityLabels[fault.responsibilityPosition]}</dd></div>}
          {fault.attribution && <div><dt>Decizia managerului</dt><dd>{fault.attribution === "cutter" ? "Eroare de tăiere confirmată" : "Fără vină atribuită tăietorului"}</dd></div>}
          {fault.supervisorComment && <div><dt>Comentariul șefului de croitorie</dt><dd>{fault.supervisorComment}</dd></div>}
          <div><dt>Raportat</dt><dd><time dateTime={fault.reportedAt}>{time.format(new Date(fault.reportedAt))}</time></dd></div>
          {arrival && <div><dt>Sosire</dt><dd>{arrival}</dd></div>}
        </dl>
      </section>

      {actions.canReview && <section className="detail-section fault-action" aria-labelledby="review-title" data-testid="supervisor-review">
        <h2 id="review-title">Verifică problema</h2>
        <p>Verifică produsul. Confirmarea mută comanda în grija ta, la Primire Croitorie, fără aprobarea managerului. Închiderea o lasă la angajat, în aceeași etapă.</p>
        <label className="field"><span>Motivul confirmat</span><select value={reasonKey} onChange={(event) => edit(setReasonKey)(event.target.value)}>
          <option value="">Alege motivul</option>{reasons.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>
        <label className="field"><span>Comentariu {reason?.requiresComment ? "(obligatoriu)" : "(obligatoriu doar la închidere)"}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">Confirmă și preia · scanează eticheta produsului</p>
        <QrCapture disabled={!reasonKey || submitting || (Boolean(reason?.requiresComment) && !commentReady)} onToken={(token) => void run((key) => service.review(fault.id, { expectedVersion: fault.version, decision: "accept", reasonKey, qrToken: token, comment: comment.trim() || null, idempotencyKey: key }))} />
        {!reasonKey && <small className="server-note">Alege motivul înainte de scanare.</small>}
        <button className="button button-secondary" type="button" disabled={submitting || !commentReady} onClick={() => void run((key) => service.review(fault.id, { expectedVersion: fault.version, decision: "dismiss", comment: comment.trim(), idempotencyKey: key }))}>Închide raportul (nu este o problemă)</button>
      </section>}

      {actions.canRequestReturn && <section className="detail-section fault-action" aria-labelledby="return-title" data-testid="supervisor-return">
        <h2 id="return-title">Trimite la tăiere</h2>
        <p>Cheamă tăietorul <strong>{fault.responsible.displayName}</strong> să verifice produsul. Managerul decide returnarea la tăiere numai după ce tăietorul scanează eticheta.</p>
        {receivers && <label className="field"><span>Tăietor disponibil</span><select value={receiverId} onChange={(event) => edit(setReceiverId)(event.target.value)}>
          <option value="">Alege tăietorul</option>{receivers.map((item) => <option key={item.id} value={item.id}>{item.displayName}</option>)}</select></label>}
        <label className="field"><span>Mesaj (opțional)</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || (receivers !== null && !receiverId)} aria-busy={submitting || undefined}
          onClick={() => void run((key) => service.sendReturn(fault.id, { expectedVersion: fault.version, receiverEmployeeId: receiverId || null, comment: comment.trim() || null, idempotencyKey: key }))}>{submitting ? "Se trimite…" : "Trimite la tăiere"}</button>
      </section>}

      {actions.canAcknowledge && !tailoring && <section className="detail-section fault-action" aria-labelledby="ack-title">
        <h2 id="ack-title">Confirmă eroarea</h2>
        <p>Verifică produsele de mai sus. Metrajul ales la Primire Croitorie nu poate fi micșorat.</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /> <span>{ACK_TEXT}</span></label>
        <label className="field"><span>Comentariu (opțional)</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => setComment(event.target.value)} /></label>
        <p className="eyebrow">Pasul 2 · Scanează eticheta comenzii returnate</p>
        <QrCapture disabled={!confirmed || submitting} onToken={(token) => { if (confirmed) void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, idempotencyKey: key })); }} />
        {!confirmed && <small className="server-note">Bifează confirmarea înainte de scanare.</small>}
      </section>}

      {actions.canAcknowledge && tailoring && <section className="detail-section fault-action" aria-labelledby="receipt-title" data-testid="cutter-receipt">
        <h2 id="receipt-title">Primește produsul</h2>
        <p>Scanarea confirmă doar că ai primit produsul. Nu înseamnă că recunoști o greșeală.</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{RECEIPT_TEXT}</span></label>
        {actions.statesResponsibility && <fieldset className="choice-group"><legend>Poziția ta despre responsabilitate</legend>
          <label className="check-field"><input type="radio" name="position" checked={position === "accepted"} onChange={() => edit(setPosition)("accepted")} /> <span>Recunosc eroarea de tăiere</span></label>
          <label className="check-field"><input type="radio" name="position" checked={position === "disputed"} onChange={() => edit(setPosition)("disputed")} /> <span>Contest responsabilitatea (de exemplu material defect)</span></label>
        </fieldset>}
        <label className="field"><span>Comentariu {position === "disputed" ? "(obligatoriu)" : "(opțional)"}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">Scanează eticheta produsului primit</p>
        <QrCapture disabled={!confirmed || submitting || (actions.statesResponsibility && !position) || (position === "disputed" && !commentReady)}
          onToken={(token) => void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, responsibility: actions.statesResponsibility ? position : null, idempotencyKey: key }))} />
        {!confirmed && <small className="server-note">Bifează primirea înainte de scanare.</small>}
      </section>}

      {fault.status === "awaiting_approval" && <p className="order-notice" role="status">Returnarea la tăiere așteaptă aprobarea managerului operațional. Lucrarea nu începe până la aprobare.</p>}

      {(actions.canRequestRereview || actions.canAskManagersAgain) && <section className="detail-section fault-action" aria-labelledby="rereview-title">
        <h2 id="rereview-title">{actions.canAskManagersAgain ? "Cere o nouă decizie" : "Solicită reanalizarea"}</h2>
        <p>Dacă decizia pare greșită, cere o nouă analiză. Respingerea rămâne în istoric.</p>
        <label className="field"><span>Motivul reanalizării (obligatoriu)</span><textarea maxLength={1000} rows={3} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || !commentReady} aria-busy={submitting || undefined} onClick={() => void run((key) => service.rereview(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{submitting ? "Se trimite…" : "Solicită reanalizarea"}</button>
      </section>}

      {actions.canRestore && <section className="detail-section fault-action" aria-labelledby="restore-title" data-testid="supervisor-restore">
        <h2 id="restore-title">{fault.status === "awaiting_restore_receipt" ? "Predă produsul altui angajat" : "Predă înapoi la croitorie"}</h2>
        <p>Scanează eticheta cu produsul în mână. Comanda rămâne blocată până când angajatul care o primește scanează eticheta și confirmă primirea. Abia atunci revine în etapa {fault.detectedStage.label ?? fault.detectedStage.id}, la acel angajat. Nu este nevoie de aprobarea managerului.</p>
        {recipients && <label className="field"><span>Angajatul care primește produsul</span><select value={recipientId} onChange={(event) => edit(setRecipientId)(event.target.value)}>
          <option value="">Alege angajatul</option>{recipients.map((item) => <option key={item.id} value={item.id}>{item.displayName}</option>)}</select></label>}
        {recipients && recipients.length === 0 && <small className="server-note">Niciun angajat nu poate primi acum comanda în această etapă. Comanda rămâne blocată.</small>}
        <label className="field"><span>Motiv (obligatoriu)</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">Scanează eticheta produsului</p>
        <QrCapture disabled={submitting || !commentReady || (recipients !== null && !recipientId)} onToken={(token) => void run((key) => service.restore(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim(), recipientEmployeeId: recipientId || null, idempotencyKey: key }))} />
      </section>}

      {actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="restore-receipt-title" data-testid="restore-receipt">
        <h2 id="restore-receipt-title">Primește comanda înapoi</h2>
        <p>Ia produsul de la {fault.physicalCustodian?.displayName ?? "șeful de croitorie"}, scanează eticheta și confirmă. Comanda revine la tine în etapa {fault.detectedStage.label ?? fault.detectedStage.id} și poți continua lucrul.</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{RESTORE_RECEIPT_TEXT}</span></label>
        <label className="field"><span>Comentariu (opțional)</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">Scanează eticheta produsului primit</p>
        <QrCapture disabled={!confirmed || submitting} onToken={(token) => { if (confirmed) void run((key) => service.receiveRestore(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, idempotencyKey: key })); }} />
        {!confirmed && <small className="server-note">Bifează primirea înainte de scanare.</small>}
      </section>}

      {actions.canAddNote && !actions.canReview && !actions.canRestore && !actions.canAcknowledge && !actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="note-title">
        <h2 id="note-title">Adaugă informații</h2>
        <label className="field"><span>Informație</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-secondary" type="button" disabled={submitting || !commentReady} onClick={() => void run((key) => service.addNote(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>Adaugă</button>
      </section>}

      {actionError && <ErrorState error={actionError} compact onAction={() => { setActionError(null); if (actionError.code !== "RESPONSIBLE_UNAVAILABLE" && actionError.code !== "RESTORE_RECIPIENT_UNAVAILABLE") setReload((value) => value + 1); }} />}
      {submitting && <p className="server-note" role="status">Așteptăm confirmarea serverului.</p>}

      {fault.decisions && fault.decisions.length > 0 && <section className="detail-section"><p className="eyebrow">Decizii manager</p>
        <ol className="decision-list">{fault.decisions.map((decision) => <li key={decision.attempt}>
          <strong>Încercarea {decision.attempt} · {decision.status === "pending" ? "în așteptare" : decision.status === "approved" ? "aprobată" : decision.status === "rejected" ? "respinsă" : "anulată"}</strong>
          {decision.openedReason === "rereview" && <small>Reanalizare cerută de {decision.openedBy}{decision.openedComment ? `: „${decision.openedComment}”` : ""}</small>}
          {decision.decidedBy && <small>{decision.decidedBy}{decision.comment ? `: „${decision.comment}”` : ""}</small>}
        </li>)}</ol>
      </section>}
      {fault.timeline && fault.timeline.length > 0 && <section className="detail-section"><p className="eyebrow">Istoric</p>
        <ol className="decision-list exception-timeline">{fault.timeline.map((entry) => <li key={entry.version}>
          <strong>{timelineLabel(entry.action)}</strong><small>{entry.actor} · <time dateTime={entry.at}>{time.format(new Date(entry.at))}</time></small>{entry.comment && <small>„{entry.comment}”</small>}
        </li>)}</ol>
      </section>}
      {fault.role !== "supervisor" && <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}`)}>Vezi comanda</button>}
    </article>
  );
}
