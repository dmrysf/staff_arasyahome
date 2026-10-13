import { useEffect, useState } from "react";
import { useI18n } from "../i18n";
import { useLive } from "../app/liveContext";
import { liveMessage } from "../domain/faults";

/** Visible (and, where supported, haptic) notice for live events that need the employee. */
export function LiveNotice({ navigate }: { navigate: (path: string) => void }) {
  const { t } = useI18n();
  const { last, connection } = useLive();
  const [dismissed, setDismissed] = useState(0);
  const text = last ? liveMessage(last.type, last.orderNumber, last.revisionNumber) : null;
  const visible = last !== null && text !== null && last.seq > dismissed;
  useEffect(() => { if (visible) navigator.vibrate?.([60, 40, 60]); }, [visible, last?.seq]);
  return (
    <>
      {connection === "reconnecting" && <p className="live-connection" role="status">{t("live.reconnecting")}</p>}
      {visible && <div className="live-notice" role="status" aria-live="assertive">
        <p>{text}</p>
        <div>
          {last.exceptionId && <button className="button button-primary" type="button" onClick={() => { setDismissed(last.seq); navigate(`/exceptions/${encodeURIComponent(last.exceptionId!)}`); }}>{t("live.openRequest")}</button>}
          {last.type.startsWith("document.") && last.orderId && <button className="button button-primary" type="button" onClick={() => { setDismissed(last.seq); navigate(last.type === "document.blocked" || last.type === "document.reactivated" ? `/orders/${encodeURIComponent(last.orderId!)}` : `/documents/${encodeURIComponent(last.orderId!)}`); }}>{t("common.open")}</button>}
          {last.transferId && <button className="button button-primary" type="button" onClick={() => { setDismissed(last.seq); navigate("/"); }}>{t("live.viewTransfers")}</button>}
          <button className="button button-link" type="button" onClick={() => setDismissed(last.seq)}>{t("common.close")}</button>
        </div>
      </div>}
    </>
  );
}
