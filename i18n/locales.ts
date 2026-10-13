/**
 * Staff interface languages. The language of a signed-in employee comes only from the employee's central IAM
 * account (session `employee.locale`); never from the device, the browser language, the country or the IP address.
 * Romanian is the default and the fallback for any unknown value.
 */
export const SUPPORTED_LOCALES = ["ro", "tr", "en", "si"] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];
export const DEFAULT_LOCALE: Locale = "ro";

/** Native name (shown in the language picker and the profile) and the Intl tag used for dates and numbers. */
export const LOCALE_INFO: Readonly<Record<Locale, { name: string; intl: string }>> = Object.freeze({
  ro: { name: "Română", intl: "ro-RO" },
  tr: { name: "Türkçe", intl: "tr-TR" },
  en: { name: "English", intl: "en-GB" },
  si: { name: "සිංහල", intl: "si-LK" },
});

export function isLocale(value: unknown): value is Locale {
  return typeof value === "string" && (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/** Any unknown, empty or malformed value falls back to Romanian. */
export function normalizeLocale(value: unknown): Locale {
  return isLocale(value) ? value : DEFAULT_LOCALE;
}
