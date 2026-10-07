# YD SOFT source integration — Milestone 1: signed source foundation

Release: Operations API 2.17.0. There is no migration, and there are no Staff, Dashboard or B2B changes.

This milestone prepares Arasya Operations to receive orders from YD SOFT-powered WooCommerce sites (first Trendhome and OutletPerdele) through a controlled cutover. It adds a config-backed source registry, a mode for each source (`validation` or `active`), a signed validation endpoint that writes nothing, and a mode guard on real ingestion. The YD SOFT client (WordPress side, outbox, retries, QR replacement) belongs to Milestone 2 and later.

## Source model

- Each website is a separate **source** with a unique `sourceKey` that matches `^[a-z0-9_-]{1,40}$`, for example `trendhome` or `outletperdele`.
- Each source has its **own HMAC secret**. Never share one secret between two sites. A site is trusted only because of its source key plus that key's secret. Arasya never trusts a site because of its hostname.
- Each source has its own enabled flag, mode, rate-limit identity (`source-ingestion/<sourceKey>`, 1200 requests per minute, shared by all of that source's routes) and heartbeat state (`order_sources.last_contact_at`).
- The keys `b2b` (internal B2B handoff) and `trendyol` (pull-based marketplace) are reserved. They can never be configured as signed sources.

The registry (`Arasya\Operations\Integration\SourceRegistry`) is built from the private configuration at runtime. It holds only safe metadata: `sourceKey`, `displayName`, `integrationType` (currently only `yd-soft-woocommerce`), `enabled` and `mode`. The secrets stay inside `SourceSignatureVerifier`. A source with any configuration problem is left out of the registry, so it fails closed, and readiness reports the problem.

### Modes

| Mode | Heartbeat | `/orders/validate` | `/orders` (real ingestion) |
|---|---|---|---|
| `validation` (default) | yes | yes, writes nothing | `403 SOURCE_NOT_ACTIVE` |
| `active` | yes | yes, writes nothing | yes, through `OrderProjectionWriter` |
| disabled (`enabled=false`) | `503 SOURCE_NOT_CONFIGURED` | `503 SOURCE_NOT_CONFIGURED` | `503 SOURCE_NOT_CONFIGURED` |
| unknown, reserved or misconfigured | `503 SOURCE_NOT_CONFIGURED` | `503 SOURCE_NOT_CONFIGURED` | `503 SOURCE_NOT_CONFIGURED` |

Disabled and unknown sources get the same answer. The API checks this before it verifies the signature or parses the body, so these requests never reach storage or the rate limiter. The `SOURCE_NOT_ACTIVE` check runs only after a valid signature, so only the real site can learn its own mode.

Real ingestion also still requires an `active` row in `order_sources`. The writer checks this unchanged (`SOURCE_UNKNOWN` / `SOURCE_INACTIVE`).

## Signing (unchanged)

```
X-Arasya-Timestamp: <unix seconds>
X-Arasya-Signature: v1=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>
```

- The allowed timestamp skew is ±300 seconds. The API compares signatures in constant time with `hash_equals()`.
- The request body is limited to 256 KiB.
- The routes use no cookies, no CORS, no CSRF, no JWT and no browser tokens. They are server-to-server only.
- A signature made with one source's secret fails under every other source key (`401 SOURCE_SIGNATURE_INVALID`).

## Endpoints

All endpoints use `POST https://api.arasyahome.ro/integrations/sources/{sourceKey}/…`.

### `…/orders/validate` (no side effects)

The body is the same schemaVersion 1 order payload as real ingestion. The API parses it with the same `SourceOrderPayloadMapper`. There is no second parser, so the strict unknown-key rejection, the 200-item maximum, the strict `options` label/value pairs and the email rejection all apply. Explicit stages are checked against the canonical 14 stage IDs. On success the response is:

```json
{
  "ok": true,
  "schemaVersion": 1,
  "sourceKey": "trendhome",
  "mode": "validation",
  "workflow": { "id": "curtain-production", "version": 1, "stageCount": 14 },
  "itemCount": 1
}
```

The response never echoes payload values (customer, phone, address, notes, SKUs, product names, order number, order ID, event ID or manufacturing values). Errors name the field, never its value.

**Zero-write guarantee.** Validation never calls `OrderProjectionWriter` and never uses the database. It creates no order, item, projection receipt, QR reference, document revision, analytics fact, ownership, approval or heartbeat. The only state it touches is the per-source rate-limit counter, which real ingestion shares. Because validation stores no receipt, a payload validated today is still accepted later by `/orders` with the **same `eventId`**. Tests prove this: `tests/SourceConnectionUnitTests.php` uses a PDO that records every statement, and `tests/mysql-source-connection-integration.php` compares checksums of every table before and after.

Both `validation` and `active` sources may call this endpoint.

### `…/orders` (real ingestion, active sources only)

The behavior is unchanged for active sources. `OrderProjectionWriter` stays authoritative: there is one idempotent receipt per `(sourceKey, eventId)`, a replay returns `duplicate`, a lost response that is retried returns the same `globalOrderId` and QR, older events return `out_of_order`, and cancellation sets `availability: "cancelled"` and makes the order unavailable. The response is still `{ "outcome", "globalOrderId", "qr" }`. Any other source mode gets `403 SOURCE_NOT_ACTIVE` before the body is parsed.

### `…/heartbeat`

The body is `{ "sentAt": "<ISO-8601>" }`. Validation and active sources may send it. A heartbeat still updates `order_sources.last_contact_at` (freshness), so the source needs its `order_sources` row. The response is:

```json
{
  "ok": true,
  "sourceKey": "trendhome",
  "mode": "validation",
  "contract": { "schemaVersion": 1, "workflowId": "curtain-production", "workflowVersion": 1, "stageCount": 14 }
}
```

The response never contains secrets, configuration values, paths or database details.

## Payload contract (schemaVersion 1, unchanged)

- Root: `schemaVersion`, `eventId`, `changedAt`, `order`, optional `production`.
- Order: `id`, `number`, `status`, `availability`, `notes`, `acceptedAt`, `items`, `delivery`.
- Item: `id`, `line`, `name`, `sku`, `variant`, `color`, `width`, `height`, `unit`, `meters`, `quantity`, `options`.
- See [source-integrations.md](source-integrations.md) and [production-documents.md](production-documents.md) for field rules.
- **Email is not part of the contract.** An `email` inside `order.delivery` is rejected. The phone is masked at ingestion (`DeliveryContext`).
- `options` are stored exactly as the source states them (`{label, value}`). Operations does not interpret `1 buc.`, `2 buc.`, `Manopera` or similar values, and does not invent production rules from them. YD SOFT will send exact manufacturing information in a later milestone.

## Canonical production workflow (unchanged)

`curtain-production` version 1 keeps exactly 14 stages:

1. `waiting` (În așteptare)
2. `material-preparation` (Tăiere)
3. `workshop-receiving` (Primire Croitorie)
4. `labeling` (Etichetare)
5. `material-straightening` (Îndreptare material)
6. `bottom-hem` (Tivul de jos)
7. `side-hem` (Tivul lateral)
8. `ironing` (Călcare)
9. `height` (Înălțime)
10. `header-tape` (Rejansă)
11. `sewing-finishing` (Finisare coasere)
12. `quality-control` (Control calitate)
13. `packing` (Împachetare)
14. `delivery` (Livrare)

Only an explicit canonical stage ID is accepted (`production.workflowKey = "curtain-production"`, `workflowVersion = 1`, `stageId`). Operations never derives a stage from the WooCommerce status, a Romanian label or a YD SOFT 8-stage label. **There is no automatic 8→14 mapping.** A new YD SOFT order omits `production` (and starts at `waiting`) or sends `stageId: "waiting"`.

## Configuration

Source settings use the existing private configuration (`$HOME/arasya-config/secrets.json`, the legacy PHP file or environment variables). The API reads them per declared source key. In each per-source key, the source key is written in upper case and `-` becomes `_`.

| Key | Meaning |
|---|---|
| `ARASYA_SOURCE_KEYS` | Comma-separated declared sources. The default is `trendhome,outletperdele`. |
| `ARASYA_SOURCE_SECRET_<KEY>` | That site's own HMAC secret. It must be at least 32 bytes, and placeholders are rejected. |
| `ARASYA_SOURCE_MODE_<KEY>` | `validation` (default) or `active`. |
| `ARASYA_SOURCE_ENABLED_<KEY>` | `true` (default) or `false`. |
| `ARASYA_SOURCE_NAME_<KEY>` | Optional display name. The defaults are `Trendhome` and `OutletPerdele`; other sources use the key. |
| `ARASYA_SOURCE_TYPE_<KEY>` | Optional. Only `yd-soft-woocommerce` is accepted. |

**Compatibility note:** before 2.17.0, a configured secret made a source fully active. From 2.17.0, a source without `ARASYA_SOURCE_MODE_<KEY>=active` is in `validation` mode, so real ingestion must be switched on explicitly. Any environment that must keep ingesting must set the mode to `active` before or together with the upgrade. This includes the Dashboard real-API E2E configuration, which sets only the secrets and is still pinned to API 2.16.0.

Never commit a secret. Never put one in a migration, fixture, log or response. Generate each secret separately, for example with `openssl rand -hex 32`.

### Readiness

`php bin/readiness.php` reports:

- `OK source_signing_<key>` and `OK source_mode_<key>_<validation|active|disabled>` for each usable source;
- `WARN source_config_<issue>[_<key>]` for each configuration problem: `missing_secret`, `invalid_mode`, `invalid_enabled`, `invalid_type`, `invalid_display_name`, `duplicate_key`, `environment_collision` (for example `a-b` and `a_b`), `reserved_key` or `invalid_key` (the raw value is never printed);
- `WARN source_missing_in_database_<key>` or `WARN source_inactive_in_database_<key>` when an active source cannot ingest because its `order_sources` row is missing or inactive;
- `OK|WARN source_contact_<key>` for heartbeat freshness (unchanged).

## Initial provisioning: Trendhome and OutletPerdele

Both `order_sources` rows already exist (seed `003_order_sources.sql`). Keep both sources in `validation` mode until the planned cutover:

```json
"ARASYA_SOURCE_KEYS": "trendhome,outletperdele",
"ARASYA_SOURCE_SECRET_TRENDHOME": "<64 random hex characters, Trendhome only>",
"ARASYA_SOURCE_MODE_TRENDHOME": "validation",
"ARASYA_SOURCE_SECRET_OUTLETPERDELE": "<64 random hex characters, OutletPerdele only>",
"ARASYA_SOURCE_MODE_OUTLETPERDELE": "validation"
```

Cutover for one site, after YD SOFT has validated its real payloads:

1. Set `ARASYA_SOURCE_MODE_<KEY>` to `active`.
2. Run readiness.
3. Let YD SOFT send through `/orders`.

To roll back, set the mode back to `validation`, or set `ARASYA_SOURCE_ENABLED_<KEY>=false`.

## Adding a future site (until the pairing milestone exists)

1. Choose a key, for example `perdele-noi`. Generate a new secret.
2. Add the key to `ARASYA_SOURCE_KEYS`. Set `ARASYA_SOURCE_SECRET_PERDELE_NOI`, plus optionally `ARASYA_SOURCE_NAME_PERDELE_NOI`, while the mode is still `validation`.
3. Before the site goes `active`, add its `order_sources` row. This is an operator SQL step, not a code change: `INSERT INTO order_sources (source_key, source_type, display_name, schema_version, status, created_at, updated_at) VALUES ('perdele-noi', 'woocommerce', 'Perdele Noi', 1, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));`. Heartbeats also need this row.
4. Validate the site's payloads, then switch it to `active`.

No integration code is written for a new site.

## Deferred on purpose

- Root approval, pairing and self-service secret issuance. The protocol (source key plus per-source secret) stays the same when these are added.
- The YD SOFT WordPress client, outbox, retries and credentials (Milestone 2).
- Source-scoped QR/document endpoints. The QR/document engine is unchanged.
- Any outbound WooCommerce write. Sources stay inbound-only (`tests/inbound-only-guard.php`).
