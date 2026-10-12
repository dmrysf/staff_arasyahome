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

const copy = {
  intake: { open: "Returnează la Tăiere", title: "Returnează la Tăiere", intro: "Bifează doar produsele tăiate greșit. Responsabilul este stabilit automat din istoricul comenzii.", picker: "Produse cu eroare", total: "Metraj cu eroare", reason: "Motiv", comment: "Comentariu", submit: "Trimite cererea" },
  // Supervised flow: the worker only reports; the tailoring quality supervisor verifies and decides.
  defect: { open: "Raportează o problemă", title: "Raportează o problemă", intro: "Alege produsele afectate și descrie problema. Șeful de croitorie verifică produsul și decide pașii următori.", picker: "Produse afectate", total: "Metraj afectat", reason: "Problema observată", comment: "Descriere", submit: "Raportează șefului de croitorie" },
} as const;

/** Selection of the exact faulty lines, reason and comment; one server-confirmed request. */
export function ReturnToCuttingPanel({ order, service, onReported, onSessionExpired, variant = "intake" }: { order: StaffOrder; service: ExceptionService; onReported: (exceptionId: string) => void; onSessionExpired: () => void; variant?: "intake" | "defect" }) {
  const text = copy[variant];
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
  // The intake-only return needs measured lines; a reported defect may concern a line the source sent without meters.
  const metered = variant === "defect" ? order.products : order.products.filter((item) => item.meters != null && item.meters > 0);
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

  if (!open) return <button className="button button-danger-outline" type="button" onClick={() => setOpen(true)}>{text.open}</button>;
  return (
    <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="return-title">
      <div className="confirmation-card return-card">
        <p className="eyebrow">Comanda #{order.orderNumber}</p>
        <h2 id="return-title">{text.title}</h2>
        <p>{text.intro}</p>
        <fieldset className="fault-picker"><legend>{text.picker}</legend>
          {metered.map((item) => <label key={item.id} className="check-field"><input type="checkbox" checked={selected.has(item.id)} onChange={() => toggle(item.id)} />
            <span><strong>{item.name}</strong><small>{[item.code, item.color].filter(Boolean).join(" · ")}</small></span><b>{item.meters != null && item.meters > 0 ? formatMeters(item.meters) : "fără metraj"}</b></label>)}
        </fieldset>
        <p className="fault-total" role="status">{text.total}: <b>{formatDecimalMeters(selectedMeters(order.products, selected))}</b></p>
        <label className="field"><span>{text.reason}</span><select value={reasonKey} onChange={(event) => { setReasonKey(event.target.value); keyRef.current = null; }}>
          <option value="">Alege motivul</option>{reasons.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</select></label>
        <label className="field"><span>{text.comment} {reason?.requiresComment ? "(obligatoriu)" : "(opțional)"}</span><textarea maxLength={1000} rows={2} value={comment} onChange={(event) => { setComment(event.target.value); keyRef.current = null; }} /></label>
        {error && <ErrorState error={error} compact onAction={() => setError(null)} />}
        <div className="confirmation-actions">
          <button className="button button-secondary" type="button" disabled={submitting} onClick={() => setOpen(false)}>Anulează</button>
          <button className="button button-primary" type="button" disabled={!ready || submitting} aria-busy={submitting || undefined} onClick={() => void submit()}>{submitting ? "Se trimite…" : text.submit}</button>
        </div>
        {submitting && <small className="server-note">Așteptăm confirmarea serverului.</small>}
      </div>
    </div>
  );
}
