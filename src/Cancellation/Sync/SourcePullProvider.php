<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

/**
 * WooCommerce sources (Trend Home, Outlet Perdele): Arasya holds no WooCommerce credentials and never calls a shop. The
 * shop's own YD SOFT connector pulls the request through the signed route
 * POST /integrations/sources/{key}/orders/cancellations, changes the order in WooCommerce only when its own local gate
 * allows it (never a paid order: that needs a person and a separate refund decision), and reports the result through
 * POST .../orders/cancellations/result. Confirmation is the shop's next signed order event with availability
 * `cancelled`, the same inbound path every cancellation already uses.
 */
final readonly class SourcePullProvider implements CancellationProvider
{
    public function __construct(private string $sourceKey, private bool $sourceConfigured)
    {
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function mechanism(): string
    {
        return 'source_pull';
    }

    public function configured(): bool
    {
        return $this->sourceConfigured;
    }

    public function capability(): array
    {
        return [
            'inbound' => 'signed_source_event',
            'outbound' => 'source_pull',
            'confirmation' => 'inbound_event',
            'retry' => 'connector_pulls_again',
            'reconciliation' => 'inbound_events',
            'unsupported' => ['refund', 'paid_order_cancellation', 'shipped_order_cancellation'],
            'configured' => $this->sourceConfigured,
        ];
    }

    public function readStatus(array $operation): ProviderResult
    {
        throw new \LogicException('A source-pull provider is never read by Arasya.');
    }

    public function submit(array $operation): ProviderResult
    {
        throw new \LogicException('A source-pull provider is never called by Arasya.');
    }
}
