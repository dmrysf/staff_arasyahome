import type { OrderActionBlockedReason, OrderActionId } from "./models";

export const orderActionLabels: Record<OrderActionId, string> = {
  claim: "Preia comanda",
  complete_stage: "Finalizează etapa",
  complete_production: "Finalizează producția",
};

export const orderActionConfirmation: Record<OrderActionId, { title: string; note: string }> = {
  claim: { title: "Preiei comanda?", note: "Comanda devine responsabilitatea ta la etapa curentă." },
  complete_stage: { title: "Etapa este gata?", note: "Comanda trece la etapa următoare și este predată colegilor." },
  complete_production: { title: "Finalizezi producția?", note: "Comanda este marcată ca livrată din producție." },
};

export const orderActionSuccess: Record<OrderActionId, { eyebrow: string; title: string }> = {
  claim: { eyebrow: "Preluare confirmată", title: "Comanda este la tine" },
  complete_stage: { eyebrow: "Etapă finalizată", title: "Comanda a fost predată" },
  complete_production: { eyebrow: "Producție finalizată", title: "Comanda este finalizată" },
};

export const orderActionBlockedCopy: Record<OrderActionBlockedReason, string> = {
  claimed_by_other: "Un coleg lucrează deja la această comandă.",
  stage_not_allowed: "Comanda este la o etapă care nu îți este alocată.",
  production_completed: "Producția acestei comenzi este finalizată.",
  order_unavailable: "Comanda a fost anulată la sursă și nu se mai lucrează.",
  permission_missing: "Nu ai permisiunea pentru această acțiune.",
  workflow_unavailable: "Fluxul de producție nu este disponibil momentan.",
  exception_pending: "Comanda este blocată: o cerere de returnare la tăiere așteaptă confirmarea și aprobarea.",
  document_revision_pending: "Document blocat: comanda are o revizie de document în curs. Așteaptă aprobarea și documentul nou.",
  production_authority_source: "Producția acestei comenzi este încă gestionată în YD SOFT. Un manager trebuie să o preia în Arasya înainte de a lucra la ea.",
};

export function isOrderActionId(value: unknown): value is OrderActionId {
  return value === "claim" || value === "complete_stage" || value === "complete_production";
}

export function isOrderActionBlockedReason(value: unknown): value is OrderActionBlockedReason {
  return typeof value === "string" && Object.hasOwn(orderActionBlockedCopy, value);
}
