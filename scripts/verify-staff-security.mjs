import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { connectSource, normalizeHttpsOrigin } from "./generate-staff-htaccess.mjs";

const template = await readFile(new URL("./staff-htaccess.template", import.meta.url), "utf8");
const productionOrigin = "https://api.arasyahome.ro";
const productionConnect = connectSource({ preview: false, apiBaseUrl: `${productionOrigin}/` });
const previewConnect = connectSource({ preview: true, apiBaseUrl: productionOrigin });

assert.equal(normalizeHttpsOrigin(`${productionOrigin}/`), productionOrigin);
assert.equal(productionConnect, `'self' ${productionOrigin}`);
assert.equal(previewConnect, "'self'");
assert.throws(() => normalizeHttpsOrigin("http://api.arasyahome.ro"));
assert.throws(() => normalizeHttpsOrigin(`${productionOrigin}/path`));
assert.match(template, /script-src 'self'/);
assert.doesNotMatch(template, /script-src[^;]*(?:'unsafe-inline'|'unsafe-eval')/);
assert.match(template, /frame-ancestors 'none'/);
assert.match(template, /camera=\(self\)/);
assert.match(template.replaceAll("__ARASYA_CONNECT_SRC__", productionConnect), new RegExp(`connect-src 'self' ${productionOrigin}`));
assert.match(template, /max-age=31536000, immutable/);
assert.match(template, /<Files "index\.html">[\s\S]*no-store/);

console.log("PASS Staff CSP origin validation, framing, camera and cache policies are deliberate.");
