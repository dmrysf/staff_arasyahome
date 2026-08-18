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

console.log("Artefactele statice Staff sunt complete.");
