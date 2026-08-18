import type { OrderSource } from "../domain/models";

export const sourceLabels: Record<OrderSource, string> = {
  trendhome: "Trendhome",
  outletperdele: "OutletPerdele",
  b2b: "B2B",
  marketplace: "Marketplace",
  unknown: "Sursă necunoscută",
};

export function SourceBadge({ source }: { source: OrderSource }) {
  return <span className={`source-badge source-${source}`}>{sourceLabels[source]}</span>;
}
