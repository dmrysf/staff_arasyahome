import { AppIcon } from "../icons/AppIcon";

export function PrimaryScanButton({ active, onSelect }: { active: boolean; onSelect: () => void }) {
  return (
    <button className={`primary-scan-nav${active ? " active" : ""}`} type="button" onClick={onSelect} aria-label="Scanează codul QR" aria-current={active ? "page" : undefined}>
      <span className="primary-scan-icon"><AppIcon name="scan" size={26} /></span>
      <span className="primary-scan-label" aria-hidden="true">Scanează QR</span>
    </button>
  );
}
