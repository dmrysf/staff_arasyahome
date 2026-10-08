import { useEffect, useRef, useState } from "react";
import type { StaffServiceError } from "../../domain/models";
import { qrAuthorityLabels, qrAuthorityModeLabels, qrLabelFilename, qrRevisionStateLabels, qrRotateBlockedCopy, qrRotationReasonLabels, type ProductionQrApi, type ProductionQrView, type QrRotationReason } from "../../domain/productionQr";
import { useLive } from "../../app/liveContext";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });

/** Saves the server-rendered label; nothing is drawn or encoded in the browser. */
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

/**
 * Manager view of the production QR authority of one order: who owns the code, the active QR revision with
 * a server-rendered preview and label download, the revision history and the audited rotation (lost, damaged
 * or compromised label). Every change is server-confirmed; the old code stops working in the same step.
 */
export function ProductionQrPanel({ orderId, service }: { orderId: string; service: ProductionQrApi }) {
  const [view, setView] = useState<ProductionQrView | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [reason, setReason] = useState<QrRotationReason | "">("");
  const [reload, setReload] = useState(0);
  const key = useRef<string | null>(null);
  const liveRevision = useLive().byOrder[orderId] ?? 0;

  useEffect(() => {
    const controller = new AbortController();
    service.inspect(orderId, controller.signal).then((next) => { setView(next); setError(null); }).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [orderId, service, liveRevision, reload]);

  async function rotate() {
    if (!view?.active || !reason || busy) return;
    key.current ??= createIdempotencyKey();
    setBusy(true);
    setError(null);
    try {
      setView(await service.rotate(orderId, { expectedQrRevision: view.active.revision, reason }, key.current));
      key.current = null;
      setConfirming(false);
      setReason("");
    } catch (caught) {
      const failure = toServiceError(caught);
      if (!["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"].includes(failure.code)) key.current = null;
      setError(failure);
      setReload((value) => value + 1);
    } finally { setBusy(false); }
  }

  if (!view) return error ? <section className="detail-section qr-panel" role="alert"><p className="eyebrow">Cod QR de producție</p><p>{getErrorPresentation(error).message}</p></section> : null;
  const active = view.active;
  const arasya = view.qrAuthority === "arasya";
  return (
    <section className="detail-section qr-panel" aria-labelledby="qr-title" data-testid="production-qr">
      <div className="section-title"><p className="eyebrow">Cod QR de producție</p><h2 id="qr-title">{active ? `QR REVIZIA ${active.revision}` : "Fără cod activ"}</h2></div>
      <p className={`qr-authority ${arasya ? "qr-authority-arasya" : "qr-authority-source"}`} role="status">{qrAuthorityLabels[view.qrAuthority]}</p>
      <p className="document-hint">{qrAuthorityModeLabels[view.qrAuthorityMode]}</p>
      {active && <div className="qr-active">
        <img className="qr-preview" src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(active.svg)}`} alt={`Cod QR activ, revizia ${active.revision}`} width={168} height={168} />
        <div className="qr-active-meta">
          <p className="qr-state qr-state-active">Activ · revizia {active.revision}</p>
          <p className="document-meta">Emis {time.format(new Date(active.issuedAt))} · amprentă {active.hint}</p>
          {active.documentRevision !== null && <p className="document-meta">Legat de documentul de producție REVIZIA {active.documentRevision}</p>}
          {arasya && <button className="button button-secondary" type="button" onClick={() => saveSvg(active.svg, qrLabelFilename(view.orderNumber, active.revision))}>Descarcă eticheta QR</button>}
        </div>
      </div>}
      {!arasya && <p className="document-hint">Pentru această comandă YD SOFT tipărește încă propriul cod. Codul Arasya rămâne doar pentru scanarea în Staff.</p>}
      {view.rotate.allowed && !confirming && <button className="button button-secondary" type="button" onClick={() => { setConfirming(true); setReason(""); key.current = null; }}>Înlocuiește codul QR</button>}
      {view.rotate.allowed && confirming && active && <div className="document-actions qr-rotate" role="group" aria-label="Înlocuire cod QR">
        <p className="order-notice order-notice-warning" role="note">Codul QR actual (revizia {active.revision}) nu va mai funcționa pentru producție. Tipărește și lipește eticheta nouă pe comandă.</p>
        <label className="field">Motiv
          <select value={reason} onChange={(event) => setReason(event.target.value as QrRotationReason | "")}>
            <option value="">Alege motivul</option>
            {view.rotate.reasons.map((option) => <option key={option} value={option}>{qrRotationReasonLabels[option]}</option>)}
          </select>
        </label>
        <div className="qr-rotate-buttons">
          <button className="button button-primary" type="button" disabled={!reason || busy} onClick={() => void rotate()}>{busy ? "Se procesează…" : `Emite QR REVIZIA ${active.revision + 1}`}</button>
          <button className="button button-link" type="button" disabled={busy} onClick={() => setConfirming(false)}>Renunță</button>
        </div>
      </div>}
      {!view.rotate.allowed && view.rotate.blockedReason && arasya && <p className="document-hint">{qrRotateBlockedCopy[view.rotate.blockedReason] ?? "Codul QR nu poate fi înlocuit acum."}</p>}
      {view.history.length > 1 && <details className="qr-history">
        <summary>Istoric coduri QR ({view.history.length})</summary>
        <ol>{view.history.map((row) => <li key={row.revision} className={`qr-history-${row.state}`}>
          <strong>Revizia {row.revision}</strong><span>{qrRevisionStateLabels[row.state]}</span>
          <small>{time.format(new Date(row.issuedAt))}{row.retiredAt ? ` → ${time.format(new Date(row.retiredAt))}` : ""} · {row.hint}{row.documentRevision !== null ? ` · document R${row.documentRevision}` : ""}</small>
        </li>)}</ol>
      </details>}
      {error && <p className="order-notice order-notice-warning" role="alert">{getErrorPresentation(error).message}</p>}
    </section>
  );
}
