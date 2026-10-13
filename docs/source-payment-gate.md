# Source advance payment gate (API 2.29.0, migration 025)

Trend Home and Outlet Perdele accept cash-on-delivery orders that need an advance payment. The owner's rule: such an
order must not enter production until the advance is confirmed. WooCommerce (YD SOFT) stays the payment record;
Arasya stores only what the factory needs to decide whether production may start.

## Contract: `order.payment` (signed source schema 1, optional)

```json
"payment": {
  "method": "cod",
  "advanceStatus": "confirmed",
  "advanceRevision": 2,
  "advanceConfirmedAt": "2026-10-13T08:00:00Z"
}
```

| Field | Rule |
|---|---|
| `method` | `cod` (a cash-on-delivery gateway), `online` (a gateway the source verified, with the payment recorded as received) or `unverified` (anything else). |
| `advanceStatus` | `not_required`, `waiting`, `partial` or `confirmed`. `online` is always `not_required`; `unverified` is always `waiting`. |
| `advanceRevision` | Number of recorded confirmations (integer ≥ 0). |
| `advanceConfirmedAt` | Optional UTC time; only with `confirmed`. |

- Amounts are refused. An unknown key or value is `422 SOURCE_PAYLOAD_INVALID` and nothing is stored.
- The block is optional. A source sends it only after the signed heartbeat answered `paymentGateMode`, so an older
  API never sees an unknown key. A snapshot without the block keeps the stored facts: absence never opens a gate.
- The block travels in the normal signed order snapshot. It reuses the outbox, HMAC signing, idempotent event ids,
  the receipt ledger and the `changedAt` ordering. A replay is a `duplicate`; an older snapshot is `out_of_order`
  and never overwrites a newer confirmation. A confirmation for one source order cannot touch another order or
  another source: the global id is `<source>:<order id>` and every source signs with its own secret.
- The payment facts join the receipt hash only when present, so every earlier event keeps its hash. They are not
  part of the production ticket: a payment update never makes a document stale.

## Gate states

| Stored facts | State | Gate |
|---|---|---|
| `online` | online | open |
| `unverified` | unverified | closed |
| `cod` + `not_required` | not_required | open |
| `cod` + `confirmed` | confirmed | open |
| `cod` + `waiting` | waiting | closed |
| `cod` + `partial` | partial | closed |
| nothing stated (every order ingested before 2.29) | unknown | closed |

A WooCommerce `processing` status, an Arasya order, a QR or a document is never read as a confirmed advance.

### Non-COD payments (YD SOFT 2.10.0, `PaymentFacts`)

A non-COD gateway is not proof of payment. The source sends `online` only when both hold:

- the gateway is on the verified list (`yd_arasya_verified_online_methods`, default `netopiapayments`). Netopia moves
  an order to `processing` only from its signed IPN `confirmed` notification for the full amount; a partial or
  pending payment goes to `on-hold`, a card error to `failed`;
- WooCommerce recorded the payment (`date_paid` set) and the order is not `pending`, `on-hold`, `failed`,
  `checkout-draft`, `cancelled`, `refunded` or `trash`.
- the gateway's own evidence exists. For Netopia this is the private note its IPN `confirmed` handler writes
  ("Plata prin NETOPIA payments / Transaction ID: <id>"); Netopia stores no transaction id. A payment date alone is
  not proof: Trend Home 63438 is `processing` with a payment date but without that note, so it is `unverified`.

Everything else is `unverified` and closed: bank transfer (`bacs`, until an authorised accounting verification with a
provable collection exists), cheque, TBI financing (`tbi_bank`, `tbiro`, `tbiirisro`: the Romanian TBI plugin only
records the credit application decision `_tbi_checkout_status` 0 Canceled / 1 Approved / 2 Draft / 3 Rejected, with
WooCommerce status sync disabled for Romania; "Approved" is not a proven payment), an empty or unknown gateway,
and a Netopia payment that is pending, on hold, failed or without IPN evidence. A gateway joins the verified list
only by an explicit business decision backed by evidence (filter), never by default. Live evidence (2026-10-13):
every order Arasya received is `cod` or `netopiapayments`; `bacs` and `cheque` are disabled on both sites; no TBI
order has reached `processing`.

## Modes: `ARASYA_SOURCE_PAYMENT_GATE_<SOURCE>`

| Mode | Effect |
|---|---|
| `off` (default, and the fallback for any unreadable value) | Facts are stored when sent; nothing reads them. |
| `observe` | Staff, the order views and the source's document state show the gate; nothing is refused. |
| `enforce` | A closed gate refuses every production start for the source's orders at stage 1. |

B2B and Trendyol are never gated (they have their own entry flows).

## Enforced entry points (all server-side, on the locked order row)

| Path | Check |
|---|---|
| Signed source print that would create revision 1 (`orders/document`) | `DocumentService::createInitial` |
| Staff/Dashboard document generation of revision 1 | `DocumentService::createInitial` |
| Cutting QR intake (stage 1 → cutting) | `OrderAccessPolicy::evaluateCuttingIntake` |
| Stage-1 claim and stage-1 completion | `OrderAccessPolicy::evaluate` |
| Manager/root assignment of a stage-1 order | `OrderOwnershipService` |

Every check reads the payment columns of the row locked `FOR UPDATE` inside the action's own transaction, so a
stale screen or a concurrent update can never open the gate. The refusal is
`409 AVANS_NEVALIDAT` with `details.paymentGate` (`waiting`, `partial` or `unknown`) and a Romanian message, for
example "Comanda nu poate intra în producție. Avansul necesar nu a fost confirmat.". The source's document state
answers `issueAllowed: false` and `paymentGate`.

Reprints of an existing revision, revisions after the first (approved workflow) and every stage after stage 1 are
not gated: the gate decides when production may start, it never stops work already in progress.

## Reversal after production started

When a newer snapshot closes the gate of an order that already left stage 1, Arasya keeps the stage, the owner, the
document and all work, and sets `payment_reversed_at`. Staff shows "Avansul nu mai este confirmat după începerea
producției. Lucrul continuă; anunță managerul." A renewed confirmation clears the flag. A gate that is closed
before production starts simply keeps the order at stage 1.

## Factory view

The order view carries `paymentGate: {state, label, mode, blocking, reversedAt}` only while the source's gate is
`observe` or `enforce`. It never carries an amount, a percentage, a confirmation list or the payer.

## Rollout (per source, never both sites at once)

1. Back up the database; deploy API 2.29.0 and run `bin/migrate.php` (025 only: nullable columns and CHECKs).
   Every gate is `off`; nothing changes for production.
2. Deploy Staff 2.24.0 (knows `payment_gate_closed` and `AVANS_NEVALIDAT`; Staff 2.23 fails closed on the new
   reason, so it must not run against an enforced gate).
3. Update YD SOFT on one site. It snapshots the advance requirement of every processing COD order and starts
   sending `order.payment`; its reconciliation pass resends open orders.
4. Set that source to `observe`; review the backlog report and record the advances already received in YD SOFT.
5. Set the source to `enforce` after the owner's approval; pilot one order end to end; then the second site.

## Rollback

- Set the source's mode back to `off`: immediate, no data change.
- Code rollback to 2.28.0 is schema-compatible (025 only adds nullable columns). 025 is not reversed.
