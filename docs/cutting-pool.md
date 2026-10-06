# Cutting pool, transfers and display V1

Release: API 2.14.0 / Staff 2.5.0 / Dashboard 0.7.0, additive migration 015.
Design baseline: API 2.13.0 / Staff 2.4.0, migration 014; Dashboard 0.6.0.

## Single truth and boundaries

`operational_orders` remains the only current owner/stage truth. The pool is a query, not a
queue with priority or enforced FIFO: available, unfinished, unblocked, unowned orders at
`material-preparation`. Claim extends the existing command, using the existing opaque QR
reference. Multiple unfinished orders require explicit confirmation of the server's current
count. The owner's employee row serializes simultaneous claims of different orders; order
rows serialize competing claims. Idempotent replay precedes version/QR checks.

Transfer rows describe intent, not a second owner. State: pending → approved → accepted →
completed; pending → rejected; any nonterminal state → cancelled (Root recovery).
Only the owner requests; only the existing operations approver authority decides; only the
eligible target accepts and verifies the same QR. Owner changes only on QR verification.
One order has at most one open transfer. Pending/approved/accepted work is blocked from
normal completion and owner interventions until resolved; prior owner remains recorded.
Targets are active non-Root Staff employees in the same primary cutting department, eligible
at stage 2. Eligibility, versions and authority are re-read under the transaction lock.

Existing activity events preserve ownership intervals and completion attribution. Transfer
timestamps retain request, manager decision, target acceptance and QR confirmation separately.
Immutable cutting facts supplement (never replace) activity: pool entry, first claim, interval
start/end, item-count and active-business-second snapshots and complete whole-order meters.
The final 2→3 completer receives the entire order and meter total, not a fractional split.
Closed transfer waits are subtracted from active duration; their raw timestamps are preserved.
Fault attribution
continues to use the latest canonical 2→3 activity event.

## Display boundary

Root alone creates/re-pairs/revokes named devices and changes 15/30/60 minute thresholds.
A 10-minute, high-entropy, one-time 16-character hex code is exchanged for a separate host-only Secure
HttpOnly cookie. Only hashes are stored. Neither employee sessions nor browser storage nor
URL secrets authenticate the board. Sessions expire after 30 days. Pairing is rate-limited,
exact-Origin checked and atomic. Production uses `__Host-arasya_cutting_display` with
Secure, HttpOnly, SameSite=Lax, Path=/ and no Domain. Test loopback alone uses a non-Secure
test cookie. Retrying device creation never returns a persisted plaintext pairing code:
Root must explicitly re-pair if that one-time response was lost.
Display cookies are accepted only for sanitized board snapshot/status and `/live/events`.
They never authenticate normal Staff/Dashboard endpoints. Revocation invalidates each next
request; re-pairing rotates the old session. Pair codes and cookies are never audit metadata.

The existing bounded live outbox supplies identifier-only invalidations. Display streams
receive only a dedicated display audience, not employee/approver events. Default hold stays
zero. Clients reconnect visibly at 2.5 seconds, slowly hidden and with failure backoff; cursor
bootstrap sends no history. REST remains authoritative, commands are online-only.

## Time, source and recovery

Board uses existing Europe/Bucharest business hours, daily calendar boundaries and Root
thresholds. Closed hours never release owners or delete history. Waiting/blocked durations
are separate from active business-time duration; blocked cards never escalate worker colors.
Waiting preview is bounded (20) with a full count and explicit overflow; item aggregates are batched.
Daily totals use an activity index, not a scan of the full historical order collection. No ranking,
VIP or urgency. All active work remains available on the board, including multiple orders.
The Romanian `/cutting-board` screen bypasses employee sign-in. At 1920×1080 it shows eight
active cards; short/narrow screens show four, with 12-second automatic pagination and an
explicit page count. Product codes rotate two at a time every eight seconds without truncating
the server's full code set. No sound or optimistic mutation. Live retries converge from REST,
with an additional visible safety refresh; state keys detect schedule transitions without writes.
Stage IDs and historical snapshots remain unchanged; current labels are Tăiere / Primire Croitorie.

Source cancellation before first cutting claim removes availability without approval. After
cutting starts it records the inbound commercial fact without silently deleting ownership or
resetting production. There is no outbound integration. Root cancels stale transfer intent
with a mandatory audited reason; this does not silently reassign or delete historical facts.

## Manual rollout and rollback

No production deployment is performed by this implementation. After green release evidence,
create fresh non-empty verified DB and config/secrets backups without exposing their contents;
publish API; run only `php bin/migrate.php` (015 after already applied 014), then the existing
official seed/readiness mechanism; publish
Dashboard and Staff. Only Root then pairs wall displays and checks revoke/re-pair. Denisa and
Hikmet keep the same narrow role; no new broad grant is needed.

Do not roll back to pre-V1 code while open transfer requests or display sessions exist:
cancel open requests and revoke displays first. Migration is additive and must not be
reversed by deleting history. Analytics, priorities, tailoring board, QR revisions and all
outbound/stock/commerce mutations remain excluded.
