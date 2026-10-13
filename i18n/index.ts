export { DEFAULT_LOCALE, LOCALE_INFO, SUPPORTED_LOCALES, isLocale, normalizeLocale, type Locale } from "./locales";
export { activeLocale, formatDate, setActiveLocale, t, tKnown, translate, CATALOGS, type MessageParams } from "./translator";
export type { Catalog, MessageKey, Plural } from "./types";
export { liveLabels, liveRecords } from "./labels";
export { I18nProvider, useI18n, localeName } from "./I18nProvider";
export { readDeviceLocale, saveDeviceLocale, DEVICE_LOCALE_KEY } from "./devicePreference";
