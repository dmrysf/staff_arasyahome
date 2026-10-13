import type { ProductionItem } from "./models";
import { t } from "../i18n";

// Measurement values keep the production document format (Romanian decimal comma) in every language, so the
// screen always matches the printed sheet; only the words around them are translated.
const number = new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 3 });

/** "300 × 260 cm", or a single labelled dimension when the source sent only one. */
export function formatMeasurements(item: ProductionItem): string | undefined {
  const measurements = item.measurements;
  if (!measurements || (measurements.width == null && measurements.height == null)) return undefined;
  const unit = measurements.unit ? ` ${measurements.unit}` : "";
  if (measurements.width != null && measurements.height != null) return `${number.format(measurements.width)} × ${number.format(measurements.height)}${unit}`;
  if (measurements.width != null) return t("product.widthOnly", { value: `${number.format(measurements.width)}${unit}` });
  return t("product.heightOnly", { value: `${number.format(measurements.height ?? 0)}${unit}` });
}

export function formatMeters(value: number): string {
  return `${number.format(value)} m`;
}
