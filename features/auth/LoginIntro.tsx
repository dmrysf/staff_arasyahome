import { useLayoutEffect, useRef, type ReactNode, type RefObject } from "react";

/** Typed into the intro search bar after every successful sign-in. */
export const INTRO_TEXT = "Coded by the Roisey team";

const logo = { src: new URL("../../components/brand/arasya-logo.webp", import.meta.url).href, width: 599, height: 96 };
const EASE_OUT = "cubic-bezier(.2, .8, .2, 1)";
const EASE_IN_OUT = "cubic-bezier(.65, 0, .35, 1)";
const SPRING = "cubic-bezier(.34, 1.4, .64, 1)";
const TYPE_START_MS = 2250;
const TYPE_STEP_MS = 52;
const FRESH_CHARACTERS = 4;

/**
 * Browser automation (Playwright sets navigator.webdriver) signs in hundreds of times per run; the intro would only
 * slow those runs down. Real browsers always play it: there is no skip control and no reduced-motion exception.
 */
export const introPlaysHere = () => typeof navigator === "undefined" || navigator.webdriver !== true;

/**
 * Wraps Staff. While an intro runs, Staff is rendered (and loads its data) inside a hidden, inert stage; the intro
 * opens that stage from its send button so the user lands in the real Staff screen.
 */
export function LoginIntroStage({ run, onDone, children }: { run: number; onDone: () => void; children: ReactNode }) {
  const stage = useRef<HTMLDivElement>(null);
  return (
    <>
      <div className="intro-stage" ref={stage} data-intro={run ? "playing" : undefined} inert={run ? true : undefined}>{children}</div>
      {run > 0 && <LoginIntro key={run} stage={stage} onDone={onDone} />}
    </>
  );
}

function LoginIntro({ stage, onDone }: { stage: RefObject<HTMLDivElement | null>; onDone: () => void }) {
  const root = useRef<HTMLDivElement>(null);
  const typed = useRef<HTMLSpanElement>(null);
  const fresh = useRef<HTMLSpanElement>(null);

  useLayoutEffect(() => {
    const scene = root.current;
    const target = stage.current;
    if (!scene || !target || typeof scene.animate !== "function") { onDone(); return; }
    const part = (selector: string) => scene.querySelector<HTMLElement>(selector) as HTMLElement;
    const [horizon, glow, brand, mark, word, bar, send] = [".intro-horizon", ".intro-glow", ".intro-brand", ".intro-mark", ".intro-word-inner", ".intro-bar", ".intro-send"].map(part);
    const animations: Animation[] = [];
    const timers: number[] = [];
    // Multi-step timelines run linearly overall and ease each step, so offsets stay at their real times.
    const play = (element: Element, keyframes: Keyframe[], options: KeyframeAnimationOptions) => {
      const steps = keyframes.length > 2;
      const animation = element.animate(steps ? keyframes.map((frame) => ({ easing: EASE_OUT, ...frame })) : keyframes, { fill: "both", easing: steps ? "linear" : EASE_OUT, ...options });
      animations.push(animation);
      return animation;
    };

    // Measure the final layout before anything moves.
    const width = window.innerWidth;
    const height = window.innerHeight;
    const wordShift = part(".intro-word").getBoundingClientRect().width / 2;
    const barBox = bar.getBoundingClientRect();
    const lift = (barBox.height + 28) / 2;
    const toBar = barBox.top + barBox.height / 2 - horizon.offsetTop;
    const sendBox = send.getBoundingClientRect();

    // 1. The horizon rises; 4. it flattens into the search bar.
    horizon.style.transformOrigin = "50% 0";
    play(horizon, [
      { opacity: 0, transform: "translateY(16%)" },
      { opacity: 1, transform: "none", offset: 0.38 },
      { opacity: 1, transform: "none", offset: 0.72, easing: EASE_IN_OUT },
      { opacity: 0, transform: `translateY(${toBar}px) scaleX(1.6) scaleY(.06)` },
    ], { duration: 2100 });
    // 2. The white "A" appears alone, 3. moves aside while the rest of the wordmark slides out of it.
    play(mark, [
      { opacity: 0, transform: `translateX(${wordShift}px) scale(.55)` },
      { opacity: 0, transform: `translateX(${wordShift}px) scale(.55)`, offset: 0.16 },
      { opacity: 1, transform: `translateX(${wordShift}px) scale(1)`, offset: 0.56, easing: EASE_IN_OUT },
      { opacity: 1, transform: `translateX(${wordShift}px) scale(1)`, offset: 0.66, easing: EASE_IN_OUT },
      { opacity: 1, transform: "none" },
    ], { duration: 1600 });
    play(word, [{ opacity: 0, transform: "translateX(-12%)" }, { opacity: 1, transform: "none" }], { delay: 1430, duration: 520 });
    play(brand, [{ transform: `translateY(${lift}px)` }, { transform: "none" }], { delay: 1500, duration: 650, easing: EASE_IN_OUT });
    play(bar, [{ opacity: 0, transform: "scaleX(.3) scaleY(.35)" }, { opacity: 1, transform: "none" }], { delay: 1560, duration: 650 });
    play(glow, [{ opacity: 0, transform: "translateY(30%)" }, { opacity: 1, transform: "none" }], { delay: 1500, duration: 900 });

    // 5. The text types itself; the newest letters arrive in blue, as in the reference.
    for (let count = 1; count <= INTRO_TEXT.length; count += 1) {
      timers.push(window.setTimeout(() => {
        const split = Math.max(0, count - FRESH_CHARACTERS);
        if (typed.current) typed.current.textContent = INTRO_TEXT.slice(0, split);
        if (fresh.current) fresh.current.textContent = INTRO_TEXT.slice(split, count);
      }, TYPE_START_MS + count * TYPE_STEP_MS));
    }
    const typedAt = TYPE_START_MS + INTRO_TEXT.length * TYPE_STEP_MS;
    timers.push(window.setTimeout(() => {
      if (typed.current) typed.current.textContent = INTRO_TEXT;
      if (fresh.current) fresh.current.textContent = "";
    }, typedAt + 320));

    // 6. The send button appears and is pressed …
    const sendAt = typedAt + 120;
    play(send, [
      { opacity: 0, transform: "scale(.4)", easing: SPRING },
      { opacity: 1, transform: "scale(1)", offset: 0.4 },
      { opacity: 1, transform: "scale(1)", offset: 0.6 },
      { opacity: 1, transform: "scale(.84)", offset: 0.76 },
      { opacity: 1, transform: "scale(1)" },
    ], { delay: sendAt, duration: 750 });

    // … and opens into Staff: a circle on the button, a card, then the whole screen, settling from slightly close.
    const side = Math.min(width, height);
    const insetX = (width - side) / 2;
    const insetY = (height - side) / 2;
    const fromX = sendBox.left + sendBox.width / 2 - width / 2;
    const fromY = sendBox.top + sendBox.height / 2 - height / 2;
    const fromScale = (sendBox.width * 1.15) / side;
    const clip = (y: number, x: number, radius: number) => `inset(${y}px ${x}px ${y}px ${x}px round ${radius}px)`;
    const cardAt = sendAt + 650;
    const card = play(target, [
      { opacity: 0, transform: `translate(${fromX}px, ${fromY}px) scale(${fromScale})`, clipPath: clip(insetY, insetX, side / 2), easing: EASE_IN_OUT },
      { opacity: 1, transform: `translate(${fromX * 0.82}px, ${fromY * 0.82}px) scale(${fromScale * 1.6})`, clipPath: clip(insetY, insetX, side / 2), offset: 0.12, easing: EASE_IN_OUT },
      { opacity: 1, transform: `translate(${fromX * 0.3}px, ${fromY * 0.3}px) scale(.44)`, clipPath: clip(insetY, insetX, side * 0.12), offset: 0.46, easing: EASE_IN_OUT },
      { opacity: 1, transform: "scale(1.04)", clipPath: clip(0, 0, 0), offset: 0.8, easing: EASE_OUT },
      { opacity: 1, transform: "none", clipPath: clip(0, 0, 0) },
    ], { delay: cardAt, duration: 1000 });
    play(scene, [{ opacity: 1 }, { opacity: 0 }], { delay: cardAt + 800, duration: 200, easing: "linear" });

    card.finished.then(onDone, () => undefined);
    return () => {
      for (const timer of timers) window.clearTimeout(timer);
      for (const animation of animations) animation.cancel();
    };
  }, [onDone, stage]);

  return (
    <div className="login-intro" ref={root} aria-hidden="true" data-testid="login-intro">
      <div className="intro-horizon" />
      <div className="intro-glow" />
      <div className="intro-scene">
        <div className="intro-brand">
          <span className="intro-part intro-mark"><img src={logo.src} width={logo.width} height={logo.height} alt="" decoding="sync" draggable={false} /></span>
          <span className="intro-part intro-word"><span className="intro-word-inner"><img src={logo.src} width={logo.width} height={logo.height} alt="" decoding="sync" draggable={false} /></span></span>
        </div>
        <div className="intro-bar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
          <span className="intro-text"><span ref={typed} /><span ref={fresh} className="intro-fresh" /><span className="intro-caret" /></span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" /></svg>
          <span className="intro-send"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M6 11l6-6 6 6" /></svg></span>
        </div>
      </div>
    </div>
  );
}
