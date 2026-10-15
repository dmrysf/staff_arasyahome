import type { StaffOrder } from "./models";

/** A delayed GET must not replace a newer committed production revision. Rework can move back to an earlier stage. */
export function latestProductionView(current: StaffOrder | null, incoming: StaffOrder): StaffOrder {
  return current?.id === incoming.id && current.productionVersion > incoming.productionVersion ? current : incoming;
}
