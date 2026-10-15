import { physicalText as pt } from "../../i18n/physical";
import { useEffect, useRef, useState } from "react";
import { liveRecords, useI18n } from "../../i18n";
import { faultReasonName } from "../../domain/stageNames";
import type { FaultReason, PieceTrack, StaffOrder, StaffServiceError } from "../../domain/models";
import { orderPieces, pieceName, selectionSummary, trackPieces } from "../../domain/pieces";
import type { ExceptionService } from "../../services/contracts";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { formatFaultMeters, selectedMeters } from "../../domain/faults";
import { formatMeters } from "../../domain/productFormat";

export const DETECTION_STAGE_ID = "workshop-receiving";

/** Shown only to the tailoring intake employee who accepted the order and may complete its stage. */
/** Preview of the selected scope: measured meters, and lines without meters counted apart (never as 0 m). */
function selectionScope(items: StaffOrder["products"], selected: ReadonlySet<string>) {
  const unmeasured = items.filter((item) => selected.has(item.id) && !(item.meters != null && item.meters > 0)).length;
  const picked = [...selected].length;
  return { faultMeters: picked > 0 && unmeasured === picked ? null : selectedMeters(items, selected), unmeasuredLineCount: unmeasured };
}

export function canReturnToCutting(order: StaffOrder, permissions: readonly string[]): boolean {
  return order.productionStageId === DETECTION_STAGE_ID && order.employeeAllowedAction?.id === "complete_stage"
    && !order.productionQuality?.openException && permissions.includes("orders.report_fault");
}

// Supervised flow ("defect"): the worker only reports; the tailoring quality supervisor verifies and decides.
const copy = liveRecords("ret.", ["intake", "defect"], ["open", "intro", "picker", "total", "reason", "comment", "submit"]);

/**
 * Selection of the exact faulty lines (older API) or, with per-piece rework, the exact physical pieces ("Bucata 1/2"):
 * nothing is preselected and the employee sees "Cantitate defectă: k din n". A report on a piece in rework (track) names
 * that track's pieces. Reason and comment; one server-confirmed request.
 */
export function ReturnToCuttingPanel({ order, service, onReported, onSessionExpired, variant = "intake", track, recovery }: { order: StaffOrder; service: ExceptionService; onReported: (exceptionId: string) => void; onSessionExpired: () => void; variant?: "intake" | "defect"; track?: PieceTrack; partialRework?: boolean; recovery?: import("../../domain/models").FaultException }) {
  const { t } = useI18n();
  const text = copy[variant];
  const [open, setOpen] = useState(false);
  const [reasons, setReasons] = useState<FaultReason[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [reasonKey, setReasonKey] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const keyRef = useRef<string | null>(null);
  const commandRef = useRef<Parameters<ExceptionService["report"]>[1] | null>(null);
  const inFlight = useRef(false);
  const [pending, setPending] = useState(false);
  // Piece selection: always for a track, and on the order when the server enabled per-piece reports.
  const piecesMode = Boolean(order.pieces) && (Boolean(track) || order.pieces?.partialRework === true || Boolean(recovery));
  const carrierCandidates = !order.pieces ? [] : track ? trackPieces(order.pieces, track) : orderPieces(order.pieces);
  const candidates = recovery ? carrierCandidates.filter(piece => recovery.affectedPieces?.length ? recovery.affectedPieces.some(original => original.unitId === piece.id) : (recovery.lines ?? []).some(line => line.itemId === piece.itemId)) : carrierCandidates;
  const [recoveryConfirmed, setRecoveryConfirmed] = useState(false);
  const [pieces, setPieces] = useState<Set<string>>(new Set());

  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    service.reasons({ signal: controller.signal }).then(setReasons).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [open, service]);

  const reason = reasons.find((item) => item.key === reasonKey);
  // The intake-only return needs measured lines; a reported defect may concern a line the source sent without meters.
  const metered = variant === "defect" ? order.products : order.products.filter((item) => item.meters != null && item.meters > 0);
  const commentMissing = (Boolean(reason?.requiresComment) || Boolean(recovery)) && comment.trim().length < 3;
  const ready = (piecesMode ? pieces.size > 0 : selected.size > 0) && reason && !commentMissing && (!recovery || recoveryConfirmed);
  const toggle = (id: string) => setSelected((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); keyRef.current = null; return next; });
  const togglePiece = (id: string) => setPieces((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); keyRef.current = null; return next; });
  const pieceSummary = selectionSummary(candidates, pieces);
  const pieceLines = order.products.filter((item) => candidates.some((piece) => piece.itemId === item.id));

  async function submit() {
    if (!ready || inFlight.current) return;
    inFlight.current = true;
    keyRef.current ??= createIdempotencyKey();
    setSubmitting(true);
    setPending(true);
    setError(null);
    try {
      const chosen = candidates.filter((piece) => pieces.has(piece.id));
      commandRef.current ??= piecesMode
        ? { expectedVersion: track ? track.version : order.productionVersion, itemIds: [...new Set(chosen.map((piece) => piece.itemId))], units: chosen.map((piece) => piece.id), trackId: track?.id ?? null, ...(recovery ? { recoversExceptionId: recovery.id } : {}), reasonKey, comment: comment.trim() || null, idempotencyKey: keyRef.current }
        : { expectedVersion: order.productionVersion, itemIds: [...selected], reasonKey, comment: comment.trim() || null, idempotencyKey: keyRef.current };
      const created = await service.report(order.id, commandRef.current);
      commandRef.current = null;
      setPending(false);
      keyRef.current = null;
      setOpen(false);
      onReported(created.id);
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      if (!["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(failure.code)) { keyRef.current = null; commandRef.current = null; setPending(false); }
      setError(failure);
    } finally { inFlight.current = false; setSubmitting(false); }
  }

  if (!open) return <button className="button button-danger-outline" type="button" onClick={() => setOpen(true)}>{track ? t("piece.reportOnPiece") : text.open}</button>;
  return (
    <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="return-title">
      <div className="confirmation-card return-card">
        <p className="eyebrow">{t("common.order", { number: order.orderNumber })}</p>
        <h2 id="return-title">{text.open}</h2>
        <p>{text.intro}</p>
        {recovery && <label className="check-field"><input type="checkbox" checked={recoveryConfirmed} disabled={pending || submitting} onChange={e => { setRecoveryConfirmed(e.target.checked); keyRef.current = null; }} />{pt("physical.recovery.confirm", { number: recovery.number })}</label>}
        {piecesMode ? <>
          <fieldset className="fault-picker" data-testid="piece-picker"><legend>{t("ret.pieces.legend")}</legend>
            {pieceLines.map((item) => {
              const linePieces = candidates.filter((piece) => piece.itemId === item.id);
              return <div key={item.id} className="piece-line">
                <p><strong>{item.name}</strong><small>{[item.code, item.color].filter(Boolean).join(" · ")}</small></p>
                {linePieces.map((piece) => { const name = pieceName(piece); return <label key={piece.id} className="check-field" aria-label={name}><input type="checkbox" checked={pieces.has(piece.id)} onChange={() => togglePiece(piece.id)} disabled={piece.reviewRequired || pending || submitting} />
                  <span><strong>{name}</strong></span></label>; })}
                <small className="server-note">{t("ret.pieces.count", { count: linePieces.filter((piece) => pieces.has(piece.id)).length, total: linePieces.length })}</small>
              </div>;
            })}
          </fieldset>
          <p className="fault-total" role="status" data-testid="piece-count">{t("ret.pieces.count", { count: pieceSummary.selected, total: pieceSummary.total })}</p>
          {pieceSummary.selected > 0 && <p className={`order-notice ${pieceSummary.partial ? "order-notice-muted" : "order-notice-warning"}`} role="note">{pieceSummary.partial ? t("ret.pieces.only") : t("ret.pieces.all")}</p>}
        </> : <>
          <fieldset className="fault-picker"><legend>{text.picker}</legend>
            {metered.map((item) => <label key={item.id} className="check-field"><input type="checkbox" checked={selected.has(item.id)} disabled={pending || submitting} onChange={() => toggle(item.id)} />
              <span><strong>{item.name}</strong><small>{[item.code, item.color].filter(Boolean).join(" · ")}</small></span><b>{item.meters != null && item.meters > 0 ? formatMeters(item.meters) : t("common.noMeters")}</b></label>)}
          </fieldset>
          <p className="fault-total" role="status">{text.total}: <b>{formatFaultMeters(selectionScope(order.products, selected))}</b></p>
        </>}
        <label className="field"><span>{text.reason}</span><select disabled={pending || submitting} value={reasonKey} onChange={(event) => { setReasonKey(event.target.value); keyRef.current = null; }}>
          <option value="">{t("common.chooseReason")}</option>{reasons.map((item) => <option key={item.key} value={item.key}>{faultReasonName(item.key, item.label)}</option>)}</select></label>
        <label className="field"><span>{text.comment} {(reason?.requiresComment || recovery) ? t("common.requiredSuffix") : t("common.optionalSuffix")}</span><textarea disabled={pending || submitting} maxLength={1000} rows={2} value={comment} onChange={(event) => { setComment(event.target.value); keyRef.current = null; }} /></label>
        {error && <ErrorState error={error} compact onAction={() => setError(null)} />}
        <div className="confirmation-actions">
          <button className="button button-secondary" type="button" disabled={submitting} onClick={() => setOpen(false)}>{t("common.cancel")}</button>
          <button className="button button-primary" type="button" disabled={!ready || submitting} aria-busy={submitting || undefined} onClick={() => void submit()}>{submitting ? t("common.sending") : text.submit}</button>
        </div>
        {submitting && <small className="server-note">{t("common.waitingServer")}</small>}
      </div>
    </div>
  );
}
