import jsQR from "jsqr";
import type { QrDecoder } from "./qrDecoder";

const MAX_FRAME_EDGE = 640;

/**
 * Pure-JS QR-only fallback (Safari/iOS and other browsers without BarcodeDetector).
 * Loaded lazily in its own chunk; frames are downscaled to bound CPU per decode.
 */
export class JsQrFallbackDecoder implements QrDecoder {
  readonly kind = "fallback" as const;
  private canvas: HTMLCanvasElement | null = typeof document === "undefined" ? null : document.createElement("canvas");
  private context: CanvasRenderingContext2D | null = this.canvas?.getContext("2d", { willReadFrequently: true }) ?? null;

  isSupported(): boolean {
    return this.context !== null;
  }

  async detect(source: HTMLVideoElement): Promise<string | null> {
    const canvas = this.canvas;
    const context = this.context;
    const width = source.videoWidth;
    const height = source.videoHeight;
    if (!canvas || !context || !width || !height) return null;
    const scale = Math.min(1, MAX_FRAME_EDGE / Math.max(width, height));
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    const frame = context.getImageData(0, 0, canvas.width, canvas.height);
    const result = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: "dontInvert" });
    return result?.data.trim() || null;
  }

  dispose(): void {
    if (this.canvas) {
      this.canvas.width = 0;
      this.canvas.height = 0;
    }
    this.canvas = null;
    this.context = null;
  }
}
