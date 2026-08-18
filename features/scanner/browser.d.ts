interface BarcodeDetectorOptions { formats?: string[] }
interface DetectedBarcode { rawValue: string }
interface BarcodeDetector {
  detect(source: HTMLVideoElement): Promise<DetectedBarcode[]>;
}
declare const BarcodeDetector: {
  prototype: BarcodeDetector;
  new (options?: BarcodeDetectorOptions): BarcodeDetector;
  getSupportedFormats?(): Promise<string[]>;
};
