# Contextual employee guide (Staff 2.38.0)

New employees open a workspace and meet many numbers, states and buttons at once. The guide answers three questions
for the screen they are on: what this place is for, what they do here, and what happens when they finish. It is a
presentation layer only: no API call, no table, no migration, and it never performs or unlocks an action.

## Where it appears

- **Header control** "Cum funcționează? / Nasıl çalışır?" on every framed screen of every signed-in employee. It opens
  a native modal dialog: a bottom sheet on phones, a side panel from 768 px. Escape, the close button or a tap on the
  backdrop closes it, and focus returns to the control. The full-screen QR scanner has no header and no control.
- **Home card** under the workspace switcher: the open workspace in two lines and "Vezi detalii / Ayrıntıları göster".
  The employee can hide it and show it again.
- **Management overview**: each summary number carries a one-line explanation, and "Ce înseamnă aceste cifre? /
  Bu sayılar ne anlama geliyor?" opens the glossary.

## Content

- Catalog: `i18n/messages/guide.ts`, Romanian and Turkish only, the same meaning in both. The tests refuse an empty
  or untranslated text, Turkish letters in Romanian copy and Romanian letters in Turkish copy.
- Each topic has: title, two-line summary, purpose, at most four steps, what happens next, one caution where a real
  rule exists, and optional details. Button names are quoted exactly as the Staff screens show them.
- Topics: the Staff overview, every workspace (Confirmare, finance-only Confirmare, Trendyol, production documents,
  management, the general panel and the seven production departments) and every routed screen (orders, order detail,
  history, profile, tailoring supervisor, production exception, production authority, Trendyol package, production
  document, the Confirmare order, team, finance, production handoff, AWB queue, e-Factura follow-up, operating health).
- Management numbers follow `ProductionOverviewService` (flow `current`): active, waiting (still at stage 1), in work,
  unassigned (no current production owner, normal at stage 1), completed today (Europe/Bucharest day).
- The rules stated as rules: a telephone confirmation is not a verified payment; recording a payment is not verifying
  it, and nobody verifies their own record; an unknown invoice or proforma outcome is never issued again (duplicate);
  invoice management and finance approval are separate rights; the AWB is made only in Staff (the CurieRO button in
  WordPress collects the whole total); SPV and e-Factura are the accountant's responsibility and Arasya does not read SPV.

## Context and permissions (`domain/guide.ts`)

- The topic comes from the route; on the home screen from the open workspace (`useGuideWorkspace`).
- A line with `need` appears only when the session grants that capability (Confirmare operate or supervise, finance
  record or approve, invoice issue or manage, stage claim and advance, fault reporting, document permissions,
  production authority, the Dashboard link). Nobody is told about a button they do not see.
- Shop scopes are listed from the session only ("Emiți factura finală: Trend Home"); no order, customer or amount is
  ever read by the guide.
- Production staff see only their own stages; a one-stage workspace explains its stage in the steps.

## Language

- The guide language is chosen in the guide (Română | Türkçe) and is independent of the account's Staff language.
- It starts in Turkish for Turkish accounts and in Romanian for the others.
- It is stored per employee (`arasya_staff_guide_lang:<employee uuid>`); the provider is keyed by the employee, so the
  next person on a shared phone starts from their own choice. Storage failures fall back to the default.

## Failure isolation

The header control, the dialog and the home card sit in `GuideBoundary`: an error renders nothing and the screen
underneath keeps working. The resolver is wrapped the same way.

## Tests

- `tests/contextual-guide.test.ts`: catalog parity, routes, workspace switching, the go-live roles (operator, finance
  recorder, finance approver with invoice manager, the Trend Home and Outlet Perdele issuers), production stages,
  management definitions, capability filtering, language storage, the rendered dialog and the static-only rule.
- `e2e/staff-guide.spec.ts` (preview): the control, the dialog with axe, the language switch leaving the Staff language
  alone, Escape and focus return, the per-employee choice after reload, another screen's topic, the home card, 320 px
  phones and the desktop side panel.
