import type { ProductionWorkflow } from "./models";

export const CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE = Object.freeze([
  Object.freeze({ id: "waiting", ordinal: 1 }),
  Object.freeze({ id: "material-preparation", ordinal: 2 }),
  Object.freeze({ id: "workshop-receiving", ordinal: 3 }),
  Object.freeze({ id: "labeling", ordinal: 4 }),
  Object.freeze({ id: "material-straightening", ordinal: 5 }),
  Object.freeze({ id: "bottom-hem", ordinal: 6 }),
  Object.freeze({ id: "side-hem", ordinal: 7 }),
  Object.freeze({ id: "ironing", ordinal: 8 }),
  Object.freeze({ id: "height", ordinal: 9 }),
  Object.freeze({ id: "header-tape", ordinal: 10 }),
  Object.freeze({ id: "sewing-finishing", ordinal: 11 }),
  Object.freeze({ id: "quality-control", ordinal: 12 }),
  Object.freeze({ id: "packing", ordinal: 13 }),
  Object.freeze({ id: "delivery", ordinal: 14 }),
] as const);

type WorkflowStructure = Pick<ProductionWorkflow, "id" | "version" | "stages">;

export function hasValidCanonicalProductionWorkflowStructure(workflow: WorkflowStructure): boolean {
  if (workflow.id !== "curtain-production" || workflow.version !== 1) return true;
  if (workflow.stages.length !== CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.length) return false;
  return CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.every((expected, index) => {
    const actual = workflow.stages[index];
    return actual?.id === expected.id && actual.ordinal === expected.ordinal;
  });
}
