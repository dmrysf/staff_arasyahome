import { createContext, useContext, useEffect, useState } from "react";
import type { ReactNode } from "react";
import type { LiveEvent } from "../domain/models";
import type { LiveService } from "../services/contracts";
import { nextLiveNotice } from "../domain/faults";

/** byOrder counts events per order id so an order screen re-reads only when its own order changed. */
type LiveState = { revision: number; last: LiveEvent | null; connection: "connected" | "reconnecting"; byOrder: Readonly<Record<string, number>> };
const LiveContext = createContext<LiveState>({ revision: 0, last: null, connection: "connected", byOrder: {} });

/** Holds the single live subscription of the signed-in Staff session; screens re-read on each revision. */
export function LiveProvider({ service, enabled, children }: { service: LiveService; enabled: boolean; children: ReactNode }) {
  const [state, setState] = useState<LiveState>({ revision: 0, last: null, connection: "connected", byOrder: {} });
  useEffect(() => {
    if (!enabled) return undefined;
    return service.subscribe(
      (event) => setState((current) => ({ ...current, revision: current.revision + 1, last: nextLiveNotice(current.last, event),
        byOrder: event.orderId ? { ...current.byOrder, [event.orderId]: (current.byOrder[event.orderId] ?? 0) + 1 } : current.byOrder })),
      (connection) => setState((current) => ({ ...current, connection, revision: connection === "connected" ? current.revision + 1 : current.revision })),
    );
  }, [enabled, service]);
  return <LiveContext.Provider value={state}>{children}</LiveContext.Provider>;
}

export const useLive = () => useContext(LiveContext);
