import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { exceptionUnavailableKey } from "../domain/confirmareFinance";
import { CATALOGS } from "../i18n";

// Finance screen layout and wording (Staff 2.36.1, live owner report 2026-10-26: the bank check button broke one word per
// line, twelve task filters were squeezed into the card, and switched-off finance read like a failure).
const root = path.resolve(import.meta.dirname, "..");
const css = readFileSync(path.join(root, "styles", "confirmare.css"), "utf8");
const source = (file: string) => readFileSync(path.join(root, "features", "confirmare", file), "utf8");

test("every checklist row that carries a button uses the action layout (never the 20 px icon column)", () => {
  for (const file of ["ConfirmareBankCheck.tsx"]) {
    const text = source(file);
    for (const match of text.matchAll(/<li\b[^>]*className=\{?[`"]([^`"]*)[`"]\}?[^>]*>/g)) {
      const row = text.slice(match.index!, text.indexOf("</li>", match.index!));
      if (/<button\b/.test(row)) assert.match(match[1], /\bcf-check-action\b/, `${file}: a checklist row with a button needs cf-check-action`);
    }
  }
  // The plain row stays two columns; the action row spans the button below on phones and gives it a column from 720 px.
  assert.match(css, /\.cf-checklist li \{[^}]*grid-template-columns: 20px minmax\(0, 1fr\);/);
  assert.match(css, /\.cf-checklist li\.cf-check-action > \.button, \.cf-checklist li\.cf-check-action > \.cf-confirm-actions \{ grid-column: 1 \/ -1; \}/);
  const wide = css.slice(css.indexOf("@media (min-width: 720px)"), css.indexOf("}", css.indexOf("cf-check-action .button { white-space")) + 1);
  assert.match(wide, /\.cf-checklist li\.cf-check-action \{ grid-template-columns: 20px minmax\(0, 1fr\) auto; \}/);
  assert.match(wide, /\.cf-checklist li\.cf-check-action \.button \{ white-space: nowrap; \}/);
});

test("the finance task filter is one sideways-scrolling row of one-line tasks; the behaviour (tablist, selection) stays", () => {
  const screen = source("ConfirmareFinanceScreen.tsx");
  assert.match(screen, /className="segmented cf-tabs cf-task-strip" role="tablist" aria-label=\{t\("cf\.fin\.tasks"\)\}/);
  assert.match(screen, /role="tab" aria-selected=\{task === null\} onClick=\{\(\) => setTask\(null\)\}/);
  assert.match(screen, /role="tab" aria-selected=\{task === id\} onClick=\{\(event\) => \{ setTask\(id\); event\.currentTarget\.scrollIntoView/);
  assert.match(css, /\.segmented\.cf-task-strip \{ display: flex; overflow-x: auto;/);
  assert.match(css, /\.segmented\.cf-task-strip button \{ flex: 0 0 auto; white-space: nowrap;/);
  // Only the finance filter changes: the other Confirmare tab rows keep the grid.
  for (const file of ["ConfirmareQueueScreen.tsx", "ConfirmareTransferExceptions.tsx", "ConfirmareShopTasks.tsx"]) assert.doesNotMatch(source(file), /cf-task-strip/, file);
});

test("switched-off finance is named as such, a failed read stays a failure, in every Staff language", () => {
  assert.equal(exceptionUnavailableKey("not_configured"), "cf.fin.exc.notConfigured");
  for (const reason of ["unavailable", "timeout", "unauthorized", "invalid_response", ""]) assert.equal(exceptionUnavailableKey(reason), "cf.fin.exc.unavailable", reason);
  for (const [locale, catalog] of Object.entries(CATALOGS)) {
    const messages = catalog as unknown as Record<string, string>;
    assert.ok(messages["cf.fin.exc.notConfigured"]?.length > 20, `${locale}: notConfigured`);
    assert.notEqual(messages["cf.fin.exc.notConfigured"], messages["cf.fin.exc.unavailable"], locale);
  }
  assert.match(source("ConfirmareTransferExceptions.tsx"), /data-reason=\{list\.reason\}>\{t\(exceptionUnavailableKey\(list\.reason\)\)\}/);
});
