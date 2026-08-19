import { useEffect, useRef } from "react";
import type { ProductionWorkflow } from "../domain/models";
import { getStageById, getStageProgress } from "../domain/productionWorkflow";

export function ProductionRoadmap({ workflow, currentStageId }: { workflow: ProductionWorkflow; currentStageId: string }) {
  const currentRef = useRef<HTMLLIElement>(null);
  const progress = getStageProgress(workflow, currentStageId);
  const current = getStageById(workflow, currentStageId);

  useEffect(() => {
    currentRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
  }, [currentStageId, workflow.version]);

  return (
    <section className="production-roadmap" aria-labelledby="production-roadmap-title">
      <header className="roadmap-heading">
        <div><p className="eyebrow">Flux de producție</p><h2 id="production-roadmap-title">{workflow.name}</h2></div>
        <span>v{workflow.version}</span>
      </header>
      {!current && <p className="roadmap-unavailable" role="status">Etapă indisponibilă</p>}
      <ol className="roadmap-track" aria-label={`Etape producție: ${workflow.stages.length}`}>
        {workflow.stages.map((stage, index) => {
          const state = progress.index < 0 || index > progress.index ? "future" : index === progress.index ? "current" : "completed";
          return (
            <li
              className={`roadmap-stage ${state}`}
              data-stage-id={stage.id}
              key={stage.id}
              ref={state === "current" ? currentRef : undefined}
              aria-current={state === "current" ? "step" : undefined}
            >
              <span className="roadmap-marker" aria-hidden="true">{state === "completed" ? "✓" : stage.ordinal}</span>
              <span className="roadmap-copy"><small>{state === "completed" ? "Finalizată" : state === "current" ? "Etapa actuală" : "Urmează"}</small><strong>{stage.label}</strong></span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
