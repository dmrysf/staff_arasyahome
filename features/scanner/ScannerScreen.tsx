import { useCallback, useEffect, useLayoutEffect, useMemo, useReducer, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { OrderService } from "../../services/contracts";
import type { Employee, ProductionItem, ProductionWorkflow, StaffOrder } from "../../domain/models";
import { StaffServiceError as ServiceError } from "../../domain/models";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { OrderNotices } from "../../components/OrderNotices";
import { createDuplicateGuard } from "./duplicateGuard";
import { initialScannerState, isTransient, scannerReducer, type IntakeAttempt } from "./machine";
import { mapCameraError, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { getUsableProductionProducts, requireProductionProducts } from "../../domain/orderValidation";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { orderActionConfirmation, orderActionLabels, orderActionSuccess } from "../../domain/orderActions";
import { selectQrDecoder, type QrDecoder } from "./qrDecoder";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";
import { AppIcon } from "../../components/icons/AppIcon";
import { getNextStage, getStageById } from "../../domain/productionWorkflow";
import { canUseCuttingIntake, cuttingIntakeCopy, type CuttingApi } from "../../domain/cutting";
import { QrCapture } from "../exceptions/QrCapture";

type TorchCapabilities = MediaTrackCapabilities & { torch?: boolean };
type TorchConstraintSet = MediaTrackConstraintSet & { torch?: boolean };

function ReviewContent({ order, item, workflow }: { order: StaffOrder; item: ProductionItem; workflow: ProductionWorkflow }) {
  const currentStage = getStageById(workflow, order.productionStageId);
  const nextStage = order.employeeAllowedAction?.id === "complete_stage" ? getNextStage(workflow, order.productionStageId) : undefined;
  const { t } = useI18n();
  const products = getUsableProductionProducts(order);
  const size = formatMeasurements(item);
  return (
    <>
      <div className="sheet-handle" />
      <div className="review-heading"><div><SourceBadge source={order.source} /><h2>{t("common.order", { number: order.orderNumber })}</h2></div><span className="verified-mark" role="img" aria-label={t("scanner.verified")}><AppIcon name="check" size={20} /></span></div>
      <div className="review-product"><p className="eyebrow">{products.length > 1 ? t("product.productOf", { count: products.length }) : t("product.product")}</p><strong>{item.name}</strong>{item.code && <span>{item.code}</span>}<dl>{item.color && <div><dt>{t("product.color")}</dt><dd>{item.color}</dd></div>}{size && <div><dt>{t("product.size")}</dt><dd>{size}</dd></div>}{item.meters != null && <div><dt>{t("product.meters")}</dt><dd>{formatMeters(item.meters)}</dd></div>}<div><dt>{t("product.quantity")}</dt><dd>{item.quantity}</dd></div></dl></div>
      <div className="stage-transition"><div><small>{t("scanner.currentStage")}</small><StageLabel stage={currentStage} muted={Boolean(nextStage)} /></div>{nextStage && <><span aria-hidden="true">↓</span><div><small>{t("scanner.nextStage")}</small><StageLabel stage={nextStage} /></div></>}</div>
      <OrderNotices order={order} />
    </>
  );
}

export function ScannerScreen({ service, cutting, employee, workflow, mode, navigate, onSessionExpired }: { service: OrderService; cutting?: CuttingApi; employee?: Employee; workflow: ProductionWorkflow; mode: StaffRuntimeMode; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const { t } = useI18n();
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
  // An eligible cutter's camera scan goes to the cutting intake first: one scan receives the order into cutting.
  const intakeEnabled = Boolean(cutting && employee && canUseCuttingIntake(employee));
  const intakeInFlightRef = useRef(false);
  const activeIntakeRef = useRef<string | null>(null);
  const runIntakeRef = useRef<(attempt: IntakeAttempt) => Promise<void>>(async () => undefined);
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
  // Short haptic confirmation where the device supports it: a code was read, then the server confirmed the action.
  useEffect(() => {
    if (state.status === "decoded") navigator.vibrate?.(25);
    else if (state.status === "success") navigator.vibrate?.([20, 40, 20]);
  }, [state.status]);

  const resolveOrder = useCallback(async (normalized: string, manual: boolean) => {
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
  }, [onSessionExpired, service]);

  const resolveCode = useCallback(async (token: string, manual = false) => {
    const normalized = token.trim();
    if (!normalized || !duplicateGuard.accept(normalized)) return;
    stopCamera();
    dispatch({ type: "CODE_DETECTED", token: normalized });
    if (!manual && intakeEnabled) {
      const attempt = { token: normalized, idempotencyKey: createIdempotencyKey() };
      dispatch({ type: "INTAKE_STARTED", attempt });
      await runIntakeRef.current(attempt);
      return;
    }
    dispatch({ type: "RESOLVE_STARTED" });
    await resolveOrder(normalized, manual);
  }, [duplicateGuard, intakeEnabled, resolveOrder, stopCamera]);

  /**
   * One intake request at a time. Success is shown only from the committed server answer; a workload warning asks
   * for the explicit confirmation and reuses the scanned label; another stage or source falls back to the ordinary
   * verification of the same code; a transient failure keeps the attempt for a retry with the same key.
   */
  async function runIntake(attempt: IntakeAttempt) {
    if (!cutting || intakeInFlightRef.current) return;
    intakeInFlightRef.current = true;
    activeIntakeRef.current = attempt.idempotencyKey;
    // A late answer to an abandoned scan (screen reset meanwhile) never changes the current screen.
    const current = () => activeIntakeRef.current === attempt.idempotencyKey;
    let next: (() => Promise<void>) | null = null;
    try {
      const result = await cutting.intake({ qrToken: attempt.token, ...(attempt.confirmedMultiple ? { confirmedMultiple: true, ownedCount: attempt.ownedCount } : {}) }, attempt.idempotencyKey);
      if (!current()) return;
      dispatch({ type: "INTAKE_SUCCEEDED", order: result.order, outcome: result.outcome });
    } catch (caught) {
      const error = toServiceError(caught);
      if (error.code === "SESSION_EXPIRED" || error.code === "NO_SESSION") onSessionExpired();
      if (!current()) return;
      const ownedCount = typeof error.details?.ownedCount === "number" ? error.details.ownedCount : null;
      if (error.code === "CUTTING_INTAKE_NOT_APPLICABLE") {
        dispatch({ type: "INTAKE_NOT_APPLICABLE" });
        next = () => resolveOrder(attempt.token, false);
      } else if (ownedCount !== null && (error.code === "CONFIRMATION_REQUIRED" || error.code === "ORDER_CHANGED")) {
        if (ownedCount === 0) {
          // The other jobs were finished meanwhile: no confirmation is needed any more (a new intent, a new key).
          const plain = { token: attempt.token, idempotencyKey: createIdempotencyKey() };
          dispatch({ type: "INTAKE_STARTED", attempt: plain });
          next = () => runIntake(plain);
        } else {
          dispatch({ type: "INTAKE_CONFIRM_REQUIRED", ownedCount });
        }
      } else {
        dispatch({ type: "INTAKE_FAILED", error });
      }
    } finally {
      intakeInFlightRef.current = false;
    }
    if (next) await next();
  }
  useLayoutEffect(() => { runIntakeRef.current = runIntake; });

  function confirmIntake() {
    if (state.status !== "take_confirm" || !confirmedMultiple || intakeInFlightRef.current) return;
    // A changed intent (the explicit confirmation of this count) is a new request with a new key.
    const attempt = { token: state.token, idempotencyKey: createIdempotencyKey(), confirmedMultiple: true, ownedCount: state.ownedCount };
    setConfirmedMultiple(false);
    dispatch({ type: "INTAKE_STARTED", attempt });
    void runIntake(attempt);
  }

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
    intakeInFlightRef.current = false;
    activeIntakeRef.current = null;
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

  const busy = state.status === "submitting" || state.status === "taking";
  const intakeProduct = state.status === "success" && state.intake ? getUsableProductionProducts(state.order).at(0) : undefined;
  const reviewItem = state.status === "review" ? getUsableProductionProducts(state.order).at(0) : undefined;
  const pending = state.status === "confirming" || state.status === "submitting" ? state.order : null;
  const pendingAction = pending?.employeeAllowedAction;
  const pendingCurrent = pending ? getStageById(workflow, pending.productionStageId) : undefined;
  const pendingNext = pending && pendingAction?.id === "complete_stage" ? getNextStage(workflow, pending.productionStageId) : undefined;
  const needsCuttingQr = Boolean(cutting && pending?.productionStageId === "material-preparation" && pendingAction?.id === "claim");

  return (
    <main className="scanner-screen">
      <div className={`camera-view${["review", "confirming", "submitting", "taking", "take_confirm", "success", "error"].includes(state.status) ? " camera-dimmed" : ""}`}>
        <video ref={videoRef} muted playsInline aria-label={t("scanner.videoLabel")} />
        <div className="camera-fallback" aria-hidden="true" />
        <header className="scanner-toolbar"><button type="button" disabled={busy} onClick={() => { stopCamera(); navigate("/"); }} aria-label={t("scanner.backHome")}><AppIcon name="back" /></button><span>{t("scanner.title")}</span>{state.status === "scanning" && state.torchSupported ? <button type="button" className={state.torchOn ? "active" : ""} onClick={toggleTorch} aria-label={state.torchOn ? t("scanner.torchOff") : t("scanner.torchOn")}><AppIcon name="torch" /></button> : <span />}</header>
        <div className="scanner-target" data-state={state.status} aria-hidden="true"><i /><i /><i /><i /><span /></div>
        <div className="scanner-instruction" aria-live="polite"><strong>{state.status === "requesting_permission" ? t("scanner.opening") : state.status === "scanning" ? t("scanner.align") : state.status === "decoded" || state.status === "resolving" ? t("scanner.readVerifying") : state.status === "taking" ? t("scanner.readTaking") : t("scanner.scanOrder")}</strong>{state.status === "scanning" && <span>{state.torchSupported ? t("scanner.autoTorch") : t("scanner.auto")}</span>}</div>
        {state.status === "scanning" && <div className="scanner-manual-switch"><button className="manual-link" type="button" onClick={() => { stopCamera(); dispatch({ type: "OPEN_MANUAL" }); setManualOpen(true); }}>{t("scanner.manualSwitch")}</button></div>}
        {state.status === "idle" && !manualOpen && <div className="camera-start"><button className="button button-primary button-large" type="button" onClick={startCamera}>{t("scanner.openCamera")}</button>{mode === "demo" && <button className="button button-demo" type="button" onClick={() => resolveCode("arasya:61833")}>{t("scanner.demo")}</button>}{mode === "preview" && <button className="button button-preview" type="button" onClick={() => resolveCode("arasya:61833")}>{t("scanner.simulate")}</button>}<button className="manual-link" type="button" onClick={() => setManualOpen(true)}>{t("scanner.manualEntry")}</button></div>}
        {manualOpen && state.status === "idle" && <form className="manual-panel" onSubmit={(event) => { event.preventDefault(); void resolveCode(manualCode, true); }}><div className="sheet-handle" /><label className="field"><span>{t("scanner.manualLabel")}</span><input inputMode="text" autoComplete="off" autoCapitalize="characters" spellCheck={false} maxLength={128} value={manualCode} onChange={(event) => setManualCode(event.target.value)} placeholder={t("scanner.manualPlaceholder")} /></label><button className="button button-primary" type="submit" disabled={!manualCode.trim()}>{t("scanner.find")}</button><button className="button button-link" type="button" onClick={() => setManualOpen(false)}>{t("common.cancel")}</button></form>}
        {state.status === "resolving" && <div className="resolving-card" role="status"><span className="inline-spinner" /><strong>{t("scanner.verifying")}</strong><small>{t("scanner.dontClose")}</small></div>}
        {state.status === "taking" && <div className="resolving-card" role="status" aria-busy="true" data-testid="cutting-intake-progress"><span className="inline-spinner" /><strong>{t("scanner.taking")}</strong><small>{t("scanner.takingNote")}</small></div>}
      </div>

      {state.status === "review" && reviewItem && <section className="bottom-sheet review-sheet"><ReviewContent order={state.order} item={reviewItem} workflow={workflow} />{state.order.employeeAllowedAction && <button className="button button-primary button-large" type="button" onClick={() => dispatch({ type: "OPEN_CONFIRMATION" })}>{orderActionLabels[state.order.employeeAllowedAction.id]}<AppIcon name="arrow" size={20} /></button>}<button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(state.order.id)}`)}>{t("scanner.details")}</button><button className="button button-link" type="button" onClick={reset}>{t("scanner.scanOtherCode")}</button></section>}
      {state.status === "review" && !reviewItem && <section className="bottom-sheet error-sheet"><ErrorState error={new ServiceError("ORDER_PRODUCTS_UNAVAILABLE")} compact onAction={reset} /></section>}

      {pending && pendingAction && <section className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="confirmation-title"><div className="confirmation-card"><span className="state-icon confirm-icon" aria-hidden="true"><AppIcon name="arrow" size={24} /></span><p className="eyebrow">{t("common.confirmationRequired")}</p><h2 id="confirmation-title">{orderActionConfirmation[pendingAction.id].title}</h2><p>{t("orderAction.orderNote", { number: pending.orderNumber, note: orderActionConfirmation[pendingAction.id].note })}</p>
        {needsCuttingQr && <>
          {claimQr ? <p role="status">{t("orderAction.qrCaptured")}</p> : <QrCapture onToken={setClaimQr} disabled={busy} />}
          {ownedCount === null ? <p>{t("orderAction.checkingWork")}</p> : ownedCount > 0 && <div className="order-notice order-notice-warning"><strong>{t("orderAction.alreadyOwned", { count: ownedCount })}</strong><p>{t("orderAction.multipleBody")}</p><label><input type="checkbox" checked={confirmedMultiple} disabled={busy} onChange={(e) => setConfirmedMultiple(e.target.checked)} /> {t("orderAction.multipleConfirm")}</label></div>}
        </>}
        <div className="confirm-transition"><StageLabel stage={pendingCurrent} muted={pendingAction.id !== "claim"} />{pendingNext && <><span aria-hidden="true">↓</span><StageLabel stage={pendingNext} /></>}{pendingAction.id === "complete_production" && <><span aria-hidden="true">↓</span><span className="stage-label"><span>✓</span>{t("common.productionCompleted")}</span></>}</div><div className="confirmation-actions"><button className="button button-secondary" type="button" disabled={busy} onClick={() => dispatch({ type: "CANCEL_CONFIRMATION" })}>{t("common.cancel")}</button><button className="button button-primary" type="button" disabled={busy || (needsCuttingQr && (!claimQr || ownedCount === null || (ownedCount > 0 && !confirmedMultiple)))} aria-busy={busy || undefined} onClick={submit}>{busy ? t("common.processing") : t("common.confirm")}</button></div>{busy && <small className="server-note">{t("common.waitingServer")}</small>}</div></section>}

      {state.status === "take_confirm" && <section className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="intake-confirm-title" data-testid="cutting-intake-confirm"><div className="confirmation-card"><span className="state-icon confirm-icon" aria-hidden="true"><AppIcon name="arrow" size={24} /></span><p className="eyebrow">{t("common.confirmationRequired")}</p><h2 id="intake-confirm-title">{t("scanner.takeAnother")}</h2>
        {state.changed && <p role="status">{t("scanner.countChanged")}</p>}
        <div className="order-notice order-notice-warning"><strong>{t("orderAction.alreadyOwned", { count: state.ownedCount })}</strong><p>{t("orderAction.multipleBody")}</p><label><input type="checkbox" checked={confirmedMultiple} onChange={(e) => setConfirmedMultiple(e.target.checked)} /> {t("orderAction.multipleConfirm")}</label></div>
        <p>{t("scanner.reuseLabel")}</p>
        <div className="confirmation-actions"><button className="button button-secondary" type="button" onClick={reset}>{t("common.cancel")}</button><button className="button button-primary" type="button" disabled={!confirmedMultiple} onClick={confirmIntake}>{t("scanner.confirmTake")}</button></div></div></section>}

      {state.status === "success" && state.intake && <section className="success-state" data-testid="cutting-intake-success"><span className="success-check"><AppIcon name="check" size={34} /></span><p className="eyebrow">{cuttingIntakeCopy[state.intake].eyebrow}</p><h2>{cuttingIntakeCopy[state.intake].title}</h2><p className="intake-note">{cuttingIntakeCopy[state.intake].text}</p>
        <dl className="intake-facts"><div><dt>{t("scanner.fact.order")}</dt><dd>#{state.order.orderNumber}</dd></div><div><dt>{t("scanner.fact.source")}</dt><dd><SourceBadge source={state.order.source} /></dd></div><div><dt>{t("scanner.fact.stage")}</dt><dd><StageLabel stage={getStageById(workflow, state.order.productionStageId)} /></dd></div>{employee && <div><dt>{t("scanner.fact.responsible")}</dt><dd>{employee.displayName}</dd></div>}{intakeProduct && <div className="intake-product"><dt>{t("product.product")}</dt><dd>{[intakeProduct.name, formatMeasurements(intakeProduct), intakeProduct.meters != null ? formatMeters(intakeProduct.meters) : null].filter(Boolean).join(" · ")}</dd></div>}</dl>
        <div><button className="button button-primary button-large" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(state.order.id)}`)}>{t("common.openOrder")}</button><button className="button button-secondary" type="button" onClick={reset}>{t("common.scanAnother")}</button></div></section>}

      {state.status === "success" && !state.intake && <section className="success-state"><span className="success-check"><AppIcon name="check" size={34} /></span><p className="eyebrow">{orderActionSuccess[state.action].eyebrow}</p><h2>{orderActionSuccess[state.action].title}</h2>{state.order.productionCompletedAt ? <span className="stage-label"><span>✓</span>{t("common.productionCompleted")}</span> : <StageLabel stage={getStageById(workflow, state.order.productionStageId)} />}<div><button className="button button-primary button-large" type="button" onClick={reset}>{t("common.scanAnother")}</button><button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(state.order.id)}`)}>{t("common.viewOrder")}</button></div></section>}

      {state.status === "error" && <section className="bottom-sheet error-sheet"><ErrorState error={state.error} compact onAction={() => {
        if (state.recovery === "login") onSessionExpired();
        else if (state.failedSubmission && isTransient(state.error)) retrySubmit();
        else if (state.failedIntake && isTransient(state.error)) { const attempt = state.failedIntake; dispatch({ type: "RETRY_INTAKE" }); void runIntake(attempt); }
        else if (state.recovery === "reload" && state.failedSubmission) void reloadOrder(state.failedSubmission.order.id);
        else { reset(); if (state.recovery === "manual") setManualOpen(true); }
      }} /><button className="button button-link" type="button" disabled={busy} onClick={() => navigate("/")}>{t("scanner.backHomeButton")}</button></section>}
    </main>
  );
}
