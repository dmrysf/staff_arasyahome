<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Integration\Trendyol\TrendyolCancellationApi;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolEligibility;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use RuntimeException;

/**
 * Trendyol Romania. Verified against Trendyol's documentation (Cancel Package / Tedarik Edememe Bildirimi): a seller can
 * cancel a package only as an "unsupplied" notice with one of the reasons 500-506, and the package then becomes
 * Cancelled. The documentation does not state the response codes, the allowed statuses or idempotency, so:
 *
 *   - readStatus() always reads the package again first (Order V2 `shipmentPackageIds`, the same read the intake uses);
 *     Cancelled / UnSupplied is the only confirmation;
 *   - submit() is called only for a package in a new-order status (Created, Picking, Invoiced) and names every current
 *     line with its full quantity, so the package is never split;
 *   - an accepted answer means "awaiting confirmation"; a lost answer or a 5xx is uncertain and is never repeated before
 *     another status read;
 *   - 401/403 is a permanent authorization failure; 429 is retried later; other 4xx are refusals.
 *
 * Refunds are Trendyol's own process after its cancellation; Arasya triggers none.
 */
final class TrendyolCancellationProvider implements CancellationProvider
{
    /** @var array<string, list<array{lineId: int, quantity: int}>> current lines from the last read, per operation */
    private array $lines = [];

    public function __construct(private ?TrendyolClient $client, private ?TrendyolCancellationApi $api)
    {
    }

    public function key(): string
    {
        return 'trendyol';
    }

    public function mechanism(): string
    {
        return 'api';
    }

    public function configured(): bool
    {
        return $this->client !== null && $this->api !== null;
    }

    public function capability(): array
    {
        return [
            'inbound' => 'marketplace_sync',
            'outbound' => 'api',
            'endpoint' => 'PUT /integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied',
            'confirmation' => 'status_read',
            'retry' => 'bounded_backoff_after_status_read',
            'reconciliation' => 'package_status_read',
            'reasons' => TrendyolCancellationApi::REASONS,
            'unsupported' => ['refund', 'customer_cancellation_reason', 'shipped_package_cancellation'],
            'configured' => $this->configured(),
        ];
    }

    public function readStatus(array $operation): ProviderResult
    {
        if ($this->client === null) {
            return new ProviderResult('not_configured', null, 'TRENDYOL_NOT_CONFIGURED');
        }
        $packageId = (int) $operation['external_order_ref'];
        try {
            $packages = $this->client->packagesByIds([$packageId]);
        } catch (RuntimeException $error) {
            return match ($error->getMessage()) {
                'TRENDYOL_AUTH_FAILED' => new ProviderResult('auth_failed', null, 'TRENDYOL_AUTH_FAILED'),
                default => new ProviderResult('transient', null, self::code($error->getMessage(), 'TRENDYOL_UNAVAILABLE')),
            };
        }
        if (!isset($packages[$packageId])) {
            return new ProviderResult('rejected', null, 'TRENDYOL_PACKAGE_NOT_FOUND');
        }
        try {
            $package = TrendyolPackage::fromApi($packages[$packageId]);
        } catch (\InvalidArgumentException) {
            return new ProviderResult('transient', null, 'TRENDYOL_MALFORMED_RESPONSE');
        }
        $class = TrendyolEligibility::statusClass($package->status);
        if ($class === TrendyolEligibility::CLASS_CANCELLED) {
            return new ProviderResult('cancelled', null, null, $package->status);
        }
        if ($class !== TrendyolEligibility::CLASS_NEW) {
            return new ProviderResult('not_cancellable', null, 'TRENDYOL_STATUS_NOT_CANCELLABLE', $package->status);
        }
        $this->lines[(string) $operation['operation_uuid']] = array_map(static fn (array $line): array => ['lineId' => (int) $line['lineId'], 'quantity' => (int) $line['quantity']], $package->lines);
        return new ProviderResult('open', null, null, $package->status);
    }

    public function submit(array $operation): ProviderResult
    {
        $lines = $this->lines[(string) $operation['operation_uuid']] ?? null;
        if ($this->api === null) {
            return new ProviderResult('not_configured', null, 'TRENDYOL_NOT_CONFIGURED');
        }
        if ($lines === null || $lines === []) {
            throw new \LogicException('A Trendyol cancellation is submitted only right after a status read found the package open.');
        }
        try {
            $answer = $this->api->reportUnsupplied((int) $operation['external_order_ref'], $lines, (int) $operation['reason_code']);
        } catch (\InvalidArgumentException) {
            // Refused locally before anything was sent.
            return new ProviderResult('rejected', null, 'TRENDYOL_CANCELLATION_INVALID');
        } catch (RuntimeException) {
            // The connection broke: Trendyol may or may not have received it.
            return new ProviderResult('uncertain', null, 'TRENDYOL_ANSWER_LOST');
        }
        $status = $answer['status'];
        return match (true) {
            $status >= 200 && $status < 300 => new ProviderResult('accepted', $status),
            $status === 401 || $status === 403 => new ProviderResult('auth_failed', $status, 'TRENDYOL_AUTH_FAILED'),
            $status === 429 => new ProviderResult('transient', $status, 'TRENDYOL_RATE_LIMITED'),
            $status >= 400 && $status < 500 => new ProviderResult('rejected', $status, 'TRENDYOL_REJECTED_' . $status),
            default => new ProviderResult('uncertain', $status, 'TRENDYOL_SERVER_ERROR'),
        };
    }

    private static function code(string $message, string $fallback): string
    {
        return preg_match('/^TRENDYOL_[A-Z_]{1,40}$/D', $message) === 1 ? $message : $fallback;
    }
}
