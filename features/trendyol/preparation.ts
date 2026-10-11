import type { TaskStep } from "../../components/ui/TaskSteps";
import type { TrendyolLine, TrendyolLineKind, TrendyolPackageDetail } from "../../domain/trendyol";

/** The employee's unsaved values of one line (strings exactly as typed). */
export type LineDraft = { kind: TrendyolLineKind | ""; widthCm: string; heightCm: string; meters: string; notes: string };

export const draftOf = (line: TrendyolLine): LineDraft => ({
  kind: line.prepared?.kind ?? "",
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
      { id: "review", label: "Verifică produsele", state: "done", detail: `${total} ${total === 1 ? "produs verificat" : "produse verificate"}` },
      { id: "measure", label: "Completează măsurile", state: "done", detail: `${total}/${total} linii confirmate` },
      { id: "approve", label: "Aprobă producția", state: "done", detail: production.releasedBy ? `Aprobată de ${production.releasedBy}` : "Aprobată" },
      { id: "print", label: "Tipărește fișa cu QR", state: !documentReady ? "blocked" : handedOver ? "ready" : "current", detail: documentReady ? "Fișa de producție (REVIZIA 1) este gata" : "Fișa de producție nu este activă" },
      { id: "handoff", label: "Trimite la Tăiere", state: handedOver ? "done" : "upcoming", detail: handedOver ? `Etapa actuală: ${production.stageLabel ?? production.stageId}` : "Etapa 1 se finalizează din pagina comenzii" },
    ];
  }

  const blocked = detail.readiness.blockedReason !== null;
  return [
    { id: "review", label: "Verifică produsele", state: confirmed > 0 ? "done" : "current", detail: `${total} ${total === 1 ? "produs" : "produse"} din Trendyol` },
    { id: "measure", label: "Completează măsurile", state: allConfirmed ? "done" : confirmed > 0 ? "current" : "upcoming", detail: `${confirmed}/${total} linii confirmate` },
    { id: "approve", label: "Aprobă producția", state: blocked ? "blocked" : allConfirmed ? "current" : "upcoming", detail: blocked ? "Blocată de statusul din Trendyol" : allConfirmed ? "Toate liniile sunt confirmate" : "După confirmarea tuturor liniilor" },
    { id: "print", label: "Tipărește fișa cu QR", state: "upcoming", detail: "Fișa apare după aprobare" },
    { id: "handoff", label: "Trimite la Tăiere", state: "upcoming", detail: "După tipărirea fișei" },
  ];
}

/** A server decimal ("298.500") as the factory reads it ("298,5"); the stored value is unchanged. */
export function formatDecimal(value: string | null): string {
  if (value === null) return "—";
  const trimmed = value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value;
  return trimmed.replace(".", ",");
}
