export interface QrDecoder {
  readonly kind: "native" | "fallback";
  isSupported(): boolean | Promise<boolean>;
  detect(source: HTMLVideoElement): Promise<string | null>;
  dispose?(): void;
}

export class NativeBarcodeDetectorDecoder implements QrDecoder {
  readonly kind = "native" as const;
  private readonly detector = new BarcodeDetector({ formats: ["qr_code"] });

  async isSupported(): Promise<boolean> {
    if (typeof BarcodeDetector === "undefined") return false;
    if (!BarcodeDetector.getSupportedFormats) return true;
    try {
      return (await BarcodeDetector.getSupportedFormats()).includes("qr_code");
    } catch {
      return false;
    }
  }

  async detect(source: HTMLVideoElement): Promise<string | null> {
    const results = await this.detector.detect(source);
    return results[0]?.rawValue?.trim() || null;
  }
}

function createNativeDecoder(): QrDecoder | null {
  if (typeof BarcodeDetector === "undefined") return null;
  try {
    return new NativeBarcodeDetectorDecoder();
  } catch {
    return null;
  }
}

export async function loadFallbackQrDecoder(): Promise<QrDecoder | null> {
  // Future QR packages are lazy-imported only here; no fallback library ships in V1.1.
  return null;
}

type DecoderSelectionOptions = {
  nativeDecoder?: QrDecoder | null;
  fallbackLoader?: () => Promise<QrDecoder | null>;
};

export async function selectQrDecoder(options: DecoderSelectionOptions = {}): Promise<QrDecoder | null> {
  const nativeDecoder = options.nativeDecoder === undefined ? createNativeDecoder() : options.nativeDecoder;
  if (nativeDecoder && await nativeDecoder.isSupported()) return nativeDecoder;
  nativeDecoder?.dispose?.();

  const fallback = await (options.fallbackLoader ?? loadFallbackQrDecoder)();
  if (fallback && await fallback.isSupported()) return fallback;
  fallback?.dispose?.();
  return null;
}
