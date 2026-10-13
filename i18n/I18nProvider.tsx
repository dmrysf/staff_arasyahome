import { createContext, useContext, useEffect, useMemo, type ReactNode } from "react";
import { LOCALE_INFO, type Locale } from "./locales";
import { formatDate, setActiveLocale, t, tKnown } from "./translator";

type I18nValue = { locale: Locale; t: typeof t; tKnown: typeof tKnown; formatDate: typeof formatDate };
const I18nContext = createContext<I18nValue>({ locale: "ro", t, tKnown, formatDate });

/**
 * Sets the interface language for everything below it and the document language (`<html lang>`, so screen readers,
 * hyphenation and Turkish casing follow it). Sinhala and all other languages are left-to-right.
 */
export function I18nProvider({ locale, children }: { locale: Locale; children: ReactNode }) {
  setActiveLocale(locale);
  const value = useMemo<I18nValue>(() => ({ locale, t, tKnown, formatDate }), [locale]);
  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = "ltr";
  }, [locale]);
  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  return useContext(I18nContext);
}

export function localeName(locale: Locale): string {
  return LOCALE_INFO[locale].name;
}
