// Decodes QR matrices produced by the PHP production-sheet encoder with jsQR (the decoder Staff scans with).
// Run from the repository root after `pnpm install`: node operations-api/tests/qr-decode.mjs
import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const jsQR = require("jsqr");
const bootstrap = fileURLToPath(new URL("../bootstrap.php", import.meta.url));
const cases = [
  ["ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ", "Q"], ["ARASYA:Q1:234567ABCDEFGHIJKLMNOPQRST", "M"], ["hello world", "L"],
  ["Ăîș mixed bytes 123", "H"], ["ARASYA:Q1:" + "A".repeat(120), "Q"], ["x".repeat(150), "M"],
];
const php = `require ${JSON.stringify(bootstrap)}; $o=[]; foreach(json_decode($argv[1],true) as [$t,$l]) $o[]=Arasya\\Operations\\B2B\\Pdf\\QrCode::matrix($t,$l); echo json_encode($o);`;
const matrices = JSON.parse(execFileSync("php", ["-r", php, JSON.stringify(cases)], { encoding: "utf8" }));
let failures = 0;
matrices.forEach((m, k) => {
  const scale = 6, quiet = 4, n = m.length, size = (n + 2 * quiet) * scale;
  const data = new Uint8ClampedArray(size * size * 4).fill(255);
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (m[r][c])
    for (let y = 0; y < scale; y++) for (let x = 0; x < scale; x++) {
      const i = (((r + quiet) * scale + y) * size + ((c + quiet) * scale + x)) * 4;
      data[i] = data[i + 1] = data[i + 2] = 0;
    }
  const decoded = jsQR(data, size, size);
  if (decoded?.data !== cases[k][0]) { failures++; console.error(`FAIL case ${k}: ${decoded?.data}`); }
});
if (failures) process.exit(1);
console.log(`PASS production QR encoder: ${cases.length} symbols (versions 1-8, levels L/M/Q/H) decoded by jsQR`);
