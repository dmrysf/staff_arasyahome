import type { LiveEvent } from "../../domain/models";

export type SseFrame = { event: string; id: number | null; data: Record<string, unknown> };

/** Parses a complete text/event-stream body into frames; malformed data lines are skipped. */
export function parseSse(text: string): SseFrame[] {
  const frames: SseFrame[] = [];
  for (const block of text.replace(/\r\n/g, "\n").split("\n\n")) {
    let event = "message";
    let id: number | null = null;
    let data: Record<string, unknown> | null = null;
    for (const line of block.split("\n")) {
      if (line.startsWith("event: ")) event = line.slice(7).trim();
      else if (line.startsWith("id: ")) { const value = Number(line.slice(4)); id = Number.isSafeInteger(value) && value > 0 ? value : null; }
      else if (line.startsWith("data: ")) {
        try { const parsed: unknown = JSON.parse(line.slice(6)); if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) data = parsed as Record<string, unknown>; }
        catch { data = null; }
      }
    }
    if (data) frames.push({ event, id, data });
  }
  return frames;
}

export type LiveClientOptions = {
  /** Performs the authenticated GET; resolves with the response or rejects on transport failure. */
  fetchStream: (path: string, signal: AbortSignal) => Promise<Response>;
  onEvent: (event: LiveEvent) => void;
  onState?: (state: "connected" | "reconnecting") => void;
  /** Called for 401/403: the session or access changed; the loop stops. */
  onDenied?: (response: Response) => void;
  isVisible?: () => boolean;
  sleep?: (ms: number, signal: AbortSignal) => Promise<void>;
  visibleDelayMs?: number;
  hiddenDelayMs?: number;
};

const wait = (ms: number, signal: AbortSignal) => new Promise<void>((resolve) => {
  if (signal.aborted) { resolve(); return; }
  const timer = setTimeout(resolve, ms);
  signal.addEventListener("abort", () => { clearTimeout(timer); resolve(); }, { once: true });
});

/**
 * Reconnecting event-stream reader. The first request learns the current cursor (no history), every
 * later request asks only for events after the last cursor, so a reconnect never delivers an event
 * twice. Failures back off (2 s, 4 s … 30 s); access denial stops the loop.
 */
export function startLiveClient(options: LiveClientOptions): () => void {
  const controller = new AbortController();
  const sleep = options.sleep ?? wait;
  const visible = options.isVisible ?? (() => typeof document === "undefined" || document.visibilityState === "visible");
  let cursor: number | null = null;
  let failures = 0;
  let lastDelivered = 0;
  void (async () => {
    while (!controller.signal.aborted) {
      try {
        const response = await options.fetchStream(cursor === null ? "/live/events" : `/live/events?after=${cursor}`, controller.signal);
        if (response.status === 401 || response.status === 403) { options.onDenied?.(response); return; }
        if (!response.ok) throw new Error(`live ${response.status}`);
        const frames = parseSse(await response.text());
        for (const frame of frames) {
          if (frame.event === "ready" || frame.event === "cursor") {
            const next = Number(frame.data.cursor);
            if (Number.isSafeInteger(next) && next >= 0 && (cursor === null || next >= cursor)) cursor = next;
            continue;
          }
          if (frame.id === null || frame.id <= lastDelivered) continue;
          lastDelivered = frame.id;
          const data = frame.data;
          options.onEvent({ seq: frame.id, type: frame.event, exceptionId: typeof data.exceptionId === "string" ? data.exceptionId : undefined,
            orderId: typeof data.orderId === "string" ? data.orderId : undefined, orderNumber: typeof data.orderNumber === "string" ? data.orderNumber : undefined,
            status: typeof data.status === "string" ? data.status : undefined });
        }
        if (failures > 0) options.onState?.("connected");
        failures = 0;
        await sleep(visible() ? options.visibleDelayMs ?? 2_500 : options.hiddenDelayMs ?? 15_000, controller.signal);
      } catch {
        if (controller.signal.aborted) return;
        failures += 1;
        options.onState?.("reconnecting");
        await sleep(Math.min(30_000, 2_000 * 2 ** (failures - 1)), controller.signal);
      }
    }
  })();
  return () => controller.abort();
}
