<?php

declare(strict_types=1);

namespace Arasya\Operations\Activity;

use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Staff history ranges are calendar ranges in the factory time zone
 * (Europe/Bucharest), converted to UTC half-open intervals for storage queries.
 */
final readonly class ActivityRange
{
    public const FACTORY_TIME_ZONE = 'Europe/Bucharest';
    private const MAX_CUSTOM_DAYS = 92;

    private function __construct(public DateTimeImmutable $fromUtc, public DateTimeImmutable $toUtc)
    {
    }

    public static function resolve(string $range, ?string $from, ?string $to, DateTimeImmutable $now): self
    {
        $zone = new DateTimeZone(self::FACTORY_TIME_ZONE);
        $utc = new DateTimeZone('UTC');
        $localNow = $now->setTimezone($zone);
        $startOfToday = $localNow->setTime(0, 0);
        $endOfToday = $startOfToday->modify('+1 day');
        [$start, $end] = match ($range) {
            'today' => [$startOfToday, $endOfToday],
            '7days' => [$startOfToday->modify('-6 days'), $endOfToday],
            'month' => [$startOfToday->modify('first day of this month'), $endOfToday],
            'custom' => self::custom($from, $to, $zone),
            default => throw new ApiException(400, 'INVALID_RANGE', 'The activity range is not supported.'),
        };
        if ($range !== 'custom' && ($from !== null || $to !== null)) {
            throw new ApiException(400, 'INVALID_RANGE', 'Dates are accepted only for a custom range.');
        }
        return new self($start->setTimezone($utc), $end->setTimezone($utc));
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private static function custom(?string $from, ?string $to, DateTimeZone $zone): array
    {
        $start = self::date($from, $zone);
        $last = self::date($to, $zone);
        if ($start > $last) {
            throw new ApiException(400, 'INVALID_RANGE', 'The start date must not be after the end date.');
        }
        $end = $last->modify('+1 day');
        if ((int) $start->diff($end)->format('%a') > self::MAX_CUSTOM_DAYS) {
            throw new ApiException(400, 'INVALID_RANGE', 'A custom range can cover at most 92 days.');
        }
        return [$start, $end];
    }

    private static function date(?string $value, DateTimeZone $zone): DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new ApiException(400, 'INVALID_RANGE', 'Custom dates must use YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException(400, 'INVALID_RANGE', 'Custom dates must be real calendar dates.');
        }
        return $date;
    }
}
