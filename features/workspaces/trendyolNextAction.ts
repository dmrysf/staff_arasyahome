import type { TrendyolPackageSummary } from "../../domain/trendyol";
import type { TrendyolDashboardData } from "../trendyol/trendyolRefresh";

export type NextAction = {
  tone: "action" | "ready" | "attention" | "calm";
  eyebrow: string;
  title: string;
  detail: string;
  /** The order the action is about (shown in the monospace order style). */
  orderNumber: string | null;
  cta: { label: string; path: string } | null;
};

const missingLines = (item: TrendyolPackageSummary): number | null => item.lineCount === undefined ? null : Math.max(0, item.lineCount - (item.preparedCount ?? 0));

/**
 * The single next step for a Trendyol operator, chosen only from data the server already returned (no extra read,
 * no guess): finish an order that is ready for approval, otherwise continue the first order that still needs
 * measurements, otherwise review the orders that need attention. It links to the existing screens; it never acts.
 */
export function trendyolNextAction(data: TrendyolDashboardData): NextAction {
  const { capabilities, counts, intake } = data.overview;
  const ready = capabilities.release ? data.pending.find((item) => missingLines(item) === 0) : undefined;
  if (ready) {
    return { tone: "ready", eyebrow: "Următorul pas", title: "Aprobă intrarea în producție", detail: "Toate liniile sunt confirmate. Verifică încă o dată și aprobă.", orderNumber: ready.orderNumber, cta: { label: `Deschide comanda #${ready.orderNumber}`, path: `/trendyol/${ready.packageId}` } };
  }
  const toPrepare = data.pending.find((item) => (missingLines(item) ?? 1) > 0) ?? data.pending[0];
  if (toPrepare && capabilities.prepare) {
    const missing = missingLines(toPrepare);
    const detail = missing === null ? "Completează măsurile fiecărui produs." : `${missing} din ${toPrepare.lineCount} ${toPrepare.lineCount === 1 ? "linie" : "linii"} de completat.`;
    return { tone: "action", eyebrow: "Următorul pas", title: "Completează măsurile", detail, orderNumber: toPrepare.orderNumber, cta: { label: `Deschide comanda #${toPrepare.orderNumber}`, path: `/trendyol/${toPrepare.packageId}` } };
  }
  if (counts.pending > 0) {
    return { tone: "action", eyebrow: "De pregătit", title: `${counts.pending} ${counts.pending === 1 ? "comandă așteaptă" : "comenzi așteaptă"} pregătirea`, detail: "Poți vedea comenzile; pregătirea o face o persoană cu drept de pregătire.", orderNumber: null, cta: { label: "Vezi comenzile", path: "/trendyol" } };
  }
  if (counts.attention > 0) {
    return { tone: "attention", eyebrow: "Verificare manuală", title: `${counts.attention} ${counts.attention === 1 ? "comandă necesită" : "comenzi necesită"} atenție`, detail: "Statusul din Trendyol nu permite încă intrarea în producție.", orderNumber: null, cta: { label: "Vezi comenzile", path: "/trendyol" } };
  }
  if (intake.status !== "active") {
    return { tone: "calm", eyebrow: "Conexiune Trendyol", title: intake.status === "paused" ? "Citirea comenzilor este oprită temporar" : "Conexiunea Trendyol nu este activată", detail: "Nu există comenzi de pregătit.", orderNumber: null, cta: null };
  }
  return { tone: "calm", eyebrow: "Totul este la zi", title: "Nicio comandă de pregătit", detail: "Comenzile noi din Trendyol apar aici automat.", orderNumber: null, cta: null };
}
