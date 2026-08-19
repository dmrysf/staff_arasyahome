import { access, readdir, readFile } from "node:fs/promises";
import path from "node:path";

const repositoryRoot = path.resolve(import.meta.dirname, "..");
const dist = path.join(repositoryRoot, "dist");
const requiredFiles = ["index.html", "manifest.webmanifest", "sw.js", ".htaccess"];

async function requireFile(relativePath) {
  const target = path.join(dist, relativePath);
  try {
    await access(target);
  } catch {
    throw new Error(`Lipsește artefactul obligatoriu: dist/${relativePath}`);
  }
}

for (const requiredFile of requiredFiles) await requireFile(requiredFile);

const assetDirectory = path.join(dist, "assets");
let assets;
try {
  assets = await readdir(assetDirectory);
} catch {
  throw new Error("Lipsește directorul obligatoriu: dist/assets");
}

if (!assets.some((file) => file.endsWith(".js"))) throw new Error("Lipsește bundle-ul JavaScript din dist/assets");
if (!assets.some((file) => file.endsWith(".css"))) throw new Error("Lipsește bundle-ul CSS din dist/assets");

const indexHtml = await readFile(path.join(dist, "index.html"), "utf8");
if (!indexHtml.includes("/assets/")) throw new Error("dist/index.html nu referă asset-urile Vite");

const htaccess = await readFile(path.join(dist, ".htaccess"), "utf8");
if (!htaccess.includes("Content-Security-Policy")) throw new Error("Politica CSP Staff lipsește");
if (!htaccess.includes("frame-ancestors 'none'")) throw new Error("CSP nu blochează încadrarea Staff");
if (!htaccess.includes("camera=(self)")) throw new Error("Permissions-Policy nu permite camera first-party");
if (htaccess.includes("unsafe-eval") || htaccess.includes("script-src 'unsafe-inline'")) throw new Error("CSP permite script nesigur");
if (htaccess.includes("__ARASYA_CONNECT_SRC__")) throw new Error("Placeholder-ul CSP nu a fost rezolvat");
if (!htaccess.includes("max-age=31536000, immutable")) throw new Error("Regula cache immutable pentru assets lipsește");
if (!htaccess.includes('Cache-Control "no-store, max-age=0"')) throw new Error("Regula non-immutable pentru entrypoint lipsește");

console.log("Artefactele statice Staff sunt complete.");
