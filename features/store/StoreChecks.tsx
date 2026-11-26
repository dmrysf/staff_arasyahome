import { useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { ConfirmareApi } from "../../domain/confirmare";
import type { CustomerCheckOutcome, ShopQueueItem } from "../../domain/confirmareInvoice";
import type { Tone } from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation } from "../../services/errors";
import { ErrorMessage, SourceTag, ToneMessage, fmtDateTime } from "./StoreParts";

const OUTCOMES: readonly CustomerCheckOutcome[] = ["confirmed", "no_answer", "needs_correction"];

/**
 * Staff 2.60.0 (Store Workspace V1.1): the customer check of an order routed to this employee without a telephone call — an
 * online payment the system approved after the shop verified it with the payment provider, or a manager route. Nobody has
 * talked to the customer yet, so production stays closed until the employee calls and answers here (the server's rule,
 * `ConfirmareService::customerCheck`). An order an operator already confirmed by telephone never comes here (API 2.83.1).
 * One answer per order and key; the earlier long list above the documents is gone: this is a small tab of its own, in the
 * Store Workspace design, and the order opens in the store's own order screen.
 */
export function CustomerCheckTable({ items, loaded, error, ops, onDone, navigate }: { items: ShopQueueItem[]; loaded: boolean; error: unknown; ops: ConfirmareApi; onDone: () => void; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const answer = useCustomerCheck(ops, onDone);
  // An answered order leaves the list at once: its answer stays above the list.
  const done = Object.entries(answer.notes).filter(([id]) => !items.some((item) => item.globalOrderId === id));
  const answered = done.length > 0 && <div className="sw-flash" data-testid="sw-checks-result">{done.map(([id, note]) => <ToneMessage key={id} tone={note.tone}>{note.text}</ToneMessage>)}</div>;
  if (error !== null && !loaded) return <ErrorMessage error={error} />;
  if (loaded && items.length === 0) return <>{answered}<p className="sw-empty" data-testid="sw-checks-empty">{t("sw.check.empty")}</p></>;
  return <>
    {answered}
    <p className="sw-muted" data-testid="sw-checks-lead">{t("sw.check.lead")}</p>
    <div className="sw-table-wrap">
      <table className="sw-table sw-checks-table" data-testid="sw-checks">
        <thead><tr><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.check.why")}</th><th scope="col">{t("sw.col.when")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.check.answer")}</span></th></tr></thead>
        <tbody>{items.map((item) => <tr key={item.globalOrderId} data-testid={`sw-check-row-${item.orderNumber}`}>
          <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(item.globalOrderId)}`)}>#{item.orderNumber}</button> <SourceTag source={item.source} /></td>
          <td>{t("sw.check.reason")}</td>
          <td>{fmtDateTime(item.assignedAt)}</td>
          <td className="sw-cell-action"><CheckButtons orderId={item.globalOrderId} orderNumber={item.orderNumber} answer={answer} /></td>
        </tr>)}</tbody>
      </table>
    </div>
  </>;
}

/** The same three answers on the store's order screen, for an order that waits for this employee's customer check. */
export function CustomerCheckCard({ item, ops, onDone }: { item: ShopQueueItem; ops: ConfirmareApi; onDone: () => void }) {
  const { t } = useI18n();
  const answer = useCustomerCheck(ops, onDone);
  return <section className="sw-card sw-card-wide sw-check-card" aria-labelledby="sw-o-check" data-testid="sw-order-check">
    <h2 id="sw-o-check">{t("sw.check.title")}</h2>
    <p className="sw-muted">{t("sw.check.lead")}</p>
    <CheckButtons orderId={item.globalOrderId} orderNumber={item.orderNumber} answer={answer} />
  </section>;
}

type CheckAnswer = ReturnType<typeof useCustomerCheck>;

function CheckButtons({ orderId, orderNumber, answer }: { orderId: string; orderNumber: string; answer: CheckAnswer }) {
  const { t } = useI18n();
  const result = answer.notes[orderId];
  return <div className="sw-check-actions">
    {OUTCOMES.map((outcome) => <button key={outcome} type="button" className={`sw-button ${outcome === "confirmed" ? "sw-button-blue" : "sw-button-ghost"}`} disabled={answer.busy !== null}
      aria-busy={answer.busy === `${orderId}:${outcome}`} onClick={() => void answer.send(orderId, orderNumber, outcome)} data-testid={`sw-check-${outcome}`}>{t(`cf.check.${outcome}`)}</button>)}
    {result && <ToneMessage tone={result.tone}>{result.text}</ToneMessage>}
  </div>;
}

function useCustomerCheck(ops: ConfirmareApi, onDone: () => void) {
  const { t } = useI18n();
  const [busy, setBusy] = useState<string | null>(null);
  const [notes, setNotes] = useState<Record<string, { tone: Tone; text: string }>>({});
  // One key per order and answer: a second click (or a lost answer) never records a second, different answer.
  const keys = useRef(new Map<string, string>());
  async function send(orderId: string, orderNumber: string, outcome: CustomerCheckOutcome) {
    if (!ops.customerCheck || busy) return;
    const intent = `${orderId}:${outcome}`;
    let key = keys.current.get(intent);
    if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); }
    setBusy(intent);
    try {
      const result = await ops.customerCheck(orderId, outcome, key);
      setNotes((current) => ({ ...current, [orderId]: { tone: result.productionCleared ? "green" : "yellow", text: `#${orderNumber}: ${t(`cf.check.result.${outcome}`)}` } }));
      onDone();
    } catch (error) {
      setNotes((current) => ({ ...current, [orderId]: { tone: "yellow", text: `#${orderNumber}: ${getErrorPresentation(error).message}` } }));
    } finally { setBusy(null); }
  }
  return { busy, notes, send };
}
