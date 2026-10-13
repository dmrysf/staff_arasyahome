import type { TaskStep } from "../../components/ui/TaskSteps";
import { t } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import type { TrendyolLine, TrendyolLineKind, TrendyolPackageDetail } from "../../domain/trendyol";

/** The employee's unsaved values of one line (strings exactly as typed). */
export type LineDraft = { kind: TrendyolLineKind | ""; productionTitle: string; widthCm: string; heightCm: string; meters: string; notes: string };

/** The Romanian production title starts from the saved one, else from the title approved for the product code. */
export const draftOf = (line: TrendyolLine): LineDraft => ({
  kind: line.prepared?.kind ?? "",
  productionTitle: line.prepared?.productionTitle ?? line.suggestedTitle ?? "",
  widthCm: line.prepared?.widthCm ?? "",
  heightCm: line.prepared?.heightCm ?? "",
  meters: line.prepared?.meters ?? "",
  notes: line.prepared?.notes ?? "",
});

/**
 * Where one line stands: `confirmed` only while the screen still shows exactly what the server saved; any edit makes
 * it `unsaved` (the server copy stays valid until the employee saves); `todo` before anything is entered.
 */
export function lineStatus(line: TrendyolLine, draft: LineDraft): "confirmed" | "unsaved" | "todo" {
  const saved = draftOf(line);
  const same = (Object.keys(saved) as (keyof LineDraft)[]).every((key) => saved[key].trim() === draft[key].trim());
  // A line saved before the Romanian title existed still needs it before the package can be approved.
  if (line.prepared && !line.prepared.productionTitle) return same ? "todo" : "unsaved";
  if (line.prepared && same) return "confirmed";
  if (!line.prepared && same) return "todo";
  return "unsaved";
}

/**
 * The guided Trendyol sequence, derived only from the package the server returned:
 * products checked → measurements confirmed → explicit approval → production document → hand-off to cutting.
 * Printing is never shown as done: the server knows the document is ready, not that paper came out of a printer.
 */
export function preparationSteps(detail: TrendyolPackageDetail): TaskStep[] {
  if (detail.intakeStatus === "dismissed" || detail.intakeStatus === "marketplace_cancelled") return [];
  const total = detail.lines.length;
  const confirmed = detail.lines.filter((line) => line.prepared !== null).length;
  const allConfirmed = total > 0 && detail.readiness.missingLines.length === 0;
  const production = detail.production;

  if (production) {
    const handedOver = production.stageId !== "waiting";
    const documentReady = production.documentStatus === "active";
    return [
      { id: "review", label: t("ty.steps.review"), state: "done", detail: t("ty.steps.checked", { count: total }) },
      { id: "measure", label: t("ty.steps.measure"), state: "done", detail: t("ty.steps.linesConfirmed", { confirmed: total, total }) },
      { id: "approve", label: t("ty.steps.approve"), state: "done", detail: production.releasedBy ? t("ty.steps.approvedBy", { name: production.releasedBy }) : t("ty.steps.approved") },
      { id: "print", label: t("ty.steps.print"), state: !documentReady ? "blocked" : handedOver ? "ready" : "current", detail: documentReady ? t("ty.steps.sheetReady") : t("ty.steps.sheetNotActive") },
      { id: "handoff", label: t("ty.steps.handoff"), state: handedOver ? "done" : "upcoming", detail: handedOver ? t("ty.steps.currentStage", { stage: stageName(production.stageId, production.stageLabel) }) : t("ty.steps.stage1") },
    ];
  }

  const blocked = detail.readiness.blockedReason !== null;
  return [
    { id: "review", label: t("ty.steps.review"), state: confirmed > 0 ? "done" : "current", detail: t("ty.steps.fromTrendyol", { count: total }) },
    { id: "measure", label: t("ty.steps.measure"), state: allConfirmed ? "done" : confirmed > 0 ? "current" : "upcoming", detail: t("ty.steps.linesConfirmed", { confirmed, total }) },
    { id: "approve", label: t("ty.steps.approve"), state: blocked ? "blocked" : allConfirmed ? "current" : "upcoming", detail: blocked ? t("ty.steps.blocked") : allConfirmed ? t("ty.steps.allConfirmed") : t("ty.steps.afterAll") },
    { id: "print", label: t("ty.steps.print"), state: "upcoming", detail: t("ty.steps.afterApproval") },
    { id: "handoff", label: t("ty.steps.handoff"), state: "upcoming", detail: t("ty.steps.afterPrint") },
  ];
}

/** A server decimal ("298.500") as the factory reads it ("298,5"); the stored value is unchanged. */
export function formatDecimal(value: string | null): string {
  if (value === null) return "—";
  const trimmed = value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
  return trimmed.replace(".", ",");
}
