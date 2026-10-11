import type { CSSProperties } from "react";
import { AppIcon } from "../icons/AppIcon";

export type TaskStepState = "done" | "current" | "ready" | "upcoming" | "blocked";
export type TaskStep = { id: string; label: string; state: TaskStepState; detail: string };

/** Words for each state, so the steps never depend on color or the check mark alone. */
export const taskStepStateLabels: Record<TaskStepState, string> = {
  done: "Finalizat",
  current: "Pasul curent",
  ready: "Disponibil",
  upcoming: "Urmează",
  blocked: "Blocat",
};

/**
 * Guided sequence over server states. It only presents what the server already reported: a step is "done" only when
 * the server data proves it, and nothing here performs or predicts an action.
 */
export function TaskSteps({ steps, label, vertical = false, testId }: { steps: readonly TaskStep[]; label: string; vertical?: boolean; testId?: string }) {
  if (steps.length === 0) return null;
  const focus = steps.find((step) => step.state === "current") ?? steps.find((step) => step.state === "blocked") ?? null;
  return (
    <div className={vertical ? "task-steps-vertical" : undefined} data-testid={testId}>
      <ol className="task-steps" aria-label={label} style={{ "--steps": steps.length } as CSSProperties}>
        {steps.map((step, index) => (
          <li key={step.id} className={`task-step is-${step.state}`} aria-current={step.state === "current" ? "step" : undefined} data-state={step.state}>
            <span className="task-step-marker" aria-hidden="true">{step.state === "done" ? <AppIcon name="check" size={16} /> : step.state === "blocked" ? <AppIcon name="lock" size={15} /> : index + 1}</span>
            <span className="task-step-copy">
              <strong>{step.label}</strong>
              <span className="visually-hidden">: {taskStepStateLabels[step.state]}.</span>
              <small>{step.detail}</small>
            </span>
          </li>
        ))}
      </ol>
      {focus && <p className="task-steps-current" aria-hidden="true"><strong>{focus.label}</strong>{focus.detail}</p>}
    </div>
  );
}
