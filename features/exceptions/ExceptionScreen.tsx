import { physicalText as pt } from "../../i18n/physical";
import { useEffect, useState } from "react";
import { formatDate, t as translateNow, useI18n } from "../../i18n";
import { faultReasonName, stageName } from "../../domain/stageNames";
import type { FaultException, StaffServiceError } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { useLive } from "../../app/liveContext";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorState } from "../../components/ErrorState";
import { LoadingState } from "../../components/ui/States";
import { SourceBadge } from "../../components/SourceBadge";
import { toServiceError } from "../../services/errors";
import { arrivalLabel, faultStatusLabel, formatDecimalMeters, formatFaultMeters, responsibilityLabels, timelineLabel } from "../../domain/faults";
import { ExceptionActions } from "./ExceptionActions";
import { isPartialScope, scopeCounts, type PieceApi } from "../../domain/pieces";
import { PieceLabelsView } from "../orders/PiecesPanel";

const time = { format: (value: Date): string => formatDate(value, { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" }) };

/** A request on exact pieces (API 2.31.0): only those pieces move; the rest of the order keeps its stage. */
export function partialScopeNotice(fault: Pick<FaultException, "orderScope">): string | null {
  if (!isPartialScope(fault)) return null;
  const counts = scopeCounts(fault);
  return counts ? translateNow("exc.scope.partial", { affected: counts.defective, healthy: counts.healthy }) : null;
}

/**
 * Production is routed per order: an approved return to cutting moves the whole order. The warning names what
 * really moves whenever the order holds more than the affected lines, or the affected line has several pieces.
 */
export function returnScopeWarning(fault: Pick<FaultException, "orderScope" | "lineCount">): string | null {
  const t = translateNow;
  const scope = fault.orderScope;
  if (!scope || !scope.wholeOrderMoves) return null;
  // Every piece of the order was named (API 2.31.0): nothing beyond the reported pieces moves, so no warning.
  if (scope.piecesRecorded && scope.affectedQuantity >= scope.orderQuantity) return null;
  if (scope.orderLineCount > scope.affectedLineCount) {
    return t("exc.scope.wholeOrder", { lines: scope.orderLineCount, pieces: scope.orderQuantity, affected: t("common.products", { count: scope.affectedLineCount }) });
  }
  return scope.affectedQuantity > 1 ? t("exc.scope.allPieces", { pieces: scope.affectedQuantity }) : null;
}

/**
 * One production exception, updated live. The original cutting fault (detector and responsible cutter) and
 * the supervised tailoring defect (worker, tailoring quality supervisor, cutter, manager). Every action is
 * shown only when the server granted it, and every result is the server's committed state. An action that needs the
 * product in hand opens the universal scanner for this request: the screen itself never captures a QR code.
 */
export function ExceptionScreen({ exceptionId, service, pieces, navigate, onSessionExpired }: { exceptionId: string; service: ExceptionService; /** Piece labels (API 2.31.0). */ pieces?: PieceApi; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const { t } = useI18n();
  const { revision } = useLive();
  const [fault, setFault] = useState<FaultException | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    service.get(exceptionId, { signal: controller.signal })
      .then((loaded) => { setError(null); setFault(loaded); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  // Every live event re-reads the request (one small GET), so the screen never needs a manual refresh.
  }, [exceptionId, service, revision, reload]);

  if (error) return <ErrorState error={error} onAction={() => { setError(null); if (error.code === "EXCEPTION_NOT_FOUND") navigate("/"); else setReload((value) => value + 1); }} />;
  if (!fault) return <LoadingState label={t("exc.loading")} shape="detail" />;
  const arrival = arrivalLabel(fault.arrivalNumber);
  const tailoring = fault.type === "tailoring_defect";
  const actions = fault.actions;
  const scopeWarning = tailoring ? returnScopeWarning(fault) : null;
  const partialNotice = partialScopeNotice(fault);
  const counts = scopeCounts(fault);
  return (
    <article className="screen-stack detail-screen exception-screen" data-testid="exception-screen">
      <button className="back-link" type="button" onClick={() => navigate(fault.role === "supervisor" ? "/problems" : "/")}><AppIcon name="back" size={20} /> {fault.role === "supervisor" ? t("supervisor.title") : t("common.home")}</button>
      <section className="detail-hero"><div><SourceBadge source={fault.order.source} /><p className="eyebrow">{tailoring ? t("exc.typeDefect") : t("exc.typeReturn")} · {fault.number}</p><h1>{t("common.orderWord")} <span className="order-id">#{fault.order.orderNumber}</span></h1></div>
        <span className={`exception-status status-${fault.status}`} role="status" data-testid="exception-status">{faultStatusLabel(fault)}</span></section>
      {tailoring && fault.role === "detector" && fault.status === "awaiting_supervisor_review" && <p className="order-action-done" role="status">✓ {t("exc.reportedToSupervisor")}</p>}
      {fault.repeatedError && <p className="order-notice order-notice-warning" role="note">{t("exc.repeated")}</p>}
      {fault.status === "approved" && (fault.role === "responsible" || fault.role === "receiver") && <p className="order-action-done" role="status">✓ {t("exc.approvedRework")}</p>}
      {tailoring && fault.status === "return_rejected" && <p className="order-notice order-notice-warning" role="note">{t("exc.returnRejected")}</p>}
      {tailoring && fault.status === "awaiting_acknowledgment" && !actions.canAcknowledge && <p className="order-notice" role="status" data-testid="waiting-cutter">{t("exc.waitingCutter", { name: fault.receiver?.displayName ?? fault.responsible.displayName })}</p>}
      {fault.status === "awaiting_restore_receipt" && !actions.canConfirmRestore && <p className="order-notice" role="status" data-testid="restore-pending">{t("exc.restorePending", { name: fault.restoreRecipient?.displayName ?? t("exc.tailoringEmployee") })}</p>}

      <section className="detail-section">
        <p className="eyebrow">{tailoring ? t("exc.affected") : t("exc.faulty")}</p>
        <h2>{t("common.products", { count: fault.lineCount })} · {formatFaultMeters(fault)}</h2>
        <ul className="fault-lines">{fault.lines?.map((line) => <li key={line.itemId}><span><strong>{line.name}</strong><small>{[line.code, line.color, line.variant, line.affectedQuantity !== undefined ? t("exc.pieces.lineAffected", { affected: line.affectedQuantity, quantity: line.quantity }) : t("exc.lineQuantity", { count: line.quantity })].filter(Boolean).join(" · ")}</small></span><b>{formatDecimalMeters(line.meters)}</b></li>)}</ul>
        {counts && <p className="piece-counts" data-testid="piece-counts"><strong>{counts.defective}</strong> · <span>{counts.healthy}</span></p>}
        {fault.affectedPieces && fault.affectedPieces.length > 0 && <div className="affected-pieces" data-testid="affected-pieces"><p className="eyebrow">{t("exc.pieces.title")}</p>
          <ul className="piece-list">{fault.affectedPieces.map((piece) => <li key={piece.unitId}>{t("piece.label", { index: piece.index, count: piece.count })} · {[piece.code, piece.color].filter(Boolean).join(" · ")}</li>)}</ul></div>}
        {partialNotice && <p className="order-notice order-notice-muted" role="note" data-testid="partial-scope">{partialNotice}</p>}
        {pieces && fault.role === "supervisor" && fault.orderScope?.trackId && <PieceLabelsView trackId={fault.orderScope.trackId} service={pieces} />}
        {scopeWarning && ["awaiting_supervisor_review", "in_supervisor_custody", "awaiting_acknowledgment", "awaiting_approval", "return_rejected"].includes(fault.status) && <p className="order-notice order-notice-warning" role="note" data-testid="return-scope-warning">{scopeWarning}</p>}
        <dl className="fault-facts">
          <div><dt>{tailoring ? t("exc.reportedProblem") : t("exc.reason")}</dt><dd>{faultReasonName(fault.reason.key, fault.reason.label)}</dd></div>
          {fault.confirmedReason && <div><dt>{t("exc.confirmedBySupervisor")}</dt><dd>{faultReasonName(fault.confirmedReason.key, fault.confirmedReason.label)}</dd></div>}
          {fault.detectorComment && <div><dt>{tailoring ? t("exc.description") : t("exc.intakeComment")}</dt><dd>{fault.detectorComment}</dd></div>}
          {tailoring && <div><dt>{t("exc.detectedStage")}</dt><dd>{stageName(fault.detectedStage.id, fault.detectedStage.label)}</dd></div>}
          {tailoring && fault.currentStage && <div><dt>{t("exc.currentStage")}</dt><dd>{stageName(fault.currentStage.id, fault.currentStage.label)}</dd></div>}
          <div><dt>{t("exc.detectedBy")}</dt><dd>{fault.detector.displayName}</dd></div>
          {fault.supervisor && <div><dt>{t("exc.supervisor")}</dt><dd>{fault.supervisor.displayName}</dd></div>}
          <div><dt>{t("exc.cutBy")}</dt><dd>{fault.responsible.displayName}</dd></div>
          {fault.cuttingHandoff && <div><dt>{t("exc.cuttingHandoff")}</dt><dd><time dateTime={fault.cuttingHandoff.at}>{time.format(new Date(fault.cuttingHandoff.at))}</time></dd></div>}
          {fault.receiver && <div><dt>{t("exc.sentTo")}</dt><dd>{fault.receiver.displayName}</dd></div>}
          {tailoring && fault.physicalCustodian && <div><dt>{t("exc.custodian")}</dt><dd>{fault.physicalCustodian.displayName}</dd></div>}
          {fault.restoreRecipient && <div><dt>{fault.restoreReceivedAt ? t("exc.receivedBackBy") : t("exc.handedBackTo")}</dt><dd>{fault.restoreRecipient.displayName}</dd></div>}
          {fault.responsibilityPosition && <div><dt>{t("exc.responsibility")}</dt><dd>{responsibilityLabels[fault.responsibilityPosition]}</dd></div>}
          {fault.attribution && <div><dt>{t("exc.managerDecision")}</dt><dd>{fault.attribution === "cutter" ? t("exc.cutterFault") : t("exc.noCutterFault")}</dd></div>}
          {fault.supervisorComment && <div><dt>{t("exc.supervisorComment")}</dt><dd>{fault.supervisorComment}</dd></div>}
          <div><dt>{t("exc.reported")}</dt><dd><time dateTime={fault.reportedAt}>{time.format(new Date(fault.reportedAt))}</time></dd></div>
          {arrival && <div><dt>{t("exc.arrival")}</dt><dd>{arrival}</dd></div>}
        </dl>
      </section>

      {fault.status === "awaiting_approval" && <p className="order-notice" role="status">{t("exc.awaitingApproval")}</p>}

      <ExceptionActions key={`${fault.id}:${fault.version}`} fault={fault} service={service} qrToken={null} onUpdated={setFault} onStale={() => setReload((value) => value + 1)}
        onOpenScanner={() => navigate(`/scan?exception=${encodeURIComponent(fault.id)}`)} onSessionExpired={onSessionExpired} />

      {fault.decisions && fault.decisions.length > 0 && <section className="detail-section"><p className="eyebrow">{t("exc.decisions")}</p>
        <ol className="decision-list">{fault.decisions.map((decision) => <li key={decision.attempt}>
          <strong>{t("exc.attempt", { attempt: decision.attempt, status: t(decision.status === "pending" ? "exc.decision.pending" : decision.status === "approved" ? "exc.decision.approved" : decision.status === "rejected" ? "exc.decision.rejected" : "exc.decision.cancelled") })}</strong>
          {decision.openedReason === "rereview" && <small>{t("exc.rereviewBy", { name: decision.openedBy })}{decision.openedComment ? `: ${t("common.quote", { text: decision.openedComment })}` : ""}</small>}
          {decision.decidedBy && <small>{decision.decidedBy}{decision.comment ? `: ${t("common.quote", { text: decision.comment })}` : ""}</small>}
        </li>)}</ol>
      </section>}
      {fault.timeline && fault.timeline.length > 0 && <section className="detail-section"><p className="eyebrow">{t("common.history")}</p>
        <ol className="decision-list exception-timeline">{fault.timeline.map((entry) => <li key={entry.version}>
          <strong>{timelineLabel(entry.action)}</strong><small>{entry.actor} · <time dateTime={entry.at}>{time.format(new Date(entry.at))}</time></small>{entry.comment && <small>{t("common.quote", { text: entry.comment })}</small>}
        </li>)}</ol>
      </section>}
      {fault.type === "tailoring_defect" && ["restored", "cancelled"].includes(fault.status) && <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}?recover=${encodeURIComponent(fault.id)}`)}>{pt("physical.recovery.open")}</button>}
      {fault.role !== "supervisor" && <button className="button button-secondary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(fault.order.id)}`)}>{t("common.viewOrder")}</button>}
    </article>
  );
}
