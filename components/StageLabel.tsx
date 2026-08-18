import type { ProductionStage } from "../domain/models";

export function StageLabel({ stage, muted = false }: { stage: ProductionStage; muted?: boolean }) {
  return <span className={`stage-label${muted ? " muted" : ""}`}><span>{stage.ordinal}</span>{stage.label}</span>;
}
