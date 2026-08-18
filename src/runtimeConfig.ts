export type RuntimeConfigInput = {
  isDevelopment: boolean;
  isProduction: boolean;
  demoFlag?: string;
  previewFlag?: string;
  apiBaseUrl?: string;
};

export type StaffRuntimeMode = "demo" | "preview" | "production";

export type StaffRuntimeConfig = {
  mode: StaffRuntimeMode;
  apiBaseUrl: string;
};

export function resolveRuntimeConfig(input: RuntimeConfigInput): StaffRuntimeConfig {
  let mode: StaffRuntimeMode = "production";
  if (input.isDevelopment && input.demoFlag === "true") mode = "demo";
  else if (input.isProduction && input.previewFlag === "true") mode = "preview";

  return {
    mode,
    apiBaseUrl: input.apiBaseUrl?.trim() ?? "",
  };
}
