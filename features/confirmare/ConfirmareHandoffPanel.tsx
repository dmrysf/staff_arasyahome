import { useI18n } from "../../i18n";
import { handoffBlockerLabel, handoffStateLabel, type ConfirmareDetail, type HandoffState } from "../../domain/confirmare";
import type { DocumentApi } from "../../domain/documents";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { DocumentPanel } from "../documents/DocumentPanel";

const tones: Readonly<Record<HandoffState, Tone>> = { closed: "neutral", blocked: "danger", ready: "accent", print: "accent", awaiting_cutting: "warning", in_production: "success" };

/**
 * The production handoff of a Confirmare order (API 2.42.0). It shows the server's handoff state and, for an employee
 * the server allows, the central production document panel (the only PDF and QR system): generate revision 1, print,
 * reprint with a reason, or request the approved revision after a change. The cutting QR scan starts production.
 */
export function ConfirmareHandoffPanel({ detail, documents, permissions, onChanged }: { detail: ConfirmareDetail; documents?: DocumentApi; permissions: readonly string[]; onChanged: () => void }) {
  const { t } = useI18n();
  const handoff = detail.handoff;
  if (!handoff || detail.status !== "approved") return null;
  const tone = tones[handoff.state];
  const revisionWork = handoff.blockers.includes("document_blocked") && permissions.includes("production.documents.request_revision");
  const showDocument = Boolean(documents) && (handoff.actions.generate || handoff.actions.print || revisionWork);
  const waitingForDocumentRights = !showDocument && (handoff.state === "ready" || handoff.state === "print");
  return (
    <section className="detail-section cf-handoff" aria-labelledby="cf-handoff-title" data-testid="cf-handoff">
      <div className="section-title">
        <h2 id="cf-handoff-title">{t("cf.handoff.title")}</h2>
        <StatusPill tone={tone} icon={handoff.state === "in_production" ? "check" : handoff.state === "blocked" ? "lock" : "clock"} testId="cf-handoff-state">{handoffStateLabel(handoff.state)}</StatusPill>
      </div>
      <p className="cf-tab-hint">{t(`cf.handoff.detail.${handoff.state}`)}</p>
      {handoff.blockers.length > 0 && <>
        <p className="eyebrow">{t("cf.handoff.blockersTitle")}</p>
        <ul className="cf-checklist" data-testid="cf-handoff-blockers">{handoff.blockers.map((blocker) => <li key={blocker} className="is-missing"><AppIcon name="lock" size={18} /><span>{handoffBlockerLabel(blocker)}</span></li>)}</ul>
      </>}
      {handoff.document?.revision != null && <p className="cf-tab-hint">{t("cf.handoff.revision", { number: handoff.document.revision })}</p>}
      {waitingForDocumentRights && <Notice tone="info" role="note">{t("cf.handoff.noPermission")}</Notice>}
      {showDocument && documents && <>
        <DocumentPanel orderId={detail.globalOrderId} service={documents} permissions={permissions} onChanged={onChanged} onPrinted={onChanged} />
        <p className="cf-tab-hint">{t("cf.handoff.reprintNote")}</p>
      </>}
      <p className="cf-tab-hint">{t("cf.handoff.lead")}</p>
    </section>
  );
}
