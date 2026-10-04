# Arasya Staff — Operational UI System

## Design philosophy

Arasya Staff is a scan-first operational instrument, not a dashboard. The interface favors immediate recognition, comfortable touch targets, clear production context, and restrained feedback. Decorative surfaces, glow, gradients, and explanatory copy are minimized so the employee's next action remains obvious during repeated phone use.

The visual hierarchy is: scan, understand the order, perform the permitted action, confirm, and continue. Desktop and tablet retain the same mobile application identity rather than becoming an administration interface.

## Brand and color semantics

The official Arasya logo is reserved for quiet brand identification, most visibly on Login and at a much smaller scale inside the authenticated experience. It must retain its original proportions and artwork and must not be used as utility iconography.

The palette uses deep graphite backgrounds, neutral raised surfaces, warm off-white text, and muted coral for the primary product action. Coral identifies Scan, active selection, and primary CTAs. Green is semantic and is reserved for connected, confirmed, completed, and success states. Amber communicates checking or warning. A stronger red communicates destructive, disconnected, and error states. These roles are centralized in CSS design tokens.

## Navigation and Scan

Mobile navigation contains Acasă, Comenzi, a true center Scan action, Istoric, and Profil. Scan is a circular 66px control positioned at the exact horizontal center of the bar and elevated slightly for thumb access. Secondary items use quieter icons and labels. The bar respects safe-area insets and remains width-constrained on tablets and desktop.

The Home Scan surface is entirely interactive. It uses scale, position, and the warm accent—not animation or glow—to establish priority. The camera, decoder selection, duplicate guard, cleanup, confirmation, idempotency, expected-version, and server-confirmed success behavior are unchanged.

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
