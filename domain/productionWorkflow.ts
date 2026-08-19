import type { ProductionStage, ProductionWorkflow } from "./models";

export function getStageById(workflow: ProductionWorkflow, stageId: string): ProductionStage | undefined {
  return workflow.stages.find((stage) => stage.id === stageId);
}

export function getNextStage(workflow: ProductionWorkflow, stageId: string): ProductionStage | undefined {
  const index = workflow.stages.findIndex((stage) => stage.id === stageId);
  return index < 0 ? undefined : workflow.stages[index + 1];
}

export function getPreviousStage(workflow: ProductionWorkflow, stageId: string): ProductionStage | undefined {
  const index = workflow.stages.findIndex((stage) => stage.id === stageId);
  return index > 0 ? workflow.stages[index - 1] : undefined;
}

export function getStageProgress(workflow: ProductionWorkflow, stageId: string) {
  const index = workflow.stages.findIndex((stage) => stage.id === stageId);
  return {
    index,
    completed: index < 0 ? 0 : index,
    total: workflow.stages.length,
    ratio: index < 0 || workflow.stages.length < 2 ? 0 : index / (workflow.stages.length - 1),
  };
}
