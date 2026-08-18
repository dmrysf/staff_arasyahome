/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_STAFF_DEMO_MODE?: string;
  readonly VITE_STAFF_API_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
