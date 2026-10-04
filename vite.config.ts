import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

export default defineConfig(({ mode }) => ({
  plugins: [react()],
  // Only the isolated real-API browser test artifact may call a loopback HTTP Operations API.
  define: { __STAFF_E2E_LOOPBACK_API__: JSON.stringify(mode === "e2e") },
  build: { outDir: mode === "e2e" ? "dist-e2e" : "dist" },
}));
