<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

/**
 * Who may change the production state of one canonical order (operational_orders.production_authority).
 *
 * - SOURCE: production is still managed by the commerce source (for WooCommerce: the legacy YD SOFT
 *   production engine). Arasya keeps the commerce snapshot; Staff may not claim the order while its
 *   source enforces the authority split.
 * - OPERATIONS: Arasya is the only production authority. Source snapshots still update commerce data
 *   but never the production stage, authority or production version.
 *
 * Real ingestion and production authority are separate: an order existing in Arasya says nothing about
 * who manages its production.
 */
final class ProductionAuthority
{
    public const SOURCE = 'source';
    public const OPERATIONS = 'operations';

    private function __construct()
    {
    }
}
