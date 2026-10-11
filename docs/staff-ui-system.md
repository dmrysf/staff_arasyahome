# Arasya Staff — Operational UI System

## Design philosophy

Arasya Staff is a scan-first operational instrument, not a dashboard. The interface favors immediate recognition, comfortable touch targets, clear production context, and restrained feedback. Decorative surfaces, glow, gradients, and explanatory copy are minimized so the employee's next action remains obvious during repeated phone use.

The visual hierarchy is: scan, understand the order, perform the permitted action, confirm, and continue. Desktop and tablet retain the same mobile application identity rather than becoming an administration interface.

## Brand and color semantics

The official Arasya logo is reserved for quiet brand identification, most visibly on Login and at a much smaller scale inside the authenticated experience. It must retain its original proportions and artwork and must not be used as utility iconography.

The palette uses deep graphite backgrounds, neutral raised surfaces, warm off-white text, and muted coral for the primary product action. Coral identifies Scan, active selection, and primary CTAs. Green is semantic and is reserved for connected, confirmed, completed, and success states. Amber communicates checking or warning. A stronger red communicates destructive, disconnected, and error states. These roles are centralized in CSS design tokens.

## Navigation and Scan

Phones use a docked bottom bar (Acasă, Comenzi, Scan, Istoric, Profil) that sits on the safe-area inset instead of floating over content; the active item carries an accent marker and an accent icon in addition to the label. Scan is a 64px rounded-square control raised from the center of the bar, rendered only with `orders.scan`; an empty slot keeps it centered when a destination is not authorized. Landscape phones use a shorter bar.

From 1024px the same navigation element becomes a sidebar: brand, a labelled `Scanează QR` action, the destinations, and Profil pinned to the bottom. Content then uses the screen width up to 1320px instead of the former 880px column.

The camera, decoder selection, duplicate guard, cleanup, confirmation, idempotency, expected-version, and server-confirmed success behavior are unchanged.

## Design system (Staff 2.15.0, Package 1)

`styles/tokens.css` is the only place for colors, type scale, spacing, radii, control heights, motion durations and easings, layers and safe areas. Two themes share the vocabulary: **Industrial Premium Dark** (default) and **Clean Operations Light** (`<html data-theme="light">`, opt-in until every screen has been reviewed in it). `styles/foundation.css` holds primitives (buttons, fields, status pills, notices, skeletons, task steps) and the app shell; `styles/experiences.css` holds the reference experiences. Neither file contains a hex color (a unit test enforces it).

- Typography uses system faces (no font download) with tabular figures for numbers and a monospace face for order identifiers and measurements. Inputs use 16px text so iOS never zooms.
- Status is always words plus an icon; tone only reinforces it.
- Motion uses transform and opacity only (press 110ms, hover 150ms, screen 240ms, success 380ms, stage 450ms) and collapses under `prefers-reduced-motion`. No animation triggers a request.

Reference experiences:

- **Home**: a compact greeting, the workspace switcher as scrollable chips on phones, and for Trendyol operators one *next step* card chosen from data already loaded (ready for approval, then measurements to complete, then attention). It links to the existing screens and never acts. Desktop splits work (left) and status (right).
- **Trendyol preparation**: five guided steps (`Verifică produsele`, `Completează măsurile`, `Aprobă producția`, `Tipărește fișa cu QR`, `Trimite la Tăiere`) derived only from the package the server returned. A step is done only when server data proves it; printing is never shown as done. Each line shows confirmed, unsaved or to-complete state, a radio product-type picker, the Trendyol size suggestion marked as unconfirmed, paired width × height inputs, and validation next to the field. The form never refreshes itself.
- **Production document**: revision, status, a print card stating that the button downloads the server PDF with the production QR, a separate preview without QR, and the revision history. Counts say *PDF descărcat pentru tipărire*, never *tipărit*: the server cannot know that paper came out of a printer.

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
