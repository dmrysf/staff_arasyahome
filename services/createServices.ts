import type { ServiceBundle } from "./contracts";
import { createDemoServices } from "./dev/demoServices";
import { createProductionServices } from "./production/httpServices";

export function createServices(config: { demoMode: boolean; apiBaseUrl: string }): ServiceBundle {
  return config.demoMode ? createDemoServices() : createProductionServices(config.apiBaseUrl);
}
