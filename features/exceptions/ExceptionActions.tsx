import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import { faultReasonName, stageName } from "../../domain/stageNames";
import type { FaultException, FaultReason, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

const RETRY_CODES = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];
/** The request moved on meanwhile: the committed state is read again and the employee confirms anew. */
const STALE_CODES = ["EXCEPTION_CHANGED", "EXCEPTION_STATE_INVALID", "EXCEPTION_ALREADY_RESOLVED", "ORDER_CHANGED", "IDEMPOTENCY_CONFLICT"];

function ConfirmButton({ label, disabled, busy, onClick }: { label: string; disabled: boolean; busy: boolean; onClick: () => void }) {
  const { t } = useI18n();
  return <button className="button button-primary button-large" type="button" disabled={disabled || busy} aria-busy={busy || undefined} onClick={onClick}>{busy ? t("common.processing") : label}</button>;
}

/** A sentence with one emphasized name (the name is the employee's own data, never translated). */
function emphasize(text: string, name: string) {
  const index = text.indexOf(name);
  return index < 0 ? text : <>{text.slice(0, index)}<strong>{name}</strong>{text.slice(index + name.length)}</>;
}

/**
 * The actions the server granted on one production exception, shared by the request screen and the universal
 * scanner. An action that needs the product in hand (supervisor acceptance, cutter receipt, hand-back, receipt back)
 * takes the code of the one scan made in the scanner (`qrToken`); on the request screen, where nothing was scanned,
 * it only opens the scanner for this request. Actions without the product (dismissal, sending to cutting, re-review,
 * notes) work on both. Every action carries the request version and an idempotency key; the server re-verifies the
 * code, the actor, the state and the version, and the shown result is always the committed answer. Callers key it by
 * request id and version: another version is another decision, so nothing typed or ticked for the old one carries over.
 */
export function ExceptionActions({ fault, service, qrToken, onUpdated, onStale, onOpenScanner, onSessionExpired }: {
  fault: FaultException;
  service: ExceptionService;
  /** The code scanned in the universal scanner for this request; null on the request screen. Kept in memory only. */
  qrToken: string | null;
  onUpdated: (fault: FaultException) => void;
  /** The request changed meanwhile: re-read it (the screen reloads, the scanner repeats its lookup with the same code). */
  onStale: () => void;
  onOpenScanner?: () => void;
  onSessionExpired: () => void;
}) {
  const { t } = useI18n();
  const scanning = qrToken !== null;
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
  // A double tap or a repeated camera detection never sends a second command while one is in flight.
  const inFlightRef = useRef(false);

  const reviewing = fault.actions.canReview && scanning;
  useEffect(() => {
    if (!reviewing) return undefined;
    const controller = new AbortController();
    service.reasons({ signal: controller.signal }).then(setReasons).catch(() => undefined);
    return () => controller.abort();
  }, [reviewing, service]);

  async function run(action: (key: string) => Promise<FaultException>) {
    if (inFlightRef.current) return;
    inFlightRef.current = true;
    keyRef.current ??= createIdempotencyKey();
    setSubmitting(true);
    setActionError(null);
    try {
      const updated = await action(keyRef.current);
      keyRef.current = null;
      setComment("");
      setConfirmed(false);
      setPosition(null);
      setReceivers(null);
      setReceiverId("");
      setRecipients(null);
      setRecipientId("");
      onUpdated(updated);
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      // A transient failure keeps the key so a retry replays the same intent; anything else starts over.
      if (!RETRY_CODES.includes(failure.code)) keyRef.current = null;
      if (failure.code === "RESPONSIBLE_UNAVAILABLE") service.receivers(fault.id).then(setReceivers).catch(() => setReceivers([]));
      if (failure.code === "RESTORE_RECIPIENT_UNAVAILABLE" && fault.actions.canRestore) service.restoreRecipients(fault.id).then(setRecipients).catch(() => setRecipients([]));
      setActionError(failure);
    } finally {
      inFlightRef.current = false;
      setSubmitting(false);
    }
  }
  /** A new intent (other text, other choice) never reuses the key of a different one. */
  const edit = <T,>(setter: (value: T) => void) => (value: T) => { keyRef.current = null; setter(value); };

  const tailoring = fault.type === "tailoring_defect";
  const actions = fault.actions;
  const commentReady = comment.trim().length >= 3;
  const reason = reasons.find((item) => item.key === reasonKey);
  const detected = stageName(fault.detectedStage.id, fault.detectedStage.label);
  // Restoring before the cutting employee received the product withdraws the return to cutting: a deliberate, ticked choice.
  const cancelsReturn = fault.status === "awaiting_acknowledgment";
  const scanPrompt = <div className="scan-prompt" data-testid="scan-prompt">
    <p>{t("exc.scanToAct")}</p>
    <button className="button button-primary button-large" type="button" onClick={onOpenScanner} disabled={!onOpenScanner}><AppIcon name="scan" size={20} /> {t("exc.openScanner")}</button>
  </div>;
  const codeNote = <small className="server-note" data-testid="code-reused">{t("scan.exc.codeReused")}</small>;

  return (
    <>
      {actions.canReview && <section className="detail-section fault-action" aria-labelledby="review-title" data-testid="supervisor-review">
        <h2 id="review-title">{t("exc.review.title")}</h2>
        <p>{t("exc.review.body")}</p>
        {scanning && <label className="field"><span>{t("exc.review.reason")}</span><select value={reasonKey} onChange={(event) => edit(setReasonKey)(event.target.value)}>
          <option value="">{t("common.chooseReason")}</option>{reasons.map((item) => <option key={item.key} value={item.key}>{faultReasonName(item.key, item.label)}</option>)}</select></label>}
        <label className="field"><span>{reason?.requiresComment ? t("common.commentRequired") : t("exc.review.commentDismissOnly")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        {scanning ? <>
          <ConfirmButton label={t("scan.exc.confirm.accept")} disabled={!reasonKey || (Boolean(reason?.requiresComment) && !commentReady)} busy={submitting} onClick={() => void run((key) => service.review(fault.id, { expectedVersion: fault.version, decision: "accept", reasonKey, qrToken, comment: comment.trim() || null, idempotencyKey: key }))} />
          {!reasonKey && <small className="server-note">{t("exc.review.chooseFirst")}</small>}
          {codeNote}
        </> : scanPrompt}
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

      {actions.canAcknowledge && !tailoring && <section className="detail-section fault-action" aria-labelledby="ack-title" data-testid="cutter-ack">
        <h2 id="ack-title">{t("exc.ack.title")}</h2>
        <p>{t("exc.ack.body")}</p>
        {scanning ? <>
          <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.ack.text")}</span></label>
          <label className="field"><span>{t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
          <ConfirmButton label={t("scan.exc.confirm.receipt")} disabled={!confirmed} busy={submitting} onClick={() => void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken, comment: comment.trim() || null, idempotencyKey: key }))} />
          {!confirmed && <small className="server-note">{t("exc.ack.checkFirst")}</small>}
          {codeNote}
        </> : scanPrompt}
      </section>}

      {actions.canAcknowledge && tailoring && <section className="detail-section fault-action" aria-labelledby="receipt-title" data-testid="cutter-receipt">
        <h2 id="receipt-title">{t("exc.receipt.title")}</h2>
        <p>{t("exc.receipt.body")}</p>
        {scanning ? <>
          <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.receipt.text")}</span></label>
          {actions.statesResponsibility && <fieldset className="choice-group"><legend>{t("exc.receipt.position")}</legend>
            <label className="check-field"><input type="radio" name="position" checked={position === "accepted"} onChange={() => edit(setPosition)("accepted")} /> <span>{t("exc.receipt.accept")}</span></label>
            <label className="check-field"><input type="radio" name="position" checked={position === "disputed"} onChange={() => edit(setPosition)("disputed")} /> <span>{t("exc.receipt.dispute")}</span></label>
          </fieldset>}
          <label className="field"><span>{position === "disputed" ? t("common.commentRequired") : t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
          <ConfirmButton label={t("scan.exc.confirm.receipt")} disabled={!confirmed || (actions.statesResponsibility && !position) || (position === "disputed" && !commentReady)} busy={submitting} onClick={() => void run((key) => service.acknowledge(fault.id, { expectedVersion: fault.version, qrToken, comment: comment.trim() || null, responsibility: actions.statesResponsibility ? position : null, idempotencyKey: key }))} />
          {!confirmed && <small className="server-note">{t("exc.receipt.checkFirst")}</small>}
          {codeNote}
        </> : scanPrompt}
      </section>}

      {(actions.canRequestRereview || actions.canAskManagersAgain) && <section className="detail-section fault-action" aria-labelledby="rereview-title">
        <h2 id="rereview-title">{actions.canAskManagersAgain ? t("exc.rereview.askAgain") : t("exc.rereview.request")}</h2>
        <p>{t("exc.rereview.body")}</p>
        <label className="field"><span>{t("exc.rereview.reason")}</span><textarea maxLength={1000} rows={3} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={submitting || !commentReady} aria-busy={submitting || undefined} onClick={() => void run((key) => service.rereview(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{submitting ? t("common.sending") : t("exc.rereview.request")}</button>
      </section>}

      {actions.canRestore && <section className="detail-section fault-action" aria-labelledby="restore-title" data-testid="supervisor-restore">
        <h2 id="restore-title">{cancelsReturn ? t("exc.restore.cancelTitle") : fault.status === "awaiting_restore_receipt" ? t("exc.restore.otherEmployee") : t("exc.restore.title")}</h2>
        <p>{cancelsReturn ? t("exc.restore.cancelBody", { name: fault.receiver?.displayName ?? fault.responsible.displayName, stage: detected }) : t("exc.restore.body", { stage: detected })}</p>
        {scanning ? <>
          {cancelsReturn && <label className="check-field" data-testid="cancel-return-confirm"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.restore.cancelConfirm")}</span></label>}
          {recipients && <label className="field"><span>{t("exc.restore.recipient")}</span><select value={recipientId} onChange={(event) => edit(setRecipientId)(event.target.value)}>
            <option value="">{t("exc.restore.chooseEmployee")}</option>{recipients.map((item) => <option key={item.id} value={item.id}>{item.displayName}</option>)}</select></label>}
          {recipients && recipients.length === 0 && <small className="server-note">{t("exc.restore.none")}</small>}
          <label className="field"><span>{t("common.reasonRequired")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
          <ConfirmButton label={t("scan.exc.confirm.restore")} disabled={!commentReady || (recipients !== null && !recipientId) || (cancelsReturn && !confirmed)} busy={submitting} onClick={() => void run((key) => service.restore(fault.id, { expectedVersion: fault.version, qrToken, comment: comment.trim(), recipientEmployeeId: recipientId || null, ...(cancelsReturn ? { cancelReturn: true as const } : {}), idempotencyKey: key }))} />
          {codeNote}
        </> : scanPrompt}
      </section>}

      {actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="restore-receipt-title" data-testid="restore-receipt">
        <h2 id="restore-receipt-title">{t("exc.restoreReceipt.title")}</h2>
        <p>{t("exc.restoreReceipt.body", { name: fault.physicalCustodian?.displayName ?? t("exc.restoreReceipt.supervisor"), stage: detected })}</p>
        {scanning ? <>
          <label className="check-field"><input type="checkbox" checked={confirmed} onChange={(event) => edit(setConfirmed)(event.target.checked)} /> <span>{t("exc.restoreReceipt.text")}</span></label>
          <label className="field"><span>{t("common.commentOptional")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
          <ConfirmButton label={t("scan.exc.confirm.restoreReceipt")} disabled={!confirmed} busy={submitting} onClick={() => void run((key) => service.receiveRestore(fault.id, { expectedVersion: fault.version, qrToken, comment: comment.trim() || null, idempotencyKey: key }))} />
          {!confirmed && <small className="server-note">{t("exc.receipt.checkFirst")}</small>}
          {codeNote}
        </> : scanPrompt}
      </section>}

      {!scanning && actions.canAddNote && !actions.canReview && !actions.canRestore && !actions.canAcknowledge && !actions.canConfirmRestore && <section className="detail-section fault-action" aria-labelledby="note-title">
        <h2 id="note-title">{t("exc.note.title")}</h2>
        <label className="field"><span>{t("exc.note.label")}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => edit(setComment)(event.target.value)} /></label>
        <button className="button button-secondary" type="button" disabled={submitting || !commentReady} onClick={() => void run((key) => service.addNote(fault.id, { expectedVersion: fault.version, comment: comment.trim(), idempotencyKey: key }))}>{t("common.add")}</button>
      </section>}

      {actionError && <ErrorState error={actionError} compact onAction={() => {
        const failure = actionError;
        setActionError(null);
        if (RETRY_CODES.includes(failure.code)) return;
        if (failure.code === "RESPONSIBLE_UNAVAILABLE" || failure.code === "RESTORE_RECIPIENT_UNAVAILABLE") return;
        if (STALE_CODES.includes(failure.code) || !scanning) onStale();
      }} />}
      {submitting && <p className="server-note" role="status">{t("common.waitingServer")}</p>}
    </>
  );
}
