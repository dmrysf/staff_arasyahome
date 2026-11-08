<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Arasya\Operations\Cutting\BusinessTime;
$hours = [];
for ($day = 1; $day <= 7; $day++) $hours[] = ['weekday' => $day, 'is_open' => $day < 7 ? 1 : 0, 'opens_at' => $day < 7 ? '05:00:00' : null, 'closes_at' => $day < 7 ? '20:00:00' : null];
$time = new BusinessTime($hours);
$checks = 0;
$check = static function (bool $ok) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException("Failed business-time check {$checks}"); };
$check($time->seconds('2026-10-05 01:00:00', '2026-10-05 03:00:00') === 3600);
$check($time->seconds('2026-10-03 16:00:00', '2026-10-05 03:00:00') === 7200);
$check(!$time->isOpen(new DateTimeImmutable('2026-10-04 10:00:00', new DateTimeZone('UTC'))));
$check($time->isOpen(new DateTimeImmutable('2026-10-05 02:00:00', new DateTimeZone('UTC'))));
$check(!$time->isOpen(new DateTimeImmutable('2026-10-05 17:00:00', new DateTimeZone('UTC'))));
$check($time->seconds('2026-10-05 03:00:00', '2026-10-05 02:00:00') === 0);
// The closing hour includes the DST offset of that date, not the offset at claim time.
$check($time->seconds('2026-10-24 01:00:00', '2026-10-26 04:00:00') === 57600);
// Closing boundary (fixed instants, no wall clock): the closing minute is excluded, so with 00:00-23:59 the
// board is closed from 23:59:00 to 00:00:00 Bucharest. This is production behaviour; the e2e TV test avoids that minute.
$allDay = new BusinessTime(array_map(static fn (int $day): array => ['weekday' => $day, 'is_open' => 1, 'opens_at' => '00:00:00', 'closes_at' => '23:59:00'], range(1, 7)));
$utc = new DateTimeZone('UTC');
$check($allDay->isOpen(new DateTimeImmutable('2026-11-09 21:58:59', $utc)));      // Mon 23:58:59 EET: open
$check(!$allDay->isOpen(new DateTimeImmutable('2026-11-09 21:59:00', $utc)));     // Mon 23:59:00 EET: closed (exclusive end)
$check(!$allDay->isOpen(new DateTimeImmutable('2026-11-09 21:59:10', $utc)));     // Mon 23:59:10 EET: closed
$check(!$allDay->isOpen(new DateTimeImmutable('2026-11-08 21:59:10', $utc)));     // Sun 23:59:10 EET: the CI run of 2026-11-08
$check($allDay->isOpen(new DateTimeImmutable('2026-11-09 22:00:00', $utc)));      // Tue 00:00:00 EET: open again
$check($allDay->isOpen(new DateTimeImmutable('2026-07-15 20:58:59', $utc)));      // summer 23:58:59 EEST: open
$check(!$allDay->isOpen(new DateTimeImmutable('2026-07-15 20:59:10', $utc)));     // summer 23:59:10 EEST: closed
$check($time->isOpen(new DateTimeImmutable('2026-11-09 17:59:59', $utc)) && !$time->isOpen(new DateTimeImmutable('2026-11-09 18:00:00', $utc))); // 19:59:59 open, 20:00:00 closed
echo "PASS {$checks} cutting business-time checks\n";
