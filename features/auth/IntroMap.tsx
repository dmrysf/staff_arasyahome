import { useId, useLayoutEffect, useRef } from "react";
import { INTRO_MAP } from "./introMapData";

const EASE_OUT = "cubic-bezier(.2, .8, .2, 1)";
const EASE_IN = "cubic-bezier(.55, 0, .75, .2)";
const EASE_IN_OUT = "cubic-bezier(.65, 0, .35, 1)";
const SPRING = "cubic-bezier(.34, 1.56, .64, 1)";

/** The two Arasya Home offices, marked on the map. */
const OFFICES = [
  { key: "krefeld", name: "Krefeld", at: INTRO_MAP.krefeld, label: { dx: -12, dy: 4, anchor: "end" }, delay: 1900 },
  { key: "bucharest", name: "Bucharest", at: INTRO_MAP.bucharest, label: { dx: 12, dy: 4, anchor: "start" }, delay: 2150 },
] as const;

/**
 * The map of Europe inside the intro's horizon: country lines in blue, Germany and Romania tinted, the Krefeld and
 * Bucharest offices pulsing in champagne and joined by a route of light. It is loaded only when a sign-in starts
 * (its own small chunk), drawn as vectors so it stays sharp at any resolution, and removed with the intro.
 * `start` is when the intro began (performance.now()), so the map keeps time with it even if it arrives late.
 */
export default function IntroMap({ start, pressAt }: { start: number; pressAt: number }) {
  const root = useRef<HTMLDivElement>(null);
  const id = useId().replace(/:/g, "");

  useLayoutEffect(() => {
    const map = root.current;
    if (!map || typeof map.animate !== "function") return;
    const elapsed = performance.now() - start;
    const animations: Animation[] = [];
    const play = (element: Element | null, keyframes: Keyframe[], options: KeyframeAnimationOptions & { delay?: number }) => {
      if (!element) return;
      animations.push(element.animate(keyframes, { fill: "both", easing: EASE_OUT, ...options, delay: (options.delay ?? 0) - elapsed }));
    };
    const one = (selector: string) => map.querySelector(selector);
    const all = (selector: string) => [...map.querySelectorAll(selector)];

    // The map rises with the horizon, opens from the top like light spreading over it and slowly turns into place.
    play(map, [{ opacity: 0, transform: "translateY(14%)" }, { opacity: 1, transform: "none" }], { delay: 150, duration: 1100 });
    play(one(".intro-map-view"), [
      { clipPath: "circle(0% at 50% 0%)", transform: "rotate(-3deg) scale(1.1)" },
      { clipPath: "circle(150% at 50% 0%)", transform: "rotate(-.6deg) scale(1.025)", offset: 0.4 },
      { clipPath: "circle(150% at 50% 0%)", transform: "none" },
    ].map((frame) => ({ easing: EASE_OUT, ...frame })), { delay: 350, duration: 5000, easing: "linear" });
    play(one(".intro-map-grid"), [{ opacity: 0 }, { opacity: 1 }], { delay: 300, duration: 900 });
    play(one(".intro-map-key"), [{ opacity: 0 }, { opacity: 1 }], { delay: 1500, duration: 900 });

    // A blue scan sweeps across the lines, twice.
    play(one(".intro-map-scan-band"), [{ transform: "translateX(0)" }, { transform: "translateX(1600px)" }], { delay: 1500, duration: 1700, easing: EASE_IN_OUT, fill: "none" });
    play(one(".intro-map-scan-band"), [{ transform: "translateX(0)" }, { transform: "translateX(1600px)" }], { delay: 3500, duration: 1700, easing: EASE_IN_OUT, fill: "none" });

    // Country names arrive from west to east.
    all(".intro-map-label").sort((a, b) => Number(a.getAttribute("x")) - Number(b.getAttribute("x"))).forEach((label, index) => {
      play(label, [{ opacity: 0, transform: "translateY(5px)" }, { opacity: 1, transform: "none" }], { delay: 1250 + index * 45, duration: 600 });
    });

    // The offices: a dot springs in, rings pulse out of it, the name slides in beside it.
    for (const office of OFFICES) {
      play(one(`.intro-city-${office.key} .intro-city-dot`), [{ transform: "scale(0)" }, { transform: "scale(1)" }], { delay: office.delay, duration: 650, easing: SPRING });
      play(one(`.intro-city-${office.key} .intro-city-halo`), [{ opacity: 0, transform: "scale(.3)" }, { opacity: 1, transform: "none" }], { delay: office.delay, duration: 900 });
      all(`.intro-city-${office.key} .intro-city-ring`).forEach((ring, index) => {
        play(ring, [{ opacity: 0.9, transform: "scale(1)" }, { opacity: 0, transform: "scale(6)" }], { delay: office.delay + 150 + index * 900, duration: 1800, iterations: Infinity, fill: "none" });
      });
      play(one(`.intro-city-${office.key} .intro-city-name`), [
        { opacity: 0, transform: `translateX(${office.label.anchor === "end" ? 8 : -8}px)` },
        { opacity: 1, transform: "none" },
      ], { delay: office.delay + 200, duration: 700 });
    }

    // The route between the offices draws itself, then a spark of light travels along it.
    play(one(".intro-map-route"), [{ strokeDashoffset: 1 }, { strokeDashoffset: 0 }], { delay: 2450, duration: 1100, easing: EASE_IN_OUT });
    play(one(".intro-map-spark"), [{ strokeDashoffset: 0.1, opacity: 1 }, { strokeDashoffset: -1, opacity: 1 }], { delay: 3300, duration: 1500, easing: EASE_IN_OUT, iterations: Infinity });

    // It leaves with the rest of the scene when the button is pressed.
    play(map, [{ opacity: 1 }, { opacity: 0 }], { delay: pressAt + 80, duration: 520, easing: EASE_IN, fill: "forwards" });

    return () => { for (const animation of animations) animation.cancel(); };
  }, [start, pressAt]);

  return (
    <div className="intro-map" ref={root}>
      <div className="intro-map-view">
        <svg viewBox="0 0 1000 500" preserveAspectRatio="xMidYMin slice">
          <defs>
            <linearGradient id={`${id}-band`} x1="0" x2="1" y1="0" y2="0">
              <stop offset="0" stopColor="white" stopOpacity="0" />
              <stop offset=".5" stopColor="white" />
              <stop offset="1" stopColor="white" stopOpacity="0" />
            </linearGradient>
            <mask id={`${id}-scan`} maskUnits="userSpaceOnUse" x="-400" y="-50" width="1800" height="600">
              <rect className="intro-map-scan-band" x="-360" y="-50" width="320" height="600" fill={`url(#${id}-band)`} />
            </mask>
          </defs>
          <path className="intro-map-grid" d={INTRO_MAP.grid} />
          <path className="intro-map-key" d={INTRO_MAP.germany + INTRO_MAP.romania} />
          <path className="intro-map-coast" d={INTRO_MAP.coast} />
          <path className="intro-map-borders" d={INTRO_MAP.borders} />
          <g className="intro-map-scan" mask={`url(#${id}-scan)`}>
            <path d={INTRO_MAP.coast} />
            <path d={INTRO_MAP.borders} />
          </g>
          {INTRO_MAP.labels.map((label) => (
            <text key={label.text} className={`intro-map-label${label.size < 12 ? " is-small" : ""}${label.key ? " is-key" : ""}`} x={label.x} y={label.y} textAnchor="middle">{label.text}</text>
          ))}
          <path className="intro-map-route" d={INTRO_MAP.route} pathLength={1} />
          <path className="intro-map-spark" d={INTRO_MAP.route} pathLength={1} />
          {OFFICES.map((office) => (
            <g key={office.key} className={`intro-city intro-city-${office.key}`}>
              <circle className="intro-city-halo" cx={office.at[0]} cy={office.at[1]} r="16" />
              <circle className="intro-city-ring" cx={office.at[0]} cy={office.at[1]} r="3.4" />
              <circle className="intro-city-ring" cx={office.at[0]} cy={office.at[1]} r="3.4" />
              <circle className="intro-city-dot" cx={office.at[0]} cy={office.at[1]} r="3.6" />
              <g className="intro-city-tag"><text className="intro-city-name" x={office.at[0] + office.label.dx} y={office.at[1] + office.label.dy} textAnchor={office.label.anchor}>{office.name}</text></g>
            </g>
          ))}
        </svg>
      </div>
    </div>
  );
}
