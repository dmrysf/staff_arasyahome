// Prepares the real-API Chromium suite: a disposable E2E database seeded through the
// real Operations code, the fixture JSON, and a Y4M "camera" stream showing the order QR.
import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import QRCode from "qrcode";

const root = path.resolve(import.meta.dirname, "..");
export const realApiPaths = {
  fixture: path.join(root, "e2e", ".real-api-fixture.json"),
  video: path.join(root, "e2e", ".runtime", "real-api-qr.y4m"),
  home: path.join(root, "e2e", ".runtime", "home"),
};

if (!process.env.ARASYA_E2E_DB_NAME) {
  console.error("ARASYA_E2E_DB_NAME is required (a dedicated database whose name contains 'e2e' and 'test').");
  process.exit(2);
}

const output = execFileSync("php", [path.join(root, "operations-api", "tests", "e2e-fixture.php")], { env: process.env, encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] });
const fixture = JSON.parse(output);
writeFileSync(realApiPaths.fixture, JSON.stringify(fixture, null, 2));

const width = 640;
const height = 480;
const qr = QRCode.create(fixture.qr[fixture.orders.qr], { errorCorrectionLevel: "M" });
const modules = qr.modules.size;
const scale = Math.floor(300 / (modules + 8));
const size = (modules + 8) * scale;
const left = Math.floor((width - size) / 2);
const top = Math.floor((height - size) / 2);
const luma = Buffer.alloc(width * height, 128);
for (let y = 0; y < size; y += 1) {
  for (let x = 0; x < size; x += 1) {
    const moduleX = Math.floor(x / scale) - 4;
    const moduleY = Math.floor(y / scale) - 4;
    const dark = moduleX >= 0 && moduleY >= 0 && moduleX < modules && moduleY < modules && qr.modules.get(moduleY, moduleX);
    luma[(top + y) * width + left + x] = dark ? 16 : 235;
  }
}
const chroma = Buffer.alloc((width / 2) * (height / 2), 128);
const frames = [Buffer.from(`YUV4MPEG2 W${width} H${height} F10:1 Ip A1:1 C420jpeg\n`)];
for (let frame = 0; frame < 5; frame += 1) frames.push(Buffer.from("FRAME\n"), luma, chroma, chroma);
mkdirSync(path.dirname(realApiPaths.video), { recursive: true });
mkdirSync(realApiPaths.home, { recursive: true });
writeFileSync(realApiPaths.video, Buffer.concat(frames));
console.log(`Real-API E2E fixture ready (${Object.keys(fixture.qr).length} orders).`);
