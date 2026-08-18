import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { StaffApp } from "../app/StaffApp";
import { PwaRegistration } from "../app/PwaRegistration";
import { resolveRuntimeConfig } from "./runtimeConfig";
import "../app/globals.css";
import "../styles/app.css";

const root = document.getElementById("root");

if (!root) throw new Error("Elementul rădăcină al aplicației lipsește.");

const config = resolveRuntimeConfig({
  isDevelopment: import.meta.env.DEV,
  isProduction: import.meta.env.PROD,
  demoFlag: import.meta.env.VITE_STAFF_DEMO_MODE,
  previewFlag: import.meta.env.VITE_STAFF_PREVIEW_MODE,
  apiBaseUrl: import.meta.env.VITE_STAFF_API_BASE_URL,
});

createRoot(root).render(
  <StrictMode>
    <StaffApp initialRoute={window.location.pathname} {...config} />
    <PwaRegistration />
  </StrictMode>,
);
