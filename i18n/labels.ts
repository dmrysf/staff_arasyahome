import { t } from "./translator";
import type { MessageKey } from "./types";

/**
 * A label map whose values are read in the active language at the moment they are used, so existing lookups
 * (`labels[code]`, `Object.keys(labels)`, `Object.hasOwn(labels, code)`) keep working in every language.
 * The map is frozen: its codes are fixed; only the language of the words changes.
 */
export function liveLabels<K extends string>(prefix: string, codes: readonly K[]): Readonly<Record<K, string>> {
  const labels = {} as Record<K, string>;
  for (const code of codes) Object.defineProperty(labels, code, { enumerable: true, get: () => t(`${prefix}${code}` as MessageKey) });
  return Object.freeze(labels);
}

/** The same for records of several texts per code (for example a title and a note). */
export function liveRecords<K extends string, F extends string>(prefix: string, codes: readonly K[], fields: readonly F[]): Readonly<Record<K, Readonly<Record<F, string>>>> {
  const records = {} as Record<K, Record<F, string>>;
  for (const code of codes) {
    const record = {} as Record<F, string>;
    for (const field of fields) Object.defineProperty(record, field, { enumerable: true, get: () => t(`${prefix}${code}.${field}` as MessageKey) });
    records[code] = Object.freeze(record);
  }
  return Object.freeze(records);
}
