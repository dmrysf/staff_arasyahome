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
echo "PASS {$checks} cutting business-time checks\n";
