import { useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import { canUseConfirmare } from "../../domain/confirmare";
import { AppIcon } from "../../components/icons/AppIcon";
import { canWatchHealth } from "../../domain/confirmareOps";
import { canOverseeFinance, canUseFinanceInbox, oversightPath } from "../../domain/financeRequests";

/**
 * The Confirmare home of a finance employee without a Confirmare scope (API 2.41.0): the finance tasks only. Since
 * Staff 2.42.1 the Confirmare home of an operator or supervisor is the operator desk itself (StaffApp opens it on `/`),
 * so there is no second operator home with its own cards and counts.
 */
export function ConfirmareWorkspace({ employee, navigate }: { employee: Employee; navigate: (path: string) => void }) {
  if (canUseConfirmare(employee)) return null;
  return <FinanceOnlyWorkspace employee={employee} navigate={navigate} />;
}

/** API 2.46.0: the AWB queue and the e-Factura follow-up of a shop employee. */
export function ShopTaskButtons({ navigate }: { navigate: (path: string) => void }) {
  const { t } = useI18n();
  return <>
    <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/awb")} data-testid="cf-open-awb"><AppIcon name="orders" size={20} />{t("cf.awbq.open")}</button>
    <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/efactura")} data-testid="cf-open-efactura"><AppIcon name="document" size={20} />{t("cf.efactura.open")}</button>
  </>;
}

/**
 * Staff 2.41.0: a finance employee starts from their own payment checks (Verificări de plată); the other finance tasks
 * and the supervisor's oversight are one click away. Nothing of the orders is listed here.
 */
function FinanceOnlyWorkspace({ employee, navigate }: { employee: Employee; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const inbox = canUseFinanceInbox(employee);
  const oversees = canOverseeFinance(employee);
  const primary = inbox ? "/confirmare/finance/requests" : oversees ? oversightPath() : "/confirmare/finance";
  return (
    <div className="workspace-body confirmare-workspace" data-testid="workspace-confirmare-finance">
      <section className="next-action tone-attention" aria-labelledby="cf-finance-next">
        <div className="next-action-copy">
          <p className="eyebrow">{t("cf.fin.homeEyebrow")}</p>
          <h2 id="cf-finance-next">{t(inbox ? "fr.home.title" : oversees ? "fr.oversight.title" : "cf.fin.homeTitle")}</h2>
          <p className="next-action-detail">{t(inbox ? "fr.home.detail" : oversees ? "fr.oversight.lead" : "cf.fin.homeDetail")}</p>
        </div>
        <div className="next-action-actions">
          <button type="button" className="button button-primary next-action-cta" onClick={() => navigate(primary)} data-testid={inbox ? "fr-open-inbox" : oversees ? "fr-open-oversight" : "cf-open-finance"}>{t(inbox ? "fr.home.open" : oversees ? "fr.oversight.open" : "cf.fin.open")}<AppIcon name="arrow" size={20} /></button>
          {inbox && oversees && <button type="button" className="button button-secondary" onClick={() => navigate(oversightPath())} data-testid="fr-open-oversight">{t("fr.oversight.open")}</button>}
          {canWatchHealth(employee) && <button type="button" className="button button-secondary" onClick={() => navigate("/confirmare/health")} data-testid="cf-open-health">{t("cf.health.open")}</button>}
        </div>
      </section>
    </div>
  );
}
