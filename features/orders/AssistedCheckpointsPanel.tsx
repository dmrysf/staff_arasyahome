import { physicalText as pt } from "../../i18n/physical";
import { useRef, useState } from "react";
import type { AssistedCheckpointPiece, AssistedCheckpoints, StaffOrder } from "../../domain/models";
import { pieceName } from "../../domain/pieces";
import { stageName } from "../../domain/stageNames";
import { isAmbiguous, physicalError, type AssistedCheckpointInput, type StaffPhysicalApi } from "../../domain/physical";
import { createIdempotencyKey } from "../../services/idempotency";

type Carrier = { id: string; data: AssistedCheckpoints; canMark: boolean; apart: boolean };
type Command = { key: string; unitId: string; body: AssistedCheckpointInput };

/**
 * Route policy version 2 (API 2.34.0): at stage 6 / 7 the stage employee may record, per PERDEA piece, whether its
 * auxiliary work was done. The status is information only: it never blocks the stage, and the screen never presents it
 * as a blocker. Each change is saved by the server first (version + idempotency key); a lost answer replays the same key.
 */
export function AssistedCheckpointsPanel({ order, service, supervisor, onChanged }: { order: StaffOrder; service: StaffPhysicalApi; supervisor: boolean; onChanged: () => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [pending, setPending] = useState<Command | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const lock = useRef(false);
  const pieces = order.pieces;
  if (!pieces || !service.assistedCheckpoint) return null;
  // The existing stage authority: the stage's own employee (offered the stage action, or busy because a colleague holds it).
  const orderStageWorker = order.employeeAllowedAction != null || order.employeeActionBlockedReason === "claimed_by_other";
  const carriers: Carrier[] = [
    ...(pieces.assistedCheckpoints ? [{ id: "order", data: pieces.assistedCheckpoints, canMark: supervisor || orderStageWorker, apart: false }] : []),
    ...pieces.tracks.filter(track => track.assistedCheckpoints).map(track => ({ id: track.id, data: track.assistedCheckpoints as AssistedCheckpoints,
      canMark: supervisor || track.allowedAction !== null || track.blockedReason === "claimed_by_other", apart: true })),
  ];
  if (carriers.length === 0) return null;

  async function run(command: Command) {
    if (lock.current || !service.assistedCheckpoint) return;
    lock.current = true; setBusy(command.unitId); setError(null); setSaved(false); setPending(command);
    try {
      await service.assistedCheckpoint(command.unitId, command.body, command.key);
      setPending(null); setSaved(true); onChanged();
    } catch (caught) {
      setError(physicalError(caught));
      if (!isAmbiguous(caught)) { setPending(null); onChanged(); }
    } finally { lock.current = false; setBusy(null); }
  }
  const set = (piece: AssistedCheckpointPiece, status: AssistedCheckpointInput["status"]) =>
    void run({ key: createIdempotencyKey(), unitId: piece.unitId, body: { stageId: piece.stageId, status, expectedVersion: piece.version } });

  return <section className="detail-section assisted-checkpoints" data-testid="assisted-checkpoints">
    {saved && <p className="order-action-done" role="status">✓ {pt("physical.assisted.saved")}</p>}
    {error && <p role="alert">{error}</p>}
    {pending && busy === null && <div className="button-row"><button type="button" className="button button-primary" onClick={() => void run(pending)}>{pt("physical.reincearca_aceeasi_cerere")}</button>
      <button type="button" className="button button-secondary" onClick={() => { setPending(null); setError(null); onChanged(); }}>{pt("physical.reincarca_starea_salvata")}</button></div>}
    {carriers.map(carrier => <article key={carrier.id} className="piece-track card" data-testid="assisted-carrier">
      <h2>{carrier.apart ? pt("physical.assisted.track", { stage: stageName(carrier.data.stageId) }) : pt("physical.assisted.title", { stage: stageName(carrier.data.stageId) })}</h2>
      <p>{pt("physical.assisted.question")} <span className="muted">{pt("physical.assisted.intro")}</span></p>
      {carrier.data.total > 0 && <p className="order-notice order-notice-muted" role="note" data-testid="assisted-summary">{pt("physical.assisted.summary", { performed: carrier.data.performed, notPerformed: carrier.data.notPerformed, unmarked: carrier.data.unmarked })}</p>}
      {carrier.data.unconfirmedLines.map(line => <p key={line.itemId} className="muted" data-testid="assisted-unconfirmed-line">{pt("physical.assisted.unconfirmedLine", { product: line.code ?? line.name })}</p>)}
      <ul className="assisted-list">{carrier.data.pieces.map(piece => {
        const disabled = !carrier.canMark || busy !== null || pending !== null;
        return <li key={piece.unitId} className={`assisted-piece assisted-${piece.status}`} data-testid="assisted-piece" data-status={piece.status}>
          <strong>{piece.code ?? piece.name} · {pieceName(piece)}</strong>
          <span className="queue-chip">{pt(`physical.assisted.status.${piece.status}`)}</span>
          {piece.markedBy && <span>{pt("physical.assisted.markedBy", { name: piece.markedBy.displayName })}</span>}
          {carrier.canMark && <div className="assisted-controls">
            <label className="check-field"><input type="checkbox" checked={piece.status === "performed"} disabled={disabled} aria-busy={busy === piece.unitId || undefined}
              onChange={event => set(piece, event.target.checked ? "performed" : "unmarked")} />{pt("physical.assisted.done")}</label>
            {piece.status !== "not_performed" && <button type="button" className="button button-secondary" disabled={disabled} onClick={() => set(piece, "not_performed")}>{pt("physical.assisted.notDone")}</button>}
          </div>}
        </li>;
      })}</ul>
    </article>)}
  </section>;
}
