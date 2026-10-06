import { useCallback, useEffect, useRef, useState } from "react";
import { selectQrDecoder, type QrDecoder } from "../scanner/qrDecoder";

/**
 * Captures the QR payload of the physical order label. Camera first; if the camera is unavailable the
 * employee can type the label code. The server verifies that the code belongs to the same order.
 */
export function QrCapture({ onToken, disabled }: { onToken: (token: string) => void; disabled?: boolean }) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const decoderRef = useRef<QrDecoder | null>(null);
  const frameRef = useRef<number | null>(null);
  const [state, setState] = useState<"idle" | "starting" | "scanning" | "failed">("idle");
  const [manual, setManual] = useState(false);
  const [code, setCode] = useState("");

  const stop = useCallback(() => {
    if (frameRef.current != null) cancelAnimationFrame(frameRef.current);
    frameRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    decoderRef.current?.dispose?.();
    decoderRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);
  useEffect(() => stop, [stop]);

  async function start() {
    if (state === "starting" || state === "scanning") return;
    setState("starting");
    try {
      const decoder = await selectQrDecoder();
      if (!decoder || !navigator.mediaDevices?.getUserMedia) throw new Error("unavailable");
      decoderRef.current = decoder;
      const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: "environment" } } });
      streamRef.current = stream;
      if (videoRef.current) { videoRef.current.srcObject = stream; await videoRef.current.play(); }
      setState("scanning");
      let last = 0;
      let busy = false;
      const tick = async (time: number) => {
        if (!decoderRef.current || !videoRef.current) return;
        if (!busy && time - last > 200 && videoRef.current.readyState === HTMLMediaElement.HAVE_ENOUGH_DATA) {
          last = time;
          busy = true;
          try {
            const value = await decoderRef.current.detect(videoRef.current);
            if (value) { stop(); setState("idle"); navigator.vibrate?.(35); onToken(value); return; }
          } catch { /* a single unreadable frame is ignored */ }
          finally { busy = false; }
        }
        frameRef.current = requestAnimationFrame(tick);
      };
      frameRef.current = requestAnimationFrame(tick);
    } catch {
      stop();
      setState("failed");
      setManual(true);
    }
  }

  return (
    <div className="qr-capture">
      <div className={state === "scanning" ? "qr-capture-view active" : "qr-capture-view"}><video ref={videoRef} muted playsInline aria-label="Imagine cameră pentru eticheta comenzii" /></div>
      {state !== "scanning" && <button className="button button-primary button-large" type="button" disabled={disabled || state === "starting"} onClick={start}>{state === "starting" ? "Se deschide camera…" : "Scanează eticheta QR a comenzii"}</button>}
      {state === "scanning" && <p className="qr-capture-hint" role="status">Aliniază eticheta comenzii în cadru.</p>}
      {state === "failed" && <p className="order-notice" role="status">Camera nu este disponibilă. Introdu codul de pe etichetă.</p>}
      {!manual && <button className="button button-link" type="button" onClick={() => { stop(); setState("idle"); setManual(true); }}>Introdu codul de pe etichetă</button>}
      {manual && <form className="qr-manual" onSubmit={(event) => { event.preventDefault(); if (code.trim()) onToken(code.trim()); }}>
        <label className="field"><span>Cod etichetă (ARASYA:Q1:…)</span><input autoComplete="off" autoCapitalize="characters" spellCheck={false} maxLength={64} value={code} onChange={(event) => setCode(event.target.value)} /></label>
        <button className="button button-secondary" type="submit" disabled={disabled || !code.trim()}>Verifică codul</button>
      </form>}
    </div>
  );
}
