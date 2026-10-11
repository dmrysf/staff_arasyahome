# Arasya Staff — Operational UI System

## Design philosophy

Arasya Staff is a scan-first operational instrument, not a dashboard. The interface favors immediate recognition, comfortable touch targets, clear production context, and restrained feedback. Decorative surfaces, glow, gradients, and explanatory copy are minimized so the employee's next action remains obvious during repeated phone use.

The visual hierarchy is: scan, understand the order, perform the permitted action, confirm, and continue. Desktop and tablet retain the same mobile application identity rather than becoming an administration interface.

## Brand and color semantics

The official Arasya logo is reserved for quiet brand identification, most visibly on Login and at a much smaller scale inside the authenticated experience. It must retain its original proportions and artwork and must not be used as utility iconography.

The palette is white surfaces and black text; black identifies Scan, active selection, and primary CTAs. Green is semantic and is reserved for connected, confirmed, completed, and success states. Amber communicates checking or warning. A stronger red communicates destructive, disconnected, and error states. These roles are centralized in CSS design tokens.

## Navigation and Scan

Phones use a docked bottom bar (Acasă, Comenzi, Scan, Istoric, Profil) that sits on the safe-area inset instead of floating over content; the active item carries an accent marker and an accent icon in addition to the label. Scan is a 64px rounded-square control raised from the center of the bar, rendered only with `orders.scan`; an empty slot keeps it centered when a destination is not authorized. Landscape phones use a shorter bar.

From 1024px the same navigation element becomes a sidebar: brand, a labelled `Scanează QR` action, the destinations, and Profil pinned to the bottom. Content then uses the screen width up to 1320px instead of the former 880px column.

The camera, decoder selection, duplicate guard, cleanup, confirmation, idempotency, expected-version, and server-confirmed success behavior are unchanged.

## Design system (Staff 2.15.0, Package 1)

`styles/tokens.css` is the only place for colors, type scale, spacing, radii, control heights, motion durations and easings, layers and safe areas. The default look is **minimalist: white background, black text, black primary buttons** (owner decision, 2026-10-11). Color appears only as small functional signals (status dots, icons, edges and faint wells for warnings and errors); the text on them stays black. The earlier graphite theme remains available as an opt-in (`<html data-theme="dark">`); camera screens stay dark in every theme. `styles/foundation.css` holds primitives (buttons, fields, status pills, notices, skeletons, task steps) and the app shell; `styles/experiences.css` holds the reference experiences. Neither file contains a hex color (a unit test enforces it).

- Typography uses system faces (no font download) with tabular figures for numbers and a monospace face for order identifiers and measurements. Inputs use 16px text so iOS never zooms.
- Status is always words plus an icon; the tone only colors the icon or dot.
- Buttons move: the primary button lifts on hover and its trailing arrow travels; outlined buttons fill black from the left and turn their label white; every press scales in and shows a short ring (felt on touch screens without hover); a busy button shows a running bar under its label; a newly enabled primary action settles in once.
- Motion uses transform and opacity only (press 110ms, hover 200ms, sweep 320ms, screen 240ms, success 380ms, stage 450ms) and collapses under `prefers-reduced-motion`. No animation triggers a request.

Reference experiences:

- **Home**: a compact greeting, the workspace switcher as scrollable chips on phones, and for Trendyol operators one *next step* card chosen from data already loaded (ready for approval, then measurements to complete, then attention). It links to the existing screens and never acts. Desktop splits work (left) and status (right).
- **Trendyol preparation**: five guided steps (`Verifică produsele`, `Completează măsurile`, `Aprobă producția`, `Tipărește fișa cu QR`, `Trimite la Tăiere`) derived only from the package the server returned. A step is done only when server data proves it; printing is never shown as done. Each line shows confirmed, unsaved or to-complete state, a radio product-type picker, the Trendyol size suggestion marked as unconfirmed, paired width × height inputs, and validation next to the field. The form never refreshes itself.
- **Production document**: revision, status, a print card stating that the button downloads the server PDF with the production QR, a separate preview without QR, and the revision history. Counts say *PDF descărcat pentru tipărire*, never *tipărit*: the server cannot know that paper came out of a printer.

## Shared system across every screen (Staff 2.16.0, Package 2)

`styles/app.css` now styles every remaining screen (login, orders, order detail, history, profile, scanner, sheets, confirmation and success states, exceptions, QR authority, document lookup, management tables, cutting tools) from the tokens only; a unit test refuses any hex or rgb colour outside `tokens.css`. Shared React states live in `components/ui/States.tsx`:

- `BootScreen`: start-up and blocking states (session check, workflow load, no Staff access) with the brand, one sentence, a progress bar while busy and an optional action.
- `LoadingState`: an announced label plus skeletons shaped like a list or a detail page (replaces the old spinner rows).
- `EmptyPanel`: what will appear here and, when useful, the next action.
- `PageHeader`: eyebrow, title, one sentence, optional actions.

`ErrorState` and the confirmation dialogs draw their icons (no `!` or `?` glyphs). Tabs, range chips and selected options are filled black when active; status is a coloured dot next to black text. The scanner keeps a dark camera surface with white controls. Order identifiers use the monospace face everywhere. A theme switch is deliberately absent: the owner chose the white look, and the graphite theme stays an opt-in token set.

## Department screens (Staff 2.17.0, Package 3)

Every production workspace (Pornire producție, Tăiere, Atelier, Croitorie, Control calitate, Împachetare, Livrare) opens with one *next step* chosen from the stage queue already read (`features/workspaces/stageProfiles.ts`): continue own work first; then take new work, by scanning the original label in cutting or by opening the oldest available order elsewhere (opening never claims); then blocked orders. Queue rows show what the department checks first: sizes, fabric meters and quantity for cutting, workshop, sewing and quality control; items and quantities for packing and delivery. Desktop splits work (left) from the stage numbers and checklist (right). Cutting shows own and blocked work before the available pool.

The Trendyol inbox uses the same order rows, status line and skeletons as the Trendyol home. The scanner viewfinder shows its state: a slow sweep while scanning, the frame closing in green once a code is read, one shake on a problem, with the instruction text announced. Order detail shows the products, with size and meters in large monospace figures, right after the action. Nothing in this package changes which orders are listed, which actions exist or who may take them.

## Motion (Staff 2.18.0, Package 4)

`styles/motion.css` is the motion layer. Lists settle in once with a short stagger when they first appear or a new item arrives; an automatic refresh that keeps the same rows animates nothing. A metric animates only when its value changes (the number is keyed by value). Status chips, progress bars and the production roadmap follow the server state with transitions, and the stage marker settles in when the server reports the new stage. Success screens reveal their title and actions in sequence after the server confirms. Every asynchronous primary button reports `aria-busy` and shows a running bar. The scanner adds a short vibration where supported when a code is read and when the server confirms the action. Only transform and opacity move (a test checks every keyframe), nothing starts a request, and `prefers-reduced-motion` removes durations, iterations and delays.

## UX verification (Staff 2.19.0, Package 5)

`e2e/zzzzzzzzzz-ux-verification-real-api.spec.ts` runs in the real-API Chromium job and fails on regressions: axe WCAG 2.2 AA on login, Trendyol home and inbox, profile, cutting home (phone and desktop), orders, order detail, history and scanner; every visible control at least 44 × 44 px; the skip link (`Sari la conținut`) is the first Tab stop and moves focus to the work; reduced motion leaves lists, numbers and cards still; a slow orders read shows content-shaped skeletons; a failed read shows a recoverable error instead of an empty list; package validation appears next to the invalid field, which receives focus, and nothing is written. `tests/premium-ui-accessibility.test.ts` computes WCAG contrast for every text token on every surface of the white theme (4.5:1) and for signals and focus (3:1).

Fixes from the audit: refresh, size-suggestion and revision-preview buttons raised to 44 px; the production roadmap scroller is keyboard focusable; inputs show a visible focus ring; validation sits under its field with a red icon.

Not verified here: physical iOS and Android devices, real screen readers (VoiceOver, TalkBack) and frame-rate profiling.

## Brand logos (Staff 2.20.0)

The Arasya Home wordmark (`components/brand/arasya-logo.webp|png`, trimmed, 599 × 96) replaces the letter mark on login, password change, start-up and no-access screens, in the phone header (with a `Staff` tag from 420 px) and at the top of the desktop sidebar. The Trendyol wordmark (`trendyol-logo.webp|png`, 314 × 72) appears only inside the Trendyol workspace: the connection card, the inbox header and the package header. `components/brand/BrandLogo.tsx` loads both through `new URL(…, import.meta.url)`, so they ship as hashed immutable assets kept with every retained release; every image carries its intrinsic width and height and a fixed CSS height, so the space is reserved before the file arrives and nothing moves (layout shift 0 measured at 320–1920 px). WebP first, PNG fallback; the opt-in dark theme inverts the black wordmarks. The square favicon keeps the letter mark.

## YD Soft connection status

`YDSoftConnectionStatus` accepts `connected`, `checking`, or `disconnected`. It maps each state to explicit Romanian text as well as a semantic color, so status is never color-only. Connected uses a small, reduced-motion-safe pulse. The current default is a designed `connected` placeholder; it is not derived from `navigator.onLine`, does not poll, and does not claim to be a real health check. A future health service can supply the same typed state without changing the component.

## Employee-scoped orders

“Comenzile mele” does **not** represent every order in the employee's department, every order at an allowed production stage, or the global production queue. It contains only orders for which the authenticated employee has a direct operational relationship, such as claimed, assigned, updated, received or sent through handover, or completed.

List payloads may include a compact `employeeRelation` summary with the relation type, employee UUID, and last relevant action time. The UI translates relation types into Romanian phrases and distinguishes current work from recent or handed-over involvement. Detailed audit activity remains separate in History and order detail.

Preview Mode deliberately contains unassigned and other-employee fixtures at relevant stages. `PreviewServices.listMine()` excludes them. Scanning and successfully claiming an unassigned Preview order adds a relation in memory and makes it appear in My Orders. This proves product behavior only; it is not authorization.

Order screens show exactly one primary action supplied by the server (`Preia comanda`, `Finalizează etapa` or `Finalizează producția`) behind an explicit confirmation dialog with the current and next stage. When no action is allowed, a short Romanian notice explains why (colleague owns it, stage not allocated, production finished, cancelled at source). Stale or unavailable source data shows a notice without blocking production. Buttons disable while a request is in flight; success appears only after the server confirms.

The production contract is server-authoritative:

```text
authenticated employee session
→ GET /orders/mine
→ backend derives employee_uuid
→ backend returns only directly related orders
```

The frontend must not request an arbitrary employee UUID and must never rely on client-side filtering to hide unauthorized orders.

## Responsive and accessibility principles

Phone layouts start at 320px, with explicit review across common 360–430px widths. Controls remain at least 44–48px, text contrast is suitable for bright work environments, focus indicators are visible, and icon-only Scan has an accessible Romanian label. Navigation and content account for device safe areas. Tablet spacing increases within controlled maximum widths; Scan geometry does not scale indefinitely.

Reduced-motion preferences collapse the YD Soft pulse and short interaction animations to effectively static presentation. Motion uses only brief transform and opacity changes; no JavaScript animation loop or visual dependency is introduced.
