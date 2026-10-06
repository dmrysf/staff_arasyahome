<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
final readonly class DateRange
{
    private function __construct(public DateTimeImmutable $fromUtc, public DateTimeImmutable $toUtc) {}
    public static function from(array $query, DateTimeImmutable $now): self
    {
        $zone=new DateTimeZone('Europe/Bucharest'); $today=$now->setTimezone($zone)->setTime(0,0);
        if (isset($query['from']) || isset($query['to'])) {
            $parse=static function(mixed $value) use($zone): DateTimeImmutable {
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) throw new InvalidArgumentException('Invalid date range');
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
                if (!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Invalid date range');
                return $date;
            };
            $from=$parse($query['from']??null); $to=$parse($query['to']??null)->modify('+1 day');
        } else {
            [$from,$to]=match($query['period']??'month') {
                'today'=>[$today,$today->modify('+1 day')],
                'yesterday'=>[$today->modify('-1 day'),$today],
                'week'=>[$today->modify('-'.((int)$today->format('N')-1).' days'),$today->modify('+1 day')],
                'month'=>[$today->modify('first day of this month'),$today->modify('+1 day')],
                'previous_month'=>[$today->modify('first day of previous month'),$today->modify('first day of this month')],
                default=>throw new InvalidArgumentException('Invalid date period')
            };
        }
        if ($to <= $from || $from->diff($to)->days>366) throw new InvalidArgumentException('Date range must be between 1 and 366 days');
        $utc=new DateTimeZone('UTC'); return new self($from->setTimezone($utc),$to->setTimezone($utc));
    }
    public function metadata(): array { return ['fromUtc'=>$this->fromUtc->format('Y-m-d H:i:s'),'toUtcExclusive'=>$this->toUtc->format('Y-m-d H:i:s'),'timezone'=>'Europe/Bucharest']; }
}
