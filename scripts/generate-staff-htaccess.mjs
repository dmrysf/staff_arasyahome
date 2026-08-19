import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";

const repositoryRoot = path.resolve(import.meta.dirname, "..");
const templatePath = path.join(repositoryRoot, "scripts", "staff-htaccess.template");
const outputPath = path.join(repositoryRoot, "dist", ".htaccess");

export function normalizeHttpsOrigin(rawValue) {
  const raw = rawValue.trim();
  if (!raw) return "";
  let url;
  try { url = new URL(raw); }
  catch { throw new Error("VITE_STAFF_API_BASE_URL must be a valid HTTPS origin."); }
  if (url.protocol !== "https:" || url.username || url.password || url.search || url.hash || (url.pathname !== "/" && url.pathname !== "")) {
    throw new Error("VITE_STAFF_API_BASE_URL must be an exact HTTPS origin.");
  }
  if (/[\r\n]/.test(url.origin)) throw new Error("VITE_STAFF_API_BASE_URL contains invalid header characters.");
  return url.origin;
}

export function connectSource({ preview, apiBaseUrl }) {
  if (preview) return "'self'";
  const origin = normalizeHttpsOrigin(apiBaseUrl);
  return origin ? `'self' ${origin}` : "'self'";
}

export async function generateStaffHtaccess(environment = process.env) {
  const template = await readFile(templatePath, "utf8");
  const policy = connectSource({
    preview: environment.VITE_STAFF_PREVIEW_MODE === "true",
    apiBaseUrl: environment.VITE_STAFF_API_BASE_URL ?? "",
  });
  const output = template.replaceAll("__ARASYA_CONNECT_SRC__", policy);
  if (output.includes("__ARASYA_CONNECT_SRC__")) throw new Error("Staff CSP placeholder was not resolved.");
  await writeFile(outputPath, output, { encoding: "utf8", mode: 0o644 });
  return output;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  await generateStaffHtaccess();
}
