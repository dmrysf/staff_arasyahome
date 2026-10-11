import type { StaffServiceError } from "../domain/models";
import { getErrorPresentation } from "../services/errors";
import { AppIcon } from "./icons/AppIcon";

export function ErrorState({ error, onAction, compact = false }: { error: StaffServiceError; onAction: () => void; compact?: boolean }) {
  const content = getErrorPresentation(error);
  return (
    <section className={`error-state${compact ? " compact" : ""}`} role="alert">
      <span className="state-icon error-icon" aria-hidden="true"><AppIcon name="alert" size={compact ? 20 : 24} /></span>
      <p className="eyebrow">Nu am putut continua</p>
      <h2>{content.title}</h2>
      <p>{content.message}</p>
      <button className="button button-primary" type="button" onClick={onAction}><AppIcon name="refresh" size={18} />{content.action}</button>
    </section>
  );
}
