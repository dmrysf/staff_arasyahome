import { useRef, useState } from "react";
import type { ProductionWorkflow, StaffOrder } from "../../domain/models";
import { StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { orderActionConfirmation, orderActionSuccess } from "../../domain/orderActions";
import { getNextStage, getStageById } from "../../domain/productionWorkflow";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

type PanelState =
  | { status: "idle" }
  | { status: "confirming"; idempotencyKey: string }
  | { status: "submitting"; idempotencyKey: string }
  | { status: "done"; message: string }
  | { status: "error"; error: StaffServiceError; idempotencyKey: string };

/**
 * Detail-screen action: confirmation, single in-flight request, and success only
 * after the server commits. A retry after a network failure reuses the same key.
 */
export function OrderActionPanel({ order, workflow, service, onUpdated, onReload, onSessionExpired }: {
  order: StaffOrder;
  workflow: ProductionWorkflow;
  service: OrderService;
  onUpdated: (order: StaffOrder) => void;
  onReload: () => void;
  onSessionExpired: () => void;
}) {
  const [state, setState] = useState<PanelState>({ status: "idle" });
  const inFlight = useRef(false);
  const action = order.employeeAllowedAction;
  if (!action && state.status !== "done") return null;

  async function submit(idempotencyKey: string) {
    if (!action || inFlight.current) return;
    inFlight.current = true;
    setState({ status: "submitting", idempotencyKey });
    try {
      const input = { expectedVersion: order.productionVersion, idempotencyKey };
      const updated = action.id === "claim" ? await service.claim(order.id, input) : await service.confirmStageTransition(order.id, input);
      setState({ status: "done", message: orderActionSuccess[action.id].title });
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
  const next = getNextStage(workflow, order.productionStageId);
  return (
    <section className="detail-section order-action-panel" aria-live="polite">
      {state.status === "done" && <p className="order-action-done" role="status">✓ {state.message}</p>}
      {action && (state.status === "idle" || state.status === "done") && <button className="button button-primary button-large" type="button" onClick={() => setState({ status: "confirming", idempotencyKey: createIdempotencyKey() })}>{action.label}<span aria-hidden="true">→</span></button>}
      {state.status === "error" && <ErrorState error={state.error} compact onAction={() => {
        const transient = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(state.error.code);
        if (transient) void submit(state.idempotencyKey);
        else { setState({ status: "idle" }); onReload(); }
      }} />}
      {action && (state.status === "confirming" || state.status === "submitting") && (
        <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="detail-confirmation-title">
          <div className="confirmation-card">
            <span className="state-icon confirm-icon" aria-hidden="true">?</span>
            <p className="eyebrow">Confirmare necesară</p>
            <h2 id="detail-confirmation-title">{orderActionConfirmation[action.id].title}</h2>
            <p>Comanda #{order.orderNumber}. {orderActionConfirmation[action.id].note}</p>
            <div className="confirm-transition">
              <StageLabel stage={current} muted={action.id !== "claim"} />
              {action.id === "complete_stage" && <><span aria-hidden="true">↓</span><StageLabel stage={next} /></>}
              {action.id === "complete_production" && <><span aria-hidden="true">↓</span><span className="stage-label"><span>✓</span>Producție finalizată</span></>}
            </div>
            <div className="confirmation-actions">
              <button className="button button-secondary" type="button" disabled={state.status === "submitting"} onClick={() => setState({ status: "idle" })}>Anulează</button>
              <button className="button button-primary" type="button" disabled={state.status === "submitting"} onClick={() => void submit(state.idempotencyKey)}>{state.status === "submitting" ? "Se procesează…" : "Confirmă"}</button>
            </div>
            {state.status === "submitting" && <small className="server-note">Așteptăm confirmarea serverului.</small>}
          </div>
        </div>
      )}
    </section>
  );
}
