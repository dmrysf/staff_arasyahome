<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use Arasya\Operations\Quality\ExceptionQueries;
/** Reuse the established API UTC wire format; never let browsers parse a naive database timestamp. */
final class UtcPresentation
{
    private const KEYS=['asOf','fromUtc','toUtcExclusive','createdAt','sourceImportedAt','sourceChangedAt','qrCreatedAt','firstClaimAt','completedAt','cancelledAt','measuredUntil','start','end','openedAt','decidedAt','resolvedAt',
        'occurred_at','requested_at','decided_at','resolved_at','accepted_at','qr_verified_at','reported_at','acknowledged_at','opened_at'];
    public static function format(array $data): array
    {
        foreach ($data as $key=>&$value) {
            if (is_array($value)) $value=self::format($value);
            elseif (in_array($key,self::KEYS,true) && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D',$value)) $value=ExceptionQueries::iso($value);
        }
        unset($value); return $data;
    }
}
