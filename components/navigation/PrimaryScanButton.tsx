import { AppIcon } from "../icons/AppIcon";

export function PrimaryScanButton({ active, onSelect }: { active: boolean; onSelect: () => void }) {
  return (
    <button className={`primary-scan-nav${active ? " active" : ""}`} type="button" onClick={onSelect} aria-label="Scanează codul QR" aria-current={active ? "page" : undefined}>
      <AppIcon name="scan" size={29} />
    </button>
  );
}
