<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Validation for current-account requests. Failures name the fields and a reason, never the submitted values.
 * Business dates are calendar dates (YYYY-MM-DD) in the Arasya business time zone and may not lie in the future.
 */
final class AccountInput
{
    public const TIME_ZONE = 'Europe/Bucharest';
    public const CURRENCIES = ['RON', 'EUR'];
    public const DIRECTIONS = ['debit', 'credit'];
    public const METHODS = ['bank_transfer', 'cash', 'card', 'compensation', 'other'];
    public const PAYMENT_FIELDS = ['currencyCode', 'amount', 'valueDate', 'method', 'externalReference', 'note', 'allocations'];
    public const ENTRY_FIELDS = ['currencyCode', 'direction', 'amount', 'valueDate', 'reason'];
    public const REVERSAL_FIELDS = ['valueDate', 'reason'];
    public const ALLOCATION_FIELDS = ['paymentId', 'allocations'];
    public const RELEASE_FIELDS = ['reason'];
    public const MAX_ALLOCATIONS = 100;
    private const REFERENCE_MAX = 160;
    private const TEXT_MAX = 2000;

    /** @var array<string, string> */
    private array $errors = [];

    /** The business date "today" for automatic postings. */
    public static function today(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone(self::TIME_ZONE))->format('Y-m-d');
    }

    /** @return array{currencyCode:string,amount:int,valueDate:string,method:string,externalReference:?string,note:?string,allocations:list<array{receivableId:string,amount:int}>} */
    public static function payment(array $input, DateTimeImmutable $now): array
    {
        $v = new self();
        $data = [
            'currencyCode' => $v->currency($input['currencyCode'] ?? null),
            'amount' => $v->amount($input['amount'] ?? null, 'amount'),
            'valueDate' => $v->date($input['valueDate'] ?? null, 'valueDate', $now),
            'method' => $v->choice($input['method'] ?? null, self::METHODS, 'method'),
            'externalReference' => $v->text($input['externalReference'] ?? null, self::REFERENCE_MAX, 'externalReference'),
            'note' => $v->text($input['note'] ?? null, self::TEXT_MAX, 'note'),
            'allocations' => $v->allocations($input['allocations'] ?? [], 'allocations'),
        ];
        // Reference rules per method: a transfer needs its bank/document reference, a compensation needs a
        // reference or an explanation, "other" needs a description. Cash and card may omit the reference.
        if ($data['method'] === 'bank_transfer' && $data['externalReference'] === null) $v->fail('externalReference', 'required');
        if ($data['method'] === 'compensation' && $data['externalReference'] === null && $data['note'] === null) $v->fail('note', 'required');
        if ($data['method'] === 'other' && $data['note'] === null) $v->fail('note', 'required');
        $v->finish();
        return $data;
    }

    /** Opening balance or manual adjustment. @return array{currencyCode:string,direction:string,amount:int,valueDate:string,reason:string} */
    public static function entry(array $input, DateTimeImmutable $now): array
    {
        $v = new self();
        $data = [
            'currencyCode' => $v->currency($input['currencyCode'] ?? null),
            'direction' => $v->choice($input['direction'] ?? null, self::DIRECTIONS, 'direction'),
            'amount' => $v->amount($input['amount'] ?? null, 'amount'),
            'valueDate' => $v->date($input['valueDate'] ?? null, 'valueDate', $now),
            'reason' => $v->required($input['reason'] ?? null, self::TEXT_MAX, 'reason'),
        ];
        $v->finish();
        return $data;
    }

    /** @return array{valueDate:string,reason:string} */
    public static function reversal(array $input, DateTimeImmutable $now): array
    {
        $v = new self();
        $data = ['valueDate' => $v->date($input['valueDate'] ?? null, 'valueDate', $now), 'reason' => $v->required($input['reason'] ?? null, self::TEXT_MAX, 'reason')];
        $v->finish();
        return $data;
    }

    /** @return array{paymentId:string,allocations:list<array{receivableId:string,amount:int}>} */
    public static function allocation(array $input): array
    {
        $v = new self();
        $payment = $input['paymentId'] ?? null;
        if (!is_string($payment) || !self::isUuid($payment)) $v->fail('paymentId', 'invalid');
        $data = ['paymentId' => (string) $payment, 'allocations' => $v->allocations($input['allocations'] ?? null, 'allocations')];
        if ($data['allocations'] === []) $v->fail('allocations', 'required');
        $v->finish();
        return $data;
    }

    public static function releaseReason(array $input): string
    {
        $v = new self();
        $reason = $v->required($input['reason'] ?? null, self::TEXT_MAX, 'reason');
        $v->finish();
        return $reason;
    }

    /** Statement range: optional from/to calendar dates, to defaults to today. @return array{currencyCode:string,from:?string,to:string} */
    public static function statement(array $query, DateTimeImmutable $now): array
    {
        $v = new self();
        $currency = $v->currency($query['currency'] ?? null, 'currency');
        $from = isset($query['from']) && $query['from'] !== '' ? $v->date($query['from'], 'from', null) : null;
        $to = isset($query['to']) && $query['to'] !== '' ? $v->date($query['to'], 'to', null) : self::today($now);
        if ($from !== null && $to !== '' && $from > $to) $v->fail('from', 'after_to');
        $v->finish();
        return ['currencyCode' => $currency, 'from' => $from, 'to' => $to];
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') return null;
        $v = new self();
        $date = $v->date($value, $field, null);
        $v->finish();
        return $date;
    }

    private function currency(mixed $value, string $field = 'currencyCode'): string
    {
        return $this->choice($value, self::CURRENCIES, $field);
    }

    private function choice(mixed $value, array $choices, string $field): string
    {
        if ($value === null || $value === '') { $this->fail($field, 'required'); return ''; }
        if (!is_string($value) || !in_array($value, $choices, true)) { $this->fail($field, 'invalid'); return ''; }
        return $value;
    }

    private function amount(mixed $value, string $field): int
    {
        if ($value === null || $value === '') { $this->fail($field, 'required'); return 0; }
        $cents = AccountMoney::parsePositive($value);
        if ($cents === null) { $this->fail($field, 'invalid'); return 0; }
        return $cents;
    }

    private function date(mixed $value, string $field, ?DateTimeImmutable $now): string
    {
        if ($value === null || $value === '') { $this->fail($field, 'required'); return ''; }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) { $this->fail($field, 'invalid'); return ''; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIME_ZONE));
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '2000-01-01') { $this->fail($field, 'invalid'); return ''; }
        if ($now !== null && $value > self::today($now)) { $this->fail($field, 'future'); return ''; }
        return $value;
    }

    private function text(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1 || preg_match('//u', $value) !== 1) { $this->fail($field, 'invalid'); return null; }
        $value = trim($value);
        if ($value === '') return null;
        if (mb_strlen($value) > $max) { $this->fail($field, 'too_long'); return null; }
        return $value;
    }

    private function required(mixed $value, int $max, string $field): string
    {
        $text = $this->text($value, $max, $field);
        if ($text === null && !isset($this->errors[$field])) $this->fail($field, 'required');
        return (string) $text;
    }

    /** @return list<array{receivableId:string,amount:int}> */
    private function allocations(mixed $value, string $field): array
    {
        if ($value === null) return [];
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ALLOCATIONS) { $this->fail($field, 'invalid'); return []; }
        $out = []; $seen = [];
        foreach ($value as $item) {
            $id = is_array($item) ? ($item['receivableId'] ?? null) : null;
            $cents = is_array($item) ? AccountMoney::parsePositive($item['amount'] ?? null) : null;
            if (!is_array($item) || array_diff(array_keys($item), ['receivableId', 'amount']) !== [] || !is_string($id) || !self::isUuid($id) || $cents === null || isset($seen[$id])) {
                $this->fail($field, 'invalid');
                return [];
            }
            $seen[$id] = true;
            $out[] = ['receivableId' => $id, 'amount' => $cents];
        }
        return $out;
    }

    private function fail(string $field, string $reason): void
    {
        $this->errors[$field] ??= $reason;
    }

    private function finish(): void
    {
        if ($this->errors !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', ['fields' => $this->errors]);
        }
    }
}
