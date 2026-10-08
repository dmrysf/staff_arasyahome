import { useState, type FormEvent } from "react";
import { StaffServiceError } from "../../domain/models";
import { getErrorPresentation } from "../../services/errors";
import { PasswordField } from "./PasswordField";

/** Client-side checks in Romanian; the server enforces the same policy and remains the authority. */
export function passwordChangeProblem(input: { currentPassword: string; newPassword: string; confirmation: string; username: string }): string {
  if (!input.currentPassword || !input.newPassword || !input.confirmation) return "Completează parola actuală, parola nouă și confirmarea.";
  if (input.newPassword.length < 12) return "Parola nouă trebuie să aibă cel puțin 12 caractere.";
  if (input.newPassword === input.currentPassword) return "Parola nouă trebuie să fie diferită de parola actuală.";
  if (input.username && input.newPassword.toLowerCase().includes(input.username.toLowerCase())) return "Parola nouă nu poate conține numele de utilizator.";
  if (input.newPassword !== input.confirmation) return "Confirmarea nu coincide cu parola nouă.";
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
      setError(caught instanceof StaffServiceError ? getErrorPresentation(caught.code).message : "Parola nu a putut fi schimbată.");
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <div className="login-brand" aria-label="Arasya"><span className="brand-mark">A</span><strong>ARASYA</strong></div>
      <form className="login-card" onSubmit={handleSubmit} noValidate>
        <div className="login-heading">
          <h1>{voluntary ? "Schimbă parola" : "Setează parola personală"}</h1>
          <p>{voluntary ? `${displayName}, alege o parolă nouă. Celelalte sesiuni vor fi închise.` : `${displayName}, contul folosește o parolă temporară.`}</p>
        </div>
        {!voluntary && <div className="password-required" role="note"><strong>Pas obligatoriu</strong><span>Înainte de a accesa informațiile companiei trebuie să alegi o parolă personală. Parola temporară nu mai funcționează după schimbare.</span></div>}
        <PasswordField label={voluntary ? "Parola actuală" : "Parola actuală (temporară)"} toggleName="parola curentă" value={currentPassword} onChange={setCurrentPassword} autoComplete="current-password" />
        <PasswordField label="Parola nouă" toggleName="noua parolă" value={newPassword} onChange={setNewPassword} autoComplete="new-password" />
        <PasswordField label="Confirmă parola nouă" toggleName="confirmarea parolei" value={confirmation} onChange={setConfirmation} autoComplete="new-password" />
        <p className="login-footnote password-rules">Minimum 12 caractere, diferită de parola actuală și fără numele de utilizator. Aceeași parolă funcționează în toate aplicațiile Arasya.</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting}>{submitting ? "Se salvează…" : "Salvează parola nouă"}</button>
        {voluntary && onCancel
          ? <button className="button button-secondary" type="button" onClick={onCancel}>Anulează</button>
          : <button className="button button-secondary" type="button" onClick={() => { void onLogout(); }}>Ieși din cont</button>}
      </form>
    </main>
  );
}
