import { physicalText as pt } from "../../i18n/physical";
import { PieceOutcomeEditor, outcomesReady } from "../../components/PieceOutcomeEditor";
import type { PieceOutcome } from "../../domain/pieces";
import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { PieceTrack, ProductionWorkflow, QualityCapabilities, StaffOrder, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { approvedReturnFor, orderPieces, pieceLabelFilename, pieceName, trackPieces, trackStatusLabel, type PieceApi, type PieceLabels } from "../../domain/pieces";
import { isSupervised, TAILORING_STAGE_IDS } from "../../domain/faults";
import { stageName } from "../../domain/stageNames";
import { getStageById } from "../../domain/productionWorkflow";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { QrCapture } from "../exceptions/QrCapture";
import { ReturnToCuttingPanel } from "../exceptions/ReturnToCuttingPanel";

const RETRY_CODES = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];

/** Saves a server-rendered label; nothing is drawn or encoded in the browser. */
function saveSvg(svg: string, filename: string) {
  const url = URL.createObjectURL(new Blob([svg], { type: "image/svg+xml" }));
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.rel = "noopener";
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/** Printable labels of the pieces on one track (server-rendered QR). The label identifies exactly that piece when scanned. */
export function PieceLabelsView({ trackId, service, revision }: { trackId: string; service: PieceApi; revision?: number }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [reload, setReload] = useState(0);
  const [loadedTag, setLoadedTag] = useState("");
  const tag = `${trackId}:${revision ?? "legacy"}:${reload}`;
  const [labels, setLabels] = useState<PieceLabels | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    service.labels(trackId, controller.signal).then(value => { setLabels(value); setLoadedTag(tag); setError(null); }).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [open, service, trackId, revision, reload, tag]);
  if (!open) return <button type="button" className="button button-secondary" onClick={() => setOpen(true)} data-testid="piece-labels-open">{t("piece.showLabels")}</button>;
  return <div className="piece-labels" data-testid="piece-labels">
    <p className="eyebrow">{t("piece.labels")}</p>
    <p className="server-note">{t("piece.labelHint")}</p>
    {error && <ErrorState error={error} compact onAction={() => { setError(null); setReload(v => v + 1); }} />}
    {loadedTag !== tag && !error && <p role="status">{t("common.loading")}</p>}
    {loadedTag === tag && labels?.labels.map((label) => <figure key={label.unitId} className="piece-label">
      {label.svg && <img className="qr-preview" src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(label.svg)}`} alt={t("piece.label", { index: label.piece.split("/")[0], count: label.piece.split("/")[1] })} width={168} height={168} />}
      <figcaption><strong>#{labels.orderNumber} · {t("piece.label", { index: label.piece.split("/")[0], count: label.piece.split("/")[1] })}</strong><small>{[label.code, label.color, label.name].filter(Boolean).join(" · ")}</small></figcaption>
      {label.svg && <button type="button" className="button button-secondary" onClick={() => saveSvg(label.svg!, pieceLabelFilename(labels.orderNumber, label.piece))}>{t("piece.downloadLabel")}</button>}
    </figure>)}
  </div>;
}

type Confirm = { track: PieceTrack; action: "claim" | "complete_stage"; key: string };

/**
 * The physical pieces of an order whose pieces do not all travel together (API 2.31.0): the pieces with the order and
 * every piece in rework with its own stage, owner, rework cycle and open request. Claiming a piece needs a scan of the
 * piece label (or of the order QR together with the piece chosen here); completing the stage where the defect was found
 * needs the explicit confirmation that it is resolved. Every result is the server's committed state.
 */
export function PiecesPanel({ order, workflow, service, exceptions, quality, permissions, navigate, onChanged, onSessionExpired }: {
  order: StaffOrder; workflow: ProductionWorkflow; service?: PieceApi; exceptions: ExceptionService; quality: QualityCapabilities | null; permissions: readonly string[];
  navigate: (path: string) => void; onChanged: () => void; onSessionExpired: () => void;
}) {
  const { t } = useI18n();
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [outcomes, setOutcomes] = useState<PieceOutcome[]>([]);
  const [resolved, setResolved] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const inFlight = useRef(false);
  const pieces = order.pieces;
  if (!pieces || (pieces.tracks.length === 0 && pieces.total !== null)) return null;
  const productOf = (itemId: string) => order.products.find((item) => item.id === itemId);
  const approved = approvedReturnFor(order);
  const withOrder = orderPieces(pieces);

  async function run(current: Confirm, qrToken?: string) {
    if (!service || inFlight.current) return;
    inFlight.current = true;
    setSubmitting(true);
    setError(null);
    try {
      if (current.action === "claim") await service.claim(current.track.id, { expectedVersion: current.track.version, qrToken: qrToken ?? "", idempotencyKey: current.key });
      else await service.complete(current.track.id, { expectedVersion: current.track.version, ...(current.track.requiresPieceOutcome ? { pieceOutcomes: outcomes } : {}), ...(current.track.requiresDefectResolution ? { defectResolved: true as const } : {}), idempotencyKey: current.key });
      setDone(current.action);
      setConfirm(null);
      setResolved(false);
      onChanged();
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      // A transient failure keeps the key, so a retry replays the same intent; anything else starts over.
      if (!RETRY_CODES.includes(failure.code)) setConfirm(null);
      setError(failure);
    } finally {
      inFlight.current = false;
      setSubmitting(false);
    }
  }

  return (
    <section className="detail-section pieces-panel" aria-labelledby="pieces-title" data-testid="pieces-panel">
      <p className="eyebrow">{t("piece.title")}</p>
      <h2 id="pieces-title">{pieces.total === null ? pt("physical.numar_de_bucati_neconfirmat") : t("piece.summary", { total: pieces.total, onOrder: pieces.onOrder, inRework: pieces.inRework })}</h2>
      {approved && <div className="order-action-done piece-approved" role="status" data-testid="piece-approved">
        <strong>{t("piece.approved.title")}</strong>
        <span>{t("piece.approved.order", { number: order.orderNumber })}</span>
        <span>{t("piece.approved.product", { code: [...new Set(trackPieces(pieces, approved).map((piece) => productOf(piece.itemId)?.code ?? productOf(piece.itemId)?.name ?? ""))].join(", ") })}</span>
        <span>{t("piece.approved.quantity", { count: approved.quantity })}</span>
        <span>{t("piece.approved.stage", { stage: stageName(approved.stage.id, approved.stage.label) })}</span>
        {approved.owner && <span>{t("piece.approved.owner", { name: approved.owner.displayName })}</span>}
      </div>}
      {pieces.mixedProgress && <p className="order-notice order-notice-muted" role="note">{t("piece.mixed")}</p>}
      {done && <p className="order-action-done" role="status">✓ {done === "claim" ? t("orderAction.success.claim.title") : t("orderAction.success.complete_stage.title")}</p>}

      {withOrder.length > 0 && <div className="piece-track piece-with-order" data-testid="pieces-with-order">
        <div className="piece-track-head"><strong>{t("piece.withOrder")}</strong><StageLabel stage={getStageById(workflow, order.productionStageId)} muted /></div>
        <ul className="piece-list">{withOrder.map((piece) => <li key={piece.id}>{pieceName(piece)} · {productOf(piece.itemId)?.code ?? productOf(piece.itemId)?.name}</li>)}</ul>
      </div>}

      {pieces.tracks.map((track) => {
        const items = trackPieces(pieces, track);
        const canReport = quality !== null && isSupervised(order, quality) && track.owner?.isMe === true && track.allowedAction?.id === "complete_stage"
          && !track.openException && (TAILORING_STAGE_IDS as readonly string[]).includes(track.stage.id) && permissions.includes("orders.report_fault");
        return <article key={track.id} className={`piece-track piece-track-${track.openException ? "blocked" : track.owner?.isMe ? "mine" : "open"}`} data-testid="piece-track" data-track={track.id}>
          <div className="piece-track-head">
            <strong>{items.map((piece) => `${pieceName(piece)} · ${productOf(piece.itemId)?.code ?? productOf(piece.itemId)?.name ?? ""}`).join(", ")}</strong>
            <span className="queue-chip">{trackStatusLabel(track)}</span>
          </div>
          <dl className="fault-facts">
            <div><dt>{t("piece.currentStage")}</dt><dd>{stageName(track.stage.id, track.stage.label)}</dd></div>
            <div><dt>{t("piece.owner")}</dt><dd>{track.owner?.displayName ?? t("piece.noOwner")}</dd></div>
            {track.reworked && <div><dt>{t("piece.cycle", { cycle: track.reworkCycle })}</dt><dd>{t("piece.defective", { count: track.quantity })}</dd></div>}
          </dl>
          {items.some((piece) => piece.reviewRequired) && <p className="order-notice order-notice-warning" role="note">{t("piece.reviewRequired")}</p>}
          <div className="piece-actions">
            {track.openException && <button type="button" className="button button-primary" onClick={() => navigate(`/exceptions/${encodeURIComponent(track.openException!.id)}`)}>{t("piece.openRequest")}</button>}
            {service && track.allowedAction?.id === "claim" && <button type="button" className="button button-primary" data-testid="piece-claim" onClick={() => { setError(null); setDone(null); setConfirm({ track, action: "claim", key: createIdempotencyKey() }); }}>{t("piece.claim")}</button>}
            {service && track.allowedAction?.id === "complete_stage" && <button type="button" className="button button-primary" data-testid="piece-complete" onClick={() => { setError(null); setDone(null); setResolved(false); setOutcomes([]); setConfirm({ track, action: "complete_stage", key: createIdempotencyKey() }); }}>{t("piece.complete")}</button>}
            {service && <PieceLabelsView trackId={track.id} service={service} revision={track.version} />}
          </div>
          {canReport && <div className="return-section"><ReturnToCuttingPanel variant="defect" order={order} track={track} service={exceptions} partialRework={quality?.partialRework === true} onSessionExpired={onSessionExpired} onReported={(id) => navigate(`/exceptions/${encodeURIComponent(id)}`)} /></div>}
        </article>;
      })}

      {error && <ErrorState error={error} compact onAction={() => { setError(null); onChanged(); }} />}

      {confirm && <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="piece-confirm-title">
        <div className="confirmation-card">
          <p className="eyebrow">{t("common.order", { number: order.orderNumber })}</p>
          <h2 id="piece-confirm-title">{confirm.action === "claim" ? t("piece.claimTitle") : t("piece.completeTitle")}</h2>
          <p><strong>{trackPieces(pieces, confirm.track).map(pieceName).join(", ")}</strong> · {stageName(confirm.track.stage.id, confirm.track.stage.label)}{confirm.action === "complete_stage" && confirm.track.nextStageId ? ` → ${stageName(confirm.track.nextStageId)}` : ""}</p>
          {confirm.action === "claim" && <>
            <p>{t("piece.claimScan")}</p>
            <QrCapture disabled={submitting} onToken={(token) => void run(confirm, token)} />
          </>}
          {confirm.action === "complete_stage" && confirm.track.requiresPieceOutcome && <PieceOutcomeEditor units={trackPieces(pieces, confirm.track).map(p => ({ id: p.id, label: `${pieceName(p)} · ${productOf(p.itemId)?.code ?? ""}` }))} value={outcomes} onChange={setOutcomes} disabled={submitting || error !== null} />}
          {confirm.action === "complete_stage" && confirm.track.requiresDefectResolution && <>
            <p className="order-notice" role="note">{t("piece.defectResolvedNote", { stage: stageName(confirm.track.stage.id, confirm.track.stage.label) })}</p>
            <label className="check-field" data-testid="piece-defect-resolved"><input type="checkbox" checked={resolved} disabled={submitting || error !== null} onChange={(event) => setResolved(event.target.checked)} /> <span>{t("piece.defectResolved")}</span></label>
          </>}
          <div className="confirmation-actions">
            <button className="button button-secondary" type="button" disabled={submitting} onClick={() => setConfirm(null)}>{t("common.cancel")}</button>
            {confirm.action === "complete_stage" && <button className="button button-primary" type="button" aria-busy={submitting || undefined}
              disabled={submitting || (confirm.track.requiresDefectResolution && !resolved) || (confirm.track.requiresPieceOutcome && !outcomesReady(trackPieces(pieces, confirm.track), outcomes))} onClick={() => void run(confirm)}>{submitting ? t("common.processing") : t("common.confirm")}</button>}
          </div>
          {submitting && <small className="server-note">{t("common.waitingServer")}</small>}
        </div>
      </div>}
    </section>
  );
}
