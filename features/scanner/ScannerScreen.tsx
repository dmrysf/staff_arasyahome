import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from "react";
import type { OrderService } from "../../services/contracts";
import type { ProductionItem, ProductionWorkflow, StaffOrder } from "../../domain/models";
import { StaffServiceError as ServiceError } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { OrderNotices } from "../../components/OrderNotices";
import { createDuplicateGuard } from "./duplicateGuard";
import { initialScannerState, isTransient, scannerReducer } from "./machine";
import { mapCameraError, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { getUsableProductionProducts, requireProductionProducts } from "../../domain/orderValidation";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { orderActionConfirmation, orderActionSuccess } from "../../domain/orderActions";
import { selectQrDecoder, type QrDecoder } from "./qrDecoder";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";
import { AppIcon } from "../../components/icons/AppIcon";
import { getNextStage, getStageById } from "../../domain/productionWorkflow";
import type { CuttingApi } from "../../domain/cutting";
import { QrCapture } from "../exceptions/QrCapture";

type TorchCapabilities = MediaTrackCapabilities & { torch?: boolean };
type TorchConstraintSet = MediaTrackConstraintSet & { torch?: boolean };

function ReviewContent({ order, item, workflow }: { order: StaffOrder; item: ProductionItem; workflow: ProductionWorkflow }) {
  const currentStage = getStageById(workflow, order.productionStageId);
  const nextStage = order.employeeAllowedAction?.id === "complete_stage" ? getNextStage(workflow, order.productionStageId) : undefined;
  const products = getUsableProductionProducts(order);
  const size = formatMeasurements(item);
  return (
    <>
      <div className="sheet-handle" />
      <div className="review-heading"><div><SourceBadge source={order.source} /><h2>Comanda #{order.orderNumber}</h2></div><span className="verified-mark" aria-label="Comandă verificată">✓</span></div>
      <div className="review-product"><p className="eyebrow">{products.length > 1 ? `Produs 1 din ${products.length}` : "Produs"}</p><strong>{item.name}</strong>{item.code && <span>{item.code}</span>}<dl>{item.color && <div><dt>Culoare</dt><dd>{item.color}</dd></div>}{size && <div><dt>Dimensiune</dt><dd>{size}</dd></div>}{item.meters != null && <div><dt>Metri</dt><dd>{formatMeters(item.meters)}</dd></div>}<div><dt>Cantitate</dt><dd>{item.quantity}</dd></div></dl></div>
      <div className="stage-transition"><div><small>Etapa actuală</small><StageLabel stage={currentStage} muted={Boolean(nextStage)} /></div>{nextStage && <><span aria-hidden="true">↓</span><div><small>Următoarea etapă</small><StageLabel stage={nextStage} /></div></>}</div>
      <OrderNotices order={order} />
    </>
  );
}

export function ScannerScreen({ service, cutting, workflow, mode, navigate, onSessionExpired }: { service: OrderService; cutting?: CuttingApi; workflow: ProductionWorkflow; mode: StaffRuntimeMode; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const [state, dispatch] = useReducer(scannerReducer, initialScannerState);
  const [manualOpen, setManualOpen] = useState(false);
  const [manualCode, setManualCode] = useState("");
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const animationRef = useRef<number | null>(null);
  const decoderRef = useRef<QrDecoder | null>(null);
  const cameraAttemptRef = useRef(0);
  const cameraStartingRef = useRef(false);
  const duplicateGuard = useMemo(() => createDuplicateGuard(), []);
  const submittingRef = useRef(false);
  const [claimQr, setClaimQr] = useState("");
  const [ownedCount, setOwnedCount] = useState<number | null>(null);
  const [confirmedMultiple, setConfirmedMultiple] = useState(false);
  const confirmingCutting = state.status === "confirming" && state.order.productionStageId === "material-preparation" && state.order.employeeAllowedAction?.id === "claim";
  useEffect(() => {
    if (!confirmingCutting || !cutting) return;
    const controller = new AbortController();
    cutting.pool(controller.signal).then((pool) => setOwnedCount(pool.ownedCount), () => { if (!controller.signal.aborted) setOwnedCount(null); });
    return () => controller.abort();
  }, [confirmingCutting, cutting]);

  const stopCamera = useCallback(() => {
    cameraAttemptRef.current += 1;
    cameraStartingRef.current = false;
    if (animationRef.current != null) cancelAnimationFrame(animationRef.current);
    animationRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
    decoderRef.current?.dispose?.();
    decoderRef.current = null;
  }, []);

  useEffect(() => stopCamera, [stopCamera]);

  const resolveCode = useCallback(async (token: string, manual = false) => {
    const normalized = token.trim();
    if (!normalized || !duplicateGuard.accept(normalized)) return;
    stopCamera();
    dispatch({ type: "CODE_DETECTED", token: normalized });
    dispatch({ type: "RESOLVE_STARTED" });
    try {
      const resolved = manual ? await service.lookup(normalized) : await service.resolveQr(normalized);
      const order = requireProductionProducts(resolved);
      setClaimQr(manual ? "" : normalized);
      navigator.vibrate?.(35);
      dispatch({ type: "ORDER_RESOLVED", order });
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION") onSessionExpired();
      dispatch({ type: "RESOLVE_FAILED", error });
    }
  }, [duplicateGuard, onSessionExpired, service, stopCamera]);

  useEffect(() => {
    if (state.status !== "scanning" || !decoderRef.current) return;
    let active = true;
    let lastCheck = 0;
    let decoding = false;
    const interval = decoderRef.current.kind === "fallback" ? 260 : 180;
    const scanFrame = async (timestamp: number) => {
      if (!active) return;
      if (!decoding && timestamp - lastCheck > interval && videoRef.current?.readyState === HTMLMediaElement.HAVE_ENOUGH_DATA) {
        lastCheck = timestamp;
        decoding = true;
        try {
          const value = await decoderRef.current?.detect(videoRef.current);
          if (value && active) { active = false; void resolveCode(value); return; }
        } catch { /* a transient frame decode failure is safe to ignore */ }
        finally { decoding = false; }
      }
      if (active) animationRef.current = requestAnimationFrame(scanFrame);
    };
    animationRef.current = requestAnimationFrame(scanFrame);
    return () => { active = false; if (animationRef.current != null) cancelAnimationFrame(animationRef.current); };
  }, [resolveCode, state.status]);

  async function startCamera() {
    if (cameraStartingRef.current || state.status === "requesting_permission" || state.status === "scanning" || streamRef.current) return;
    cameraStartingRef.current = true;
    const attempt = cameraAttemptRef.current + 1;
    cameraAttemptRef.current = attempt;
    setManualOpen(false);
    duplicateGuard.reset();
    dispatch({ type: "REQUEST_CAMERA" });
    try {
      if (!navigator.mediaDevices?.getUserMedia) throw new ServiceError("CAMERA_UNAVAILABLE");
      const decoder = await selectQrDecoder();
      if (!decoder) throw new ServiceError("AUTOMATIC_SCAN_UNAVAILABLE");
      if (attempt !== cameraAttemptRef.current) { decoder.dispose?.(); return; }
      decoderRef.current = decoder;
      const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } } });
      if (attempt !== cameraAttemptRef.current) { stream.getTracks().forEach((track) => track.stop()); return; }
      streamRef.current = stream;
      if (videoRef.current) { videoRef.current.srcObject = stream; await videoRef.current.play(); }
      const track = stream.getVideoTracks()[0];
      const torchSupported = Boolean((track?.getCapabilities?.() as TorchCapabilities | undefined)?.torch);
      dispatch({ type: "CAMERA_READY", torchSupported });
      cameraStartingRef.current = false;
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
    setClaimQr("");
    setOwnedCount(null);
    setConfirmedMultiple(false);
    dispatch({ type: "RESET" });
  }

  async function perform(order: StaffOrder, idempotencyKey: string) {
    const action = order.employeeAllowedAction;
    if (!action || submittingRef.current) return;
    submittingRef.current = true;
    try {
      const input = { expectedVersion: order.productionVersion, idempotencyKey, ...(cutting && action.id === "claim" && order.productionStageId === "material-preparation" ? { qrToken: claimQr, ownedCount: ownedCount ?? 0, confirmedMultiple } : {}) };
      const updated = action.id === "claim" ? await service.claim(order.id, input) : await service.confirmStageTransition(order.id, input);
      navigator.vibrate?.([30, 40, 30]);
      dispatch({ type: "SUBMIT_SUCCEEDED", order: updated });
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION") onSessionExpired();
      dispatch({ type: "SUBMIT_FAILED", error });
    } finally { submittingRef.current = false; }
  }

  function submit() {
    if (state.status !== "confirming" || submittingRef.current) return;
    if (cutting && confirmingCutting && (!claimQr || ownedCount === null || (ownedCount > 0 && !confirmedMultiple))) return;
    const idempotencyKey = createIdempotencyKey();
    dispatch({ type: "SUBMIT", idempotencyKey });
    void perform(state.order, idempotencyKey);
  }

  function retrySubmit() {
    if (state.status !== "error" || !state.failedSubmission) return;
    const { order, idempotencyKey } = state.failedSubmission;
    dispatch({ type: "RETRY_SUBMIT" });
    void perform(order, idempotencyKey);
  }

  async function reloadOrder(orderId: string) {
    reset();
    duplicateGuard.accept(orderId);
    dispatch({ type: "CODE_DETECTED", token: orderId });
    dispatch({ type: "RESOLVE_STARTED" });
    try {
      dispatch({ type: "ORDER_RESOLVED", order: requireProductionProducts(await service.getById(orderId)) });
    } catch (caught) {
      dispatch({ type: "RESOLVE_FAILED", error: toServiceError(caught) });
    }
  }

  const busy = state.status === "submitting";
  const reviewItem = state.status === "review" ? getUsableProductionProducts(state.order).at(0) : undefined;
  const pending = state.status === "confirming" || state.status === "submitting" ? state.order : null;
  const pendingAction = pending?.employeeAllowedAction;
  const pendingCurrent = pending ? getStageById(workflow, pending.productionStageId) : undefined;
  const pendingNext = pending && pendingAction?.id === "complete_stage" ? getNextStage(workflow, pending.productionStageId) : undefined;
  const needsCuttingQr = Boolean(cutting && pending?.productionStageId === "material-preparation" && pendingAction?.id === "claim");

  return (
    <main className="scanner-screen">
      <div className={`camera-view${["review", "confirming", "submitting", "success", "error"].includes(state.status) ? " camera-dimmed" : ""}`}>
        <video ref={videoRef} muted playsInline aria-label="Imagine cameră pentru scanare QR" />
        <div className="camera-fallback" aria-hidden="true" />
        <header className="scanner-toolbar"><button type="button" disabled={busy} onClick={() => { stopCamera(); navigate("/"); }} aria-label="Înapoi la pagina principală"><AppIcon name="back" /></button><span>Scanare QR</span>{state.status === "scanning" && state.torchSupported ? <button type="button" className={state.torchOn ? "active" : ""} onClick={toggleTorch} aria-label={state.torchOn ? "Oprește lanterna" : "Pornește lanterna"}><AppIcon name="torch" /></button> : <span />}</header>
        <div className="scanner-target" aria-hidden="true"><i /><i /><i /><i /><span /></div>
        <div className="scanner-instruction"><strong>{state.status === "requesting_permission" ? "Se deschide camera…" : state.status === "scanning" ? "Aliniază codul QR în cadru" : "Scanează o comandă"}</strong></div>
        {state.status === "scanning" && <div className="scanner-manual-switch"><button className="manual-link" type="button" onClick={() => { stopCamera(); dispatch({ type: "OPEN_MANUAL" }); setManualOpen(true); }}>Introdu codul manual</button></div>}
        {state.status === "idle" && !manualOpen && <div className="camera-start"><button className="button button-primary button-large" type="button" onClick={startCamera}>Deschide camera</button>{mode === "demo" && <button className="button button-demo" type="button" onClick={() => resolveCode("arasya:61833")}>Previzualizează scanare demo</button>}{mode === "preview" && <button className="button button-preview" type="button" onClick={() => resolveCode("arasya:61833")}>Simulează scanarea</button>}<button className="manual-link" type="button" onClick={() => setManualOpen(true)}>Introdu manual numărul / codul comenzii</button></div>}
        {manualOpen && state.status === "idle" && <form className="manual-panel" onSubmit={(event) => { event.preventDefault(); void resolveCode(manualCode, true); }}><div className="sheet-handle" /><label className="field"><span>Număr / cod comandă</span><input inputMode="text" autoComplete="off" autoCapitalize="characters" spellCheck={false} maxLength={128} value={manualCode} onChange={(event) => setManualCode(event.target.value)} placeholder="Ex: 61833" /></label><button className="button button-primary" type="submit" disabled={!manualCode.trim()}>Caută comanda</button><button className="button button-link" type="button" onClick={() => setManualOpen(false)}>Anulează</button></form>}
        {state.status === "resolving" && <div className="resolving-card" role="status"><span className="inline-spinner" /><strong>Se verifică comanda…</strong><small>Nu închide această fereastră.</small></div>}
      </div>

      {state.status === "review" && reviewItem && <section className="bottom-sheet review-sheet"><ReviewContent order={state.order} item={reviewItem} workflow={workflow} />{state.order.employeeAllowedAction && <button className="button button-primary button-large" type="button" onClick={() => dispatch({ type: "OPEN_CONFIRMATION" })}>{state.order.employeeAllowedAction.label}<span aria-hidden="true">→</span></button>}<button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(state.order.id)}`)}>Vezi detalii</button><button className="button button-link" type="button" onClick={reset}>Scanează alt cod</button></section>}
      {state.status === "review" && !reviewItem && <section className="bottom-sheet error-sheet"><ErrorState error={new ServiceError("ORDER_PRODUCTS_UNAVAILABLE")} compact onAction={reset} /></section>}

      {pending && pendingAction && <section className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="confirmation-title"><div className="confirmation-card"><span className="state-icon confirm-icon" aria-hidden="true">?</span><p className="eyebrow">Confirmare necesară</p><h2 id="confirmation-title">{orderActionConfirmation[pendingAction.id].title}</h2><p>Comanda #{pending.orderNumber}. {orderActionConfirmation[pendingAction.id].note}</p>
        {needsCuttingQr && <>
          {claimQr ? <p role="status">Etichetă QR capturată · serverul verifică aceeași comandă.</p> : <QrCapture onToken={setClaimQr} disabled={busy} />}
          {ownedCount === null ? <p>Se verifică lucrările tale active…</p> : ownedCount > 0 && <div className="order-notice order-notice-warning"><strong>Ai deja {ownedCount} comenzi în lucru.</strong><p>Dacă preiei această comandă, trebuie să finalizezi sau să transferi toate comenzile active. Vrei să continui?</p><label><input type="checkbox" checked={confirmedMultiple} disabled={busy} onChange={(e) => setConfirmedMultiple(e.target.checked)} /> Confirm explicit preluarea încă unei comenzi.</label></div>}
        </>}
        <div className="confirm-transition"><StageLabel stage={pendingCurrent} muted={pendingAction.id !== "claim"} />{pendingNext && <><span aria-hidden="true">↓</span><StageLabel stage={pendingNext} /></>}{pendingAction.id === "complete_production" && <><span aria-hidden="true">↓</span><span className="stage-label"><span>✓</span>Producție finalizată</span></>}</div><div className="confirmation-actions"><button className="button button-secondary" type="button" disabled={busy} onClick={() => dispatch({ type: "CANCEL_CONFIRMATION" })}>Anulează</button><button className="button button-primary" type="button" disabled={busy || (needsCuttingQr && (!claimQr || ownedCount === null || (ownedCount > 0 && !confirmedMultiple)))} onClick={submit}>{busy ? "Se procesează…" : "Confirmă"}</button></div>{busy && <small className="server-note">Așteptăm confirmarea serverului.</small>}</div></section>}

      {state.status === "success" && <section className="success-state"><span className="success-check"><AppIcon name="check" size={34} /></span><p className="eyebrow">{orderActionSuccess[state.action].eyebrow}</p><h2>{orderActionSuccess[state.action].title}</h2>{state.order.productionCompletedAt ? <span className="stage-label"><span>✓</span>Producție finalizată</span> : <StageLabel stage={getStageById(workflow, state.order.productionStageId)} />}<div><button className="button button-primary button-large" type="button" onClick={reset}>Scanează altă comandă</button><button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(state.order.id)}`)}>Vezi comanda</button></div></section>}

      {state.status === "error" && <section className="bottom-sheet error-sheet"><ErrorState error={state.error} compact onAction={() => {
        if (state.recovery === "login") onSessionExpired();
        else if (state.failedSubmission && isTransient(state.error)) retrySubmit();
        else if (state.recovery === "reload" && state.failedSubmission) void reloadOrder(state.failedSubmission.order.id);
        else { reset(); if (state.recovery === "manual") setManualOpen(true); }
      }} /><button className="button button-link" type="button" disabled={busy} onClick={() => navigate("/")}>Înapoi acasă</button></section>}
    </main>
  );
}
