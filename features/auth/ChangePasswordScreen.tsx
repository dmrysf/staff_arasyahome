import { useState, type FormEvent } from "react";
import { StaffServiceError } from "../../domain/models";
import { getErrorPresentation } from "../../services/errors";

/** Forced first-login (or post-reset) password change; the API allows nothing else until it succeeds. */
export function ChangePasswordScreen({ displayName, onChange, onLogout }: { displayName: string; onChange: (input: { currentPassword: string; newPassword: string }) => Promise<unknown>; onLogout: () => Promise<void> }) {
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    if (!currentPassword || !newPassword) { setError("Completează parola actuală și parola nouă."); return; }
    if (newPassword.length < 12) { setError("Parola nouă trebuie să aibă cel puțin 12 caractere."); return; }
    if (newPassword !== confirmation) { setError("Confirmarea nu coincide cu parola nouă."); return; }
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
        <div className="login-heading"><h1>Schimbă parola</h1><p>{displayName}, contul folosește o parolă temporară. Alege o parolă nouă pentru a continua.</p></div>
        <label className="field"><span>Parola actuală</span><input autoComplete="current-password" type="password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} /></label>
        <label className="field"><span>Parola nouă</span><input autoComplete="new-password" type="password" value={newPassword} onChange={(event) => setNewPassword(event.target.value)} /></label>
        <label className="field"><span>Confirmă parola nouă</span><input autoComplete="new-password" type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></label>
        <p className="login-footnote">Minimum 12 caractere, diferită de parola actuală.</p>
        {error && <p className="form-error" role="alert">{error}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting}>{submitting ? "Se salvează…" : "Salvează parola nouă"}</button>
        <button className="button button-secondary" type="button" onClick={() => { void onLogout(); }}>Ieși din cont</button>
      </form>
    </main>
  );
}
