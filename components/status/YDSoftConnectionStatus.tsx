import { liveLabels } from "../../i18n";

export type SystemConnectionStatus = "connected" | "checking" | "disconnected";

export const systemConnectionLabels: Readonly<Record<SystemConnectionStatus, string>> = liveLabels("ydsoft.", ["connected", "checking", "disconnected"]);

export function YDSoftConnectionStatus({ status = "connected" }: { status?: SystemConnectionStatus }) {
  return (
    <span className={`system-status status-${status}`} role="status">
      <span className="system-status-dot" aria-hidden="true" />
      <span className="system-status-label">{systemConnectionLabels[status]}</span>
    </span>
  );
}
