import { physicalText as pt } from "../i18n/physical";
import type { PieceOutcome } from "../domain/pieces";
/** No default repair or replacement. Every affected identity needs an explicit physical result. */
export function PieceOutcomeEditor({ units, value, onChange, disabled }: { units: { id: string; label: string }[]; value: PieceOutcome[]; onChange: (value: PieceOutcome[]) => void; disabled: boolean }) {
  return <fieldset disabled={disabled} data-testid="piece-outcomes"><legend>{pt("physical.rezultatul_fizic_al_retaierii")}</legend>
    {units.map(unit => { const selected = value.find(v => v.unitId === unit.id); return <fieldset key={unit.id}><legend>{unit.label}</legend>
      <label>{pt("physical.rezultat")}<select aria-label={pt("physical.rezultat")} value={selected?.outcome ?? ""} onChange={e => onChange([...value.filter(v => v.unitId !== unit.id), ...(e.target.value ? [{ unitId: unit.id, outcome: e.target.value as "repair" | "replacement" }] : [])])}>
        <option value="">{pt("physical.alege_rezultatul_verificat")}</option><option value="repair">{pt("physical.reparata_aceeasi_bucata_ciclu_nou")}</option><option value="replacement">{pt("physical.inlocuita_bucata_si_eticheta_noua")}</option></select></label>
      {selected?.outcome === "replacement" && <label>{pt("physical.destinatia_originalului")}<select aria-label={pt("physical.destinatia_originalului")} value={selected.originalDisposition ?? ""} onChange={e => onChange(value.map(v => v.unitId === unit.id ? { ...v, originalDisposition: e.target.value as "quarantined" | "scrapped" } : v))}>
        <option value="">{pt("physical.confirma_destinatia")}</option><option value="quarantined">{pt("physical.carantina")}</option><option value="scrapped">{pt("physical.casat")}</option></select></label>}
    </fieldset>; })}
    <p>{pt("physical.eticheta_originala_a_unei_inlocuiri_nu_mai")}</p>
  </fieldset>;
}
export const outcomesReady = (units: { id: string }[], values: PieceOutcome[]) => units.length > 0 && units.every(unit => values.some(v => v.unitId === unit.id && (v.outcome === "repair" || (v.outcome === "replacement" && Boolean(v.originalDisposition)))));
