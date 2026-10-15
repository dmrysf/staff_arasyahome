<?php

declare(strict_types=1);
namespace Arasya\Operations\Simulation;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Session fixture time is frozen; real wall time remains separately visible in the center. */
final readonly class SimulationClock implements Clock
{
    public function __construct(private PDO $pdo) {}
    public function now(): DateTimeImmutable
    {
        $ms=(int)$this->pdo->query('SELECT baseline_ms FROM simulation_environment WHERE singleton_id=1')->fetchColumn();
        return DateTimeImmutable::createFromFormat('U.u',sprintf('%d.%06d',intdiv($ms,1000),($ms%1000)*1000))->setTimezone(new DateTimeZone('UTC'));
    }
}
