<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

/**
 * One sales channel's cancellation capability. The central engine (SyncOperations, CancellationSyncWorker) never knows
 * provider details; a provider never changes Arasya state itself.
 *
 * Mechanisms: `api` (the worker calls the provider: readStatus() then submit()), `source_pull` (the channel's own
 * connector pulls the request through the signed source route and reports back; Arasya makes no call), `manual` (no
 * supported call: a person acts in the channel's back office; confirmation still arrives inbound).
 */
interface CancellationProvider
{
    public function key(): string;

    /** @return 'api'|'source_pull'|'manual' */
    public function mechanism(): string;

    /** Whether the provider is fully configured for outbound requests (credentials, storefront, connector). */
    public function configured(): bool;

    /**
     * Facts shown to managers (verified behaviour, what is not supported, how confirmation arrives).
     * @return array<string, mixed>
     */
    public function capability(): array;

    /**
     * Authoritative status read of the external order (api mechanism only).
     * @param array<string, mixed> $operation
     */
    public function readStatus(array $operation): ProviderResult;

    /**
     * Sends the cancellation (api mechanism only). Called only after readStatus() found the order open and cancellable.
     * @param array<string, mixed> $operation
     */
    public function submit(array $operation): ProviderResult;
}
