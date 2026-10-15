import { physicalText as pt } from "../../i18n/physical";
import { useEffect, useRef, useState } from "react";
import { formatDate, t as translateNow, useI18n } from "../../i18n";
import { faultReasonName, stageName } from "../../domain/stageNames";
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
import { isPartialScope, scopeCounts, type PieceApi } from "../../domain/pieces";
import { PieceLabelsView } from "../orders/PiecesPanel";

const time = { format: (value: Date): string => formatDate(value, { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" }) };

/** A sentence with one emphasized name (the name is the employee's own data, never translated). */
function emphasize(text: string, name: string) {
  const index = text.indexOf(name);
  return index < 0 ? text : <>{text.slice(0, index)}<strong>{name}</strong>{text.slice(index + name.length)}</>;
}
/** A request on exact pieces (API 2.31.0): only those pieces move; the rest of the order keeps its stage. */
export function partialScopeNotice(fault: Pick<FaultException, "orderScope">): string | null {
  if (!isPartialScope(fault)) return null;
  const counts = scopeCounts(fault);
  return counts ? translateNow("exc.scope.partial", { affected: counts.defective, healthy: counts.healthy }) : null;
}

/**
 * Production is routed per order: an approved return to cutting moves the whole order. The warning names what
 * really moves whenever the order holds more than the affected lines, or the affected line has several pieces.
 */
export function returnScopeWarning(fault: Pick<FaultException, "orderScope" | "lineCount">): string | null {
  const t = translateNow;
  const scope = fault.orderScope;
  if (!scope || !scope.wholeOrderMoves) return null;
  // Every piece of the order was named (API 2.31.0): nothing beyond the reported pieces moves, so no warning.
  if (scope.piecesRecorded && scope.affectedQuantity >= scope.orderQuantity) return null;
  if (scope.orderLineCount > scope.affectedLineCount) {
    return t("exc.scope.wholeOrder", { lines: scope.orderLineCount, pieces: scope.orderQuantity, affected: t("common.products", { count: scope.affectedLineCount }) });
  }
  return scope.affectedQuantity > 1 ? t("exc.scope.allPieces", { pieces: scope.affectedQuantity }) : null;
}
const RETRY_CODES = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];

/**
 * One production exception, updated live. The original cutting fault (detector and responsible cutter) and
 * the supervised tailoring defect (worker, tailoring quality supervisor, cutter, manager). Every action is
 * shown only when the server granted it, and every result is the server's committed state.
 */
export function ExceptionScreen({ exceptionId, service, pieces, navigate, onSessionExpired }: { exceptionId: string; service: ExceptionService; /** Piece labels (API 2.31.0). */ pieces?: PieceApi; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const { t } = useI18n();
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
  if (!fault) return <LoadingState label={t("exc.loading")} shape="detail" />;
  const arrival = arrivalLabel(fault.arrivalNumber);
  const tailoring = fault.type === "tailoring_defect";
  const actions = fault.actions;
  const commentReady = comment.trim().length >= 3;
  const reason = reasons.find((item) => item.key === reasonKey);
  const scopeWarning = tailoring ? returnScopeWarning(fault) : null;
  const partialNotice = partialScopeNotice(fault);
  const counts = scopeCounts(fault);
  // Restoring before the cutting employee received the product withdraws the return to cutting: a deliberate, ticked choice.
  const cancelsReturn = fault.status === "awaiting_acknowledgment";
  return (
    <article className="screen-stack detail-screen exception-screen" data-testid="exception-screen">
      <button className="back-link" type="button" onClick={() => navigate(fault.role === "supervisor" ? "/problems" : "/")}><AppIcon name="back" size={20} /> {fault.role === "supervisor" ? t("supervisor.title") : t("common.home")}</button>
      <section className="detail-hero"><div><SourceBadge source={fault.order.source} /><p className="eyebrow">{tailoring ? t("exc.typeDefect") : t("exc.typeReturn")} · {fault.number}</p><h1>{t("common.orderWord")} <span className="order-id">#{fault.order.orderNumber}</span></h1></div>
        <span className={`exception-status status-${fault.status}`} role="status" data-testid="exception-status">{faultStatusLabel(fault)}</span></section>
      {tailoring && fault.role === "detector" && fault.status === "awaiting_supervisor_review" && <p className="order-action-done" role="status">✓ {t("exc.reportedToSupervisor")}</p>}
      {fault.repeatedError && <p className="order-notice order-notice-warning" role="note">{t("exc.repeated")}</p>}
      {fault.status === "approved" && (fault.role === "responsible" || fault.role === "receiver") && <p className="order-action-done" role="status">✓ {t("exc.approvedRework")}</p>}
      {tailoring && fault.status === "return_rejected" && <p className="order-notice order-notice-warning" role="note">{t("exc.returnRejected")}</p>}
      {tailoring && fault.status === "awaiting_acknowledgment" && !actions.canAcknowledge && <p className="order-notice" role="status" data-testid="waiting-cutter">{t("exc.waitingCutter", { name: fault.receiver?.displayName ?? fault.responsible.displayName })}</p>}
      {fault.status === "awaiting_restore_receipt" && !actions.canConfirmRestore && <p className="order-notice" role="status" data-testid="restore-pending">{t("exc.restorePending", { name: fault.restoreRecipient?.displayName ?? t("exc.tailoringEmployee") })}</p>}

      <section className="detail-section">
        <p className="eyebrow">{tailoring ? t("exc.affected") : t("exc.faulty")}</p>
        <h2>{t("common.products", { count: fault.lineCount })} · {formatFaultMeters(fault)}</h2>
        <ul className="fault-lines">{fault.lines?.map((line) => <li key={line.itemId}><span><strong>{line.name}</strong><small>{[line.code, line.color, line.variant, line.affectedQuantity !== undefined ? t("exc.pieces.lineAffected", { affected: line.affectedQuantity, quantity: line.quantity }) : t("exc.lineQuantity", { count: line.quantity })].filter(Boolean).join(" · ")}</small></span><b>{formatDecimalMeters(line.meters)}</b></li>)}</ul>
        {counts && <p className="piece-counts" data-testid="piece-counts"><strong>{counts.defective}</strong> · <span>{counts.healthy}</span></p>}
        {fault.affectedPieces && fault.affectedPieces.length > 0 && <div className="affected-pieces" data-testid="affected-pieces"><p className="eyebrow">{t("exc.pieces.title")}</p>
          <ul className="piece-list">{fault.affectedPieces.map((piece) => <li key={piece.unitId}>{t("piece.label", { index: piece.index, count: piece.count })} · {[piece.code, piece.color].filter(Boolean).join(" · ")}</li>)}</ul></div>}
        {partialNotice && <p className="order-notice order-notice-muted" role="note" data-testid="partial-scope">{partialNotice}</p>}
        {pieces && fault.role === "supervisor" && fault.orderScope?.trackId && <PieceLabelsView trackId={fault.orderScope.trackId} service={pieces} />}
        {scopeWarning && ["awaiting_supervisor_review", "in_supervisor_custody", "awaiting_acknowledgment", "awaiting_approval", "return_rejected"].includes(fault.status) && <p className="order-notice order-notice-warning" role="note" data-testid="return-scope-warning">{scopeWarning}</p>}
        <dl className="fault-facts">
          <div><dt>{tailoring ? t("exc.reportedProblem") : t("exc.reason")}</dt><dd>{faultReasonName(fault.reason.key, fault.reason.label)}</dd></div>
          {fault.confirmedReason && <div><dt>{t("exc.confirmedBySupervisor")}</dt><dd>{faultReasonName(fault.confirmedReason.key, fault.confirmedReason.label)}</dd></div>}
          {fault.detectorComment && <div><dt>{tailoring ? t("exc.description") : t("exc.intakeComment")}</dt><dd>{fault.detectorComment}</dd></div>}
          {tailoring && <div><dt>{t("exc.detectedStage")}</dt><dd>{stageName(fault.detectedStage.id, fault.detectedStage.label)}</dd></div>}
          {tailoring && fault.currentStage && <div><dt>{t("exc.currentStage")}</dt><dd>{stageName(fault.currentStage.id, fault.currentStage.label)}</dd></div>}
          <div><dt>{t("exc.detectedBy")}</dt><dd>{fault.detector.displayName}</dd></div>
          {fault.supervisor && <div><dt>{t("exc.supervisor")}</dt><dd>{fault.supervisor.displayName}</dd></div>}
          <div><dt>{t("exc.cutBy")}</dt><dd>{fault.responsible.displayName}</dd></div>
          {fault.cuttingHandoff && <div><dt>{t("exc.cuttingHandoff")}</dt><dd><time dateTime={fault.cuttingHandoff.at}>{time.format(new Date(fault.cuttingHandoff.at))}</time></dd></div>}
          {fault.receiver && <div><dt>{t("exc.sentTo")}</dt><dd>{fault.receiver.displayName}</dd></div>}
          {tailoring && fault.physicalCustodian && <div><dt>{t("exc.custodian")}</dt><dd>{fault.physicalCustodian.displayName}</dd></div>}
          {fault.restoreRecipient && <div><dt>{fault.restoreReceivedAt ? t("exc.receivedBackBy") : t("exc.handedBackTo")}</dt><dd>{fault.restoreRecipient.displayName}</dd></div>}
          {fault.responsibilityPosition && <div><dt>{t("exc.responsibility")}</dt><dd>{responsibilityLabels[fault.responsibilityPosition]}</dd></div>}
          {fault.attribution && <div><dt>{t("exc.managerDecision")}</dt><dd>{fault.attribution === "cutter" ? t("exc.cutterFault") : t("exc.noCutterFault")}</dd></div>}
          {fault.supervisorComment && <div><dt>{t("exc.supervisorComment")}</dt><dd>{fault.supervisorComment}</dd></div>}
          <div><dt>{t("exc.reported")}</dt><dd><time dateTime={fault.reportedAt}>{time.format(new Date(fault.reportedAt))}</time></dd></div>
          {arrival && <div><dt>{t("exc.arrival")}</dt><dd>{arrival}</dd></div>}
        </dl>
      </section>

      {actions.canReview && <section className="detail-section fault-action" aria-labelledby="review-title" data-testid="supervisor-review">
        <h2 id="review-title">{t("exc.review.title")}</h2>
        <p>{t("exc.review.body")}</p>
        <label className="field"><span>{t("exc.review.reason")}</span><select value={reasonKey} onChange={(event) => edit(setReasonKey)(event.target.value)}>
          <option value="">{t("common.chooseReason")}</option>{reasons.map((item) => <option key={item.key} value={item.key}>{faultReasonName(item.key, item.label)}</option>)}</select></label>
        <label className="field"><span>{reason?.requiresComment ? t("common.commentRequired") : t("exc.review.commentDismissOnly")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">{t("exc.review.scan")}</p>
        <QrCapture disabled={!reasonKey || submitting || (Boolean(reason?.requiresComment) && !commentReady)} onToken={(token) => void run((key) => service.review(fault.id, { expectedVersion: fault.version, decision: "accept", reasonKey, qrToken: token, comment: comment.trim() || null, idempotencyKey: key }))} />
        {!reasonKey && <small className="server-note">{t("exc.review.chooseFirst")}</small>}
        <button className="button button-secondary" type="button" disabled={submitting || !commentReady} onClick={() => void run((key) => service.review(fault.id, { expectedVersion: fault.version, decision: "dismiss", comment: comment.trim(), idempotencyKey: key }))}>{t("exc.review.dismiss")}</button>
      </section>}

      {actions.canRequestReturn && <section className="detail-section fault-action" aria-labelledby="return-title" data-testid="supervisor-return">
        <h2 id="return-title">{t("exc.return.title")}</h2>
        <p>{emphasize(t("exc.return.body", { name: fault.responsible.displayName }), fault.responsible.displayName)}</p>
        {receivers && <label className="field"><span>{t("exc.return.cutter")}</span><select value={receiverId} onChange={(event) => edit(setReceiverId)(event.target.value)}>
          <option value="">{t("exc.return.chooseCutter")}</option>{receivers.map((item) => <option key={item.id} value={item.id}>{item.displayName}</option>)}</select></label>}
        <label className="field"><span>{t("exc.return.message")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || (receivers !== null && !receiverId)} aria-busy={submitting || undefined}
          onClick={() => void run((key) => service.sendReturn(fault.id, { expectedVersion: fault.version, receiverEmployeeId: receiverId || null, comment: comment.trim() || null, idempotencyKey: key }))}>{submitting ? t("common.sending") : t("exc.return.send")}</button>
      </section>}

      {actions.canAcknowledge && !tailoring && <section className="detail-section fault-action" aria-labelledby="ack-title">
        <h2 id="ack-title">{t("exc.ack.title")}</h2>
        <p>{t("exc.ack.body")}</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /> <span>{t("exc.ack.text")}</span></label>
        <label className="field"><span>{t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => setComment(event.target.value)} /></label>
        <p className="eyebrow">{t("exc.ack.step2")}</p>
        <QrCapture disabled={!confirmed || submitting} onToken={(token) => { if (confirmed) void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, idempotencyKey: key })); }} />
        {!confirmed && <small className="server-note">{t("exc.ack.checkFirst")}</small>}
      </section>}

      {actions.canAcknowledge && tailoring && <section className="detail-section fault-action" aria-labelledby="receipt-title" data-testid="cutter-receipt">
        <h2 id="receipt-title">{t("exc.receipt.title")}</h2>
        <p>{t("exc.receipt.body")}</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.receipt.text")}</span></label>
        {actions.statesResponsibility && <fieldset className="choice-group"><legend>{t("exc.receipt.position")}</legend>
          <label className="check-field"><input type="radio" name="position" checked={position === "accepted"} onChange={() => edit(setPosition)("accepted")} /> <span>{t("exc.receipt.accept")}</span></label>
          <label className="check-field"><input type="radio" name="position" checked={position === "disputed"} onChange={() => edit(setPosition)("disputed")} /> <span>{t("exc.receipt.dispute")}</span></label>
        </fieldset>}
        <label className="field"><span>{position === "disputed" ? t("common.commentRequired") : t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">{t("exc.receipt.scan")}</p>
        <QrCapture disabled={!confirmed || submitting || (actions.statesResponsibility && !position) || (position === "disputed" && !commentReady)}
          onToken={(token) => void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, responsibility: actions.statesResponsibility ? position : null, idempotencyKey: key }))} />
        {!confirmed && <small className="server-note">{t("exc.receipt.checkFirst")}</small>}
      </section>}

      {fault.status === "awaiting_approval" && <p className="order-notice" role="status">{t("exc.awaitingApproval")}</p>}

      {(actions.canRequestRereview || actions.canAskManagersAgain) && <section className="detail-section fault-action" aria-labelledby="rereview-title">
        <h2 id="rereview-title">{actions.canAskManagersAgain ? t("exc.rereview.askAgain") : t("exc.rereview.request")}</h2>
        <p>{t("exc.rereview.body")}</p>
        <label className="field"><span>{t("exc.rereview.reason")}</span><textarea maxLength={1000} rows={3} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || !commentReady} aria-busy={submitting || undefined} onClick={() => void run((key) => service.rereview(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{submitting ? t("common.sending") : t("exc.rereview.request")}</button>
      </section>}

      {actions.canRestore && <section className="detail-section fault-action" aria-labelledby="restore-title" data-testid="supervisor-restore">
        <h2 id="restore-title">{cancelsReturn ? t("exc.restore.cancelTitle") : fault.status === "awaiting_restore_receipt" ? t("exc.restore.otherEmployee") : t("exc.restore.title")}</h2>
        <p>{cancelsReturn ? t("exc.restore.cancelBody", { name: fault.receiver?.displayName ?? fault.responsible.displayName, stage: stageName(fault.detectedStage.id, fault.detectedStage.label) }) : t("exc.restore.body", { stage: stageName(fault.detectedStage.id, fault.detectedStage.label) })}</p>
        {cancelsReturn && <label className="check-field" data-testid="cancel-return-confirm"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.restore.cancelConfirm")}</span></label>}
        {recipients && <label className="field"><span>{t("exc.restore.recipient")}</span><select value={recipientId} onChange={(event) => edit(setRecipientId)(event.target.value)}>
          <option value="">{t("exc.restore.chooseEmployee")}</option>{recipients.map((item) => <option key={item.id} value={item.id}>{item.displayName}</option>)}</select></label>}
        {recipients && recipients.length === 0 && <small className="server-note">{t("exc.restore.none")}</small>}
        <label className="field"><span>{t("common.reasonRequired")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">{t("exc.scanProduct")}</p>
        <QrCapture disabled={submitting || !commentReady || (recipients !== null && !recipientId) || (cancelsReturn && !confirmed)} onToken={(token) => void run((key) => service.restore(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim(), recipientEmployeeId: recipientId || null, ...(cancelsReturn ? { cancelReturn: true as const } : {}), idempotencyKey: key }))} />
      </section>}

      {actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="restore-receipt-title" data-testid="restore-receipt">
        <h2 id="restore-receipt-title">{t("exc.restoreReceipt.title")}</h2>
        <p>{t("exc.restoreReceipt.body", { name: fault.physicalCustodian?.displayName ?? t("exc.restoreReceipt.supervisor"), stage: stageName(fault.detectedStage.id, fault.detectedStage.label) })}</p>
        <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.restoreReceipt.text")}</span></label>
        <label className="field"><span>{t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <p className="eyebrow">{t("exc.receipt.scan")}</p>
        <QrCapture disabled={!confirmed || submitting} onToken={(token) => { if (confirmed) void run((key) => service.receiveRestore(fault.id, { expectedVersion: fault.version, qrToken: token, comment: comment.trim() || null, idempotencyKey: key })); }} />
        {!confirmed && <small className="server-note">{t("exc.receipt.checkFirst")}</small>}
      </section>}

      {actions.canAddNote && !actions.canReview && !actions.canRestore && !actions.canAcknowledge && !actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="note-title">
        <h2 id="note-title">{t("exc.note.title")}</h2>
        <label className="field"><span>{t("exc.note.label")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-secondary" type="button" disabled={submitting || !commentReady} onClick={() => void run((key) => service.addNote(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{t("common.add")}</button>
      </section>}

      {actionError && <ErrorState error={actionError} compact onAction={() => { setActionError(null); if (actionError.code !== "RESPONSIBLE_UNAVAILABLE" && actionError.code !== "RESTORE_RECIPIENT_UNAVAILABLE") setReload((value) => value + 1); }} />}
      {submitting && <p className="server-note" role="status">{t("common.waitingServer")}</p>}

      {fault.decisions && fault.decisions.length > 0 && <section className="detail-section"><p className="eyebrow">{t("exc.decisions")}</p>
        <ol className="decision-list">{fault.decisions.map((decision) => <li key={decision.attempt}>
          <strong>{t("exc.attempt", { attempt: decision.attempt, status: t(decision.status === "pending" ? "exc.decision.pending" : decision.status === "approved" ? "exc.decision.approved" : decision.status === "rejected" ? "exc.decision.rejected" : "exc.decision.cancelled") })}</strong>
          {decision.openedReason === "rereview" && <small>{t("exc.rereviewBy", { name: decision.openedBy })}{decision.openedComment ? `: ${t("common.quote", { text: decision.openedComment })}` : ""}</small>}
          {decision.decidedBy && <small>{decision.decidedBy}{decision.comment ? `: ${t("common.quote", { text: decision.comment })}` : ""}</small>}
        </li>)}</ol>
      </section>}
      {fault.timeline && fault.timeline.length > 0 && <section className="detail-section"><p className="eyebrow">{t("common.history")}</p>
        <ol className="decision-list exception-timeline">{fault.timeline.map((entry) => <li key={entry.version}>
          <strong>{timelineLabel(entry.action)}</strong><small>{entry.actor} · <time dateTime={entry.at}>{time.format(new Date(entry.at))}</time></small>{entry.comment && <small>{t("common.quote", { text: entry.comment })}</small>}
        </li>)}</ol>
      </section>}
      {fault.type === "tailoring_defect" && ["restored", "cancelled"].includes(fault.status) && <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}?recover=${encodeURIComponent(fault.id)}`)}>{pt("physical.recovery.open")}</button>}
      {fault.role !== "supervisor" && <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}`)}>{t("common.viewOrder")}</button>}
    </article>
  );
}
