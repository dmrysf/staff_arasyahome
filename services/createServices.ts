import type { ServiceBundle } from "./contracts";
import { createDemoServices } from "./dev/demoServices";
import { createPreviewServices } from "./preview/previewServices";
import { createProductionServices } from "./production/httpServices";
import type { StaffRuntimeConfig } from "../src/runtimeConfig";

export function createServices(config: StaffRuntimeConfig): ServiceBundle {
  switch (config.mode) {
    case "demo": return createDemoServices();
    case "preview": return createPreviewServices();
    case "production": return createProductionServices(config.apiBaseUrl);
  }
}
