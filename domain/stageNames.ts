import type { ProductionWorkflow } from "./models";
import { tKnown, translate, type Locale, type MessageKey } from "../i18n";
import { CATALOGS } from "../i18n";

/**
 * Visible stage names by canonical stage id. The server's Romanian label is kept only as the fallback for a stage
 * this release does not know; stage ids, ordinals, order and every request payload stay exactly the server's.
 */
export function stageName(stageId: string | null | undefined, fallback?: string | null): string {
  return tKnown("stage", stageId, fallback ?? stageId);
}

function hasKey(key: string): key is MessageKey {
  return Object.hasOwn(CATALOGS.ro, key);
}

/** The same workflow with display names in one language; a new object only for display, never sent back. */
export function localizeWorkflow(workflow: ProductionWorkflow, locale: Locale): ProductionWorkflow {
  const workflowKey = `workflow.${workflow.id}`;
  return {
    ...workflow,
    name: hasKey(workflowKey) ? translate(locale, workflowKey) : workflow.name,
    stages: workflow.stages.map((stage) => {
      const key = `stage.${stage.id}`;
      return { ...stage, label: hasKey(key) ? translate(locale, key) : stage.label };
    }),
  };
}

/** Department names by IAM department key, role names by role key; unknown keys keep the IAM name. */
export const departmentName = (key: string | null | undefined, fallback: string): string => tKnown("department", key, fallback);
export const roleName = (key: string | null | undefined, fallback: string): string => tKnown("role", key, fallback);
/** Production problem reasons by reason key; an unknown key keeps the server's label. */
export const faultReasonName = (key: string | null | undefined, fallback: string): string => tKnown("fault.reason", key, fallback);
