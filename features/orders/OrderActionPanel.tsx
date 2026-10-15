import { physicalText as pt } from "../../i18n/physical";
import { PieceOutcomeEditor, outcomesReady } from "../../components/PieceOutcomeEditor";
import { orderPieces, pieceName, type PieceOutcome } from "../../domain/pieces";
import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { CuttingApi } from "../../domain/cutting";
import { QrCapture } from "../exceptions/QrCapture";
import type { OrderActionId, ProductionWorkflow, StaffOrder } from "../../domain/models";
import { StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { orderActionConfirmation, orderActionLabels, orderActionSuccess } from "../../domain/orderActions";
import { getNextStage, getStageById } from "../../domain/productionWorkflow";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { AppIcon } from "../../components/icons/AppIcon";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

type PanelState =
  | { status: "idle" }
  | { status: "confirming"; idempotencyKey: string }
  | { status: "submitting"; idempotencyKey: string }
  | { status: "done"; message: OrderActionId }
  | { status: "error"; error: StaffServiceError; idempotencyKey: string };

/**
 * Detail-screen action: confirmation, single in-flight request, and success only
 * after the server commits. A retry after a network failure reuses the same key.
 */
export function OrderActionPanel({ order, workflow, service, cutting, onUpdated, onReload, onSessionExpired }: {
  order: StaffOrder;
  workflow: ProductionWorkflow;
  service: OrderService;
  cutting?: CuttingApi;
  onUpdated: (order: StaffOrder) => void;
  onReload: () => void;
  onSessionExpired: () => void;
}) {
  const { t } = useI18n();
  const [state, setState] = useState<PanelState>({ status: "idle" });
  const inFlight = useRef(false);
  const intent = useRef<{ key: string; orderId: string; action: OrderActionId; input: Parameters<OrderService["claim"]>[1] & Parameters<OrderService["confirmStageTransition"]>[1] } | null>(null);
  const [resolved, setResolved] = useState(false);
  const [outcomes, setOutcomes] = useState<PieceOutcome[]>([]);
  const affected = order.pieces ? orderPieces(order.pieces) : [];
  const needsOutcome = order.pieces?.requiresPieceOutcome === true;
  const needsResolution = order.pieces?.requiresDefectResolution === true;
  const action = order.employeeAllowedAction;
  const cuttingClaim = Boolean(cutting && order.productionStageId === "material-preparation" && action?.id === "claim");
  const [qrToken, setQrToken] = useState("");
  const [ownedCount, setOwnedCount] = useState<number | null>(null);
  const [confirmedMultiple, setConfirmedMultiple] = useState(false);
  const confirming = state.status === "confirming";
  useEffect(() => {
    if (!confirming || !cuttingClaim) return;
    const controller = new AbortController();
    if (cutting) cutting.pool(controller.signal).then((pool) => setOwnedCount(pool.ownedCount), () => { if (!controller.signal.aborted) setOwnedCount(null); });
    else void Promise.resolve().then(() => { if (!controller.signal.aborted) setOwnedCount(0); });
    return () => controller.abort();
  }, [confirming, cuttingClaim, cutting]);
  if (!action && state.status !== "done") return null;

  async function submit(idempotencyKey: string) {
    if (!action || inFlight.current) return;
    inFlight.current = true;
    setState({ status: "submitting", idempotencyKey });
    try {
      const input = { expectedVersion: order.productionVersion, idempotencyKey, ...(needsOutcome ? { pieceOutcomes: outcomes } : {}), ...(needsResolution ? { defectResolved: true as const } : {}), ...(cuttingClaim ? { qrToken, ownedCount: ownedCount ?? 0, confirmedMultiple } : {}) };
      if (intent.current?.key !== idempotencyKey) intent.current = { key: idempotencyKey, orderId: order.id, action: action.id, input };
      const command = intent.current;
      const updated = command.action === "claim" ? await service.claim(command.orderId, command.input) : await service.confirmStageTransition(command.orderId, command.input);
      intent.current = null;
      setState({ status: "done", message: action.id });
      onUpdated(updated);
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION") onSessionExpired();
      setState({ status: "error", error, idempotencyKey });
    } finally {
      inFlight.current = false;
    }
  }

  const current = getStageById(workflow, order.productionStageId);
  const next = order.pieces?.pieceRouting ? undefined : getNextStage(workflow, order.productionStageId);
  return (
    <section className="detail-section order-action-panel" aria-live="polite">
      {state.status === "done" && <p className="order-action-done" role="status">✓ {orderActionSuccess[state.message].title}</p>}
      {action && (state.status === "idle" || state.status === "done") && <button className="button button-primary button-large" type="button" onClick={() => { intent.current = null; setQrToken(""); setResolved(false); setOutcomes([]); setOwnedCount(null); setConfirmedMultiple(false); setState({ status: "confirming", idempotencyKey: createIdempotencyKey() }); }}>{orderActionLabels[action.id]}<span aria-hidden="true">→</span></button>}
      {state.status === "error" && <ErrorState error={state.error} compact onAction={() => {
        const transient = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(state.error.code);
        if (transient) void submit(state.idempotencyKey);
        else { intent.current = null; setState({ status: "idle" }); onReload(); }
      }} />}
      {action && (state.status === "confirming" || state.status === "submitting") && (
        <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="detail-confirmation-title">
          <div className="confirmation-card">
            <span className="state-icon confirm-icon" aria-hidden="true"><AppIcon name="arrow" size={24} /></span>
            <p className="eyebrow">{t("common.confirmationRequired")}</p>
            <h2 id="detail-confirmation-title">{orderActionConfirmation[action.id].title}</h2>
            <p>{t("orderAction.orderNote", { number: order.orderNumber, note: orderActionConfirmation[action.id].note })}</p>
            {order.pieces?.mixedProgress && <p className="order-notice order-notice-muted" role="note" data-testid="order-pieces-scope">{t("piece.choose.order", { count: order.pieces.onOrder, stage: current?.label ?? "" })}</p>}
            {cuttingClaim && <>
              {qrToken ? <p role="status">{t("orderAction.qrCaptured")}</p> : <QrCapture onToken={setQrToken} disabled={state.status === "submitting"} />}
              {ownedCount === null ? <p>{t("orderAction.checkingWork")}</p> : ownedCount > 0 && <div className="order-notice order-notice-warning"><strong>{t("orderAction.alreadyOwned", { count: ownedCount })}</strong><p>{t("orderAction.multipleBody")}</p><label><input type="checkbox" checked={confirmedMultiple} onChange={(e) => setConfirmedMultiple(e.target.checked)} /> {t("orderAction.multipleConfirm")}</label></div>}
            </>}
            {action.id !== "claim" && needsOutcome && <PieceOutcomeEditor units={affected.map(p => ({ id: p.id, label: pieceName(p) }))} value={outcomes} onChange={setOutcomes} disabled={state.status === "submitting"} />}
            {action.id !== "claim" && needsResolution && <label className="check-field"><input type="checkbox" checked={resolved} disabled={state.status === "submitting"} onChange={e => setResolved(e.target.checked)} />{pt("physical.am_verificat_bucatile_afectate_si_defectul_este")}</label>}
            <div className="confirm-transition">
              <StageLabel stage={current} muted={action.id !== "claim"} />
              {action.id === "complete_stage" && next && <><span aria-hidden="true">↓</span><StageLabel stage={next} /></>}
              {action.id === "complete_production" && <><span aria-hidden="true">↓</span><span className="stage-label"><span>✓</span>{t("common.productionCompleted")}</span></>}
            </div>
            <div className="confirmation-actions">
              <button className="button button-secondary" type="button" disabled={state.status === "submitting"} onClick={() => setState({ status: "idle" })}>{t("common.cancel")}</button>
              <button className="button button-primary" type="button" aria-busy={state.status === "submitting" || undefined} disabled={state.status === "submitting" || (action.id !== "claim" && ((needsOutcome && !outcomesReady(affected, outcomes)) || (needsResolution && !resolved))) || (cuttingClaim && (!qrToken || ownedCount === null || (ownedCount > 0 && !confirmedMultiple)))} onClick={() => void submit(state.idempotencyKey)}>{state.status === "submitting" ? t("common.processing") : t("common.confirm")}</button>
            </div>
            {state.status === "submitting" && <small className="server-note">{t("common.waitingServer")}</small>}
          </div>
        </div>
      )}
    </section>
  );
}
