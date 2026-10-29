<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Production\WorkflowCohort;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Which orders finance tasks cover (owner decision 2026-10-29): every Trend Home and Outlet Perdele order first
 * submitted at or after 2026-10-22 00:00 Europe/Bucharest (2026-10-21T21:00:00Z, inclusive), whatever its workflow
 * cohort (confirmare, legacy, NULL or undetermined) and whenever it reached Arasya.
 *
 * The date is the shop's first real submission (operational_orders.source_submitted_at with its evidence kind), never
 * the last change, a proforma date or the last synchronisation. An order without submission evidence is `undetermined`:
 * it is never silently counted in or out, and no task is created for it automatically (manual review). The first
 * acceptance in Arasya (operational_orders.created_at) is kept apart and only reported (an older order that reached
 * Arasya later is listed separately; it is still private by default but its commercial workflow is never changed).
 *
 * The scope decides which orders a finance task may be created for. It never grants access: a finance employee reaches
 * an order only through a task assigned to them (FinanceTaskAccess), whatever its date.
 */
final class FinanceTaskScope
{
    public const FROM_UTC = WorkflowCohort::BUSINESS_CUTOVER_UTC;
    public const IN_SCOPE = 'in_scope';
    public const BEFORE_SCOPE = 'before_scope';
    public const UNDETERMINED = 'undetermined';

    private function __construct()
    {
    }

    /** @param string|null $submittedAt the stored first submission (UTC, SQL or ISO), or null without evidence */
    public static function of(?string $submittedAt): string
    {
        $instant = self::instant($submittedAt);
        if ($instant === null) {
            return self::UNDETERMINED;
        }
        return $instant >= new DateTimeImmutable(self::FROM_UTC) ? self::IN_SCOPE : self::BEFORE_SCOPE;
    }

    /** The boundary as a UTC SQL DATETIME(6) literal, for queries. */
    public static function fromSql(): string
    {
        return (new DateTimeImmutable(self::FROM_UTC))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function instant(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
