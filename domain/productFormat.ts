import type { ProductionItem } from "./models";

const number = new Intl.NumberFormat("ro-RO", { maximumFractionDigits: 3 });

/** "300 × 260 cm", or a single labelled dimension when the source sent only one. */
export function formatMeasurements(item: ProductionItem): string | undefined {
  const measurements = item.measurements;
  if (!measurements || (measurements.width == null && measurements.height == null)) return undefined;
  const unit = measurements.unit ? ` ${measurements.unit}` : "";
  if (measurements.width != null && measurements.height != null) return `${number.format(measurements.width)} × ${number.format(measurements.height)}${unit}`;
  if (measurements.width != null) return `Lățime ${number.format(measurements.width)}${unit}`;
  return `Înălțime ${number.format(measurements.height ?? 0)}${unit}`;
}

export function formatMeters(value: number): string {
  return `${number.format(value)} m`;
}
