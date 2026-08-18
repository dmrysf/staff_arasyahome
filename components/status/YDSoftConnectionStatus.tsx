export type SystemConnectionStatus = "connected" | "checking" | "disconnected";

export const systemConnectionLabels: Record<SystemConnectionStatus, string> = {
  connected: "YD Soft conectat",
  checking: "Se verifică...",
  disconnected: "YD Soft deconectat",
};

export function YDSoftConnectionStatus({ status = "connected" }: { status?: SystemConnectionStatus }) {
  return (
    <span className={`system-status status-${status}`} role="status">
      <span className="system-status-dot" aria-hidden="true" />
      {systemConnectionLabels[status]}
    </span>
  );
}
