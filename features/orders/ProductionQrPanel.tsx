import { useEffect, useRef, useState } from "react";
import { formatDate, useI18n } from "../../i18n";
import type { StaffServiceError } from "../../domain/models";
import { qrAuthorityLabels, qrAuthorityModeLabels, qrLabelFilename, qrRevisionStateLabels, qrRotateBlockedCopy, qrRotationReasonLabels, type ProductionQrApi, type ProductionQrView, type QrRotationReason } from "../../domain/productionQr";
import { useLive } from "../../app/liveContext";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

const time = { format: (value: Date): string => formatDate(value, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) };

/** Saves the server-rendered label; nothing is drawn or encoded in the browser. */
function saveSvg(svg: string, filename: string) {
  // The QR path remains server-rendered; a simulation export adds an unmistakable physical label.
  const output = import.meta.env?.MODE === "simulation" ? `<svg xmlns="http://www.w3.org/2000/svg" width="240" height="280" viewBox="0 0 240 280"><rect width="240" height="280" fill="white"/><text x="120" y="20" text-anchor="middle" font-family="sans-serif" font-size="14" font-weight="bold">SIMULARE / TEST</text><svg x="10" y="35" width="220" height="220" ${svg.slice(svg.indexOf("viewBox"))}</svg>` : svg;
  const url = URL.createObjectURL(new Blob([output], { type: "image/svg+xml" }));
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
  const { t } = useI18n();
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

  if (!view) return error ? <section className="detail-section qr-panel" role="alert"><p className="eyebrow">{t("qrp.eyebrow")}</p><p>{getErrorPresentation(error).message}</p></section> : null;
  const active = view.active;
  const arasya = view.qrAuthority === "arasya";
  return (
    <section className="detail-section qr-panel" aria-labelledby="qr-title" data-testid="production-qr">
      <div className="section-title"><p className="eyebrow">{t("qrp.eyebrow")}</p><h2 id="qr-title">{active ? t("qrp.revisionTitle", { number: active.revision }) : t("qrp.noActive")}</h2></div>
      <p className={`qr-authority ${arasya ? "qr-authority-arasya" : "qr-authority-source"}`} role="status">{qrAuthorityLabels[view.qrAuthority]}</p>
      <p className="document-hint">{qrAuthorityModeLabels[view.qrAuthorityMode]}</p>
      {active && <div className="qr-active">
        <img className="qr-preview" src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(active.svg)}`} alt={t("qrp.imageAlt", { number: active.revision })} width={168} height={168} />
        <div className="qr-active-meta">
          <p className="qr-state qr-state-active">{t("qrp.activeRevision", { number: active.revision })}</p>
          <p className="document-meta">{t("qrp.issued", { date: time.format(new Date(active.issuedAt)), hint: active.hint })}</p>
          {active.documentRevision !== null && <p className="document-meta">{t("qrp.linkedDocument", { number: active.documentRevision })}</p>}
          {arasya && <button className="button button-secondary" type="button" onClick={() => saveSvg(active.svg, qrLabelFilename(view.orderNumber, active.revision))}>{t("qrp.download")}</button>}
        </div>
      </div>}
      {!arasya && <p className="document-hint">{t("qrp.sourcePrints")}</p>}
      {view.rotate.allowed && !confirming && <button className="button button-secondary" type="button" onClick={() => { setConfirming(true); setReason(""); key.current = null; }}>{t("qrp.replace")}</button>}
      {view.rotate.allowed && confirming && active && <div className="document-actions qr-rotate" role="group" aria-label={t("qrp.replaceGroup")}>
        <p className="order-notice order-notice-warning" role="note">{t("qrp.replaceWarning", { number: active.revision })}</p>
        <label className="field">{t("common.reason")}
          <select value={reason} onChange={(event) => setReason(event.target.value as QrRotationReason | "")}>
            <option value="">{t("common.chooseReason")}</option>
            {view.rotate.reasons.map((option) => <option key={option} value={option}>{qrRotationReasonLabels[option]}</option>)}
          </select>
        </label>
        <div className="qr-rotate-buttons">
          <button className="button button-primary" type="button" disabled={!reason || busy} onClick={() => void rotate()}>{busy ? t("common.processing") : t("qrp.issue", { number: active.revision + 1 })}</button>
          <button className="button button-link" type="button" disabled={busy} onClick={() => setConfirming(false)}>{t("common.renounce")}</button>
        </div>
      </div>}
      {!view.rotate.allowed && view.rotate.blockedReason && arasya && <p className="document-hint">{Object.hasOwn(qrRotateBlockedCopy, view.rotate.blockedReason) ? qrRotateBlockedCopy[view.rotate.blockedReason] : t("qrp.blocked.default")}</p>}
      {view.history.length > 1 && <details className="qr-history">
        <summary>{t("qrp.historyTitle", { count: view.history.length })}</summary>
        <ol>{view.history.map((row) => <li key={row.revision} className={`qr-history-${row.state}`}>
          <strong>{t("qrp.revisionRow", { number: row.revision })}</strong><span>{qrRevisionStateLabels[row.state]}</span>
          <small>{time.format(new Date(row.issuedAt))}{row.retiredAt ? ` → ${time.format(new Date(row.retiredAt))}` : ""} · {row.hint}{row.documentRevision !== null ? ` · document R${row.documentRevision}` : ""}</small>
        </li>)}</ol>
      </details>}
      {error && <p className="order-notice order-notice-warning" role="alert">{getErrorPresentation(error).message}</p>}
    </section>
  );
}
