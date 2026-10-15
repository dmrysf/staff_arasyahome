import { physicalText as pt } from "../../i18n/physical";
import { useRef, useState } from "react";
import type { AssistedCheckpointPiece, AssistedCheckpoints, StaffOrder } from "../../domain/models";
import { pieceName } from "../../domain/pieces";
import { stageName } from "../../domain/stageNames";
import { isAmbiguous, physicalError, type AssistedCheckpointInput, type StaffPhysicalApi } from "../../domain/physical";
import { createIdempotencyKey } from "../../services/idempotency";
import { QrCapture } from "../exceptions/QrCapture";

type Carrier = { id: string; data: AssistedCheckpoints; canVerify: boolean; apart: boolean };
type Choice = { piece: AssistedCheckpointPiece; action: AssistedCheckpointInput["action"]; outcome?: "performed" | "not_performed" };
type Command = { key: string; unitId: string; body: AssistedCheckpointInput };

/**
 * Route policy version 2 (API 2.34.0): every PERDEA piece at stage 6 / 7 has its own assisted check. A helper takes the
 * curtain and hands it back; the stage employee checks every piece and records the result. Each action needs the piece
 * scan and an explicit confirmation; the server decides who may do what and whether the stage may complete.
 */
export function AssistedCheckpointsPanel({ order, service, supervisor, onChanged }: { order: StaffOrder; service: StaffPhysicalApi; supervisor: boolean; onChanged: () => void }) {
  const [choice, setChoice] = useState<Choice | null>(null);
  const [qr, setQr] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Command | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const lock = useRef(false);
  const pieces = order.pieces;
  if (!pieces || !service.assistedCheckpoint) return null;
  // The stage employee of the order (or of a track) is the one the server offers the stage action or reports as busy elsewhere.
  const orderStageWorker = order.employeeAllowedAction != null || order.employeeActionBlockedReason === "claimed_by_other";
  const carriers: Carrier[] = [
    ...(pieces.assistedCheckpoints ? [{ id: "order", data: pieces.assistedCheckpoints, canVerify: supervisor || orderStageWorker, apart: false }] : []),
    ...pieces.tracks.filter(track => track.assistedCheckpoints).map(track => ({ id: track.id, data: track.assistedCheckpoints as AssistedCheckpoints,
      canVerify: supervisor || track.allowedAction !== null || track.blockedReason === "claimed_by_other", apart: true })),
  ];
  if (carriers.length === 0) return null;
  const product = (itemId: string) => order.products.find(item => item.id === itemId);
  // Other products travelling with the order at this stage keep their ordinary production work.
  const checked = new Set(pieces.assistedCheckpoints?.pieces.map(piece => piece.unitId) ?? []);
  const manufacturing = pieces.assistedCheckpoints ? pieces.units.filter(unit => unit.trackId === null && !checked.has(unit.id)) : [];

  async function run(command: Command) {
    if (lock.current || !service.assistedCheckpoint) return;
    lock.current = true; setBusy(true); setError(null); setSaved(false); setPending(command);
    try {
      await service.assistedCheckpoint(command.unitId, command.body, command.key);
      setPending(null); setChoice(null); setQr(""); setConfirmed(false); setSaved(true); onChanged();
    } catch (caught) {
      setError(physicalError(caught));
      // Only an unconfirmed answer keeps the same key: a retry replays the same intent, never a second action.
      if (!isAmbiguous(caught)) setPending(null);
    } finally { lock.current = false; setBusy(false); }
  }
  function submit() {
    if (!choice || !qr || !confirmed) return;
    void run({ key: createIdempotencyKey(), unitId: choice.piece.unitId, body: { action: choice.action, stageId: choice.piece.stageId, expectedVersion: choice.piece.version, qrToken: qr, ...(choice.outcome ? { outcome: choice.outcome } : {}) } });
  }
  const open = (next: Choice) => { setChoice(next); setQr(""); setConfirmed(false); setError(null); setSaved(false); };
  const actionLabel = (value: Choice) => value.action === "take" ? pt("physical.assisted.take") : value.action === "return" ? pt("physical.assisted.return")
    : value.outcome === "performed" ? pt("physical.assisted.verifyPerformed") : pt("physical.assisted.verifyNotPerformed");

  return <section className="detail-section assisted-checkpoints" data-testid="assisted-checkpoints">
    {saved && <p className="order-action-done" role="status">✓ {pt("physical.assisted.saved")}</p>}
    {error && !choice && <p role="alert">{error}</p>}
    {pending && !busy && !choice && <div className="button-row"><button type="button" className="button button-primary" onClick={() => void run(pending)}>{pt("physical.reincearca_aceeasi_cerere")}</button>
      <button type="button" className="button button-secondary" onClick={() => { setPending(null); setError(null); onChanged(); }}>{pt("physical.reincarca_starea_salvata")}</button></div>}
    {carriers.map(carrier => <article key={carrier.id} className="piece-track card" data-testid="assisted-carrier">
      <h2>{carrier.apart ? pt("physical.assisted.track", { stage: stageName(carrier.data.stageId) }) : pt("physical.assisted.title", { stage: stageName(carrier.data.stageId) })}</h2>
      <p>{pt("physical.assisted.intro")}</p>
      {carrier.data.planRequired
        ? <p className="order-notice order-notice-warning" role="note">{pt("physical.assisted.planRequired")}</p>
        : <p className={`order-notice ${carrier.data.verified < carrier.data.required ? "order-notice-warning" : "order-notice-muted"}`} role="note" data-testid="assisted-progress">
          {pt("physical.assisted.progress", { verified: carrier.data.verified, required: carrier.data.required })} · {carrier.data.verified < carrier.data.required ? pt("physical.assisted.blocked") : pt("physical.assisted.ready")}</p>}
      {!carrier.apart && manufacturing.length > 0 && <ul className="piece-list">{manufacturing.map(unit => <li key={unit.id}>{pt("physical.assisted.manufacturing", { product: `${pieceName(unit)} · ${product(unit.itemId)?.code ?? product(unit.itemId)?.name ?? ""}` })}</li>)}</ul>}
      <ul className="assisted-list">{carrier.data.pieces.map(piece => {
        const offers: Choice[] = [];
        if (piece.status === "awaiting_work" || piece.status === "not_performed") offers.push({ piece, action: "take" });
        if (piece.status === "taken_for_work" && piece.helper?.isMe) offers.push({ piece, action: "return" });
        const ownWork = piece.helper?.isMe === true && (piece.status === "work_returned" || piece.status === "verified");
        if (carrier.canVerify && piece.status !== "taken_for_work" && !ownWork) {
          if (piece.status !== "verified") offers.push({ piece, action: "verify", outcome: "performed" });
          if (piece.status !== "not_performed") offers.push({ piece, action: "verify", outcome: "not_performed" });
        }
        return <li key={piece.unitId} className={`assisted-piece assisted-${piece.status}`} data-testid="assisted-piece" data-status={piece.status}>
          <strong>{piece.code ?? piece.name} · {pieceName(piece)}</strong>
          <span className="queue-chip">{pt(`physical.assisted.status.${piece.status}`)}</span>
          {piece.helper && <span>{pt("physical.assisted.helper", { name: piece.helper.displayName })}</span>}
          {piece.verifiedBy && <span>{pt("physical.assisted.verifiedBy", { name: piece.verifiedBy.displayName })}</span>}
          {offers.length > 0 && <div className="button-row piece-actions">{offers.map(offer => <button key={`${offer.action}-${offer.outcome ?? ""}`} type="button"
            className={`button ${offer.outcome === "not_performed" ? "button-secondary" : "button-primary"}`} disabled={busy || pending !== null} onClick={() => open(offer)}>{actionLabel(offer)}</button>)}</div>}
        </li>;
      })}</ul>
    </article>)}
    {choice && <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="assisted-confirm-title"><div className="confirmation-card dialog-body" data-testid="assisted-dialog">
      <h2 id="assisted-confirm-title">{actionLabel(choice)}</h2>
      <p>{choice.piece.code ?? choice.piece.name} · {pieceName(choice.piece)} · {stageName(choice.piece.stageId)}</p>
      <fieldset disabled={busy || pending !== null}>
        {qr ? <p>{pt("physical.assisted.scanned")}</p> : <><p>{pt("physical.assisted.scan")}</p><QrCapture disabled={busy} onToken={setQr} /></>}
        <label><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />{pt("physical.assisted.confirm", { stage: stageName(choice.piece.stageId) })}</label>
      </fieldset>
      {error && <p role="alert">{error}</p>}
      {busy && <p role="status">{pt("physical.asteapta_confirmarea_serverului")}</p>}
      <div className="confirmation-actions dialog-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={() => { setChoice(null); if (pending) onChanged(); }}>{pt("physical.assisted.cancel")}</button>
        {pending && !busy
          ? <button type="button" className="button button-primary" onClick={() => void run(pending)}>{pt("physical.reincearca_aceeasi_cerere")}</button>
          : <button type="button" className="button button-primary" disabled={busy || !qr || !confirmed} onClick={submit}>{busy ? pt("physical.asteapta_confirmarea_serverului") : pt("physical.assisted.submit")}</button>}
      </div>
    </div></div>}
  </section>;
}
