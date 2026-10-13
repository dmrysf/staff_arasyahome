import { ro } from "./messages/ro";
import { tr } from "./messages/tr";
import { en } from "./messages/en";
import { si } from "./messages/si";
import { DEFAULT_LOCALE, LOCALE_INFO, normalizeLocale, type Locale } from "./locales";
import type { Catalog, MessageKey, Plural } from "./types";

export const CATALOGS: Readonly<Record<Locale, Catalog>> = Object.freeze({ ro, tr, en, si });

export type MessageParams = Readonly<Record<string, string | number>>;

const pluralRules = new Map<Locale, Intl.PluralRules>();
function pluralCategory(locale: Locale, count: number): Intl.LDMLPluralRule {
  let rules = pluralRules.get(locale);
  if (!rules) { rules = new Intl.PluralRules(LOCALE_INFO[locale].intl); pluralRules.set(locale, rules); }
  return rules.select(count);
}

function interpolate(text: string, params: MessageParams | undefined): string {
  if (!params) return text;
  return text.replace(/\{(\w+)\}/g, (match, name: string) => Object.hasOwn(params, name) ? String(params[name]) : match);
}

/** One message of one locale: a plural form chosen by `count`, then `{name}` placeholders filled from `params`. */
export function translate(locale: Locale, key: MessageKey, params?: MessageParams): string {
  const message = CATALOGS[locale][key] ?? CATALOGS[DEFAULT_LOCALE][key];
  // A key missing from every catalog shows the key itself (never a crash); the catalog tests forbid that state.
  if (message === undefined) return key;
  if (typeof message === "string") return interpolate(message, params);
  const plural = message as Plural;
  const count = typeof params?.count === "number" ? params.count : Number(params?.count ?? 0);
  const form = plural[pluralCategory(locale, count) as keyof Plural] ?? plural.other;
  return interpolate(form, params);
}

/**
 * The active interface language. Components read it through `useI18n()`; domain helpers that build visible text
 * call `t()` at render time. The provider sets it before its children render, so every text of one render uses
 * the same language. Romanian until a provider says otherwise (also in unit tests).
 */
let active: Locale = DEFAULT_LOCALE;

export function setActiveLocale(locale: Locale): void { active = normalizeLocale(locale); }
export function activeLocale(): Locale { return active; }
export function t(key: MessageKey, params?: MessageParams): string { return translate(active, key, params); }

/** A translated name for a known code, or the server's own text for a code this release does not know. */
export function tKnown(prefix: string, code: string | null | undefined, fallback: string | null | undefined): string {
  const key = `${prefix}.${code ?? ""}` as MessageKey;
  if (code && Object.hasOwn(CATALOGS[DEFAULT_LOCALE], key)) return translate(active, key);
  return fallback ?? code ?? "—";
}

/**
 * The Intl tag used for dates of one language. A browser whose date data lacks the language (some builds ship no
 * Sinhala calendar names) would silently fall back to its own default; British English is chosen explicitly instead,
 * so a Sinhala screen never shows dates in a random language.
 */
const dateTags = new Map<Locale, string>();
export function dateTag(locale: Locale): string {
  let tag = dateTags.get(locale);
  if (!tag) {
    const wanted = LOCALE_INFO[locale].intl;
    tag = Intl.DateTimeFormat.supportedLocalesOf([wanted]).length > 0 ? wanted : "en-GB";
    dateTags.set(locale, tag);
  }
  return tag;
}

const formatters = new Map<string, Intl.DateTimeFormat>();
/** Dates and times in the active language (the factory clock, Europe/Bucharest, unless the options say otherwise). */
export function formatDate(value: Date | number | string, options: Intl.DateTimeFormatOptions): string {
  const key = `${active}|${JSON.stringify(options)}`;
  let formatter = formatters.get(key);
  if (!formatter) { formatter = new Intl.DateTimeFormat(dateTag(active), options); formatters.set(key, formatter); }
  return formatter.format(typeof value === "string" ? new Date(value) : value);
}
