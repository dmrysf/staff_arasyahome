import { ArasyaLogo } from "../../components/brand/BrandLogo";
import { useI18n } from "../../i18n";

/** Shown when the central identity is authenticated but has no access to the Staff application. */
export function NoStaffAccessScreen({ displayName, onLogout }: { displayName: string; onLogout: () => Promise<void> }) {
  const { t } = useI18n();
  return (
    <main className="boot-screen" role="alert">
      <ArasyaLogo className="boot-logo" priority />
      <h1>{t("noAccess.title")}</h1>
      <p>{t("noAccess.body", { name: displayName })}</p>
      <button className="button button-secondary" type="button" onClick={() => { void onLogout(); }}>{t("common.signOut")}</button>
    </main>
  );
}
