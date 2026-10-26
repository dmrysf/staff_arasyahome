<?php

declare(strict_types=1);

namespace Arasya\Operations\Cancellation\Sync;

/**
 * What one provider call established. `cancelled` is the only outcome that counts as confirmation, and only when it
 * comes from an authoritative read of the provider's own state (never from a write's success answer).
 *
 *   cancelled       the provider shows the order cancelled (status read)
 *   accepted        the provider accepted the request; confirmation still has to be read
 *   rejected        the provider refused it (a business refusal; retrying the same request will not help)
 *   not_cancellable the provider's current status does not allow a cancellation (for example shipped)
 *   auth_failed     credentials or permissions are refused (permanent until a person fixes them)
 *   transient       refused before any effect (rate limit, temporary unavailability): safe to retry later
 *   uncertain       the answer was lost or ambiguous: the provider may have acted; read its status before any retry
 *   not_configured  the provider is not configured for this call
 *   open            a status read found the order still open (not cancelled)
 */
final readonly class ProviderResult
{
    public const OUTCOMES = ['cancelled', 'accepted', 'rejected', 'not_cancellable', 'auth_failed', 'transient', 'uncertain', 'not_configured', 'open'];

    public function __construct(public string $outcome, public ?int $httpStatus = null, public ?string $errorCode = null, public ?string $providerStatus = null)
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unknown provider outcome.');
        }
    }
}
