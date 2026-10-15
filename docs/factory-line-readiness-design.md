# Approved factory routing and organization rehearsal

Business authorization: PR #64 follow-up, 2026-10-13. This design is recorded before implementation.

## Minimum business-model extension

The canonical curtain-production@1 stage identifiers and authority engine remain unchanged. Add explicit source line `manufacturingType`: `custom_curtain`, `custom_drapery`, or `ready_made`. Custom fabrics require positive width and height and a validated mm/cm/m unit. A ready-made line needs no cutting or tailoring measurements. Trendyol's independently approved curtain/drapery intake kind and confirmed dimensions supply the same explicit classification; product names never classify anything.

Add quantity-aware, per-source-line stage checks, bound to the line's content fingerprint, with immutable events. The current stage owner confirms each applicable line; the ordinary stage transition refuses unfinished quantities. Standard-only orders move from authorized receipt directly to packing, inside the existing server-selected transition, with an explicit applicability audit. Mixed orders retain all manufacturing stages and cannot enter packing until every fabric line has all applicable stage checks. Every line, including accessories, must be physically checked by the packing owner. Delivery requires packing checks. No client-selected next stage, controller approval, alternate stage graph or unrestricted backward jump is introduced.

This necessarily changes the meaning of stage completion for explicitly classified orders: it follows line confirmations. Existing deployments have no verified product classification metadata. `ARASYA_LINE_ROUTING=legacy|enforce` is an explicit rollout switch, default legacy for compatibility; isolated simulation enables enforce. In enforce, unknown classification fails closed. Orders with the new source-explicit manufacturingType use line checks even in legacy. Existing Trendyol kind metadata alone does not opt a legacy deployment in; enforce mode activates its approved-kind mapping. Legacy unclassified orders retain the earlier engine and are NOT acceptance evidence for the approved rules. Live rollout requires explicit source metadata mapping and backlog reconciliation, separately from payment-gate review.

Current readiness is invalidated when the same source line's manufacturing content changes, while historic confirmations remain available. A controlled return to supervisor custody invalidates checks at the detected stage and later (earlier valid tailoring checks remain available for restoration); approved return to cutting invalidates cutting and later checks, in the existing quality transaction. Restoring to the detected stage requires re-confirmation rather than manufacturing a successful item state.

## Existing organizational authority

Use synthetic identities through existing IAM. Layer the committed owner-approved 29-person factory stage/app grants on the 46-person organization plan, solely in simulation. The old organization plan predates the factory rollout and alone would leave those production profiles inactive; the factory layer is explicit, never inferred from a department or employee name. Workers retain stage/source grants, application access, locale and managerial relationships. Root and designated CEO remain separate. Operations manager, appointed operations backup, appointed document backup, tailoring intake responsible and appointed tailoring quality supervisor are distinct authority profiles. Department/stage management and role permissions are reproduced from reference policy; employee names and live credentials are not copied.

Stage/department membership does not grant tailoring-supervisor authority. Appointment windows/revocation are checked by existing policy. Supervisor custody is separate from the manager's 3→2 decision. Reporter, custodian, receiver and responsible cutter cannot decide their own case. Dashboard decisions use the existing management API and actual Dashboard client; any unavailable UI integration is reported explicitly, never replaced by simulation-controller power.

## Verification scope

Real Staff authentication and actual kernel/service transactions for normal, defect/rejection/restoration/rework, source scope, expiry/revocation, old QR, stale documents, idempotency and concurrent manager actions. Browser tests use separate user contexts. Test infrastructure and physical worker acceptance remain separate from automated results. No live IAM/order/DB/integration changes, merge or deployment are authorized.
