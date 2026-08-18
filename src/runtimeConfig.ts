export type RuntimeConfigInput = {
  isDevelopment: boolean;
  demoFlag?: string;
  apiBaseUrl?: string;
};

export function resolveRuntimeConfig(input: RuntimeConfigInput) {
  return {
    demoMode: input.isDevelopment && input.demoFlag === "true",
    apiBaseUrl: input.apiBaseUrl?.trim() ?? "",
  };
}
