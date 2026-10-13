import { useState, type FormEvent } from "react";
import type { Session } from "../../services/contracts";
import { StaffServiceError } from "../../domain/models";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";
import { PasswordField } from "./PasswordField";
import { ArasyaLogo } from "../../components/brand/BrandLogo";
import { LOCALE_INFO, SUPPORTED_LOCALES, useI18n, type Locale, type MessageKey } from "../../i18n";

const loginErrors: Partial<Record<StaffServiceError["code"], MessageKey>> = {
  CONFIGURATION_ERROR: "app.authNotConfigured",
  SERVICE_UNAVAILABLE: "common.serviceUnavailable",
  NETWORK_UNAVAILABLE: "login.error.network",
  REQUEST_TIMEOUT: "login.error.timeout",
  RATE_LIMITED: "login.error.rateLimited",
  ACCOUNT_INACTIVE: "app.accountInactive",
};

/**
 * Sign-in. Nobody is known yet, so the small language switch only changes this screen on this device; after
 * sign-in the language assigned to the account applies (it may differ from the one chosen here).
 */
export function LoginScreen({ mode, notice, locale, onLocaleChange, onLogin }: { mode: StaffRuntimeMode; notice?: string; locale?: Locale; onLocaleChange?: (locale: Locale) => void; onLogin: (input: { username: string; password: string }) => Promise<Session> }) {
  const { t } = useI18n();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<MessageKey | "">("");

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    if (!username.trim() || !password) { setError("login.missing"); return; }
    setSubmitting(true);
    setError("");
    try { await onLogin({ username, password }); setPassword(""); }
    catch (caught) {
      setPassword("");
      setError(caught instanceof StaffServiceError ? loginErrors[caught.code] ?? "login.error.failed" : "login.error.failed");
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <header className="login-brand"><ArasyaLogo className="login-logo" priority /><span className="login-app">Staff</span></header>
      {onLocaleChange && <div className="login-languages" role="group" aria-label={t("login.language")}>
        {SUPPORTED_LOCALES.map((code) => <button key={code} type="button" lang={code} aria-pressed={code === locale} onClick={() => onLocaleChange(code)}>{LOCALE_INFO[code].name}</button>)}
      </div>}
      <form className="login-card" onSubmit={handleSubmit} noValidate>
        <div className="login-heading"><h1>{t("login.title")}</h1><p>{t("login.subtitle")}</p></div>
        {mode === "demo" && <div className="demo-notice" role="note"><span>{t("login.demoBadge")}</span> {t("login.demoNote")}</div>}
        {mode === "preview" && <div className="preview-notice" role="note"><span>{t("login.previewBadge")}</span> {t("login.previewNote")}</div>}
        {notice && <p className="session-notice" role="status">{notice}</p>}
        <label className="field"><span>{t("login.username")}</span><input autoComplete="username" inputMode="text" value={username} onChange={(event) => setUsername(event.target.value)} placeholder={t("login.usernamePlaceholder")} /></label>
        <PasswordField label={t("login.password")} toggleName={t("login.passwordName")} value={password} onChange={setPassword} autoComplete="current-password" placeholder="••••••••" />
        {error && <p className="form-error" role="alert">{t(error)}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting} aria-busy={submitting || undefined}>{submitting ? t("login.submitting") : t("login.submit")}<span aria-hidden="true">→</span></button>
      </form>
      <p className="login-footnote">{t("login.footnote")}</p>
    </main>
  );
}
