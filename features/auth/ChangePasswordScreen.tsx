import { useState, type FormEvent } from "react";
import { t, useI18n } from "../../i18n";
import { StaffServiceError } from "../../domain/models";
import { getErrorPresentation } from "../../services/errors";
import { PasswordField } from "./PasswordField";
import { ArasyaLogo } from "../../components/brand/BrandLogo";

/** Client-side checks in the active language; the server enforces the same policy and remains the authority. */
export function passwordChangeProblem(input: { currentPassword: string; newPassword: string; confirmation: string; username: string }): string {
  if (!input.currentPassword || !input.newPassword || !input.confirmation) return t("password.problem.missing");
  if (input.newPassword.length < 12) return t("password.problem.length");
  if (input.newPassword === input.currentPassword) return t("password.problem.same");
  if (input.username && input.newPassword.toLowerCase().includes(input.username.toLowerCase())) return t("password.problem.username");
  if (input.newPassword !== input.confirmation) return t("password.problem.mismatch");
  return "";
}

/**
 * Forced first-login (or post-reset) password change, and the voluntary change from the profile. While the
 * password is temporary the API allows nothing else until this succeeds.
 */
export function ChangePasswordScreen({ displayName, username, voluntary = false, onChange, onLogout, onCancel }: {
  displayName: string;
  username: string;
  voluntary?: boolean;
  onChange: (input: { currentPassword: string; newPassword: string }) => Promise<unknown>;
  onLogout: () => Promise<void>;
  onCancel?: () => void;
}) {
  useI18n();
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    const problem = passwordChangeProblem({ currentPassword, newPassword, confirmation, username });
    if (problem) { setError(problem); return; }
    setSubmitting(true);
    setError("");
    try { await onChange({ currentPassword, newPassword }); }
    catch (caught) {
      setError(caught instanceof StaffServiceError ? getErrorPresentation(caught).message : t("password.failed"));
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <header className="login-brand"><ArasyaLogo className="login-logo" priority /><span className="login-app">Staff</span></header>
      <form className="login-card" onSubmit={handleSubmit} noValidate>
        <div className="login-heading">
          <h1>{voluntary ? t("password.titleVoluntary") : t("password.titleRequired")}</h1>
          <p>{voluntary ? t("password.leadVoluntary", { name: displayName }) : t("password.leadRequired", { name: displayName })}</p>
        </div>
        {!voluntary && <div className="password-required" role="note"><strong>{t("password.requiredStep")}</strong><span>{t("password.requiredNote")}</span></div>}
        <PasswordField label={voluntary ? t("password.current") : t("password.currentTemporary")} toggleName={t("password.currentName")} value={currentPassword} onChange={setCurrentPassword} autoComplete="current-password" />
        <PasswordField label={t("password.new")} toggleName={t("password.newName")} value={newPassword} onChange={setNewPassword} autoComplete="new-password" />
        <PasswordField label={t("password.confirm")} toggleName={t("password.confirmName")} value={confirmation} onChange={setConfirmation} autoComplete="new-password" />
        <p className="login-footnote password-rules">{t("password.rules")}</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting} aria-busy={submitting || undefined}>{submitting ? t("password.saving") : t("password.save")}</button>
        {voluntary && onCancel
          ? <button className="button button-secondary" type="button" onClick={onCancel}>{t("common.cancel")}</button>
          : <button className="button button-secondary" type="button" onClick={() => { void onLogout(); }}>{t("common.signOut")}</button>}
      </form>
    </main>
  );
}
