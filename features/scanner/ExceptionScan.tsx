import { useEffect, useState } from "react";
import { useI18n } from "../../i18n";
import type { FaultException, ProductionWorkflow, StaffOrder } from "../../domain/models";
import type { ExceptionService, OrderService } from "../../services/contracts";
import { faultStatusLabel, hasMyStep, nextStepText } from "../../domain/faults";
import { faultReasonName, stageName } from "../../domain/stageNames";
import { getStageById } from "../../domain/productionWorkflow";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { AppIcon } from "../../components/icons/AppIcon";
import { ExceptionActions } from "../exceptions/ExceptionActions";
import { partialScopeNotice } from "../exceptions/ExceptionScreen";

/**
 * The open production exceptions of one scanned code, in the scanner itself. The employee sees what was scanned
 * (order, request, product, stages, who handed it over) and only the actions the server granted them. Several
 * actionable requests are listed and never guessed. An employee without a step is told who acts next.
 */
export function ExceptionScan({ token, items, chosenId, mismatch, service, navigate, onChoose, onCommitted, onStale, onReset, onSessionExpired }: {
  token: string;
  items: FaultException[];
  chosenId: string | null;
  mismatch: boolean;
  service: ExceptionService;
  navigate: (path: string) => void;
  onChoose: (id: string | null) => void;
  onCommitted: (fault: FaultException) => void;
  onStale: () => void;
  onReset: () => void;
  onSessionExpired: () => void;
}) {
  const { t } = useI18n();
  const chosen = items.find((item) => item.id === chosenId) ?? null;
  const footer = <div className="scan-exception-footer">
    {chosen && <button className="button button-secondary" type="button" onClick={() => navigate(`/exceptions/${encodeURIComponent(chosen.id)}`)}>{t("scan.exc.openRequest")}</button>}
    {chosen && items.length > 1 && <button className="button button-link" type="button" onClick={() => onChoose(null)}>{t("common.back")}</button>}
    <button className="button button-link" type="button" onClick={onReset}>{t("scanner.scanOtherCode")}</button>
  </div>;

  return (
    <section className="bottom-sheet review-sheet exception-scan-sheet" data-testid="scan-exception" aria-live="polite">
      <div className="sheet-handle" />
      {mismatch && <p className="order-notice order-notice-warning" role="alert" data-testid="scan-context-mismatch">{t("scan.exc.contextMismatch")}</p>}
      {items.length === 0 && <p className="order-notice" role="status" data-testid="scan-exception-gone">{t("scan.exc.gone")}</p>}
      {!chosen && items.length > 0 && <div className="scan-exception-choice" role="group" aria-labelledby="scan-choice-title" data-testid="scan-exception-choice">
        <strong id="scan-choice-title">{t("scan.exc.choose")}</strong>
        {items.map((item) => <button key={item.id} className="button button-secondary" type="button" onClick={() => onChoose(item.id)}>
          {item.number} · #{item.order.orderNumber} · {faultStatusLabel(item)}{item.orderScope?.piecesRecorded ? ` · ${t("piece.defective", { count: item.orderScope.affectedQuantity })}` : ""}
        </button>)}
      </div>}
      {chosen && <ScannedRequest key={`${chosen.id}:${chosen.version}`} fault={chosen} token={token} service={service} onCommitted={onCommitted} onStale={onStale} onSessionExpired={onSessionExpired} />}
      {footer}
    </section>
  );
}

function ScannedRequest({ fault, token, service, onCommitted, onStale, onSessionExpired }: { fault: FaultException; token: string; service: ExceptionService; onCommitted: (fault: FaultException) => void; onStale: () => void; onSessionExpired: () => void }) {
  const { t } = useI18n();
  const tailoring = fault.type === "tailoring_defect";
  const mine = hasMyStep(fault);
  const next = nextStepText(fault);
  const partial = partialScopeNotice(fault);
  const sender = fault.actions.canConfirmRestore ? fault.physicalCustodian ?? fault.supervisor : null;
  return <>
    <div className="review-heading">
      <div><SourceBadge source={fault.order.source} /><p className="eyebrow">{tailoring ? t("exc.typeDefect") : t("exc.typeReturn")} · {fault.number}</p><h2>{t("common.order", { number: fault.order.orderNumber })}</h2></div>
      <span className={`exception-status status-${fault.status}`} role="status" data-testid="scan-exception-status">{faultStatusLabel(fault)}</span>
    </div>
    {fault.actions.canConfirmRestore && <p className="scan-exception-lead"><strong>{t("scan.exc.restoreReceiptLead")}</strong></p>}
    <dl className="intake-facts scan-exception-facts">
      <div><dt>{tailoring ? t("exc.reportedProblem") : t("exc.reason")}</dt><dd>{faultReasonName((fault.confirmedReason ?? fault.reason).key, (fault.confirmedReason ?? fault.reason).label)}</dd></div>
      {fault.lines && fault.lines.length > 0 && <div className="intake-product"><dt>{t("common.products", { count: fault.lineCount })}</dt><dd><ul className="fault-lines">{fault.lines.map((line) => <li key={line.itemId}><span><strong>{line.name}</strong><small>{[line.code, line.color, line.variant, line.affectedQuantity !== undefined ? t("exc.pieces.lineAffected", { affected: line.affectedQuantity, quantity: line.quantity }) : t("exc.lineQuantity", { count: line.quantity })].filter(Boolean).join(" · ")}</small></span></li>)}</ul></dd></div>}
      {fault.affectedPieces && fault.affectedPieces.length > 0 && <div><dt>{t("exc.pieces.title")}</dt><dd>{fault.affectedPieces.map((piece) => t("piece.label", { index: piece.index, count: piece.count })).join(", ")}</dd></div>}
      {tailoring && <div><dt>{fault.actions.canConfirmRestore ? t("scan.exc.returnsTo") : t("exc.detectedStage")}</dt><dd>{stageName(fault.detectedStage.id, fault.detectedStage.label)}</dd></div>}
      {tailoring && fault.currentStage && <div><dt>{t("exc.currentStage")}</dt><dd>{stageName(fault.currentStage.id, fault.currentStage.label)}</dd></div>}
      {sender && <div><dt>{t("scan.exc.from")}</dt><dd>{sender.displayName}</dd></div>}
      <div><dt>{t("exc.detectedBy")}</dt><dd>{fault.detector.displayName}</dd></div>
    </dl>
    {partial && <p className="order-notice order-notice-muted" role="note">{partial}</p>}
    {mine
      ? <ExceptionActions fault={fault} service={service} qrToken={token} onUpdated={onCommitted} onStale={onStale} onSessionExpired={onSessionExpired} />
      : <div className="order-notice" role="status" data-testid="scan-exception-waiting"><strong>{t("scan.exc.waiting")}</strong>{next && <small data-testid="scan-exception-next">{next}</small>}</div>}
  </>;
}

/**
 * The committed result of a scanned exception action, as the server answered it. After a receipt back the order is
 * read again, so the stage and whether the order is now the employee's come from the server, not from the client.
 */
export function ExceptionScanDone({ fault, orders, workflow, navigate, onReset }: { fault: FaultException; orders: OrderService; workflow: ProductionWorkflow; navigate: (path: string) => void; onReset: () => void }) {
  const { t } = useI18n();
  const [order, setOrder] = useState<StaffOrder | null>(null);
  const restored = fault.status === "restored";
  useEffect(() => {
    if (!restored) return undefined;
    const controller = new AbortController();
    orders.getById(fault.order.id, { signal: controller.signal }).then(setOrder).catch(() => undefined);
    return () => controller.abort();
  }, [fault.order.id, orders, restored]);
  const next = nextStepText(fault);
  return <section className="success-state" data-testid="scan-exception-done">
    <span className="success-check"><AppIcon name="check" size={34} /></span>
    <p className="eyebrow">{t("scan.exc.done")}</p>
    <h2 data-testid="scan-exception-done-status">{faultStatusLabel(fault)}</h2>
    {next && <p className="intake-note" data-testid="scan-exception-done-next">{next}</p>}
    <dl className="intake-facts">
      <div><dt>{t("scanner.fact.order")}</dt><dd>#{fault.order.orderNumber}</dd></div>
      <div><dt>{t("scanner.fact.source")}</dt><dd><SourceBadge source={fault.order.source} /></dd></div>
      {order ? <div><dt>{t("scanner.fact.stage")}</dt><dd><StageLabel stage={getStageById(workflow, order.productionStageId)} /></dd></div>
        : fault.currentStage && <div><dt>{t("scanner.fact.stage")}</dt><dd>{stageName(fault.currentStage.id, fault.currentStage.label)}</dd></div>}
    </dl>
    {order?.employeeAllowedAction && <p className="order-action-done" role="status" data-testid="scan-exception-owner">✓ {t("scan.exc.ownerYou")}</p>}
    <div>
      {restored && order && <button className="button button-primary button-large" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(order.id)}`)}>{t("common.openOrder")}</button>}
      <button className={`button ${restored && order ? "button-secondary" : "button-primary button-large"}`} type="button" onClick={onReset}>{t("common.scanAnother")}</button>
      <button className="button button-secondary" type="button" onClick={() => navigate(`/exceptions/${encodeURIComponent(fault.id)}`)}>{t("scan.exc.openRequest")}</button>
    </div>
  </section>;
}
