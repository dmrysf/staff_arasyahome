import { useState } from "react";
import type { Employee } from "../../domain/models";
import { StaffServiceError } from "../../domain/models";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";
import { departmentName, roleName } from "../../domain/stageNames";
import { localeName, normalizeLocale, useI18n } from "../../i18n";

export function ProfileScreen({ employee, mode, onLogout, onChangePassword, passwordChanged = false }: { employee: Employee; mode: StaffRuntimeMode; onLogout: () => Promise<void>; onChangePassword?: () => void; passwordChanged?: boolean }) {
  const { t } = useI18n();
  const initials = employee.displayName.split(" ").map((part) => part[0]).join("").slice(0, 2);
  const [loggingOut, setLoggingOut] = useState(false);
  const [logoutError, setLogoutError] = useState<"" | "common.serviceUnavailable" | "profile.signOutFailed">("");
  async function handleLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    setLogoutError("");
    try { await onLogout(); }
    catch (error) {
      setLogoutError(error instanceof StaffServiceError && error.code === "SERVICE_UNAVAILABLE" ? "common.serviceUnavailable" : "profile.signOutFailed");
      setLoggingOut(false);
    }
  }
  return (
    <div className="screen-stack profile-screen">
      <section className="profile-identity"><span className="profile-avatar">{initials}</span><div><h1>{employee.displayName}</h1><p>{departmentName(employee.departmentKey, employee.department)}</p>{mode === "preview" && <span className="profile-preview-label">{t("profile.previewMode")}</span>}</div></section>
      <section className="profile-panel"><div><span>{t("profile.role")}</span><strong>{roleName(employee.role, employee.role)}</strong></div><div><span>{t("profile.username")}</span><strong>{employee.username}</strong></div><div data-testid="profile-language"><span>{t("profile.language")}</span><strong lang={normalizeLocale(employee.locale)}>{localeName(normalizeLocale(employee.locale))}</strong></div><div><span>{t("profile.environment")}</span><strong>{mode === "demo" ? t("profile.envDemo") : mode === "preview" ? t("profile.envPreview") : t("profile.envProduction")}</strong></div></section>
      <p className="profile-language-note">{t("profile.languageNote")}</p>
      <section className="security-note"><span aria-hidden="true">◉</span><div><strong>{t("profile.securityTitle")}</strong><p>{t("profile.securityBody")}</p></div></section>
      {passwordChanged && <p className="session-notice" role="status">{t("profile.passwordChanged")}</p>}
      {onChangePassword && mode !== "demo" && <button className="button button-secondary button-large" type="button" onClick={onChangePassword}>{t("profile.changePassword")}</button>}
      {logoutError && <p className="form-error" role="alert">{t(logoutError)}</p>}
      <button className="button button-secondary button-large" type="button" disabled={loggingOut} onClick={handleLogout}>{loggingOut ? t("profile.signingOut") : t("common.signOut")}</button>
    </div>
  );
}
