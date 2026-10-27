import { Component, createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import type { Employee } from "../../domain/models";
import type { StaffRoute } from "../../domain/staffRoute";
import type { Workspace } from "../../domain/workspaces";
import { defaultGuideLang, readGuideCardHidden, readGuideLang, resolveGuide, saveGuideCardHidden, saveGuideLang, type GuideLang, type GuideText, type ResolvedGuide } from "../../domain/guide";
import { FLOW_STEPS, GUIDE_LANGS, flowLabels, guideChrome, guideLangNames, managementLabels, managementTerms } from "../../i18n/messages/guide";
import { AppIcon } from "../../components/icons/AppIcon";

/**
 * "Cum funcționează? / Nasıl çalışır?" (Staff 2.38.0): the explanation of the screen the employee is on, in Romanian
 * or Turkish as the employee chooses inside the guide (the Staff interface language stays the account's). It only
 * explains: no API call, no action, nothing it shows comes from outside the session. A failure in the guide renders
 * nothing and never stops the screen underneath.
 */
type GuideState = {
  lang: GuideLang;
  setLang: (lang: GuideLang) => void;
  guide: ResolvedGuide | null;
  open: () => void;
  cardHidden: boolean;
  setCardHidden: (hidden: boolean) => void;
  setWorkspace: (workspace: Workspace | null) => void;
};

const GuideContext = createContext<GuideState | null>(null);

/** The guide of the current screen, or null outside the signed-in app (and in isolated renders). */
export function useGuide(): GuideState | null {
  return useContext(GuideContext);
}

/** The home screen tells the guide which workspace is open; nothing else changes. */
export function useGuideWorkspace(workspace: Workspace | null): void {
  const setWorkspace = useContext(GuideContext)?.setWorkspace;
  useEffect(() => {
    if (!setWorkspace) return;
    setWorkspace(workspace);
    return () => setWorkspace(null);
  }, [workspace, setWorkspace]);
}

export const pick = (value: GuideText, lang: GuideLang): string => value[lang];

export function GuideProvider({ employee, route, dashboardLink, children }: { employee: Employee; route: StaffRoute; dashboardLink: boolean; children: ReactNode }) {
  // StaffApp keys the provider by employee, so another person signing in on the same device starts from their own choice.
  const uuid = employee.employeeUuid;
  const [current, setPrefs] = useState(() => ({ lang: readGuideLang(uuid, defaultGuideLang(employee.locale)), cardHidden: readGuideCardHidden(uuid) }));
  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [isOpen, setOpen] = useState(false);

  const setLang = useCallback((lang: GuideLang) => { saveGuideLang(uuid, lang); setPrefs((value) => ({ ...value, lang })); }, [uuid]);
  const setCardHidden = useCallback((hidden: boolean) => { saveGuideCardHidden(uuid, hidden); setPrefs((value) => ({ ...value, cardHidden: hidden })); }, [uuid]);
  const open = useCallback(() => setOpen(true), []);
  const guide = useMemo(() => {
    try { return resolveGuide(employee, route, route.kind === "home" ? workspace : null, { dashboardLink }); }
    catch { return null; }
  }, [employee, route, workspace, dashboardLink]);

  const state = useMemo<GuideState>(() => ({ lang: current.lang, setLang, guide, open, cardHidden: current.cardHidden, setCardHidden, setWorkspace }), [current.lang, current.cardHidden, setLang, guide, open, setCardHidden]);
  return (
    <GuideContext.Provider value={state}>
      {children}
      {isOpen && guide && <GuideBoundary><GuideDialog guide={guide} lang={current.lang} onLang={setLang} onClose={() => setOpen(false)} /></GuideBoundary>}
    </GuideContext.Provider>
  );
}

/** A guide error renders nothing; the screen and its actions keep working. */
export class GuideBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError() { return { failed: true }; }
  render() { return this.state.failed ? null : this.props.children; }
}

/** The header control, visible to every signed-in employee on every framed screen. */
export function GuideButton() {
  const guide = useGuide();
  if (!guide?.guide) return null;
  return (
    <button type="button" className="guide-control" onClick={guide.open} aria-haspopup="dialog" lang={guide.lang} data-testid="guide-open">
      <AppIcon name="info" size={18} />
      <span className="guide-control-label">{pick(guideChrome.open, guide.lang)}</span>
    </button>
  );
}

function LangSwitch({ lang, onLang }: { lang: GuideLang; onLang: (lang: GuideLang) => void }) {
  return (
    <div className="guide-lang" role="group" aria-label={pick(guideChrome.language, lang)}>
      {GUIDE_LANGS.map((code) => (
        <button key={code} type="button" lang={code} aria-pressed={code === lang} onClick={() => onLang(code)} data-testid={`guide-lang-${code}`}>{guideLangNames[code]}</button>
      ))}
    </div>
  );
}

function List({ items, lang, ordered = false, testId }: { items: readonly GuideText[]; lang: GuideLang; ordered?: boolean; testId?: string }) {
  const Tag = ordered ? "ol" : "ul";
  return <Tag className="guide-list" data-testid={testId}>{items.map((item) => <li key={item.ro}>{pick(item, lang)}</li>)}</Tag>;
}

const MANAGEMENT_SUMMARY = ["active", "waiting", "inWork", "unassigned", "completedToday"] as const;

export function GuideDialog({ guide, lang, onLang, onClose }: { guide: ResolvedGuide; lang: GuideLang; onLang: (lang: GuideLang) => void; onClose: () => void }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const close = useRef(onClose);
  useEffect(() => { close.current = onClose; }, [onClose]);
  useEffect(() => {
    const element = dialog.current;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (element && !element.open) {
      try { element.showModal(); } catch { element.setAttribute("open", ""); }
    }
    // A tap on the dimmed backdrop closes the sheet (Escape and the close button already do).
    const onBackdrop = (event: MouseEvent) => { if (event.target === element) close.current(); };
    element?.addEventListener("click", onBackdrop);
    return () => { element?.removeEventListener("click", onBackdrop); opener?.focus(); };
  }, []);
  const c = guideChrome;
  const overview = guide.topicId === "overview";
  return (
    <dialog ref={dialog} className="guide-dialog" aria-labelledby="guide-title" lang={lang} onClose={onClose} onCancel={(event) => { event.preventDefault(); onClose(); }} data-testid="guide-dialog">
      <div className="guide-panel">
        <header className="guide-head">
          <div>
            <p className="eyebrow">{pick(c.dialogTitle, lang)}</p>
            <h2 id="guide-title">{pick(guide.title, lang)}</h2>
          </div>
          <button type="button" className="guide-close" onClick={onClose} aria-label={pick(c.close, lang)} data-testid="guide-close"><span aria-hidden="true">×</span></button>
        </header>
        <LangSwitch lang={lang} onLang={onLang} />
        <p className="guide-lang-note">{pick(c.languageNote, lang)}</p>

        <section className="guide-block" aria-labelledby="guide-purpose">
          <h3 id="guide-purpose">{pick(c.purpose, lang)}</h3>
          <p data-testid="guide-purpose">{pick(guide.purpose, lang)}</p>
        </section>
        {guide.scopes.length > 0 && <section className="guide-block" aria-labelledby="guide-scopes">
          <h3 id="guide-scopes">{pick(c.scopes, lang)}</h3>
          <List items={guide.scopes} lang={lang} testId="guide-scopes" />
        </section>}
        {guide.steps.length > 0 && <section className="guide-block" aria-labelledby="guide-steps">
          <h3 id="guide-steps">{pick(c.steps, lang)}</h3>
          <List items={guide.steps} lang={lang} ordered testId="guide-steps" />
        </section>}
        {guide.stages.length > 0 && <section className="guide-block" aria-labelledby="guide-stages">
          <h3 id="guide-stages">{pick(c.stages, lang)}</h3>
          <List items={guide.stages} lang={lang} testId="guide-stages" />
        </section>}
        {guide.topicId === "ws.management" && <section className="guide-block" aria-labelledby="guide-terms">
          <h3 id="guide-terms">{pick(c.metricsHelp, lang)}</h3>
          <dl className="guide-terms" data-testid="guide-terms">
            {MANAGEMENT_SUMMARY.map((key) => <div key={key}><dt>{pick(managementLabels[key], lang)}</dt><dd>{pick(managementTerms[key], lang)}</dd></div>)}
          </dl>
          <List items={[managementTerms.stageActive, managementTerms.stageUnassigned, managementTerms.stageOldest, managementTerms.legacy]} lang={lang} />
        </section>}
        {guide.after.length > 0 && <section className="guide-block" aria-labelledby="guide-after">
          <h3 id="guide-after">{pick(c.after, lang)}</h3>
          <List items={guide.after} lang={lang} testId="guide-after" />
        </section>}
        {guide.caution.length > 0 && <section className="guide-block guide-caution" aria-labelledby="guide-caution">
          <h3 id="guide-caution"><AppIcon name="alert" size={18} />{pick(c.caution, lang)}</h3>
          <List items={guide.caution} lang={lang} testId="guide-caution" />
        </section>}
        {guide.details.length > 0 && <details className="guide-details" data-testid="guide-details">
          <summary>{pick(c.showDetails, lang)}</summary>
          <List items={guide.details} lang={lang} />
        </details>}

        <details className="guide-details guide-overview" open={overview} data-testid="guide-overview">
          <summary>{pick(c.overviewTitle, lang)}</summary>
          {!overview && <p>{pick(c.overviewBody, lang)}</p>}
          <ol className="guide-flow" aria-label={pick(c.flowLabel, lang)}>
            {FLOW_STEPS.map((step) => <li key={step} className={guide.flow.has(step) ? "is-own" : undefined}>
              {pick(flowLabels[step], lang)}{guide.flow.has(step) && <small> · {pick(c.yourArea, lang)}</small>}
            </li>)}
          </ol>
          <p className="guide-lang-note">{pick(c.overviewNote, lang)}</p>
        </details>
        <p className="guide-footnote">{pick(c.explainsOnly, lang)}</p>
      </div>
    </dialog>
  );
}

/** Home: one short card for the open workspace; it can be closed and shown again, and opens the full guide. */
export function GuideHomeCard() {
  const state = useGuide();
  if (!state?.guide) return null;
  const { guide, lang } = state;
  if (state.cardHidden) {
    return <button type="button" className="button button-link guide-card-show" onClick={() => state.setCardHidden(false)} lang={lang} data-testid="guide-card-show"><AppIcon name="info" size={18} />{pick(guideChrome.cardShow, lang)}</button>;
  }
  return (
    <section className="guide-card" aria-labelledby="guide-card-title" lang={lang} data-testid="guide-card">
      <div className="guide-card-copy">
        <p className="eyebrow">{pick(guideChrome.workspace, lang)}</p>
        <h2 id="guide-card-title">{pick(guide.title, lang)}</h2>
        <p>{pick(guide.summary, lang)}</p>
      </div>
      <div className="guide-card-actions">
        <button type="button" className="button button-secondary button-compact" onClick={state.open} aria-haspopup="dialog" data-testid="guide-card-details">{pick(guideChrome.showDetails, lang)}</button>
        <button type="button" className="guide-close" onClick={() => state.setCardHidden(true)} aria-label={pick(guideChrome.cardHide, lang)} data-testid="guide-card-hide"><span aria-hidden="true">×</span></button>
      </div>
    </section>
  );
}
