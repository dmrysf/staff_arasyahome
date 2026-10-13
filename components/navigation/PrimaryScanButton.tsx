import { AppIcon } from "../icons/AppIcon";
import { useI18n } from "../../i18n";

export function PrimaryScanButton({ active, onSelect }: { active: boolean; onSelect: () => void }) {
  const { t } = useI18n();
  return (
    <button className={`primary-scan-nav${active ? " active" : ""}`} type="button" onClick={onSelect} aria-label={t("nav.scanLabel")} aria-current={active ? "page" : undefined}>
      <span className="primary-scan-icon"><AppIcon name="scan" size={26} /></span>
      <span className="primary-scan-label" aria-hidden="true">{t("nav.scan")}</span>
    </button>
  );
}
