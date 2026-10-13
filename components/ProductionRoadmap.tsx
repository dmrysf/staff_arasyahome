import { useEffect, useRef } from "react";
import { useI18n } from "../i18n";
import type { ProductionWorkflow } from "../domain/models";
import { getStageById, getStageProgress } from "../domain/productionWorkflow";
import { AppIcon } from "./icons/AppIcon";

export function ProductionRoadmap({ workflow, currentStageId }: { workflow: ProductionWorkflow; currentStageId: string }) {
  const { t } = useI18n();
  const currentRef = useRef<HTMLLIElement>(null);
  const progress = getStageProgress(workflow, currentStageId);
  const current = getStageById(workflow, currentStageId);

  useEffect(() => {
    currentRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "center" });
  }, [currentStageId, workflow.version]);

  return (
    <section className="production-roadmap" aria-labelledby="production-roadmap-title">
      <header className="roadmap-heading">
        <div><p className="eyebrow">{t("roadmap.eyebrow")}</p><h2 id="production-roadmap-title">{workflow.name}</h2></div>
        <span>v{workflow.version}</span>
      </header>
      {!current && <p className="roadmap-unavailable" role="status">{t("common.stageUnavailable")}</p>}
      {/* eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- a horizontally scrollable region must be keyboard focusable (WCAG 2.1.1) */}
      <ol className="roadmap-track" aria-label={t("roadmap.stages", { count: workflow.stages.length })} tabIndex={0}>
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
              <span className="roadmap-marker" aria-hidden="true">{state === "completed" ? <AppIcon name="check" size={16} /> : stage.ordinal}</span>
              <span className="roadmap-copy"><small>{state === "completed" ? t("roadmap.completed") : state === "current" ? t("roadmap.current") : t("roadmap.upcoming")}</small><strong>{stage.label}</strong></span>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
