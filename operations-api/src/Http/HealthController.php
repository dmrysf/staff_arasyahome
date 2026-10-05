<?php

declare(strict_types=1);

namespace Arasya\Operations\Http;

use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;

final readonly class HealthController
{
    public const VERSION = '2.9.0';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    public function show(): Response
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            throw new ApiException(503, 'SERVICE_UNAVAILABLE', 'Service is not ready.');
        }
        return Response::json([
            'status' => 'ok',
            'service' => 'arasya-operations-api',
            'version' => self::VERSION,
            'time' => $this->clock->now()->format(DATE_ATOM),
        ]);
    }
}
