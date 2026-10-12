import { useLayoutEffect, useRef, type CSSProperties, type ReactNode } from "react";
import { INTRO_MAP } from "./introMapData";

const EASE_OUT = "cubic-bezier(.2, .8, .2, 1)";
const EASE_IN = "cubic-bezier(.55, 0, .75, .2)";
const EASE_IN_OUT = "cubic-bezier(.65, 0, .35, 1)";
const SPRING = "cubic-bezier(.34, 1.56, .64, 1)";

/** The two Arasya Home offices, marked on the map. */
const OFFICES = [
  { key: "krefeld", name: "Krefeld", at: INTRO_MAP.krefeld, delay: 1900 },
  { key: "bucharest", name: "Bucharest", at: INTRO_MAP.bucharest, delay: 2150 },
] as const;

/** The route between the offices is drawn in its own small box (map units), so revealing it repaints nothing else. */
const ROUTE_BOX = { x: 363, y: 79, width: 304, height: 166 };
const box = (left: number, top: number, width?: number, height?: number): CSSProperties => ({
  left: `${left / 10}%`,
  top: `${top / 5}%`,
  ...(width === undefined ? {} : { width: `${width / 10}%`, height: `${height! / 5}%` }),
});

/** Points along the route (a quadratic curve), as percentages of the route box, for the spark that travels it. */
function routePoints(steps: number) {
  const [x0, y0, cx, cy, x1, y1] = INTRO_MAP.route.match(/-?[\d.]+/g)!.map(Number);
  return Array.from({ length: steps + 1 }, (_, index) => {
    const t = index / steps;
    const x = (1 - t) ** 2 * x0 + 2 * t * (1 - t) * cx + t ** 2 * x1;
    const y = (1 - t) ** 2 * y0 + 2 * t * (1 - t) * cy + t ** 2 * y1;
    return `translate(${(((x - ROUTE_BOX.x) / ROUTE_BOX.width) * 100).toFixed(2)}%, ${(((y - ROUTE_BOX.y) / ROUTE_BOX.height) * 100).toFixed(2)}%)`;
  });
}

/** A window that wipes its content in from west to east (the window and its content move against each other). */
function Wipe({ className, children }: { className: string; children: ReactNode }) {
  return <div className={`intro-wipe ${className}`}><div className="intro-wipe-inner">{children}</div></div>;
}

/**
 * The map of Europe inside the intro's horizon: country lines in blue, Germany and Romania tinted, the Krefeld and
 * Bucharest offices pulsing in champagne and joined by a route of light. It is loaded only when a sign-in starts
 * (its own small chunk), drawn as vectors so it stays sharp at any resolution, and removed with the intro.
 *
 * Phones must play it without dropping frames, so the drawings never change once painted: every movement is a
 * transform or an opacity change of a whole layer, which the compositor runs on its own (a busy main thread, e.g.
 * Staff loading its data behind the intro, cannot stall it). Reveals are windows sliding over their content.
 * `start` is when the intro began (performance.now()), so the map keeps time with it even if it arrives late.
 */
export default function IntroMap({ start, pressAt }: { start: number; pressAt: number }) {
  const root = useRef<HTMLDivElement>(null);

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
    const wipe = (selector: string, options: KeyframeAnimationOptions & { delay: number }) => {
      play(one(selector), [{ transform: "translateX(-100%)" }, { transform: "none" }], options);
      play(one(`${selector} > .intro-wipe-inner`), [{ transform: "translateX(120%)" }, { transform: "none" }], options);
    };

    // The map rises with the horizon and slowly turns into place.
    play(map, [{ opacity: 0, transform: "translateY(14%)" }, { opacity: 1, transform: "none" }], { delay: 150, duration: 1100 });
    play(one(".intro-map-view"), [
      { opacity: 0, transform: "rotate(-3deg) scale(1.1)" },
      { opacity: 1, transform: "rotate(-.6deg) scale(1.025)", offset: 0.4 },
      { opacity: 1, transform: "none" },
    ].map((frame) => ({ easing: EASE_OUT, ...frame })), { delay: 350, duration: 5000, easing: "linear" });
    play(one(".intro-map-key"), [{ opacity: 0 }, { opacity: 1 }], { delay: 1500, duration: 900 });

    // A blue scan sweeps across the lines, twice: a window of brighter lines slides over the map.
    for (const delay of [1500, 3500]) {
      play(one(".intro-map-scan"), [{ transform: "translateX(-112.5%)" }, { transform: "translateX(387.5%)" }], { delay, duration: 1700, easing: EASE_IN_OUT, fill: "none" });
      play(one(".intro-map-scan-lines"), [{ transform: "translateX(36%)" }, { transform: "translateX(-124%)" }], { delay, duration: 1700, easing: EASE_IN_OUT, fill: "none" });
    }

    // Country names arrive from west to east.
    wipe(".intro-map-names", { delay: 1200, duration: 1500, easing: EASE_IN_OUT });

    // The offices: a dot springs in, rings pulse out of it, the name slides in beside it.
    for (const office of OFFICES) {
      const city = `.intro-city-${office.key}`;
      play(one(`${city} .intro-city-dot`), [{ transform: "scale(0)" }, { transform: "scale(1)" }], { delay: office.delay, duration: 650, easing: SPRING });
      play(one(`${city} .intro-city-halo`), [{ opacity: 0, transform: "scale(.3)" }, { opacity: 1, transform: "none" }], { delay: office.delay, duration: 900 });
      all(`${city} .intro-city-ring`).forEach((ring, index) => {
        play(ring, [{ opacity: 0.9, transform: "scale(.17)" }, { opacity: 0, transform: "none" }], { delay: office.delay + 150 + index * 900, duration: 1800, iterations: Infinity, fill: "none" });
      });
      play(one(`${city} .intro-city-name`), [
        { opacity: 0, transform: `translateX(${office.key === "krefeld" ? 8 : -8}px)` },
        { opacity: 1, transform: "none" },
      ], { delay: office.delay + 200, duration: 700 });
    }

    // The route between the offices draws itself, then a spark of light travels along it.
    wipe(".intro-map-route-line", { delay: 2450, duration: 1100, easing: EASE_IN_OUT });
    const points = routePoints(16);
    play(one(".intro-map-spark"), points.map((transform, index) => ({
      transform,
      opacity: index === 0 || index === points.length - 1 ? 0 : 1,
      easing: "linear",
    })), { delay: 3300, duration: 1500, easing: EASE_IN_OUT, iterations: Infinity });

    // It leaves with the rest of the scene when the button is pressed.
    play(map, [{ opacity: 1 }, { opacity: 0 }], { delay: pressAt + 80, duration: 520, easing: EASE_IN, fill: "forwards" });

    return () => { for (const animation of animations) animation.cancel(); };
  }, [start, pressAt]);

  return (
    <div className="intro-map" ref={root}>
      <div className="intro-map-view">
        <div className="intro-map-plane">
          <svg className="intro-map-base" viewBox="0 0 1000 500">
            <path className="intro-map-grid" d={INTRO_MAP.grid} />
            <path className="intro-map-coast" d={INTRO_MAP.coast} />
            <path className="intro-map-borders" d={INTRO_MAP.borders} />
          </svg>
          <svg className="intro-map-key" viewBox="0 0 1000 500"><path d={INTRO_MAP.germany + INTRO_MAP.romania} /></svg>
          <div className="intro-map-scan">
            <svg className="intro-map-scan-lines" viewBox="0 0 1000 500"><path d={INTRO_MAP.coast} /><path d={INTRO_MAP.borders} /></svg>
          </div>
          {/* Names are HTML text: SVG text is laid out again whenever a parent layer moves, HTML text is not. */}
          <Wipe className="intro-map-names">
            {INTRO_MAP.labels.map((label) => (
              <span key={label.text} className={`intro-map-label${label.size < 12 ? " is-small" : ""}${label.key ? " is-key" : ""}`} style={box(label.x, label.y)}>{label.text}</span>
            ))}
          </Wipe>
          <div className="intro-map-route" style={box(ROUTE_BOX.x, ROUTE_BOX.y, ROUTE_BOX.width, ROUTE_BOX.height)}>
            <Wipe className="intro-map-route-line">
              <svg viewBox={`${ROUTE_BOX.x} ${ROUTE_BOX.y} ${ROUTE_BOX.width} ${ROUTE_BOX.height}`}><path d={INTRO_MAP.route} /></svg>
            </Wipe>
            <span className="intro-map-spark"><span /></span>
          </div>
          {OFFICES.map((office) => (
            <div key={office.key} className={`intro-city intro-city-${office.key}`} style={box(office.at[0], office.at[1])}>
              <span className="intro-city-halo" />
              <span className="intro-city-ring" />
              <span className="intro-city-ring" />
              <span className="intro-city-dot" />
              <span className="intro-city-name">{office.name}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
