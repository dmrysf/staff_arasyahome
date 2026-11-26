import type { ReactNode } from "react";
import { useI18n, type MessageKey } from "../../../i18n";
import { sourceLabel, type ConfirmareDetail, type OperatorDecision } from "../../../domain/confirmare";
import { formatShopMoney } from "../../../domain/confirmareShop";
import type { MoneyHeader } from "../../../domain/decisionDesk";
import { AppIcon, type AppIconName } from "../../../components/icons/AppIcon";

/**
 * The order workspace's sticky header (Staff 2.58.0, owner brief 2026-11-26): the order, the two figures the operator
 * reads out on every call (TOTAL COMANDĂ and AVANS SOLICITAT, 32 px), the verified money and the two ramburs figures
 * (never mixed up), and the three decisions as big action buttons. It stays on screen while the page scrolls and keeps
 * one height, so nothing below it jumps. While the shop recalculates, no old figure is shown as the new one.
 */
export function OrderFinancialHeader({ detail, money, computing, advancePercent, statusLabel, onBack, decisions, after, panel }: {
  detail: ConfirmareDetail; money: MoneyHeader | null; computing: boolean; advancePercent: number | null; statusLabel: string;
  onBack: () => void; decisions: ReactNode; after?: ReactNode; panel?: ReactNode;
}) {
  const { t } = useI18n();
  const value = (amount: string | null) => (money && amount !== null ? formatShopMoney(amount, money.currency) : "—");
  return (
    <header className="ow-head" data-testid="dd-sticky">
      <div className="ow-head-top">
      <div className="ow-head-id">
        <button type="button" className="ow-back" onClick={onBack} aria-label={t("dd.back")} title={t("dd.back")} data-testid="dd-back"><AppIcon name="back" size={20} /></button>
        <div className="ow-head-name">
          <h1 className="ow-number">#{detail.orderNumber}</h1>
          <span className="ow-head-tags">
            <span className="ow-shop" data-source={detail.sourceKey}>{sourceLabel(detail.sourceKey)}</span>
            <span className={`ow-status ow-status-${detail.status}`}>{statusLabel}</span>
          </span>
        </div>
      </div>
        <div className="ow-money" data-testid="dd-money" aria-busy={computing}>
          <dl className="ow-money-key" data-testid="dd-total">
            <dt>{t("dd.h.total")}</dt>
            <dd aria-live="polite">{computing ? <Computing /> : value(money?.total ?? null)}</dd>
          </dl>
          {money?.cod ? (
            <>
              <dl className="ow-money-key is-advance" data-testid="dd-advance">
                <dt>
                  {advancePercent !== null ? t("dd.h.advance", { percent: advancePercent }) : t("dd.h.advanceNoRate")}
                  {/* An estimate of an unsaved change says so on the same line (the header keeps its height). */}
                  {money.advanceEstimated && !computing && <span className="ow-est" title={t("dd.h.advanceEstimate")}>{t("dd.h.estimateShort")}<span className="visually-hidden">: {t("dd.h.advanceEstimate")}</span></span>}
                </dt>
                <dd aria-live="polite">{computing ? <Computing /> : value(money.advance)}</dd>
              </dl>
              <dl className="ow-money-side">
                <MoneyLine label={t("dd.h.received")} value={value(money.received)} testId="dd-received" />
                <MoneyLine label={t("dd.h.rambursNow")} value={computing ? null : value(money.rambursNow)} testId="dd-ramburs-now" />
                {money.rambursAfterAdvance !== null && <MoneyLine label={t("dd.h.rambursAfter")} value={computing ? null : value(money.rambursAfterAdvance)} testId="dd-ramburs-after" />}
              </dl>
            </>
          ) : money && (
            <dl className="ow-money-side">
              <MoneyLine label={t("dd.h.paidOnline")} value={value(money.received)} testId="dd-received" />
            </dl>
          )}
        </div>
      </div>
      <div className="ow-head-actions">
        {decisions}
        {after}
      </div>
      {panel}
    </header>
  );
}

function Computing() {
  const { t } = useI18n();
  return <span className="ow-computing"><span className="ow-spinner" aria-hidden="true" />{t("dd.h.computing")}</span>;
}

function MoneyLine({ label, value, testId }: { label: string; value: string | null; testId: string }) {
  const { t } = useI18n();
  return <div className="ow-money-line" data-testid={testId}><dt>{label}</dt><dd aria-live="polite">{value ?? <span className="ow-computing-inline">{t("dd.listTotalPending")}</span>}</dd></div>;
}

const DECISION_STYLE: Record<OperatorDecision, { icon: AppIconName; tone: string; testId: string }> = {
  confirmed: { icon: "check", tone: "green", testId: "dd-green" },
  waiting: { icon: "clock", tone: "yellow", testId: "dd-yellow" },
  refused: { icon: "close", tone: "red", testId: "dd-red" },
};

/**
 * The three decisions, each one click (Operation Simplification V1 unchanged): no modal, no form, no second step. The
 * buttons say what they do ("Confirmă", "Pune în așteptare", "Refuză") with the customer's answer under it. While one
 * is sent all three are disabled (no double click); the green one also waits for the shop's new total.
 */
export function OrderDecisionBar({ busy, greenBlocked, greenLabel, onDecide }: {
  busy: OperatorDecision | "save" | "sync" | "undo" | null; greenBlocked: boolean; greenLabel: string | null; onDecide: (decision: OperatorDecision) => void;
}) {
  const { t } = useI18n();
  return (
    <div className="ow-decisions" role="group" aria-label={t("dd.decisions")}>
      {(["confirmed", "waiting", "refused"] as const).map((decision) => {
        const style = DECISION_STYLE[decision];
        const sending = busy === decision;
        const disabled = busy !== null || (decision === "confirmed" && greenBlocked);
        const label = decision === "confirmed" && greenLabel ? greenLabel : sending ? t("dd.btn.busy") : t(`dd.btn.${decision}` as MessageKey);
        return (
          <button key={decision} type="button" className={`ow-decision ow-decision-${style.tone}`} disabled={disabled} aria-busy={sending}
            onClick={() => onDecide(decision)} data-testid={style.testId}>
            <span className="ow-decision-icon" aria-hidden="true"><AppIcon name={style.icon} size={22} /></span>
            <span className="ow-decision-text">
              <strong>{label}</strong>
              <small>{t(`dd.btn.${decision}.hint` as MessageKey)}</small>
            </span>
          </button>
        );
      })}
    </div>
  );
}
