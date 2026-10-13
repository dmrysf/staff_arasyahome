import react from "@vitejs/plugin-react";
import { defineConfig, loadEnv } from "vite";
import { simulationApiAllowed } from "./domain/simulation";

export default defineConfig(({ mode }) => {
  if (mode === "simulation") {
    const env = { ...loadEnv(mode, process.cwd(), "VITE_"), ...process.env };
    if (!simulationApiAllowed(env.VITE_STAFF_API_BASE_URL ?? "")) throw new Error("Simulation builds require an isolated loopback or api.sim.arasyahome.ro origin.");
    if (env.VITE_STAFF_DEMO_MODE === "true" || env.VITE_STAFF_PREVIEW_MODE === "true") throw new Error("Simulation must use the real Staff HTTP services.");
  }
  return {
    plugins: [react()],
    // Only isolated artifacts may call a loopback HTTP Operations API.
    define: { __STAFF_E2E_LOOPBACK_API__: JSON.stringify(mode === "e2e" || mode === "simulation") },
    build: { outDir: mode === "simulation" ? "dist-simulation" : mode === "e2e" ? "dist-e2e" : "dist" },
  };
});
