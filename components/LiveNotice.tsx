import { useEffect, useState } from "react";
import { useLive } from "../app/liveContext";
import { liveMessage } from "../domain/faults";

/** Visible (and, where supported, haptic) notice for live events that need the employee. */
export function LiveNotice({ navigate }: { navigate: (path: string) => void }) {
  const { last, connection } = useLive();
  const [dismissed, setDismissed] = useState(0);
  const text = last ? liveMessage(last.type, last.orderNumber) : null;
  const visible = last !== null && text !== null && last.seq > dismissed;
  useEffect(() => { if (visible) navigator.vibrate?.([60, 40, 60]); }, [visible, last?.seq]);
  return (
    <>
      {connection === "reconnecting" && <p className="live-connection" role="status">Conexiune întreruptă · reconectare automată…</p>}
      {visible && <div className="live-notice" role="status" aria-live="assertive">
        <p>{text}</p>
        <div>
          {last.exceptionId && <button className="button button-primary" type="button" onClick={() => { setDismissed(last.seq); navigate(`/exceptions/${encodeURIComponent(last.exceptionId!)}`); }}>Deschide cererea</button>}
          <button className="button button-link" type="button" onClick={() => setDismissed(last.seq)}>Închide</button>
        </div>
      </div>}
    </>
  );
}
