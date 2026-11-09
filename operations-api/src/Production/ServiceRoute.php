<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use PDO;
use PDOException;

/**
 * Reads the frozen order-level service route of a canonical production order (operational_orders.service_route,
 * migration 050). Only the explicit B2B production submission writes it, from the frozen B2B order type; a database CHECK
 * keeps it NULL for every other source. NULL means the full canonical route. ProductRoutePolicy decides what a route means.
 */
final class ServiceRoute
{
    private function __construct()
    {
    }

    public static function of(PDO $pdo, string $orderUuid): ?string
    {
        try {
            $s = $pdo->prepare('SELECT service_route FROM operational_orders WHERE order_uuid = ?');
            $s->execute([$orderUuid]);
            $value = $s->fetchColumn();
        } catch (PDOException $error) {
            // Before migration 050 the column does not exist: every order keeps the full route.
            if ((int) ($error->errorInfo[1] ?? 0) === 1054) {
                return null;
            }
            throw $error;
        }
        return is_string($value) ? $value : null;
    }
}
