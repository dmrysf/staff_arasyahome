import type { ProductionStage } from "../domain/models";

export function StageLabel({ stage, muted = false }: { stage?: ProductionStage; muted?: boolean }) {
  if (!stage) return <span className={`stage-label unavailable${muted ? " muted" : ""}`}><span>—</span>Etapă indisponibilă</span>;
  return <span className={`stage-label${muted ? " muted" : ""}`}><span>{stage.ordinal}</span>{stage.label}</span>;
}
