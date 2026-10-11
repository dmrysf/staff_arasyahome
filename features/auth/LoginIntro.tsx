import { useLayoutEffect, useRef, type ReactNode, type RefObject } from "react";

/** Typed into the intro search bar after every successful sign-in: the Arasya Home line chosen by the owner. */
export const INTRO_TEXT = "Designed to Inspire. Made to Last.";

const logo = { src: new URL("../../components/brand/arasya-logo.webp", import.meta.url).href, width: 599, height: 96 };
const EASE_OUT = "cubic-bezier(.2, .8, .2, 1)";
const EASE_IN = "cubic-bezier(.55, 0, .75, .2)";
const EASE_IN_OUT = "cubic-bezier(.65, 0, .35, 1)";
const SPRING = "cubic-bezier(.34, 1.4, .64, 1)";
const TYPE_START_MS = 2200;
const FRESH_CHARACTERS = 4;

/** A human typing rhythm: uneven keystrokes, a beat between words and a breath after a full stop. */
function typingTimes(text: string) {
  const times: number[] = [];
  let at = TYPE_START_MS;
  for (let index = 0; index < text.length; index += 1) {
    at += 30 + ((index * 37) % 5) * 5;
    times.push(at);
    if (text[index] === " ") at += 30;
    if (text[index] === "." && index < text.length - 1) at += 260;
  }
  return times;
}

/**
 * Browser automation (Playwright sets navigator.webdriver) signs in hundreds of times per run; the intro would only
 * slow those runs down. Real browsers always play it: there is no way to pass it and no reduced-motion exception.
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
    const [camera, backdrop, layout, horizon, glow, brand, mark, sharp, soft, word, sheen, bar, text, send, ring, drapeLeft, drapeRight] = [
      ".intro-camera", ".intro-backdrop", ".intro-scene", ".intro-horizon", ".intro-glow", ".intro-brand", ".intro-mark", ".intro-sharp", ".intro-soft",
      ".intro-word-inner", ".intro-sheen-band", ".intro-bar", ".intro-text", ".intro-send", ".intro-ring", ".intro-drape-left", ".intro-drape-right",
    ].map(part);
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
    const sendX = sendBox.left + sendBox.width / 2;
    const sendY = sendBox.top + sendBox.height / 2;

    // The camera drifts in the whole time: the light behind moves more than the logo and bar, which gives depth.
    play(backdrop, [{ transform: "scale(1.14)" }, { transform: "none" }], { duration: 4200 });
    play(layout, [{ transform: "scale(.92)" }, { transform: "none" }], { duration: 4200 });

    // 1. The horizon rises; 4. it flattens into the search bar.
    horizon.style.transformOrigin = "50% 0";
    play(horizon, [
      { opacity: 0, transform: "translateY(16%)" },
      { opacity: 1, transform: "none", offset: 0.38 },
      { opacity: 1, transform: "none", offset: 0.72, easing: EASE_IN_OUT },
      { opacity: 0, transform: `translateY(${toBar}px) scaleX(1.6) scaleY(.06)` },
    ], { duration: 2100 });
    // 2. The "A" comes into focus from close up, 3. moves aside while the rest of the wordmark is drawn out of it.
    play(mark, [
      { opacity: 0, transform: `translateX(${wordShift}px) scale(1.7)` },
      { opacity: 0, transform: `translateX(${wordShift}px) scale(1.7)`, offset: 0.12 },
      { opacity: 1, transform: `translateX(${wordShift}px) scale(1)`, offset: 0.5, easing: EASE_IN_OUT },
      { opacity: 1, transform: `translateX(${wordShift}px) scale(1)`, offset: 0.62, easing: EASE_IN_OUT },
      { opacity: 1, transform: "none" },
    ], { duration: 1700 });
    play(soft, [{ opacity: 0 }, { opacity: 1, offset: 0.3 }, { opacity: 0, offset: 0.58 }, { opacity: 0 }], { duration: 1700 });
    play(sharp, [{ opacity: 0 }, { opacity: 0, offset: 0.3 }, { opacity: 1, offset: 0.58 }, { opacity: 1 }], { duration: 1700 });
    play(word, [{ opacity: 0, transform: "translateX(-10%)", clipPath: "inset(0 100% 0 0)" }, { opacity: 1, transform: "none", clipPath: "inset(0 0% 0 0)" }], { delay: 1400, duration: 640 });
    play(brand, [{ transform: `translateY(${lift}px)` }, { transform: "none" }], { delay: 1500, duration: 650, easing: EASE_IN_OUT });
    play(bar, [{ opacity: 0, transform: "scaleX(.3) scaleY(.35)" }, { opacity: 1, transform: "none" }], { delay: 1560, duration: 650 });
    play(glow, [{ opacity: 0, transform: "translateY(30%)" }, { opacity: 1, transform: "none" }], { delay: 1500, duration: 900 });
    // A band of light sweeps across the finished wordmark.
    play(sheen, [{ transform: "translateX(-120%)" }, { transform: "translateX(320%)" }], { delay: 2050, duration: 900, easing: EASE_IN_OUT });

    // 5. The line types itself with a human rhythm; the newest letters arrive in champagne.
    const times = typingTimes(INTRO_TEXT);
    times.forEach((time, index) => {
      const count = index + 1;
      timers.push(window.setTimeout(() => {
        const split = Math.max(0, count - FRESH_CHARACTERS);
        if (typed.current) typed.current.textContent = INTRO_TEXT.slice(0, split);
        if (fresh.current) fresh.current.textContent = INTRO_TEXT.slice(split, count);
      }, time));
    });
    const typedAt = times[times.length - 1];
    timers.push(window.setTimeout(() => {
      if (typed.current) typed.current.textContent = INTRO_TEXT;
      if (fresh.current) fresh.current.textContent = "";
    }, typedAt + 320));

    // 6. The curtain button appears and is pressed: its drapes part and a ring of light leaves it …
    const sendAt = typedAt + 120;
    const pressAt = sendAt + 450;
    play(send, [
      { opacity: 0, transform: "scale(.4)", easing: SPRING },
      { opacity: 1, transform: "scale(1)", offset: 0.4 },
      { opacity: 1, transform: "scale(1)", offset: 0.6 },
      { opacity: 1, transform: "scale(.84)", offset: 0.76 },
      { opacity: 1, transform: "scale(1)" },
    ], { delay: sendAt, duration: 750 });
    play(drapeLeft, [{ transform: "none" }, { transform: "translateX(-1.5px) scaleX(.45)" }], { delay: pressAt, duration: 420, easing: EASE_IN_OUT });
    play(drapeRight, [{ transform: "none" }, { transform: "translateX(1.5px) scaleX(.45)" }], { delay: pressAt, duration: 420, easing: EASE_IN_OUT });
    play(ring, [{ opacity: 0.9, transform: "scale(1)" }, { opacity: 0, transform: "scale(4.5)" }], { delay: pressAt + 60, duration: 700 });

    // … the line and the logo leave toward the camera, which pushes in on the button …
    // These run "forwards" only, so they do not override the entrances above before they start.
    play(text, [{ opacity: 1, transform: "none" }, { opacity: 0, transform: "translateY(-18px)" }], { delay: pressAt + 40, duration: 380, easing: EASE_IN, fill: "forwards" });
    play(brand, [{ opacity: 1, transform: "none" }, { opacity: 0, transform: "translateY(-18px) scale(1.3)" }], { delay: pressAt - 40, duration: 480, easing: EASE_IN, fill: "forwards" });
    play(bar, [{ opacity: 1, transform: "none" }, { opacity: 0, transform: "scaleX(.9)" }], { delay: pressAt + 220, duration: 420, easing: EASE_IN, fill: "forwards" });
    camera.style.transformOrigin = `${sendX}px ${sendY}px`;
    play(camera, [{ transform: "none" }, { transform: "scale(1.22)" }], { delay: pressAt, duration: 1000, easing: EASE_IN, fill: "forwards" });

    // … and Staff opens from the button: a circle, a card, then the whole screen, settling from slightly close.
    const side = Math.min(width, height);
    const insetX = (width - side) / 2;
    const insetY = (height - side) / 2;
    const fromX = sendX - width / 2;
    const fromY = sendY - height / 2;
    const fromScale = (sendBox.width * 1.15) / side;
    const clip = (y: number, x: number, radius: number) => `inset(${y}px ${x}px ${y}px ${x}px round ${radius}px)`;
    const cardAt = pressAt + 110;
    const card = play(target, [
      { opacity: 0, transform: `translate(${fromX}px, ${fromY}px) scale(${fromScale})`, clipPath: clip(insetY, insetX, side / 2), easing: EASE_IN_OUT },
      { opacity: 1, transform: `translate(${fromX * 0.82}px, ${fromY * 0.82}px) scale(${fromScale * 1.6})`, clipPath: clip(insetY, insetX, side / 2), offset: 0.12, easing: EASE_IN_OUT },
      { opacity: 1, transform: `translate(${fromX * 0.3}px, ${fromY * 0.3}px) scale(.44)`, clipPath: clip(insetY, insetX, side * 0.12), offset: 0.46, easing: EASE_IN_OUT },
      { opacity: 1, transform: "scale(1.05)", clipPath: clip(0, 0, 0), offset: 0.8, easing: EASE_OUT },
      { opacity: 1, transform: "none", clipPath: clip(0, 0, 0) },
    ], { delay: cardAt, duration: 1000 });
    play(scene, [{ opacity: 1 }, { opacity: 0 }], { delay: cardAt + 800, duration: 200, easing: "linear" });

    // 7. Inside Staff the header, the first blocks and the navigation rise in one after another.
    const content = [target.querySelector(".app-header"), ...target.querySelectorAll(".app-content > *"), target.querySelector(".app-nav")]
      .filter((element): element is Element => element !== null).slice(0, 6);
    const settle = content.map((element, index) => play(element, [{ opacity: 0, transform: "translateY(22px)" }, { opacity: 1, transform: "none" }], { delay: cardAt + 480 + index * 70, duration: 520 }));

    Promise.all([card, ...settle].map((animation) => animation.finished)).then(onDone, () => undefined);
    return () => {
      for (const timer of timers) window.clearTimeout(timer);
      for (const animation of animations) animation.cancel();
    };
  }, [onDone, stage]);

  const mask = `url("${logo.src}")`;
  return (
    <div className="login-intro" ref={root} aria-hidden="true" data-testid="login-intro">
      <div className="intro-camera">
        <div className="intro-backdrop">
          <div className="intro-horizon" />
          <div className="intro-glow" />
        </div>
        <div className="intro-scene">
          <div className="intro-brand">
            <span className="intro-part intro-mark">
              <img className="intro-soft" src={logo.src} width={logo.width} height={logo.height} alt="" decoding="sync" draggable={false} />
              <img className="intro-sharp" src={logo.src} width={logo.width} height={logo.height} alt="" decoding="sync" draggable={false} />
            </span>
            <span className="intro-part intro-word"><span className="intro-word-inner"><img src={logo.src} width={logo.width} height={logo.height} alt="" decoding="sync" draggable={false} /></span></span>
            <span className="intro-sheen" style={{ WebkitMaskImage: mask, maskImage: mask }}><span className="intro-sheen-band" /></span>
          </div>
          <div className="intro-bar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><path d="M12 5v14M5 12h14" /></svg>
            <span className="intro-text"><span ref={typed} /><span ref={fresh} className="intro-fresh" /><span className="intro-caret" /></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><rect x="9" y="3" width="6" height="11" rx="3" /><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" /></svg>
            <span className="intro-send">
              <span className="intro-ring" />
              <svg viewBox="0 0 24 24" fill="currentColor">
                <rect x="2.5" y="3.2" width="19" height="1.8" rx=".9" />
                <path className="intro-drape intro-drape-left" d="M4 5.6h6.4c0 3.6-1.2 6.1-3.2 7.6 1.4 1.9 2.2 4.2 2.4 7H4z" />
                <path className="intro-drape intro-drape-right" d="M20 5.6h-6.4c0 3.6 1.2 6.1 3.2 7.6-1.4 1.9-2.2 4.2-2.4 7H20z" />
              </svg>
            </span>
          </div>
        </div>
      </div>
      <div className="intro-vignette" />
      <div className="intro-grain" />
    </div>
  );
}
