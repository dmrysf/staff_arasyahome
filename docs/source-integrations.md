# Source integrations (V2.2.0)

All external commerce data enters Arasya Operations first. Staff never contacts Trendhome, OutletPerdele, Trendyol or any marketplace; it only reads the normalized projection. Every source path ends in the same `SourceOrderSnapshot` → `OrderProjectionWriter` pipeline.

## Inbound-only rule

Operations source connectors are inbound-only unless an explicit, separately reviewed outbound integration is implemented. Arasya production transitions do not mutate WooCommerce order statuses, and `operations-api/tests/inbound-only-guard.php` enforces this in CI. See [production-control.md](production-control.md).

## Projection rules

- Each event is identified by `(source, eventId)` and a deterministic SHA-256 hash of its normalized content (items sorted by line, numbers normalized). A replayed event returns `duplicate`; the same event ID with different content returns `SOURCE_EVENT_CONFLICT`.
- `changedAt` orders events. An older event is recorded as `out_of_order` and never changes the order. A different payload at the same timestamp returns `SOURCE_REVISION_CONFLICT`. A newer identical payload only refreshes observation time (`duplicate`, version unchanged).
- Commerce status (`processing`, `completed`, `Picking`, …) is stored as commerce data only and never moves production.
- A source may send an explicit production stage only as `{ "workflowKey": "curtain-production", "workflowVersion": 1, "stageId": "…" }`. The stage must be an active canonical stage; otherwise the whole event is rejected with `SOURCE_STAGE_UNKNOWN` and the last valid state is kept. Labels are diagnostic only. There is no translation, fuzzy, case-insensitive or AI matching.
- An explicit source stage can only move an order forward, and only until the first Operations action. After an employee claims the order, production belongs to Operations and source stages are ignored. Orders without an explicit stage start at `waiting`.
- Every projected order receives an opaque QR reference (see [staff-operations-api.md](staff-operations-api.md)).

## Freshness

Each successful source contact (event or signed heartbeat) updates `order_sources.last_contact_at`. Orders report freshness from that timestamp: `fresh` up to `ARASYA_SOURCE_FRESH_SECONDS` (default 900), `stale` up to `ARASYA_SOURCE_UNAVAILABLE_SECONDS` (default 3600), then `source_unavailable` (also when the source is inactive). The next contact restores `fresh` deterministically. Freshness is informational: Staff shows a notice, and production work continues because Operations owns the production stage.

## Trendhome and OutletPerdele (WooCommerce push)

Endpoint: `POST https://api.arasyahome.ro/integrations/sources/{trendhome|outletperdele}/orders`, `/orders/validate` and `/heartbeat`. Since Operations API 2.17.0 every source has a mode: `/orders` only accepts sources in `active` mode (others get `403 SOURCE_NOT_ACTIVE`), and `/orders/validate` checks a payload without writing anything. See [YD SOFT source integration](yd-soft-source-integration.md) for the source registry, modes and provisioning.

Authentication: `X-Arasya-Timestamp: <unix seconds>` and `X-Arasya-Signature: v1=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>`. Requests outside ±300 seconds are rejected; replays inside the window are neutralized by idempotent receipts. Each site has its own secret (`ARASYA_SOURCE_SECRET_TRENDHOME`, `ARASYA_SOURCE_SECRET_OUTLETPERDELE`, at least 32 random bytes) and its own mode (`ARASYA_SOURCE_MODE_<KEY>`, default `validation`) stored only in the Operations private configuration and in that site's `wp-config.php`. These routes need no cookie, CORS or CSRF and are limited to 1200 requests per minute per source.

Payload (schema version 1; unknown fields are rejected):

```json
{
  "schemaVersion": 1,
  "eventId": "wc-61833-20261004100000123456",
  "changedAt": "2026-10-04T10:00:00.123456Z",
  "order": {
    "id": 61833,
    "number": "61833",
    "status": { "code": "processing", "label": "Procesare" },
    "availability": "active",
    "notes": "Tiv dublu",
    "acceptedAt": "2026-10-04T09:58:00Z",
    "items": [
      { "id": 981, "line": 1, "name": "Draperie Velvet", "sku": "DV-302", "variant": "Bej", "color": "Bej",
        "width": 300, "height": 260, "unit": "cm", "meters": 8.4, "quantity": 1 }
    ]
  }
}
```

`availability` is `active` or `cancelled` (cancelled orders cannot be claimed). The response is `{ "outcome": "applied|duplicate|out_of_order", "globalOrderId": "trendhome:61833", "qr": "ARASYA:Q1:…" }`, so the site can print the QR on its work order.

### Installing the connector

`integrations/woocommerce/arasya-operations-connector.php` is a single-file plugin (not part of the API release). Install it as a must-use plugin on each WooCommerce site and add to `wp-config.php`:

```php
define('ARASYA_OPERATIONS_URL', 'https://api.arasyahome.ro');
define('ARASYA_OPERATIONS_SOURCE', 'trendhome'); // or 'outletperdele'
define('ARASYA_OPERATIONS_SECRET', '<the same secret as ARASYA_SOURCE_SECRET_TRENDHOME>');
```

It sends every order status except unpaid drafts (`pending`, `checkout-draft`, `failed`; adjustable with `arasya_operations_skip_statuses`), including custom statuses such as Trendhome's `se-proceseaza`, so Operations always holds the current commerce status. It queues a send through Action Scheduler on order create/update/status change (deduplicated while one send is pending), builds the payload at send time so retries carry the newest state, signs it, retries transient failures with exponential backoff (up to 12 attempts) and sends a signed heartbeat every five minutes through WP-Cron. It sends no customer name, address, phone, e-mail or customer note. Curtain measurements come from line-item meta `_arasya_width`, `_arasya_height`, `_arasya_unit` (`mm|cm|m`) and `_arasya_meters`, or from the `arasya_operations_item_measurements` filter; production instructions come only from order meta `_arasya_production_notes` or the `arasya_operations_production_notes` filter. Map each site's real product-option fields with these filters before going live.

Sites that use the WC Kalkulator curtain calculator also install `integrations/woocommerce/arasya-operations-wc-kalkulator.php` as a second must-use plugin. It reads the existing item meta without changing it: `_wck_fields.lungimea` / `inaltime` become width / height in metres (`unit: m`), `_wck_stock_reduction_multiplier × quantity` becomes fabric meters (the same quantity WooCommerce deducts from stock), and each line's `manopera` and `buc` labels are appended to the production notes as `Linia N (SKU): …`. Explicit `_arasya_*` meta always wins. A site with different field names overrides `arasya_operations_wck_field_map`. The field names were verified on live Trendhome orders (WC Kalkulator 1.6.1).

**Status:** implementation and contract tests are complete (the connector payload is validated against the API mapper and signature verifier in `php operations-api/tests/run.php`). Live delivery from the Trendhome and OutletPerdele sites has **not** been verified; it requires installing the connector, configuring both secrets, mapping measurement fields, and observing `applied` outcomes and `OK source_contact_*` in `php bin/readiness.php`.

## Trendyol (Seller API pull)

`bin/sync-trendyol.php` pulls shipment packages from `GET {base}/integration/order/sellers/{sellerId}/orders` (Basic authentication, `User-Agent: <sellerId> - SelfIntegration`), ordered by last modification, from a persisted cursor with a ten-minute overlap (first run: 14 days). Each package becomes one order `trendyol:<shipmentPackageId>` with event ID `package-<id>-<lastModifiedDate>`; `Cancelled`/`UnSupplied` packages are unavailable; the package status is commerce data only and production starts at `waiting`. A successful run records a source heartbeat. An advisory lock prevents overlapping runs; it never runs from a web request.

Configuration (all three or none; partial configuration fails closed): `ARASYA_TRENDYOL_SELLER_ID`, `ARASYA_TRENDYOL_API_KEY`, `ARASYA_TRENDYOL_API_SECRET`, optional `ARASYA_TRENDYOL_API_BASE_URL` (HTTPS, default `https://apigw.trendyol.com`). Without credentials the command prints `TRENDYOL_NOT_CONFIGURED` and exits successfully. Suggested cron:

```cron
*/5 * * * * cd "$HOME/arasya-operations-api/releases/$(cat "$HOME/arasya-operations-api/active-release")" && /usr/local/bin/php bin/sync-trendyol.php >> "$HOME/arasya-trendyol-sync.log" 2>&1
```

**Status:** the adapter, mapper, client, cursor and configuration are implemented and contract-tested with realistic fixtures (`operations-api/tests/fixtures/trendyol-packages.json`). Live Trendyol synchronization has **not** been verified because no Trendyol credentials were available; enabling it requires only the three configuration values and the cron entry.

## Future sources (B2B, other marketplaces)

Add a row to `order_sources`, a signing secret (push) or an adapter that maps to `SourceOrderSnapshot` (pull). The projection, freshness, QR and Staff rules apply unchanged.
