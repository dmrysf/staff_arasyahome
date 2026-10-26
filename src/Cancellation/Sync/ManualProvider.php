<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

/**
 * A channel without a supported outbound cancellation (B2B until its own integration exists, or a provider that is not
 * configured). The operation goes straight to manual review with the reason, so nothing ever appears sent: a person
 * cancels in the channel's own back office, and the inbound cancellation confirms it like any other.
 */
final readonly class ManualProvider implements CancellationProvider
{
    public function __construct(private string $sourceKey, private string $reason)
    {
    }

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function mechanism(): string
    {
        return 'manual';
    }

    public function configured(): bool
    {
        return false;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function capability(): array
    {
        return ['inbound' => $this->sourceKey === 'b2b' ? 'internal' : 'source', 'outbound' => 'manual', 'confirmation' => 'inbound_event', 'retry' => 'none',
            'reconciliation' => 'inbound_events', 'unsupported' => ['outbound_cancellation', 'refund'], 'configured' => false, 'reason' => $this->reason];
    }

    public function readStatus(array $operation): ProviderResult
    {
        return new ProviderResult('not_configured', null, $this->reason);
    }

    public function submit(array $operation): ProviderResult
    {
        return new ProviderResult('not_configured', null, $this->reason);
    }
}
