"use client";

import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from "react";
import type { OrderService } from "../../services/contracts";
import type { StaffOrder, StaffServiceError } from "../../domain/models";
import { StaffServiceError as ServiceError } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { createDuplicateGuard } from "./duplicateGuard";
import { initialScannerState, scannerReducer } from "./machine";
import { mapCameraError, toServiceError } from "../../services/errors";

type TorchCapabilities = MediaTrackCapabilities & { torch?: boolean };
type TorchConstraintSet = MediaTrackConstraintSet & { torch?: boolean };

function requestId() {
  return globalThis.crypto?.randomUUID?.() ?? `staff-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function ReviewContent({ order }: { order: StaffOrder }) {
  const item = order.products[0];
  return (
    <>
      <div className="sheet-handle" />
      <div className="review-heading"><div><SourceBadge source={order.source} /><h2>Comanda #{order.orderNumber}</h2></div><span className="verified-mark" aria-label="Comandă verificată">✓</span></div>
      <div className="review-product"><p className="eyebrow">Produs</p><strong>{item.name}</strong><span>{item.code}</span><dl>{item.color && <div><dt>Culoare</dt><dd>{item.color}</dd></div>}{item.dimensions && <div><dt>Dimensiune</dt><dd>{item.dimensions}</dd></div>}{item.meters != null && <div><dt>Cantitate</dt><dd>{item.meters} m</dd></div>}</dl></div>
      <div className="stage-transition"><div><small>Etapa actuală</small><StageLabel stage={order.currentStage} muted /></div>{order.nextStage && <><span aria-hidden="true">↓</span><div><small>Următoarea etapă</small><StageLabel stage={order.nextStage} /></div></>}</div>
    </>
  );
}

export function ScannerScreen({ service, demoMode, navigate, onSessionExpired }: { service: OrderService; demoMode: boolean; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const [state, dispatch] = useReducer(scannerReducer, initialScannerState);
  const [manualOpen, setManualOpen] = useState(false);
  const [manualCode, setManualCode] = useState("");
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const animationRef = useRef<number | null>(null);
  const detectorRef = useRef<BarcodeDetector | null>(null);
  const duplicateGuard = useMemo(() => createDuplicateGuard(), []);
  const submittingRef = useRef(false);

  const stopCamera = useCallback(() => {
    if (animationRef.current != null) cancelAnimationFrame(animationRef.current);
    animationRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  useEffect(() => stopCamera, [stopCamera]);

  const resolveCode = useCallback(async (token: string, manual = false) => {
    const normalized = token.trim();
    if (!normalized || !duplicateGuard.accept(normalized)) return;
    stopCamera();
    dispatch({ type: "CODE_DETECTED", token: normalized });
    dispatch({ type: "RESOLVE_STARTED" });
    try {
      const order = manual ? await service.lookup(normalized) : await service.resolveQr(normalized);
      navigator.vibrate?.(35);
      dispatch({ type: "ORDER_RESOLVED", order });
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED") onSessionExpired();
      dispatch({ type: "RESOLVE_FAILED", error });
    }
  }, [duplicateGuard, onSessionExpired, service, stopCamera]);

  useEffect(() => {
    if (state.status !== "scanning" || typeof BarcodeDetector === "undefined") return;
    detectorRef.current ??= new BarcodeDetector({ formats: ["qr_code"] });
    let active = true;
    let lastCheck = 0;
    const scanFrame = async (timestamp: number) => {
      if (!active || state.status !== "scanning") return;
      if (timestamp - lastCheck > 180 && videoRef.current?.readyState === HTMLMediaElement.HAVE_ENOUGH_DATA) {
        lastCheck = timestamp;
        try {
          const results = await detectorRef.current?.detect(videoRef.current);
          const value = results?.[0]?.rawValue;
          if (value) { active = false; void resolveCode(value); return; }
        } catch { /* a transient frame decode failure is safe to ignore */ }
      }
      animationRef.current = requestAnimationFrame(scanFrame);
    };
    animationRef.current = requestAnimationFrame(scanFrame);
    return () => { active = false; if (animationRef.current != null) cancelAnimationFrame(animationRef.current); };
  }, [resolveCode, state.status]);

  async function startCamera() {
    if (state.status === "requesting_permission" || state.status === "scanning" || streamRef.current) return;
    setManualOpen(false);
    duplicateGuard.reset();
    dispatch({ type: "REQUEST_CAMERA" });
    try {
      if (!navigator.mediaDevices?.getUserMedia) throw new ServiceError("CAMERA_UNAVAILABLE");
      const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: "environment" } } });
      streamRef.current = stream;
      if (videoRef.current) { videoRef.current.srcObject = stream; await videoRef.current.play(); }
      const track = stream.getVideoTracks()[0];
      const torchSupported = Boolean((track?.getCapabilities?.() as TorchCapabilities | undefined)?.torch);
      dispatch({ type: "CAMERA_READY", torchSupported });
    } catch (caught) {
      stopCamera();
      const error = caught instanceof ServiceError ? caught : mapCameraError(caught as { name?: string });
      dispatch({ type: "CAMERA_FAILED", error });
    }
  }

  async function toggleTorch() {
    if (state.status !== "scanning" || !state.torchSupported) return;
    const enabled = !state.torchOn;
    try {
      await streamRef.current?.getVideoTracks()[0]?.applyConstraints({ advanced: [{ torch: enabled } as TorchConstraintSet] });
      dispatch({ type: "TORCH_CHANGED", enabled });
    } catch { /* capability may disappear after an orientation/device change */ }
  }

  function reset() {
    stopCamera();
    submittingRef.current = false;
    duplicateGuard.reset();
    setManualOpen(false);
    setManualCode("");
    dispatch({ type: "RESET" });
  }

  async function submit() {
    if (state.status !== "confirming" || submittingRef.current) return;
    submittingRef.current = true;
    const idempotencyKey = requestId();
    const order = state.order;
    dispatch({ type: "SUBMIT", idempotencyKey });
    try {
      const updated = await service.confirmStageTransition(order.id, { expectedVersion: order.version, idempotencyKey });
      dispatch({ type: "SUBMIT_SUCCEEDED", order: updated });
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED") onSessionExpired();
      dispatch({ type: "SUBMIT_FAILED", error });
    } finally { submittingRef.current = false; }
  }

  const busy = state.status === "submitting";

  return (
    <main className="scanner-screen">
      <div className={`camera-view${["review", "confirming", "submitting", "success", "error"].includes(state.status) ? " camera-dimmed" : ""}`}>
        <video ref={videoRef} muted playsInline aria-label="Imagine cameră pentru scanare QR" />
        <div className="camera-fallback" aria-hidden="true" />
        <header className="scanner-toolbar"><button type="button" disabled={busy} onClick={() => { stopCamera(); navigate("/"); }} aria-label="Înapoi la pagina principală">←</button><span>Scanare QR</span>{state.status === "scanning" && state.torchSupported ? <button type="button" className={state.torchOn ? "active" : ""} onClick={toggleTorch} aria-label={state.torchOn ? "Oprește lanterna" : "Pornește lanterna"}>☼</button> : <span />}</header>
        <div className="scanner-target" aria-hidden="true"><i /><i /><i /><i /><span /></div>
        <div className="scanner-instruction"><strong>{state.status === "requesting_permission" ? "Se deschide camera…" : state.status === "scanning" ? "Aliniază codul QR în cadru" : "Scanează rapid și sigur"}</strong><span>{state.status === "scanning" && typeof BarcodeDetector === "undefined" ? "Introdu codul manual pe acest dispozitiv." : "Camera pornește numai când alegi tu."}</span></div>
        {state.status === "idle" && !manualOpen && <div className="camera-start"><button className="button button-primary button-large" type="button" onClick={startCamera}>Deschide camera</button>{demoMode && <button className="button button-demo" type="button" onClick={() => resolveCode("arasya:61833")}>Previzualizează scanare demo</button>}<button className="manual-link" type="button" onClick={() => setManualOpen(true)}>Introdu manual numărul / codul comenzii</button></div>}
        {manualOpen && state.status === "idle" && <form className="manual-panel" onSubmit={(event) => { event.preventDefault(); void resolveCode(manualCode, true); }}><div className="sheet-handle" /><label className="field"><span>Număr / cod comandă</span><input inputMode="text" value={manualCode} onChange={(event) => setManualCode(event.target.value)} placeholder="Ex: 61833" /></label><button className="button button-primary" type="submit" disabled={!manualCode.trim()}>Caută comanda</button><button className="button button-link" type="button" onClick={() => setManualOpen(false)}>Anulează</button></form>}
        {state.status === "resolving" && <div className="resolving-card" role="status"><span className="inline-spinner" /><strong>Se verifică comanda…</strong><small>Nu închide această fereastră.</small></div>}
      </div>

      {state.status === "review" && <section className="bottom-sheet review-sheet"><ReviewContent order={state.order} /><button className="button button-primary button-large" type="button" onClick={() => dispatch({ type: "OPEN_CONFIRMATION" })}>{state.order.employeeAllowedAction?.label ?? "Continuă"}<span aria-hidden="true">→</span></button><button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${state.order.id}`)}>Vezi detalii</button><button className="button button-link" type="button" onClick={reset}>Scanează alt cod</button></section>}

      {(state.status === "confirming" || state.status === "submitting") && <section className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="confirmation-title"><div className="confirmation-card"><span className="state-icon confirm-icon" aria-hidden="true">?</span><p className="eyebrow">Confirmare necesară</p><h2 id="confirmation-title">Confirmați preluarea?</h2><p>Comanda #{state.order.orderNumber}</p><div className="confirm-transition"><StageLabel stage={state.order.currentStage} muted /><span aria-hidden="true">↓</span>{state.order.nextStage && <StageLabel stage={state.order.nextStage} />}</div><div className="confirmation-actions"><button className="button button-secondary" type="button" disabled={busy} onClick={() => dispatch({ type: "CANCEL_CONFIRMATION" })}>Anulează</button><button className="button button-primary" type="button" disabled={busy} onClick={submit}>{busy ? "Se procesează…" : "Confirmă"}</button></div>{busy && <small className="server-note">Așteptăm confirmarea serverului.</small>}</div></section>}

      {state.status === "success" && <section className="success-state"><span className="success-check" aria-hidden="true">✓</span><p className="eyebrow">Actualizare confirmată</p><h2>Comanda a fost actualizată</h2><StageLabel stage={state.order.currentStage} /><div><button className="button button-primary button-large" type="button" onClick={reset}>Scanează altă comandă</button><button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${state.order.id}`)}>Vezi comanda</button></div></section>}

      {state.status === "error" && <section className="bottom-sheet error-sheet"><ErrorState error={state.error as StaffServiceError} compact onAction={() => { if (state.recovery === "login") onSessionExpired(); else { reset(); if (state.recovery === "manual") setManualOpen(true); } }} /><button className="button button-link" type="button" onClick={() => navigate("/")}>Înapoi acasă</button></section>}
    </main>
  );
}
