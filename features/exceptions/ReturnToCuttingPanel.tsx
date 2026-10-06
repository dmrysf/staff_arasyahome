import { useEffect, useRef, useState } from "react";
import type { FaultReason, StaffOrder, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { formatDecimalMeters, selectedMeters } from "../../domain/faults";
import { formatMeters } from "../../domain/productFormat";

export const DETECTION_STAGE_ID = "workshop-receiving";

/** Shown only to the tailoring intake employee who accepted the order and may complete its stage. */
export function canReturnToCutting(order: StaffOrder, permissions: readonly string[]): boolean {
  return order.productionStageId === DETECTION_STAGE_ID && order.employeeAllowedAction?.id === "complete_stage"
    && !order.productionQuality?.openException && permissions.includes("orders.report_fault");
}

/** Selection of the exact faulty lines, reason and comment; one server-confirmed request. */
export function ReturnToCuttingPanel({ order, service, onReported, onSessionExpired }: { order: StaffOrder; service: ExceptionService; onReported: (exceptionId: string) => void; onSessionExpired: () => void }) {
  const [open, setOpen] = useState(false);
  const [reasons, setReasons] = useState<FaultReason[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [reasonKey, setReasonKey] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const keyRef = useRef<string | null>(null);

  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    service.reasons({ signal: controller.signal }).then(setReasons).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [open, service]);

  const reason = reasons.find((item) => item.key === reasonKey);
  const metered = order.products.filter((item) => item.meters != null && item.meters > 0);
  const commentMissing = Boolean(reason?.requiresComment) && comment.trim().length < 3;
  const ready = selected.size > 0 && reason && !commentMissing;
  const toggle = (id: string) => setSelected((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); keyRef.current = null; return next; });

  async function submit() {
    if (!ready || submitting) return;
    keyRef.current ??= createIdempotencyKey();
    setSubmitting(true);
    setError(null);
    try {
      const created = await service.report(order.id, { expectedVersion: order.productionVersion, itemIds: [...selected], reasonKey, comment: comment.trim() || null, idempotencyKey: keyRef.current });
      keyRef.current = null;
      setOpen(false);
      onReported(created.id);
    } catch (caught) {
      const failure = toServiceError(caught);
      if (failure.code === "SESSION_EXPIRED" || failure.code === "NO_SESSION") onSessionExpired();
      if (!["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(failure.code)) keyRef.current = null;
      setError(failure);
    } finally { setSubmitting(false); }
  }

  if (!open) return <button className="button button-danger-outline" type="button" onClick={() => setOpen(true)}>Returnează la Tăiere</button>;
  return (
    <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="return-title">
      <div className="confirmation-card return-card">
        <p className="eyebrow">Comanda #{order.orderNumber}</p>
        <h2 id="return-title">Returnează la Tăiere</h2>
        <p>Bifează doar produsele tăiate greșit. Responsabilul este stabilit automat din istoricul comenzii.</p>
        <fieldset className="fault-picker"><legend>Produse cu eroare</legend>
          {metered.map((item) => <label key={item.id} className="check-field"><input type="checkbox" checked={selected.has(item.id)} onChange={() => toggle(item.id)} />
            <span><strong>{item.name}</strong><small>{[item.code, item.color].filter(Boolean).join(" · ")}</small></span><b>{formatMeters(item.meters!)}</b></label>)}
        </fieldset>
        <p className="fault-total" role="status">Metraj cu eroare: <b>{formatDecimalMeters(selectedMeters(order.products, selected))}</b></p>
        <label className="field"><span>Motiv</span><select value={reasonKey} onChange={(event) => { setReasonKey(event.target.value); keyRef.current = null; }}>
          <option value="">Alege motivul</option>{reasons.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>
        <label className="field"><span>{reason?.requiresComment ? "Comentariu (obligatoriu)" : "Comentariu (opțional)"}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => { setComment(event.target.value); keyRef.current = null; }} /></label>
        {error && <ErrorState error={error} compact onAction={() => setError(null)} />}
        <div className="confirmation-actions">
          <button className="button button-secondary" type="button" disabled={submitting} onClick={() => setOpen(false)}>Anulează</button>
          <button className="button button-primary" type="button" disabled={!ready || submitting} onClick={() => void submit()}>{submitting ? "Se trimite…" : "Trimite cererea"}</button>
        </div>
        {submitting && <small className="server-note">Așteptăm confirmarea serverului.</small>}
      </div>
    </div>
  );
}
