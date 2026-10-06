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
// The QR actually drawn on every page of each representative production ticket decodes to the
// revision's opaque payload, and only that payload (no customer, product or measurement data).
const fixtures = fileURLToPath(new URL("./fixtures/ticket-snapshots.php", import.meta.url));
const payload = "ARASYA:Q1:KZXW6YLTMEQHA4TPMR2WG5DJN5XA";
const ticketPhp = `require ${JSON.stringify(bootstrap)}; $o=[]; foreach((require ${JSON.stringify(fixtures)}) as $name=>$s){ $d=Arasya\\Operations\\Document\\ProductionTicketPdf::document($s,['number'=>2,'status'=>'active','generatedAt'=>'2026-10-06 07:12:00','qrPayload'=>$argv[1]]); foreach($d->qrLog() as $q) $o[]=[$name,$q['page'],$q['matrix']]; } echo json_encode($o);`;
const symbols = JSON.parse(execFileSync("php", ["-r", ticketPhp, payload], { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 }));
const decode = (m) => {
  const scale = 6, quiet = 4, n = m.length, size = (n + 2 * quiet) * scale;
  const data = new Uint8ClampedArray(size * size * 4).fill(255);
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (m[r][c])
    for (let y = 0; y < scale; y++) for (let x = 0; x < scale; x++) {
      const i = (((r + quiet) * scale + y) * size + ((c + quiet) * scale + x)) * 4;
      data[i] = data[i + 1] = data[i + 2] = 0;
    }
  return jsQR(data, size, size)?.data;
};
for (const [name, page, matrix] of symbols) {
  if (decode(matrix) !== payload) { failures++; console.error(`FAIL ticket ${name} page ${page}`); }
}
const pagesByTicket = symbols.reduce((all, [name]) => ({ ...all, [name]: (all[name] ?? 0) + 1 }), {});
if (Object.keys(pagesByTicket).length !== 5 || pagesByTicket["b2b-project"] < 2) { failures++; console.error("FAIL ticket coverage", pagesByTicket); }
if (failures) process.exit(1);
console.log(`PASS production QR encoder: ${cases.length} symbols (versions 1-8, levels L/M/Q/H) and ${symbols.length} printed ticket QR codes (every page of 5 tickets) decoded by jsQR`);
