<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

/**
 * The three B2B commercial order types (owner decision 2026-11-09, migration 050). The type is chosen explicitly on the
 * draft, frozen at finalization and copied to the canonical production order at explicit production submission, where
 * it selects the order-level service route (ProductRoutePolicy). It is independent of the line `kind`
 * (curtain/drapery/other), which is a product classification: nothing infers one from the other.
 *
 *   FULL_TAILORING  every applicable canonical stage, 1 to 14
 *   CUT_ONLY        1 -> 2 -> 14: the stage 2 employee cuts, then the order goes to Livrare
 *   FULL_ROLL_BAG   1 -> 2 -> 14: the stage 2 employee checks and prepares the full bag or roll, then Livrare
 *
 * A historical order without a type (NULL) keeps the full route and never gains a short one.
 */
final class OrderType
{
    public const FULL_TAILORING = 'FULL_TAILORING';
    public const CUT_ONLY = 'CUT_ONLY';
    public const FULL_ROLL_BAG = 'FULL_ROLL_BAG';
    public const ALL = [self::FULL_TAILORING, self::CUT_ONLY, self::FULL_ROLL_BAG];

    private function __construct()
    {
    }
}
