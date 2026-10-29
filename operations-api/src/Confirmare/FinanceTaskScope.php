<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Production\WorkflowCohort;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Which orders finance tasks cover (owner decisions 2026-10-29): every Trend Home and Outlet Perdele order whose first
 * submission in the shop OR first acceptance in Arasya is at or after 2026-10-22 00:00 Europe/Bucharest
 * (2026-10-21T21:00:00Z, inclusive), whatever its workflow cohort (confirmare, legacy, NULL or undetermined).
 *
 * The shop date is the first real submission (operational_orders.source_submitted_at with its evidence kind), the
 * Arasya date the first acceptance (operational_orders.created_at); never the last change, a proforma date or the last
 * synchronisation. An older order that reached Arasya at or after the boundary is covered (its commercial workflow is
 * never changed). An order without submission evidence that Arasya accepted before the boundary is `undetermined`: it
 * is never silently counted in or out, and no task is created for it automatically (manual review).
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

    /** The covering instant of an order row (alias `o`) in SQL: the later of the shop submission and the Arasya acceptance. */
    public const SQL_INSTANT = "GREATEST(COALESCE(o.source_submitted_at, '1000-01-01 00:00:00'), o.created_at)";

    /**
     * @param string|null $submittedAt the stored first submission (UTC, SQL or ISO), or null without evidence
     * @param string|null $acceptedAt  the first acceptance in Arasya (operational_orders.created_at, UTC)
     */
    public static function of(?string $submittedAt, ?string $acceptedAt = null): string
    {
        $from = new DateTimeImmutable(self::FROM_UTC);
        $submitted = self::instant($submittedAt);
        $accepted = self::instant($acceptedAt);
        if (($submitted !== null && $submitted >= $from) || ($accepted !== null && $accepted >= $from)) {
            return self::IN_SCOPE;
        }
        return $submitted === null ? self::UNDETERMINED : self::BEFORE_SCOPE;
    }

    /** @param array<string, mixed> $row an operational_orders row with source_submitted_at and created_at */
    public static function ofRow(array $row): string
    {
        return self::of($row['source_submitted_at'] === null ? null : (string) $row['source_submitted_at'], isset($row['created_at']) ? (string) $row['created_at'] : null);
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
