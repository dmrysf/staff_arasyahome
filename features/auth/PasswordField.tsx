import { useId, useState } from "react";
import { useI18n } from "../../i18n";

/**
 * A password input with its own Show/Hide control. Each control has a distinct accessible name (it never
 * repeats the field label) and starts hidden; showing one field never reveals another.
 */
export function PasswordField({ label, toggleName, value, onChange, autoComplete, placeholder }: {
  label: string;
  toggleName: string;
  value: string;
  onChange: (value: string) => void;
  autoComplete: "current-password" | "new-password";
  placeholder?: string;
}) {
  const { t } = useI18n();
  const id = useId();
  const [visible, setVisible] = useState(false);
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <div className="password-input">
        <input id={id} type={visible ? "text" : "password"} autoComplete={autoComplete} autoCapitalize="none" autoCorrect="off" spellCheck={false} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} />
        <button className="password-toggle" type="button" aria-controls={id} aria-pressed={visible} aria-label={visible ? t("password.hideLabel", { name: toggleName }) : t("password.showLabel", { name: toggleName })} onClick={() => setVisible((current) => !current)}>{visible ? t("password.hide") : t("password.show")}</button>
      </div>
    </div>
  );
}
