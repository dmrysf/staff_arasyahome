import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../../i18n";
import { AppIcon } from "../../../components/icons/AppIcon";

/**
 * Small shared pieces of the operator workspace (Staff 2.58.0): a copy button with a short confirmation, the quantity
 * stepper and a VAT tag. Scoped to the `ow-` classes (styles/operatorWorkspace.css), never global.
 */

/** Copies a value and says so for 1.5 s (a live region announces it); a blocked clipboard simply does nothing. */
export function CopyButton({ value, label, testId, compact = false }: { value: string; label: string; testId?: string; compact?: boolean }) {
  const { t } = useI18n();
  const [done, setDone] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);
  const copy = () => {
    void navigator.clipboard?.writeText(value).then(() => {
      setDone(true);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => setDone(false), 1500);
    }).catch(() => undefined);
  };
  return (
    <button type="button" className={`ow-copy${compact ? " is-compact" : ""}${done ? " is-done" : ""}`} onClick={copy} aria-label={label} title={label} data-testid={testId}>
      <AppIcon name={done ? "check" : "copy"} size={16} />
      <span className={compact ? "visually-hidden" : undefined} aria-live="polite">{done ? t("dd.copied") : label}</span>
    </button>
  );
}

/** The quantity ordered: 44 px buttons, typed digits accepted, 1 to 100 like the shop's edit limit. */
export function Stepper({ value, onChange, testId, label }: { value: number; onChange: (value: number) => void; testId: string; label: string }) {
  const { t } = useI18n();
  const [typed, setTyped] = useState<string | null>(null);
  return (
    <div className="ow-stepper" role="group" aria-label={label}>
      <button type="button" disabled={value <= 1} onClick={() => onChange(Math.max(1, value - 1))} aria-label={t("dd.qty.less")} data-testid={`${testId}-minus`}>−</button>
      <input type="text" inputMode="numeric" value={typed ?? String(value)} aria-label={label} data-testid={testId}
        onChange={(event) => {
          const raw = event.target.value.replace(/\D/g, "").slice(0, 3);
          setTyped(raw);
          const next = Number.parseInt(raw, 10);
          if (Number.isInteger(next) && next >= 1 && next <= 100) onChange(next);
        }}
        onBlur={() => setTyped(null)} onKeyDown={(event) => { if (event.key === "Enter") event.preventDefault(); }} />
      <button type="button" disabled={value >= 100} onClick={() => onChange(Math.min(100, value + 1))} aria-label={t("dd.qty.more")} data-testid={`${testId}-plus`}>+</button>
    </div>
  );
}

export function VatTag({ vat }: { vat: "incl" | "excl" }) {
  const { t } = useI18n();
  return <span className="ow-vat">{t(vat === "incl" ? "dd.vat.incl" : "dd.vat.excl")}</span>;
}
