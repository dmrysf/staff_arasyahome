<?php
declare(strict_types=1);
namespace Arasya\Operations\Cutting;
use DateTimeImmutable;
use DateTimeZone;

/** Business seconds are derived from the authoritative schedule, including each day's DST offset. */
final readonly class BusinessTime
{
    public function __construct(private array $hours) {}
    public function isOpen(DateTimeImmutable $instant): bool
    {
        $local = $instant->setTimezone(new DateTimeZone('Europe/Bucharest'));
        $row = $this->day((int) $local->format('N'));
        return $row !== null && (int) $row['is_open'] === 1 && $local->format('H:i:s') >= $row['opens_at'] && $local->format('H:i:s') < $row['closes_at'];
    }
    public function seconds(string $start, string $end): int
    {
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($start, $utc);
        $to = new DateTimeImmutable($end, $utc);
        if ($to <= $from) return 0;
        $zone = new DateTimeZone('Europe/Bucharest');
        $day = $from->setTimezone($zone)->setTime(0, 0);
        $last = $to->setTimezone($zone)->setTime(0, 0);
        $seconds = 0;
        while ($day <= $last) {
            $row = $this->day((int) $day->format('N'));
            if ($row !== null && (int) $row['is_open'] === 1) {
                $open = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $row['opens_at'], $zone);
                $close = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $row['closes_at'], $zone);
                $seconds += max(0, min($to->getTimestamp(), $close->getTimestamp()) - max($from->getTimestamp(), $open->getTimestamp()));
            }
            $day = $day->modify('+1 day');
        }
        return $seconds;
    }
    private function day(int $weekday): ?array
    {
        foreach ($this->hours as $row) if ((int) $row['weekday'] === $weekday) return $row;
        return null;
    }
}
