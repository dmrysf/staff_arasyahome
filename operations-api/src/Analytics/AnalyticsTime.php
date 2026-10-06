<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use DateTimeImmutable;
use DateTimeZone;

/** UTC intervals, authoritative Bucharest schedule. Grace applies only to approval clocks. */
final readonly class AnalyticsTime
{
    public function __construct(private array $hours, private int $graceMinutes = 60) {}
    public function duration(string $start, string $end, bool $approval = false): array
    {
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($start, $utc);
        $to = new DateTimeImmutable($end, $utc);
        $wall = max(0, $to->getTimestamp() - $from->getTimestamp());
        if ($wall === 0) return ['wallSeconds'=>0, 'businessSeconds'=>0];
        $zone = new DateTimeZone('Europe/Bucharest');
        // Include the preceding day: a configured grace can cross local midnight.
        $day = $from->setTimezone($zone)->setTime(0, 0)->modify('-1 day');
        $last = $to->setTimezone($zone)->setTime(0, 0);
        $windows = [];
        while ($day <= $last) {
            foreach ($this->hours as $row) {
                if ((int)$row['weekday'] !== (int)$day->format('N') || !(int)$row['is_open']) continue;
                $open = new DateTimeImmutable($day->format('Y-m-d').' '.$row['opens_at'], $zone);
                $close = new DateTimeImmutable($day->format('Y-m-d').' '.$row['closes_at'], $zone);
                $endTimestamp = $close->getTimestamp() + ($approval ? $this->graceMinutes * 60 : 0);
                $a = max($from->getTimestamp(), $open->getTimestamp());
                $b = min($to->getTimestamp(), $endTimestamp);
                if ($b > $a) $windows[] = [$a, $b];
            }
            $day = $day->modify('+1 day');
        }
        return ['wallSeconds'=>$wall, 'businessSeconds'=>self::unionSeconds($windows)];
    }
    /** Blocks are clipped and unioned before subtraction; no double-counted waits. */
    public function active(string $start, string $end, array $blocks): array
    {
        $duration = $this->duration($start, $end);
        $clipped = [];
        foreach ($blocks as $block) {
            $a = max($start, $block['start']);
            $b = min($end, $block['end'] ?? $end);
            if ($b > $a) $clipped[] = [$a, $b];
        }
        usort($clipped, static fn(array $a,array $b):int => strcmp($a[0],$b[0]));
        $merged = [];
        foreach ($clipped as [$a,$b]) {
            $last = count($merged)-1;
            if ($last >= 0 && $a <= $merged[$last][1]) $merged[$last][1] = max($b,$merged[$last][1]);
            else $merged[] = [$a,$b];
        }
        foreach ($merged as [$a,$b]) {
            $wait = $this->duration($a,$b);
            $duration['wallSeconds'] -= $wait['wallSeconds'];
            $duration['businessSeconds'] -= $wait['businessSeconds'];
        }
        return $duration;
    }
    /** Union of blocked windows on the chosen clock; manager grace never leaks into employee clocks. */
    public function blocked(string $start,string $end,array $blocks,bool $approval=false): array
    {
        if (!$approval) {
            $all=$this->duration($start,$end); $active=$this->active($start,$end,$blocks);
            return ['wallSeconds'=>$all['wallSeconds']-$active['wallSeconds'],'businessSeconds'=>$all['businessSeconds']-$active['businessSeconds']];
        }
        usort($blocks,static fn($a,$b)=>strcmp($a['start'],$b['start'])); $merged=[];
        foreach ($blocks as $block) {
            $a=max($start,$block['start']); $b=min($end,$block['end']??$end); if ($b<=$a) continue;
            $last=count($merged)-1;
            if ($last>=0 && $a<=$merged[$last][1]) $merged[$last][1]=max($b,$merged[$last][1]); else $merged[]=[$a,$b];
        }
        $sum=['wallSeconds'=>0,'businessSeconds'=>0];
        foreach ($merged as [$a,$b]) { $duration=$this->duration($a,$b,true); foreach ($sum as $clock=>$seconds) $sum[$clock]+=$duration[$clock]; }
        return $sum;
    }
    private static function unionSeconds(array $windows): int
    {
        usort($windows, static fn(array $a,array $b):int => $a[0]<=>$b[0]);
        $total=0; $end=PHP_INT_MIN;
        foreach ($windows as [$a,$b]) { $total += max(0,$b-max($a,$end)); $end=max($end,$b); }
        return $total;
    }
}
