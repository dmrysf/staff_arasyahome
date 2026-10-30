import type { CSSProperties, ReactNode, RefObject } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ApprovalRequirement, ConfirmareDetail } from "../../domain/confirmare";
import { requirementLabel } from "../../domain/confirmare";
import { WORKFLOW_STEPS, stepTitleKey, type ConfirmareWorkflow, type WorkflowStep, type WorkflowStepState, type WorkflowSubtaskId, type WorkflowSubtaskState } from "../../domain/confirmareWorkflow";
import { AppIcon, type AppIconName } from "../../components/icons/AppIcon";
import { StatusPill, type Tone } from "../../components/ui/StatusPill";

/**
 * The guided Confirmare order (Staff 2.40.0): presentation only. The steps, their states and the active task come from
 * resolveWorkflow (server facts and the operator's unsent ticks); nothing here sends a command or decides a state.
 */

const stepTone: Readonly<Record<WorkflowStepState, Tone>> = { done: "success", active: "accent", waiting: "warning", attention: "danger", locked: "neutral", skipped: "neutral" };
const stepIcon: Readonly<Record<WorkflowStepState, AppIconName>> = { done: "check", active: "arrow", waiting: "clock", attention: "alert", locked: "lock", skipped: "info" };
const subIcon: Readonly<Record<WorkflowSubtaskState, AppIconName>> = { done: "check", current: "arrow", todo: "clock", waiting: "clock", blocked: "lock", skipped: "info" };

/**
 * The five steps as one row of markers; each state has words, never only a colour or an icon. `compact` (Staff 2.42.0,
 * the operator desk): only "Pasul n din 5" is shown, the markers stay for assistive technology (the step cards beside it
 * show the same states).
 */
export function StepProgress({ workflow, compact = false }: { workflow: ConfirmareWorkflow; compact?: boolean }) {
  const { t } = useI18n();
  const index = WORKFLOW_STEPS.indexOf(workflow.active);
  return (
    <nav className={`cfw-progress${compact ? " is-compact" : ""}`} aria-label={t("cfw.progress.label")} data-testid="cfw-progress">
      <p className="eyebrow" data-testid="cfw-progress-of">{t("cfw.progress.of", { current: index + 1, total: WORKFLOW_STEPS.length })}</p>
      <ol className={`task-steps cfw-steps${compact ? " visually-hidden" : ""}`} style={{ "--steps": WORKFLOW_STEPS.length } as CSSProperties}>
        {workflow.steps.map((step, position) => (
          <li key={step.id} className={`task-step cfw-step is-${step.state === "active" ? "current" : step.state}`} aria-current={step.id === workflow.active ? "step" : undefined} data-state={step.state} data-testid={`cfw-progress-${step.id}`}>
            <span className="task-step-marker" aria-hidden="true">{step.state === "done" ? <AppIcon name="check" size={16} /> : step.state === "attention" ? <AppIcon name="alert" size={15} /> : step.state === "waiting" ? <AppIcon name="clock" size={15} /> : position + 1}</span>
            <span className="task-step-copy">
              <strong>{t(stepTitleKey(step.id))}</strong>
              <span className="visually-hidden">: {t(`cfw.state.${step.state}` as MessageKey)}</span>
              <small className="cfw-step-state" aria-hidden="true">{t(`cfw.state.${step.state}` as MessageKey)}</small>
            </span>
          </li>
        ))}
      </ol>
    </nav>
  );
}

/** One step as a card: a compact summary when done, a title when not reached, the task when active. */
export function StepCard({ step, number, active, children }: { step: WorkflowStep; number: number; active: boolean; children?: ReactNode }) {
  const { t } = useI18n();
  const title = t(stepTitleKey(step.id));
  if (!active) {
    return (
      <section className={`cfw-card is-${step.state}`} aria-label={title} data-testid={`cfw-step-${step.id}`} data-state={step.state}>
        <div className="cfw-card-head">
          <span className="cfw-card-number" aria-hidden="true">{step.state === "done" ? <AppIcon name="check" size={16} /> : number}</span>
          <h2 className="cfw-card-title">{title}</h2>
          <StatusPill tone={stepTone[step.state]} icon={stepIcon[step.state]} testId={`cfw-step-${step.id}-state`}>{t(`cfw.state.${step.state}` as MessageKey)}</StatusPill>
        </div>
        {step.summary && <p className="cfw-card-summary" data-testid={`cfw-step-${step.id}-summary`}>{t(step.summary)}</p>}
      </section>
    );
  }
  return (
    <section className={`cfw-card is-active is-${step.state}`} aria-labelledby={`cfw-step-${step.id}-title`} data-testid={`cfw-step-${step.id}`} data-state={step.state}>
      <div className="cfw-card-head">
        <span className="cfw-card-number" aria-hidden="true">{number}</span>
        <p className="cfw-card-title eyebrow" id={`cfw-step-${step.id}-title`}>{title}</p>
        <StatusPill tone={stepTone[step.state]} icon={stepIcon[step.state]} testId={`cfw-step-${step.id}-state`}>{t(`cfw.state.${step.state}` as MessageKey)}</StatusPill>
      </div>
      {children}
    </section>
  );
}

/** The subtasks of the active step; a reached one can be opened again (to look, or to redo it), a future one cannot. */
export function SubtaskList({ step, shown, onPick }: { step: WorkflowStep; shown: WorkflowSubtaskId | null; onPick?: (id: WorkflowSubtaskId) => void }) {
  const { t } = useI18n();
  const visible = step.subtasks.filter((subtask) => subtask.state !== "skipped");
  if (visible.length < 2) return null;
  return (
    <ol className="cfw-subtasks" data-testid="cfw-subtasks">
      {visible.map((subtask) => {
        const label = t(`cfw.sub.${subtask.id}` as MessageKey);
        const state = t(`cfw.sub.state.${subtask.state}` as MessageKey);
        const openable = onPick && step.state !== "locked" && (subtask.state === "done" || subtask.state === "current" || subtask.state === "waiting") && subtask.id !== shown;
        return (
          <li key={subtask.id} className={`cfw-subtask is-${subtask.state}${subtask.id === shown ? " is-shown" : ""}`} data-testid={`cfw-sub-${subtask.id}`} data-state={subtask.state}>
            <AppIcon name={subIcon[subtask.state]} size={16} />
            {openable
              ? <button type="button" className="cfw-subtask-open" onClick={() => onPick(subtask.id)} aria-label={t("cfw.sub.open", { task: label })}>{label}</button>
              : <span>{label}</span>}
            <span className="visually-hidden">: {state}</span>
            <small aria-hidden="true">{state}</small>
          </li>
        );
      })}
    </ol>
  );
}

/** What happened, what is needed, who acts and whether the signed-in employee can act: the answer to "Ce trebuie să fac acum?". */
export function TaskBrief({ workflow, ownerName, headingRef }: { workflow: ConfirmareWorkflow; ownerName: string | null; headingRef: RefObject<HTMLHeadingElement | null> }) {
  const { t } = useI18n();
  const owner = workflow.owner === "colleague" && ownerName ? ownerName : t(`cfw.owner.${workflow.owner}` as MessageKey);
  return (
    <div className="cfw-brief" data-testid="cfw-task" data-task={workflow.task}>
      <h3 className="cfw-task-title" id="cfw-task-title" tabIndex={-1} ref={headingRef} data-testid="cfw-task-title">{t(`cfw.task.${workflow.task}.title` as MessageKey)}</h3>
      <p className="cfw-task-lead" data-testid="cfw-task-lead">{t(`cfw.task.${workflow.task}.lead` as MessageKey)}</p>
      <p className="cfw-owner" data-testid="cfw-owner">
        <span>{t("cfw.owner.label")}: <strong>{owner}</strong></span>
        <span className={workflow.canAct ? "cfw-can-act" : "cfw-cannot-act"}>{t(workflow.canAct ? "cfw.canAct.yes" : "cfw.canAct.no")}</span>
      </p>
    </div>
  );
}

/** A process running beside the active step (the advance proforma of three unanswered calls, a payment already proven). */
export function BackgroundStrip({ workflow, detail }: { workflow: ConfirmareWorkflow; detail: ConfirmareDetail }) {
  const { t } = useI18n();
  if (workflow.background.length === 0) return null;
  const calls = detail.cycle?.calls;
  return (
    <aside className="cfw-background" aria-label={t("cfw.bg.title")} data-testid="cfw-background">
      {workflow.background.map((item) => <p key={item} data-testid={`cfw-bg-${item}`}>
        <AppIcon name={item === "paymentCleared" ? "check" : "clock"} size={16} />
        {item === "callCycle" ? t("cfw.bg.callCycle", { count: calls?.noAnswer ?? 0, max: calls?.max ?? 3 }) : t(`cfw.bg.${item}` as MessageKey)}
      </p>)}
    </aside>
  );
}

/** The approval conditions: a short "all met" line, the one thing still missing, and the full list on request. */
export function VerificationDetails({ missing, items }: { missing: readonly ApprovalRequirement[]; items: readonly ApprovalRequirement[] }) {
  const { t } = useI18n();
  const first = missing[0];
  return (
    <div className="cfw-verification">
      <p className={first ? "cfw-next-fix" : "cfw-all-met"} data-testid="cfw-verification-state">{first ? t("cfw.final.next", { item: requirementLabel(first) }) : t("cfw.final.allMet")}</p>
      <details className="cfw-disclosure">
        <summary data-testid="cfw-verification-open">{t("cfw.final.details")}</summary>
        <ul className="cf-checklist" data-testid="cf-checklist">
          {items.map((requirement) => <li key={requirement} className={missing.includes(requirement) ? "is-missing" : "is-met"}><AppIcon name={missing.includes(requirement) ? "clock" : "check"} size={18} /><span>{requirementLabel(requirement)}</span></li>)}
          {missing.filter((requirement) => !items.includes(requirement)).map((requirement) => <li key={requirement} className="is-missing"><AppIcon name="lock" size={18} /><span>{requirementLabel(requirement)}</span></li>)}
        </ul>
      </details>
    </div>
  );
}

