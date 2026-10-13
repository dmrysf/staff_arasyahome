import { isLocale, type Locale } from "./locales";

/**
 * The language of the sign-in screen on this device, chosen before anyone is known. It never reaches the server
 * and never changes an account: after sign-in the account language applies. Only this one key is stored, and the
 * app works the same when browser storage is unavailable.
 */
export const DEVICE_LOCALE_KEY = "arasya.staff.locale";
type PreferenceStorage = Pick<Storage, "getItem" | "setItem">;

function browserStorage(): PreferenceStorage | undefined {
  try { return globalThis.localStorage; }
  catch { return undefined; }
}

export function readDeviceLocale(storage: PreferenceStorage | undefined = browserStorage()): Locale | null {
  try {
    const value = storage?.getItem(DEVICE_LOCALE_KEY) ?? null;
    return isLocale(value) ? value : null;
  } catch { return null; }
}

export function saveDeviceLocale(locale: Locale, storage: PreferenceStorage | undefined = browserStorage()): void {
  try { storage?.setItem(DEVICE_LOCALE_KEY, locale); }
  catch { /* The sign-in screen still works without browser storage. */ }
}
