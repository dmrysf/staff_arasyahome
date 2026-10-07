<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration;

/**
 * What a signed source may do. Validation sources may authenticate, heartbeat and validate payloads
 * but can never write canonical orders; only active sources reach the OrderProjectionWriter.
 */
enum SourceMode: string
{
    case Validation = 'validation';
    case Active = 'active';
}
