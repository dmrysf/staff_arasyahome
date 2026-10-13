import type { OrderSource } from "../domain/models";
import { t } from "../i18n";

// Brand names stay as they are; only "unknown" is a word.
export const sourceLabels: Readonly<Record<OrderSource, string>> = Object.freeze({
  trendhome: "Trendhome",
  outletperdele: "OutletPerdele",
  trendyol: "Trendyol",
  b2b: "B2B",
  marketplace: "Marketplace",
  get unknown() { return t("source.unknown"); },
});

export function SourceBadge({ source }: { source: OrderSource }) {
  return <span className={`source-badge source-${source}`}>{sourceLabels[source]}</span>;
}
