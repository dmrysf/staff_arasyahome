<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

use Arasya\Operations\Config\Config;
use PDO;

/**
 * Maps an order's source to its cancellation provider and answers whether outbound synchronization may run for it.
 * Two independent gates: the server switch ARASYA_CANCELLATION_SYNC=enabled and the provider's database flag
 * (cancellation_sync_<source>, created disabled by migration 034). Both are needed; neither alone sends anything.
 */
final class ProviderRegistry
{
    public const FLAGS = ['trendyol' => 'cancellation_sync_trendyol', 'trendhome' => 'cancellation_sync_trendhome', 'outletperdele' => 'cancellation_sync_outletperdele'];

    /** @var array<string, CancellationProvider> */
    private array $providers = [];

    public function __construct(private PDO $pdo, private Config $config, private ?TrendyolCancellationProvider $trendyol = null)
    {
    }

    public function for(string $sourceKey): CancellationProvider
    {
        return $this->providers[$sourceKey] ??= match (true) {
            $sourceKey === 'trendyol' => $this->trendyol !== null && $this->trendyol->configured() ? $this->trendyol : new ManualProvider('trendyol', 'TRENDYOL_NOT_CONFIGURED'),
            in_array($sourceKey, ['trendhome', 'outletperdele'], true) => new SourcePullProvider($sourceKey, isset($this->config->sourceSecrets[$sourceKey])),
            $sourceKey === 'b2b' => new ManualProvider('b2b', 'B2B_OUTBOUND_NOT_AVAILABLE'),
            default => new ManualProvider($sourceKey, 'PROVIDER_UNSUPPORTED'),
        };
    }

    public function serverEnabled(): bool
    {
        return $this->config->cancellationSyncEnabled();
    }

    public function flagEnabled(string $sourceKey): bool
    {
        $flag = self::FLAGS[$sourceKey] ?? null;
        if ($flag === null) {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT enabled FROM production_feature_flags WHERE flag_key = ?');
        $statement->execute([$flag]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** Both gates open and the provider configured: an outbound request for this source may be sent or handed over. */
    public function active(string $sourceKey): bool
    {
        $provider = $this->for($sourceKey);
        return $this->serverEnabled() && $this->flagEnabled($sourceKey) && $provider->mechanism() !== 'manual' && $provider->configured();
    }

    /** @return array<string, mixed> */
    public function view(string $sourceKey): array
    {
        $provider = $this->for($sourceKey);
        return ['source' => $sourceKey, 'mechanism' => $provider->mechanism(), 'serverEnabled' => $this->serverEnabled(), 'flagEnabled' => $this->flagEnabled($sourceKey),
            'active' => $this->active($sourceKey)] + $provider->capability();
    }
}
