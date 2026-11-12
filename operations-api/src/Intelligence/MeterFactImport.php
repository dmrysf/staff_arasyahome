<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceOrderPayloadMapper;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * Brings the pricing basis of orders that Arasya already holds, but that were last sent before the shop stated it, into
 * `order_item_meter_facts` (Production Intelligence V2 history). The input is a shop export (YD SOFT
 * `wp yd-arasya meter-basis export`): order and line ids with their `meterBasis`, nothing else.
 *
 * Facts only. The import never creates, changes or re-sends an order: no order row, item, version, hash, QR, document,
 * Confirmare or activity is written, so nothing printed can become stale. It only adds a fact where none is stored:
 * a fact the shop stated with a real order event is newer evidence and is never overwritten. Orders Arasya does not hold
 * and lines that are not on the canonical order are counted and skipped, never guessed. The whole file is validated
 * before anything is written; each order is written in its own short transaction. Repeating an import writes nothing.
 * Imported rows carry `source_event_id = import:<sha256 of the file>` and get today's `updated_at`, so the automatic
 * ledger run (`meter-ledger.php --changed`) or the backfill books them; their sale date stays the order's own date.
 */
final readonly class MeterFactImport
{
    public const FORMAT = 'arasya-meter-basis-export';
    public const FORMAT_VERSION = 1;
    public const SOURCES = ['trendhome', 'outletperdele'];
    private const MAX_ORDERS = 50000;
    private const MAX_ITEMS = 200;

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * @return array{dryRun: bool, origin: string, orders: int, ordersMissing: int, items: int, itemsMissing: int,
     *               recorded: int, alreadyStated: int, byKind: array<string, int>}
     */
    public function run(string $sourceKey, string $json, bool $dryRun): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Invalid meter fact import context');
        }
        $orders = self::parse($sourceKey, $json);
        $origin = 'import:' . hash('sha256', $json);
        $report = ['dryRun' => $dryRun, 'origin' => $origin, 'orders' => 0, 'ordersMissing' => 0, 'items' => 0, 'itemsMissing' => 0,
            'recorded' => 0, 'alreadyStated' => 0, 'byKind' => []];
        $find = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?');
        $lines = $this->pdo->prepare('SELECT source_item_id FROM operational_order_items WHERE order_uuid = ?');
        $stated = $this->pdo->prepare('SELECT source_item_id FROM order_item_meter_facts WHERE order_uuid = ?');
        $insert = $this->pdo->prepare('INSERT INTO order_item_meter_facts (order_uuid, source_item_id, basis_kind, priced_length, source_event_id, first_observed_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($orders as $orderId => $items) {
            // A numeric id became an integer array key; ids are strings everywhere else.
            $orderId = (string) $orderId;
            $report['items'] += count($items);
            $find->execute([(new GlobalOrderId($sourceKey, $orderId))->toString()]);
            $orderUuid = $find->fetchColumn();
            if ($orderUuid === false) {
                $report['ordersMissing']++;
                continue;
            }
            $report['orders']++;
            $lines->execute([$orderUuid]);
            $known = array_flip(array_map('strval', $lines->fetchAll(PDO::FETCH_COLUMN)));
            $stated->execute([$orderUuid]);
            $present = array_flip(array_map('strval', $stated->fetchAll(PDO::FETCH_COLUMN)));
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            if (!$dryRun) {
                $this->pdo->beginTransaction();
            }
            try {
                foreach ($items as $itemId => $basis) {
                    $itemId = (string) $itemId;
                    if (!isset($known[$itemId])) {
                        $report['itemsMissing']++;
                        continue;
                    }
                    if (isset($present[$itemId])) {
                        $report['alreadyStated']++;
                        continue;
                    }
                    if (!$dryRun) {
                        try {
                            $insert->execute([$orderUuid, $itemId, $basis['kind'], $basis['length'] === null ? null : number_format($basis['length'], 3, '.', ''), $origin, $now, $now]);
                        } catch (PDOException $error) {
                            // 1062: a real order event stated this line in the meantime; that fact wins.
                            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                                throw $error;
                            }
                            $report['alreadyStated']++;
                            continue;
                        }
                    }
                    $report['recorded']++;
                    $report['byKind'][$basis['kind']] = ($report['byKind'][$basis['kind']] ?? 0) + 1;
                }
                if (!$dryRun) {
                    $this->pdo->commit();
                }
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        ksort($report['byKind']);
        return $report;
    }

    /**
     * The whole export, validated before anything is written.
     * @return array<string, array<string, array{kind: string, length: float|null}>> order id => item id => basis
     */
    public static function parse(string $sourceKey, string $json): array
    {
        if (!in_array($sourceKey, self::SOURCES, true)) {
            throw new InvalidArgumentException('Only trendhome and outletperdele state a meter basis');
        }
        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The export is not valid JSON');
        }
        if (!is_array($document) || ($document['format'] ?? null) !== self::FORMAT || ($document['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Not a meter basis export of version ' . self::FORMAT_VERSION);
        }
        if (($document['sourceKey'] ?? null) !== $sourceKey) {
            throw new InvalidArgumentException('The export belongs to another source');
        }
        $list = $document['orders'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_ORDERS) {
            throw new InvalidArgumentException('orders must be a list');
        }
        $orders = [];
        foreach ($list as $index => $order) {
            $field = "orders[{$index}]";
            if (!is_array($order) || array_diff(array_keys($order), ['id', 'items']) !== [] || !isset($order['id'], $order['items'])) {
                throw new InvalidArgumentException("{$field} is invalid");
            }
            $orderId = self::identifier($order['id'], "{$field}.id");
            if (isset($orders[$orderId])) {
                throw new InvalidArgumentException("{$field}.id is repeated");
            }
            if (!is_array($order['items']) || !array_is_list($order['items']) || count($order['items']) > self::MAX_ITEMS) {
                throw new InvalidArgumentException("{$field}.items is invalid");
            }
            $orders[$orderId] = [];
            foreach ($order['items'] as $line => $item) {
                $itemField = "{$field}.items[{$line}]";
                if (!is_array($item) || array_diff(array_keys($item), ['id', 'meterBasis']) !== [] || !isset($item['id'], $item['meterBasis'])) {
                    throw new InvalidArgumentException("{$itemField} is invalid");
                }
                $itemId = self::identifier($item['id'], "{$itemField}.id");
                if (isset($orders[$orderId][$itemId])) {
                    throw new InvalidArgumentException("{$itemField}.id is repeated");
                }
                try {
                    // The exact rule an order event's meterBasis is held to (API 2.70.0).
                    $orders[$orderId][$itemId] = SourceOrderPayloadMapper::meterBasis($item['meterBasis'], "{$itemField}.meterBasis");
                } catch (ApiException) {
                    throw new InvalidArgumentException("{$itemField}.meterBasis is invalid");
                }
            }
        }
        return $orders;
    }

    private static function identifier(mixed $value, string $field): string
    {
        $value = is_int($value) && $value > 0 ? (string) $value : $value;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid");
        }
        return $value;
    }
}
